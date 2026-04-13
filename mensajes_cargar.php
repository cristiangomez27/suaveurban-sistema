<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
mysqli_report(MYSQLI_REPORT_OFF);

session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['usuario_id'])) {
    echo json_encode(['ok' => false, 'mensaje' => 'Sesión no válida']);
    exit;
}

require_once 'config/database.php';

$usuarioId = (int)$_SESSION['usuario_id'];
$tipo = trim($_GET['tipo'] ?? 'grupal');
$destinatarioId = (int)($_GET['destinatario_id'] ?? 0);
$limite = 80;

if (!in_array($tipo, ['grupal', 'privado'], true)) {
    $tipo = 'grupal';
}

$conn->query("UPDATE usuarios SET last_seen = NOW() WHERE id = {$usuarioId} LIMIT 1");

/*
|--------------------------------------------------------------------------
| Usuarios disponibles
|--------------------------------------------------------------------------
*/
$usuarios = [];
$resUsuarios = $conn->query("
    SELECT id, nombre, rol, estado, last_seen,
           CASE
               WHEN last_seen IS NOT NULL AND last_seen >= (NOW() - INTERVAL 2 MINUTE) THEN 1
               ELSE 0
           END AS en_linea
    FROM usuarios
    WHERE estado = 'activo' AND id <> {$usuarioId}
    ORDER BY nombre ASC
");

if ($resUsuarios) {
    while ($u = $resUsuarios->fetch_assoc()) {
        $usuarios[] = [
            'id' => (int)$u['id'],
            'nombre' => (string)$u['nombre'],
            'rol' => (string)$u['rol'],
            'en_linea' => (int)$u['en_linea'] === 1
        ];
    }
}

/*
|--------------------------------------------------------------------------
| Cargar mensajes visibles
|--------------------------------------------------------------------------
*/
$mensajes = [];
$idsVisibles = [];

if ($tipo === 'privado' && $destinatarioId > 0) {
    $stmt = $conn->prepare("
        SELECT m.*,
               ur.nombre AS remitente_nombre,
               ur.rol AS remitente_rol
        FROM mensajes_internos m
        INNER JOIN usuarios ur ON ur.id = m.remitente_id
        WHERE m.tipo = 'privado'
          AND (
                (m.remitente_id = ? AND m.destinatario_id = ?)
             OR (m.remitente_id = ? AND m.destinatario_id = ?)
          )
        ORDER BY m.id DESC
        LIMIT {$limite}
    ");
    $stmt->bind_param("iiii", $usuarioId, $destinatarioId, $destinatarioId, $usuarioId);
} else {
    $stmt = $conn->prepare("
        SELECT m.*,
               ur.nombre AS remitente_nombre,
               ur.rol AS remitente_rol
        FROM mensajes_internos m
        INNER JOIN usuarios ur ON ur.id = m.remitente_id
        WHERE m.tipo = 'grupal'
        ORDER BY m.id DESC
        LIMIT {$limite}
    ");
}

$stmt->execute();
$resMensajes = $stmt->get_result();
$temp = [];
while ($row = $resMensajes->fetch_assoc()) {
    $temp[] = $row;
    $idsVisibles[] = (int)$row['id'];
}
$stmt->close();

$temp = array_reverse($temp);

/*
|--------------------------------------------------------------------------
| Marcar entregado / leído para mensajes que recibe el usuario actual
|--------------------------------------------------------------------------
*/
foreach ($temp as $m) {
    $mensajeId = (int)$m['id'];
    $remitenteId = (int)$m['remitente_id'];
    $esPrivado = $m['tipo'] === 'privado';

    if ($remitenteId === $usuarioId) {
        continue;
    }

    $meCorresponde = false;

    if ($esPrivado) {
        $meCorresponde = ((int)$m['destinatario_id'] === $usuarioId);
    } else {
        $meCorresponde = true;
    }

    if ($meCorresponde) {
        $stmtIns = $conn->prepare("
            INSERT INTO mensajes_lecturas (mensaje_id, usuario_id, delivered_at, read_at, creado_en)
            VALUES (?, ?, NOW(), NOW(), NOW())
            ON DUPLICATE KEY UPDATE
                delivered_at = IFNULL(delivered_at, NOW()),
                read_at = NOW()
        ");
        $stmtIns->bind_param("ii", $mensajeId, $usuarioId);
        $stmtIns->execute();
        $stmtIns->close();
    }
}

/*
|--------------------------------------------------------------------------
| Estados de palomitas
|--------------------------------------------------------------------------
*/
$usuariosActivosIds = [];
$resActivos = $conn->query("SELECT id FROM usuarios WHERE estado = 'activo'");
if ($resActivos) {
    while ($a = $resActivos->fetch_assoc()) {
        $usuariosActivosIds[] = (int)$a['id'];
    }
}

foreach ($temp as $m) {
    $mensajeId = (int)$m['id'];
    $remitenteId = (int)$m['remitente_id'];
    $tipoMsg = (string)$m['tipo'];
    $estadoTicks = '';

    if ($remitenteId === $usuarioId) {
        if ($tipoMsg === 'privado') {
            $dest = (int)$m['destinatario_id'];

            $stmtTick = $conn->prepare("
                SELECT delivered_at, read_at
                FROM mensajes_lecturas
                WHERE mensaje_id = ? AND usuario_id = ?
                LIMIT 1
            ");
            $stmtTick->bind_param("ii", $mensajeId, $dest);
            $stmtTick->execute();
            $resTick = $stmtTick->get_result();
            $tick = $resTick ? $resTick->fetch_assoc() : null;
            $stmtTick->close();

            if ($tick && !empty($tick['read_at'])) {
                $estadoTicks = 'read';
            } elseif ($tick && !empty($tick['delivered_at'])) {
                $estadoTicks = 'delivered';
            } else {
                $estadoTicks = 'sent';
            }
        } else {
            $targets = array_values(array_filter($usuariosActivosIds, function($id) use ($remitenteId) {
                return (int)$id !== (int)$remitenteId;
            }));

            $totalTargets = count($targets);
            $deliveredCount = 0;
            $readCount = 0;

            if ($totalTargets > 0) {
                $stmtGroup = $conn->prepare("
                    SELECT COUNT(*) AS entregados,
                           SUM(CASE WHEN read_at IS NOT NULL THEN 1 ELSE 0 END) AS leidos
                    FROM mensajes_lecturas
                    WHERE mensaje_id = ?
                ");
                $stmtGroup->bind_param("i", $mensajeId);
                $stmtGroup->execute();
                $resGroup = $stmtGroup->get_result();
                $rowGroup = $resGroup ? $resGroup->fetch_assoc() : null;
                $stmtGroup->close();

                $deliveredCount = (int)($rowGroup['entregados'] ?? 0);
                $readCount = (int)($rowGroup['leidos'] ?? 0);

                if ($readCount >= $totalTargets) {
                    $estadoTicks = 'read';
                } elseif ($deliveredCount >= $totalTargets) {
                    $estadoTicks = 'delivered';
                } else {
                    $estadoTicks = 'sent';
                }
            } else {
                $estadoTicks = 'sent';
            }
        }
    }

    $mensajes[] = [
        'id' => $mensajeId,
        'tipo' => $tipoMsg,
        'mensaje' => (string)$m['mensaje'],
        'remitente_id' => $remitenteId,
        'destinatario_id' => $m['destinatario_id'] !== null ? (int)$m['destinatario_id'] : null,
        'remitente_nombre' => (string)$m['remitente_nombre'],
        'remitente_rol' => (string)$m['remitente_rol'],
        'creado_en' => (string)$m['creado_en'],
        'mio' => $remitenteId === $usuarioId,
        'ticks' => $estadoTicks
    ];
}

echo json_encode([
    'ok' => true,
    'mensajes' => $mensajes,
    'usuarios' => $usuarios,
    'usuario_actual' => $usuarioId
], JSON_UNESCAPED_UNICODE);
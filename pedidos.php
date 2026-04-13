<?php
session_start();

if (!isset($_SESSION['usuario_id'])) {
    header("Location: index.php");
    exit;
}

require_once 'config/database.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function existeTabla(mysqli $conn, string $tabla): bool
{
    $tabla = $conn->real_escape_string($tabla);
    $res = $conn->query("SHOW TABLES LIKE '{$tabla}'");
    return ($res && $res->num_rows > 0);
}

function obtenerColumnasTabla(mysqli $conn, string $tabla): array
{
    $cols = [];
    if (!existeTabla($conn, $tabla)) {
        return $cols;
    }
    $res = $conn->query("SHOW COLUMNS FROM `$tabla`");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $cols[] = $row['Field'];
        }
    }
    return $cols;
}

function tieneColumna(array $cols, string $col): bool
{
    return in_array($col, $cols, true);
}

function e($valor): string
{
    return htmlspecialchars((string)$valor, ENT_QUOTES, 'UTF-8');
}

function obtenerValorEstado(array $row): string
{
    $estado = strtoupper(trim((string)($row['estado'] ?? '')));
    $estatus = strtoupper(trim((string)($row['estatus'] ?? '')));

    if ($estado !== '') return $estado;
    if ($estatus !== '') return $estatus;

    return 'NUEVO';
}

function nombreBonitoEstado(string $estado): string
{
    $estado = strtoupper(trim($estado));
    return match($estado) {
        'NUEVO' => 'Nuevo',
        'RECIBIDO' => 'Recibido',
        'EN PROCESO' => 'En proceso',
        'LISTO' => 'Listo',
        'ENTREGADO' => 'Entregado',
        default => $estado === '' ? 'Nuevo' : ucfirst(strtolower($estado))
    };
}

function normalizarTelefono(string $phone): string
{
    $phone = preg_replace('/\D+/', '', $phone);

    if ($phone === '') return '';
    if (strpos($phone, '521') === 0) return $phone;
    if (strpos($phone, '52') === 0) return '521' . substr($phone, 2);
    if (strlen($phone) === 10) return '521' . $phone;

    return $phone;
}

function buildAbsoluteUrl(string $relativeOrAbsolute): string
{
    $relativeOrAbsolute = trim($relativeOrAbsolute);
    if ($relativeOrAbsolute === '') {
        return '';
    }

    if (preg_match('#^https?://#i', $relativeOrAbsolute)) {
        return $relativeOrAbsolute;
    }

    $base = defined('APP_URL') ? rtrim(APP_URL, '/') : '';
    if ($base === '') {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? '';
        if ($host !== '') {
            $base = $scheme . '://' . $host;
        }
    }

    if ($base === '') {
        return '';
    }

    return $base . '/' . ltrim($relativeOrAbsolute, '/');
}

function greenApiSendMessageSafe(string $phone, string $message): array
{
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'mensaje' => 'cURL no está disponible'];
    }

    $instanceId = defined('GREEN_API_INSTANCE_ID') ? GREEN_API_INSTANCE_ID : '';
    $token = defined('GREEN_API_TOKEN') ? GREEN_API_TOKEN : '';

    if ($instanceId === '' || $token === '') {
        return ['ok' => false, 'mensaje' => 'Green API no configurado'];
    }

    $phone = normalizarTelefono($phone);
    if ($phone === '') {
        return ['ok' => false, 'mensaje' => 'Teléfono vacío'];
    }

    $url = "https://7107.api.greenapi.com/waInstance{$instanceId}/sendMessage/{$token}";
    $payload = json_encode([
        'chatId' => $phone . '@c.us',
        'message' => $message
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2
    ]);

    $response = curl_exec($ch);
    $error = curl_error($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($error) return ['ok' => false, 'mensaje' => $error];
    if ($http < 200 || $http >= 300) return ['ok' => false, 'mensaje' => 'HTTP ' . $http . ' - ' . $response];

    return ['ok' => true, 'mensaje' => $response];
}

function obtenerImagenPedido(array $pedido, mysqli $conn): string
{
    if (!empty($pedido['imagen_diseno'])) {
        return (string)$pedido['imagen_diseno'];
    }

    $ventaId = isset($pedido['venta_id']) ? (int)$pedido['venta_id'] : 0;
    if ($ventaId > 0 && existeTabla($conn, 'ventas')) {
        $colsVentas = obtenerColumnasTabla($conn, 'ventas');
        if (tieneColumna($colsVentas, 'imagen_diseno')) {
            $stmt = $conn->prepare("SELECT imagen_diseno FROM ventas WHERE id = ? LIMIT 1");
            $stmt->bind_param("i", $ventaId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!empty($row['imagen_diseno'])) {
                return (string)$row['imagen_diseno'];
            }
        }
    }

    return '';
}

if (!existeTabla($conn, 'pedidos')) {
    die('La tabla pedidos no existe todavía.');
}

$colsPedidos = obtenerColumnasTabla($conn, 'pedidos');
if (!tieneColumna($colsPedidos, 'estado')) {
    $conn->query("ALTER TABLE pedidos ADD COLUMN estado VARCHAR(50) NOT NULL DEFAULT 'NUEVO'");
}
if (!tieneColumna($colsPedidos, 'estatus')) {
    $conn->query("ALTER TABLE pedidos ADD COLUMN estatus VARCHAR(50) NOT NULL DEFAULT 'NUEVO'");
}
if (!tieneColumna($colsPedidos, 'fecha_entregado')) {
    $conn->query("ALTER TABLE pedidos ADD COLUMN fecha_entregado DATETIME NULL");
}
if (!tieneColumna($colsPedidos, 'imagen_diseno')) {
    $conn->query("ALTER TABLE pedidos ADD COLUMN imagen_diseno VARCHAR(255) NULL");
}
if (!tieneColumna($colsPedidos, 'cliente_telefono')) {
    $conn->query("ALTER TABLE pedidos ADD COLUMN cliente_telefono VARCHAR(30) NULL");
}
if (!tieneColumna($colsPedidos, 'cliente_nombre')) {
    $conn->query("ALTER TABLE pedidos ADD COLUMN cliente_nombre VARCHAR(150) NULL");
}

$fondoSidebar = '';
$fondoContenido = '';
$logoActual = 'logo.png';
$alphaPanel = 0.20;
$alphaSidebar = 0.88;
$nombreNegocio = 'Suave Urban Studio';

if (existeTabla($conn, 'configuracion')) {
    $colsConfig = obtenerColumnasTabla($conn, 'configuracion');
    $select = [];

    foreach (['logo', 'fondo_sidebar', 'fondo_contenido', 'transparencia_panel', 'transparencia_sidebar', 'nombre_negocio'] as $col) {
        if (tieneColumna($colsConfig, $col)) {
            $select[] = $col;
        }
    }

    if (!empty($select)) {
        $sql = "SELECT " . implode(', ', $select) . " FROM configuracion WHERE id = 1 LIMIT 1";
        $res = $conn->query($sql);
        if ($res && $res->num_rows > 0) {
            $cfg = $res->fetch_assoc();
            if (!empty($cfg['logo'])) $logoActual = $cfg['logo'];
            if (!empty($cfg['fondo_sidebar'])) $fondoSidebar = $cfg['fondo_sidebar'];
            if (!empty($cfg['fondo_contenido'])) $fondoContenido = $cfg['fondo_contenido'];
            if (!empty($cfg['nombre_negocio'])) $nombreNegocio = $cfg['nombre_negocio'];
            if (isset($cfg['transparencia_panel'])) $alphaPanel = max(0.05, min(0.95, (float)$cfg['transparencia_panel']));
            if (isset($cfg['transparencia_sidebar'])) $alphaSidebar = max(0.10, min(0.98, (float)$cfg['transparencia_sidebar']));
        }
    }
}

$mensaje = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion'])) {
    try {
        if ($_POST['accion'] === 'marcar_entregado') {
            $pedidoId = (int)($_POST['pedido_id'] ?? 0);
            if ($pedidoId <= 0) {
                throw new Exception('Pedido inválido.');
            }

            $stmt = $conn->prepare("SELECT * FROM pedidos WHERE id = ? LIMIT 1");
            $stmt->bind_param("i", $pedidoId);
            $stmt->execute();
            $pedido = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$pedido) {
                throw new Exception('No se encontró el pedido.');
            }

            $estado = obtenerValorEstado($pedido);
            if ($estado !== 'LISTO') {
                throw new Exception('Solo se puede marcar entregado cuando el pedido está en LISTO.');
            }

            $ventaId = (int)($pedido['venta_id'] ?? 0);

            $conn->begin_transaction();

            $stmtUpd = $conn->prepare("UPDATE pedidos SET estado = 'ENTREGADO', estatus = 'ENTREGADO', fecha_entregado = NOW() WHERE id = ? LIMIT 1");
            $stmtUpd->bind_param("i", $pedidoId);
            $stmtUpd->execute();
            $stmtUpd->close();

            if ($ventaId > 0 && existeTabla($conn, 'ventas')) {
                $colsVentas = obtenerColumnasTabla($conn, 'ventas');
                $updates = [];
                if (tieneColumna($colsVentas, 'estado')) $updates[] = "estado = 'ENTREGADO'";
                if (tieneColumna($colsVentas, 'estatus')) $updates[] = "estatus = 'ENTREGADO'";
                if (!empty($updates)) {
                    $sqlVenta = "UPDATE ventas SET " . implode(', ', $updates) . " WHERE id = ? LIMIT 1";
                    $stmtVenta = $conn->prepare($sqlVenta);
                    $stmtVenta->bind_param("i", $ventaId);
                    $stmtVenta->execute();
                    $stmtVenta->close();
                }
            }

            $conn->commit();
            $mensaje = 'Pedido marcado como entregado correctamente.';
        }

        if ($_POST['accion'] === 'reenviar_whatsapp') {
            $pedidoId = (int)($_POST['pedido_id'] ?? 0);
            if ($pedidoId <= 0) {
                throw new Exception('Pedido inválido.');
            }

            $stmt = $conn->prepare("SELECT * FROM pedidos WHERE id = ? LIMIT 1");
            $stmt->bind_param("i", $pedidoId);
            $stmt->execute();
            $pedido = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$pedido) {
                throw new Exception('No se encontró el pedido.');
            }

            $ventaId = (int)($pedido['venta_id'] ?? 0);
            $cliente = trim((string)($pedido['cliente_nombre'] ?? 'Cliente'));
            $telefono = trim((string)($pedido['cliente_telefono'] ?? ''));
            $folio = trim((string)($pedido['folio'] ?? 'SIN FOLIO'));

            if (($telefono === '' || $cliente === '' || $folio === '') && $ventaId > 0 && existeTabla($conn, 'ventas')) {
                $colsVentas = obtenerColumnasTabla($conn, 'ventas');
                $selectVenta = [];
                foreach (['cliente_nombre', 'cliente_telefono', 'folio'] as $c) {
                    if (tieneColumna($colsVentas, $c)) $selectVenta[] = $c;
                }
                if (!empty($selectVenta)) {
                    $stmtVenta = $conn->prepare("SELECT " . implode(', ', $selectVenta) . " FROM ventas WHERE id = ? LIMIT 1");
                    $stmtVenta->bind_param("i", $ventaId);
                    $stmtVenta->execute();
                    $venta = $stmtVenta->get_result()->fetch_assoc();
                    $stmtVenta->close();

                    if (!empty($venta)) {
                        if ($cliente === '' && !empty($venta['cliente_nombre'])) $cliente = trim((string)$venta['cliente_nombre']);
                        if ($telefono === '' && !empty($venta['cliente_telefono'])) $telefono = trim((string)$venta['cliente_telefono']);
                        if ($folio === '' && !empty($venta['folio'])) $folio = trim((string)$venta['folio']);
                    }
                }
            }

            $remisionAbsoluteUrl = buildAbsoluteUrl('imprimir_remision.php?id=' . $ventaId);

            if ($telefono === '') {
                throw new Exception('Este pedido no tiene teléfono registrado.');
            }
            if ($remisionAbsoluteUrl === '') {
                throw new Exception('No se pudo generar la URL de la remisión.');
            }

            $texto = "Hola {$cliente} 👋\n\n"
                . "Aquí está tu nota de remisión.\n"
                . "Folio: *{$folio}*\n\n"
                . $remisionAbsoluteUrl;

            $envio = greenApiSendMessageSafe($telefono, $texto);
            if (!$envio['ok']) {
                throw new Exception('No se pudo reenviar por WhatsApp: ' . $envio['mensaje']);
            }

            $mensaje = 'Remisión reenviada correctamente por WhatsApp.';
        }
    } catch (Throwable $e) {
        try {
            $conn->rollback();
        } catch (Throwable $rollbackError) {
        }
        $error = 'Error: ' . $e->getMessage();
    }
}

$buscar = trim((string)($_GET['buscar'] ?? ''));
$filtroEstado = strtoupper(trim((string)($_GET['estado'] ?? 'TODOS')));
$permitidos = ['TODOS', 'NUEVO', 'RECIBIDO', 'EN PROCESO', 'LISTO', 'ENTREGADO'];
if (!in_array($filtroEstado, $permitidos, true)) {
    $filtroEstado = 'TODOS';
}

$pedidos = [];
$sql = "SELECT * FROM pedidos WHERE 1=1";
$params = [];
$types = '';

if ($buscar !== '') {
    $sql .= " AND (folio LIKE ? OR cliente_nombre LIKE ? OR cliente_telefono LIKE ?)";
    $like = '%' . $buscar . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types .= 'sss';
}

if ($filtroEstado !== 'TODOS') {
    $sql .= " AND (UPPER(COALESCE(estado,'')) = ? OR UPPER(COALESCE(estatus,'')) = ?)";
    $params[] = $filtroEstado;
    $params[] = $filtroEstado;
    $types .= 'ss';
}

$sql .= " ORDER BY id DESC";

$stmtPedidos = $conn->prepare($sql);
if (!empty($params)) {
    $stmtPedidos->bind_param($types, ...$params);
}
$stmtPedidos->execute();
$resPedidos = $stmtPedidos->get_result();
while ($row = $resPedidos->fetch_assoc()) {
    $row['imagen_final'] = obtenerImagenPedido($row, $conn);
    $pedidos[] = $row;
}
$stmtPedidos->close();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pedidos - <?php echo e($nombreNegocio); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root{--gold:#c89b3c;--border:rgba(200,155,60,.20);--shadow:0 16px 34px rgba(0,0,0,.28)}
        *{box-sizing:border-box}
        body{
            margin:0;min-height:100vh;font-family:'Segoe UI',sans-serif;color:#fff;display:flex;
            background:<?php echo !empty($fondoContenido)
                ? "linear-gradient(rgba(0,0,0,.46), rgba(0,0,0,.62)), url('" . htmlspecialchars($fondoContenido, ENT_QUOTES, 'UTF-8') . "') center/cover fixed no-repeat"
                : "linear-gradient(135deg,#070709,#131318)"; ?>;
        }
        .sidebar{
            width:85px;position:fixed;top:0;left:0;bottom:0;display:flex;flex-direction:column;align-items:center;padding:15px 0;
            background:<?php echo !empty($fondoSidebar)
                ? "linear-gradient(rgba(0,0,0," . $alphaSidebar . "), rgba(0,0,0," . $alphaSidebar . ")), url('" . htmlspecialchars($fondoSidebar, ENT_QUOTES, 'UTF-8') . "') center/cover no-repeat"
                : "rgba(0,0,0," . $alphaSidebar . ")"; ?>;
            border-right:1px solid var(--border);backdrop-filter:blur(10px);-webkit-backdrop-filter:blur(10px);z-index:1000;
        }
        .logo-pos{width:55px;margin-bottom:16px;filter:drop-shadow(0 0 10px rgba(200,155,60,.6));animation:logoPulse 4s ease-in-out infinite, glow 3s infinite alternate}
        .nav-controls{display:flex;flex-direction:column;gap:18px;width:100%;align-items:center;padding-bottom:20px;border-bottom:1px solid var(--border);margin-bottom:26px}
        .sidebar a{color:#5b5b5b;font-size:20px;text-decoration:none;transition:.25s ease}
        .sidebar a:hover,.sidebar a.active{color:var(--gold);filter:drop-shadow(0 0 8px var(--gold))}
        .exit-btn:hover{color:#ff4d4d!important;filter:drop-shadow(0 0 8px #ff4d4d)!important}
        .content{flex:1;margin-left:85px;padding:24px}
        .hero,.toolbar,.card,.notice-success,.notice-error{
            background:rgba(255,255,255,<?php echo max(0.03, min(0.20, $alphaPanel * 0.35)); ?>);
            border:1px solid var(--border);box-shadow:var(--shadow);backdrop-filter:blur(10px);-webkit-backdrop-filter:blur(10px)
        }
        .hero{border-radius:22px;padding:20px;margin-bottom:16px}
        .hero h1{margin:0 0 6px;font-size:32px}
        .hero p{margin:0;color:#ddd}
        .toolbar{border-radius:18px;padding:16px;margin-bottom:18px}
        .toolbar form{display:grid;grid-template-columns:1.2fr .8fr auto auto;gap:12px}
        .toolbar input,.toolbar select{
            width:100%;border-radius:14px;border:1px solid rgba(255,255,255,.10);background:rgba(0,0,0,.28);color:#fff;padding:12px 14px;outline:none
        }
        .btn-top,.btn{
            border:none;border-radius:14px;padding:12px 16px;font-weight:800;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;justify-content:center
        }
        .btn-top{background:#c89b3c;color:#111}
        .btn-clear{background:#2c2c34;color:#fff}
        .notice-success,.notice-error{padding:14px 16px;border-radius:16px;margin-bottom:14px;font-weight:700}
        .notice-success{background:rgba(34,197,94,.14);border-color:rgba(34,197,94,.35);color:#d2f9de}
        .notice-error{background:rgba(239,68,68,.14);border-color:rgba(239,68,68,.35);color:#ffd2d2}
        .cards{display:grid;gap:14px}
        .card{border-radius:20px;padding:16px;overflow:hidden;position:relative}
        .card::before{
            content:"";position:absolute;inset:0;background:linear-gradient(135deg, rgba(200,155,60,.07), transparent 35%, transparent 75%, rgba(200,155,60,.05));pointer-events:none;
        }
        .card-grid{display:grid;grid-template-columns:180px 1fr auto;gap:16px;align-items:start;position:relative;z-index:1}
        .thumb{
            width:100%;height:180px;border-radius:16px;overflow:hidden;border:1px solid rgba(255,255,255,.08);background:rgba(0,0,0,.28);display:flex;align-items:center;justify-content:center
        }
        .thumb img{width:100%;height:100%;object-fit:contain;background:#0c0c0f}
        .thumb-empty{font-size:13px;color:#aaa;padding:12px;text-align:center}
        .folio{color:var(--gold);font-size:12px;font-weight:800;letter-spacing:.8px;margin-bottom:8px}
        .cliente{font-size:22px;font-weight:800;margin-bottom:10px}
        .meta{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px 16px}
        .meta div{font-size:13px;color:#ddd;line-height:1.5;word-break:break-word}
        .meta strong{color:#fff}
        .estado-badge{
            display:inline-flex;align-items:center;justify-content:center;padding:8px 12px;border-radius:999px;
            background:rgba(200,155,60,.16);border:1px solid rgba(200,155,60,.24);color:#fff;font-weight:800;font-size:12px;margin-bottom:12px
        }
        .actions{display:grid;gap:10px;min-width:210px}
        .btn-print{background:#dbeafe;color:#1d4ed8}
        .btn-wsp{background:#dcfce7;color:#166534}
        .btn-entregado{background:#22c55e;color:#fff}
        .empty{border:1px dashed rgba(255,255,255,.10);border-radius:18px;padding:20px;text-align:center;color:#bbb;background:rgba(255,255,255,.02)}
        @keyframes glow{from{filter:drop-shadow(0 0 5px rgba(200,155,60,.4))}to{filter:drop-shadow(0 0 15px rgba(200,155,60,.7))}}
        @keyframes logoPulse{0%,100%{transform:scale(1)}50%{transform:scale(1.08)}}
        @media (max-width:1180px){.card-grid{grid-template-columns:1fr}.actions{min-width:auto}.meta{grid-template-columns:1fr}}
        @media (max-width:840px){.toolbar form{grid-template-columns:1fr}.content{padding:16px 12px}.hero h1{font-size:26px}}
    </style>
</head>
<body>
    <aside class="sidebar">
        <img src="<?php echo e($logoActual); ?>" alt="Logo" class="logo-pos">
        <div class="nav-controls">
            <a href="dashboard.php" title="Dashboard"><i class="fas fa-home"></i></a>
            <a href="ventas.php" title="Ventas"><i class="fas fa-cash-register"></i></a>
            <a href="clientes.php" title="Clientes"><i class="fas fa-users"></i></a>
            <a href="produccion.php" title="Producción"><i class="fas fa-industry"></i></a>
            <a href="pedidos.php" title="Pedidos" class="active"><i class="fas fa-clipboard-list"></i></a>
            <a href="configuracion.php" title="Configuración"><i class="fas fa-cog"></i></a>
        </div>
        <a href="logout.php" class="exit-btn" title="Cerrar sesión"><i class="fas fa-sign-out-alt"></i></a>
    </aside>

    <main class="content">
        <section class="hero">
            <h1>Pedidos y remisiones</h1>
            <p>Busca por folio, cliente o teléfono y desde aquí reimprime, reenvía o entrega según el estatus.</p>
        </section>

        <?php if ($mensaje !== ''): ?>
            <div class="notice-success"><?php echo e($mensaje); ?></div>
        <?php endif; ?>

        <?php if ($error !== ''): ?>
            <div class="notice-error"><?php echo e($error); ?></div>
        <?php endif; ?>

        <section class="toolbar">
            <form method="GET">
                <input type="text" name="buscar" value="<?php echo e($buscar); ?>" placeholder="Buscar por folio, cliente o teléfono...">
                <select name="estado">
                    <?php foreach (['TODOS' => 'Todos los estados', 'NUEVO' => 'Nuevo', 'RECIBIDO' => 'Recibido', 'EN PROCESO' => 'En proceso', 'LISTO' => 'Listo', 'ENTREGADO' => 'Entregado'] as $key => $label): ?>
                        <option value="<?php echo e($key); ?>" <?php echo $filtroEstado === $key ? 'selected' : ''; ?>><?php echo e($label); ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn-top">Buscar</button>
                <a href="pedidos.php" class="btn-top btn-clear">Limpiar</a>
            </form>
        </section>

        <section class="cards">
            <?php if (!empty($pedidos)): ?>
                <?php foreach ($pedidos as $pedido): ?>
                    <?php
                    $estado = obtenerValorEstado($pedido);
                    $ventaId = (int)($pedido['venta_id'] ?? 0);
                    $urlImpresion = 'imprimir_remision.php?id=' . $ventaId;
                    ?>
                    <article class="card">
                        <div class="card-grid">
                            <div class="thumb">
                                <?php if (!empty($pedido['imagen_final'])): ?>
                                    <img src="<?php echo e($pedido['imagen_final']); ?>" alt="Diseño">
                                <?php else: ?>
                                    <div class="thumb-empty">Sin imagen del diseño</div>
                                <?php endif; ?>
                            </div>

                            <div>
                                <div class="folio"><?php echo e($pedido['folio'] ?? 'SIN FOLIO'); ?></div>
                                <div class="cliente"><?php echo e($pedido['cliente_nombre'] ?? 'Cliente'); ?></div>
                                <div class="estado-badge"><?php echo e(nombreBonitoEstado($estado)); ?></div>

                                <div class="meta">
                                    <div><strong>Teléfono:</strong> <?php echo e($pedido['cliente_telefono'] ?? '-'); ?></div>
                                    <div><strong>Tipo:</strong> <?php echo e($pedido['tipo_cliente'] ?? '-'); ?></div>
                                    <div><strong>Producto:</strong> <?php echo e($pedido['producto'] ?? '-'); ?></div>
                                    <div><strong>Talla:</strong> <?php echo e($pedido['talla'] ?? '-'); ?></div>
                                    <div><strong>Color:</strong> <?php echo e($pedido['color'] ?? '-'); ?></div>
                                    <div><strong>Diseño:</strong> <?php echo e($pedido['diseno'] ?? '-'); ?></div>
                                    <div><strong>Fecha entrega:</strong> <?php echo e($pedido['fecha_entrega'] ?? '-'); ?></div>
                                    <div><strong>Observaciones:</strong> <?php echo e($pedido['observaciones'] ?? '-'); ?></div>
                                </div>
                            </div>

                            <div class="actions">
                                <a href="<?php echo e($urlImpresion); ?>" target="_blank" class="btn btn-print">Reimprimir remisión</a>

                                <form method="POST">
                                    <input type="hidden" name="accion" value="reenviar_whatsapp">
                                    <input type="hidden" name="pedido_id" value="<?php echo (int)$pedido['id']; ?>">
                                    <button type="submit" class="btn btn-wsp">Reenviar WhatsApp</button>
                                </form>

                                <?php if ($estado === 'LISTO'): ?>
                                    <form method="POST" onsubmit="return confirm('¿Marcar este pedido como ENTREGADO?');">
                                        <input type="hidden" name="accion" value="marcar_entregado">
                                        <input type="hidden" name="pedido_id" value="<?php echo (int)$pedido['id']; ?>">
                                        <button type="submit" class="btn btn-entregado">Entregado</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="empty">No se encontraron pedidos con ese filtro.</div>
            <?php endif; ?>
        </section>
    </main>
</body>
</html>

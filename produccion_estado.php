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
    $tablaSegura = $conn->real_escape_string($tabla);
    $res = $conn->query("SHOW TABLES LIKE '{$tablaSegura}'");
    return ($res && $res->num_rows > 0);
}

function obtenerColumnasTabla(mysqli $conn, string $tabla): array
{
    $columnas = [];
    if (!existeTabla($conn, $tabla)) {
        return $columnas;
    }

    $res = $conn->query("SHOW COLUMNS FROM `$tabla`");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $columnas[] = $row['Field'];
        }
    }

    return $columnas;
}

function tieneColumna(array $columnas, string $columna): bool
{
    return in_array($columna, $columnas, true);
}

function normalizarEstado(string $estado): string
{
    $estado = trim($estado);
    $base = function_exists('mb_strtolower') ? mb_strtolower($estado, 'UTF-8') : strtolower($estado);

    return match ($base) {
        'nuevo', 'pedido', 'pendiente', '' => 'NUEVO',
        'recibido', 'pedido recibido' => 'RECIBIDO',
        'en proceso', 'proceso' => 'EN PROCESO',
        'listo', 'terminado', 'listo para entrega' => 'LISTO',
        'entregado' => 'ENTREGADO',
        default => strtoupper($estado),
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

function greenApiSendMessageSafe(string $phone, string $message): array
{
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'mensaje' => 'cURL no disponible'];
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

function construirMensajeCliente(string $cliente, string $folio, string $estado): string
{
    $cliente = trim($cliente) !== '' ? trim($cliente) : 'cliente';
    $folio = trim($folio) !== '' ? trim($folio) : 'SIN FOLIO';
    $estado = normalizarEstado($estado);

    return match ($estado) {
        'RECIBIDO' => "Hola {$cliente} 👋\n\nTu pedido con folio *{$folio}* ya fue recibido correctamente.",
        'EN PROCESO' => "Hola {$cliente} 👋\n\nTu pedido con folio *{$folio}* ya está en proceso.",
        'LISTO' => "Hola {$cliente} 👋\n\nTu pedido con folio *{$folio}* ya está listo para entregar.",
        default => "Hola {$cliente} 👋\n\nTu pedido con folio *{$folio}* tuvo una actualización.",
    };
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: produccion.php?tipo=error&msg=" . urlencode('Acceso no válido'));
    exit;
}

$pedidoId = isset($_POST['pedido_id']) ? (int)$_POST['pedido_id'] : 0;
$nuevoEstado = normalizarEstado($_POST['nuevo_estado'] ?? '');

if ($pedidoId <= 0 || !in_array($nuevoEstado, ['RECIBIDO', 'EN PROCESO', 'LISTO'], true)) {
    header("Location: produccion.php?tipo=error&msg=" . urlencode('Datos inválidos para actualizar'));
    exit;
}

try {
    if (!existeTabla($conn, 'pedidos')) {
        throw new Exception('La tabla pedidos no existe');
    }

    $colsPedidos = obtenerColumnasTabla($conn, 'pedidos');
    if (!tieneColumna($colsPedidos, 'estado')) {
        $conn->query("ALTER TABLE pedidos ADD COLUMN estado VARCHAR(50) NOT NULL DEFAULT 'NUEVO'");
    }
    if (!tieneColumna($colsPedidos, 'estatus')) {
        $conn->query("ALTER TABLE pedidos ADD COLUMN estatus VARCHAR(50) NOT NULL DEFAULT 'NUEVO'");
    }

    $stmt = $conn->prepare("SELECT * FROM pedidos WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $pedidoId);
    $stmt->execute();
    $pedido = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$pedido) {
        throw new Exception('No se encontró el pedido');
    }

    $ventaId = (int)($pedido['venta_id'] ?? 0);
    $clienteNombre = trim((string)($pedido['cliente_nombre'] ?? ''));
    $clienteTelefono = trim((string)($pedido['cliente_telefono'] ?? ''));
    $folio = trim((string)($pedido['folio'] ?? ''));

    if (($clienteNombre === '' || $clienteTelefono === '' || $folio === '') && $ventaId > 0 && existeTabla($conn, 'ventas')) {
        $colsVentas = obtenerColumnasTabla($conn, 'ventas');
        $select = [];

        foreach (['cliente_nombre', 'cliente_telefono', 'folio'] as $col) {
            if (tieneColumna($colsVentas, $col)) {
                $select[] = $col;
            }
        }

        if (!empty($select)) {
            $stmtVenta = $conn->prepare("SELECT " . implode(', ', $select) . " FROM ventas WHERE id = ? LIMIT 1");
            $stmtVenta->bind_param("i", $ventaId);
            $stmtVenta->execute();
            $venta = $stmtVenta->get_result()->fetch_assoc();
            $stmtVenta->close();

            if ($venta) {
                if ($clienteNombre === '' && !empty($venta['cliente_nombre'])) $clienteNombre = trim((string)$venta['cliente_nombre']);
                if ($clienteTelefono === '' && !empty($venta['cliente_telefono'])) $clienteTelefono = trim((string)$venta['cliente_telefono']);
                if ($folio === '' && !empty($venta['folio'])) $folio = trim((string)$venta['folio']);
            }
        }
    }

    $conn->begin_transaction();

    $stmtUpd = $conn->prepare("UPDATE pedidos SET estado = ?, estatus = ? WHERE id = ? LIMIT 1");
    $stmtUpd->bind_param("ssi", $nuevoEstado, $nuevoEstado, $pedidoId);
    $stmtUpd->execute();
    $stmtUpd->close();

    if ($ventaId > 0 && existeTabla($conn, 'ventas')) {
        $colsVentas = obtenerColumnasTabla($conn, 'ventas');

        if (tieneColumna($colsVentas, 'estado') && tieneColumna($colsVentas, 'estatus')) {
            $stmtUpdVenta = $conn->prepare("UPDATE ventas SET estado = ?, estatus = ? WHERE id = ? LIMIT 1");
            $stmtUpdVenta->bind_param("ssi", $nuevoEstado, $nuevoEstado, $ventaId);
            $stmtUpdVenta->execute();
            $stmtUpdVenta->close();
        }
    }

    $conn->commit();

    $msg = 'Estado actualizado correctamente';

    if ($clienteTelefono !== '') {
        $texto = construirMensajeCliente($clienteNombre, $folio, $nuevoEstado);
        $envio = greenApiSendMessageSafe($clienteTelefono, $texto);

        if ($envio['ok']) {
            $msg = 'Estado actualizado y WhatsApp enviado';
        } else {
            $msg = 'Estado actualizado, pero no se pudo enviar WhatsApp';
        }
    }

    header("Location: produccion.php?tipo=ok&msg=" . urlencode($msg));
    exit;
} catch (Throwable $e) {
    try {
        $conn->rollback();
    } catch (Throwable $rollbackError) {
    }

    header("Location: produccion.php?tipo=error&msg=" . urlencode('Error al actualizar: ' . $e->getMessage()));
    exit;
}
?>
<?php
require_once 'config/database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    die("Acceso no válido");
}

$id = isset($_POST['id']) ? intval($_POST['id']) : 0;
$estatus = isset($_POST['estatus']) ? trim($_POST['estatus']) : '';

$permitidos = ['pedido_recibido', 'en_proceso', 'listo', 'entregado'];

if ($id <= 0 || !in_array($estatus, $permitidos, true)) {
    die("Datos inválidos");
}

$stmt = $conn->prepare("UPDATE ventas SET estatus_pedido = ? WHERE id = ?");
$stmt->bind_param("si", $estatus, $id);
$stmt->execute();

$stmtVenta = $conn->prepare("SELECT * FROM ventas WHERE id = ?");
$stmtVenta->bind_param("i", $id);
$stmtVenta->execute();
$venta = $stmtVenta->get_result()->fetch_assoc();

function textoEstatus($estatus) {
    switch ($estatus) {
        case 'pedido_recibido': return 'Pedido recibido';
        case 'en_proceso': return 'En proceso';
        case 'listo': return 'Listo para entregar';
        case 'entregado': return 'Entregado';
        default: return 'Pedido recibido';
    }
}

function enviarWhatsApp($telefono, $mensaje) {
    $apikey = "3953858";

    $telefono = preg_replace('/[^0-9]/', '', $telefono);

    if (strlen($telefono) === 10) {
        $telefono = "521" . $telefono;
    }

    $mensaje = urlencode($mensaje);

    $url = "https://api.callmebot.com/whatsapp.php?phone={$telefono}&text={$mensaje}&apikey={$apikey}";

    $respuesta = @file_get_contents($url);

    return $respuesta !== false;
}

if ($venta && !empty($venta['cliente_telefono'])) {
    $mensaje = "Suave Urban Studio\n\n"
             . "Actualización de tu pedido\n"
             . "Folio: " . $venta['folio'] . "\n"
             . "Estado: " . textoEstatus($estatus) . "\n\n"
             . "Consulta tu remisión aquí:\n"
             . "https://suaveurbanstudio.com.mx/verificar_remision.php?id=" . $id;

    enviarWhatsApp($venta['cliente_telefono'], $mensaje);
}

header("Location: verificar_remision.php?id=" . $id);
exit;
?>
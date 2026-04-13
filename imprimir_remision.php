<?php
require_once 'config/database.php';

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    die("Venta no válida");
}

$id = intval($_GET['id']);

function e($valor): string
{
    return htmlspecialchars((string)$valor, ENT_QUOTES, 'UTF-8');
}

function existeTabla(mysqli $conn, string $tabla): bool
{
    $tabla = $conn->real_escape_string($tabla);
    $res = $conn->query("SHOW TABLES LIKE '{$tabla}'");
    return ($res && $res->num_rows > 0);
}

$logoActual = 'logo.png';
$descripcionNegocio = 'Ropa y personalización';

if (existeTabla($conn, 'configuracion')) {
    $resConfig = $conn->query("SELECT * FROM configuracion WHERE id = 1 LIMIT 1");
    if ($resConfig && $resConfig->num_rows > 0) {
        $config = $resConfig->fetch_assoc();
        if (!empty($config['logo'])) {
            $logoActual = $config['logo'];
        }
        if (!empty($config['descripcion_negocio'])) {
            $descripcionNegocio = $config['descripcion_negocio'];
        }
    }
}

$stmtVenta = $conn->prepare("SELECT * FROM ventas WHERE id = ? LIMIT 1");
$stmtVenta->bind_param("i", $id);
$stmtVenta->execute();
$venta = $stmtVenta->get_result()->fetch_assoc();
$stmtVenta->close();

if (!$venta) {
    die("Venta no encontrada");
}


if (!empty($venta['cliente_id']) && existeTabla($conn, 'clientes')) {
    $colsClientes = [];
    $resCols = $conn->query("SHOW COLUMNS FROM `clientes`");
    if ($resCols) {
        while ($rowCol = $resCols->fetch_assoc()) {
            $colsClientes[] = $rowCol['Field'];
        }
    }

    $selectCliente = [];
    foreach (['nombre', 'telefono', 'direccion', 'email', 'tipo_cliente'] as $col) {
        if (in_array($col, $colsClientes, true)) {
            $selectCliente[] = $col;
        }
    }

    if (!empty($selectCliente)) {
        $stmtCliente = $conn->prepare("SELECT " . implode(', ', $selectCliente) . " FROM clientes WHERE id = ? LIMIT 1");
        $clienteIdTmp = (int)$venta['cliente_id'];
        $stmtCliente->bind_param("i", $clienteIdTmp);
        $stmtCliente->execute();
        $clienteDb = $stmtCliente->get_result()->fetch_assoc();
        $stmtCliente->close();

        if ($clienteDb) {
            if ((empty($venta['cliente_nombre']) || trim((string)$venta['cliente_nombre']) === '' || trim((string)$venta['cliente_nombre']) === 'Público en general') && !empty($clienteDb['nombre'])) {
                $venta['cliente_nombre'] = $clienteDb['nombre'];
            }
            if ((empty($venta['cliente_telefono']) || trim((string)$venta['cliente_telefono']) === '') && !empty($clienteDb['telefono'])) {
                $venta['cliente_telefono'] = $clienteDb['telefono'];
            }
            if ((empty($venta['cliente_direccion']) || trim((string)$venta['cliente_direccion']) === '') && !empty($clienteDb['direccion'])) {
                $venta['cliente_direccion'] = $clienteDb['direccion'];
            }
            if ((empty($venta['cliente_email']) || trim((string)$venta['cliente_email']) === '') && !empty($clienteDb['email'])) {
                $venta['cliente_email'] = $clienteDb['email'];
            }
            if ((empty($venta['tipo_cliente']) || trim((string)$venta['tipo_cliente']) === '') && !empty($clienteDb['tipo_cliente'])) {
                $venta['tipo_cliente'] = $clienteDb['tipo_cliente'];
            }
        }
    }
}

$textoQR = 'https://suaveurbanstudio.com.mx/verificar_remision.php?id=' . $id;
$qrURL = "https://api.qrserver.com/v1/create-qr-code/?size=220x220&data=" . urlencode($textoQR);

$detalles = [];
if (existeTabla($conn, 'ventas_detalle')) {
    $stmtDetalles = $conn->prepare("SELECT * FROM ventas_detalle WHERE venta_id = ? ORDER BY id ASC");
    $stmtDetalles->bind_param("i", $id);
    $stmtDetalles->execute();
    $resDetalles = $stmtDetalles->get_result();
    while ($row = $resDetalles->fetch_assoc()) {
        $detalles[] = $row;
    }
    $stmtDetalles->close();
}

$folio = $venta['folio'] ?? ('REM-' . $id);
$fechaVenta = $venta['fecha_venta'] ?? ($venta['fecha'] ?? date('Y-m-d'));
$fechaEntrega = $venta['fecha_entrega'] ?? '';
$diaEntrega = $venta['dia_entrega'] ?? '';
$clienteNombre = $venta['cliente_nombre'] ?? 'Público en general';
$clienteTelefono = $venta['cliente_telefono'] ?? '';
$clienteDireccion = $venta['cliente_direccion'] ?? '';
$clienteEmail = $venta['cliente_email'] ?? '';
$tipoCliente = $venta['tipo_cliente'] ?? '';
$metodoPago = $venta['metodo_pago'] ?? '';
$mensajeRemision = $venta['mensaje_remision'] ?? '';
$observaciones = $venta['observaciones'] ?? '';
$subtotal = isset($venta['subtotal']) ? (float)$venta['subtotal'] : (float)($venta['total'] ?? 0);
$total = isset($venta['total']) ? (float)($venta['total']) : 0;
$anticipo = isset($venta['anticipo']) ? (float)$venta['anticipo'] : 0;
$saldoPendiente = isset($venta['saldo_pendiente']) ? (float)$venta['saldo_pendiente'] : (isset($venta['resta']) ? (float)$venta['resta'] : max(0, $total - $anticipo));

$preciosSumados = 0.0;
foreach ($detalles as &$detTmp) {
    $nombreTmp = trim((string)($detTmp['nombre_producto'] ?? ''));
    if ($nombreTmp === '' || strtoupper($nombreTmp) === 'PRODUCTO') {
        if (!empty($detTmp['tipo_producto'])) {
            $detTmp['nombre_producto'] = $detTmp['tipo_producto'];
        } elseif (!empty($detTmp['categoria'])) {
            $detTmp['nombre_producto'] = $detTmp['categoria'];
        } elseif (!empty($detTmp['descripcion_corta'])) {
            $detTmp['nombre_producto'] = $detTmp['descripcion_corta'];
        } else {
            $detTmp['nombre_producto'] = 'Producto';
        }
    }
    $preciosSumados += (float)($detTmp['precio'] ?? 0);
}
unset($detTmp);

if (!empty($detalles) && $preciosSumados <= 0 && $total > 0) {
    $precioVisual = $total / count($detalles);
    foreach ($detalles as &$detTmp2) {
        $detTmp2['_precio_visual'] = $precioVisual;
    }
    unset($detTmp2);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Nota de Remisión <?php echo e($folio); ?></title>
<style>
* {
    box-sizing: border-box;
    -webkit-print-color-adjust: exact !important;
    print-color-adjust: exact !important;
}

body {
    margin: 0;
    font-family: Arial, sans-serif;
    background: #dcdcdc;
    color: #111;
}

.page {
    width: 900px;
    margin: 20px auto;
    background: #fff;
    box-shadow: 0 8px 30px rgba(0,0,0,0.12);
}

.header {
    background: #000 !important;
    color: #fff !important;
    padding: 25px;
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.header-left {
    display: flex;
    align-items: center;
    gap: 18px;
}

.logo-wrap {
    width: 100px;
    height: 100px;
    display: flex;
    align-items: center;
    justify-content: center;
}

.logo {
    max-width: 160px;
    max-height: 160px;
    object-fit: contain;
    position: relative;
    top: 15px;
}

.business h1 {
    margin: 0 0 6px 0;
    font-size: 28px;
    color: #fff !important;
}

.business p {
    margin: 0;
    font-size: 14px;
    color: #e2e2e2 !important;
}

.folio-box {
    text-align: right;
    color: #fff !important;
}

.folio-box h2 {
    margin: 0 0 8px 0;
    font-size: 24px;
    color: #fff !important;
}

.content {
    padding: 28px;
}

.section-title {
    margin: 0 0 12px 0;
    font-size: 15px;
    font-weight: bold;
    color: #000;
    border-bottom: 2px solid #000;
    padding-bottom: 6px;
}

.grid-2 {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
    margin-bottom: 25px;
}

.box {
    background: #f7f7f7;
    border: 1px solid #d6d6d6;
    border-radius: 10px;
    padding: 15px;
}

.box p {
    margin: 6px 0;
    font-size: 14px;
}

table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 8px;
}

th {
    background: #000 !important;
    color: #fff !important;
    padding: 12px;
    font-size: 13px;
    text-align: left;
}

td {
    padding: 12px;
    border-bottom: 1px solid #ddd;
    font-size: 13px;
    vertical-align: top;
}

.total-box {
    margin-top: 20px;
    display: flex;
    justify-content: flex-end;
}

.total-inner {
    width: 360px;
    background: #000 !important;
    color: #fff !important;
    border-radius: 12px;
    padding: 18px;
}

.total-inner div {
    display: flex;
    justify-content: space-between;
    margin-bottom: 8px;
}

.total-inner div:last-child {
    margin-bottom: 0;
    font-size: 22px;
    font-weight: bold;
}

.firmas {
    margin-top: 60px;
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
}

.firma-box {
    width: 40%;
    text-align: center;
}

.firma-linea {
    margin-top: 60px;
    border-top: 2px solid #000;
    padding-top: 8px;
    font-size: 14px;
    font-weight: bold;
    letter-spacing: 0.5px;
}

.qr-section {
    margin-top: 30px;
    display: flex;
    justify-content: center;
    align-items: center;
    flex-direction: column;
    gap: 8px;
}

.qr-box {
    width: 115px;
    height: 115px;
    background: #fff;
    border: 1px solid #d6d6d6;
    padding: 6px;
    display: flex;
    align-items: center;
    justify-content: center;
}

.qr-box img {
    width: 100%;
    height: 100%;
    object-fit: contain;
    display: block;
}

.qr-label {
    font-size: 11px;
    color: #444;
    text-transform: uppercase;
    letter-spacing: 0.8px;
    text-align: center;
}

.footer {
    margin-top: 26px;
    padding: 18px 28px 28px;
    color: #444;
    font-size: 13px;
    text-align: center;
    line-height: 1.7;
}

.footer-aviso {
    font-size: 14px;
    font-weight: 700;
    line-height: 1.7;
    letter-spacing: 0.3px;
    text-transform: uppercase;
    color: #222;
}

.footer-aviso span {
    display: block;
}

.footer-extra {
    margin-top: 10px;
    font-size: 12px;
    color: #555;
    line-height: 1.6;
}

.print-btn {
    position: fixed;
    right: 20px;
    top: 20px;
    background: #000;
    color: #fff;
    border: 0;
    padding: 12px 18px;
    border-radius: 8px;
    cursor: pointer;
    z-index: 1000;
}

@media print {
    body {
        background: #fff !important;
        margin: 0;
    }

    .print-btn {
        display: none !important;
    }

    .page {
        width: 100%;
        margin: 0;
        box-shadow: none;
    }
}
</style>
</head>
<body>

<button class="print-btn" onclick="window.print()">Imprimir remisión</button>

<div class="page">

    <div class="header">
        <div class="header-left">
            <div class="logo-wrap">
                <img src="<?php echo e($logoActual); ?>" alt="Logo" class="logo">
            </div>
            <div class="business">
                <h1>Suave Urban Studio</h1>
                <p><?php echo e($descripcionNegocio); ?></p>
            </div>
        </div>

        <div class="folio-box">
            <h2>NOTA DE REMISIÓN</h2>
            <div><strong>Folio:</strong> <?php echo e($folio); ?></div>
            <div><strong>Fecha venta:</strong> <?php echo e($fechaVenta); ?></div>
            <?php if ($fechaEntrega !== ''): ?>
                <div><strong>Fecha entrega:</strong> <?php echo e($fechaEntrega); ?></div>
            <?php endif; ?>
            <?php if ($diaEntrega !== ''): ?>
                <div><strong>Día:</strong> <?php echo e($diaEntrega); ?></div>
            <?php endif; ?>
        </div>
    </div>

    <div class="content">

        <div class="grid-2">
            <div>
                <div class="section-title">Datos del cliente</div>
                <div class="box">
                    <p><strong>Nombre:</strong> <?php echo e($clienteNombre !== '' ? $clienteNombre : 'Público en general'); ?></p>
                    <p><strong>Teléfono:</strong> <?php echo e($clienteTelefono !== '' ? $clienteTelefono : '-'); ?></p>
                    <p><strong>Dirección:</strong> <?php echo e($clienteDireccion !== '' ? $clienteDireccion : '-'); ?></p>
                    <p><strong>Correo:</strong> <?php echo e($clienteEmail !== '' ? $clienteEmail : '-'); ?></p>
                    <p><strong>Tipo de cliente:</strong> <?php echo e($tipoCliente !== '' ? $tipoCliente : '-'); ?></p>
                </div>
            </div>

            <div>
                <div class="section-title">Datos de la venta</div>
                <div class="box">
                    <p><strong>Método de pago:</strong> <?php echo e($metodoPago); ?></p>
                    <p><strong>Subtotal:</strong> $<?php echo number_format($subtotal, 2); ?></p>
                    <p><strong>Total:</strong> $<?php echo number_format($total, 2); ?></p>
                    <p><strong>Anticipo / Abono:</strong> $<?php echo number_format($anticipo, 2); ?></p>
                    <p><strong>Saldo pendiente:</strong> $<?php echo number_format($saldoPendiente, 2); ?></p>
                    <?php if ($mensajeRemision !== ''): ?>
                        <p><strong>Mensaje:</strong> <?php echo e($mensajeRemision); ?></p>
                    <?php endif; ?>
                    <?php if ($observaciones !== ''): ?>
                        <p><strong>Observaciones:</strong> <?php echo e($observaciones); ?></p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="section-title">Detalle de productos</div>

        <table>
            <thead>
                <tr>
                    <th>Producto</th>
                    <th>Detalles</th>
                    <th>Precio</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($detalles)): ?>
                    <?php foreach ($detalles as $item): ?>
                        <tr>
                            <td><strong><?php echo e($item['nombre_producto'] ?? 'Producto'); ?></strong></td>
                            <td>
                                <?php if (!empty($item['categoria'])): ?><div><strong>Categoría:</strong> <?php echo e($item['categoria']); ?></div><?php endif; ?>
                                <?php if (!empty($item['tipo_producto'])): ?><div><strong>Producto:</strong> <?php echo e($item['tipo_producto']); ?></div><?php endif; ?>
                                <?php if (!empty($item['talla'])): ?><div><strong>Talla:</strong> <?php echo e($item['talla']); ?></div><?php endif; ?>
                                <?php if (!empty($item['color'])): ?><div><strong>Color:</strong> <?php echo e($item['color']); ?></div><?php endif; ?>
                                <?php if (!empty($item['diseno'])): ?><div><strong>Diseño:</strong> <?php echo e($item['diseno']); ?></div><?php endif; ?>
                                <?php if (!empty($item['descripcion_corta'])): ?><div><strong>Detalle:</strong> <?php echo e($item['descripcion_corta']); ?></div><?php endif; ?>
                                <?php if (!empty($item['descripcion'])): ?><div><strong>Descripción:</strong> <?php echo e($item['descripcion']); ?></div><?php endif; ?>
                            </td>
                            <td>$<?php echo number_format((float)($item['_precio_visual'] ?? $item['precio'] ?? 0), 2); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="3">No hay productos registrados en esta remisión.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>

        <div class="total-box">
            <div class="total-inner">
                <div><span>Subtotal</span><span>$<?php echo number_format($subtotal, 2); ?></span></div>
                <div><span>Anticipo</span><span>$<?php echo number_format($anticipo, 2); ?></span></div>
                <div><span>Saldo</span><span>$<?php echo number_format($saldoPendiente, 2); ?></span></div>
                <div><span>Total</span><span>$<?php echo number_format($total, 2); ?></span></div>
            </div>
        </div>

        <div class="firmas">
            <div class="firma-box">
                <div class="firma-linea">QUIEN ENTREGA</div>
            </div>

            <div class="firma-box">
                <div class="firma-linea">QUIEN RECIBE</div>
            </div>
        </div>

        <div class="qr-section">
            <div class="qr-box">
                <img src="<?php echo e($qrURL); ?>" alt="QR de remisión">
            </div>
            <div class="qr-label">Validación de remisión</div>
        </div>

    </div>

    <div class="footer">
        <div class="footer-aviso">
            <span>Este documento funciona como</span>
            <span>comprobante de venta y</span>
            <span>nota de remisión.</span>
            <span>Deberá presentarse para la</span>
            <span>entrega o recolección de la mercancía.</span>
        </div>

        <div class="footer-extra">
            Gracias por su preferencia.<br>
            Suave Urban Studio
        </div>
    </div>

</div>

</body>
</html>
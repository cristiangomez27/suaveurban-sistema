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
        if (!empty($config['logo'])) $logoActual = $config['logo'];
        if (!empty($config['descripcion_negocio'])) $descripcionNegocio = $config['descripcion_negocio'];
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
$metodoPago = $venta['metodo_pago'] ?? '';
$total = isset($venta['total']) ? (float)$venta['total'] : 0;
$textoQR = 'https://suaveurbanstudio.com.mx/verificar_remision.php?id=' . $id;
$qrURL = "https://api.qrserver.com/v1/create-qr-code/?size=220x220&data=" . urlencode($textoQR);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=1100">
<title>Remisión Imagen <?php echo e($folio); ?></title>
<style>
*{box-sizing:border-box}
body{
    margin:0;
    background:#f0f0f0;
    font-family:Arial,sans-serif;
}
.canvas{
    width:1100px;
    margin:0 auto;
    background:#fff;
    color:#111;
}
.header{
    background:#000;
    color:#fff;
    padding:30px;
    display:flex;
    justify-content:space-between;
    align-items:center;
}
.header-left{
    display:flex;
    align-items:center;
    gap:20px;
}
.logo{
    max-width:150px;
    max-height:150px;
    object-fit:contain;
}
.title h1{
    margin:0 0 8px;
    font-size:34px;
    color:#fff;
}
.title p{
    margin:0;
    color:#ddd;
    font-size:15px;
}
.folio{
    text-align:right;
    font-size:15px;
    line-height:1.8;
}
.content{
    padding:30px;
}
.grid{
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:24px;
    margin-bottom:24px;
}
.box{
    background:#f8f8f8;
    border:1px solid #ddd;
    border-radius:12px;
    padding:18px;
}
.box h3{
    margin:0 0 14px;
    font-size:16px;
    border-bottom:2px solid #000;
    padding-bottom:6px;
}
.box p{
    margin:8px 0;
    font-size:15px;
}
table{
    width:100%;
    border-collapse:collapse;
    margin-top:12px;
}
th{
    background:#000;
    color:#fff;
    padding:12px;
    text-align:left;
    font-size:14px;
}
td{
    padding:12px;
    border-bottom:1px solid #ddd;
    vertical-align:top;
    font-size:14px;
}
.total{
    margin-top:20px;
    display:flex;
    justify-content:flex-end;
}
.total-box{
    width:320px;
    background:#000;
    color:#fff;
    border-radius:12px;
    padding:18px;
    font-size:16px;
}
.total-box .big{
    font-size:28px;
    font-weight:bold;
    margin-top:10px;
}
.bottom{
    display:flex;
    justify-content:space-between;
    align-items:flex-end;
    margin-top:35px;
}
.sign{
    width:38%;
    text-align:center;
}
.line{
    margin-top:70px;
    border-top:2px solid #000;
    padding-top:8px;
    font-weight:bold;
}
.qr{
    width:150px;
    text-align:center;
}
.qr img{
    width:120px;
    height:120px;
    display:block;
    margin:0 auto 8px;
    background:#fff;
    border:1px solid #ddd;
    padding:6px;
}
.footer{
    padding:0 30px 30px;
    text-align:center;
    color:#333;
    font-size:14px;
    line-height:1.7;
}
</style>
</head>
<body>
<div class="canvas">
    <div class="header">
        <div class="header-left">
            <img src="<?php echo e($logoActual); ?>" alt="Logo" class="logo">
            <div class="title">
                <h1>Suave Urban Studio</h1>
                <p><?php echo e($descripcionNegocio); ?></p>
            </div>
        </div>
        <div class="folio">
            <div><strong>NOTA DE REMISIÓN</strong></div>
            <div><strong>Folio:</strong> <?php echo e($folio); ?></div>
            <div><strong>Fecha venta:</strong> <?php echo e($fechaVenta); ?></div>
            <?php if ($fechaEntrega !== ''): ?><div><strong>Fecha entrega:</strong> <?php echo e($fechaEntrega); ?></div><?php endif; ?>
            <?php if ($diaEntrega !== ''): ?><div><strong>Día:</strong> <?php echo e($diaEntrega); ?></div><?php endif; ?>
        </div>
    </div>

    <div class="content">
        <div class="grid">
            <div class="box">
                <h3>Datos del cliente</h3>
                <p><strong>Nombre:</strong> <?php echo e($clienteNombre); ?></p>
                <p><strong>Teléfono:</strong> <?php echo e($clienteTelefono !== '' ? $clienteTelefono : '-'); ?></p>
                <p><strong>Dirección:</strong> <?php echo e($clienteDireccion !== '' ? $clienteDireccion : '-'); ?></p>
            </div>
            <div class="box">
                <h3>Datos de la venta</h3>
                <p><strong>Método de pago:</strong> <?php echo e($metodoPago); ?></p>
                <p><strong>Total:</strong> $<?php echo number_format($total, 2); ?></p>
            </div>
        </div>

        <div class="box">
            <h3>Detalle de productos</h3>
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
                                <?php if (!empty($item['talla'])): ?><div><strong>Talla:</strong> <?php echo e($item['talla']); ?></div><?php endif; ?>
                                <?php if (!empty($item['color'])): ?><div><strong>Color:</strong> <?php echo e($item['color']); ?></div><?php endif; ?>
                                <?php if (!empty($item['diseno'])): ?><div><strong>Diseño:</strong> <?php echo e($item['diseno']); ?></div><?php endif; ?>
                                <?php if (!empty($item['descripcion_corta'])): ?><div><strong>Detalle:</strong> <?php echo e($item['descripcion_corta']); ?></div><?php endif; ?>
                                <?php if (!empty($item['descripcion'])): ?><div><strong>Descripción:</strong> <?php echo e($item['descripcion']); ?></div><?php endif; ?>
                            </td>
                            <td>$<?php echo number_format((float)($item['precio'] ?? 0), 2); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="3">No hay productos registrados.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="total">
            <div class="total-box">
                <div>Total</div>
                <div class="big">$<?php echo number_format($total, 2); ?></div>
            </div>
        </div>

        <div class="bottom">
            <div class="sign">
                <div class="line">QUIEN ENTREGA</div>
            </div>
            <div class="qr">
                <img src="<?php echo e($qrURL); ?>" alt="QR">
                <div style="font-size:12px;">Validación de remisión</div>
            </div>
            <div class="sign">
                <div class="line">QUIEN RECIBE</div>
            </div>
        </div>
    </div>

    <div class="footer">
        Este documento funciona como comprobante de venta y nota de remisión.<br>
        Deberá presentarse para la entrega o recolección de la mercancía.
    </div>
</div>
</body>
</html>
<?php
require_once 'config/database.php';

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    die("Remisión inválida");
}

$id = intval($_GET['id']);

function existeTabla(mysqli $conn, string $tabla): bool
{
    $tabla = $conn->real_escape_string($tabla);
    $res = $conn->query("SHOW TABLES LIKE '{$tabla}'");
    return ($res && $res->num_rows > 0);
}

function e($valor): string
{
    return htmlspecialchars((string)$valor, ENT_QUOTES, 'UTF-8');
}

$stmtVenta = $conn->prepare("SELECT * FROM ventas WHERE id = ? LIMIT 1");
$stmtVenta->bind_param("i", $id);
$stmtVenta->execute();
$venta = $stmtVenta->get_result()->fetch_assoc();
$stmtVenta->close();

if (!$venta) {
    die("Remisión no encontrada");
}

/* Recuperar datos completos del cliente si la venta no los trae llenos */
if (!empty($venta['cliente_id']) && existeTabla($conn, 'clientes')) {
    $resColsClientes = $conn->query("SHOW COLUMNS FROM `clientes`");
    $colsClientes = [];
    if ($resColsClientes) {
        while ($rowCol = $resColsClientes->fetch_assoc()) {
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

/* Leer detalles correctos */
$detalles = [];
if (existeTabla($conn, 'ventas_detalle')) {
    $stmtDetalles = $conn->prepare("SELECT * FROM ventas_detalle WHERE venta_id = ? ORDER BY id ASC");
    $stmtDetalles->bind_param("i", $id);
    $stmtDetalles->execute();
    $resDetalles = $stmtDetalles->get_result();
    while ($row = $resDetalles->fetch_assoc()) {
        if (empty($row['nombre_producto']) || strtoupper(trim((string)$row['nombre_producto'])) === 'PRODUCTO') {
            if (!empty($row['tipo_producto'])) {
                $row['nombre_producto'] = $row['tipo_producto'];
            } elseif (!empty($row['categoria'])) {
                $row['nombre_producto'] = $row['categoria'];
            } elseif (!empty($row['descripcion_corta'])) {
                $row['nombre_producto'] = $row['descripcion_corta'];
            } else {
                $row['nombre_producto'] = 'Producto';
            }
        }
        $detalles[] = $row;
    }
    $stmtDetalles->close();
}

/* Estado correcto */
$estatus = trim((string)($venta['estado'] ?? $venta['estatus'] ?? 'NUEVO'));
$estatus = strtoupper($estatus);

$pasos = [
    'NUEVO' => 1,
    'RECIBIDO' => 2,
    'EN PROCESO' => 3,
    'LISTO' => 4,
    'ENTREGADO' => 4
];

$pasoActual = $pasos[$estatus] ?? 1;

function textoEstatus(string $estatus): string
{
    switch (strtoupper($estatus)) {
        case 'NUEVO': return 'Pedido nuevo';
        case 'RECIBIDO': return 'Pedido recibido';
        case 'EN PROCESO': return 'En proceso';
        case 'LISTO': return 'Listo para entregar';
        case 'ENTREGADO': return 'Entregado';
        default: return 'Pedido nuevo';
    }
}

$folio = $venta['folio'] ?? ('REM-' . $id);
$fechaVenta = $venta['fecha_venta'] ?? ($venta['fecha'] ?? '');
$fechaEntrega = $venta['fecha_entrega'] ?? '';
$diaEntrega = $venta['dia_entrega'] ?? '';
$clienteNombre = $venta['cliente_nombre'] ?? 'Público en general';
$clienteTelefono = $venta['cliente_telefono'] ?? '-';
$clienteDireccion = $venta['cliente_direccion'] ?? '-';
$clienteEmail = $venta['cliente_email'] ?? '-';
$tipoCliente = $venta['tipo_cliente'] ?? '-';
$total = isset($venta['total']) ? (float)$venta['total'] : 0;
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Verificación de remisión</title>
<style>
*{
    box-sizing:border-box;
}

body{
    margin:0;
    font-family:Arial,sans-serif;
    background:#0d0d0d;
    color:#fff;
}

.wrap{
    max-width:900px;
    margin:20px auto;
    padding:16px;
}

.card{
    background:#161616;
    border:1px solid rgba(200,155,60,0.2);
    border-radius:18px;
    padding:20px;
    box-shadow:0 10px 35px rgba(0,0,0,0.35);
}

h1{
    margin:0 0 8px 0;
    color:#c89b3c;
    font-size:32px;
}

.sub{
    color:#aaa;
    margin-bottom:20px;
    font-size:14px;
}

.grid{
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:18px;
    margin-bottom:22px;
}

.box{
    background:#1f1f1f;
    border:1px solid rgba(255,255,255,0.06);
    border-radius:14px;
    padding:16px;
}

.box p{
    margin:8px 0;
    line-height:1.5;
    word-break:break-word;
}

.status-title{
    font-size:20px;
    font-weight:bold;
    color:#c89b3c;
    margin-bottom:18px;
}

.progress{
    display:grid;
    grid-template-columns:repeat(4,1fr);
    gap:10px;
    margin-bottom:10px;
}

.step{
    text-align:center;
    padding:12px 10px;
    border-radius:12px;
    border:1px solid #333;
    background:#1b1b1b;
    color:#888;
    font-size:13px;
    line-height:1.4;
    min-height:64px;
    display:flex;
    align-items:center;
    justify-content:center;
}

.step.active{
    background:rgba(200,155,60,0.14);
    border-color:#c89b3c;
    color:#fff;
}

.step.done{
    background:rgba(40,167,69,0.16);
    border-color:#28a745;
    color:#fff;
}

.table-wrap{
    width:100%;
    overflow-x:auto;
    -webkit-overflow-scrolling:touch;
    border-radius:12px;
}

.table{
    width:100%;
    min-width:620px;
    border-collapse:collapse;
}

.table th{
    text-align:left;
    background:#000;
    color:#fff;
    padding:12px;
    font-size:14px;
}

.table td{
    padding:12px;
    border-bottom:1px solid #2c2c2c;
    vertical-align:top;
    font-size:14px;
}

.valid{
    margin-top:22px;
    padding:14px 18px;
    border-radius:12px;
    background:rgba(40,167,69,0.14);
    border:1px solid rgba(40,167,69,0.4);
    color:#d7ffe3;
    font-weight:bold;
    line-height:1.5;
}

@media (max-width: 768px){
    .wrap{
        padding:12px;
        margin:0;
    }

    .card{
        padding:16px;
        border-radius:0;
        min-height:100vh;
    }

    h1{
        font-size:26px;
        line-height:1.2;
    }

    .sub{
        font-size:13px;
    }

    .grid{
        grid-template-columns:1fr;
        gap:14px;
    }

    .progress{
        grid-template-columns:1fr 1fr;
    }

    .step{
        min-height:58px;
        font-size:12px;
        padding:10px 8px;
    }

    .box p{
        font-size:14px;
    }

    .table{
        min-width:520px;
    }

    .table th,
    .table td{
        font-size:13px;
        padding:10px;
    }

    .valid{
        font-size:14px;
    }
}
</style>
</head>
<body>
<div class="wrap">
    <div class="card">
        <h1>Verificación de remisión</h1>
        <div class="sub">Consulta del folio: <strong><?php echo e($folio); ?></strong></div>

        <div class="grid">
            <div class="box">
                <p><strong>Cliente:</strong> <?php echo e($clienteNombre); ?></p>
                <p><strong>Teléfono:</strong> <?php echo e($clienteTelefono); ?></p>
                <p><strong>Dirección:</strong> <?php echo e($clienteDireccion); ?></p>
                <p><strong>Correo:</strong> <?php echo e($clienteEmail); ?></p>
                <p><strong>Tipo de cliente:</strong> <?php echo e($tipoCliente); ?></p>
            </div>

            <div class="box">
                <p><strong>Fecha de venta:</strong> <?php echo e($fechaVenta); ?></p>
                <p><strong>Fecha de entrega:</strong> <?php echo e($fechaEntrega); ?></p>
                <p><strong>Día:</strong> <?php echo e($diaEntrega); ?></p>
                <p><strong>Total:</strong> $<?php echo number_format($total, 2); ?></p>
                <p><strong>Estatus actual:</strong> <?php echo e(textoEstatus($estatus)); ?></p>
            </div>
        </div>

        <div class="status-title">Seguimiento del pedido</div>

        <div class="progress">
            <div class="step <?php echo $pasoActual > 1 ? 'done' : ($pasoActual === 1 ? 'active' : ''); ?>">Nuevo</div>
            <div class="step <?php echo $pasoActual > 2 ? 'done' : ($pasoActual === 2 ? 'active' : ''); ?>">Recibido</div>
            <div class="step <?php echo $pasoActual > 3 ? 'done' : ($pasoActual === 3 ? 'active' : ''); ?>">En proceso</div>
            <div class="step <?php echo $pasoActual >= 4 ? 'active' : ''; ?>">Listo / Entregado</div>
        </div>

        <div class="table-wrap">
            <table class="table">
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
                        <tr>
                            <td colspan="3">No hay productos registrados en esta remisión.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="valid">
            Esta remisión es válida y corresponde al sistema de Suave Urban Studio.
        </div>
    </div>
</div>
</body>
</html>
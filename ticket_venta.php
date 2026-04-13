<?php
require_once 'config/database.php';

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    die("Venta no válida");
}

$id = intval($_GET['id']);

$logoActual = 'logo.png';
$resConfig = $conn->query("SELECT logo FROM configuracion WHERE id = 1 LIMIT 1");
if ($resConfig && $resConfig->num_rows > 0) {
    $config = $resConfig->fetch_assoc();
    if (!empty($config['logo'])) $logoActual = $config['logo'];
}

$stmtVenta = $conn->prepare("SELECT * FROM ventas WHERE id = ?");
$stmtVenta->bind_param("i", $id);
$stmtVenta->execute();
$venta = $stmtVenta->get_result()->fetch_assoc();

if (!$venta) {
    die("Venta no encontrada");
}

$stmtDetalles = $conn->prepare("SELECT * FROM ventas_detalle WHERE venta_id = ?");
$stmtDetalles->bind_param("i", $id);
$stmtDetalles->execute();
$detalles = $stmtDetalles->get_result();
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Ticket <?php echo htmlspecialchars($venta['folio']); ?></title>
<style>
    * {
        box-sizing: border-box;
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
    }

    body {
        margin: 0;
        padding: 0;
        font-family: Arial, sans-serif;
        color: #000;
        background: #fff;
    }

    .ticket {
        width: 80mm;
        max-width: 80mm;
        margin: 0 auto;
        padding: 8px;
        box-sizing: border-box;
        background: #fff;
    }

    .center { text-align: center; }

    .logo {
        max-width: 70px;
        max-height: 70px;
        object-fit: contain;
        display: block;
        margin: 0 auto 8px auto;
        filter: brightness(0);
    }

    .linea {
        border-top: 1px dashed #000;
        margin: 8px 0;
    }

    .item {
        font-size: 12px;
        margin-bottom: 8px;
    }

    .item strong {
        display: block;
    }

    .total {
        font-size: 16px;
        font-weight: bold;
        text-align: right;
        margin-top: 8px;
    }

    .small {
        font-size: 11px;
    }

    .btn-print {
        margin-top: 15px;
        width: 100%;
        padding: 10px;
        border: 0;
        background: #000;
        color: #fff;
        cursor: pointer;
    }

    @media print {
        .btn-print { display: none; }
        @page {
            size: 80mm auto;
            margin: 0;
        }
        body {
            margin: 0;
            background: #fff;
        }
    }
</style>
</head>
<body onload="window.print()">
    <div class="ticket">
        <div class="center">
            <img src="<?php echo htmlspecialchars($logoActual); ?>" alt="Logo" class="logo">
            <h2 style="margin:0;">Suave Urban Studio</h2>
            <div class="small">Ropa y personalización</div>
            <div class="small">Ticket de venta</div>
        </div>

        <div class="linea"></div>

        <div class="small"><strong>Folio:</strong> <?php echo htmlspecialchars($venta['folio']); ?></div>
        <div class="small"><strong>Fecha:</strong> <?php echo htmlspecialchars($venta['fecha_venta']); ?></div>
        <div class="small"><strong>Método:</strong> <?php echo htmlspecialchars($venta['metodo_pago']); ?></div>

        <?php if (!empty($venta['cliente_nombre'])): ?>
            <div class="small"><strong>Cliente:</strong> <?php echo htmlspecialchars($venta['cliente_nombre']); ?></div>
        <?php endif; ?>

        <div class="linea"></div>

        <?php while ($item = $detalles->fetch_assoc()): ?>
            <div class="item">
                <strong><?php echo htmlspecialchars($item['nombre_producto']); ?></strong>
                <div>$<?php echo number_format($item['precio'], 2); ?></div>

                <?php if (!empty($item['talla'])): ?><div class="small">Talla: <?php echo htmlspecialchars($item['talla']); ?></div><?php endif; ?>
                <?php if (!empty($item['color'])): ?><div class="small">Color: <?php echo htmlspecialchars($item['color']); ?></div><?php endif; ?>
                <?php if (!empty($item['diseno'])): ?><div class="small">Diseño: <?php echo htmlspecialchars($item['diseno']); ?></div><?php endif; ?>
            </div>
        <?php endwhile; ?>

        <div class="linea"></div>

        <div class="total">TOTAL: $<?php echo number_format($venta['total'], 2); ?></div>

        <div class="center small" style="margin-top:10px;">
            ¡Gracias por tu compra!
        </div>

        <button class="btn-print" onclick="window.print()">Imprimir ticket</button>
    </div>
</body>
</html>
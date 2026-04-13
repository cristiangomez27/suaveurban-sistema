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

$ventasPorMes = array_fill(1, 12, 0.0);
$gastosPorMes = array_fill(1, 12, 0.0);
$utilidadPorMes = array_fill(1, 12, 0.0);

$totalVentasAnual = 0.0;
$totalGastosAnual = 0.0;
$utilidadAnual = 0.0;
$ventasMesActual = 0.0;
$gastosMesActual = 0.0;
$utilidadMesActual = 0.0;

$ventasPorMetodo = [];
$ventasPorTipoCliente = [];
$productosMasVendidos = [];

$anioActual = (int)date('Y');

/*
|--------------------------------------------------------------------------
| VENTAS
|--------------------------------------------------------------------------
*/
if (existeTabla($conn, 'ventas')) {
    $colsVentas = obtenerColumnasTabla($conn, 'ventas');

    $colFecha = null;
    foreach (['fecha_venta', 'fecha'] as $c) {
        if (tieneColumna($colsVentas, $c)) {
            $colFecha = $c;
            break;
        }
    }

    if ($colFecha && tieneColumna($colsVentas, 'total')) {
        $sqlVentasMes = "
            SELECT MONTH($colFecha) AS mes, COALESCE(SUM(total),0) AS total
            FROM ventas
            WHERE YEAR($colFecha) = YEAR(CURDATE())
            GROUP BY MONTH($colFecha)
            ORDER BY MONTH($colFecha)
        ";
        $resVentasMes = $conn->query($sqlVentasMes);
        if ($resVentasMes) {
            while ($row = $resVentasMes->fetch_assoc()) {
                $mes = (int)$row['mes'];
                $ventasPorMes[$mes] = (float)$row['total'];
            }
        }

        $resTotalVentasAnual = $conn->query("
            SELECT COALESCE(SUM(total),0) AS total
            FROM ventas
            WHERE YEAR($colFecha) = YEAR(CURDATE())
        ");
        if ($resTotalVentasAnual) {
            $totalVentasAnual = (float)($resTotalVentasAnual->fetch_assoc()['total'] ?? 0);
        }

        $resVentasMesActual = $conn->query("
            SELECT COALESCE(SUM(total),0) AS total
            FROM ventas
            WHERE YEAR($colFecha) = YEAR(CURDATE())
              AND MONTH($colFecha) = MONTH(CURDATE())
        ");
        if ($resVentasMesActual) {
            $ventasMesActual = (float)($resVentasMesActual->fetch_assoc()['total'] ?? 0);
        }
    }

    if (tieneColumna($colsVentas, 'metodo_pago') && tieneColumna($colsVentas, 'total')) {
        $resMetodo = $conn->query("
            SELECT metodo_pago, COALESCE(SUM(total),0) AS total
            FROM ventas
            GROUP BY metodo_pago
            ORDER BY total DESC
        ");
        if ($resMetodo) {
            while ($row = $resMetodo->fetch_assoc()) {
                $metodo = trim((string)($row['metodo_pago'] ?? 'Sin definir'));
                if ($metodo === '') $metodo = 'Sin definir';
                $ventasPorMetodo[$metodo] = (float)$row['total'];
            }
        }
    }

    if (tieneColumna($colsVentas, 'tipo_cliente') && tieneColumna($colsVentas, 'total')) {
        $resTipo = $conn->query("
            SELECT tipo_cliente, COALESCE(SUM(total),0) AS total
            FROM ventas
            GROUP BY tipo_cliente
            ORDER BY total DESC
        ");
        if ($resTipo) {
            while ($row = $resTipo->fetch_assoc()) {
                $tipo = trim((string)($row['tipo_cliente'] ?? 'Sin definir'));
                if ($tipo === '') $tipo = 'Sin definir';
                $ventasPorTipoCliente[$tipo] = (float)$row['total'];
            }
        }
    }
}

/*
|--------------------------------------------------------------------------
| GASTOS
|--------------------------------------------------------------------------
*/
if (existeTabla($conn, 'facturas_proveedor')) {
    $resGastosMes = $conn->query("
        SELECT MONTH(fecha_factura) AS mes, COALESCE(SUM(monto),0) AS total
        FROM facturas_proveedor
        WHERE YEAR(fecha_factura) = YEAR(CURDATE())
        GROUP BY MONTH(fecha_factura)
        ORDER BY MONTH(fecha_factura)
    ");
    if ($resGastosMes) {
        while ($row = $resGastosMes->fetch_assoc()) {
            $mes = (int)$row['mes'];
            $gastosPorMes[$mes] = (float)$row['total'];
        }
    }

    $resTotalGastosAnual = $conn->query("
        SELECT COALESCE(SUM(monto),0) AS total
        FROM facturas_proveedor
        WHERE YEAR(fecha_factura) = YEAR(CURDATE())
    ");
    if ($resTotalGastosAnual) {
        $totalGastosAnual = (float)($resTotalGastosAnual->fetch_assoc()['total'] ?? 0);
    }

    $resGastosMesActual = $conn->query("
        SELECT COALESCE(SUM(monto),0) AS total
        FROM facturas_proveedor
        WHERE YEAR(fecha_factura) = YEAR(CURDATE())
          AND MONTH(fecha_factura) = MONTH(CURDATE())
    ");
    if ($resGastosMesActual) {
        $gastosMesActual = (float)($resGastosMesActual->fetch_assoc()['total'] ?? 0);
    }
}

/*
|--------------------------------------------------------------------------
| PRODUCTOS MÁS VENDIDOS
|--------------------------------------------------------------------------
*/
if (existeTabla($conn, 'ventas_detalle')) {
    $colsDetalle = obtenerColumnasTabla($conn, 'ventas_detalle');
    if (tieneColumna($colsDetalle, 'nombre_producto')) {
        $resProductos = $conn->query("
            SELECT nombre_producto, COUNT(*) AS veces, COALESCE(SUM(precio),0) AS total
            FROM ventas_detalle
            GROUP BY nombre_producto
            ORDER BY veces DESC, total DESC
            LIMIT 8
        ");
        if ($resProductos) {
            while ($row = $resProductos->fetch_assoc()) {
                $nombre = trim((string)($row['nombre_producto'] ?? 'Producto'));
                if ($nombre === '' || strtoupper($nombre) === 'PRODUCTO') {
                    $nombre = 'Producto';
                }
                $productosMasVendidos[] = [
                    'nombre' => $nombre,
                    'veces' => (int)($row['veces'] ?? 0),
                    'total' => (float)($row['total'] ?? 0)
                ];
            }
        }
    }
}

for ($i = 1; $i <= 12; $i++) {
    $utilidadPorMes[$i] = $ventasPorMes[$i] - $gastosPorMes[$i];
}

$utilidadAnual = $totalVentasAnual - $totalGastosAnual;
$utilidadMesActual = $ventasMesActual - $gastosMesActual;

$labelsMeses = ['Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic'];

$ventasPorMesJson = json_encode(array_values($ventasPorMes), JSON_UNESCAPED_UNICODE);
$gastosPorMesJson = json_encode(array_values($gastosPorMes), JSON_UNESCAPED_UNICODE);
$utilidadPorMesJson = json_encode(array_values($utilidadPorMes), JSON_UNESCAPED_UNICODE);
$labelsMesesJson = json_encode($labelsMeses, JSON_UNESCAPED_UNICODE);

$metodoLabelsJson = json_encode(array_keys($ventasPorMetodo), JSON_UNESCAPED_UNICODE);
$metodoDataJson = json_encode(array_values($ventasPorMetodo), JSON_UNESCAPED_UNICODE);

$tipoLabelsJson = json_encode(array_keys($ventasPorTipoCliente), JSON_UNESCAPED_UNICODE);
$tipoDataJson = json_encode(array_values($ventasPorTipoCliente), JSON_UNESCAPED_UNICODE);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Reportes - <?php echo e($nombreNegocio); ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<style>
:root{--gold:#c89b3c;--border:rgba(200,155,60,.20);--shadow:0 16px 34px rgba(0,0,0,.28)}
*{box-sizing:border-box}
body{
    margin:0;min-height:100vh;font-family:'Segoe UI',sans-serif;color:#fff;display:flex;
    background:<?php echo !empty($fondoContenido) ? "linear-gradient(rgba(0,0,0,.46), rgba(0,0,0,.62)), url('" . htmlspecialchars($fondoContenido, ENT_QUOTES, 'UTF-8') . "') center/cover fixed no-repeat" : "linear-gradient(135deg,#070709,#131318)"; ?>;
}
.sidebar{
    width:85px;position:fixed;top:0;left:0;bottom:0;display:flex;flex-direction:column;align-items:center;padding:15px 0;
    background:<?php echo !empty($fondoSidebar) ? "linear-gradient(rgba(0,0,0," . $alphaSidebar . "), rgba(0,0,0," . $alphaSidebar . ")), url('" . htmlspecialchars($fondoSidebar, ENT_QUOTES, 'UTF-8') . "') center/cover no-repeat" : "rgba(0,0,0," . $alphaSidebar . ")"; ?>;
    border-right:1px solid var(--border);backdrop-filter:blur(10px);-webkit-backdrop-filter:blur(10px);z-index:1000;
}
.logo-pos{width:55px;margin-bottom:16px;filter:drop-shadow(0 0 10px rgba(200,155,60,.6));animation:logoPulse 4s ease-in-out infinite, glow 3s infinite alternate}
.nav-controls{display:flex;flex-direction:column;gap:18px;width:100%;align-items:center;padding-bottom:20px;border-bottom:1px solid var(--border);margin-bottom:26px}
.sidebar a{color:#5b5b5b;font-size:20px;text-decoration:none;transition:.25s ease}
.sidebar a:hover,.sidebar a.active{color:var(--gold);filter:drop-shadow(0 0 8px var(--gold))}
.exit-btn:hover{color:#ff4d4d!important;filter:drop-shadow(0 0 8px #ff4d4d)!important}
.content{flex:1;margin-left:85px;padding:24px}
.hero,.stat,.chart-card,.table-wrap{
    background:rgba(255,255,255,<?php echo max(0.03, min(0.20, $alphaPanel * 0.35)); ?>);
    border:1px solid var(--border);box-shadow:var(--shadow);backdrop-filter:blur(10px);-webkit-backdrop-filter:blur(10px)
}
.hero{border-radius:22px;padding:20px;margin-bottom:16px}
.hero h1{margin:0 0 6px;font-size:32px}
.hero p{margin:0;color:#ddd}
.stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin-bottom:18px}
.stat{border-radius:18px;padding:18px}
.stat h3{margin:0;color:var(--gold);font-size:14px;text-transform:uppercase}
.stat .num{font-size:30px;font-weight:800;margin-top:10px}
.grid-2{display:grid;grid-template-columns:1.4fr 1fr;gap:16px;margin-bottom:16px}
.chart-card{border-radius:20px;padding:18px;min-height:360px}
.chart-card h2{margin:0 0 12px;color:var(--gold);font-size:20px}
.chart-holder{position:relative;height:300px}
.table-wrap{border-radius:20px;padding:18px;overflow:auto}
.table-wrap h2{margin:0 0 12px;color:var(--gold);font-size:20px}
table{width:100%;border-collapse:collapse;min-width:680px}
th{background:#000;color:#fff;padding:12px;text-align:left;font-size:13px}
td{padding:12px;border-bottom:1px solid rgba(255,255,255,.08);font-size:13px;vertical-align:top}
@keyframes glow{from{filter:drop-shadow(0 0 5px rgba(200,155,60,.4))}to{filter:drop-shadow(0 0 15px rgba(200,155,60,.7))}}
@keyframes logoPulse{0%,100%{transform:scale(1)}50%{transform:scale(1.08)}}
@media (max-width:1200px){.stats{grid-template-columns:repeat(2,minmax(0,1fr))}.grid-2{grid-template-columns:1fr}}
@media (max-width:768px){.content{padding:16px 12px}.hero h1{font-size:26px}.stats{grid-template-columns:1fr}}
</style>
</head>
<body>
<aside class="sidebar">
    <img src="<?php echo e($logoActual); ?>" alt="Logo" class="logo-pos">
    <div class="nav-controls">
        <a href="dashboard.php" title="Dashboard"><i class="fas fa-home"></i></a>
        <a href="ventas.php" title="Ventas"><i class="fas fa-cash-register"></i></a>
        <a href="pedidos.php" title="Pedidos"><i class="fas fa-clipboard-list"></i></a>
        <a href="proveedores.php" title="Proveedores"><i class="fas fa-truck-loading"></i></a>
        <a href="facturas_proveedor.php" title="Facturas"><i class="fas fa-file-invoice-dollar"></i></a>
        <a href="reportes.php" title="Reportes" class="active"><i class="fas fa-chart-line"></i></a>
        <a href="configuracion.php" title="Configuración"><i class="fas fa-cog"></i></a>
    </div>
    <a href="logout.php" class="exit-btn" title="Cerrar sesión"><i class="fas fa-sign-out-alt"></i></a>
</aside>

<main class="content">
    <section class="hero">
        <h1>Reportes y gráficas</h1>
        <p>Aquí puedes ver ventas, gastos y utilidad del sistema en el año <?php echo $anioActual; ?>.</p>
    </section>

    <section class="stats">
        <div class="stat">
            <h3>Ventas del mes</h3>
            <div class="num">$<?php echo number_format($ventasMesActual, 2); ?></div>
        </div>
        <div class="stat">
            <h3>Gastos del mes</h3>
            <div class="num">$<?php echo number_format($gastosMesActual, 2); ?></div>
        </div>
        <div class="stat">
            <h3>Utilidad del mes</h3>
            <div class="num">$<?php echo number_format($utilidadMesActual, 2); ?></div>
        </div>
        <div class="stat">
            <h3>Utilidad anual</h3>
            <div class="num">$<?php echo number_format($utilidadAnual, 2); ?></div>
        </div>
    </section>

    <section class="grid-2">
        <div class="chart-card">
            <h2>Ventas vs gastos por mes</h2>
            <div class="chart-holder"><canvas id="chartMeses"></canvas></div>
        </div>
        <div class="chart-card">
            <h2>Utilidad por mes</h2>
            <div class="chart-holder"><canvas id="chartUtilidad"></canvas></div>
        </div>
    </section>

    <section class="grid-2">
        <div class="chart-card">
            <h2>Ventas por método de pago</h2>
            <div class="chart-holder"><canvas id="chartMetodo"></canvas></div>
        </div>
        <div class="chart-card">
            <h2>Ventas por tipo de cliente</h2>
            <div class="chart-holder"><canvas id="chartTipo"></canvas></div>
        </div>
    </section>

    <section class="table-wrap">
        <h2>Productos más vendidos</h2>
        <table>
            <thead>
                <tr>
                    <th>Producto</th>
                    <th>Veces vendido</th>
                    <th>Total generado</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($productosMasVendidos)): ?>
                    <?php foreach ($productosMasVendidos as $producto): ?>
                        <tr>
                            <td><strong><?php echo e($producto['nombre']); ?></strong></td>
                            <td><?php echo (int)$producto['veces']; ?></td>
                            <td>$<?php echo number_format((float)$producto['total'], 2); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="3">No hay productos vendidos todavía.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </section>
</main>

<script>
const labelsMeses = <?php echo $labelsMesesJson; ?>;
const ventasPorMes = <?php echo $ventasPorMesJson; ?>;
const gastosPorMes = <?php echo $gastosPorMesJson; ?>;
const utilidadPorMes = <?php echo $utilidadPorMesJson; ?>;

const metodoLabels = <?php echo $metodoLabelsJson; ?>;
const metodoData = <?php echo $metodoDataJson; ?>;

const tipoLabels = <?php echo $tipoLabelsJson; ?>;
const tipoData = <?php echo $tipoDataJson; ?>;

new Chart(document.getElementById('chartMeses'), {
    type: 'bar',
    data: {
        labels: labelsMeses,
        datasets: [
            {
                label: 'Ventas',
                data: ventasPorMes
            },
            {
                label: 'Gastos',
                data: gastosPorMes
            }
        ]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { labels: { color: '#fff' } }
        },
        scales: {
            x: { ticks: { color: '#fff' }, grid: { color: 'rgba(255,255,255,0.08)' } },
            y: { ticks: { color: '#fff' }, grid: { color: 'rgba(255,255,255,0.08)' } }
        }
    }
});

new Chart(document.getElementById('chartUtilidad'), {
    type: 'line',
    data: {
        labels: labelsMeses,
        datasets: [
            {
                label: 'Utilidad',
                data: utilidadPorMes,
                tension: 0.35,
                fill: false
            }
        ]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { labels: { color: '#fff' } }
        },
        scales: {
            x: { ticks: { color: '#fff' }, grid: { color: 'rgba(255,255,255,0.08)' } },
            y: { ticks: { color: '#fff' }, grid: { color: 'rgba(255,255,255,0.08)' } }
        }
    }
});

new Chart(document.getElementById('chartMetodo'), {
    type: 'doughnut',
    data: {
        labels: metodoLabels,
        datasets: [{ data: metodoData }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { labels: { color: '#fff' } }
        }
    }
});

new Chart(document.getElementById('chartTipo'), {
    type: 'pie',
    data: {
        labels: tipoLabels,
        datasets: [{ data: tipoData }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { labels: { color: '#fff' } }
        }
    }
});
</script>
</body>
</html>

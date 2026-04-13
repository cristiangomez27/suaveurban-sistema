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

if (!existeTabla($conn, 'proveedores')) {
    $conn->query("
        CREATE TABLE proveedores (
            id INT AUTO_INCREMENT PRIMARY KEY,
            nombre VARCHAR(150) NOT NULL,
            telefono VARCHAR(30) DEFAULT NULL,
            correo VARCHAR(150) DEFAULT NULL,
            direccion TEXT DEFAULT NULL,
            observaciones TEXT DEFAULT NULL,
            activo TINYINT(1) NOT NULL DEFAULT 1,
            fecha_registro DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

if (!existeTabla($conn, 'facturas_proveedor')) {
    $conn->query("
        CREATE TABLE facturas_proveedor (
            id INT AUTO_INCREMENT PRIMARY KEY,
            proveedor_id INT NOT NULL,
            numero_factura VARCHAR(100) DEFAULT NULL,
            fecha_factura DATE NOT NULL,
            concepto VARCHAR(255) NOT NULL,
            monto DECIMAL(12,2) NOT NULL DEFAULT 0,
            observaciones TEXT DEFAULT NULL,
            fecha_registro DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_proveedor_id (proveedor_id),
            INDEX idx_fecha_factura (fecha_factura)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'guardar_factura') {
    try {
        $proveedorId = (int)($_POST['proveedor_id'] ?? 0);
        $numeroFactura = trim((string)($_POST['numero_factura'] ?? ''));
        $fechaFactura = trim((string)($_POST['fecha_factura'] ?? ''));
        $concepto = trim((string)($_POST['concepto'] ?? ''));
        $monto = (float)($_POST['monto'] ?? 0);
        $observaciones = trim((string)($_POST['observaciones'] ?? ''));

        if ($proveedorId <= 0) {
            throw new Exception('Selecciona un proveedor.');
        }
        if ($fechaFactura === '') {
            throw new Exception('La fecha de factura es obligatoria.');
        }
        if ($concepto === '') {
            throw new Exception('El concepto es obligatorio.');
        }
        if ($monto <= 0) {
            throw new Exception('El monto debe ser mayor a cero.');
        }

        $stmt = $conn->prepare("
            INSERT INTO facturas_proveedor (proveedor_id, numero_factura, fecha_factura, concepto, monto, observaciones, fecha_registro)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->bind_param("isssds", $proveedorId, $numeroFactura, $fechaFactura, $concepto, $monto, $observaciones);
        $stmt->execute();
        $stmt->close();

        $mensaje = 'Factura registrada correctamente.';
    } catch (Throwable $e) {
        $error = 'Error: ' . $e->getMessage();
    }
}

$proveedores = [];
$resProv = $conn->query("SELECT id, nombre FROM proveedores WHERE activo = 1 ORDER BY nombre ASC");
if ($resProv) {
    while ($row = $resProv->fetch_assoc()) {
        $proveedores[] = $row;
    }
}

$buscar = trim((string)($_GET['buscar'] ?? ''));
$facturas = [];
$totalMes = 0.0;
$totalAnio = 0.0;

$sqlFact = "
    SELECT f.*, p.nombre AS proveedor_nombre
    FROM facturas_proveedor f
    INNER JOIN proveedores p ON p.id = f.proveedor_id
";
$params = [];
$types = '';

if ($buscar !== '') {
    $sqlFact .= " WHERE p.nombre LIKE ? OR f.numero_factura LIKE ? OR f.concepto LIKE ?";
    $like = '%' . $buscar . '%';
    $params = [$like, $like, $like];
    $types = 'sss';
}

$sqlFact .= " ORDER BY f.fecha_factura DESC, f.id DESC";

$stmtFact = $conn->prepare($sqlFact);
if (!empty($params)) {
    $stmtFact->bind_param($types, ...$params);
}
$stmtFact->execute();
$resFact = $stmtFact->get_result();
while ($row = $resFact->fetch_assoc()) {
    $facturas[] = $row;
}
$stmtFact->close();

$resMes = $conn->query("
    SELECT COALESCE(SUM(monto), 0) AS total
    FROM facturas_proveedor
    WHERE MONTH(fecha_factura) = MONTH(CURDATE())
      AND YEAR(fecha_factura) = YEAR(CURDATE())
");
if ($resMes) {
    $totalMes = (float)($resMes->fetch_assoc()['total'] ?? 0);
}

$resAnio = $conn->query("
    SELECT COALESCE(SUM(monto), 0) AS total
    FROM facturas_proveedor
    WHERE YEAR(fecha_factura) = YEAR(CURDATE())
");
if ($resAnio) {
    $totalAnio = (float)($resAnio->fetch_assoc()['total'] ?? 0);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Facturas de proveedor - <?php echo e($nombreNegocio); ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
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
.hero,.panel,.table-wrap,.notice-success,.notice-error,.stat{
    background:rgba(255,255,255,<?php echo max(0.03, min(0.20, $alphaPanel * 0.35)); ?>);
    border:1px solid var(--border);box-shadow:var(--shadow);backdrop-filter:blur(10px);-webkit-backdrop-filter:blur(10px)
}
.hero{border-radius:22px;padding:20px;margin-bottom:16px}
.hero h1{margin:0 0 6px;font-size:32px}
.hero p{margin:0;color:#ddd}
.notice-success,.notice-error{padding:14px 16px;border-radius:16px;margin-bottom:14px;font-weight:700}
.notice-success{background:rgba(34,197,94,.14);border-color:rgba(34,197,94,.35);color:#d2f9de}
.notice-error{background:rgba(239,68,68,.14);border-color:rgba(239,68,68,.35);color:#ffd2d2}
.stats{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px;margin-bottom:16px}
.stat{border-radius:18px;padding:18px}
.stat h3{margin:0;color:var(--gold);font-size:14px;text-transform:uppercase}
.stat .num{font-size:32px;font-weight:800;margin-top:10px}
.grid{display:grid;grid-template-columns:380px 1fr;gap:16px}
.panel{border-radius:20px;padding:18px}
.panel h2{margin:0 0 14px;color:var(--gold);font-size:20px}
.form-grid{display:grid;gap:12px}
input,textarea,select{
    width:100%;border-radius:14px;border:1px solid rgba(255,255,255,.10);background:rgba(0,0,0,.28);color:#fff;padding:12px 14px;outline:none;font-family:inherit
}
textarea{min-height:110px;resize:vertical}
.btn{border:none;border-radius:14px;padding:12px 16px;font-weight:800;cursor:pointer}
.btn-save{background:var(--gold);color:#111}
.btn-search{background:#dbeafe;color:#1d4ed8}
.toolbar{display:grid;grid-template-columns:1fr auto auto;gap:10px;margin-bottom:14px}
.table-wrap{border-radius:20px;padding:18px;overflow:auto}
table{width:100%;border-collapse:collapse;min-width:860px}
th{background:#000;color:#fff;padding:12px;text-align:left;font-size:13px}
td{padding:12px;border-bottom:1px solid rgba(255,255,255,.08);font-size:13px;vertical-align:top}
@keyframes glow{from{filter:drop-shadow(0 0 5px rgba(200,155,60,.4))}to{filter:drop-shadow(0 0 15px rgba(200,155,60,.7))}}
@keyframes logoPulse{0%,100%{transform:scale(1)}50%{transform:scale(1.08)}}
@media (max-width:1100px){.grid{grid-template-columns:1fr}.toolbar{grid-template-columns:1fr}}
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
        <a href="facturas_proveedor.php" title="Facturas" class="active"><i class="fas fa-file-invoice-dollar"></i></a>
        <a href="configuracion.php" title="Configuración"><i class="fas fa-cog"></i></a>
    </div>
    <a href="logout.php" class="exit-btn" title="Cerrar sesión"><i class="fas fa-sign-out-alt"></i></a>
</aside>

<main class="content">
    <section class="hero">
        <h1>Facturas de proveedor</h1>
        <p>Registra facturas y lleva el control de gastos mensuales y anuales.</p>
    </section>

    <?php if ($mensaje !== ''): ?><div class="notice-success"><?php echo e($mensaje); ?></div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="notice-error"><?php echo e($error); ?></div><?php endif; ?>

    <section class="stats">
        <div class="stat">
            <h3>Gasto mensual</h3>
            <div class="num">$<?php echo number_format($totalMes, 2); ?></div>
        </div>
        <div class="stat">
            <h3>Gasto anual</h3>
            <div class="num">$<?php echo number_format($totalAnio, 2); ?></div>
        </div>
    </section>

    <section class="grid">
        <div class="panel">
            <h2>Nueva factura</h2>
            <form method="POST" class="form-grid">
                <input type="hidden" name="accion" value="guardar_factura">
                <select name="proveedor_id" required>
                    <option value="">Selecciona proveedor</option>
                    <?php foreach ($proveedores as $prov): ?>
                        <option value="<?php echo (int)$prov['id']; ?>"><?php echo e($prov['nombre']); ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="text" name="numero_factura" placeholder="Número de factura">
                <input type="date" name="fecha_factura" required value="<?php echo date('Y-m-d'); ?>">
                <input type="text" name="concepto" placeholder="Concepto" required>
                <input type="number" name="monto" placeholder="Monto" min="0.01" step="0.01" required>
                <textarea name="observaciones" placeholder="Observaciones"></textarea>
                <button type="submit" class="btn btn-save">Guardar factura</button>
            </form>
        </div>

        <div class="table-wrap">
            <div class="toolbar">
                <form method="GET" style="display:contents;">
                    <input type="text" name="buscar" value="<?php echo e($buscar); ?>" placeholder="Buscar por proveedor, factura o concepto">
                    <button type="submit" class="btn btn-search">Buscar</button>
                    <a href="facturas_proveedor.php" class="btn btn-search" style="text-decoration:none;display:inline-flex;align-items:center;justify-content:center;">Limpiar</a>
                </form>
            </div>

            <table>
                <thead>
                    <tr>
                        <th>Proveedor</th>
                        <th>Factura</th>
                        <th>Fecha</th>
                        <th>Concepto</th>
                        <th>Monto</th>
                        <th>Observaciones</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!empty($facturas)): ?>
                    <?php foreach ($facturas as $fac): ?>
                        <tr>
                            <td><strong><?php echo e($fac['proveedor_nombre'] ?? ''); ?></strong></td>
                            <td><?php echo e($fac['numero_factura'] ?? '-'); ?></td>
                            <td><?php echo e($fac['fecha_factura'] ?? '-'); ?></td>
                            <td><?php echo e($fac['concepto'] ?? '-'); ?></td>
                            <td>$<?php echo number_format((float)($fac['monto'] ?? 0), 2); ?></td>
                            <td><?php echo nl2br(e($fac['observaciones'] ?? '-')); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="6">No hay facturas registradas.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</main>
</body>
</html>

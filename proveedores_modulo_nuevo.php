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

$colsProveedores = obtenerColumnasTabla($conn, 'proveedores');
if (!tieneColumna($colsProveedores, 'activo')) {
    $conn->query("ALTER TABLE proveedores ADD COLUMN activo TINYINT(1) NOT NULL DEFAULT 1");
}
if (!tieneColumna($colsProveedores, 'fecha_registro')) {
    $conn->query("ALTER TABLE proveedores ADD COLUMN fecha_registro DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP");
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
        if ($_POST['accion'] === 'guardar_proveedor') {
            $nombre = trim((string)($_POST['nombre'] ?? ''));
            $telefono = trim((string)($_POST['telefono'] ?? ''));
            $correo = trim((string)($_POST['correo'] ?? ''));
            $direccion = trim((string)($_POST['direccion'] ?? ''));
            $observaciones = trim((string)($_POST['observaciones'] ?? ''));

            if ($nombre === '') {
                throw new Exception('El nombre del proveedor es obligatorio.');
            }

            $stmt = $conn->prepare("
                INSERT INTO proveedores (nombre, telefono, correo, direccion, observaciones, activo, fecha_registro)
                VALUES (?, ?, ?, ?, ?, 1, NOW())
            ");
            $stmt->bind_param("sssss", $nombre, $telefono, $correo, $direccion, $observaciones);
            $stmt->execute();
            $stmt->close();

            $mensaje = 'Proveedor guardado correctamente.';
        }

        if ($_POST['accion'] === 'desactivar_proveedor') {
            $proveedorId = (int)($_POST['proveedor_id'] ?? 0);
            if ($proveedorId <= 0) {
                throw new Exception('Proveedor inválido.');
            }

            $stmt = $conn->prepare("UPDATE proveedores SET activo = 0 WHERE id = ? LIMIT 1");
            $stmt->bind_param("i", $proveedorId);
            $stmt->execute();
            $stmt->close();

            $mensaje = 'Proveedor desactivado correctamente.';
        }

        if ($_POST['accion'] === 'activar_proveedor') {
            $proveedorId = (int)($_POST['proveedor_id'] ?? 0);
            if ($proveedorId <= 0) {
                throw new Exception('Proveedor inválido.');
            }

            $stmt = $conn->prepare("UPDATE proveedores SET activo = 1 WHERE id = ? LIMIT 1");
            $stmt->bind_param("i", $proveedorId);
            $stmt->execute();
            $stmt->close();

            $mensaje = 'Proveedor activado correctamente.';
        }
    } catch (Throwable $e) {
        $error = 'Error: ' . $e->getMessage();
    }
}

$buscar = trim((string)($_GET['buscar'] ?? ''));
$proveedores = [];

$sql = "SELECT * FROM proveedores";
$params = [];
$types = '';

if ($buscar !== '') {
    $sql .= " WHERE nombre LIKE ? OR telefono LIKE ? OR correo LIKE ?";
    $like = '%' . $buscar . '%';
    $params = [$like, $like, $like];
    $types = 'sss';
}

$sql .= " ORDER BY activo DESC, nombre ASC";

$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $proveedores[] = $row;
}
$stmt->close();
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Proveedores - <?php echo e($nombreNegocio); ?></title>
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
.hero,.panel,.table-wrap,.notice-success,.notice-error{
    background:rgba(255,255,255,<?php echo max(0.03, min(0.20, $alphaPanel * 0.35)); ?>);
    border:1px solid var(--border);box-shadow:var(--shadow);backdrop-filter:blur(10px);-webkit-backdrop-filter:blur(10px)
}
.hero{border-radius:22px;padding:20px;margin-bottom:16px}
.hero h1{margin:0 0 6px;font-size:32px}
.hero p{margin:0;color:#ddd}
.notice-success,.notice-error{padding:14px 16px;border-radius:16px;margin-bottom:14px;font-weight:700}
.notice-success{background:rgba(34,197,94,.14);border-color:rgba(34,197,94,.35);color:#d2f9de}
.notice-error{background:rgba(239,68,68,.14);border-color:rgba(239,68,68,.35);color:#ffd2d2}
.grid{display:grid;grid-template-columns:380px 1fr;gap:16px}
.panel{border-radius:20px;padding:18px}
.panel h2{margin:0 0 14px;color:var(--gold);font-size:20px}
.form-grid{display:grid;gap:12px}
input,textarea{
    width:100%;border-radius:14px;border:1px solid rgba(255,255,255,.10);background:rgba(0,0,0,.28);color:#fff;padding:12px 14px;outline:none;font-family:inherit
}
textarea{min-height:110px;resize:vertical}
.btn{border:none;border-radius:14px;padding:12px 16px;font-weight:800;cursor:pointer}
.btn-save{background:var(--gold);color:#111}
.btn-search{background:#dbeafe;color:#1d4ed8}
.btn-toggle-on{background:#dcfce7;color:#166534}
.btn-toggle-off{background:#fee2e2;color:#991b1b}
.toolbar{display:grid;grid-template-columns:1fr auto auto;gap:10px;margin-bottom:14px}
.table-wrap{border-radius:20px;padding:18px;overflow:auto}
table{width:100%;border-collapse:collapse;min-width:760px}
th{background:#000;color:#fff;padding:12px;text-align:left;font-size:13px}
td{padding:12px;border-bottom:1px solid rgba(255,255,255,.08);font-size:13px;vertical-align:top}
.badge{display:inline-flex;align-items:center;justify-content:center;padding:6px 10px;border-radius:999px;font-size:12px;font-weight:800}
.badge-on{background:rgba(34,197,94,.15);color:#d8ffe2;border:1px solid rgba(34,197,94,.30)}
.badge-off{background:rgba(239,68,68,.15);color:#ffd3d3;border:1px solid rgba(239,68,68,.30)}
.inline-form{display:inline}
@keyframes glow{from{filter:drop-shadow(0 0 5px rgba(200,155,60,.4))}to{filter:drop-shadow(0 0 15px rgba(200,155,60,.7))}}
@keyframes logoPulse{0%,100%{transform:scale(1)}50%{transform:scale(1.08)}}
@media (max-width:1100px){.grid{grid-template-columns:1fr}.toolbar{grid-template-columns:1fr}}
@media (max-width:768px){.content{padding:16px 12px}.hero h1{font-size:26px}}
</style>
</head>
<body>
<aside class="sidebar">
    <img src="<?php echo e($logoActual); ?>" alt="Logo" class="logo-pos">
    <div class="nav-controls">
        <a href="dashboard.php" title="Dashboard"><i class="fas fa-home"></i></a>
        <a href="ventas.php" title="Ventas"><i class="fas fa-cash-register"></i></a>
        <a href="pedidos.php" title="Pedidos"><i class="fas fa-clipboard-list"></i></a>
        <a href="proveedores.php" title="Proveedores" class="active"><i class="fas fa-truck-loading"></i></a>
        <a href="facturas_proveedor.php" title="Facturas"><i class="fas fa-file-invoice-dollar"></i></a>
        <a href="configuracion.php" title="Configuración"><i class="fas fa-cog"></i></a>
    </div>
    <a href="logout.php" class="exit-btn" title="Cerrar sesión"><i class="fas fa-sign-out-alt"></i></a>
</aside>

<main class="content">
    <section class="hero">
        <h1>Proveedores</h1>
        <p>Aquí puedes registrar y administrar tus proveedores del sistema.</p>
    </section>

    <?php if ($mensaje !== ''): ?><div class="notice-success"><?php echo e($mensaje); ?></div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="notice-error"><?php echo e($error); ?></div><?php endif; ?>

    <section class="grid">
        <div class="panel">
            <h2>Nuevo proveedor</h2>
            <form method="POST" class="form-grid">
                <input type="hidden" name="accion" value="guardar_proveedor">
                <input type="text" name="nombre" placeholder="Nombre del proveedor" required>
                <input type="text" name="telefono" placeholder="Teléfono">
                <input type="email" name="correo" placeholder="Correo">
                <textarea name="direccion" placeholder="Dirección"></textarea>
                <textarea name="observaciones" placeholder="Observaciones"></textarea>
                <button type="submit" class="btn btn-save">Guardar proveedor</button>
            </form>
        </div>

        <div class="table-wrap">
            <div class="toolbar">
                <form method="GET" style="display:contents;">
                    <input type="text" name="buscar" value="<?php echo e($buscar); ?>" placeholder="Buscar por nombre, teléfono o correo">
                    <button type="submit" class="btn btn-search">Buscar</button>
                    <a href="proveedores.php" class="btn btn-search" style="text-decoration:none;display:inline-flex;align-items:center;justify-content:center;">Limpiar</a>
                </form>
            </div>

            <table>
                <thead>
                    <tr>
                        <th>Proveedor</th>
                        <th>Contacto</th>
                        <th>Dirección</th>
                        <th>Observaciones</th>
                        <th>Estatus</th>
                        <th>Acción</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!empty($proveedores)): ?>
                    <?php foreach ($proveedores as $prov): ?>
                        <tr>
                            <td><strong><?php echo e($prov['nombre'] ?? ''); ?></strong></td>
                            <td>
                                <div><strong>Tel:</strong> <?php echo e($prov['telefono'] ?? '-'); ?></div>
                                <div><strong>Correo:</strong> <?php echo e($prov['correo'] ?? '-'); ?></div>
                            </td>
                            <td><?php echo nl2br(e($prov['direccion'] ?? '-')); ?></td>
                            <td><?php echo nl2br(e($prov['observaciones'] ?? '-')); ?></td>
                            <td>
                                <?php if ((int)($prov['activo'] ?? 1) === 1): ?>
                                    <span class="badge badge-on">Activo</span>
                                <?php else: ?>
                                    <span class="badge badge-off">Inactivo</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ((int)($prov['activo'] ?? 1) === 1): ?>
                                    <form method="POST" class="inline-form" onsubmit="return confirm('¿Desactivar proveedor?');">
                                        <input type="hidden" name="accion" value="desactivar_proveedor">
                                        <input type="hidden" name="proveedor_id" value="<?php echo (int)$prov['id']; ?>">
                                        <button type="submit" class="btn btn-toggle-off">Desactivar</button>
                                    </form>
                                <?php else: ?>
                                    <form method="POST" class="inline-form">
                                        <input type="hidden" name="accion" value="activar_proveedor">
                                        <input type="hidden" name="proveedor_id" value="<?php echo (int)$prov['id']; ?>">
                                        <button type="submit" class="btn btn-toggle-on">Activar</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="6">No hay proveedores registrados.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</main>
</body>
</html>

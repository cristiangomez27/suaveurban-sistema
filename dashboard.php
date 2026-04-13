<?php
session_start();

if (!isset($_SESSION['usuario_id'])) {
    header("Location: index.php");
    exit;
}

require_once 'config/database.php';

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

function limpiar($valor): string
{
    return htmlspecialchars((string)$valor, ENT_QUOTES, 'UTF-8');
}

function normalizarRol(string $rol): string
{
    $rol = strtolower(trim($rol));
    $rol = str_replace(['_', '-'], ' ', $rol);

    return match ($rol) {
        'admin', 'administrador', 'administrator', 'root', 'superadmin', 'administrador general', 'administrador genera' => 'admin',
        'mostrador', 'caja', 'cajero', 'ventas', 'vendedor' => 'mostrador',
        'produccion', 'producción', 'taller' => 'produccion',
        default => $rol !== '' ? $rol : 'mostrador',
    };
}

function obtenerEstadoVenta(array $venta): string
{
    $estado = trim((string)($venta['estado'] ?? ''));
    $estatus = trim((string)($venta['estatus'] ?? ''));

    $base = $estado !== '' ? $estado : $estatus;
    $base = mb_strtolower($base, 'UTF-8');

    return match ($base) {
        'pedido', 'nuevo', 'pendiente', '' => 'Pedido',
        'pedido recibido', 'recibido' => 'Pedido recibido',
        'en proceso', 'proceso' => 'En proceso',
        'listo', 'listo para entrega' => 'Listo',
        'entregado' => 'Entregado',
        default => ucwords($base),
    };
}

function obtenerClienteNombreVenta(mysqli $conn, array $venta): string
{
    if (!empty($venta['cliente_nombre'])) {
        return trim((string)$venta['cliente_nombre']);
    }

    $clienteId = (int)($venta['cliente_id'] ?? 0);
    if ($clienteId > 0 && existeTabla($conn, 'clientes')) {
        $columnasClientes = obtenerColumnasTabla($conn, 'clientes');
        if (tieneColumna($columnasClientes, 'nombre')) {
            $stmt = $conn->prepare("SELECT nombre FROM clientes WHERE id = ? LIMIT 1");
            $stmt->bind_param("i", $clienteId);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($res && $res->num_rows > 0) {
                $row = $res->fetch_assoc();
                $stmt->close();
                return trim((string)($row['nombre'] ?? ''));
            }
            $stmt->close();
        }
    }

    return 'Público en general';
}

function obtenerTelefonoClienteVenta(mysqli $conn, array $venta): string
{
    if (!empty($venta['cliente_telefono'])) {
        return trim((string)$venta['cliente_telefono']);
    }

    $clienteId = (int)($venta['cliente_id'] ?? 0);
    if ($clienteId > 0 && existeTabla($conn, 'clientes')) {
        $columnasClientes = obtenerColumnasTabla($conn, 'clientes');
        if (tieneColumna($columnasClientes, 'telefono')) {
            $stmt = $conn->prepare("SELECT telefono FROM clientes WHERE id = ? LIMIT 1");
            $stmt->bind_param("i", $clienteId);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($res && $res->num_rows > 0) {
                $row = $res->fetch_assoc();
                $stmt->close();
                return trim((string)($row['telefono'] ?? ''));
            }
            $stmt->close();
        }
    }

    return '';
}

$fondoSidebar = '';
$fondoContenido = '';
$logoActual = 'logo.png';
$transparenciaPanel = 0.38;
$transparenciaSidebar = 0.88;
$sonidoMensajes = '';

$configCols = obtenerColumnasTabla($conn, 'configuracion');
if (!empty($configCols)) {
    $selectConfig = [];

    foreach (['logo', 'fondo_sidebar', 'fondo_contenido', 'transparencia_panel', 'transparencia_sidebar', 'sonido_mensajes'] as $col) {
        if (tieneColumna($configCols, $col)) {
            $selectConfig[] = $col;
        }
    }

    if (!empty($selectConfig)) {
        $sqlConfig = "SELECT " . implode(', ', $selectConfig) . " FROM configuracion WHERE id = 1 LIMIT 1";
        $resConfig = $conn->query($sqlConfig);

        if ($resConfig && $resConfig->num_rows > 0) {
            $config = $resConfig->fetch_assoc();

            if (!empty($config['logo'])) $logoActual = $config['logo'];
            if (!empty($config['fondo_sidebar'])) $fondoSidebar = $config['fondo_sidebar'];
            if (!empty($config['fondo_contenido'])) $fondoContenido = $config['fondo_contenido'];
            if (isset($config['transparencia_panel'])) $transparenciaPanel = (float)$config['transparencia_panel'];
            if (isset($config['transparencia_sidebar'])) $transparenciaSidebar = (float)$config['transparencia_sidebar'];
            if (!empty($config['sonido_mensajes'])) $sonidoMensajes = $config['sonido_mensajes'];
        }
    }
}

$nombreUsuario = $_SESSION['nombre'] ?? 'Usuario';
$alphaPanel = max(0.10, min(0.95, $transparenciaPanel));
$alphaSidebar = max(0.10, min(0.98, $transparenciaSidebar));

$rolUsuario = normalizarRol((string)($_SESSION['rol'] ?? ''));

if ($rolUsuario === '' || $rolUsuario === 'mostrador') {
    $usuarioSesionId = (int)($_SESSION['usuario_id'] ?? 0);
    if ($usuarioSesionId > 0 && existeTabla($conn, 'usuarios')) {
        $columnasUsuarios = obtenerColumnasTabla($conn, 'usuarios');
        if (tieneColumna($columnasUsuarios, 'rol')) {
            $stmtRol = $conn->prepare("SELECT rol FROM usuarios WHERE id = ? LIMIT 1");
            $stmtRol->bind_param("i", $usuarioSesionId);
            $stmtRol->execute();
            $resRol = $stmtRol->get_result();
            if ($resRol && $resRol->num_rows > 0) {
                $rowRol = $resRol->fetch_assoc();
                if (!empty($rowRol['rol'])) {
                    $rolUsuario = normalizarRol((string)$rowRol['rol']);
                    $_SESSION['rol'] = $rowRol['rol'];
                }
            }
            $stmtRol->close();
        }
    }
}

$esAdmin = $rolUsuario === 'admin';
$esMostrador = $rolUsuario === 'mostrador';
$esProduccion = $rolUsuario === 'produccion';

$usuarioSesionId = (int)($_SESSION['usuario_id'] ?? 0);
if ($usuarioSesionId > 0 && existeTabla($conn, 'usuarios')) {
    $conn->query("UPDATE usuarios SET last_seen = NOW() WHERE id = {$usuarioSesionId} LIMIT 1");
}

$mensaje = '';
$error = '';

$columnasVentas = obtenerColumnasTabla($conn, 'ventas');

if (!empty($columnasVentas)) {
    if (!tieneColumna($columnasVentas, 'estado')) {
        $conn->query("ALTER TABLE ventas ADD COLUMN estado VARCHAR(50) NOT NULL DEFAULT 'Pedido'");
        $columnasVentas = obtenerColumnasTabla($conn, 'ventas');
    }

    if (tieneColumna($columnasVentas, 'estado')) {
        $conn->query("UPDATE ventas SET estado = 'Pedido' WHERE estado IS NULL OR estado = ''");
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';
    $ventaId = isset($_POST['venta_id']) ? (int)$_POST['venta_id'] : 0;

    if ($accion === 'entregar' && $ventaId > 0 && existeTabla($conn, 'ventas')) {
        if (!$esAdmin && !$esMostrador) {
            $error = 'No tienes permisos para marcar pedidos como entregados.';
        } else {
            $stmt = $conn->prepare("SELECT * FROM ventas WHERE id = ? LIMIT 1");
            $stmt->bind_param("i", $ventaId);
            $stmt->execute();
            $resVenta = $stmt->get_result();
            $ventaActual = ($resVenta && $resVenta->num_rows > 0) ? $resVenta->fetch_assoc() : null;
            $stmt->close();

            if ($ventaActual) {
                $estadoActual = obtenerEstadoVenta($ventaActual);

                if ($estadoActual !== 'Listo') {
                    $error = 'Solo se puede entregar un pedido que esté en estado Listo.';
                } else {
                    $updates = [];
                    $params = [];
                    $types = '';

                    if (tieneColumna($columnasVentas, 'estado')) {
                        $updates[] = "estado = ?";
                        $params[] = 'Entregado';
                        $types .= 's';
                    }

                    if (tieneColumna($columnasVentas, 'estatus')) {
                        $updates[] = "estatus = ?";
                        $params[] = 'Entregado';
                        $types .= 's';
                    }

                    if (!empty($updates)) {
                        $types .= 'i';
                        $params[] = $ventaId;

                        $sqlUpdate = "UPDATE ventas SET " . implode(', ', $updates) . " WHERE id = ? LIMIT 1";
                        $stmtUpdate = $conn->prepare($sqlUpdate);

                        $bindParams = [];
                        $bindParams[] = &$types;
                        foreach ($params as $k => $v) {
                            $bindParams[] = &$params[$k];
                        }

                        call_user_func_array([$stmtUpdate, 'bind_param'], $bindParams);

                        if ($stmtUpdate->execute()) {
                            $mensaje = 'Pedido entregado correctamente.';
                        } else {
                            $error = 'No se pudo marcar como entregado.';
                        }
                        $stmtUpdate->close();
                    } else {
                        $error = 'No se encontraron columnas de estado para actualizar.';
                    }
                }
            } else {
                $error = 'Pedido no encontrado.';
            }
        }
    }
}

$totalClientes = 0;
$clientesActivos = 0;
$clientesNoActivos = 0;
$clientesConWhatsapp = 0;

if (existeTabla($conn, 'clientes')) {
    $columnasClientes = obtenerColumnasTabla($conn, 'clientes');

    $resTotalClientes = $conn->query("SELECT COUNT(*) AS total FROM clientes");
    if ($resTotalClientes) {
        $totalClientes = (int)($resTotalClientes->fetch_assoc()['total'] ?? 0);
    }

    if (tieneColumna($columnasClientes, 'telefono')) {
        $resWhatsapp = $conn->query("
            SELECT COUNT(*) AS total
            FROM clientes
            WHERE telefono IS NOT NULL AND TRIM(telefono) <> ''
        ");
        if ($resWhatsapp) {
            $clientesConWhatsapp = (int)($resWhatsapp->fetch_assoc()['total'] ?? 0);
        }
    }

    if (existeTabla($conn, 'ventas') && tieneColumna($columnasClientes, 'id') && tieneColumna($columnasVentas, 'cliente_id')) {
        $resActivos = $conn->query("
            SELECT COUNT(DISTINCT c.id) AS total
            FROM clientes c
            INNER JOIN ventas v ON v.cliente_id = c.id
            WHERE v.cliente_id IS NOT NULL
        ");
        if ($resActivos) {
            $clientesActivos = (int)($resActivos->fetch_assoc()['total'] ?? 0);
        }
    }

    $clientesNoActivos = max(0, $totalClientes - $clientesActivos);
}

$ventasDashboard = [];
$pedidosPendientes = 0;
$pedidosPorEntregar = 0;
$pedidosEntregados = 0;

if (existeTabla($conn, 'ventas')) {
    $resVentas = $conn->query("SELECT * FROM ventas ORDER BY id DESC");
    if ($resVentas) {
        while ($row = $resVentas->fetch_assoc()) {
            $row['estado_normalizado'] = obtenerEstadoVenta($row);
            $row['cliente_nombre_resuelto'] = obtenerClienteNombreVenta($conn, $row);
            $row['cliente_telefono_resuelto'] = obtenerTelefonoClienteVenta($conn, $row);
            $ventasDashboard[] = $row;

            if (in_array($row['estado_normalizado'], ['Pedido', 'Pedido recibido', 'En proceso'], true)) {
                $pedidosPendientes++;
            } elseif ($row['estado_normalizado'] === 'Listo') {
                $pedidosPorEntregar++;
            } elseif ($row['estado_normalizado'] === 'Entregado') {
                $pedidosEntregados++;
            }
        }
    }
}

$vista = $_GET['vista'] ?? '';
$listadoTitulo = '';
$listadoVentas = [];

if ($vista === 'pedidos') {
    $listadoTitulo = 'Pedidos';
    foreach ($ventasDashboard as $venta) {
        if (in_array($venta['estado_normalizado'], ['Pedido', 'Pedido recibido', 'En proceso'], true)) {
            $listadoVentas[] = $venta;
        }
    }
} elseif ($vista === 'por_entregar') {
    $listadoTitulo = 'Pedidos por entregar';
    foreach ($ventasDashboard as $venta) {
        if ($venta['estado_normalizado'] === 'Listo') {
            $listadoVentas[] = $venta;
        }
    }
} elseif ($vista === 'entregados') {
    $listadoTitulo = 'Pedidos entregados';
    foreach ($ventasDashboard as $venta) {
        if ($venta['estado_normalizado'] === 'Entregado') {
            $listadoVentas[] = $venta;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Suave Urban Studio - Dashboard</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --bg: #0b0b0f;
            --gold: #c89b3c;
            --text: #ffffff;
            --text-soft: #cfcfcf;
            --border: rgba(255,255,255,0.08);
            --shadow: 0 10px 30px rgba(0,0,0,0.25);
        }

        * { box-sizing: border-box; }

        html, body {
            margin: 0;
            padding: 0;
            font-family: 'Segoe UI', sans-serif;
            min-height: 100%;
            color: var(--text);
        }

        body {
            display: flex;
            min-height: 100vh;
            background:
                <?php echo !empty($fondoContenido)
                    ? "linear-gradient(rgba(8,8,12,0.35), rgba(8,8,12,0.55)), url('" . htmlspecialchars($fondoContenido, ENT_QUOTES, 'UTF-8') . "') center/cover fixed no-repeat"
                    : "linear-gradient(135deg, #0b0b0f, #14151a)"; ?>;
            position: relative;
            overflow-x: hidden;
        }

        body::after{
            content:"";
            position:fixed;
            left:0;
            top:0;
            width:100%;
            height:100%;
            pointer-events:none;
            background:
                radial-gradient(circle at 20% 30%,rgba(200,155,60,.08) 0%,transparent 40%),
                radial-gradient(circle at 80% 70%,rgba(200,155,60,.06) 0%,transparent 40%);
            animation:particlesMove 12s linear infinite alternate;
            z-index:0;
        }

        .mobile-topbar { display: none; }
        .menu-toggle { display: none; }
        .sidebar-overlay { display: none; }

        .sidebar {
            width: 250px;
            background:
                <?php echo !empty($fondoSidebar)
                    ? "linear-gradient(rgba(10,10,10," . $alphaSidebar . "), rgba(10,10,10," . $alphaSidebar . ")), url('" . htmlspecialchars($fondoSidebar, ENT_QUOTES, 'UTF-8') . "') center/cover no-repeat"
                    : "rgba(20,21,26," . $alphaSidebar . ")"; ?>;
            border-right: 1px solid rgba(200, 155, 60, 0.2);
            padding: 20px;
            display: flex;
            flex-direction: column;
            position: fixed;
            top: 0;
            left: 0;
            height: 100vh;
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            z-index: 1000;
            overflow-y: auto;
            box-shadow: var(--shadow);
        }

        .logo-container {
            text-align: center;
            margin-bottom: 30px;
            padding: 10px;
        }

        .logo-sidebar {
            max-width: 120px;
            width: 100%;
            height: auto;
            filter: drop-shadow(0 0 10px rgba(200,155,60,0.5));
            animation: logoPulse 4s ease-in-out infinite, glow 3s infinite alternate;
            object-fit: contain;
        }

        @keyframes logoPulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.05); }
        }

        @keyframes glow {
            from { filter: drop-shadow(0 0 8px rgba(200,155,60,0.4)); }
            to { filter: drop-shadow(0 0 18px rgba(200,155,60,0.8)); }
        }

        @keyframes particlesMove{
            0%{transform:translateY(0);}
            100%{transform:translateY(-40px);}
        }

        .nav-item {
            color: #d0d0d0;
            text-decoration: none;
            padding: 12px 14px;
            margin: 5px 0;
            border-radius: 12px;
            transition: 0.3s;
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 15px;
            word-break: break-word;
            background: rgba(255,255,255,0.02);
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
        }

        .nav-item:hover,
        .active {
            background: rgba(200, 155, 60, 0.14);
            color: var(--gold);
            transform: translateX(3px);
        }

        .top-icon-link {
            color: var(--gold);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 42px;
            height: 42px;
            border-radius: 12px;
            background: rgba(255,255,255,0.05);
            margin-bottom: 15px;
            transition: 0.3s;
        }

        .top-icon-link:hover {
            background: rgba(200, 155, 60, 0.14);
            transform: scale(1.05);
        }

        .content {
            flex: 1;
            padding: 40px;
            margin-left: 250px;
            width: calc(100% - 250px);
            min-height: 100vh;
            position: relative;
            z-index: 2;
        }

        .content-inner {
            background: rgba(10, 10, 14, <?php echo $alphaPanel; ?>);
            border: 1px solid rgba(255,255,255,0.06);
            border-radius: 24px;
            padding: 30px;
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            box-shadow: var(--shadow);
        }

        .content h1 {
            margin-top: 0;
            font-size: 34px;
            line-height: 1.2;
            word-break: break-word;
        }

        .content p {
            color: var(--text-soft);
            font-size: 15px;
        }

        .stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 20px;
            margin-top: 25px;
        }

        .card {
            background: rgba(20, 21, 26, <?php echo max(0.18, min(0.92, $alphaPanel + 0.10)); ?>);
            padding: 20px;
            border-radius: 18px;
            border: 1px solid rgba(255,255,255,0.05);
            transition: 0.3s;
            animation: slideUp 0.5s ease-out forwards;
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            box-shadow: 0 8px 24px rgba(0,0,0,0.18);
            min-width: 0;
            position: relative;
            overflow: hidden;
        }

        .card::before{
            content:"";
            position:absolute;
            top:-50%;
            left:-60%;
            width:20%;
            height:200%;
            background:rgba(255,255,255,.08);
            transform:rotate(30deg);
            animation: cardshine 6s infinite;
        }

        .card.clickable {
            cursor: pointer;
            text-decoration: none;
            color: inherit;
            display: block;
        }

        .card:hover {
            transform: translateY(-5px);
            border-color: var(--gold);
            box-shadow: 0 10px 28px rgba(0,0,0,.5), 0 0 18px rgba(200,155,60,.22);
        }

        @keyframes slideUp {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        @keyframes cardshine{
            0%{left:-60%;}
            15%,100%{left:120%;}
        }

        .card h3 {
            color: var(--gold);
            margin: 0;
            font-size: 14px;
            letter-spacing: 0.3px;
            position: relative;
            z-index: 2;
        }

        .card .number {
            font-size: 32px;
            font-weight: bold;
            margin-top: 10px;
            line-height: 1.1;
            word-break: break-word;
            position: relative;
            z-index: 2;
        }

        .card .mini {
            margin-top: 8px;
            color: var(--text-soft);
            font-size: 12px;
            position: relative;
            z-index: 2;
        }

        .btn-logout {
            margin-top: auto;
            color: #ff6b6b;
            text-decoration: none;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 12px 14px;
            border-radius: 12px;
            transition: 0.3s;
        }

        .btn-logout:hover {
            color: #ffffff;
            background: rgba(255, 77, 77, 0.14);
        }

        .notice-success,
        .notice-error {
            margin-top: 18px;
            padding: 14px 16px;
            border-radius: 14px;
            font-size: 14px;
            font-weight: 700;
        }

        .notice-success {
            background: rgba(22, 163, 74, 0.14);
            border: 1px solid rgba(22, 163, 74, 0.35);
            color: #bbf7d0;
        }

        .notice-error {
            background: rgba(220, 38, 38, 0.14);
            border: 1px solid rgba(220, 38, 38, 0.35);
            color: #fecaca;
        }

        .list-panel {
            margin-top: 28px;
            background: rgba(20, 21, 26, <?php echo max(0.18, min(0.92, $alphaPanel + 0.10)); ?>);
            border-radius: 18px;
            border: 1px solid rgba(255,255,255,0.05);
            padding: 20px;
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            box-shadow: 0 8px 24px rgba(0,0,0,0.18);
        }

        .list-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            flex-wrap: wrap;
            margin-bottom: 16px;
        }

        .list-head h2 {
            margin: 0;
            color: var(--gold);
            font-size: 20px;
        }

        .btn-clear {
            text-decoration: none;
            background: rgba(255,255,255,0.08);
            color: var(--text);
            padding: 10px 14px;
            border-radius: 12px;
            font-size: 13px;
            font-weight: 700;
            transition: 0.3s;
        }

        .btn-clear:hover {
            background: rgba(200,155,60,0.16);
            color: var(--gold);
        }

        .table-wrap {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 760px;
        }

        th, td {
            padding: 14px 12px;
            border-bottom: 1px solid rgba(255,255,255,0.07);
            text-align: left;
            vertical-align: middle;
            font-size: 14px;
        }

        th {
            color: var(--gold);
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 0.4px;
        }

        .estado-pill {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 8px 12px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 800;
            background: rgba(255,255,255,0.08);
        }

        .estado-pedido { background: rgba(234, 179, 8, 0.18); color: #fde68a; }
        .estado-recibido { background: rgba(59, 130, 246, 0.18); color: #bfdbfe; }
        .estado-proceso { background: rgba(249, 115, 22, 0.18); color: #fdba74; }
        .estado-listo { background: rgba(34, 197, 94, 0.18); color: #bbf7d0; }
        .estado-entregado { background: rgba(168, 85, 247, 0.18); color: #e9d5ff; }

        .btn-entregar {
            border: none;
            background: linear-gradient(135deg, #16a34a, #22c55e);
            color: #fff;
            font-weight: 800;
            padding: 10px 14px;
            border-radius: 12px;
            cursor: pointer;
            transition: 0.3s;
        }

        .btn-entregar:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 18px rgba(34,197,94,0.22);
        }

        .empty-box {
            margin-top: 10px;
            padding: 18px;
            border: 1px dashed rgba(255,255,255,0.08);
            border-radius: 14px;
            color: var(--text-soft);
            text-align: center;
        }

        /* CHAT */
        .chat-float-btn {
            position: fixed;
            right: 24px;
            bottom: 24px;
            width: 60px;
            height: 60px;
            border: none;
            border-radius: 50%;
            background: linear-gradient(135deg, #c89b3c, #eec064);
            color: #111;
            font-size: 24px;
            cursor: pointer;
            z-index: 1200;
            box-shadow: 0 15px 35px rgba(0,0,0,0.35);
        }

        .chat-badge {
            position: absolute;
            top: -4px;
            right: -2px;
            min-width: 20px;
            height: 20px;
            padding: 0 6px;
            border-radius: 999px;
            background: #ef4444;
            color: #fff;
            font-size: 11px;
            font-weight: 800;
            display: none;
            align-items: center;
            justify-content: center;
        }

        .chat-window {
            position: fixed;
            right: 24px;
            bottom: 96px;
            width: 360px;
            max-width: calc(100vw - 24px);
            height: 520px;
            background: rgba(10,10,14,0.96);
            border: 1px solid rgba(200,155,60,0.16);
            border-radius: 20px;
            box-shadow: 0 20px 45px rgba(0,0,0,0.45);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            z-index: 1200;
            display: none;
            overflow: hidden;
        }

        .chat-window.open { display: flex; flex-direction: column; }

        .chat-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            padding: 14px 16px;
            border-bottom: 1px solid rgba(255,255,255,0.08);
            background: rgba(255,255,255,0.03);
        }

        .chat-header-left {
            display: flex;
            flex-direction: column;
            min-width: 0;
        }

        .chat-title {
            font-weight: 800;
            color: var(--gold);
        }

        .chat-subtitle {
            color: #bdbdbd;
            font-size: 12px;
        }

        .chat-close {
            background: transparent;
            color: #fff;
            border: none;
            font-size: 20px;
            cursor: pointer;
        }

        .chat-tabs {
            display: flex;
            gap: 8px;
            padding: 10px 12px 0 12px;
        }

        .chat-tab {
            flex: 1;
            border: 1px solid rgba(255,255,255,0.08);
            background: rgba(255,255,255,0.03);
            color: #fff;
            padding: 10px 12px;
            border-radius: 12px;
            cursor: pointer;
            font-weight: 700;
        }

        .chat-tab.active {
            background: rgba(200,155,60,0.16);
            color: var(--gold);
            border-color: rgba(200,155,60,0.28);
        }

        .chat-controls {
            padding: 10px 12px;
            border-bottom: 1px solid rgba(255,255,255,0.06);
        }

        .chat-controls select {
            width: 100%;
            background: rgba(255,255,255,0.06);
            color: #fff;
            border: 1px solid rgba(255,255,255,0.08);
            border-radius: 12px;
            padding: 12px;
        }

        .chat-controls select option {
            background: #111;
            color: #fff;
        }

        .chat-users-info {
            padding: 0 12px 10px 12px;
            font-size: 12px;
            color: #bbb;
        }

        .chat-body {
            flex: 1;
            overflow-y: auto;
            padding: 14px 12px;
            display: flex;
            flex-direction: column;
            gap: 10px;
            background: linear-gradient(180deg, rgba(255,255,255,0.02), transparent);
        }

        .chat-msg {
            max-width: 82%;
            padding: 10px 12px;
            border-radius: 16px;
            position: relative;
            word-wrap: break-word;
            line-height: 1.4;
        }

        .chat-msg.other {
            align-self: flex-start;
            background: rgba(255,255,255,0.06);
            color: #fff;
            border-top-left-radius: 6px;
        }

        .chat-msg.mine {
            align-self: flex-end;
            background: rgba(200,155,60,0.18);
            color: #fff;
            border-top-right-radius: 6px;
        }

        .chat-meta {
            font-size: 11px;
            color: #cfcfcf;
            margin-bottom: 4px;
        }

        .chat-text {
            font-size: 14px;
            white-space: pre-wrap;
        }

        .chat-time {
            font-size: 11px;
            color: #d8d8d8;
            margin-top: 6px;
            text-align: right;
            display: flex;
            gap: 6px;
            justify-content: flex-end;
            align-items: center;
        }

        .ticks {
            font-size: 12px;
            letter-spacing: -1px;
        }

        .ticks.sent { color: #d1d5db; }
        .ticks.delivered { color: #d1d5db; }
        .ticks.read { color: #60a5fa; }

        .chat-footer {
            padding: 12px;
            border-top: 1px solid rgba(255,255,255,0.08);
            background: rgba(255,255,255,0.03);
        }

        .chat-form {
            display: flex;
            gap: 8px;
            align-items: flex-end;
        }

        .chat-form textarea {
            flex: 1;
            min-height: 44px;
            max-height: 110px;
            resize: none;
            background: rgba(255,255,255,0.06);
            color: #fff;
            border: 1px solid rgba(255,255,255,0.08);
            border-radius: 14px;
            padding: 12px;
            outline: none;
        }

        .chat-send {
            width: 48px;
            height: 48px;
            border: none;
            border-radius: 14px;
            background: linear-gradient(135deg, #c89b3c, #eec064);
            color: #111;
            cursor: pointer;
            font-size: 18px;
        }

        .user-status {
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .dot-status {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            display: inline-block;
        }

        .dot-online { background: #22c55e; }
        .dot-offline { background: #6b7280; }

        .chat-empty {
            color: #aaa;
            text-align: center;
            margin-top: 40px;
            font-size: 14px;
        }

        @media (max-width: 1024px) {
            .content { padding: 25px; }
            .content-inner { padding: 24px; }
            .content h1 { font-size: 28px; }
        }

        @media (max-width: 768px) {
            body { display: block; }

            .mobile-topbar {
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 12px;
                position: sticky;
                top: 0;
                z-index: 1100;
                padding: 14px 16px;
                background: rgba(10, 10, 14, 0.72);
                backdrop-filter: blur(10px);
                -webkit-backdrop-filter: blur(10px);
                border-bottom: 1px solid rgba(255,255,255,0.06);
            }

            .mobile-brand {
                display: flex;
                align-items: center;
                gap: 10px;
                min-width: 0;
            }

            .mobile-brand img {
                width: 42px;
                height: 42px;
                object-fit: contain;
                border-radius: 10px;
                background: rgba(255,255,255,0.04);
                padding: 4px;
                animation: logoPulse 4s ease-in-out infinite, glow 3s infinite alternate;
            }

            .mobile-brand span {
                font-size: 14px;
                font-weight: 600;
                color: var(--text);
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
            }

            .menu-toggle {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                width: 44px;
                height: 44px;
                border: none;
                border-radius: 12px;
                background: rgba(255,255,255,0.08);
                color: var(--gold);
                font-size: 20px;
                cursor: pointer;
            }

            .sidebar-overlay {
                display: block;
                position: fixed;
                inset: 0;
                background: rgba(0,0,0,0.45);
                opacity: 0;
                visibility: hidden;
                transition: 0.3s;
                z-index: 999;
            }

            .sidebar {
                width: 280px;
                max-width: 85vw;
                transform: translateX(-100%);
                transition: transform 0.3s ease;
            }

            body.menu-open .sidebar {
                transform: translateX(0);
            }

            body.menu-open .sidebar-overlay {
                opacity: 1;
                visibility: visible;
            }

            .content {
                margin-left: 0;
                width: 100%;
                padding: 16px;
            }

            .content-inner {
                padding: 18px;
                border-radius: 18px;
            }

            .content h1 {
                font-size: 24px;
            }

            .stats {
                grid-template-columns: 1fr;
                gap: 14px;
            }

            .chat-window {
                right: 12px;
                left: 12px;
                width: auto;
                bottom: 84px;
                height: 68vh;
                max-width: none;
            }

            .chat-float-btn {
                right: 16px;
                bottom: 16px;
            }
        }
    </style>
</head>
<body>

    <div class="mobile-topbar">
        <div class="mobile-brand">
            <img src="<?php echo htmlspecialchars($logoActual, ENT_QUOTES, 'UTF-8'); ?>" alt="Logo">
            <span>Suave Urban Studio</span>
        </div>
        <button class="menu-toggle" id="menuToggle" aria-label="Abrir menú">
            <i class="fas fa-bars"></i>
        </button>
    </div>

    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <div class="sidebar" id="sidebar">
        <?php if ($esAdmin): ?>
            <a href="proveedores.php" title="Proveedores y Gastos" class="top-icon-link"><i class="fas fa-truck-loading"></i></a>
        <?php endif; ?>

        <div class="logo-container">
            <img src="<?php echo htmlspecialchars($logoActual, ENT_QUOTES, 'UTF-8'); ?>" alt="Logo" class="logo-sidebar">
        </div>

        <a href="dashboard.php" class="nav-item active"><i class="fas fa-home"></i> Inicio</a>

        <?php if ($esAdmin || $esMostrador): ?>
            <a href="ventas.php" class="nav-item"><i class="fas fa-cash-register"></i> Terminal Ventas</a>
            <a href="clientes.php" class="nav-item"><i class="fas fa-users"></i> Clientes</a>
            <a href="pedidos.php" class="nav-item"><i class="fas fa-tasks"></i> Pedidos</a>
        <?php endif; ?>

        <?php if ($esAdmin): ?>
            <a href="productos.php" class="nav-item"><i class="fas fa-box"></i> Inventario</a>
            <a href="usuarios.php" class="nav-item"><i class="fas fa-user-shield"></i> Usuarios</a>
            <a href="papelera.php" class="nav-item"><i class="fas fa-trash-alt"></i> Papelera</a>
            <a href="configuracion.php" class="nav-item"><i class="fas fa-cog"></i> Configuración</a>
        <?php endif; ?>

        <?php if ($esProduccion): ?>
            <a href="produccion.php" class="nav-item"><i class="fas fa-industry"></i> Producción</a>
        <?php endif; ?>

        <a href="logout.php" class="btn-logout"><i class="fas fa-sign-out-alt"></i> Cerrar Sesión</a>
    </div>

    <div class="content">
        <div class="content-inner">
            <h1>Bienvenido, <?php echo htmlspecialchars($nombreUsuario, ENT_QUOTES, 'UTF-8'); ?></h1>
            <p>Resumen general del sistema y control de entregas.</p>

            <?php if ($mensaje !== ''): ?>
                <div class="notice-success"><?php echo limpiar($mensaje); ?></div>
            <?php endif; ?>

            <?php if ($error !== ''): ?>
                <div class="notice-error"><?php echo limpiar($error); ?></div>
            <?php endif; ?>

            <div class="stats">
                <div class="card">
                    <h3>Clientes activos</h3>
                    <div class="number"><?php echo number_format($clientesActivos); ?></div>
                    <div class="mini">Clientes con al menos una venta</div>
                </div>

                <div class="card">
                    <h3>Clientes no activos</h3>
                    <div class="number"><?php echo number_format($clientesNoActivos); ?></div>
                    <div class="mini">Clientes registrados sin ventas</div>
                </div>

                <a href="dashboard.php?vista=pedidos" class="card clickable">
                    <h3>Pedidos</h3>
                    <div class="number"><?php echo number_format($pedidosPendientes); ?></div>
                    <div class="mini">Pedido, recibido y en proceso</div>
                </a>

                <a href="dashboard.php?vista=por_entregar" class="card clickable">
                    <h3>Pedidos por entregar</h3>
                    <div class="number"><?php echo number_format($pedidosPorEntregar); ?></div>
                    <div class="mini">Solo pedidos en estado Listo</div>
                </a>

                <a href="dashboard.php?vista=entregados" class="card clickable">
                    <h3>Pedidos entregados</h3>
                    <div class="number"><?php echo number_format($pedidosEntregados); ?></div>
                    <div class="mini">Pedidos ya entregados al cliente</div>
                </a>

                <?php if ($esAdmin || $esMostrador): ?>
                    <a href="promociones_whatsapp.php" class="card clickable">
                        <h3>Promociones WhatsApp</h3>
                        <div class="number"><?php echo number_format($clientesConWhatsapp); ?></div>
                        <div class="mini">Clientes con teléfono para ofertas</div>
                    </a>
                <?php endif; ?>

                <?php if ($esProduccion): ?>
                    <a href="produccion.php" class="card clickable">
                        <h3>Módulo Producción</h3>
                        <div class="number"><i class="fas fa-industry"></i></div>
                        <div class="mini">Entrar al panel de producción</div>
                    </a>
                <?php endif; ?>
            </div>

            <?php if ($vista !== ''): ?>
                <div class="list-panel">
                    <div class="list-head">
                        <h2><?php echo limpiar($listadoTitulo); ?></h2>
                        <a href="dashboard.php" class="btn-clear">Cerrar vista</a>
                    </div>

                    <?php if (empty($listadoVentas)): ?>
                        <div class="empty-box">No hay registros en esta vista.</div>
                    <?php else: ?>
                        <div class="table-wrap">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Remisión</th>
                                        <th>Cliente</th>
                                        <th>Estatus</th>
                                        <th>Fecha entrega</th>
                                        <th>Día entrega</th>
                                        <?php if ($vista === 'por_entregar' && ($esAdmin || $esMostrador)): ?>
                                            <th>Acción</th>
                                        <?php endif; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($listadoVentas as $venta): ?>
                                        <?php
                                            $estadoClase = match ($venta['estado_normalizado']) {
                                                'Pedido' => 'estado-pedido',
                                                'Pedido recibido' => 'estado-recibido',
                                                'En proceso' => 'estado-proceso',
                                                'Listo' => 'estado-listo',
                                                'Entregado' => 'estado-entregado',
                                                default => 'estado-pedido',
                                            };
                                        ?>
                                        <tr>
                                            <td><?php echo limpiar($venta['folio'] ?? ('REM-' . $venta['id'])); ?></td>
                                            <td><?php echo limpiar($venta['cliente_nombre_resuelto']); ?></td>
                                            <td>
                                                <span class="estado-pill <?php echo $estadoClase; ?>">
                                                    <?php echo limpiar($venta['estado_normalizado']); ?>
                                                </span>
                                            </td>
                                            <td><?php echo limpiar($venta['fecha_entrega'] ?? '-'); ?></td>
                                            <td><?php echo limpiar($venta['dia_entrega'] ?? '-'); ?></td>
                                            <?php if ($vista === 'por_entregar' && ($esAdmin || $esMostrador)): ?>
                                                <td>
                                                    <?php if ($venta['estado_normalizado'] === 'Listo'): ?>
                                                        <form method="POST" style="margin:0;">
                                                            <input type="hidden" name="accion" value="entregar">
                                                            <input type="hidden" name="venta_id" value="<?php echo (int)$venta['id']; ?>">
                                                            <button type="submit" class="btn-entregar">Entregar</button>
                                                        </form>
                                                    <?php else: ?>
                                                        -
                                                    <?php endif; ?>
                                                </td>
                                            <?php endif; ?>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <button class="chat-float-btn" id="chatFloatBtn" type="button">
        <i class="fas fa-comment-dots"></i>
        <span class="chat-badge" id="chatBadge">0</span>
    </button>

    <div class="chat-window" id="chatWindow">
        <div class="chat-header">
            <div class="chat-header-left">
                <div class="chat-title">Mensajería interna</div>
                <div class="chat-subtitle" id="chatSubtitle">General del sistema</div>
            </div>
            <button class="chat-close" id="chatCloseBtn" type="button">&times;</button>
        </div>

        <div class="chat-tabs">
            <button class="chat-tab active" id="tabGrupal" type="button">Grupal</button>
            <button class="chat-tab" id="tabPrivado" type="button">Privado</button>
        </div>

        <div class="chat-controls" id="chatControls" style="display:none;">
            <select id="chatUserSelect">
                <option value="">Selecciona un usuario</option>
            </select>
        </div>

        <div class="chat-users-info" id="chatUsersInfo">Cargando usuarios...</div>

        <div class="chat-body" id="chatBody">
            <div class="chat-empty">Sin mensajes todavía.</div>
        </div>

        <div class="chat-footer">
            <form class="chat-form" id="chatForm">
                <textarea id="chatMessage" placeholder="Escribe un mensaje"></textarea>
                <button type="submit" class="chat-send"><i class="fas fa-paper-plane"></i></button>
            </form>
        </div>
    </div>

    <?php if (!empty($sonidoMensajes)): ?>
        <audio id="chatSound" preload="auto">
            <source src="<?php echo htmlspecialchars($sonidoMensajes, ENT_QUOTES, 'UTF-8'); ?>">
        </audio>
    <?php endif; ?>

    <script>
    const body = document.body;
    const menuToggle = document.getElementById('menuToggle');
    const sidebarOverlay = document.getElementById('sidebarOverlay');

    function closeMenu() {
        body.classList.remove('menu-open');
    }

    function openMenu() {
        body.classList.add('menu-open');
    }

    if (menuToggle) {
        menuToggle.addEventListener('click', function () {
            if (body.classList.contains('menu-open')) {
                closeMenu();
            } else {
                openMenu();
            }
        });
    }

    if (sidebarOverlay) {
        sidebarOverlay.addEventListener('click', closeMenu);
    }

    window.addEventListener('resize', function () {
        if (window.innerWidth > 768) {
            closeMenu();
        }
    });

    /* CHAT */
    const chatFloatBtn = document.getElementById('chatFloatBtn');
    const chatWindow = document.getElementById('chatWindow');
    const chatCloseBtn = document.getElementById('chatCloseBtn');
    const tabGrupal = document.getElementById('tabGrupal');
    const tabPrivado = document.getElementById('tabPrivado');
    const chatControls = document.getElementById('chatControls');
    const chatUserSelect = document.getElementById('chatUserSelect');
    const chatBody = document.getElementById('chatBody');
    const chatForm = document.getElementById('chatForm');
    const chatMessage = document.getElementById('chatMessage');
    const chatUsersInfo = document.getElementById('chatUsersInfo');
    const chatSubtitle = document.getElementById('chatSubtitle');
    const chatBadge = document.getElementById('chatBadge');
    const chatSound = document.getElementById('chatSound');

    let chatModo = 'grupal';
    let chatDestino = '';
    let ultimoMensajeId = 0;
    let inicializadoChat = false;
    let usuariosChat = [];
    let unreadCount = 0;

    function abrirChat() {
        if (chatWindow) {
            chatWindow.classList.add('open');
            unreadCount = 0;
            renderBadge();
            cargarMensajes();
        }
    }

    function cerrarChat() {
        if (chatWindow) {
            chatWindow.classList.remove('open');
        }
    }

    function renderBadge() {
        if (!chatBadge) return;

        if (unreadCount > 0) {
            chatBadge.style.display = 'inline-flex';
            chatBadge.textContent = unreadCount > 99 ? '99+' : String(unreadCount);
        } else {
            chatBadge.style.display = 'none';
        }
    }

    if (chatFloatBtn) {
        chatFloatBtn.addEventListener('click', abrirChat);
    }

    if (chatCloseBtn) {
        chatCloseBtn.addEventListener('click', cerrarChat);
    }

    if (tabGrupal) {
        tabGrupal.addEventListener('click', function() {
            chatModo = 'grupal';
            chatDestino = '';
            tabGrupal.classList.add('active');
            if (tabPrivado) tabPrivado.classList.remove('active');
            if (chatControls) chatControls.style.display = 'none';
            if (chatSubtitle) chatSubtitle.textContent = 'General del sistema';
            cargarMensajes();
        });
    }

    if (tabPrivado) {
        tabPrivado.addEventListener('click', function() {
            chatModo = 'privado';
            tabPrivado.classList.add('active');
            if (tabGrupal) tabGrupal.classList.remove('active');
            if (chatControls) chatControls.style.display = 'block';
            cargarMensajes();
        });
    }

    if (chatUserSelect) {
        chatUserSelect.addEventListener('change', function() {
            chatDestino = this.value;
            const opt = this.options[this.selectedIndex];
            if (chatSubtitle) {
                chatSubtitle.textContent = this.value ? ('Chat con ' + opt.text) : 'Selecciona un usuario';
            }
            cargarMensajes();
        });
    }

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text ?? '';
        return div.innerHTML;
    }

    function formatoHora(fechaStr) {
        const fecha = new Date((fechaStr || '').replace(' ', 'T'));
        if (isNaN(fecha.getTime())) return '';
        return fecha.toLocaleTimeString([], {hour:'2-digit', minute:'2-digit'});
    }

    function tickHtml(status) {
        if (!status) return '';
        if (status === 'sent') return '<span class="ticks sent">✓</span>';
        if (status === 'delivered') return '<span class="ticks delivered">✓✓</span>';
        if (status === 'read') return '<span class="ticks read">✓✓</span>';
        return '';
    }

    function renderUsuarios(users) {
        usuariosChat = users || [];

        if (!chatUserSelect) return;

        const actualValue = chatUserSelect.value;
        chatUserSelect.innerHTML = '<option value="">Selecciona un usuario</option>';

        let onlineCount = 0;

        usuariosChat.forEach(u => {
            const option = document.createElement('option');
            option.value = String(u.id);
            option.textContent = `${u.nombre} (${u.rol}) ${u.en_linea ? '• en línea' : '• offline'}`;
            if (String(u.id) === String(actualValue)) {
                option.selected = true;
            }
            chatUserSelect.appendChild(option);

            if (u.en_linea) onlineCount++;
        });

        if (chatUsersInfo) {
            chatUsersInfo.innerHTML = `${onlineCount} en línea · ${usuariosChat.length} usuarios disponibles`;
        }

        if (chatModo === 'privado' && !chatUserSelect.value && usuariosChat.length > 0) {
            chatUserSelect.value = String(usuariosChat[0].id);
            chatDestino = chatUserSelect.value;
            if (chatSubtitle) {
                chatSubtitle.textContent = 'Chat con ' + chatUserSelect.options[chatUserSelect.selectedIndex].text;
            }
        }
    }

    function renderMensajes(items) {
        if (!chatBody) return;

        if (!items || items.length === 0) {
            chatBody.innerHTML = '<div class="chat-empty">Sin mensajes todavía.</div>';
            return;
        }

        chatBody.innerHTML = '';

        items.forEach(item => {
            const wrap = document.createElement('div');
            wrap.className = 'chat-msg ' + (item.mio ? 'mine' : 'other');

            const meta = document.createElement('div');
            meta.className = 'chat-meta';
            meta.textContent = `${item.remitente_nombre} · ${item.remitente_rol}`;

            const text = document.createElement('div');
            text.className = 'chat-text';
            text.innerHTML = escapeHtml(item.mensaje).replace(/\n/g, '<br>');

            const time = document.createElement('div');
            time.className = 'chat-time';
            time.innerHTML = `<span>${formatoHora(item.creado_en)}</span>${item.mio ? tickHtml(item.ticks) : ''}`;

            wrap.appendChild(meta);
            wrap.appendChild(text);
            wrap.appendChild(time);

            chatBody.appendChild(wrap);
        });

        chatBody.scrollTop = chatBody.scrollHeight;
    }

    async function cargarMensajes() {
        try {
            const url = new URL('mensajes_cargar.php', window.location.origin);
            url.searchParams.set('tipo', chatModo);
            if (chatModo === 'privado' && chatDestino) {
                url.searchParams.set('destinatario_id', chatDestino);
            }

            const res = await fetch(url.toString(), {cache: 'no-store'});
            const data = await res.json();

            if (!data.ok) return;

            renderUsuarios(data.usuarios || []);
            renderMensajes(data.mensajes || []);

            if (Array.isArray(data.mensajes) && data.mensajes.length > 0) {
                const maxId = Math.max(...data.mensajes.map(m => Number(m.id || 0)));
                const hayNuevo = inicializadoChat && maxId > ultimoMensajeId;

                if (hayNuevo) {
                    const nuevos = data.mensajes.filter(m => Number(m.id) > ultimoMensajeId && !m.mio);
                    if (nuevos.length > 0) {
                        if (!chatWindow || !chatWindow.classList.contains('open')) {
                            unreadCount += nuevos.length;
                            renderBadge();
                        }
                        if (chatSound) {
                            try {
                                chatSound.currentTime = 0;
                                chatSound.play().catch(() => {});
                            } catch(e) {}
                        }
                    }
                }

                ultimoMensajeId = Math.max(ultimoMensajeId, maxId);
            }

            inicializadoChat = true;
        } catch (e) {
            console.log('Error cargando mensajes', e);
        }
    }

    if (chatForm) {
        chatForm.addEventListener('submit', async function(e) {
            e.preventDefault();

            const mensaje = chatMessage ? chatMessage.value.trim() : '';
            if (!mensaje) return;

            const formData = new FormData();
            formData.append('tipo', chatModo);
            formData.append('mensaje', mensaje);

            if (chatModo === 'privado') {
                if (!chatDestino) {
                    alert('Selecciona un usuario');
                    return;
                }
                formData.append('destinatario_id', chatDestino);
            }

            try {
                const res = await fetch('mensajes_enviar.php', {
                    method: 'POST',
                    body: formData
                });
                const data = await res.json();

                if (data.ok) {
                    if (chatMessage) chatMessage.value = '';
                    cargarMensajes();
                } else {
                    alert(data.mensaje || 'No se pudo enviar el mensaje');
                }
            } catch (e) {
                alert('Error al enviar mensaje');
            }
        });
    }

    async function mantenerEstado() {
        try {
            await fetch('mensajes_estado.php', {method: 'POST'});
        } catch (e) {}
    }

    setInterval(cargarMensajes, 4000);
    setInterval(mantenerEstado, 30000);
    mantenerEstado();
    cargarMensajes();

    /* WEBSOCKET */
    (function () {
        try {
            const socket = new WebSocket("ws://72.61.200.11:8080");

            socket.onopen = function () {
                console.log("WebSocket conectado");
            };

            socket.onmessage = function (event) {
                try {
                    const data = JSON.parse(event.data);
                    console.log("Mensaje WebSocket:", data);

                    if (data.tipo === "pedido_nuevo" || data.tipo === "estatus_actualizado" || data.tipo === "refresh") {
                        location.reload();
                    }
                } catch (e) {
                    console.error("Error WebSocket:", e);
                }
            };

            socket.onclose = function () {
                console.warn("WebSocket desconectado");
            };

            socket.onerror = function (err) {
                console.error("Error de conexión WebSocket:", err);
            };
        } catch (e) {
            console.error("No se pudo iniciar WebSocket:", e);
        }
    })();
</script>

</body>
</html>
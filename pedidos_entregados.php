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

function obtenerValorEstado(array $row): string
{
    $estado = strtoupper(trim((string)($row['estado'] ?? '')));
    $estatus = strtoupper(trim((string)($row['estatus'] ?? '')));

    if ($estado !== '') return $estado;
    if ($estatus !== '') return $estatus;

    return 'NUEVO';
}

function obtenerImagenPedido(array $pedido, mysqli $conn): string
{
    if (!empty($pedido['imagen_diseno'])) {
        return (string)$pedido['imagen_diseno'];
    }

    $ventaId = isset($pedido['venta_id']) ? (int)$pedido['venta_id'] : 0;
    if ($ventaId > 0 && existeTabla($conn, 'ventas')) {
        $colsVentas = obtenerColumnasTabla($conn, 'ventas');
        if (tieneColumna($colsVentas, 'imagen_diseno')) {
            $stmt = $conn->prepare("SELECT imagen_diseno FROM ventas WHERE id = ? LIMIT 1");
            $stmt->bind_param("i", $ventaId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!empty($row['imagen_diseno'])) {
                return (string)$row['imagen_diseno'];
            }
        }
    }

    return '';
}

if (!existeTabla($conn, 'pedidos')) {
    die('La tabla pedidos no existe todavía.');
}

$fondoSidebar = '';
$fondoContenido = '';
$logoActual = 'logo.png';
$alphaPanel = 0.20;
$alphaSidebar = 0.88;
$nombreNegocio = 'Suave Urban Studio';
$nombreUsuario = $_SESSION['nombre'] ?? 'Usuario';

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

$buscar = isset($_GET['buscar']) ? trim((string)$_GET['buscar']) : '';
$pedidos = [];

$sql = "SELECT * FROM pedidos WHERE (UPPER(COALESCE(estado,'')) = 'ENTREGADO' OR UPPER(COALESCE(estatus,'')) = 'ENTREGADO')";
$params = [];
$types = '';

if ($buscar !== '') {
    $sql .= " AND folio LIKE ?";
    $params[] = '%' . $buscar . '%';
    $types .= 's';
}

$sql .= " ORDER BY COALESCE(fecha_entregado, fecha_registro, NOW()) DESC, id DESC";

$stmtPedidos = $conn->prepare($sql);
if (!empty($params)) {
    $stmtPedidos->bind_param($types, ...$params);
}
$stmtPedidos->execute();
$resPedidos = $stmtPedidos->get_result();
while ($row = $resPedidos->fetch_assoc()) {
    $row['imagen_final'] = obtenerImagenPedido($row, $conn);
    $pedidos[] = $row;
}
$stmtPedidos->close();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pedidos entregados - <?php echo e($nombreNegocio); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root{
            --gold:#c89b3c;
            --bg:#070709;
            --border:rgba(200,155,60,.20);
            --shadow:0 16px 34px rgba(0,0,0,.28);
        }
        *{box-sizing:border-box}
        body{
            margin:0;
            min-height:100vh;
            font-family:'Segoe UI',sans-serif;
            color:#fff;
            background:
                <?php echo !empty($fondoContenido)
                    ? "linear-gradient(rgba(0,0,0,.46), rgba(0,0,0,.62)), url('" . htmlspecialchars($fondoContenido, ENT_QUOTES, 'UTF-8') . "') center/cover fixed no-repeat"
                    : "linear-gradient(135deg,#070709,#131318)"; ?>;
            display:flex;
        }
        .sidebar{
            width:85px;
            background:
                <?php echo !empty($fondoSidebar)
                    ? "linear-gradient(rgba(0,0,0," . $alphaSidebar . "), rgba(0,0,0," . $alphaSidebar . ")), url('" . htmlspecialchars($fondoSidebar, ENT_QUOTES, 'UTF-8') . "') center/cover no-repeat"
                    : "rgba(0,0,0," . $alphaSidebar . ")"; ?>;
            border-right:1px solid var(--border);
            position:fixed;
            top:0;left:0;bottom:0;
            display:flex;
            flex-direction:column;
            align-items:center;
            padding:15px 0;
            backdrop-filter:blur(10px);
            -webkit-backdrop-filter:blur(10px);
            z-index:1000;
        }
        .logo-pos{
            width:55px;
            margin-bottom:16px;
            filter:drop-shadow(0 0 10px rgba(200,155,60,.6));
            animation:logoPulse 4s ease-in-out infinite, glow 3s infinite alternate;
        }
        .nav-controls{
            display:flex;
            flex-direction:column;
            gap:18px;
            width:100%;
            align-items:center;
            padding-bottom:20px;
            border-bottom:1px solid var(--border);
            margin-bottom:26px;
        }
        .sidebar a{
            color:#5b5b5b;
            font-size:20px;
            text-decoration:none;
            transition:.25s ease;
        }
        .sidebar a:hover,.sidebar a.active{
            color:var(--gold);
            filter:drop-shadow(0 0 8px var(--gold));
        }
        .exit-btn:hover{
            color:#ff4d4d!important;
            filter:drop-shadow(0 0 8px #ff4d4d)!important;
        }
        .content{
            flex:1;
            margin-left:85px;
            padding:24px;
        }
        .hero,.toolbar,.card{
            background:rgba(255,255,255,<?php echo max(0.03, min(0.20, $alphaPanel * 0.35)); ?>);
            border:1px solid var(--border);
            box-shadow:var(--shadow);
            backdrop-filter:blur(10px);
            -webkit-backdrop-filter:blur(10px);
        }
        .hero{
            border-radius:22px;
            padding:20px;
            margin-bottom:16px;
        }
        .hero h1{margin:0 0 6px;font-size:32px}
        .hero p{margin:0;color:#ddd}
        .toolbar{
            border-radius:18px;
            padding:16px;
            margin-bottom:18px;
        }
        .toolbar form{
            display:grid;
            grid-template-columns:1fr auto auto;
            gap:12px;
        }
        .toolbar input{
            width:100%;
            border-radius:14px;
            border:1px solid rgba(255,255,255,.10);
            background:rgba(0,0,0,.28);
            color:#fff;
            padding:12px 14px;
            outline:none;
        }
        .btn-top{
            border:none;
            border-radius:14px;
            padding:12px 18px;
            font-weight:800;
            cursor:pointer;
        }
        .btn-search{background:var(--gold);color:#111}
        .btn-clear{background:#2c2c34;color:#fff;text-decoration:none;display:inline-flex;align-items:center}
        .cards{
            display:grid;
            gap:14px;
        }
        .card{
            border-radius:20px;
            padding:16px;
            overflow:hidden;
            position:relative;
        }
        .card::before{
            content:"";
            position:absolute;
            inset:0;
            background:linear-gradient(135deg, rgba(200,155,60,.07), transparent 35%, transparent 75%, rgba(200,155,60,.05));
            pointer-events:none;
        }
        .card-grid{
            display:grid;
            grid-template-columns:180px 1fr auto;
            gap:16px;
            align-items:start;
            position:relative;
            z-index:1;
        }
        .thumb{
            width:100%;
            height:180px;
            border-radius:16px;
            overflow:hidden;
            border:1px solid rgba(255,255,255,.08);
            background:rgba(0,0,0,.28);
            display:flex;
            align-items:center;
            justify-content:center;
        }
        .thumb img{width:100%;height:100%;object-fit:contain;background:#0c0c0f}
        .thumb-empty{font-size:13px;color:#aaa;padding:12px;text-align:center}
        .folio{
            color:var(--gold);
            font-size:12px;
            font-weight:800;
            letter-spacing:.8px;
            margin-bottom:8px;
        }
        .cliente{
            font-size:22px;
            font-weight:800;
            margin-bottom:10px;
        }
        .meta{
            display:grid;
            grid-template-columns:repeat(2,minmax(0,1fr));
            gap:10px 16px;
        }
        .meta div{
            font-size:13px;
            color:#ddd;
            line-height:1.5;
            word-break:break-word;
        }
        .meta strong{color:#fff}
        .status{
            display:inline-flex;
            align-items:center;
            justify-content:center;
            padding:10px 14px;
            border-radius:14px;
            background:rgba(34,197,94,.16);
            color:#d6ffe0;
            border:1px solid rgba(34,197,94,.30);
            font-weight:800;
            min-width:150px;
        }
        .empty{
            border:1px dashed rgba(255,255,255,.10);
            border-radius:18px;
            padding:20px;
            text-align:center;
            color:#bbb;
            background:rgba(255,255,255,.02);
        }
        @keyframes glow{
            from{filter:drop-shadow(0 0 5px rgba(200,155,60,.4))}
            to{filter:drop-shadow(0 0 15px rgba(200,155,60,.7))}
        }
        @keyframes logoPulse{
            0%,100%{transform:scale(1)}
            50%{transform:scale(1.08)}
        }
        @media (max-width:1100px){
            .card-grid{grid-template-columns:1fr}
            .meta{grid-template-columns:1fr}
        }
        @media (max-width:768px){
            .content{padding:16px 12px}
            .toolbar form{grid-template-columns:1fr}
            .hero h1{font-size:26px}
        }
    </style>
</head>
<body>
    <aside class="sidebar">
        <img src="<?php echo e($logoActual); ?>" alt="Logo" class="logo-pos">
        <div class="nav-controls">
            <a href="dashboard.php" title="Dashboard"><i class="fas fa-home"></i></a>
            <a href="ventas.php" title="Ventas"><i class="fas fa-cash-register"></i></a>
            <a href="clientes.php" title="Clientes"><i class="fas fa-users"></i></a>
            <a href="produccion.php" title="Producción"><i class="fas fa-industry"></i></a>
            <a href="pedidos.php" title="Pedidos"><i class="fas fa-clipboard-list"></i></a>
            <a href="pedidos_entregados.php" title="Entregados" class="active"><i class="fas fa-box-open"></i></a>
            <a href="configuracion.php" title="Configuración"><i class="fas fa-cog"></i></a>
        </div>
        <a href="logout.php" class="exit-btn" title="Cerrar sesión"><i class="fas fa-sign-out-alt"></i></a>
    </aside>

    <main class="content">
        <section class="hero">
            <h1>Pedidos entregados</h1>
            <p>Aquí se guardan todos los pedidos marcados como <strong>ENTREGADO</strong>.</p>
        </section>

        <section class="toolbar">
            <form method="GET">
                <input type="text" name="buscar" value="<?php echo e($buscar); ?>" placeholder="Buscar por folio...">
                <button type="submit" class="btn-top btn-search">Buscar</button>
                <a href="pedidos_entregados.php" class="btn-top btn-clear">Limpiar</a>
            </form>
        </section>

        <section class="cards">
            <?php if (!empty($pedidos)): ?>
                <?php foreach ($pedidos as $pedido): ?>
                    <article class="card">
                        <div class="card-grid">
                            <div class="thumb">
                                <?php if (!empty($pedido['imagen_final'])): ?>
                                    <img src="<?php echo e($pedido['imagen_final']); ?>" alt="Diseño">
                                <?php else: ?>
                                    <div class="thumb-empty">Sin imagen del diseño</div>
                                <?php endif; ?>
                            </div>

                            <div>
                                <div class="folio"><?php echo e($pedido['folio'] ?? 'SIN FOLIO'); ?></div>
                                <div class="cliente"><?php echo e($pedido['cliente_nombre'] ?? 'Cliente'); ?></div>

                                <div class="meta">
                                    <div><strong>Teléfono:</strong> <?php echo e($pedido['cliente_telefono'] ?? '-'); ?></div>
                                    <div><strong>Tipo:</strong> <?php echo e($pedido['tipo_cliente'] ?? '-'); ?></div>
                                    <div><strong>Producto:</strong> <?php echo e($pedido['producto'] ?? '-'); ?></div>
                                    <div><strong>Talla:</strong> <?php echo e($pedido['talla'] ?? '-'); ?></div>
                                    <div><strong>Color:</strong> <?php echo e($pedido['color'] ?? '-'); ?></div>
                                    <div><strong>Diseño:</strong> <?php echo e($pedido['diseno'] ?? '-'); ?></div>
                                    <div><strong>Fecha entrega:</strong> <?php echo e($pedido['fecha_entrega'] ?? '-'); ?></div>
                                    <div><strong>Fecha entregado:</strong> <?php echo e($pedido['fecha_entregado'] ?? '-'); ?></div>
                                </div>
                            </div>

                            <div class="status">ENTREGADO</div>
                        </div>
                    </article>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="empty">No hay pedidos entregados.</div>
            <?php endif; ?>
        </section>
    </main>
</body>
</html>

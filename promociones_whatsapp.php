<?php
session_start();
if (!isset($_SESSION['usuario_id'])) {
    header("Location: index.php");
    exit;
}

require_once 'config/database.php';

function existeTablaPromo(mysqli $conn, string $tabla): bool {
    $tabla = $conn->real_escape_string($tabla);
    $res = $conn->query("SHOW TABLES LIKE '$tabla'");
    return ($res && $res->num_rows > 0);
}

function obtenerColumnasPromo(mysqli $conn, string $tabla): array {
    $columnas = [];
    if (!existeTablaPromo($conn, $tabla)) return $columnas;

    $res = $conn->query("SHOW COLUMNS FROM `$tabla`");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $columnas[] = $row['Field'];
        }
    }
    return $columnas;
}

function tieneColumnaPromo(array $columnas, string $columna): bool {
    return in_array($columna, $columnas, true);
}

$fondoSidebar = '';
$fondoContenido = '';
$logoActual = 'logo.png';
$transparenciaPanel = 0.32;
$transparenciaSidebar = 0.88;

$configCols = obtenerColumnasPromo($conn, 'configuracion');
if (!empty($configCols)) {
    $selectConfig = [];

    foreach (['logo', 'fondo_sidebar', 'fondo_contenido', 'transparencia_panel', 'transparencia_sidebar'] as $col) {
        if (tieneColumnaPromo($configCols, $col)) {
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
        }
    }
}

$transparenciaPanel = max(0.10, min(0.95, $transparenciaPanel));
$transparenciaSidebar = max(0.10, min(0.98, $transparenciaSidebar));

$clientesCols = obtenerColumnasPromo($conn, 'clientes');
$ventasCols = obtenerColumnasPromo($conn, 'ventas');

$clientes = [];
$totalClientes = 0;
$totalDTF = 0;
$totalPersonalizado = 0;
$totalFrecuentes = 0;
$totalExclusivos = 0;

$colTieneTipo = tieneColumnaPromo($clientesCols, 'tipo_cliente');
$colTieneNombre = tieneColumnaPromo($clientesCols, 'nombre');
$colTieneTelefono = tieneColumnaPromo($clientesCols, 'telefono');
$colTieneEmail = tieneColumnaPromo($clientesCols, 'email');

$colExclusivo = null;
foreach (['cliente_exclusivo', 'es_exclusivo', 'exclusivo', 'frecuente_exclusivo'] as $candidato) {
    if (tieneColumnaPromo($clientesCols, $candidato)) {
        $colExclusivo = $candidato;
        break;
    }
}

if (existeTablaPromo($conn, 'clientes') && $colTieneNombre && $colTieneTelefono) {
    $sqlClientes = "SELECT id, nombre, telefono"
        . ($colTieneEmail ? ", email" : "")
        . ($colTieneTipo ? ", tipo_cliente" : "")
        . ($colExclusivo ? ", `$colExclusivo` AS exclusivo_flag" : "")
        . " FROM clientes
           WHERE telefono IS NOT NULL AND TRIM(telefono) <> ''
           ORDER BY nombre ASC";

    $resClientes = $conn->query($sqlClientes);
    if ($resClientes) {
        while ($row = $resClientes->fetch_assoc()) {
            $tipo = trim((string)($row['tipo_cliente'] ?? 'Personalizado'));
            if (!in_array($tipo, ['Personalizado', 'DTF'], true)) {
                $tipo = 'Personalizado';
            }

            $telefono = preg_replace('/\D+/', '', (string)($row['telefono'] ?? ''));
            $esExclusivo = false;

            if ($colExclusivo && isset($row['exclusivo_flag'])) {
                $valor = strtolower(trim((string)$row['exclusivo_flag']));
                $esExclusivo = in_array($valor, ['1', 'si', 'sí', 'true', 'exclusivo'], true);
            }

            $clientes[] = [
                'id' => (int)($row['id'] ?? 0),
                'nombre' => $row['nombre'] ?? '',
                'telefono' => $telefono,
                'email' => $row['email'] ?? '',
                'tipo_cliente' => $tipo,
                'es_exclusivo' => $esExclusivo,
                'es_frecuente' => false,
                'ventas_count' => 0
            ];
        }
    }
}

$mapClientes = [];
foreach ($clientes as $i => $cli) {
    $mapClientes[$cli['id']] = $i;
}

if (!empty($clientes) && existeTablaPromo($conn, 'ventas') && tieneColumnaPromo($ventasCols, 'cliente_id')) {
    $resFrecuentes = $conn->query("
        SELECT cliente_id, COUNT(*) AS total_ventas
        FROM ventas
        WHERE cliente_id IS NOT NULL
        GROUP BY cliente_id
    ");

    if ($resFrecuentes) {
        while ($row = $resFrecuentes->fetch_assoc()) {
            $clienteId = (int)($row['cliente_id'] ?? 0);
            $totalVentas = (int)($row['total_ventas'] ?? 0);

            if (isset($mapClientes[$clienteId])) {
                $idx = $mapClientes[$clienteId];
                $clientes[$idx]['ventas_count'] = $totalVentas;
                if ($totalVentas >= 3) {
                    $clientes[$idx]['es_frecuente'] = true;
                }
            }
        }
    }
}

foreach ($clientes as $cli) {
    $totalClientes++;
    if ($cli['tipo_cliente'] === 'DTF') {
        $totalDTF++;
    } else {
        $totalPersonalizado++;
    }
    if (!empty($cli['es_frecuente'])) {
        $totalFrecuentes++;
    }
    if (!empty($cli['es_exclusivo'])) {
        $totalExclusivos++;
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Promociones WhatsApp - Suave Urban Studio</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --gold: #c89b3c;
            --gold-glow: rgba(200, 155, 60, 0.4);
            --glass-border: rgba(200, 155, 60, 0.15);
            --text-muted: #9a9a9a;
        }

        * { box-sizing: border-box; }

        html, body {
            margin: 0;
            padding: 0;
            min-height: 100%;
        }

        body {
            background:
                <?php echo !empty($fondoContenido)
                    ? "linear-gradient(rgba(0,0,0,0.45), rgba(0,0,0,0.60)), url('" . htmlspecialchars($fondoContenido, ENT_QUOTES, 'UTF-8') . "') center/cover fixed no-repeat"
                    : "radial-gradient(circle at top right, rgba(200,155,60,0.10), transparent 25%), #050505"; ?>;
            color: white;
            font-family: 'Segoe UI', sans-serif;
            display: flex;
            min-height: 100vh;
            overflow-x: hidden;
            position: relative;
        }

        body::before {
            content: "";
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.28);
            z-index: -1;
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
            z-index:-1;
        }

        .mobile-topbar { display: none; }
        .mobile-menu-toggle { display: none; }
        .sidebar-overlay { display: none; }

        .sidebar {
            width: 85px;
            background:
                <?php echo !empty($fondoSidebar)
                    ? "linear-gradient(rgba(0,0,0," . $transparenciaSidebar . "), rgba(0,0,0," . $transparenciaSidebar . ")), url('" . htmlspecialchars($fondoSidebar, ENT_QUOTES, 'UTF-8') . "') center/cover no-repeat"
                    : "rgba(0, 0, 0, " . $transparenciaSidebar . ")"; ?>;
            backdrop-filter: blur(15px);
            -webkit-backdrop-filter: blur(15px);
            border-right: 1px solid var(--glass-border);
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 15px 0;
            z-index: 10;
            position: fixed;
            top: 0;
            left: 0;
            height: 100vh;
            overflow-y: auto;
            box-shadow: 0 10px 40px rgba(0,0,0,0.35);
        }

        .logo-pos {
            width: 55px;
            height: auto;
            margin-bottom: 20px;
            filter: drop-shadow(0 0 8px rgba(200,155,60,0.5));
            animation: logoPulse 4s infinite, glow 3s infinite alternate;
        }

        @keyframes logoPulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.05); }
        }

        @keyframes glow {
            from { filter: drop-shadow(0 0 5px rgba(200,155,60,0.4)); }
            to { filter: drop-shadow(0 0 15px rgba(200,155,60,0.7)); }
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(14px); }
            to { opacity: 1; transform: translateY(0); }
        }

        @keyframes particlesMove{
            0%{transform:translateY(0);}
            100%{transform:translateY(-40px);}
        }

        @keyframes cardshine{
            0%{left:-60%;}
            15%,100%{left:120%;}
        }

        @keyframes btnshine{
            0%{left:-60%;}
            15%,100%{left:120%;}
        }

        .nav-controls {
            display: flex;
            flex-direction: column;
            gap: 18px;
            margin-bottom: 30px;
            border-bottom: 1px solid var(--glass-border);
            padding-bottom: 20px;
            width: 100%;
            align-items: center;
        }

        .sidebar a {
            color: #555;
            font-size: 22px;
            transition: 0.3s;
            text-decoration: none;
        }

        .sidebar a:hover,
        .sidebar a.active {
            color: var(--gold);
            filter: drop-shadow(0 0 8px var(--gold));
        }

        .exit-btn:hover {
            color: #ff4d4d !important;
            filter: drop-shadow(0 0 8px #ff4d4d) !important;
        }

        .main-content {
            margin-left: 85px;
            flex: 1;
            padding: 40px;
            overflow-y: auto;
            animation: fadeIn .5s ease-out;
            min-width: 0;
            position: relative;
            z-index: 2;
        }

        .glass-card {
            background: rgba(15, 15, 15, <?php echo $transparenciaPanel; ?>);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border: 1px solid var(--glass-border);
            border-radius: 20px;
            padding: 24px;
            margin-bottom: 24px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.5), 0 0 20px rgba(200,155,60,0.08);
            position: relative;
            overflow: hidden;
            transition: all .35s ease;
        }

        .glass-card::before{
            content:"";
            position:absolute;
            inset:0;
            background:linear-gradient(135deg, rgba(255,255,255,0.04), transparent 35%, transparent 70%, rgba(200,155,60,0.04));
            pointer-events:none;
        }

        .glass-card::after{
            content:"";
            position:absolute;
            top:-50%;
            left:-60%;
            width:20%;
            height:200%;
            background:rgba(255,255,255,.06);
            transform:rotate(30deg);
            animation: cardshine 6s infinite;
            pointer-events:none;
        }

        .glass-card:hover{
            transform: translateY(-4px);
            box-shadow: 0 10px 25px rgba(0,0,0,.6), 0 0 18px rgba(200,155,60,.35);
        }

        .title-row {
            display:flex;
            justify-content:space-between;
            align-items:center;
            gap:12px;
            flex-wrap:wrap;
            margin-bottom:22px;
            position:relative;
            z-index:1;
        }

        .title-row h1, .title-row h2, .title-row h3 {
            margin:0;
        }

        .title-main {
            font-weight:200;
            letter-spacing:1px;
            font-size: 34px;
        }

        .title-main span {
            color: var(--gold);
            font-weight: 800;
        }

        .mini-badge {
            background: rgba(37,211,102,.12);
            color: #9ef0bc;
            border:1px solid rgba(37,211,102,.18);
            border-radius:999px;
            padding:8px 12px;
            font-size:12px;
            font-weight:700;
        }

        .stats-grid {
            display:grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap:14px;
            margin-bottom: 24px;
            position:relative;
            z-index:1;
        }

        .stat-box {
            background: rgba(255,255,255,.04);
            border: 1px solid rgba(255,255,255,.06);
            border-radius: 16px;
            padding: 18px;
            transition: all .3s ease;
        }

        .stat-box:hover{
            transform: translateY(-3px);
            border-color: rgba(200,155,60,.25);
            box-shadow: 0 8px 20px rgba(0,0,0,.28), 0 0 14px rgba(200,155,60,.16);
        }

        .stat-box .label {
            color: var(--gold);
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 8px;
        }

        .stat-box .value {
            font-size: 30px;
            font-weight: 800;
        }

        .mode-grid {
            display:grid;
            grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
            gap:12px;
            position:relative;
            z-index:1;
        }

        .mode-btn {
            border:1px solid rgba(255,255,255,.08);
            background: rgba(255,255,255,.04);
            color:#fff;
            padding:14px 16px;
            border-radius:16px;
            cursor:pointer;
            transition:.25s;
            font-weight:700;
            text-align:left;
        }

        .mode-btn:hover {
            border-color: var(--gold);
            transform: translateY(-2px);
            box-shadow: 0 8px 18px rgba(0,0,0,.24), 0 0 14px rgba(200,155,60,.18);
        }

        .mode-btn.active {
            border-color: var(--gold);
            color: var(--gold);
            background: rgba(200,155,60,.10);
            box-shadow: 0 0 18px rgba(200,155,60,.10);
        }

        .field-label {
            display:block;
            font-size:11px;
            text-transform:uppercase;
            color:#b4b4b4;
            margin-bottom:8px;
            letter-spacing:.8px;
            position:relative;
            z-index:1;
        }

        .custom-input, .custom-textarea {
            width:100%;
            background: linear-gradient(180deg, rgba(255,255,255,0.10), rgba(255,255,255,0.04));
            border:1px solid rgba(255,255,255,.10);
            color:#fff;
            border-radius:12px;
            padding:12px 14px;
            outline:none;
            transition:.28s;
            position:relative;
            z-index:1;
        }

        .custom-input:focus, .custom-textarea:focus {
            border-color: var(--gold);
            box-shadow: 0 0 0 2px rgba(200,155,60,.12), 0 0 14px rgba(200,155,60,.14);
            background: rgba(255,255,255,.08);
        }

        .custom-textarea {
            min-height: 150px;
            resize: vertical;
            font-family: inherit;
        }

        .toolbar {
            display:flex;
            gap:10px;
            flex-wrap:wrap;
            margin-top:14px;
            position:relative;
            z-index:1;
        }

        .btn-mini {
            border:1px solid var(--glass-border);
            background: rgba(255,255,255,.05);
            color:#fff;
            padding:10px 14px;
            border-radius:10px;
            cursor:pointer;
            font-weight:700;
            transition:.25s;
            position: relative;
            overflow: hidden;
        }

        .btn-mini:hover {
            border-color: var(--gold);
            color: var(--gold);
            transform: translateY(-2px);
        }

        .btn-gold {
            background: linear-gradient(45deg, #c89b3c, #eec064);
            color:#000;
            border:none;
            padding:14px 18px;
            border-radius:12px;
            cursor:pointer;
            font-weight:800;
            text-transform:uppercase;
            letter-spacing:.8px;
            transition:.3s;
            position: relative;
            overflow: hidden;
        }

        .btn-gold::after,
        .btn-mini::after{
            content:"";
            position:absolute;
            top:-50%;
            left:-60%;
            width:20%;
            height:200%;
            background:rgba(255,255,255,.35);
            transform:rotate(30deg);
            animation: btnshine 4s infinite;
        }

        .btn-gold:hover {
            transform: scale(1.02) translateY(-2px);
            box-shadow: 0 8px 20px var(--gold-glow);
        }

        .clientes-topbar {
            display:flex;
            justify-content:space-between;
            align-items:center;
            gap:12px;
            flex-wrap:wrap;
            margin-bottom:14px;
            position:relative;
            z-index:1;
        }

        .clientes-actions {
            display:flex;
            gap:10px;
            flex-wrap:wrap;
        }

        .clientes-list {
            display:grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap:12px;
            max-height: 420px;
            overflow-y:auto;
            padding-right:4px;
            position:relative;
            z-index:1;
        }

        .cliente-item {
            background: rgba(255,255,255,.04);
            border:1px solid rgba(255,255,255,.06);
            border-radius:16px;
            padding:14px;
            transition:.25s;
        }

        .cliente-item.hidden {
            display:none;
        }

        .cliente-item:hover {
            border-color: rgba(200,155,60,.20);
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(0,0,0,.22), 0 0 12px rgba(200,155,60,.14);
        }

        .cliente-check-row {
            display:flex;
            gap:12px;
            align-items:flex-start;
        }

        .cliente-check-row input[type="checkbox"] {
            margin-top:4px;
            transform:scale(1.15);
            accent-color: var(--gold);
        }

        .cliente-name {
            font-weight:800;
            margin-bottom:4px;
        }

        .cliente-meta {
            color: var(--text-muted);
            font-size:13px;
            line-height:1.55;
        }

        .pill {
            display:inline-flex;
            align-items:center;
            justify-content:center;
            padding:6px 10px;
            border-radius:999px;
            font-size:11px;
            font-weight:800;
            text-transform:uppercase;
            letter-spacing:.5px;
            margin-top:8px;
            margin-right:6px;
        }

        .pill-pers {
            background: rgba(200,155,60,.18);
            color: #f7d58b;
            border:1px solid rgba(200,155,60,.25);
        }

        .pill-dtf {
            background: rgba(59,130,246,.18);
            color: #bfdbfe;
            border:1px solid rgba(59,130,246,.25);
        }

        .pill-frec {
            background: rgba(37,211,102,.14);
            color: #a7f3d0;
            border:1px solid rgba(37,211,102,.20);
        }

        .pill-exc {
            background: rgba(168,85,247,.16);
            color: #e9d5ff;
            border:1px solid rgba(168,85,247,.25);
        }

        .helper-box {
            margin-top:14px;
            padding:12px 14px;
            border-radius:12px;
            background: rgba(200,155,60,.06);
            border:1px dashed rgba(200,155,60,.25);
            color:#ddd;
            font-size:13px;
            line-height:1.55;
            position:relative;
            z-index:1;
        }

        .resume-bar {
            margin-top:16px;
            display:flex;
            justify-content:space-between;
            align-items:center;
            gap:10px;
            flex-wrap:wrap;
            position:relative;
            z-index:1;
        }

        .resume-pill {
            background: rgba(255,255,255,.05);
            border:1px solid rgba(255,255,255,.08);
            padding:10px 12px;
            border-radius:999px;
            font-size:13px;
            font-weight:700;
        }

        .preview-text {
            white-space: pre-wrap;
            line-height: 1.6;
            font-size: 14px;
            color: #f3f3f3;
            position:relative;
            z-index:1;
        }

        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-thumb { background: var(--glass-border); border-radius: 10px; }
        ::-webkit-scrollbar-thumb:hover { background: var(--gold); }

        @media (max-width: 980px) {
            body { display:block; }

            .mobile-topbar {
                display:flex;
                align-items:center;
                justify-content:space-between;
                gap:12px;
                position:sticky;
                top:0;
                z-index:1100;
                padding:14px 16px;
                background: rgba(0,0,0,.9);
                border-bottom:1px solid var(--glass-border);
                backdrop-filter: blur(10px);
                -webkit-backdrop-filter: blur(10px);
            }

            .mobile-topbar-left {
                display:flex;
                align-items:center;
                gap:10px;
                min-width:0;
            }

            .mobile-topbar-logo {
                width:38px;
                height:38px;
                object-fit:contain;
                animation: logoPulse 4s infinite, glow 3s infinite alternate;
            }

            .mobile-topbar-title {
                font-size:14px;
                font-weight:700;
                color:#fff;
                white-space:nowrap;
                overflow:hidden;
                text-overflow:ellipsis;
            }

            .mobile-menu-toggle {
                display:inline-flex;
                align-items:center;
                justify-content:center;
                width:42px;
                height:42px;
                border:1px solid rgba(255,255,255,.08);
                background: rgba(255,255,255,.06);
                color: var(--gold);
                border-radius:12px;
                font-size:18px;
                cursor:pointer;
            }

            .sidebar-overlay {
                display:block;
                position:fixed;
                inset:0;
                background: rgba(0,0,0,.45);
                opacity:0;
                visibility:hidden;
                transition:.3s ease;
                z-index:999;
            }

            .sidebar {
                width:280px;
                max-width:82vw;
                transform:translateX(-100%);
                transition:transform .3s ease;
            }

            body.menu-open .sidebar { transform:translateX(0); }
            body.menu-open .sidebar-overlay {
                opacity:1;
                visibility:visible;
            }

            .main-content {
                margin-left:0;
                padding:20px 16px;
            }

            .title-main {
                font-size: 28px;
            }
        }

        @media (max-width: 700px) {
            .main-content { padding:16px 12px; }
            .glass-card { padding:16px; border-radius:16px; }
            .clientes-list { grid-template-columns: 1fr; }
            .title-main { font-size:24px; }
        }
    </style>
</head>
<body>

    <div class="mobile-topbar">
        <div class="mobile-topbar-left">
            <img src="<?php echo htmlspecialchars($logoActual, ENT_QUOTES, 'UTF-8'); ?>" alt="Logo" class="mobile-topbar-logo">
            <div class="mobile-topbar-title">Promociones WhatsApp</div>
        </div>
        <button class="mobile-menu-toggle" id="mobileMenuToggle" aria-label="Abrir menú">
            <i class="fas fa-bars"></i>
        </button>
    </div>

    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <div class="sidebar" id="sidebar">
        <img src="<?php echo htmlspecialchars($logoActual, ENT_QUOTES, 'UTF-8'); ?>" alt="Logo" class="logo-pos">

        <div class="nav-controls">
            <a href="dashboard.php" title="Volver al Dashboard"><i class="fas fa-home"></i></a>
            <a href="logout.php" class="exit-btn" title="Salir del Sistema"><i class="fas fa-power-off"></i></a>
        </div>

        <a href="ventas.php" title="Caja POS"><i class="fas fa-cash-register"></i></a>
        <a href="clientes.php" title="Clientes"><i class="fas fa-users"></i></a>
        <a href="pedidos.php" title="Pedidos"><i class="fas fa-list-check"></i></a>
        <a href="promociones_whatsapp.php" class="active" title="Promociones WhatsApp"><i class="fab fa-whatsapp"></i></a>
        <a href="configuracion.php" title="Configuración"><i class="fas fa-cog"></i></a>
    </div>

    <div class="main-content">
        <div class="title-row">
            <h1 class="title-main">Promociones <span>WHATSAPP</span></h1>
            <div class="mini-badge"><i class="fas fa-link"></i> Marketing & Promociones</div>
        </div>

        <div class="stats-grid">
            <div class="stat-box">
                <div class="label">Clientes con WhatsApp</div>
                <div class="value"><?php echo (int)$totalClientes; ?></div>
            </div>
            <div class="stat-box">
                <div class="label">Personalizado</div>
                <div class="value"><?php echo (int)$totalPersonalizado; ?></div>
            </div>
            <div class="stat-box">
                <div class="label">DTF</div>
                <div class="value"><?php echo (int)$totalDTF; ?></div>
            </div>
            <div class="stat-box">
                <div class="label">Frecuentes</div>
                <div class="value"><?php echo (int)$totalFrecuentes; ?></div>
            </div>
            <div class="stat-box">
                <div class="label">Exclusivos</div>
                <div class="value"><?php echo (int)$totalExclusivos; ?></div>
            </div>
        </div>

        <div class="glass-card">
            <div class="title-row">
                <h3 style="color:var(--gold); font-weight:600; font-size:15px;"><i class="fas fa-bullhorn"></i> Modo de envío</h3>
            </div>

            <div class="mode-grid">
                <button type="button" class="mode-btn active" data-mode="individual" onclick="cambiarModo('individual')">
                    <i class="fas fa-user-check"></i> Selección individual
                </button>

                <button type="button" class="mode-btn" data-mode="masivo_personalizado" onclick="cambiarModo('masivo_personalizado')">
                    <i class="fas fa-shirt"></i> Masivo Personalizado
                </button>

                <button type="button" class="mode-btn" data-mode="masivo_dtf" onclick="cambiarModo('masivo_dtf')">
                    <i class="fas fa-fill-drip"></i> Masivo DTF
                </button>

                <button type="button" class="mode-btn" data-mode="frecuentes" onclick="cambiarModo('frecuentes')">
                    <i class="fas fa-star"></i> Clientes frecuentes
                </button>

                <button type="button" class="mode-btn" data-mode="exclusivos" onclick="cambiarModo('exclusivos')">
                    <i class="fas fa-crown"></i> Clientes exclusivos
                </button>
            </div>

            <div class="helper-box">
                - <strong>Selección individual:</strong> eliges clientes por nombre.<br>
                - <strong>Masivo Personalizado:</strong> manda solo a clientes de personalizados.<br>
                - <strong>Masivo DTF:</strong> manda solo a clientes DTF.<br>
                - <strong>Frecuentes:</strong> toma clientes con 3 o más ventas.<br>
                - <strong>Exclusivos:</strong> toma clientes marcados como exclusivos si existe esa columna en tu base.
            </div>
        </div>

        <div class="glass-card">
            <div class="title-row">
                <h3 style="color:var(--gold); font-weight:600; font-size:15px;"><i class="fas fa-pen"></i> Mensaje de promoción</h3>
            </div>

            <label class="field-label">Escribe tu oferta o promoción</label>
            <textarea id="mensajePromocion" class="custom-textarea">🔥 PROMOCIÓN SUAVE URBAN 🔥

Tenemos promociones disponibles esta semana.

Pregunta por:
• Playeras personalizadas
• Tazas
• DTF por metro

📍 Suave Urban Studio</textarea>

            <div class="toolbar">
                <button type="button" class="btn-mini" onclick="insertarPlantilla('personalizado')">
                    Plantilla Personalizado
                </button>
                <button type="button" class="btn-mini" onclick="insertarPlantilla('dtf')">
                    Plantilla DTF
                </button>
                <button type="button" class="btn-mini" onclick="insertarPlantilla('general')">
                    Plantilla General
                </button>
            </div>
        </div>

        <div class="glass-card" id="panelClientes">
            <div class="clientes-topbar">
                <h3 style="margin:0; color:var(--gold); font-weight:600; font-size:15px;"><i class="fas fa-users"></i> Selección de clientes</h3>

                <div class="clientes-actions">
                    <input type="text" id="buscadorClientes" class="custom-input" placeholder="Buscar cliente por nombre o teléfono..." onkeyup="filtrarClientes()" style="min-width:260px;">
                    <button type="button" class="btn-mini" onclick="seleccionarVisibles(true)">Seleccionar visibles</button>
                    <button type="button" class="btn-mini" onclick="seleccionarVisibles(false)">Limpiar visibles</button>
                </div>
            </div>

            <div class="clientes-list" id="listaClientes">
                <?php if (!empty($clientes)): ?>
                    <?php foreach ($clientes as $cli): ?>
                        <?php $tipoClase = $cli['tipo_cliente'] === 'DTF' ? 'pill-dtf' : 'pill-pers'; ?>
                        <div class="cliente-item"
                             data-id="<?php echo (int)$cli['id']; ?>"
                             data-nombre="<?php echo htmlspecialchars(function_exists('mb_strtolower') ? mb_strtolower($cli['nombre'], 'UTF-8') : strtolower($cli['nombre']), ENT_QUOTES, 'UTF-8'); ?>"
                             data-telefono="<?php echo htmlspecialchars($cli['telefono'], ENT_QUOTES, 'UTF-8'); ?>"
                             data-tipo="<?php echo htmlspecialchars($cli['tipo_cliente'], ENT_QUOTES, 'UTF-8'); ?>"
                             data-frecuente="<?php echo !empty($cli['es_frecuente']) ? '1' : '0'; ?>"
                             data-exclusivo="<?php echo !empty($cli['es_exclusivo']) ? '1' : '0'; ?>">
                            <div class="cliente-check-row">
                                <input type="checkbox" class="cliente-check" value="<?php echo (int)$cli['id']; ?>">
                                <div>
                                    <div class="cliente-name"><?php echo htmlspecialchars($cli['nombre'], ENT_QUOTES, 'UTF-8'); ?></div>
                                    <div class="cliente-meta">
                                        WhatsApp: <?php echo htmlspecialchars($cli['telefono'], ENT_QUOTES, 'UTF-8'); ?><br>
                                        <?php if (!empty($cli['email'])): ?>
                                            Correo: <?php echo htmlspecialchars($cli['email'], ENT_QUOTES, 'UTF-8'); ?><br>
                                        <?php endif; ?>
                                        Ventas registradas: <?php echo (int)$cli['ventas_count']; ?>
                                    </div>

                                    <span class="pill <?php echo $tipoClase; ?>"><?php echo htmlspecialchars($cli['tipo_cliente'], ENT_QUOTES, 'UTF-8'); ?></span>

                                    <?php if (!empty($cli['es_frecuente'])): ?>
                                        <span class="pill pill-frec">Frecuente</span>
                                    <?php endif; ?>

                                    <?php if (!empty($cli['es_exclusivo'])): ?>
                                        <span class="pill pill-exc">Exclusivo</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="helper-box">No hay clientes con teléfono registrado.</div>
                <?php endif; ?>
            </div>

            <div class="resume-bar">
                <div class="resume-pill">Modo actual: <span id="modoActualTexto">Selección individual</span></div>
                <div class="resume-pill">Clientes seleccionados: <span id="contadorSeleccionados">0</span></div>
            </div>
        </div>

        <div class="glass-card">
            <div class="title-row">
                <h3 style="color:var(--gold); font-weight:600; font-size:15px;"><i class="fas fa-eye"></i> Vista previa</h3>
            </div>

            <div class="preview-text" id="vistaPreviaPromo"></div>

            <div class="toolbar" style="margin-top:18px;">
                <button type="button" class="btn-gold" onclick="enviarPromocionReal()">
                    <i class="fab fa-whatsapp"></i> Enviar promoción
                </button>
            </div>

            <div class="helper-box">
                Marketing & Promociones.
            </div>
        </div>
    </div>

    <script>
        const body = document.body;
        const mobileMenuToggle = document.getElementById('mobileMenuToggle');
        const sidebarOverlay = document.getElementById('sidebarOverlay');

        function mostrarToast(texto, ok = true) {
    let toast = document.getElementById('toastPromo');

    if (!toast) {
        toast = document.createElement('div');
        toast.id = 'toastPromo';
        toast.style.position = 'fixed';
        toast.style.right = '20px';
        toast.style.bottom = '20px';
        toast.style.zIndex = '99999';
        toast.style.padding = '14px 18px';
        toast.style.borderRadius = '14px';
        toast.style.fontWeight = '700';
        toast.style.boxShadow = '0 12px 30px rgba(0,0,0,.35)';
        toast.style.backdropFilter = 'blur(10px)';
        toast.style.transition = 'all .3s ease';
        toast.style.opacity = '0';
        toast.style.transform = 'translateY(20px)';
        document.body.appendChild(toast);
    }

    toast.innerText = texto;
    toast.style.background = ok ? 'rgba(34,197,94,.18)' : 'rgba(239,68,68,.18)';
    toast.style.border = ok ? '1px solid rgba(34,197,94,.35)' : '1px solid rgba(239,68,68,.35)';
    toast.style.color = ok ? '#bbf7d0' : '#fecaca';
    toast.style.opacity = '1';
    toast.style.transform = 'translateY(0)';

    setTimeout(() => {
        toast.style.opacity = '0';
        toast.style.transform = 'translateY(20px)';
    }, 1800);
}
let modoActual = 'individual';

        function abrirMenu() { body.classList.add('menu-open'); }
        function cerrarMenu() { body.classList.remove('menu-open'); }

        if (mobileMenuToggle) {
            mobileMenuToggle.addEventListener('click', function () {
                body.classList.contains('menu-open') ? cerrarMenu() : abrirMenu();
            });
        }

        if (sidebarOverlay) {
            sidebarOverlay.addEventListener('click', cerrarMenu);
        }

        document.querySelectorAll('.sidebar a').forEach(link => {
            link.addEventListener('click', function () {
                if (window.innerWidth <= 980) cerrarMenu();
            });
        });

        window.addEventListener('resize', function () {
            if (window.innerWidth > 980) cerrarMenu();
        });

        function obtenerTextoModo(modo) {
            switch (modo) {
                case 'individual': return 'Selección individual';
                case 'masivo_personalizado': return 'Masivo Personalizado';
                case 'masivo_dtf': return 'Masivo DTF';
                case 'frecuentes': return 'Clientes frecuentes';
                case 'exclusivos': return 'Clientes exclusivos';
                default: return 'Selección individual';
            }
        }

        function cambiarModo(modo) {
            modoActual = modo;

            document.querySelectorAll('.mode-btn').forEach(btn => btn.classList.remove('active'));
            const activo = document.querySelector(`.mode-btn[data-mode="${modo}"]`);
            if (activo) activo.classList.add('active');

            document.getElementById('modoActualTexto').innerText = obtenerTextoModo(modo);

            aplicarFiltroModo();
            actualizarContador();
            actualizarVistaPrevia();
        }

        function aplicarFiltroModo() {
            const items = document.querySelectorAll('.cliente-item');
            const buscador = document.getElementById('buscadorClientes').value.toLowerCase().trim();

            items.forEach(item => {
                const nombre = item.dataset.nombre || '';
                const telefono = item.dataset.telefono || '';
                const tipo = item.dataset.tipo || '';
                const frecuente = item.dataset.frecuente === '1';
                const exclusivo = item.dataset.exclusivo === '1';

                let visiblePorModo = true;

                if (modoActual === 'masivo_personalizado') {
                    visiblePorModo = (tipo === 'Personalizado');
                } else if (modoActual === 'masivo_dtf') {
                    visiblePorModo = (tipo === 'DTF');
                } else if (modoActual === 'frecuentes') {
                    visiblePorModo = frecuente;
                } else if (modoActual === 'exclusivos') {
                    visiblePorModo = exclusivo;
                }

                const visiblePorTexto = buscador === '' || nombre.includes(buscador) || telefono.includes(buscador);

                item.classList.toggle('hidden', !(visiblePorModo && visiblePorTexto));
            });

            if (modoActual !== 'individual') {
                autoSeleccionarModo();
            }
        }

        function autoSeleccionarModo() {
            const items = document.querySelectorAll('.cliente-item');
            items.forEach(item => {
                const checkbox = item.querySelector('.cliente-check');
                checkbox.checked = !item.classList.contains('hidden');
            });
        }

        function filtrarClientes() {
            aplicarFiltroModo();
            actualizarContador();
        }

        function seleccionarVisibles(valor) {
            document.querySelectorAll('.cliente-item').forEach(item => {
                if (!item.classList.contains('hidden')) {
                    const checkbox = item.querySelector('.cliente-check');
                    checkbox.checked = valor;
                }
            });
            actualizarContador();
        }

        function actualizarContador() {
            const total = document.querySelectorAll('.cliente-check:checked').length;
            document.getElementById('contadorSeleccionados').innerText = total;
        }

        function insertarPlantilla(tipo) {
            const textarea = document.getElementById('mensajePromocion');

            if (tipo === 'personalizado') {
                textarea.value = `🔥 PROMOCIÓN PERSONALIZADOS 🔥

Esta semana tenemos promociones en:
• Playeras personalizadas
• Tazas
• Sudaderas
• Bordado

Pregunta por precio especial en mayoreo.

📍 Suave Urban Studio`;
            } else if (tipo === 'dtf') {
                textarea.value = `🔥 PROMOCIÓN DTF 🔥

Tenemos precio especial en DTF por metro.

Ideal para tus pedidos y producción.
Pregunta por mayoreo, urgencias y disponibilidad.

📍 Suave Urban Studio`;
            } else {
                textarea.value = `🔥 PROMOCIÓN SUAVE URBAN 🔥

Tenemos promociones disponibles esta semana.

Pregunta por:
• Playeras personalizadas
• Tazas
• DTF por metro

📍 Suave Urban Studio`;
            }

            actualizarVistaPrevia();
        }

        function actualizarVistaPrevia() {
            const mensaje = document.getElementById('mensajePromocion').value.trim();
            const seleccionados = document.querySelectorAll('.cliente-check:checked').length;
            const modo = obtenerTextoModo(modoActual);

            const preview = `Modo: ${modo}

Clientes seleccionados: ${seleccionados}

Mensaje:

${mensaje}`;
            document.getElementById('vistaPreviaPromo').innerText = preview;
        }

        function obtenerClientesSeleccionados() {
            const seleccionados = [];

            document.querySelectorAll('.cliente-item').forEach(item => {
                const checkbox = item.querySelector('.cliente-check');
                if (checkbox && checkbox.checked) {
                    seleccionados.push({
                        id: item.dataset.id,
                        nombre: item.querySelector('.cliente-name') ? item.querySelector('.cliente-name').innerText.trim() : 'Cliente',
                        telefono: item.dataset.telefono || ''
                    });
                }
            });

            return seleccionados;
        }

        async function enviarPromocionReal() {
    const clientes = obtenerClientesSeleccionados();
    const mensaje = document.getElementById('mensajePromocion').value.trim();

    if (mensaje === '') {
        mostrarToast('Escribe el mensaje de promoción.', false);
        return;
    }

    if (clientes.length === 0) {
        mostrarToast('Selecciona al menos un cliente.', false);
        return;
    }

    try {
        const res = await fetch('enviar_promocion.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                mensaje,
                clientes
            })
        });

        const data = await res.json();

        if (data.ok) {
            mostrarToast('Promoción enviada', true);
        } else {
            console.log(data);
            mostrarToast('No se pudo enviar la promoción', false);
        }
    } catch (error) {
        console.error(error);
        mostrarToast('Error al conectar con el envío', false);
    }
}

        document.querySelectorAll('.cliente-check').forEach(chk => {
            chk.addEventListener('change', function() {
                actualizarContador();
                actualizarVistaPrevia();
            });
        });

        document.getElementById('mensajePromocion').addEventListener('input', actualizarVistaPrevia);

        cambiarModo('individual');
        actualizarContador();
        actualizarVistaPrevia();
    </script>
</body>
</html>
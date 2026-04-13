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

$fondoSidebar = '';
$fondoContenido = '';
$logoActual = 'logo.png';
$transparenciaPanel = 0.32;
$transparenciaSidebar = 0.88;

$configColumns = obtenerColumnasTabla($conn, 'configuracion');
if (!empty($configColumns)) {
    $selectConfig = [];

    foreach (['logo', 'fondo_sidebar', 'fondo_contenido', 'transparencia_panel', 'transparencia_sidebar'] as $col) {
        if (tieneColumna($configColumns, $col)) {
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

$productosLista = [];
$productosColumns = obtenerColumnasTabla($conn, 'productos');

if (!empty($productosColumns)) {
    $idCol = tieneColumna($productosColumns, 'id') ? 'id' : null;
    $nombreCol = tieneColumna($productosColumns, 'nombre') ? 'nombre' : (tieneColumna($productosColumns, 'producto') ? 'producto' : null);
    $precioCol = tieneColumna($productosColumns, 'precio') ? 'precio' : (tieneColumna($productosColumns, 'precio_venta') ? 'precio_venta' : null);
    $stockCol = tieneColumna($productosColumns, 'stock') ? 'stock' : (tieneColumna($productosColumns, 'existencia') ? 'existencia' : null);

    if ($idCol && $nombreCol && $precioCol) {
        $sqlProductos = "SELECT `$idCol` AS id, `$nombreCol` AS nombre, `$precioCol` AS precio" . ($stockCol ? ", `$stockCol` AS stock" : "") . " FROM productos";
        if ($stockCol) {
            $sqlProductos .= " WHERE `$stockCol` > 0";
        }
        $sqlProductos .= " ORDER BY `$nombreCol` ASC";

        $resProductos = $conn->query($sqlProductos);
        if ($resProductos) {
            while ($prod = $resProductos->fetch_assoc()) {
                $productosLista[] = [
                    'id' => (int)($prod['id'] ?? 0),
                    'nombre' => (string)($prod['nombre'] ?? 'Producto'),
                    'precio' => (float)($prod['precio'] ?? 0)
                ];
            }
        }
    }
}

$clienteIdAuto = isset($_GET['cliente_id']) ? (int)$_GET['cliente_id'] : 0;
$clienteNombreAuto = isset($_GET['cliente']) ? trim($_GET['cliente']) : '';
$clienteTelefonoAuto = isset($_GET['tel']) ? trim($_GET['tel']) : '';
$clienteDireccionAuto = isset($_GET['direccion']) ? trim($_GET['direccion']) : '';
$clienteEmailAuto = isset($_GET['email']) ? trim($_GET['email']) : '';
$clienteTipoAuto = isset($_GET['tipo_cliente']) ? trim($_GET['tipo_cliente']) : 'Personalizado';
$clienteNuevoAuto = isset($_GET['cliente_nuevo']) ? (int)$_GET['cliente_nuevo'] : 0;

if (!in_array($clienteTipoAuto, ['Personalizado', 'DTF'], true)) {
    $clienteTipoAuto = 'Personalizado';
}

$clientesRegistrados = [];
$clientesRegistradosMap = [];

if (existeTabla($conn, 'clientes')) {
    $clienteCols = obtenerColumnasTabla($conn, 'clientes');
    $selectClientes = [];

    foreach (['id', 'nombre', 'telefono', 'direccion', 'email', 'tipo_cliente'] as $col) {
        if (tieneColumna($clienteCols, $col)) {
            $selectClientes[] = $col;
        }
    }

    if (!empty($selectClientes)) {
        $resClientesRegistrados = $conn->query("
            SELECT " . implode(', ', $selectClientes) . "
            FROM clientes
            ORDER BY nombre ASC
        ");

        if ($resClientesRegistrados) {
            while ($cli = $resClientesRegistrados->fetch_assoc()) {
                $tipoClienteFila = $cli['tipo_cliente'] ?? 'Personalizado';
                if (!in_array($tipoClienteFila, ['Personalizado', 'DTF'], true)) {
                    $tipoClienteFila = 'Personalizado';
                }

                $clientesRegistrados[] = $cli;
                $clientesRegistradosMap[(int)$cli['id']] = [
                    'id' => (int)($cli['id'] ?? 0),
                    'nombre' => $cli['nombre'] ?? '',
                    'telefono' => $cli['telefono'] ?? '',
                    'direccion' => $cli['direccion'] ?? '',
                    'email' => $cli['email'] ?? '',
                    'tipo_cliente' => $tipoClienteFila
                ];
            }
        }
    }
}

$alphaPanel = max(0.10, min(0.95, $transparenciaPanel));
$alphaSidebar = max(0.10, min(0.98, $transparenciaSidebar));
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Terminal POS - Suave Urban Studio</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
    * { box-sizing: border-box; }

    select, option, optgroup {
        background: #111 !important;
        color: #fff !important;
    }

    select:focus {
        outline: none;
        border-color: var(--gold) !important;
        box-shadow: 0 0 0 1px rgba(200,155,60,0.35);
    }

    option { padding: 10px; }

    :root {
        --gold: #c89b3c;
        --bg: #050505;
        --card: #111;
        --border: rgba(200,155,60,0.2);
        --shadow-gold: 0 0 20px rgba(200,155,60,0.16);
    }

    @keyframes pulseGold {
        0%,100% { box-shadow: 0 0 0 rgba(200,155,60,0); }
        50% { box-shadow: 0 0 18px rgba(200,155,60,0.22); }
    }

    @keyframes fadeUp {
        from { opacity: 0; transform: translateY(12px); }
        to { opacity: 1; transform: translateY(0); }
    }

    @keyframes shimmerBorder {
        0% { border-color: rgba(200,155,60,0.15); }
        50% { border-color: rgba(200,155,60,0.35); }
        100% { border-color: rgba(200,155,60,0.15); }
    }

    @keyframes logoPulse {
        0%, 100% { transform: scale(1); }
        50% { transform: scale(1.08); }
    }

    @keyframes glow {
        from { filter: drop-shadow(0 0 5px rgba(200,155,60,0.4)); }
        to { filter: drop-shadow(0 0 15px rgba(200,155,60,0.7)); }
    }

    @keyframes previewSweep {
        0% { transform: translateX(-100%); }
        100% { transform: translateX(100%); }
    }

    html, body {
        margin: 0;
        padding: 0;
        min-height: 100%;
    }

    body {
        background:
            <?php echo !empty($fondoContenido)
                ? "linear-gradient(rgba(0,0,0,0.45), rgba(0,0,0,0.60)), url('" . htmlspecialchars($fondoContenido, ENT_QUOTES, 'UTF-8') . "') center/cover fixed no-repeat"
                : "var(--bg)"; ?>;
        color: white;
        font-family: 'Segoe UI', sans-serif;
        display: flex;
        min-height: 100vh;
        overflow-x: hidden;
    }

    .mobile-topbar { display: none; }
    .mobile-menu-toggle { display: none; }
    .sidebar-overlay { display: none; }

    .sidebar {
        width: 85px;
        background:
            <?php echo !empty($fondoSidebar)
                ? "linear-gradient(rgba(0,0,0," . $alphaSidebar . "), rgba(0,0,0," . $alphaSidebar . ")), url('" . htmlspecialchars($fondoSidebar, ENT_QUOTES, 'UTF-8') . "') center/cover no-repeat"
                : "rgba(0,0,0," . $alphaSidebar . ")"; ?>;
        border-right: 1px solid var(--border);
        display: flex;
        flex-direction: column;
        align-items: center;
        padding: 15px 0;
        position: fixed;
        top: 0;
        left: 0;
        height: 100vh;
        overflow-y: auto;
        z-index: 1000;
        backdrop-filter: blur(10px);
        -webkit-backdrop-filter: blur(10px);
    }

    .logo-pos {
        width: 55px;
        height: auto;
        margin-bottom: 15px;
        filter: drop-shadow(0 0 8px rgba(200,155,60,0.5));
        animation: logoPulse 4s ease-in-out infinite, glow 3s infinite alternate;
    }

    .nav-controls {
        display: flex;
        flex-direction: column;
        gap: 18px;
        margin-bottom: 30px;
        border-bottom: 1px solid var(--border);
        padding-bottom: 20px;
        width: 100%;
        align-items: center;
    }

    .sidebar a {
        color: #555;
        font-size: 20px;
        transition: 0.3s;
        text-decoration: none;
        position: relative;
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

    .products-section {
        flex: 1;
        padding: 30px;
        overflow-y: auto;
        background: rgba(0,0,0,0.12);
        animation: fadeUp 0.45s ease;
        margin-left: 85px;
        min-width: 0;
        backdrop-filter: blur(3px);
        -webkit-backdrop-filter: blur(3px);
    }

    .cart-section {
        width: 400px;
        background:
            <?php echo !empty($fondoSidebar)
                ? "linear-gradient(rgba(10,10,10," . max(0.65, min(0.98, $alphaSidebar + 0.05)) . "), rgba(10,10,10," . max(0.65, min(0.98, $alphaSidebar + 0.05)) . ")), url('" . htmlspecialchars($fondoSidebar, ENT_QUOTES, 'UTF-8') . "') right center/cover no-repeat"
                : "rgba(10,10,10,0.88)"; ?>;
        border-left: 1px solid var(--border);
        display: flex;
        flex-direction: column;
        padding: 20px;
        backdrop-filter: blur(12px);
        -webkit-backdrop-filter: blur(12px);
        animation: fadeUp 0.45s ease;
        min-width: 320px;
    }

    .personalizador-card,
    .cliente-card {
        background: rgba(255,255,255,<?php echo max(0.03, min(0.20, $alphaPanel * 0.35)); ?>);
        border: 1px solid var(--border);
        border-radius: 16px;
        padding: 18px;
        margin-bottom: 22px;
        box-shadow: 0 10px 30px rgba(0,0,0,0.25);
        position: relative;
        overflow: hidden;
        animation: fadeUp 0.45s ease;
        backdrop-filter: blur(10px);
        -webkit-backdrop-filter: blur(10px);
    }

    .personalizador-card::before,
    .cliente-card::before {
        content: "";
        position: absolute;
        inset: 0;
        background: linear-gradient(135deg, rgba(200,155,60,0.05), transparent 35%, transparent 65%, rgba(200,155,60,0.04));
        pointer-events: none;
    }

    .personalizador-card:hover,
    .cliente-card:hover {
        box-shadow: 0 14px 35px rgba(0,0,0,0.32), var(--shadow-gold);
        border-color: rgba(200,155,60,0.32);
        transition: 0.35s ease;
    }

    .personalizador-grid,
    .cliente-grid {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 12px;
    }

    .personalizador-grid .full,
    .cliente-grid .full {
        grid-column: 1 / -1;
    }

    .field-label {
        display: block;
        font-size: 11px;
        text-transform: uppercase;
        color: #b4b4b4;
        margin-bottom: 6px;
        letter-spacing: 0.8px;
    }

    .custom-input, .custom-select, .custom-textarea {
        width: 100%;
        background: linear-gradient(180deg, rgba(255,255,255,0.10), rgba(255,255,255,0.04));
        border: 1px solid rgba(255,255,255,0.10);
        color: #fff;
        border-radius: 10px;
        padding: 11px 12px;
        outline: none;
        backdrop-filter: blur(8px);
        -webkit-backdrop-filter: blur(8px);
        transition: all 0.28s ease;
    }

    .custom-input:focus, .custom-select:focus, .custom-textarea:focus {
        border-color: var(--gold);
        box-shadow: 0 0 0 2px rgba(200,155,60,0.12);
        transform: scale(1.01);
        background: rgba(255,255,255,0.09);
    }

    .custom-textarea {
        min-height: 80px;
        resize: vertical;
        font-family: inherit;
    }

    .preview-box {
        margin-top: 14px;
        padding: 12px 14px;
        border-radius: 12px;
        border: 1px dashed rgba(200,155,60,0.35);
        background: rgba(200,155,60,0.05);
        color: #ddd;
        font-size: 13px;
        position: relative;
        overflow: hidden;
        animation: shimmerBorder 3s infinite;
        word-break: break-word;
        backdrop-filter: blur(8px);
        -webkit-backdrop-filter: blur(8px);
    }

    .preview-box strong { color: var(--gold); }

    .preview-box::after {
        content: "";
        position: absolute;
        inset: 0;
        background: linear-gradient(90deg, transparent, rgba(255,255,255,0.04), transparent);
        transform: translateX(-100%);
        animation: previewSweep 3.2s infinite;
        pointer-events: none;
    }

    .actions-inline {
        display: flex;
        gap: 10px;
        margin-top: 12px;
        flex-wrap: wrap;
    }

    .btn-mini {
        border: 1px solid var(--border);
        background: rgba(255,255,255,0.05);
        color: #fff;
        padding: 10px 14px;
        border-radius: 10px;
        cursor: pointer;
        transition: all 0.28s ease;
        font-weight: 600;
        backdrop-filter: blur(8px);
        -webkit-backdrop-filter: blur(8px);
    }

    .btn-mini:hover {
        border-color: var(--gold);
        color: var(--gold);
        transform: translateY(-2px);
        box-shadow: 0 8px 18px rgba(0,0,0,0.18);
    }

    .btn-mini-gold {
        background: var(--gold);
        color: #000;
        border: 1px solid var(--gold);
        font-weight: 800;
        animation: pulseGold 2.8s infinite;
    }

    .btn-mini-gold:hover {
        background: #d8aa47;
        color: #000;
        border-color: #d8aa47;
    }

    .grid-products {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
        gap: 15px;
    }

    .product-item {
        background: linear-gradient(180deg, rgba(255,255,255,0.08), rgba(255,255,255,0.03));
        border: 1px solid var(--border);
        padding: 20px;
        border-radius: 15px;
        text-align: center;
        cursor: pointer;
        transition: all 0.28s ease;
        position: relative;
        overflow: hidden;
        min-width: 0;
        backdrop-filter: blur(10px);
        -webkit-backdrop-filter: blur(10px);
    }

    .product-item::before {
        content: "";
        position: absolute;
        top: -120%;
        left: -40%;
        width: 60%;
        height: 300%;
        transform: rotate(20deg);
        background: linear-gradient(to right, transparent, rgba(255,255,255,0.06), transparent);
        transition: 0.55s;
    }

    .product-item:hover::before { left: 120%; }

    .product-item:hover {
        border-color: var(--gold);
        transform: translateY(-5px);
        background: rgba(200,155,60,0.05);
        box-shadow: 0 12px 28px rgba(0,0,0,0.26), 0 0 20px rgba(200,155,60,0.12);
    }

    .product-name {
        display: block;
        margin-top: 10px;
        font-weight: bold;
        font-size: 14px;
        word-break: break-word;
    }

    .product-price {
        color: var(--gold);
        font-size: 18px;
        font-weight: bold;
    }

    .cart-items {
        flex: 1;
        overflow-y: auto;
        margin: 20px 0;
        min-height: 120px;
    }

    .cart-item {
        display: flex;
        justify-content: space-between;
        gap: 10px;
        background: rgba(255,255,255,0.04);
        padding: 12px;
        border-radius: 10px;
        margin-bottom: 10px;
        border: 1px solid rgba(255,255,255,0.06);
        animation: fadeUp 0.25s ease;
        transition: 0.25s ease;
        backdrop-filter: blur(8px);
        -webkit-backdrop-filter: blur(8px);
    }

    .cart-item:hover {
        border-color: rgba(200,155,60,0.28);
        background: rgba(255,255,255,0.06);
        transform: translateX(3px);
    }

    .cart-meta {
        margin-top: 5px;
        display: flex;
        flex-direction: column;
        gap: 2px;
        color: #b0b0b0;
        font-size: 11px;
    }

    .cart-meta span {
        background: rgba(255,255,255,0.04);
        border: 1px solid rgba(255,255,255,0.05);
        padding: 3px 7px;
        border-radius: 999px;
        display: inline-block;
        width: fit-content;
        max-width: 100%;
        word-break: break-word;
    }

    .total-row {
        display: flex;
        justify-content: space-between;
        font-size: 24px;
        font-weight: bold;
        margin-bottom: 20px;
        color: var(--gold);
        gap: 10px;
    }

    .btn-pay {
        width: 100%;
        padding: 18px;
        background: var(--gold);
        color: black;
        border: none;
        border-radius: 12px;
        font-weight: 800;
        cursor: pointer;
        transition: all 0.28s ease;
    }

    .btn-pay:hover {
        transform: translateY(-2px) scale(1.01);
        box-shadow: 0 10px 24px rgba(0,0,0,0.25), 0 0 18px rgba(200,155,60,0.20);
    }

    .method {
        background: rgba(255,255,255,0.05);
        border: 1px solid #333;
        padding: 12px;
        text-align: center;
        border-radius: 8px;
        cursor: pointer;
        color: #aaa;
        font-size: 11px;
        transition: all 0.28s ease;
        word-break: break-word;
        backdrop-filter: blur(8px);
        -webkit-backdrop-filter: blur(8px);
    }

    .method:hover {
        transform: translateY(-1px);
        border-color: rgba(200,155,60,0.45);
    }

    .method.active {
        border-color: var(--gold);
        color: var(--gold);
        background: rgba(200,155,60,0.1);
        animation: pulseGold 2.4s infinite;
    }

    #toast {
        position: fixed;
        top: 20px;
        right: 20px;
        background: linear-gradient(135deg, #1f9d49, #28a745);
        color: white;
        padding: 15px 30px;
        border-radius: 14px;
        font-weight: bold;
        z-index: 9999;
        box-shadow: 0 14px 35px rgba(0,0,0,0.35);
        transform: translateX(150%) scale(0.95);
        transition: 0.45s ease;
        display: flex;
        align-items: center;
        gap: 10px;
        border: 1px solid rgba(255,255,255,0.08);
        max-width: calc(100vw - 40px);
    }

    #toast.show { transform: translateX(0) scale(1); }

    @media (max-width: 1200px) {
        .personalizador-grid, .cliente-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
        .cart-section { width: 360px; }
    }

    @media (max-width: 980px) {
        body {
            flex-direction: column;
            overflow-y: auto;
        }

        .mobile-topbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            position: sticky;
            top: 0;
            z-index: 1100;
            padding: 14px 16px;
            background: rgba(5,5,5,0.92);
            border-bottom: 1px solid var(--border);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
        }

        .mobile-topbar-left {
            display: flex;
            align-items: center;
            gap: 10px;
            min-width: 0;
        }

        .mobile-topbar-logo {
            width: 38px;
            height: 38px;
            object-fit: contain;
        }

        .mobile-topbar-title {
            font-size: 14px;
            font-weight: 700;
            color: #fff;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .mobile-menu-toggle {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 42px;
            height: 42px;
            background: rgba(255,255,255,0.06);
            color: var(--gold);
            border: 1px solid rgba(255,255,255,0.08);
            border-radius: 12px;
            font-size: 18px;
            cursor: pointer;
        }

        .sidebar-overlay {
            display: block;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.45);
            opacity: 0;
            visibility: hidden;
            transition: 0.3s ease;
            z-index: 999;
        }

        .sidebar {
            width: 280px;
            max-width: 82vw;
            transform: translateX(-100%);
            transition: transform 0.3s ease;
            padding-top: 20px;
        }

        body.menu-open .sidebar { transform: translateX(0); }
        body.menu-open .sidebar-overlay {
            opacity: 1;
            visibility: visible;
        }

        .products-section {
            margin-left: 0;
            width: 100%;
            padding: 20px 16px;
            overflow: visible;
        }

        .cart-section {
            width: 100%;
            min-width: 0;
            border-left: none;
            border-top: 1px solid var(--border);
            padding: 18px 16px 24px 16px;
        }
    }

    @media (max-width: 780px) {
        .personalizador-grid, .cliente-grid { grid-template-columns: 1fr; }
        .grid-products {
            grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
            gap: 12px;
        }
    }

    @media (max-width: 560px) {
        .products-section { padding: 16px 12px; }
        .cart-section { padding: 16px 12px 22px 12px; }
        .cliente-card, .personalizador-card {
            padding: 14px;
            border-radius: 14px;
            margin-bottom: 16px;
        }
        .actions-inline { flex-direction: column; }
        .btn-mini, .btn-mini-gold, .btn-pay { width: 100%; }
        .grid-products { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; }
    }
    </style>
</head>
<body>

    <div class="mobile-topbar">
        <div class="mobile-topbar-left">
            <img src="<?php echo htmlspecialchars($logoActual, ENT_QUOTES, 'UTF-8'); ?>" alt="Logo" class="mobile-topbar-logo">
            <div class="mobile-topbar-title">Studio POS</div>
        </div>
        <button class="mobile-menu-toggle" id="mobileMenuToggle" aria-label="Abrir menú">
            <i class="fas fa-bars"></i>
        </button>
    </div>

    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <div id="toast"><i class="fas fa-check-circle"></i> <span id="toast-msg"></span></div>

    <div class="sidebar" id="sidebar">
        <img src="<?php echo htmlspecialchars($logoActual, ENT_QUOTES, 'UTF-8'); ?>" alt="Logo" class="logo-pos">

        <div class="nav-controls">
            <a href="dashboard.php" title="Volver al Dashboard"><i class="fas fa-arrow-left"></i></a>
            <a href="logout.php" class="exit-btn" title="Salir del Sistema"><i class="fas fa-power-off"></i></a>
        </div>

        <a href="ventas.php" class="active" title="Caja POS"><i class="fas fa-cash-register"></i></a>
        <a href="productos.php" title="Inventario"><i class="fas fa-box"></i></a>
        <a href="clientes.php" title="Clientes"><i class="fas fa-users"></i></a>
        <a href="configuracion.php" title="Configuración"><i class="fas fa-cog"></i></a>
    </div>

    <div class="products-section">
        <h2 style="font-weight: 300; margin-bottom: 16px;">Terminal <span style="color:var(--gold)">Studio POS</span></h2>

        <div class="cliente-card">
            <h3 style="margin:0 0 14px 0; font-weight:400; color: var(--gold);">Datos del cliente</h3>

            <div style="display:flex; gap:10px; margin-bottom:14px; flex-wrap:wrap; align-items:flex-end;">
                <button type="button" class="btn-mini btn-mini-gold" id="btnModoCargarCliente" onclick="activarModoCliente()">
                    <i class="fas fa-user-check"></i> Cargar cliente
                </button>

                <button type="button" class="btn-mini" id="btnModoPublico" onclick="ventaPublicoGeneral()">
                    <i class="fas fa-user-tag"></i> Venta al público
                </button>

                <button type="button" class="btn-mini" onclick="limpiarDatosCliente()">
                    <i class="fas fa-user-slash"></i> Limpiar datos
                </button>
            </div>

            <div style="display:flex; gap:10px; margin-bottom:14px; flex-wrap:wrap; align-items:flex-end;">
                <div style="flex:1; min-width:260px;">
                    <label class="field-label">Seleccionar cliente registrado</label>
                    <select id="clienteRegistradoSelect" class="custom-select">
                        <option value="">Seleccionar cliente...</option>
                        <?php foreach ($clientesRegistrados as $cli): ?>
                            <?php
                                $tipoClienteOption = $cli['tipo_cliente'] ?? 'Personalizado';
                                if (!in_array($tipoClienteOption, ['Personalizado', 'DTF'], true)) {
                                    $tipoClienteOption = 'Personalizado';
                                }
                            ?>
                            <option value="<?php echo (int)$cli['id']; ?>">
                                <?php
                                    echo htmlspecialchars(
                                        ($cli['nombre'] ?: ('Cliente #' . $cli['id'])) .
                                        (!empty($cli['telefono']) ? ' - ' . $cli['telefono'] : '') .
                                        ' - ' . $tipoClienteOption,
                                        ENT_QUOTES,
                                        'UTF-8'
                                    );
                                ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="cliente-grid">
                <div>
                    <label class="field-label">Nombre del cliente</label>
                    <input type="text" id="clienteNombre" class="custom-input" placeholder="Ej: Juan Pérez" value="<?php echo htmlspecialchars($clienteNombreAuto, ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <div>
                    <label class="field-label">Teléfono</label>
                    <input type="text" id="clienteTelefono" class="custom-input" placeholder="Ej: 8123456789" value="<?php echo htmlspecialchars($clienteTelefonoAuto, ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <div>
                    <label class="field-label">Dirección</label>
                    <input type="text" id="clienteDireccion" class="custom-input" placeholder="Ej: Calle, colonia, ciudad" value="<?php echo htmlspecialchars($clienteDireccionAuto, ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <div>
                    <label class="field-label">Correo</label>
                    <input type="text" id="clienteEmail" class="custom-input" placeholder="Ej: cliente@correo.com" value="<?php echo htmlspecialchars($clienteEmailAuto, ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <div>
                    <label class="field-label">Tipo de cliente</label>
                    <select id="clienteTipo" class="custom-select">
                        <option value="Personalizado" <?php echo $clienteTipoAuto === 'Personalizado' ? 'selected' : ''; ?>>Personalizado</option>
                        <option value="DTF" <?php echo $clienteTipoAuto === 'DTF' ? 'selected' : ''; ?>>DTF</option>
                    </select>
                </div>
                <div>
                    <label class="field-label">ID Cliente</label>
                    <input type="text" id="clienteId" class="custom-input" value="<?php echo (int)$clienteIdAuto; ?>" readonly>
                </div>
            </div>
        </div>

        <div class="cliente-card">
            <h3 style="margin:0 0 14px 0; font-weight:400; color: var(--gold);">Datos de venta y entrega</h3>

            <div class="cliente-grid">
                <div>
                    <label class="field-label">Fecha de venta</label>
                    <input type="date" id="fechaVenta" class="custom-input" value="<?php echo date('Y-m-d'); ?>">
                </div>

                <div>
                    <label class="field-label">Fecha de entrega</label>
                    <input type="date" id="fechaEntrega" class="custom-input">
                </div>

                <div>
                    <label class="field-label">Día de entrega</label>
                    <input type="text" id="diaEntrega" class="custom-input" placeholder="Ej: Viernes" readonly>
                </div>

                <div class="full">
                    <label class="field-label">Imagen general del pedido</label>
                    <input type="file" id="imagenDisenoSeleccion" class="custom-input" accept="image/*" onchange="previsualizarImagenDiseno(event)">
                </div>

                <div class="full">
                    <div class="preview-box" id="previewImagenDisenoBox" style="display:none;">
                        <strong>Vista previa de imagen general del pedido:</strong>
                        <div style="margin-top:10px;">
                            <img id="previewImagenDiseno" src="" alt="Diseño" style="max-width:100%; max-height:240px; object-fit:contain; border-radius:12px; border:1px solid rgba(255,255,255,.08);">
                        </div>
                    </div>
                </div>

                <div class="full">
                    <button type="button" class="btn-mini" id="toggleExtrasVenta" onclick="toggleExtrasVenta()">
                        <i class="fas fa-chevron-down"></i> Mostrar opciones extra de remisión
                    </button>
                </div>

                <div class="full" id="extrasVentaBox" style="display:none;">
                    <div class="cliente-grid">
                        <div class="full">
                            <label class="field-label">Mensaje de remisión</label>
                            <textarea id="mensajeRemision" class="custom-textarea" placeholder="Ej: Gracias por su compra, pedido sujeto a validación de diseño..."></textarea>
                        </div>

                        <div class="full">
                            <label class="field-label">Observaciones</label>
                            <textarea id="observacionesVenta" class="custom-textarea" placeholder="Ej: Entregar por la tarde, revisar color final, anticipo pendiente..."></textarea>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="personalizador-card">
            <h3 style="margin:0 0 14px 0; font-weight:400; color: var(--gold);">Personalización rápida del producto</h3>

            <div class="personalizador-grid">
                <div>
                    <label class="field-label">Línea / Público</label>
                    <select id="categoriaSeleccion" class="custom-select" onchange="actualizarVistaPrevia()">
                        <option value="">Seleccionar...</option>
                        <option value="Bebé">Bebé</option>
                        <option value="Niño">Niño</option>
                        <option value="Joven">Joven</option>
                        <option value="Dama">Dama</option>
                        <option value="Caballero">Caballero</option>
                    </select>
                </div>

                <div>
                    <label class="field-label">Producto</label>
                    <select id="tipoProductoSeleccion" class="custom-select" onchange="actualizarVistaPrevia()">
                        <option value="">Seleccionar...</option>
                        <option value="Playera">Playera</option>
                        <option value="Playera Manga Larga">Playera Manga Larga</option>
                        <option value="Playera Dri Fit">Playera Dri Fit</option>
                        <option value="Sudadera">Sudadera</option>
                    </select>
                </div>

                <div>
                    <label class="field-label">Precio manual</label>
                    <input type="number" id="precioManualProducto" class="custom-input" min="0" step="0.01" placeholder="Ej: 180.00">
                </div>

                <div>
                    <label class="field-label">Talla</label>
                    <select id="tallaSeleccion" class="custom-select" onchange="actualizarVistaPrevia()">
                        <option value="">Seleccionar...</option>
                    </select>
                </div>

                <div>
                    <label class="field-label">Color</label>
                    <select id="colorSeleccion" class="custom-select" onchange="actualizarVistaPrevia()">
                        <option value="">Seleccionar...</option>
                        <option value="Blanco">Blanco</option>
                        <option value="Negro">Negro</option>
                        <option value="Rojo">Rojo</option>
                        <option value="Royal">Royal</option>
                        <option value="Marino">Marino</option>
                        <option value="Turquesa">Turquesa</option>
                        <option value="Verde Lima">Verde Lima</option>
                        <option value="Amarillo">Amarillo</option>
                        <option value="Naranja">Naranja</option>
                        <option value="Rosa">Rosa</option>
                        <option value="Fucsia">Fucsia</option>
                        <option value="Gris Jaspe">Gris Jaspe</option>
                        <option value="Charcoal">Charcoal</option>
                        <option value="Gold">Gold</option>
                    </select>
                </div>

                <div>
                    <label class="field-label">Diseño</label>
                    <input type="text" id="disenoSeleccion" class="custom-input" placeholder="Ej: Logo frontal 30x30" oninput="actualizarVistaPrevia()">
                </div>

                <div>
                    <label class="field-label">Descripción corta</label>
                    <input type="text" id="descripcionCortaSeleccion" class="custom-input" placeholder="Ej: Pedido express" oninput="actualizarVistaPrevia()">
                </div>

                <div class="full">
                    <label class="field-label">Descripción / Detalles</label>
                    <textarea id="descripcionSeleccion" class="custom-textarea" placeholder="Ej: Diseño al frente, nombre atrás, color blanco, entrega viernes..." oninput="actualizarVistaPrevia()"></textarea>
                </div>

                <div class="full">
                    <label class="field-label">Imagen del diseño</label>
                    <input type="file" id="imagenDisenoSeleccion" class="custom-input" accept="image/*" onchange="previsualizarImagenDiseno(event)">
                </div>

                <div class="full">
                    <div class="preview-box" id="previewImagenDisenoBox" style="display:none;">
                        <strong>Vista previa de imagen:</strong>
                        <div style="margin-top:10px;">
                            <img id="previewImagenDiseno" src="" alt="Diseño" style="max-width:100%; max-height:240px; object-fit:contain; border-radius:12px; border:1px solid rgba(255,255,255,.08);">
                        </div>
                    </div>
                </div>
            </div>

            <div class="preview-box" id="vistaPreviaProducto">
                <strong>Vista previa:</strong> Sin personalización todavía.
            </div>

            <div class="actions-inline">
                <button type="button" class="btn-mini" onclick="limpiarPersonalizacion()">
                    <i class="fas fa-eraser"></i> Limpiar selección
                </button>

                <button type="button" class="btn-mini btn-mini-gold" onclick="agregarPersonalizado()">
                    <i class="fas fa-cart-plus"></i> Añadir al carrito
                </button>
            </div>
        </div>

        <div class="grid-products">
            <?php if (!empty($productosLista)): ?>
                <?php foreach ($productosLista as $prod): ?>
                <div class="product-item" onclick="agregarAlCarrito(<?php echo (int)$prod['id']; ?>, <?php echo json_encode($prod['nombre'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>, <?php echo json_encode((float)$prod['precio']); ?>)">
                    <i class="fas fa-bolt" style="color: var(--gold); font-size: 24px;"></i>
                    <span class="product-name"><?php echo htmlspecialchars($prod['nombre'], ENT_QUOTES, 'UTF-8'); ?></span>
                    <span class="product-price">$<?php echo number_format((float)$prod['precio'], 2); ?></span>
                </div>
                <?php endforeach; ?>
            <?php else: ?>
                <p>No hay productos disponibles o la tabla de productos no está lista todavía.</p>
            <?php endif; ?>
        </div>
    </div>

    <div class="cart-section">
        <h3 style="margin-top:0;">Orden Actual</h3>
        <div class="cart-items" id="lista-carrito">
            <p style="color: #444; text-align: center; margin-top: 50px;">Carrito vacío</p>
        </div>

        <div class="cart-total">
            <div class="payment-methods" style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 20px;">
                <div class="method active" onclick="setMetodo(this)">EFECTIVO</div>
                <div class="method" onclick="setMetodo(this)">TRANSFERENCIA</div>
                <div class="method" onclick="setMetodo(this)">PAGO MIXTO</div>
                <div class="method" onclick="setMetodo(this)">TARJETA</div>
            </div>

            <div class="total-row">
                <span>TOTAL</span>
                <span id="total-precio">$0.00</span>
            </div>

            <div class="cliente-grid" style="margin-top: 14px;">
                <div>
                    <label class="field-label">Anticipo / Abono</label>
                    <input type="number" id="anticipoVenta" class="custom-input" min="0" step="0.01" value="0" oninput="actualizarSaldoPendiente()">
                </div>
                <div>
                    <label class="field-label">Saldo pendiente</label>
                    <input type="number" id="saldoPendienteVenta" class="custom-input" min="0" step="0.01" value="0.00" readonly>
                </div>
            </div>

            <div style="display: flex; flex-direction: column; gap: 10px;">
                <button class="btn-pay" onclick="finalizarVenta(true, false)" style="background: #fff; color: #000; display: flex; align-items: center; justify-content: center; gap: 10px;">
                    <i class="fas fa-print"></i> COBRAR E IMPRIMIR
                </button>

                <button class="btn-pay" onclick="finalizarVenta(false, false)" style="background: var(--gold); color: #000; display: flex; align-items: center; justify-content: center; gap: 10px;">
                    <i class="fas fa-check-circle"></i> REGISTRAR PAGO
                </button>

                <button class="btn-pay" onclick="finalizarVentaSoloTicket()" style="background: #1f1f1f; color: #fff; display: flex; align-items: center; justify-content: center; gap: 10px;">
                    <i class="fas fa-receipt"></i> COBRAR E IMPRIMIR TICKET
                </button>

                <button class="btn-pay" onclick="finalizarVentaConRemisionWhatsApp()" style="background: #25D366; color: #fff; display: flex; align-items: center; justify-content: center; gap: 10px;">
                    <i class="fab fa-whatsapp"></i> REGISTRAR Y ENVIAR REMISIÓN
                </button>
            </div>
        </div>
    </div>

    <script>
        const tallasPorCategoria = {
            'Bebé': ['2', '4', '6'],
            'Niño': ['2', '4', '6', '8', '10', '12', '14'],
            'Joven': ['XS', 'S', 'M', 'L'],
            'Dama': ['XS', 'S', 'M', 'L', 'XL', 'XXL'],
            'Caballero': ['S', 'M', 'L', 'XL', 'XXL', '3XL']
        };

        let carrito = [];
        let total = 0;
        let metodoSeleccionado = 'EFECTIVO';

        const categoriaSeleccion = document.getElementById('categoriaSeleccion');
        const tipoProductoSeleccion = document.getElementById('tipoProductoSeleccion');
        const tallaSeleccion = document.getElementById('tallaSeleccion');
        const colorSeleccion = document.getElementById('colorSeleccion');
        const disenoSeleccion = document.getElementById('disenoSeleccion');
        const descripcionCortaSeleccion = document.getElementById('descripcionCortaSeleccion');
        const descripcionSeleccion = document.getElementById('descripcionSeleccion');
        const vistaPreviaProducto = document.getElementById('vistaPreviaProducto');
        const imagenDisenoSeleccion = document.getElementById('imagenDisenoSeleccion');
        const previewImagenDisenoBox = document.getElementById('previewImagenDisenoBox');
        const previewImagenDiseno = document.getElementById('previewImagenDiseno');

        let imagenDisenoBase64 = '';
        let imagenDisenoNombre = '';

        const clienteNombre = document.getElementById('clienteNombre');
        const clienteTelefono = document.getElementById('clienteTelefono');
        const clienteDireccion = document.getElementById('clienteDireccion');
        const clienteEmail = document.getElementById('clienteEmail');
        const clienteTipo = document.getElementById('clienteTipo');
        const clienteId = document.getElementById('clienteId');
        const clienteRegistradoSelect = document.getElementById('clienteRegistradoSelect');

        const fechaVenta = document.getElementById('fechaVenta');
        const fechaEntrega = document.getElementById('fechaEntrega');
        const diaEntrega = document.getElementById('diaEntrega');
        const mensajeRemision = document.getElementById('mensajeRemision');
        const observacionesVenta = document.getElementById('observacionesVenta');
        const precioManualProducto = document.getElementById('precioManualProducto');
        const anticipoVenta = document.getElementById('anticipoVenta');
        const saldoPendienteVenta = document.getElementById('saldoPendienteVenta');
        const extrasVentaBox = document.getElementById('extrasVentaBox');
        const toggleExtrasVentaBtn = document.getElementById('toggleExtrasVenta');
        const btnModoCargarCliente = document.getElementById('btnModoCargarCliente');
        const btnModoPublico = document.getElementById('btnModoPublico');

        const clientesRegistradosMap = <?php echo json_encode($clientesRegistradosMap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;

        const body = document.body;
        const mobileMenuToggle = document.getElementById('mobileMenuToggle');
        const sidebarOverlay = document.getElementById('sidebarOverlay');

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

        function mostrarToast(texto) {
            const toast = document.getElementById('toast');
            document.getElementById('toast-msg').innerText = texto;
            toast.classList.add('show');
            setTimeout(() => toast.classList.remove('show'), 2200);
        }

        function aplicarModoVenta(esPublico = false) {
            const bloquear = [clienteRegistradoSelect, clienteNombre, clienteDireccion, clienteEmail, clienteId, clienteTipo];
            bloquear.forEach(el => {
                if (el) el.disabled = esPublico;
            });

            if (btnModoCargarCliente) btnModoCargarCliente.classList.toggle('btn-mini-gold', !esPublico);
            if (btnModoPublico) btnModoPublico.classList.toggle('btn-mini-gold', esPublico);
        }

        function activarModoCliente() {
            aplicarModoVenta(false);
            if (clienteRegistradoSelect && clienteRegistradoSelect.value.trim() !== '') {
                cargarClienteRegistrado();
            } else {
                mostrarToast('Modo cargar cliente activado');
            }
        }

        function toggleExtrasVenta() {
            if (!extrasVentaBox) return;
            const visible = extrasVentaBox.style.display !== 'none';
            extrasVentaBox.style.display = visible ? 'none' : 'block';
            if (toggleExtrasVentaBtn) {
                toggleExtrasVentaBtn.innerHTML = visible
                    ? '<i class="fas fa-chevron-down"></i> Mostrar opciones extra de remisión'
                    : '<i class="fas fa-chevron-up"></i> Ocultar opciones extra de remisión';
            }
        }


        function cargarClienteRegistrado() {
            aplicarModoVenta(false);
            const idSeleccionado = clienteRegistradoSelect ? clienteRegistradoSelect.value.trim() : '';
            if (!idSeleccionado || !clientesRegistradosMap[idSeleccionado]) {
                mostrarToast('Selecciona un cliente registrado');
                return;
            }

            const cliente = clientesRegistradosMap[idSeleccionado];
            clienteId.value = cliente.id || '';
            clienteNombre.value = cliente.nombre || '';
            clienteTelefono.value = cliente.telefono || '';
            clienteDireccion.value = cliente.direccion || '';
            clienteEmail.value = cliente.email || '';
            clienteTipo.value = cliente.tipo_cliente || 'Personalizado';

            mostrarToast('Cliente registrado cargado correctamente');
        }

        function setMetodo(el) {
            document.querySelectorAll('.method').forEach(m => m.classList.remove('active'));
            el.classList.add('active');
            metodoSeleccionado = el.innerText;
        }

        function ventaPublicoGeneral() {
            const telefonoActual = clienteTelefono.value.trim();
            aplicarModoVenta(true);
            clienteId.value = '';
            clienteNombre.value = 'Público en general';
            clienteTelefono.value = telefonoActual;
            clienteDireccion.value = '';
            clienteEmail.value = '';
            clienteTipo.value = 'Personalizado';
            if (clienteRegistradoSelect) clienteRegistradoSelect.value = '';
            mostrarToast('Venta al público activada. Nombre, dirección, correo e ID quedaron bloqueados.');
        }

        function limpiarDatosCliente() {
            aplicarModoVenta(false);
            clienteId.value = '';
            clienteNombre.value = '';
            clienteTelefono.value = '';
            clienteDireccion.value = '';
            clienteEmail.value = '';
            clienteTipo.value = 'Personalizado';
            if (clienteRegistradoSelect) clienteRegistradoSelect.value = '';
            mostrarToast('Datos del cliente limpiados correctamente');
        }

        function actualizarTallas() {
            const categoria = categoriaSeleccion.value;
            const tallas = tallasPorCategoria[categoria] || [];
            tallaSeleccion.innerHTML = '<option value="">Seleccionar...</option>';
            tallas.forEach(talla => {
                const option = document.createElement('option');
                option.value = talla;
                option.textContent = talla;
                tallaSeleccion.appendChild(option);
            });
        }

        function actualizarVistaPrevia() {
            const diseno = disenoSeleccion.value.trim();
            const descripcionCorta = descripcionCortaSeleccion.value.trim();
            const descripcion = descripcionSeleccion.value.trim();

            const nombreArmado = construirNombrePersonalizado('Producto base');
            let meta = [];

            if (diseno) meta.push('Diseño: ' + diseno);
            if (descripcionCorta) meta.push('Detalle: ' + descripcionCorta);
            if (descripcion) meta.push('Descripción: ' + descripcion);

            vistaPreviaProducto.innerHTML =
                `<strong>Vista previa:</strong> ${nombreArmado !== 'Producto base' ? nombreArmado : 'Sin personalización todavía.'}` +
                (meta.length ? `<br><span style="display:block; margin-top:6px; color:#bbb;">${meta.join(' | ')}</span>` : '');
        }

        function construirNombrePersonalizado(nombreBase) {
            const categoria = categoriaSeleccion.value;
            const tipo = tipoProductoSeleccion.value;
            const talla = tallaSeleccion.value;
            const color = colorSeleccion.value;
            const descripcionCorta = descripcionCortaSeleccion.value.trim();

            let partes = [];
            if (tipo) partes.push(tipo);
            if (categoria) partes.push(categoria);
            if (talla) partes.push(talla);
            if (color) partes.push(color);

            let nombreFinal = partes.length ? partes.join(' ') : nombreBase;
            if (descripcionCorta) nombreFinal += ' - ' + descripcionCorta;
            return nombreFinal;
        }

        function obtenerMetaPersonalizacion() {
            return {
                categoria: categoriaSeleccion.value,
                tipo_producto: tipoProductoSeleccion.value,
                talla: tallaSeleccion.value,
                color: colorSeleccion.value,
                diseno: disenoSeleccion.value.trim(),
                descripcion_corta: descripcionCortaSeleccion.value.trim(),
                descripcion: descripcionSeleccion.value.trim()
            };
        }

        function previsualizarImagenDiseno(event) {
            const file = event.target.files && event.target.files[0] ? event.target.files[0] : null;

            if (!file) {
                imagenDisenoBase64 = '';
                imagenDisenoNombre = '';
                if (previewImagenDisenoBox) previewImagenDisenoBox.style.display = 'none';
                if (previewImagenDiseno) previewImagenDiseno.src = '';
                return;
            }

            imagenDisenoNombre = file.name;

            const reader = new FileReader();
            reader.onload = function(e) {
                imagenDisenoBase64 = e.target.result || '';
                if (previewImagenDiseno && imagenDisenoBase64) {
                    previewImagenDiseno.src = imagenDisenoBase64;
                }
                if (previewImagenDisenoBox) {
                    previewImagenDisenoBox.style.display = 'block';
                }
            };
            reader.readAsDataURL(file);
        }

        function limpiarImagenDiseno() {
            imagenDisenoBase64 = '';
            imagenDisenoNombre = '';

            if (imagenDisenoSeleccion) {
                imagenDisenoSeleccion.value = '';
            }

            if (previewImagenDiseno) {
                previewImagenDiseno.src = '';
            }

            if (previewImagenDisenoBox) {
                previewImagenDisenoBox.style.display = 'none';
            }
        }

        function agregarAlCarrito(id, nombre, precio) {
            const meta = obtenerMetaPersonalizacion();
            const nombrePersonalizado = construirNombrePersonalizado(nombre);
            const precioUsar = parseFloat(precioManualProducto ? precioManualProducto.value : 0);

            if (isNaN(precioUsar) || precioUsar <= 0) {
                alert('Ingresa un precio manual válido para agregar el producto.');
                return;
            }

            carrito.push({
                id,
                nombre: nombrePersonalizado,
                precio: parseFloat(precioUsar),
                cantidad: 1,
                personalizacion: meta,
                nombre_original: nombre
            });
            renderCarrito();
        }

        function agregarPersonalizado() {
            const tipo = tipoProductoSeleccion.value;
            if (!tipo) {
                alert("Selecciona un producto");
                return;
            }

            const precioBase = parseFloat(precioManualProducto ? precioManualProducto.value : 0);
            if (isNaN(precioBase) || precioBase <= 0) {
                alert("Ingresa un precio manual válido.");
                return;
            }

            const nombre = construirNombrePersonalizado(tipo);

            carrito.push({
                id: Date.now(),
                nombre: nombre,
                precio: parseFloat(precioBase),
                cantidad: 1,
                personalizacion: obtenerMetaPersonalizacion(),
                nombre_original: tipo
            });

            renderCarrito();
        }

        function actualizarSaldoPendiente() {
            const anticipo = parseFloat(anticipoVenta ? anticipoVenta.value : 0) || 0;
            const saldo = Math.max(0, total - anticipo);
            if (saldoPendienteVenta) {
                saldoPendienteVenta.value = saldo.toFixed(2);
            }
        }

        function renderCarrito() {
            const container = document.getElementById('lista-carrito');
            const totalTxt = document.getElementById('total-precio');
            container.innerHTML = '';
            total = 0;

            if (carrito.length === 0) {
                container.innerHTML = '<p style="color: #444; text-align: center; margin-top: 50px;">Carrito vacío</p>';
            }

            carrito.forEach((item, index) => {
                total += parseFloat(item.precio) * (parseInt(item.cantidad || 1));
                const meta = item.personalizacion || {};
                const extras = [];

                if (meta.tipo_producto) extras.push(meta.tipo_producto);
                if (meta.categoria) extras.push(meta.categoria);
                if (meta.talla) extras.push('Talla ' + meta.talla);
                if (meta.color) extras.push(meta.color);
                if (meta.diseno) extras.push('Diseño: ' + meta.diseno);
                if (meta.descripcion) extras.push(meta.descripcion);

                container.innerHTML += `
                    <div class="cart-item">
                        <div>
                            <strong>${item.nombre}</strong><br>
                            <small>$${Number(item.precio).toFixed(2)}</small>
                            ${extras.length ? `<div class="cart-meta"><span>${extras.join('</span><span>')}</span></div>` : ''}
                        </div>
                        <i class="fas fa-trash" style="color:#ff4d4d; cursor:pointer;" onclick="eliminarDelCarrito(${index})"></i>
                    </div>`;
            });

            totalTxt.innerText = `$${total.toFixed(2)}`;
            actualizarSaldoPendiente();
        }

        function eliminarDelCarrito(index) {
            carrito.splice(index, 1);
            renderCarrito();
        }

        function limpiarPersonalizacion() {
            categoriaSeleccion.value = '';
            tipoProductoSeleccion.value = '';
            tallaSeleccion.innerHTML = '<option value="">Seleccionar...</option>';
            colorSeleccion.value = '';
            disenoSeleccion.value = '';
            descripcionCortaSeleccion.value = '';
            descripcionSeleccion.value = '';
            limpiarImagenDiseno();
            actualizarVistaPrevia();
        }

        function actualizarDiaEntrega() {
            const valor = fechaEntrega.value;
            if (!valor) {
                diaEntrega.value = '';
                return;
            }

            const fechaLocal = new Date(valor + 'T12:00:00');
            const dias = ['Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'];
            diaEntrega.value = dias[fechaLocal.getDay()] || '';
        }

        function finalizarVenta(imprimir = false, enviarRemisionWhatsapp = false) {
            if (carrito.length === 0) return;

            if (!fechaVenta.value) {
                alert('Selecciona la fecha de venta');
                return;
            }

            if (!fechaEntrega.value) {
                alert('Selecciona la fecha de entrega');
                return;
            }

            const esPublicoGeneral = !clienteId.value.trim();

            if (esPublicoGeneral && !clienteTelefono.value.trim()) {
                const continuar = confirm('La venta al público no tiene teléfono. Si continúas, no se podrá enviar la nota de remisión por WhatsApp. ¿Deseas seguir?');
                if (!continuar) return;
            }

            fetch('guardar_venta.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({
                    carrito,
                    total,
                    metodo: metodoSeleccionado,
                    imprimir: imprimir,
                    enviar_remision_whatsapp: enviarRemisionWhatsapp,
                    cliente_id: clienteId.value.trim(),
                    cliente_nombre: clienteNombre.value.trim() || 'Público en general',
                    cliente_telefono: clienteTelefono.value.trim(),
                    cliente_direccion: clienteDireccion.value.trim(),
                    cliente_email: clienteEmail.value.trim(),
                    tipo_cliente: clienteTipo ? clienteTipo.value.trim() : 'Personalizado',
                    fecha_venta: fechaVenta ? fechaVenta.value : '',
                    fecha_entrega: fechaEntrega ? fechaEntrega.value : '',
                    dia_entrega: diaEntrega ? diaEntrega.value.trim() : '',
                    mensaje_remision: mensajeRemision ? mensajeRemision.value.trim() : '',
                    observaciones: observacionesVenta ? observacionesVenta.value.trim() : '',
                    anticipo: anticipoVenta ? anticipoVenta.value : '0',
                    saldo_pendiente: saldoPendienteVenta ? saldoPendienteVenta.value : '0',
                    imagen_diseno_base64: imagenDisenoBase64,
                    imagen_diseno_nombre: imagenDisenoNombre
                })
            })
            .then(async res => {
                const texto = await res.text();
                try {
                    return JSON.parse(texto);
                } catch (e) {
                    throw new Error(texto || 'Respuesta inválida del servidor');
                }
            })
            .then(data => {
                if (data.status === 'success') {
                    const toast = document.getElementById('toast');
                    document.getElementById('toast-msg').innerText = data.mensaje || 'Venta registrada correctamente';
                    toast.classList.add('show');

                    if (imprimir) {
                        if (data.remision_url) window.open(data.remision_url, '_blank');
                        setTimeout(() => {
                            if (data.ticket_url) window.open(data.ticket_url, '_blank');
                        }, 500);
                    }

                    carrito = [];
                    renderCarrito();
                    limpiarDatosCliente();
                    limpiarPersonalizacion();

                    if (fechaEntrega) fechaEntrega.value = '';
                    if (diaEntrega) diaEntrega.value = '';
                    if (mensajeRemision) mensajeRemision.value = '';
                    if (observacionesVenta) observacionesVenta.value = '';
                    if (precioManualProducto) precioManualProducto.value = '';
                    if (anticipoVenta) anticipoVenta.value = '0';
                    if (saldoPendienteVenta) saldoPendienteVenta.value = '0.00';
                    if (fechaVenta) fechaVenta.value = new Date().toISOString().split('T')[0];

                    setTimeout(() => {
                        toast.classList.remove('show');
                        window.location.reload();
                    }, 3000);
                } else {
                    alert(data.mensaje || 'Ocurrió un error al registrar la venta');
                }
            })
            .catch(error => {
                alert('Error al guardar la venta: ' + error.message);
                console.error(error);
            });
        }

        function finalizarVentaSoloTicket() {
            if (carrito.length === 0) return;

            if (!fechaVenta.value) {
                alert('Selecciona la fecha de venta');
                return;
            }

            if (!fechaEntrega.value) {
                alert('Selecciona la fecha de entrega');
                return;
            }

            const esPublicoGeneral = !clienteId.value.trim();

            if (esPublicoGeneral && !clienteTelefono.value.trim()) {
                const continuar = confirm('La venta al público no tiene teléfono. Si continúas, no se podrá enviar la nota de remisión por WhatsApp. ¿Deseas seguir?');
                if (!continuar) return;
            }

            fetch('guardar_venta.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({
                    carrito,
                    total,
                    metodo: metodoSeleccionado,
                    imprimir: false,
                    solo_ticket: true,
                    enviar_remision_whatsapp: false,
                    cliente_id: clienteId.value.trim(),
                    cliente_nombre: clienteNombre.value.trim() || 'Público en general',
                    cliente_telefono: clienteTelefono.value.trim(),
                    cliente_direccion: clienteDireccion.value.trim(),
                    cliente_email: clienteEmail.value.trim(),
                    tipo_cliente: clienteTipo ? clienteTipo.value.trim() : 'Personalizado',
                    fecha_venta: fechaVenta ? fechaVenta.value : '',
                    fecha_entrega: fechaEntrega ? fechaEntrega.value : '',
                    dia_entrega: diaEntrega ? diaEntrega.value.trim() : '',
                    mensaje_remision: mensajeRemision ? mensajeRemision.value.trim() : '',
                    observaciones: observacionesVenta ? observacionesVenta.value.trim() : '',
                    anticipo: anticipoVenta ? anticipoVenta.value : '0',
                    saldo_pendiente: saldoPendienteVenta ? saldoPendienteVenta.value : '0',
                    imagen_diseno_base64: imagenDisenoBase64,
                    imagen_diseno_nombre: imagenDisenoNombre
                })
            })
            .then(async res => {
                const texto = await res.text();
                try {
                    return JSON.parse(texto);
                } catch (e) {
                    throw new Error(texto || 'Respuesta inválida del servidor');
                }
            })
            .then(data => {
                if (data.status === 'success') {
                    const toast = document.getElementById('toast');
                    document.getElementById('toast-msg').innerText = data.mensaje || 'Venta registrada correctamente';
                    toast.classList.add('show');

                    if (data.ticket_url) {
                        window.open(data.ticket_url, '_blank');
                    }

                    carrito = [];
                    renderCarrito();
                    limpiarDatosCliente();
                    limpiarPersonalizacion();

                    if (fechaEntrega) fechaEntrega.value = '';
                    if (diaEntrega) diaEntrega.value = '';
                    if (mensajeRemision) mensajeRemision.value = '';
                    if (observacionesVenta) observacionesVenta.value = '';
                    if (precioManualProducto) precioManualProducto.value = '';
                    if (anticipoVenta) anticipoVenta.value = '0';
                    if (saldoPendienteVenta) saldoPendienteVenta.value = '0.00';
                    if (fechaVenta) fechaVenta.value = new Date().toISOString().split('T')[0];

                    setTimeout(() => {
                        toast.classList.remove('show');
                        window.location.reload();
                    }, 3000);
                } else {
                    alert(data.mensaje || 'Ocurrió un error al registrar la venta');
                }
            })
            .catch(error => {
                alert('Error al guardar la venta: ' + error.message);
                console.error(error);
            });
        }

        function finalizarVentaConRemisionWhatsApp() {
            finalizarVenta(false, true);
        }

        categoriaSeleccion.addEventListener('change', function() {
            actualizarTallas();
            actualizarVistaPrevia();
        });

        if (fechaEntrega) {
            fechaEntrega.addEventListener('change', actualizarDiaEntrega);
        }

        if (clienteRegistradoSelect) {
            clienteRegistradoSelect.addEventListener('change', function() {
                if (this.value.trim() !== '') cargarClienteRegistrado();
            });
        }

        aplicarModoVenta(false);
        actualizarVistaPrevia();
        actualizarSaldoPendiente();

        <?php if ($clienteNombreAuto === 'Público en general'): ?>
        aplicarModoVenta(true);
        <?php endif; ?>

        <?php if ($clienteNuevoAuto === 1 && $clienteNombreAuto !== ''): ?>
        (function() {
            const toast = document.getElementById('toast');
            document.getElementById('toast-msg').innerText = "Cliente cargado para iniciar venta";
            toast.classList.add('show');
            setTimeout(() => {
                toast.classList.remove('show');
            }, 2200);
        })();
        <?php endif; ?>
    </script>
</body>
</html>
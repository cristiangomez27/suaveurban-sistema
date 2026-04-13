<?php
session_start();
if (!isset($_SESSION['usuario_id'])) { header("Location: index.php"); exit; }
require_once 'config/database.php';
require_once 'config/functions.php';

/*
|--------------------------------------------------------------------------
| CONFIGURACIÓN VISUAL COMPLETA
|--------------------------------------------------------------------------
*/
function existeTablaVisualProductos(mysqli $conn, string $tabla): bool {
    $tabla = $conn->real_escape_string($tabla);
    $res = $conn->query("SHOW TABLES LIKE '$tabla'");
    return ($res && $res->num_rows > 0);
}

function obtenerColumnasVisualProductos(mysqli $conn, string $tabla): array {
    $columnas = [];
    if (!existeTablaVisualProductos($conn, $tabla)) return $columnas;

    $res = $conn->query("SHOW COLUMNS FROM `$tabla`");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $columnas[] = $row['Field'];
        }
    }
    return $columnas;
}

function tieneColumnaVisualProductos(array $columnas, string $columna): bool {
    return in_array($columna, $columnas, true);
}

$fondoSidebar = '';
$fondoContenido = '';
$logoActual = 'logo.png';
$transparenciaPanel = 0.32;
$transparenciaSidebar = 0.88;

$configCols = obtenerColumnasVisualProductos($conn, 'configuracion');
if (!empty($configCols)) {
    $selectConfig = [];

    foreach (['logo', 'fondo_sidebar', 'fondo_contenido', 'transparencia_panel', 'transparencia_sidebar'] as $col) {
        if (tieneColumnaVisualProductos($configCols, $col)) {
            $selectConfig[] = $col;
        }
    }

    if (!empty($selectConfig)) {
        $sqlConfig = "SELECT " . implode(', ', $selectConfig) . " FROM configuracion WHERE id = 1 LIMIT 1";
        $resConfig = $conn->query($sqlConfig);
        if ($resConfig && $resConfig->num_rows > 0) {
            $config = $resConfig->fetch_assoc();
            if (!empty($config['fondo_sidebar'])) $fondoSidebar = $config['fondo_sidebar'];
            if (!empty($config['fondo_contenido'])) $fondoContenido = $config['fondo_contenido'];
            if (!empty($config['logo'])) $logoActual = $config['logo'];
            if (isset($config['transparencia_panel'])) $transparenciaPanel = (float)$config['transparencia_panel'];
            if (isset($config['transparencia_sidebar'])) $transparenciaSidebar = (float)$config['transparencia_sidebar'];
        }
    }
}

$transparenciaPanel = max(0.10, min(0.95, $transparenciaPanel));
$transparenciaSidebar = max(0.10, min(0.98, $transparenciaSidebar));

$notificacion = "";
asegurarTablaPapelera($conn);

function existe_columna($conn, $tabla, $columna) {
    $tabla = mysqli_real_escape_string($conn, $tabla);
    $columna = mysqli_real_escape_string($conn, $columna);
    $res = $conn->query("SHOW COLUMNS FROM `$tabla` LIKE '$columna'");
    return $res && $res->num_rows > 0;
}

function insertar_catalogo_yazbek_inventario($conn) {
    $catalogo = [
        'Bebé' => [
            ['producto' => 'Playera manga corta', 'tallas' => ['2', '4', '6']],
        ],
        'Niño' => [
            ['producto' => 'Playera manga corta', 'tallas' => ['2', '4', '6', '8', '10', '12', '14']],
            ['producto' => 'Playera manga larga', 'tallas' => ['4', '6', '8', '10', '12', '14']],
            ['producto' => 'Playera Dri Fit', 'tallas' => ['4', '6', '8', '10', '12', '14']],
            ['producto' => 'Sudadera', 'tallas' => ['4', '6', '8', '10', '12', '14']],
        ],
        'Joven' => [
            ['producto' => 'Playera manga corta', 'tallas' => ['XS', 'S', 'M', 'L']],
            ['producto' => 'Playera manga larga', 'tallas' => ['XS', 'S', 'M', 'L']],
            ['producto' => 'Playera Dri Fit', 'tallas' => ['XS', 'S', 'M', 'L']],
            ['producto' => 'Sudadera', 'tallas' => ['XS', 'S', 'M', 'L']],
        ],
        'Dama' => [
            ['producto' => 'Playera manga corta silueta', 'tallas' => ['XS', 'S', 'M', 'L', 'XL', 'XXL']],
            ['producto' => 'Playera manga larga', 'tallas' => ['XS', 'S', 'M', 'L', 'XL', 'XXL']],
            ['producto' => 'Playera Dri Fit', 'tallas' => ['XS', 'S', 'M', 'L', 'XL', 'XXL']],
            ['producto' => 'Sudadera', 'tallas' => ['XS', 'S', 'M', 'L', 'XL', 'XXL']],
        ],
        'Caballero' => [
            ['producto' => 'Playera manga corta', 'tallas' => ['S', 'M', 'L', 'XL', 'XXL', '3XL']],
            ['producto' => 'Playera manga larga', 'tallas' => ['S', 'M', 'L', 'XL', 'XXL', '3XL']],
            ['producto' => 'Playera Dri Fit', 'tallas' => ['S', 'M', 'L', 'XL', 'XXL', '3XL']],
            ['producto' => 'Sudadera', 'tallas' => ['S', 'M', 'L', 'XL', 'XXL', '3XL']],
        ],
    ];

    $colores = ['Blanco', 'Negro', 'Rojo', 'Royal', 'Marino', 'Turquesa', 'Verde Lima', 'Amarillo', 'Naranja', 'Rosa', 'Fucsia', 'Gris Jaspe', 'Charcoal', 'Gold', 'Rosa claro'];

    $usaDescripcion = existe_columna($conn, 'productos', 'descripcion');
    $insertados = 0;

    foreach ($catalogo as $categoria => $productosCategoria) {
        foreach ($productosCategoria as $item) {
            foreach ($item['tallas'] as $talla) {
                foreach ($colores as $color) {
                    $nombre = $item['producto'] . ' ' . $categoria . ' ' . $talla . ' - ' . $color;
                    $nombreEsc = mysqli_real_escape_string($conn, $nombre);
                    $categoriaEsc = mysqli_real_escape_string($conn, $categoria);
                    $canalEsc = mysqli_real_escape_string($conn, 'Yazbek Base');

                    $existe = $conn->query("SELECT id FROM productos WHERE nombre = '$nombreEsc' AND categoria = '$categoriaEsc' LIMIT 1");
                    if ($existe && $existe->num_rows > 0) {
                        continue;
                    }

                    if ($usaDescripcion) {
                        $conn->query("INSERT INTO productos (nombre, categoria, precio, stock, descripcion) VALUES ('$nombreEsc', '$categoriaEsc', '0.00', '0', '$canalEsc')");
                    } else {
                        $conn->query("INSERT INTO productos (nombre, categoria, precio, stock) VALUES ('$nombreEsc', '$categoriaEsc', '0.00', '0')");
                    }

                    if ($conn->affected_rows > 0) {
                        $insertados++;
                    }
                }
            }
        }
    }

    return $insertados;
}

// ACCIÓN: CARGAR CATÁLOGO YAZBEK AL INVENTARIO
if (isset($_POST['cargar_catalogo_yazbek'])) {
    $insertados = insertar_catalogo_yazbek_inventario($conn);
    if ($insertados > 0) {
        $notificacion = "Catálogo Yazbek cargado al inventario: " . $insertados . " productos agregados";
    } else {
        $notificacion = "El catálogo Yazbek ya estaba cargado en el inventario";
    }
}

// ACCIÓN: ELIMINAR
if (isset($_GET['eliminar'])) {
    $id = intval($_GET['eliminar']);
    if ($id > 0) {
        $resProductoEliminar = $conn->query("SELECT * FROM productos WHERE id = $id LIMIT 1");
        $productoEliminar = ($resProductoEliminar && $resProductoEliminar->num_rows > 0) ? $resProductoEliminar->fetch_assoc() : null;

        if ($productoEliminar) {
            if (enviarRegistroAPapelera($conn, 'productos', $id, $productoEliminar, $_SESSION['usuario_id'] ?? null)) {
                $conn->query("DELETE FROM productos WHERE id = $id");
                $notificacion = "Producto enviado a papelera";
            } else {
                $notificacion = "No se pudo enviar el producto a papelera";
            }
        } else {
            $notificacion = "No se encontró el producto para eliminar";
        }
    }
}

// ACCIÓN: ACTUALIZAR PRECIO/STOCK DESDE TABLA
if (isset($_POST['actualizar'])) {
    $id = $_POST['id'];
    $precio = $_POST['precio'];
    $stock = $_POST['stock'];
    $conn->query("UPDATE productos SET precio='$precio', stock='$stock' WHERE id='$id'");
    $notificacion = "Cambios guardados correctamente";
}

// ACTUALIZACIÓN MASIVA POR PALABRA CLAVE
if (isset($_POST['actualizar_masivo'])) {
    $palabra_clave = trim($_POST['palabra_clave'] ?? '');
    $precio_masivo = trim($_POST['precio_masivo'] ?? '');
    $stock_masivo = trim($_POST['stock_masivo'] ?? '');

    if ($palabra_clave === '') {
        $notificacion = "Escribe una palabra clave para actualizar en lote";
    } else {
        $palabra_clave_sql = mysqli_real_escape_string($conn, $palabra_clave);
        $camposActualizar = [];

        if ($precio_masivo !== '') {
            $precio_masivo = floatval($precio_masivo);
            $camposActualizar[] = "precio = '$precio_masivo'";
        }

        if ($stock_masivo !== '') {
            $stock_masivo = intval($stock_masivo);
            $camposActualizar[] = "stock = '$stock_masivo'";
        }

        if (empty($camposActualizar)) {
            $notificacion = "Debes capturar precio o stock para actualizar";
        } else {
            $sqlUpdateMasivo = "UPDATE productos SET " . implode(', ', $camposActualizar) . " WHERE nombre LIKE '%$palabra_clave_sql%'";

            if ($conn->query($sqlUpdateMasivo)) {
                if ($conn->affected_rows > 0) {
                    $notificacion = "Actualización masiva aplicada a " . $conn->affected_rows . " productos";
                } else {
                    $notificacion = "No se encontraron productos con esa palabra clave";
                }
            } else {
                $notificacion = "Error al actualizar en lote: " . $conn->error;
            }
        }
    }
}

// ACTUALIZACIÓN MASIVA POR CATEGORÍA
if (isset($_POST['actualizar_categoria'])) {
    $categoria_masiva = trim($_POST['categoria_masiva'] ?? '');
    $precio_categoria = trim($_POST['precio_categoria'] ?? '');
    $stock_categoria = trim($_POST['stock_categoria'] ?? '');

    if ($categoria_masiva === '') {
        $notificacion = "Debes seleccionar una categoría";
    } else {
        $categoria_sql = mysqli_real_escape_string($conn, $categoria_masiva);
        $camposActualizar = [];

        if ($precio_categoria !== '') {
            $precio_categoria = floatval($precio_categoria);
            $camposActualizar[] = "precio = '$precio_categoria'";
        }

        if ($stock_categoria !== '') {
            $stock_categoria = intval($stock_categoria);
            $camposActualizar[] = "stock = '$stock_categoria'";
        }

        if (empty($camposActualizar)) {
            $notificacion = "Debes capturar precio o stock para actualizar la categoría";
        } else {
            $sqlUpdateCategoria = "UPDATE productos SET " . implode(', ', $camposActualizar) . " WHERE categoria = '$categoria_sql'";

            if ($conn->query($sqlUpdateCategoria)) {
                if ($conn->affected_rows > 0) {
                    $notificacion = "Actualización por categoría aplicada a " . $conn->affected_rows . " productos";
                } else {
                    $notificacion = "No se encontraron productos en esa categoría";
                }
            } else {
                $notificacion = "Error al actualizar categoría: " . $conn->error;
            }
        }
    }
}

// ACCIÓN: AGREGAR NUEVO
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['nuevo_producto'])) {
    $nombre = mysqli_real_escape_string($conn, $_POST['nombre']);
    $categoria = $_POST['categoria'];
    $precio = $_POST['precio'];
    $stock = $_POST['stock'];
    $canal = $_POST['canal'];
    $conn->query("INSERT INTO productos (nombre, categoria, precio, stock, descripcion) VALUES ('$nombre', '$categoria', '$precio', '$stock', '$canal')");
    $notificacion = "¡Producto registrado con éxito!";
}

$productos = $conn->query("SELECT * FROM productos ORDER BY categoria ASC, nombre ASC");
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventario Yazbek - Suave Urban Studio</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        *{ box-sizing:border-box; }

        :root{
            --gold:#c89b3c;
            --gold-glow:rgba(200,155,60,0.4);
            --bg:#050505;
            --glass-border:rgba(200,155,60,0.15);
            --text-muted:#888;
            --shadow-gold:0 0 20px rgba(200,155,60,0.16);
        }

        html, body{
            margin:0;
            padding:0;
            min-height:100%;
        }

        @keyframes fadeIn{
            from{opacity:0;transform:translateY(15px);}
            to{opacity:1;transform:translateY(0);}
        }

        @keyframes logoPulse{
            0%,100%{transform:scale(1);}
            50%{transform:scale(1.05);}
        }

        @keyframes glow{
            from{filter:drop-shadow(0 0 5px rgba(200,155,60,0.4));}
            to{filter:drop-shadow(0 0 15px rgba(200,155,60,0.7));}
        }

        body{
            font-family:'Segoe UI',sans-serif;
            color:white;
            display:flex;
            min-height:100vh;
            background:
                <?php echo !empty($fondoContenido)
                    ? "linear-gradient(rgba(0,0,0,0.45), rgba(0,0,0,0.60)), url('" . htmlspecialchars($fondoContenido, ENT_QUOTES, 'UTF-8') . "') center/cover fixed no-repeat"
                    : "radial-gradient(circle at top right, rgba(200,155,60,0.10), transparent 25%), #050505"; ?>;
            overflow-x:hidden;
            position:relative;
        }

        body::before{
            content:"";
            position:fixed;
            inset:0;
            background:rgba(0,0,0,0.28);
            z-index:-1;
        }

        .mobile-topbar{ display:none; }
        .mobile-menu-toggle{ display:none; }
        .sidebar-overlay{ display:none; }

        .sidebar{
            width:85px;
            background:
                <?php echo !empty($fondoSidebar)
                    ? "linear-gradient(rgba(0,0,0," . $transparenciaSidebar . "), rgba(0,0,0," . $transparenciaSidebar . ")), url('" . htmlspecialchars($fondoSidebar, ENT_QUOTES, 'UTF-8') . "') center/cover no-repeat"
                    : "rgba(0,0,0," . $transparenciaSidebar . ")"; ?>;
            backdrop-filter:blur(15px);
            -webkit-backdrop-filter:blur(15px);
            border-right:1px solid var(--glass-border);
            display:flex;
            flex-direction:column;
            align-items:center;
            padding:15px 0;
            z-index:1000;
            position:fixed;
            top:0;
            left:0;
            height:100vh;
            overflow-y:auto;
            box-shadow:0 10px 40px rgba(0,0,0,0.35);
        }

        .logo-pos{
            width:55px;
            height:auto;
            margin-bottom:20px;
            animation:logoPulse 4s infinite, glow 3s infinite alternate;
        }

        .nav-controls{
            display:flex;
            flex-direction:column;
            gap:20px;
            margin-bottom:30px;
            border-bottom:1px solid var(--glass-border);
            padding-bottom:20px;
            width:100%;
            align-items:center;
        }

        .sidebar a{
            color:#555;
            font-size:22px;
            transition:0.3s;
            text-decoration:none;
            margin-bottom:18px;
        }

        .sidebar a:hover,
        .sidebar a.active{
            color:var(--gold);
            filter:drop-shadow(0 0 8px var(--gold));
        }

        .main{
            flex:1;
            margin-left:85px;
            padding:40px;
            min-width:0;
            width:calc(100% - 85px);
            animation:fadeIn 0.6s ease-out;
        }

        .layout{
            display:grid;
            grid-template-columns:350px 1fr;
            gap:30px;
            align-items:start;
        }

        .card{
            background:rgba(15,15,15,<?php echo $transparenciaPanel; ?>);
            backdrop-filter:blur(12px);
            -webkit-backdrop-filter:blur(12px);
            border:1px solid var(--glass-border);
            border-radius:20px;
            padding:25px;
            box-shadow:0 10px 40px rgba(0,0,0,0.5), 0 0 20px rgba(200,155,60,0.08);
            overflow:hidden;
            position:relative;
        }

        .card::before{
            content:"";
            position:absolute;
            inset:0;
            background:linear-gradient(135deg, rgba(255,255,255,0.04), transparent 35%, transparent 70%, rgba(200,155,60,0.04));
            pointer-events:none;
        }

        .card > *{ position:relative; z-index:1; }

        input, select{
            background:rgba(255,255,255,0.04);
            border:1px solid rgba(255,255,255,0.1);
            color:white;
            padding:12px;
            border-radius:12px;
            width:100%;
            margin-bottom:15px;
            box-sizing:border-box;
            transition:0.3s;
        }

        input:focus, select:focus{
            border-color:var(--gold);
            background:rgba(255,255,255,0.08);
            outline:none;
            box-shadow:0 0 15px rgba(200,155,60,0.2);
        }

        select option{
            background:#111;
            color:#fff;
        }

        .btn-add{
            background:linear-gradient(45deg, #c89b3c, #eec064);
            color:black;
            border:none;
            padding:15px;
            border-radius:12px;
            font-weight:800;
            width:100%;
            cursor:pointer;
            transition:0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            text-transform:uppercase;
            letter-spacing:0.8px;
        }

        .btn-add:hover{
            transform:scale(1.02) translateY(-2px);
            box-shadow:0 8px 20px var(--gold-glow);
        }

        .table-wrap{
            width:100%;
            overflow-x:auto;
            -webkit-overflow-scrolling:touch;
        }

        table{
            width:100%;
            border-collapse:collapse;
            min-width:720px;
        }

        th{
            text-align:left;
            padding:12px;
            color:var(--gold);
            font-size:11px;
            border-bottom:1px solid var(--glass-border);
            letter-spacing:1px;
            white-space:nowrap;
            text-transform:uppercase;
        }

        td{
            padding:12px;
            border-bottom:1px solid rgba(255,255,255,0.03);
            font-size:13.5px;
            vertical-align:middle;
        }

        tr:hover{
            background:rgba(200,155,60,0.04);
        }

        .input-edit{
            background:rgba(255,255,255,0.08);
            border:1px solid rgba(255,255,255,0.14);
            color:#fff;
            width:75px;
            padding:8px 6px;
            border-radius:8px;
            text-align:center;
            font-weight:bold;
            margin-bottom:0;
        }

        .btn-save{
            background:none;
            border:none;
            color:var(--gold);
            cursor:pointer;
            font-size:18px;
            transition:0.2s;
        }

        .btn-save:hover{
            transform:scale(1.2);
        }

        .btn-del{
            color:#ff4d4d;
            margin-left:15px;
            text-decoration:none;
            font-size:16px;
            opacity:0.8;
            transition:0.2s;
        }

        .btn-del:hover{
            opacity:1;
            filter:drop-shadow(0 0 6px rgba(255,77,77,0.4));
        }

        .tag-canal{
            font-size:9px;
            padding:3px 7px;
            background:rgba(255,255,255,0.05);
            border:1px solid rgba(255,255,255,0.10);
            border-radius:10px;
            color:#aaa;
            text-transform:uppercase;
            display:inline-block;
            margin-top:6px;
            max-width:100%;
            word-break:break-word;
        }

        #toast{
            position:fixed;
            top:20px;
            right:20px;
            background:linear-gradient(45deg, #c89b3c, #eec064);
            color:black;
            padding:16px 30px;
            border-radius:14px;
            transform:translateX(150%);
            transition:0.5s;
            font-weight:bold;
            z-index:1200;
            box-shadow:0 10px 30px rgba(0,0,0,0.5);
            max-width:calc(100vw - 40px);
        }

        #toast.show{
            transform:translateX(0);
        }

        .ref-grid{
            display:grid;
            grid-template-columns:repeat(auto-fit, minmax(220px, 1fr));
            gap:20px;
        }

        .colores-grid{
            display:grid;
            grid-template-columns:repeat(auto-fit, minmax(130px, 1fr));
            gap:10px;
        }

        .mini-card{
            background:rgba(255,255,255,0.03);
            border:1px solid var(--glass-border);
            border-radius:14px;
            padding:15px;
        }

        @media (max-width: 1100px){
            .layout{
                grid-template-columns:1fr;
            }
        }

        @media (max-width: 900px){
            body{
                display:block;
            }

            .mobile-topbar{
                display:flex;
                align-items:center;
                justify-content:space-between;
                gap:12px;
                position:sticky;
                top:0;
                z-index:1100;
                padding:14px 16px;
                background:rgba(0,0,0,0.92);
                border-bottom:1px solid var(--glass-border);
                backdrop-filter:blur(10px);
                -webkit-backdrop-filter:blur(10px);
            }

            .mobile-topbar-left{
                display:flex;
                align-items:center;
                gap:10px;
                min-width:0;
            }

            .mobile-topbar-logo{
                width:38px;
                height:38px;
                object-fit:contain;
            }

            .mobile-topbar-title{
                font-size:14px;
                font-weight:700;
                color:#fff;
                white-space:nowrap;
                overflow:hidden;
                text-overflow:ellipsis;
            }

            .mobile-menu-toggle{
                display:inline-flex;
                align-items:center;
                justify-content:center;
                width:42px;
                height:42px;
                border:1px solid rgba(255,255,255,0.08);
                background:rgba(255,255,255,0.06);
                color:var(--gold);
                border-radius:12px;
                font-size:18px;
                cursor:pointer;
            }

            .sidebar-overlay{
                display:block;
                position:fixed;
                inset:0;
                background:rgba(0,0,0,0.45);
                opacity:0;
                visibility:hidden;
                transition:0.3s ease;
                z-index:999;
            }

            .sidebar{
                width:280px;
                max-width:82vw;
                transform:translateX(-100%);
                transition:transform 0.3s ease;
            }

            body.menu-open .sidebar{
                transform:translateX(0);
            }

            body.menu-open .sidebar-overlay{
                opacity:1;
                visibility:visible;
            }

            .main{
                margin-left:0;
                padding:20px 16px;
                width:100%;
            }

            #toast{
                right:12px;
                left:12px;
                top:76px;
                max-width:none;
                padding:14px 16px;
                transform:translateY(-20px);
                opacity:0;
            }

            #toast.show{
                transform:translateY(0);
                opacity:1;
            }
        }

        @media (max-width: 640px){
            .main{
                padding:16px 12px;
            }

            .card{
                padding:16px;
                border-radius:16px;
            }

            h1{
                font-size:26px;
                line-height:1.2;
            }

            .ref-grid{
                grid-template-columns:1fr;
                gap:14px;
            }

            .colores-grid{
                grid-template-columns:repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 480px){
            .main{
                padding:14px 10px;
            }

            .card{
                padding:14px;
            }

            .mobile-topbar-title{
                font-size:13px;
            }

            .colores-grid{
                grid-template-columns:1fr 1fr;
            }
        }
    </style>
</head>
<body>

    <div class="mobile-topbar">
        <div class="mobile-topbar-left">
            <img src="<?php echo $logoActual; ?>" alt="Logo" class="mobile-topbar-logo">
            <div class="mobile-topbar-title">Inventario</div>
        </div>
        <button class="mobile-menu-toggle" id="mobileMenuToggle" aria-label="Abrir menú">
            <i class="fas fa-bars"></i>
        </button>
    </div>

    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <div id="toast">✔ <span id="toast-msg"></span></div>

    <div class="sidebar" id="sidebar">
        <img src="<?php echo $logoActual; ?>" alt="Logo" class="logo-pos">

        <div class="nav-controls">
            <a href="dashboard.php" title="Inicio"><i class="fas fa-th-large"></i></a>
            <a href="ventas.php" title="Nueva Venta"><i class="fas fa-shopping-cart"></i></a>
            <a href="productos.php" class="active" title="Inventario"><i class="fas fa-boxes"></i></a>
            <a href="configuracion.php" title="Ajustes"><i class="fas fa-sliders-h"></i></a>
        </div>
    </div>

    <div class="main">
        <h1 style="font-weight:300; margin-bottom:5px;">Inventario <span style="color:var(--gold); font-weight:800;">Maestro</span></h1>
        <p style="color:#aaa; margin-bottom:30px; font-size:14px;">Gestión de stock y precios de Suave Urban Studio</p>

        <div class="layout">

            <div class="card">
                <h3 style="color:var(--gold); margin-top:0; font-weight:500;">Nuevo Producto</h3>
                <form method="POST">
                    <label style="font-size:11px; color:#777; text-transform:uppercase; letter-spacing:1px;">Nombre / Talla / Color</label>
                    <input type="text" name="nombre" placeholder="Ej: Playera Dama M - Turquesa" required>

                    <label style="font-size:11px; color:#777; text-transform:uppercase; letter-spacing:1px;">Categoría</label>
                    <select name="categoria">
                        <option value="Bebé">Bebé (Toddler)</option>
                        <option value="Niño">Niño</option>
                        <option value="Joven">Joven (XS-L)</option>
                        <option value="Dama">Dama (Silueta)</option>
                        <option value="Caballero">Caballero</option>
                        <option value="Sudaderas">Sudaderas</option>
                        <option value="Tazas">Tazas Personalizadas</option>
                        <option value="Servicios">Servicios (DTF/Bordado)</option>
                    </select>

                    <label style="font-size:11px; color:#777; text-transform:uppercase; letter-spacing:1px;">Canal de Venta</label>
                    <select name="canal">
                        <option value="Ambos">Ambos Canales</option>
                        <option value="Boutique">Solo Boutique</option>
                        <option value="TikTok">Solo TikTok Shop</option>
                    </select>

                    <div style="display:flex; gap:15px; flex-wrap:wrap;">
                        <div style="flex:1; min-width:140px;">
                            <label style="font-size:11px; color:#777; text-transform:uppercase; letter-spacing:1px;">Precio Venta</label>
                            <input type="number" step="0.01" name="precio" placeholder="0.00" required>
                        </div>
                        <div style="flex:1; min-width:140px;">
                            <label style="font-size:11px; color:#777; text-transform:uppercase; letter-spacing:1px;">Stock Inicial</label>
                            <input type="number" name="stock" placeholder="0" required>
                        </div>
                    </div>

                    <button type="submit" name="nuevo_producto" class="btn-add">Registrar Item</button>
                </form>

                <div style="margin-top:20px; border-top:1px solid rgba(255,255,255,0.1); padding-top:20px;">
                    <h3 style="color:var(--gold); font-weight:500; margin:0 0 12px 0;">Actualizar productos en lote</h3>

                    <form method="POST">
                        <label style="font-size:11px; color:#777; text-transform:uppercase; letter-spacing:1px;">Palabra clave</label>
                        <input type="text" name="palabra_clave" placeholder="Ej: Playera Bebé">

                        <div style="display:flex; gap:10px; flex-wrap:wrap;">
                            <div style="flex:1; min-width:140px;">
                                <label style="font-size:11px; color:#777; text-transform:uppercase; letter-spacing:1px;">Nuevo precio</label>
                                <input type="number" step="0.01" name="precio_masivo" placeholder="Opcional">
                            </div>

                            <div style="flex:1; min-width:140px;">
                                <label style="font-size:11px; color:#777; text-transform:uppercase; letter-spacing:1px;">Nuevo stock</label>
                                <input type="number" name="stock_masivo" placeholder="Opcional">
                            </div>
                        </div>

                        <button type="submit" name="actualizar_masivo" class="btn-add" style="margin-top:10px;">
                            <i class="fas fa-layer-group" style="margin-right:8px;"></i>Actualizar en lote
                        </button>
                    </form>

                    <p style="font-size:12px; color:#777; margin-top:10px;">
                        Ejemplo: escribir <b>Playera Bebé</b> actualizará todas sus tallas y colores.
                    </p>
                </div>

                <div style="margin-top:20px; border-top:1px solid rgba(255,255,255,0.1); padding-top:20px;">
                    <h3 style="color:var(--gold); font-weight:500; margin:0 0 12px 0;">Actualizar por categoría</h3>

                    <form method="POST">
                        <label style="font-size:11px; color:#777; text-transform:uppercase; letter-spacing:1px;">Categoría</label>
                        <select name="categoria_masiva">
                            <option value="">Selecciona una categoría</option>
                            <option value="Bebé">Bebé</option>
                            <option value="Niño">Niño</option>
                            <option value="Joven">Joven</option>
                            <option value="Dama">Dama</option>
                            <option value="Caballero">Caballero</option>
                            <option value="Sudaderas">Sudaderas</option>
                            <option value="Tazas">Tazas</option>
                            <option value="Servicios">Servicios</option>
                        </select>

                        <div style="display:flex; gap:10px; flex-wrap:wrap;">
                            <div style="flex:1; min-width:140px;">
                                <label style="font-size:11px; color:#777; text-transform:uppercase; letter-spacing:1px;">Nuevo precio</label>
                                <input type="number" step="0.01" name="precio_categoria" placeholder="Opcional">
                            </div>

                            <div style="flex:1; min-width:140px;">
                                <label style="font-size:11px; color:#777; text-transform:uppercase; letter-spacing:1px;">Nuevo stock</label>
                                <input type="number" name="stock_categoria" placeholder="Opcional">
                            </div>
                        </div>

                        <button type="submit" name="actualizar_categoria" class="btn-add" style="margin-top:10px;">
                            <i class="fas fa-tags" style="margin-right:8px;"></i>Actualizar categoría
                        </button>
                    </form>

                    <p style="font-size:12px; color:#777; margin-top:10px;">
                        Ejemplo: selecciona <b>Dama</b> y actualiza de una sola vez todos los productos de esa categoría.
                    </p>
                </div>

                <div style="margin-top:18px; padding-top:18px; border-top:1px solid rgba(255,255,255,0.08);">
                    <form method="POST" onsubmit="return confirm('¿Cargar catálogo base Yazbek al inventario? Solo agrega los que no existan.');">
                        <button type="submit" name="cargar_catalogo_yazbek" class="btn-add" style="background:rgba(255,255,255,0.04); color:var(--gold); border:1px solid var(--glass-border);">
                            <i class="fas fa-download" style="margin-right:8px;"></i>Cargar catálogo Yazbek al inventario
                        </button>
                    </form>
                    <div style="font-size:12px; color:#777; margin-top:10px; line-height:1.5;">
                        Agrega automáticamente al inventario los productos base de Bebé, Niño, Joven, Dama y Caballero con precio 0 y stock 0 para que ya solo modifiques precio y existencia.
                    </div>
                </div>
            </div>

            <div class="card">
                <h3 style="margin-top:0; font-weight:500;">Existencias en Almacén</h3>
                <div style="font-size:12px; color:#777; margin-bottom:15px;">Los productos Yazbek base se cargan con precio 0.00 y stock 0 para que solo edites lo necesario.</div>

                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Producto</th>
                                <th>Categoría</th>
                                <th>Precio</th>
                                <th>Stock</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while($row = $productos->fetch_assoc()): ?>
                            <tr>
                                <form method="POST">
                                    <input type="hidden" name="id" value="<?php echo $row['id']; ?>">
                                    <td>
                                        <strong><?php echo $row['nombre']; ?></strong><br>
                                        <span class="tag-canal"><?php echo $row['descripcion']; ?></span>
                                    </td>
                                    <td><span style="color:#aaa; font-size:12px;"><?php echo $row['categoria']; ?></span></td>
                                    <td><input type="number" step="0.01" name="precio" value="<?php echo $row['precio']; ?>" class="input-edit"></td>
                                    <td><input type="number" name="stock" value="<?php echo $row['stock']; ?>" class="input-edit" style="width:55px;"></td>
                                    <td style="text-align:right; white-space:nowrap;">
                                        <button type="submit" name="actualizar" class="btn-save"><i class="fas fa-check-circle"></i></button>
                                        <a href="productos.php?eliminar=<?php echo $row['id']; ?>" class="btn-del" onclick="return confirm('¿Eliminar producto?')"><i class="fas fa-trash"></i></a>
                                    </td>
                                </form>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>

        <div class="card" style="margin-top:30px;">
            <h3 style="color:var(--gold); margin-top:0; font-weight:500;">Catálogo Base Yazbek (Referencia)</h3>
            <div style="font-size:13px; color:#aaa; margin-bottom:20px;">
                Información organizada para registrar productos correctamente en el inventario sin borrar nada de tu archivo original.
            </div>

            <div class="ref-grid">
                <div class="mini-card">
                    <h4 style="color:var(--gold); margin-top:0;">Bebé</h4>
                    <p style="margin:6px 0;"><strong>Productos:</strong> Playera manga corta</p>
                    <p style="margin:6px 0;"><strong>Tallas:</strong> 2, 4, 6</p>
                    <p style="margin:6px 0;"><strong>Colores base:</strong> Blanco, Negro, Rojo, Royal, Rosa claro</p>
                </div>

                <div class="mini-card">
                    <h4 style="color:var(--gold); margin-top:0;">Niño</h4>
                    <p style="margin:6px 0;"><strong>Productos:</strong> Playera manga corta, Playera manga larga, Playera Dri Fit, Sudadera</p>
                    <p style="margin:6px 0;"><strong>Tallas:</strong> 2, 4, 6, 8, 10, 12, 14</p>
                </div>

                <div class="mini-card">
                    <h4 style="color:var(--gold); margin-top:0;">Joven</h4>
                    <p style="margin:6px 0;"><strong>Productos:</strong> Playera manga corta, Playera manga larga, Playera Dri Fit, Sudadera</p>
                    <p style="margin:6px 0;"><strong>Tallas:</strong> XS, S, M, L</p>
                </div>

                <div class="mini-card">
                    <h4 style="color:var(--gold); margin-top:0;">Dama</h4>
                    <p style="margin:6px 0;"><strong>Productos:</strong> Playera manga corta silueta, Playera manga larga, Playera Dri Fit, Sudadera</p>
                    <p style="margin:6px 0;"><strong>Tallas:</strong> XS, S, M, L, XL, XXL</p>
                </div>

                <div class="mini-card">
                    <h4 style="color:var(--gold); margin-top:0;">Caballero</h4>
                    <p style="margin:6px 0;"><strong>Productos:</strong> Playera manga corta, Playera manga larga, Playera Dri Fit, Sudadera</p>
                    <p style="margin:6px 0;"><strong>Tallas:</strong> S, M, L, XL, XXL, 3XL</p>
                </div>
            </div>

            <div style="margin-top:25px;">
                <h4 style="color:var(--gold); margin-bottom:12px;">Colores base Yazbek para usar en todo el sistema</h4>
                <div class="colores-grid">
                    <div class="mini-card">Blanco</div>
                    <div class="mini-card">Negro</div>
                    <div class="mini-card">Rojo</div>
                    <div class="mini-card">Royal</div>
                    <div class="mini-card">Marino</div>
                    <div class="mini-card">Turquesa</div>
                    <div class="mini-card">Verde Lima</div>
                    <div class="mini-card">Amarillo</div>
                    <div class="mini-card">Naranja</div>
                    <div class="mini-card">Rosa</div>
                    <div class="mini-card">Fucsia</div>
                    <div class="mini-card">Gris Jaspe</div>
                    <div class="mini-card">Charcoal</div>
                    <div class="mini-card">Gold</div>
                </div>
            </div>

            <div style="margin-top:25px; background:rgba(200,155,60,0.08); border:1px solid rgba(200,155,60,0.2); border-radius:12px; padding:15px;">
                <h4 style="color:var(--gold); margin-top:0;">Ejemplos para registrar productos</h4>
                <div style="display:grid; gap:8px; font-size:13px; color:#ddd;">
                    <div>Playera Niño 8 - Rojo</div>
                    <div>Playera Dama M - Negro</div>
                    <div>Sudadera Caballero L - Gris Jaspe</div>
                    <div>Playera Dri Fit Joven S - Royal</div>
                </div>
            </div>
        </div>
    </div>

    <script>
        const body = document.body;
        const mobileMenuToggle = document.getElementById('mobileMenuToggle');
        const sidebarOverlay = document.getElementById('sidebarOverlay');

        function abrirMenu() {
            body.classList.add('menu-open');
        }

        function cerrarMenu() {
            body.classList.remove('menu-open');
        }

        if (mobileMenuToggle) {
            mobileMenuToggle.addEventListener('click', function () {
                if (body.classList.contains('menu-open')) {
                    cerrarMenu();
                } else {
                    abrirMenu();
                }
            });
        }

        if (sidebarOverlay) {
            sidebarOverlay.addEventListener('click', cerrarMenu);
        }

        document.querySelectorAll('.sidebar a').forEach(link => {
            link.addEventListener('click', function () {
                if (window.innerWidth <= 900) {
                    cerrarMenu();
                }
            });
        });

        window.addEventListener('resize', function () {
            if (window.innerWidth > 900) {
                cerrarMenu();
            }
        });

        <?php if($notificacion != ""): ?>
            const toast = document.getElementById('toast');
            document.getElementById('toast-msg').innerText = <?php echo json_encode($notificacion); ?>;
            toast.classList.add('show');
            setTimeout(() => { toast.classList.remove('show'); }, 3000);
        <?php endif; ?>
    </script>

</body>
</html>

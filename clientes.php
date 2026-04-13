<?php
session_start();
if (!isset($_SESSION['usuario_id'])) { header("Location: index.php"); exit; }
require_once 'config/database.php';
require_once 'config/functions.php';

/*
|--------------------------------------------------------------------------
| CARGAR CONFIGURACIÓN VISUAL COMPLETA
|--------------------------------------------------------------------------
*/
function existeTablaVisual(mysqli $conn, string $tabla): bool {
    $tabla = $conn->real_escape_string($tabla);
    $res = $conn->query("SHOW TABLES LIKE '$tabla'");
    return ($res && $res->num_rows > 0);
}

function obtenerColumnasVisual(mysqli $conn, string $tabla): array {
    $columnas = [];
    if (!existeTablaVisual($conn, $tabla)) return $columnas;

    $res = $conn->query("SHOW COLUMNS FROM `$tabla`");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $columnas[] = $row['Field'];
        }
    }
    return $columnas;
}

function tieneColumnaVisual(array $columnas, string $columna): bool {
    return in_array($columna, $columnas, true);
}

$fondoSidebar = '';
$fondoContenido = '';
$logoActual = 'logo.png';
$transparenciaPanel = 0.32;
$transparenciaSidebar = 0.88;

$configCols = obtenerColumnasVisual($conn, 'configuracion');
if (!empty($configCols)) {
    $selectConfig = [];

    foreach (['logo', 'fondo_sidebar', 'fondo_contenido', 'transparencia_panel', 'transparencia_sidebar'] as $col) {
        if (tieneColumnaVisual($configCols, $col)) {
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

/*
|--------------------------------------------------------------------------
| ASEGURAR COLUMNA tipo_cliente
|--------------------------------------------------------------------------
*/
$clientesColsSistema = obtenerColumnasVisual($conn, 'clientes');
if (!empty($clientesColsSistema) && !tieneColumnaVisual($clientesColsSistema, 'tipo_cliente')) {
    $conn->query("ALTER TABLE clientes ADD COLUMN tipo_cliente VARCHAR(30) NOT NULL DEFAULT 'Personalizado' AFTER email");
    $clientesColsSistema = obtenerColumnasVisual($conn, 'clientes');
}

/*
|--------------------------------------------------------------------------
| FUNCIÓN PARA VALIDAR ADMINISTRADOR
|--------------------------------------------------------------------------
*/
function usuario_es_admin_clientes($conn) {
    if (isset($_SESSION['is_admin']) && (int)$_SESSION['is_admin'] === 1) {
        return true;
    }

    $rolActual = '';

    $camposPosibles = ['rol', 'usuario_rol', 'tipo_usuario', 'perfil', 'puesto', 'cargo', 'usuario'];
    foreach ($camposPosibles as $campo) {
        if (!empty($_SESSION[$campo])) {
            $valor = trim((string)$_SESSION[$campo]);
            $valor = function_exists('mb_strtolower') ? mb_strtolower($valor) : strtolower($valor);
            $rolActual = $valor;
            break;
        }
    }

    $usuarioSesionId = (int)($_SESSION['usuario_id'] ?? 0);
    if ($usuarioSesionId > 0) {
        $resRolUsuario = $conn->query("SELECT rol FROM usuarios WHERE id = $usuarioSesionId LIMIT 1");
        if ($resRolUsuario && $filaRolUsuario = $resRolUsuario->fetch_assoc()) {
            if (!empty($filaRolUsuario['rol'])) {
                $rolActual = trim((string)$filaRolUsuario['rol']);
                $rolActual = function_exists('mb_strtolower') ? mb_strtolower($rolActual) : strtolower($rolActual);
            }
        }
    }

    $rolActual = str_replace('_', ' ', $rolActual);

    $adminsValidos = [
        'admin',
        'administrador',
        'administrator',
        'root',
        'superadmin',
        'administrador general',
        'administrador genera'
    ];

    return in_array($rolActual, $adminsValidos, true);
}

$esAdmin = usuario_es_admin_clientes($conn);
asegurarTablaPapelera($conn);

/*
|--------------------------------------------------------------------------
| LÓGICA DE REGISTRO RÁPIDO
|--------------------------------------------------------------------------
*/
$notificacion = "";
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['btn_guardar'])) {
    $nombre = trim($_POST['nombre'] ?? '');
    $telefono = trim($_POST['telefono'] ?? '');
    $direccion = trim($_POST['direccion'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $tipoCliente = trim($_POST['tipo_cliente'] ?? 'Personalizado');

    if (!in_array($tipoCliente, ['Personalizado', 'DTF'], true)) {
        $tipoCliente = 'Personalizado';
    }

    if ($nombre === '' || $telefono === '') {
        $notificacion = "Debes capturar nombre y teléfono.";
    } else {
        if (tieneColumnaVisual($clientesColsSistema, 'tipo_cliente')) {
            $stmt = $conn->prepare("INSERT INTO clientes (nombre, telefono, direccion, email, tipo_cliente) VALUES (?, ?, ?, ?, ?)");
            if ($stmt) {
                $stmt->bind_param("sssss", $nombre, $telefono, $direccion, $email, $tipoCliente);

                if ($stmt->execute()) {
                    $clienteIdNuevo = $stmt->insert_id;
                    $stmt->close();

                    header("Location: ventas.php?cliente_id=" . urlencode($clienteIdNuevo) . "&cliente=" . urlencode($nombre) . "&tel=" . urlencode($telefono) . "&direccion=" . urlencode($direccion) . "&email=" . urlencode($email) . "&tipo_cliente=" . urlencode($tipoCliente) . "&cliente_nuevo=1");
                    exit;
                } else {
                    $notificacion = "Error al registrar cliente: " . $conn->error;
                    $stmt->close();
                }
            } else {
                $notificacion = "Error al preparar el registro del cliente.";
            }
        } else {
            $stmt = $conn->prepare("INSERT INTO clientes (nombre, telefono, direccion, email) VALUES (?, ?, ?, ?)");
            if ($stmt) {
                $stmt->bind_param("ssss", $nombre, $telefono, $direccion, $email);

                if ($stmt->execute()) {
                    $clienteIdNuevo = $stmt->insert_id;
                    $stmt->close();

                    header("Location: ventas.php?cliente_id=" . urlencode($clienteIdNuevo) . "&cliente=" . urlencode($nombre) . "&tel=" . urlencode($telefono) . "&direccion=" . urlencode($direccion) . "&email=" . urlencode($email) . "&tipo_cliente=" . urlencode($tipoCliente) . "&cliente_nuevo=1");
                    exit;
                } else {
                    $notificacion = "Error al registrar cliente: " . $conn->error;
                    $stmt->close();
                }
            } else {
                $notificacion = "Error al preparar el registro del cliente.";
            }
        }
    }
}

/*
|--------------------------------------------------------------------------
| ELIMINAR CLIENTE SOLO ADMIN
|--------------------------------------------------------------------------
*/
if (isset($_GET['eliminar'])) {
    if (!$esAdmin) {
        $notificacion = "No tienes permisos para eliminar clientes.";
    } else {
        $idEliminar = intval($_GET['eliminar']);

        if ($idEliminar > 0) {
            $clienteEliminar = null;

            $stmtLeer = $conn->prepare("SELECT * FROM clientes WHERE id = ? LIMIT 1");
            if ($stmtLeer) {
                $stmtLeer->bind_param("i", $idEliminar);
                $stmtLeer->execute();

                if (method_exists($stmtLeer, 'get_result')) {
                    $resCliente = $stmtLeer->get_result();
                    if ($resCliente) {
                        $clienteEliminar = $resCliente->fetch_assoc();
                    }
                }

                $stmtLeer->close();
            }

            if (!$clienteEliminar) {
                $resultadoCliente = $conn->query("SELECT * FROM clientes WHERE id = " . $idEliminar . " LIMIT 1");
                $clienteEliminar = ($resultadoCliente && $resultadoCliente->num_rows > 0) ? $resultadoCliente->fetch_assoc() : null;
            }

            if ($clienteEliminar) {
                if (enviarRegistroAPapelera($conn, 'clientes', $idEliminar, $clienteEliminar, $_SESSION['usuario_id'] ?? null)) {
                    $stmtDelete = $conn->prepare("DELETE FROM clientes WHERE id = ?");
                    if ($stmtDelete) {
                        $stmtDelete->bind_param("i", $idEliminar);

                        if ($stmtDelete->execute()) {
                            if ($stmtDelete->affected_rows > 0) {
                                $notificacion = "Cliente enviado a papelera correctamente.";
                            } else {
                                $notificacion = "No se encontró el cliente para eliminar.";
                            }
                        } else {
                            $notificacion = "Error al eliminar cliente: " . $conn->error;
                        }

                        $stmtDelete->close();
                    } else {
                        $notificacion = "No se pudo preparar la eliminación del cliente.";
                    }
                } else {
                    $notificacion = "No se pudo enviar el cliente a papelera.";
                }
            } else {
                $notificacion = "No se encontró el cliente para eliminar.";
            }
        } else {
            $notificacion = "ID de cliente no válido.";
        }
    }
}

$clientes = $conn->query("SELECT * FROM clientes ORDER BY id DESC");
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Clientes PRO - Suave Urban Studio</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root { 
            --gold: #c89b3c; 
            --gold-glow: rgba(200, 155, 60, 0.4);
            --bg: #050505; 
            --glass-border: rgba(200, 155, 60, 0.15);
            --text-muted: #888;
        }

        * { box-sizing: border-box; }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(15px); }
            to { opacity: 1; transform: translateY(0); }
        }

        @keyframes logoPulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.05); }
        }

        @keyframes glow {
            from { filter: drop-shadow(0 0 5px rgba(200,155,60,0.4)); }
            to { filter: drop-shadow(0 0 15px rgba(200,155,60,0.7)); }
        }

        @keyframes floatSoft {
            0%,100% { transform: translateY(0px); }
            50% { transform: translateY(-4px); }
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
            animation: logoPulse 4s infinite, glow 3s infinite alternate;
        }

        .nav-controls {
            display: flex;
            flex-direction: column;
            gap: 20px;
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
            animation: fadeIn 0.6s ease-out;
            min-width: 0;
        }

        .glass-card {
            background: rgba(15, 15, 15, <?php echo $transparenciaPanel; ?>);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border: 1px solid var(--glass-border);
            border-radius: 20px;
            padding: 25px;
            margin-bottom: 30px;
            box-shadow: 0 10px 40px 0 rgba(0, 0, 0, 0.5), 0 0 20px rgba(200,155,60,0.08);
            min-width: 0;
            overflow: hidden;
            position: relative;
        }

        .glass-card::before{
            content:"";
            position:absolute;
            inset:0;
            background:linear-gradient(135deg, rgba(255,255,255,0.04), transparent 35%, transparent 70%, rgba(200,155,60,0.04));
            pointer-events:none;
        }

        .grid-form {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 20px;
            align-items: end;
        }

        .field-label {
            display: block;
            font-size: 10px;
            text-transform: uppercase;
            color: var(--gold);
            margin-bottom: 8px;
            letter-spacing: 1.5px;
            font-weight: bold;
            opacity: 0.9;
        }

        input, select {
            background: rgba(255, 255, 255, 0.04);
            border: 1px solid rgba(255, 255, 255, 0.1);
            color: white;
            padding: 14px;
            border-radius: 12px;
            outline: none;
            transition: 0.3s;
            box-sizing: border-box;
            width: 100%;
            min-width: 0;
            position: relative;
            z-index: 1;
        }

        select option {
            background: #111;
            color: #fff;
        }

        input:focus, select:focus {
            border-color: var(--gold);
            background: rgba(255, 255, 255, 0.08);
            box-shadow: 0 0 15px rgba(200,155,60,0.2);
        }

        .btn-gold {
            background: linear-gradient(45deg, #c89b3c, #eec064);
            color: black;
            border: none;
            padding: 14px 28px;
            border-radius: 12px;
            font-weight: 800;
            cursor: pointer;
            text-transform: uppercase;
            letter-spacing: 1px;
            transition: 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            white-space: nowrap;
            position: relative;
            z-index: 1;
        }

        .btn-gold:hover {
            transform: scale(1.03) translateY(-3px);
            box-shadow: 0 8px 20px var(--gold-glow);
        }

        .btn-danger {
            background: linear-gradient(45deg, #a61d24, #dc3545);
            color: #fff;
            border: none;
            padding: 8px 15px;
            border-radius: 12px;
            font-weight: 800;
            cursor: pointer;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            transition: 0.3s;
            font-size: 11px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            position: relative;
            z-index: 1;
        }

        .btn-danger:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(220, 53, 69, 0.35);
        }

        .acciones-pro {
            display: flex;
            justify-content: flex-end;
            gap: 8px;
            flex-wrap: wrap;
        }

        .tipo-pill {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 7px 12px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .5px;
            white-space: nowrap;
        }

        .tipo-personalizado {
            background: rgba(200,155,60,0.18);
            color: #f7d58b;
            border: 1px solid rgba(200,155,60,0.25);
        }

        .tipo-dtf {
            background: rgba(59,130,246,0.18);
            color: #bfdbfe;
            border: 1px solid rgba(59,130,246,0.25);
        }

        .table-wrap {
            width: 100%;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            margin-top: 25px;
            position: relative;
            z-index: 1;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 1180px;
        }

        th {
            text-align: left;
            color: var(--gold);
            border-bottom: 1px solid var(--glass-border);
            padding: 18px;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 1px;
            white-space: nowrap;
        }

        td {
            padding: 18px;
            border-bottom: 1px solid rgba(255,255,255,0.03);
            font-size: 14px;
            transition: 0.2s;
            vertical-align: middle;
        }

        tr:hover td {
            background: rgba(200,155,60,0.04);
            color: #fff;
        }

        #toast {
            position: fixed;
            top: 30px;
            right: 30px;
            background: #28a745;
            color: white;
            padding: 20px 40px;
            border-radius: 15px;
            transform: translateX(150%);
            transition: 0.5s cubic-bezier(0.68, -0.55, 0.265, 1.55);
            z-index: 10000;
            box-shadow: 0 15px 35px rgba(0,0,0,0.5);
            font-weight: bold;
            display: flex;
            align-items: center;
            gap: 10px;
            max-width: calc(100vw - 40px);
        }

        #toast.show { transform: translateX(0); }

        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-thumb { background: var(--glass-border); border-radius: 10px; }
        ::-webkit-scrollbar-thumb:hover { background: var(--gold); }

        @media (max-width: 980px) {
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
                background: rgba(0, 0, 0, 0.9);
                border-bottom: 1px solid var(--glass-border);
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
                border: 1px solid rgba(255,255,255,0.08);
                background: rgba(255,255,255,0.06);
                color: var(--gold);
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
            }

            body.menu-open .sidebar { transform: translateX(0); }
            body.menu-open .sidebar-overlay {
                opacity: 1;
                visibility: visible;
            }

            .main-content {
                margin-left: 0;
                padding: 20px 16px;
            }

            #toast {
                top: 76px;
                left: 12px;
                right: 12px;
                max-width: none;
                padding: 14px 16px;
                transform: translateY(-20px);
                opacity: 0;
            }

            #toast.show {
                transform: translateY(0);
                opacity: 1;
            }
        }

        @media (max-width: 700px) {
            .main-content { padding: 16px 12px; }

            .glass-card {
                padding: 16px;
                border-radius: 16px;
                margin-bottom: 18px;
            }

            .grid-form {
                grid-template-columns: 1fr;
                gap: 14px;
            }

            .grid-form > div[style*="text-align: right"] {
                text-align: left !important;
                padding-top: 0 !important;
            }

            .btn-gold {
                width: 100%;
                padding: 14px 16px;
            }

            h1 {
                font-size: 28px;
                line-height: 1.2;
            }
        }

        @media (max-width: 560px) {
            .main-content { padding: 14px 10px; }
            .glass-card { padding: 14px; }
            .mobile-topbar-title { font-size: 13px; }
            #buscador { width: 100% !important; }
        }
    </style>
</head>
<body>

    <div class="mobile-topbar">
        <div class="mobile-topbar-left">
            <img src="<?php echo $logoActual; ?>" alt="Logo" class="mobile-topbar-logo">
            <div class="mobile-topbar-title">Clientes PRO</div>
        </div>
        <button class="mobile-menu-toggle" id="mobileMenuToggle" aria-label="Abrir menú">
            <i class="fas fa-bars"></i>
        </button>
    </div>

    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <div id="toast"><i class="fas fa-check-circle fa-lg"></i> <span id="toast-msg"></span></div>

    <div class="sidebar" id="sidebar">
        <img src="<?php echo $logoActual; ?>" alt="Logo" class="logo-pos">

        <div class="nav-controls">
            <a href="dashboard.php" title="Volver al Dashboard"><i class="fas fa-home"></i></a>
            <a href="logout.php" class="exit-btn" title="Salir del Sistema"><i class="fas fa-power-off"></i></a>
        </div>

        <a href="ventas.php" title="Caja POS"><i class="fas fa-cash-register"></i></a>
        <a href="clientes.php" class="active" title="Clientes"><i class="fas fa-users"></i></a>
        <a href="configuracion.php" title="Configuración"><i class="fas fa-cog"></i></a>
    </div>

    <div class="main-content">
        <h1 style="font-weight: 200; letter-spacing: 1px; margin-bottom: 30px;">Gestión de <span style="color:var(--gold); font-weight: 800;">CLIENTES PRO</span></h1>

        <div class="glass-card">
            <h3 style="margin: 0 0 20px 0; color: var(--gold); font-size: 14px; font-weight: 400; position:relative; z-index:1;"><i class="fas fa-user-plus"></i> REGISTRO RÁPIDO DE CLIENTE</h3>
            <form method="POST" class="grid-form">
                <div>
                    <label class="field-label">Nombre Completo</label>
                    <input type="text" name="nombre" placeholder="Ej: Juan Pérez" required>
                </div>
                <div>
                    <label class="field-label">WhatsApp</label>
                    <input type="text" name="telefono" placeholder="Ej: 8123456789" required>
                </div>
                <div>
                    <label class="field-label">Tipo de cliente</label>
                    <select name="tipo_cliente" required>
                        <option value="Personalizado">Personalizado</option>
                        <option value="DTF">DTF</option>
                    </select>
                </div>
                <div>
                    <label class="field-label">Dirección de Entrega</label>
                    <input type="text" name="direccion" placeholder="Calle, Colonia, Ciudad">
                </div>
                <div>
                    <label class="field-label">Email (Opcional)</label>
                    <input type="email" name="email" placeholder="cliente@correo.com">
                </div>
                <div style="text-align: right; padding-top: 24px; position:relative; z-index:1;">
                    <button type="submit" name="btn_guardar" class="btn-gold">
                        <i class="fas fa-save"></i> Guardar Cliente
                    </button>
                </div>
            </form>
        </div>

        <div class="glass-card">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; gap: 12px; flex-wrap: wrap; position:relative; z-index:1;">
                <h3 style="margin: 0; color: var(--gold); font-size: 14px; font-weight: 400;"><i class="fas fa-address-book"></i> CARTERA DE CLIENTES</h3>
                <div style="position: relative; width: 100%; max-width: 350px;">
                    <i class="fas fa-search" style="position: absolute; left: 15px; top: 15px; color: #555; z-index:2;"></i>
                    <input type="text" id="buscador" placeholder="Buscar por nombre o teléfono..." onkeyup="buscarCliente()" style="width: 100%; padding-left: 45px; margin: 0;">
                </div>
            </div>

            <div class="table-wrap">
                <table id="tablaClientes">
                    <thead>
                        <tr>
                            <th>Nombre / Detalles</th>
                            <th>WhatsApp (Clic para abrir)</th>
                            <th>Tipo de cliente</th>
                            <th>Dirección Registrada</th>
                            <th style="text-align: right;">Acciones PRO</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($clientes && $clientes->num_rows > 0): ?>
                            <?php while($c = $clientes->fetch_assoc()): ?>
                            <?php
                                $tipoClienteFila = trim((string)($c['tipo_cliente'] ?? 'Personalizado'));
                                if (!in_array($tipoClienteFila, ['Personalizado', 'DTF'], true)) {
                                    $tipoClienteFila = 'Personalizado';
                                }
                                $tipoClase = $tipoClienteFila === 'DTF' ? 'tipo-dtf' : 'tipo-personalizado';
                            ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($c['nombre'] ?? ''); ?></strong><br>
                                    <small style="color:var(--text-muted); font-size: 11px;"><?php echo htmlspecialchars($c['email'] ?? ''); ?></small>
                                </td>
                                <td>
                                    <a href="https://wa.me/52<?php echo htmlspecialchars($c['telefono'] ?? ''); ?>" target="_blank" style="color: #25d366; text-decoration: none; font-weight: bold;">
                                        <i class="fab fa-whatsapp"></i> <?php echo htmlspecialchars($c['telefono'] ?? ''); ?>
                                    </a>
                                </td>
                                <td>
                                    <span class="tipo-pill <?php echo $tipoClase; ?>">
                                        <?php echo htmlspecialchars($tipoClienteFila); ?>
                                    </span>
                                </td>
                                <td style="color:var(--text-muted);"><?php echo htmlspecialchars($c['direccion'] ?? ''); ?></td>
                                <td style="text-align: right;">
                                    <div class="acciones-pro">
                                        <button class="btn-gold" style="padding: 8px 15px; font-size: 11px;" onclick="iniciarVenta('<?php echo htmlspecialchars($c['nombre'] ?? '', ENT_QUOTES); ?>', '<?php echo htmlspecialchars($c['telefono'] ?? '', ENT_QUOTES); ?>', '<?php echo (int)($c['id'] ?? 0); ?>', '<?php echo htmlspecialchars($c['direccion'] ?? '', ENT_QUOTES); ?>', '<?php echo htmlspecialchars($c['email'] ?? '', ENT_QUOTES); ?>', '<?php echo htmlspecialchars($tipoClienteFila, ENT_QUOTES); ?>')">
                                            <i class="fas fa-cart-plus"></i> NUEVA VENTA
                                        </button>

                                        <?php if ($esAdmin): ?>
                                            <a href="clientes.php?eliminar=<?php echo (int)($c['id'] ?? 0); ?>" class="btn-danger" onclick="return confirm('¿Seguro que quieres eliminar este cliente?');">
                                                <i class="fas fa-trash"></i> ELIMINAR
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="5" style="text-align:center; color:var(--text-muted);">No hay clientes registrados todavía.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script>
        const body = document.body;
        const mobileMenuToggle = document.getElementById('mobileMenuToggle');
        const sidebarOverlay = document.getElementById('sidebarOverlay');

        function abrirMenu() { body.classList.add('menu-open'); }
        function cerrarMenu() { body.classList.remove('menu-open'); }

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
                if (window.innerWidth <= 980) {
                    cerrarMenu();
                }
            });
        });

        window.addEventListener('resize', function () {
            if (window.innerWidth > 980) {
                cerrarMenu();
            }
        });

        function buscarCliente() {
            let input = document.getElementById('buscador').value.toLowerCase();
            let rows = document.getElementById('tablaClientes').getElementsByTagName('tr');
            for (let i = 1; i < rows.length; i++) {
                let texto = rows[i].innerText.toLowerCase();
                rows[i].style.display = texto.includes(input) ? "" : "none";
            }
        }

        function iniciarVenta(nombre, tel, clienteId, direccion, email, tipoCliente) {
            window.location.href =
                `ventas.php?cliente_id=${encodeURIComponent(clienteId)}` +
                `&cliente=${encodeURIComponent(nombre)}` +
                `&tel=${encodeURIComponent(tel)}` +
                `&direccion=${encodeURIComponent(direccion || '')}` +
                `&email=${encodeURIComponent(email || '')}` +
                `&tipo_cliente=${encodeURIComponent(tipoCliente || 'Personalizado')}`;
        }

        <?php if(!empty($notificacion)): ?>
        (function() {
            const t = document.getElementById('toast');
            document.getElementById('toast-msg').innerText = <?php echo json_encode($notificacion); ?>;
            t.classList.add('show');
            setTimeout(() => t.classList.remove('show'), 2500);
        })();
        <?php endif; ?>
    </script>
</body>
</html>
<?php
session_start();
require_once 'config/database.php';

if (isset($_SESSION['usuario_id']) && (int)$_SESSION['usuario_id'] > 0) {
    header("Location: dashboard.php");
    exit;
}

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

$logoActual = 'logo.png';
$fondoLogin = '';
$fondoContenido = '';
$imagenPub = 'https://images.unsplash.com/photo-1558769132-cb1aea458c5e?q=80&w=1500';
$logoTiktokShop = 'uploads/Tiktok-Shop-White-Logo-PNG.png';
$transparenciaPanel = 0.28;

$configCols = obtenerColumnasTabla($conn, 'configuracion');

if (!empty($configCols)) {
    $selectConfig = [];

    foreach (['logo', 'fondo_login', 'fondo_contenido', 'imagen_publicitaria', 'logo_tiktok_shop', 'transparencia_panel'] as $col) {
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
            if (!empty($config['fondo_login'])) $fondoLogin = $config['fondo_login'];
            if (!empty($config['fondo_contenido'])) $fondoContenido = $config['fondo_contenido'];
            if (!empty($config['imagen_publicitaria'])) $imagenPub = $config['imagen_publicitaria'];
            if (!empty($config['logo_tiktok_shop'])) $logoTiktokShop = $config['logo_tiktok_shop'];
            if (isset($config['transparencia_panel'])) $transparenciaPanel = (float)$config['transparencia_panel'];
        }
    }
}

$fondoPrincipal = $fondoLogin ?: $fondoContenido;

$error = '';
$mensajeOk = '';
if (isset($_GET['reset']) && $_GET['reset'] === 'ok') {
    $mensajeOk = 'Contraseña actualizada correctamente. Ya puedes iniciar sesión.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $usuario = trim($_POST['usuario'] ?? '');
    $passRaw = $_POST['password'] ?? '';

    if ($usuario === '' || $passRaw === '') {
        $error = "Acceso denegado. Revisa tus datos.";
    } elseif (!existeTabla($conn, 'usuarios')) {
        $error = "No existe la tabla de usuarios.";
    } else {
        $columnasUsuarios = obtenerColumnasTabla($conn, 'usuarios');

        $campoEstado = tieneColumna($columnasUsuarios, 'estado') ? 'estado' : (tieneColumna($columnasUsuarios, 'activo') ? 'activo' : '');
        $campoCorreo = tieneColumna($columnasUsuarios, 'correo') ? 'correo' : (tieneColumna($columnasUsuarios, 'email') ? 'email' : '');
        $campoRol = tieneColumna($columnasUsuarios, 'rol') ? 'rol' : '';

        if ($campoEstado === 'estado') {
            $sql = "SELECT * FROM usuarios WHERE usuario = ? AND estado = 'activo' LIMIT 1";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("s", $usuario);
        } elseif ($campoEstado === 'activo') {
            $sql = "SELECT * FROM usuarios WHERE usuario = ? AND activo = 1 LIMIT 1";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("s", $usuario);
        } else {
            $sql = "SELECT * FROM usuarios WHERE usuario = ? LIMIT 1";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("s", $usuario);
        }

        $stmt->execute();
        $res = $stmt->get_result();

        if ($user = $res->fetch_assoc()) {
            $loginCorrecto = false;
            $rehashNecesario = false;

            if (!empty($user['password']) && password_verify($passRaw, $user['password'])) {
                $loginCorrecto = true;

                if (password_needs_rehash($user['password'], PASSWORD_DEFAULT)) {
                    $rehashNecesario = true;
                }
            } elseif (!empty($user['password']) && md5($passRaw) === $user['password']) {
                $loginCorrecto = true;
                $rehashNecesario = true;
            }

            if ($loginCorrecto) {
                if ($rehashNecesario) {
                    $nuevoHash = password_hash($passRaw, PASSWORD_DEFAULT);
                    $stmtUpdate = $conn->prepare("UPDATE usuarios SET password = ? WHERE id = ? LIMIT 1");
                    $stmtUpdate->bind_param("si", $nuevoHash, $user['id']);
                    $stmtUpdate->execute();
                    $stmtUpdate->close();
                }

                $_SESSION['usuario_id'] = $user['id'];
                $_SESSION['nombre'] = $user['nombre'] ?? $user['usuario'];
                $_SESSION['usuario'] = $user['usuario'] ?? '';
                $_SESSION['rol'] = $campoRol !== '' ? ($user[$campoRol] ?? 'mostrador') : 'mostrador';

                if ($campoCorreo !== '') {
                    $_SESSION['correo'] = $user[$campoCorreo] ?? '';
                }

                header("Location: dashboard.php");
                exit;
            }
        }

        $stmt->close();
        $error = "Acceso denegado. Revisa tus datos.";
    }
}

$panelAlpha = max(0.10, min(0.95, $transparenciaPanel));
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Suave Urban Studio - Acceso</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --gold: #c89b3c;
            --bg: #050505;
            --tiktok: #fe2c55;
        }

        * { box-sizing: border-box; }

        body, html {
            margin: 0;
            padding: 0;
            min-height: 100%;
            background: var(--bg);
            font-family: 'Segoe UI', sans-serif;
            color: white;
            overflow-x: hidden;
        }

        .main-container {
            display: flex;
            min-height: 100vh;
            width: 100%;
            background:
                <?php echo $fondoPrincipal
                    ? "linear-gradient(rgba(0,0,0,0.45), rgba(0,0,0,0.60)), url('" . htmlspecialchars($fondoPrincipal, ENT_QUOTES, 'UTF-8') . "') center/cover no-repeat"
                    : "linear-gradient(135deg, #050505, #111111)"; ?>;
            position: relative;
        }

        .main-container::before {
            content: '';
            position: absolute;
            inset: 0;
            background: rgba(0,0,0,0.25);
            z-index: 1;
        }

        .tiktok-side, .login-side {
            position: relative;
            z-index: 2;
        }

        .tiktok-side {
            flex: 1.2;
            display: flex;
            align-items: center;
            justify-content: center;
            border-right: 1px solid rgba(255,255,255,0.08);
            text-decoration: none;
            min-width: 0;
            padding: 20px;
            backdrop-filter: blur(4px);
            -webkit-backdrop-filter: blur(4px);
        }

        .shop-badge {
            position: absolute;
            top: 30px;
            left: 30px;
            z-index: 10;
            height: 45px;
            width: auto;
            max-width: 160px;
            filter: drop-shadow(0 4px 10px rgba(0,0,0,0.5));
            transition: 0.3s;
        }

        .tiktok-side:hover .shop-badge {
            transform: scale(1.1);
        }

        .tiktok-mockup {
            width: 85%;
            height: 80%;
            min-height: 420px;
            background: url('<?php echo htmlspecialchars($imagenPub, ENT_QUOTES, 'UTF-8'); ?>') center center no-repeat;
            background-size: contain;
            border-radius: 20px;
            border: 1px solid rgba(255,255,255,0.12);
            position: relative;
            overflow: hidden;
            transition: 0.5s;
            box-shadow: 0 20px 50px rgba(0,0,0,0.8);
            background-color: rgba(0,0,0,0.18);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
        }

        .tiktok-mockup:hover {
            transform: translateY(-10px) scale(1.01);
        }

        .tiktok-overlay {
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            padding: 30px;
            background: linear-gradient(transparent, rgba(0,0,0,0.9));
        }

        .tiktok-user {
            font-weight: bold;
            font-size: 18px;
            display: flex;
            align-items: center;
            gap: 5px;
            flex-wrap: wrap;
        }

        .tiktok-user i {
            color: #20d5ec;
            font-size: 14px;
        }

        .tiktok-desc {
            font-size: 14px;
            color: #ccc;
            margin-top: 5px;
            line-height: 1.5;
        }

        .login-side {
            flex: 0.8;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 40px;
            min-width: 0;
        }

        .login-content {
            width: 100%;
            max-width: 350px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
            filter: drop-shadow(0px 10px 20px rgba(0,0,0,0.9));
            background: rgba(0, 0, 0, <?php echo $panelAlpha; ?>);
            border: 1px solid rgba(255,255,255,0.08);
            border-radius: 24px;
            padding: 28px 22px;
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
        }

        .logo-login {
            max-width: 180px;
            width: 100%;
            height: auto;
            margin-bottom: 25px;
            filter: drop-shadow(0 0 15px rgba(200,155,60,0.6));
            animation: logoPulse 4s ease-in-out infinite, logoGlow 4s ease-in-out infinite;
            object-fit: contain;
        }

        @keyframes logoPulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.03); }
        }

        @keyframes logoGlow {
            0%, 100% { filter: drop-shadow(0 0 15px rgba(200,155,60,0.6)); }
            50% { filter: drop-shadow(0 0 25px rgba(200,155,60,0.8)); }
        }

        input {
            width: 100%;
            padding: 16px;
            margin-bottom: 15px;
            background: rgba(17, 17, 17, 0.65);
            border: 1px solid rgba(200, 155, 60, 0.3);
            border-radius: 12px;
            color: white;
            font-size: 15px;
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
        }

        input:focus {
            outline: none;
            border-color: var(--gold);
            background: rgba(30, 30, 30, 0.82);
        }

        .password-wrap {
            width: 100%;
            position: relative;
        }

        .password-wrap input {
            padding-right: 48px;
            margin-bottom: 15px;
        }

        .toggle-password {
            position: absolute;
            right: 16px;
            top: 22px;
            transform: translateY(-50%);
            color: #cfcfcf;
            cursor: pointer;
            font-size: 16px;
            transition: 0.3s;
            user-select: none;
        }

        .toggle-password:hover {
            color: var(--gold);
        }

        .btn-submit {
            width: 100%;
            padding: 16px;
            background: var(--gold);
            color: black;
            border: none;
            border-radius: 12px;
            font-size: 15px;
            font-weight: 800;
            cursor: pointer;
            position: relative;
            overflow: hidden;
            transition: 0.3s;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .btn-submit::after {
            content: '';
            position: absolute;
            top: -50%;
            left: -60%;
            width: 20%;
            height: 200%;
            background: rgba(255,255,255,0.4);
            transform: rotate(30deg);
            animation: shine 4s infinite;
        }

        @keyframes shine {
            0% { left: -60%; }
            15%, 100% { left: 120%; }
        }

        .forgot-link {
            display: inline-block;
            margin-top: 4px;
            color: #d8c28a;
            text-decoration: none;
            font-size: 13px;
            transition: 0.3s;
        }

        .forgot-link:hover {
            color: #ffffff;
            text-decoration: underline;
        }

        .social-container {
            margin-top: 30px;
            width: 100%;
        }

        .social-icons {
            display: flex;
            gap: 20px;
            justify-content: center;
            flex-wrap: wrap;
        }

        .social-icons a {
            font-size: 24px;
            text-decoration: none;
            transition: 0.3s;
        }

        .social-icons a:hover {
            transform: translateY(-5px) scale(1.2);
        }

        .fa-facebook { color: #1877F2; }
        .fa-instagram { color: #E4405F; }
        .fa-whatsapp { color: #25D366; }
        .fa-tiktok { color: #fff; }

        .legal-footer {
            margin-top: 30px;
            font-size: 11px;
            color: rgba(255,255,255,0.4);
            text-align: center;
            line-height: 1.5;
            width: 100%;
        }

        @media (max-width: 1000px) {
            .tiktok-side { display: none; }
            .login-side {
                flex: 1;
                width: 100%;
                padding: 30px 20px;
            }
            .login-content {
                max-width: 420px;
            }
        }

        @media (max-width: 768px) {
            body, html { overflow-y: auto; }

            .main-container {
                min-height: 100vh;
                display: block;
                padding: 0;
            }

            .login-side {
                min-height: 100vh;
                padding: 24px 16px;
                justify-content: center;
            }

            .login-content {
                max-width: 100%;
                width: 100%;
                padding: 24px 18px;
                border-radius: 20px;
            }

            .logo-login {
                max-width: 150px;
                margin-bottom: 22px;
            }

            input {
                padding: 15px;
                font-size: 16px;
                border-radius: 12px;
            }

            .btn-submit {
                padding: 15px;
                font-size: 14px;
            }

            .toggle-password {
                top: 21px;
            }
        }
    </style>
</head>
<body>

<div class="main-container">
    <a href="https://www.tiktok.com/@suaveurbanoficial" target="_blank" class="tiktok-side">
        <img src="<?php echo htmlspecialchars($logoTiktokShop, ENT_QUOTES, 'UTF-8'); ?>" class="shop-badge" alt="TikTok Shop">

        <div class="tiktok-mockup">
            <div class="tiktok-overlay">
                <div class="tiktok-user">@suaveurbanoficial <i class="fas fa-check-circle"></i></div>
                <div class="tiktok-desc">NUEVA COLECCIÓN DISPONIBLE <br><b>Haz clic para ver la tienda</b></div>
            </div>
        </div>
    </a>

    <div class="login-side">
        <div class="login-content">
            <img src="<?php echo htmlspecialchars($logoActual, ENT_QUOTES, 'UTF-8'); ?>" alt="Suave Urban Studio" class="logo-login">

            <?php if($mensajeOk): ?>
                <div style="color: #bbf7d0; margin-bottom: 20px; font-size: 14px; font-weight: bold; width: 100%; background: rgba(22,163,74,0.14); border: 1px solid rgba(22,163,74,0.35); padding: 12px; border-radius: 12px;">
                    <?php echo htmlspecialchars($mensajeOk, ENT_QUOTES, 'UTF-8'); ?>
                </div>
            <?php endif; ?>

            <?php if($error): ?>
                <div style="color: #ff4d4d; margin-bottom: 20px; font-size: 14px; font-weight: bold; width: 100%;">
                    <?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
                </div>
            <?php endif; ?>

            <form method="POST" style="width: 100%;" autocomplete="off">
                <input type="text" name="usuario" placeholder="Usuario" required>

                <div class="password-wrap">
                    <input type="password" id="password" name="password" placeholder="Contraseña" required>
                    <i class="fa-solid fa-eye toggle-password" id="togglePassword"></i>
                </div>

                <button type="submit" class="btn-submit">INICIAR SESIÓN</button>

                <a href="recuperar_password.php" class="forgot-link">¿Olvidaste tu contraseña?</a>
            </form>

            <div class="social-container">
                <div class="social-icons">
                    <a href="https://www.facebook.com/SuaveUrbanTRCOficial" target="_blank"><i class="fab fa-facebook"></i></a>
                    <a href="https://www.instagram.com/suaveurbantrc" target="_blank"><i class="fab fa-instagram"></i></a>
                    <a href="https://wa.me/526679709815" target="_blank"><i class="fab fa-whatsapp"></i></a>
                    <a href="https://www.tiktok.com/@suaveurbanoficial" target="_blank"><i class="fab fa-tiktok"></i></a>
                </div>
            </div>

            <div class="legal-footer">
                © 2026 Suave Urban. Todos los derechos reservados.
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const togglePassword = document.getElementById('togglePassword');
    const passwordInput = document.getElementById('password');

    if (togglePassword && passwordInput) {
        togglePassword.addEventListener('click', function () {
            const isPassword = passwordInput.getAttribute('type') === 'password';
            passwordInput.setAttribute('type', isPassword ? 'text' : 'password');
            this.classList.toggle('fa-eye-slash');
        });
    }
});
</script>

</body>
</html>
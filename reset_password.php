<?php
session_start();
require_once 'config/database.php';

function existeTablaReset(mysqli $conn, string $tabla): bool
{
    $tablaSegura = $conn->real_escape_string($tabla);
    $res = $conn->query("SHOW TABLES LIKE '{$tablaSegura}'");
    return ($res && $res->num_rows > 0);
}

function obtenerColumnasReset(mysqli $conn, string $tabla): array
{
    $columnas = [];

    if (!existeTablaReset($conn, $tabla)) {
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

function tieneColumnaReset(array $columnas, string $columna): bool
{
    return in_array($columna, $columna ? [$columna] : [], true) || in_array($columna, $columnas, true);
}

$logoActual = 'logo.png';
$fondoLogin = '';
$fondoContenido = '';
$imagenPub = 'https://images.unsplash.com/photo-1558769132-cb1aea458c5e?q=80&w=1500';
$logoTiktokShop = 'uploads/Tiktok-Shop-White-Logo-PNG.png';
$transparenciaPanel = 0.28;

$configCols = obtenerColumnasReset($conn, 'configuracion');

if (!empty($configCols)) {
    $selectConfig = [];

    foreach (['logo', 'fondo_login', 'fondo_contenido', 'imagen_publicitaria', 'logo_tiktok_shop', 'transparencia_panel'] as $col) {
        if (in_array($col, $configCols, true)) {
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
$panelAlpha = max(0.10, min(0.95, $transparenciaPanel));

$token = trim($_GET['token'] ?? '');
$mensaje = '';
$valido = false;

if (isset($_GET['error']) && trim($_GET['error']) !== '') {
    $mensaje = trim($_GET['error']);
}

if ($token === '') {
    if ($mensaje === '') {
        $mensaje = 'Token inválido.';
    }
} else {
    $stmt = $conn->prepare("SELECT * FROM password_resets WHERE token = ? AND usado = 0 AND expiracion >= NOW() LIMIT 1");
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $res = $stmt->get_result();
    $resetRow = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    if ($resetRow) {
        $valido = true;
    } else {
        if ($mensaje === '') {
            $mensaje = 'El enlace es inválido o ya expiró.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Restablecer contraseña - Suave Urban Studio</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --gold: #c89b3c;
            --bg: #050505;
        }

        * { box-sizing: border-box; }

        html, body {
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

        .promo-side, .form-side {
            position: relative;
            z-index: 2;
        }

        .promo-side {
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

        .promo-side:hover .shop-badge {
            transform: scale(1.08);
        }

        .promo-mockup {
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

        .promo-mockup:hover {
            transform: translateY(-10px) scale(1.01);
        }

        .promo-overlay {
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            padding: 30px;
            background: linear-gradient(transparent, rgba(0,0,0,0.9));
        }

        .promo-title {
            font-weight: bold;
            font-size: 18px;
            display: flex;
            align-items: center;
            gap: 6px;
            flex-wrap: wrap;
        }

        .promo-title i {
            color: #20d5ec;
            font-size: 14px;
        }

        .promo-desc {
            font-size: 14px;
            color: #ccc;
            margin-top: 5px;
            line-height: 1.5;
        }

        .form-side {
            flex: 0.8;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 40px;
            min-width: 0;
        }

        .form-content {
            width: 100%;
            max-width: 360px;
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
            margin-bottom: 18px;
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

        .titulo {
            margin: 0 0 8px 0;
            font-size: 22px;
            font-weight: 800;
            color: #fff;
        }

        .sub {
            margin: 0 0 20px 0;
            font-size: 13px;
            color: #cfcfcf;
            line-height: 1.5;
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

        .msg {
            width: 100%;
            padding: 12px 14px;
            border-radius: 12px;
            margin-bottom: 16px;
            font-size: 14px;
            text-align: left;
        }

        .error {
            background: rgba(220, 38, 38, 0.14);
            border: 1px solid rgba(220, 38, 38, 0.35);
            color: #fecaca;
        }

        .hint {
            color: #cfcfcf;
            font-size: 12px;
            margin-top: -6px;
            margin-bottom: 12px;
            text-align: left;
            width: 100%;
        }

        .back {
            display: inline-block;
            margin-top: 12px;
            color: #d8c28a;
            text-decoration: none;
            font-size: 13px;
            transition: 0.3s;
        }

        .back:hover {
            color: #ffffff;
            text-decoration: underline;
        }

        @media (max-width: 1000px) {
            .promo-side { display: none; }
            .form-side {
                flex: 1;
                width: 100%;
                padding: 30px 20px;
            }
            .form-content {
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

            .form-side {
                min-height: 100vh;
                padding: 24px 16px;
                justify-content: center;
            }

            .form-content {
                max-width: 100%;
                width: 100%;
                padding: 24px 18px;
                border-radius: 20px;
            }

            .logo-login {
                max-width: 150px;
                margin-bottom: 18px;
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
    <a href="https://www.tiktok.com/@suaveurbanoficial" target="_blank" class="promo-side">
        <img src="<?php echo htmlspecialchars($logoTiktokShop, ENT_QUOTES, 'UTF-8'); ?>" class="shop-badge" alt="TikTok Shop">

        <div class="promo-mockup">
            <div class="promo-overlay">
                <div class="promo-title">Nueva contraseña <i class="fas fa-check-circle"></i></div>
                <div class="promo-desc">Crea una contraseña fuerte y segura para recuperar el acceso a tu cuenta.</div>
            </div>
        </div>
    </a>

    <div class="form-side">
        <div class="form-content">
            <img src="<?php echo htmlspecialchars($logoActual, ENT_QUOTES, 'UTF-8'); ?>" alt="Suave Urban Studio" class="logo-login">

            <h2 class="titulo">Restablecer contraseña</h2>
            <p class="sub">Escribe tu nueva contraseña y confírmala para actualizar tu acceso.</p>

            <?php if ($mensaje !== ''): ?>
                <div class="msg error"><?php echo htmlspecialchars($mensaje, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>

            <?php if ($valido): ?>
                <form method="POST" action="guardar_password.php" style="width:100%;">
                    <input type="hidden" name="token" value="<?php echo htmlspecialchars($token, ENT_QUOTES, 'UTF-8'); ?>">

                    <div class="password-wrap">
                        <input type="password" name="password" id="password" placeholder="Nueva contraseña" required>
                        <i class="fa-solid fa-eye toggle-password" id="togglePassword"></i>
                    </div>

                    <div class="password-wrap">
                        <input type="password" name="confirmar_password" id="confirmar_password" placeholder="Confirmar contraseña" required>
                        <i class="fa-solid fa-eye toggle-password" id="togglePassword2"></i>
                    </div>

                    <div class="hint">Mínimo 8 caracteres, mayúscula, minúscula, número y símbolo.</div>

                    <button type="submit" class="btn-submit">CAMBIAR CONTRASEÑA</button>
                </form>
            <?php endif; ?>

            <a href="index.php" class="back">Volver al inicio de sesión</a>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const toggle1 = document.getElementById('togglePassword');
    const toggle2 = document.getElementById('togglePassword2');
    const password = document.getElementById('password');
    const confirm = document.getElementById('confirmar_password');

    if (toggle1 && password) {
        toggle1.addEventListener('click', function () {
            password.type = password.type === 'password' ? 'text' : 'password';
            this.classList.toggle('fa-eye-slash');
        });
    }

    if (toggle2 && confirm) {
        toggle2.addEventListener('click', function () {
            confirm.type = confirm.type === 'password' ? 'text' : 'password';
            this.classList.toggle('fa-eye-slash');
        });
    }
});
</script>

</body>
</html>
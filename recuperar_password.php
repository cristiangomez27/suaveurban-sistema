<?php
session_start();
require_once 'config/database.php';
require_once 'config/mail.php';
require_once 'helpers/mail.php';

function existeTablaRP(mysqli $conn, string $tabla): bool
{
    $tablaSegura = $conn->real_escape_string($tabla);
    $res = $conn->query("SHOW TABLES LIKE '{$tablaSegura}'");
    return ($res && $res->num_rows > 0);
}

function obtenerColumnasRP(mysqli $conn, string $tabla): array
{
    $columnas = [];

    if (!existeTablaRP($conn, $tabla)) {
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

function tieneColumnaRP(array $columnas, string $columna): bool
{
    return in_array($columna, $columnas, true);
}

$logoActual = 'logo.png';
$fondoLogin = '';
$fondoContenido = '';
$imagenPub = 'https://images.unsplash.com/photo-1558769132-cb1aea458c5e?q=80&w=1500';
$logoTiktokShop = 'uploads/Tiktok-Shop-White-Logo-PNG.png';
$transparenciaPanel = 0.28;

$configCols = obtenerColumnasRP($conn, 'configuracion');

if (!empty($configCols)) {
    $selectConfig = [];

    foreach (['logo', 'fondo_login', 'fondo_contenido', 'imagen_publicitaria', 'logo_tiktok_shop', 'transparencia_panel'] as $col) {
        if (tieneColumnaRP($configCols, $col)) {
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

$mensaje = '';
$tipo = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $correo = trim($_POST['correo'] ?? '');

    if ($correo === '' || !filter_var($correo, FILTER_VALIDATE_EMAIL)) {
        $mensaje = 'Ingresa un correo válido.';
        $tipo = 'error';
    } else {
        $stmt = $conn->prepare("SELECT id, nombre, correo, estado FROM usuarios WHERE correo = ? LIMIT 1");
        $stmt->bind_param("s", $correo);
        $stmt->execute();
        $res = $stmt->get_result();
        $usuario = $res ? $res->fetch_assoc() : null;
        $stmt->close();

        if (!$usuario || (isset($usuario['estado']) && $usuario['estado'] !== 'activo')) {
            $mensaje = 'Si el correo existe, te enviamos un enlace de recuperación.';
            $tipo = 'ok';
        } else {
            $conn->query("DELETE FROM password_resets WHERE email = '" . $conn->real_escape_string($correo) . "'");

            $token = bin2hex(random_bytes(32));
            $expiracion = date('Y-m-d H:i:s', strtotime('+15 minutes'));

            $stmtInsert = $conn->prepare("INSERT INTO password_resets (email, token, expiracion, usado) VALUES (?, ?, ?, 0)");
            $stmtInsert->bind_param("sss", $correo, $token, $expiracion);
            $okInsert = $stmtInsert->execute();
            $stmtInsert->close();

            if ($okInsert) {
                $enlace = 'https://suaveurbanstudio.com.mx/reset_password.php?token=' . urlencode($token);

                $nombreUsuario = htmlspecialchars($usuario['nombre'] ?? 'Usuario', ENT_QUOTES, 'UTF-8');
                $html = '
                <div style="font-family:Segoe UI,Arial,sans-serif;background:#0b0b0f;padding:30px;color:#fff;">
                    <div style="max-width:600px;margin:0 auto;background:#111;border:1px solid rgba(200,155,60,.25);border-radius:18px;padding:30px;">
                        <h2 style="margin-top:0;color:#c89b3c;">Recuperar contraseña</h2>
                        <p>Hola <b>' . $nombreUsuario . '</b>, recibimos una solicitud para restablecer tu contraseña.</p>
                        <p>Haz clic en el siguiente botón:</p>
                        <p style="margin:25px 0;">
                            <a href="' . $enlace . '" style="background:#c89b3c;color:#000;text-decoration:none;padding:14px 22px;border-radius:10px;font-weight:800;display:inline-block;">
                                Restablecer contraseña
                            </a>
                        </p>
                        <p>O copia este enlace en tu navegador:</p>
                        <p style="word-break:break-all;color:#ddd;">' . $enlace . '</p>
                        <p>Este enlace expira en <b>15 minutos</b>.</p>
                        <p>Si tú no solicitaste esto, ignora este mensaje.</p>
                        <hr style="border:none;border-top:1px solid rgba(255,255,255,.08);margin:24px 0;">
                        <p style="color:#aaa;font-size:12px;">Suave Urban Studio</p>
                    </div>
                </div>';

                $envio = enviarCorreoSMTP($correo, 'Recuperación de contraseña - Suave Urban Studio', $html);

                if ($envio['ok']) {
                    $mensaje = 'Si el correo existe, te enviamos un enlace de recuperación.';
                    $tipo = 'ok';
                } else {
                    $mensaje = 'No se pudo enviar el correo. Revisa la configuración SMTP.';
                    $tipo = 'error';
                }
            } else {
                $mensaje = 'No se pudo generar la recuperación.';
                $tipo = 'error';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Recuperar contraseña - Suave Urban Studio</title>
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

        .ok {
            background: rgba(22, 163, 74, 0.14);
            border: 1px solid rgba(22, 163, 74, 0.35);
            color: #bbf7d0;
        }

        .error {
            background: rgba(220, 38, 38, 0.14);
            border: 1px solid rgba(220, 38, 38, 0.35);
            color: #fecaca;
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
        }
    </style>
</head>
<body>

<div class="main-container">
    <a href="https://www.tiktok.com/@suaveurbanoficial" target="_blank" class="promo-side">
        <img src="<?php echo htmlspecialchars($logoTiktokShop, ENT_QUOTES, 'UTF-8'); ?>" class="shop-badge" alt="TikTok Shop">

        <div class="promo-mockup">
            <div class="promo-overlay">
                <div class="promo-title">Recuperación segura <i class="fas fa-check-circle"></i></div>
                <div class="promo-desc">Te enviaremos un enlace temporal a tu correo para restablecer tu contraseña.</div>
            </div>
        </div>
    </a>

    <div class="form-side">
        <div class="form-content">
            <img src="<?php echo htmlspecialchars($logoActual, ENT_QUOTES, 'UTF-8'); ?>" alt="Suave Urban Studio" class="logo-login">

            <h2 class="titulo">Recuperar contraseña</h2>
            <p class="sub">Ingresa tu correo registrado para enviarte un enlace de recuperación.</p>

            <?php if ($mensaje !== ''): ?>
                <div class="msg <?php echo $tipo === 'ok' ? 'ok' : 'error'; ?>">
                    <?php echo htmlspecialchars($mensaje, ENT_QUOTES, 'UTF-8'); ?>
                </div>
            <?php endif; ?>

            <form method="POST" style="width:100%;">
                <input type="email" name="correo" placeholder="Tu correo" required>
                <button type="submit" class="btn-submit">ENVIAR RECUPERACIÓN</button>
            </form>

            <a href="index.php" class="back">Volver al inicio de sesión</a>
        </div>
    </div>
</div>

</body>
</html>
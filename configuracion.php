<?php
session_start();
if (!isset($_SESSION['usuario_id'])) {
    header("Location: index.php");
    exit;
}

require_once 'config/database.php';

$mensaje = '';
$tipoMensaje = 'ok';

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

function asegurarColumna(mysqli $conn, string $tabla, string $columna, string $sqlDefinicion): void
{
    $cols = obtenerColumnasTabla($conn, $tabla);
    if (!tieneColumna($cols, $columna)) {
        $conn->query("ALTER TABLE `$tabla` ADD COLUMN `$columna` $sqlDefinicion");
    }
}

function subirArchivo(string $campo, string $targetDir, array &$errores, array $extPermitidas, bool $debeSerImagen = false): ?string
{
    if (empty($_FILES[$campo]['name'])) {
        return null;
    }

    if (!isset($_FILES[$campo]['tmp_name']) || !is_uploaded_file($_FILES[$campo]['tmp_name'])) {
        $errores[] = "Archivo inválido en {$campo}.";
        return null;
    }

    $ext = strtolower(pathinfo($_FILES[$campo]['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $extPermitidas, true)) {
        $errores[] = "Archivo no permitido en {$campo}.";
        return null;
    }

    if ($debeSerImagen) {
        $check = @getimagesize($_FILES[$campo]['tmp_name']);
        if ($check === false) {
            $errores[] = "El archivo de {$campo} no es una imagen válida.";
            return null;
        }
    }

    if (!is_dir($targetDir)) {
        mkdir($targetDir, 0777, true);
    }

    $nombreFinal = $campo . '_' . time() . '_' . mt_rand(1000, 9999) . '.' . $ext;
    $rutaCompleta = rtrim($targetDir, '/') . '/' . $nombreFinal;

    if (!move_uploaded_file($_FILES[$campo]['tmp_name'], $rutaCompleta)) {
        $errores[] = "No se pudo subir el archivo {$campo}.";
        return null;
    }

    return $rutaCompleta;
}

function esc(mixed $valor): string
{
    return htmlspecialchars((string)$valor, ENT_QUOTES, 'UTF-8');
}

function valorCheck(array $config, string $campo, int $default = 0): bool
{
    return isset($config[$campo]) ? (int)$config[$campo] === 1 : $default === 1;
}

function ejecutarRespaldo(mysqli $conn): void
{
    $filename = "backup_sistema_" . date("Y-m-d_H-i-s") . ".sql";
    header('Content-Type: application/sql; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $tables = $conn->query("SHOW TABLES");
    echo "-- Backup del sistema\n";
    echo "-- Fecha: " . date('Y-m-d H:i:s') . "\n\n";
    while ($table = $tables->fetch_array()) {
        $tabla = $table[0];
        echo "\n-- Tabla: {$tabla}\n";
        $create = $conn->query("SHOW CREATE TABLE `$tabla`")->fetch_assoc();
        if (!empty($create['Create Table'])) {
            echo "DROP TABLE IF EXISTS `$tabla`;\n";
            echo $create['Create Table'] . ";\n\n";
        }

        $result = $conn->query("SELECT * FROM `$tabla`");
        while ($row = $result->fetch_assoc()) {
            $columnas = array_map(fn($c) => "`$c`", array_keys($row));
            $valores = [];
            foreach ($row as $value) {
                if ($value === null) {
                    $valores[] = "NULL";
                } else {
                    $valores[] = "'" . $conn->real_escape_string((string)$value) . "'";
                }
            }
            echo "INSERT INTO `$tabla` (" . implode(',', $columnas) . ") VALUES (" . implode(',', $valores) . ");\n";
        }
        echo "\n";
    }
    exit;
}

function reiniciarSistemaEnCeros(mysqli $conn): void
{
    $tablasVaciar = [
        'ventas_detalle',
        'ventas',
        'pedidos',
        'pedidos_entregados',
        'entregas',
        'clientes',
        'facturas_proveedor',
        'proveedores',
        'notificaciones',
        'bitacora',
        'papelera',
        'mensajes_internos',
        'mensajes_lecturas'
    ];

    $conn->begin_transaction();
    try {
        foreach ($tablasVaciar as $tabla) {
            if (existeTabla($conn, $tabla)) {
                $conn->query("TRUNCATE TABLE `$tabla`");
            }
        }
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
}

function optimizarSistema(mysqli $conn): string
{
    $salida = [];
    $tables = $conn->query("SHOW TABLES");
    while ($table = $tables->fetch_array()) {
        $tabla = $table[0];
        $conn->query("OPTIMIZE TABLE `$tabla`");
        $conn->query("REPAIR TABLE `$tabla`");
        $salida[] = $tabla;
    }
    return implode(', ', $salida);
}

/*
|--------------------------------------------------------------------------
| DETECTAR ADMIN
|--------------------------------------------------------------------------
*/
$rolSesion = strtolower(trim((string)($_SESSION['rol'] ?? '')));
$esAdmin = ($rolSesion === 'admin');

if (!$esAdmin) {
    $usuarioSesionId = (int)($_SESSION['usuario_id'] ?? 0);
    if ($usuarioSesionId > 0 && existeTabla($conn, 'usuarios')) {
        $resRol = $conn->query("SELECT rol FROM usuarios WHERE id = {$usuarioSesionId} LIMIT 1");
        if ($resRol && $resRol->num_rows > 0) {
            $rowRol = $resRol->fetch_assoc();
            $rolSesion = strtolower(trim((string)($rowRol['rol'] ?? '')));
            $_SESSION['rol'] = $rowRol['rol'] ?? '';
            $esAdmin = ($rolSesion === 'admin');
        }
    }
}

if (!$esAdmin) {
    header("Location: dashboard.php");
    exit;
}

/*
|--------------------------------------------------------------------------
| ASEGURAR TABLAS Y COLUMNAS NECESARIAS
|--------------------------------------------------------------------------
*/
if (!existeTabla($conn, 'configuracion')) {
    $conn->query("
        CREATE TABLE configuracion (
            id INT UNSIGNED NOT NULL PRIMARY KEY,
            logo VARCHAR(255) DEFAULT 'logo.png',
            fondo_login VARCHAR(255) DEFAULT NULL,
            fondo_sidebar VARCHAR(255) DEFAULT NULL,
            fondo_contenido VARCHAR(255) DEFAULT NULL,
            imagen_publicitaria VARCHAR(255) DEFAULT NULL,
            logo_tiktok_shop VARCHAR(255) DEFAULT NULL,
            transparencia_panel DECIMAL(4,2) NOT NULL DEFAULT 0.38,
            transparencia_sidebar DECIMAL(4,2) NOT NULL DEFAULT 0.88,
            nombre_negocio VARCHAR(150) DEFAULT 'Suave Urban Studio',
            descripcion_negocio VARCHAR(255) DEFAULT NULL,
            telefono_negocio VARCHAR(50) DEFAULT NULL,
            correo_negocio VARCHAR(150) DEFAULT NULL,
            direccion_negocio TEXT DEFAULT NULL,
            greenapi_instance VARCHAR(120) DEFAULT NULL,
            greenapi_token VARCHAR(255) DEFAULT NULL,
            activar_whatsapp TINYINT(1) NOT NULL DEFAULT 1,
            activar_qr_remision TINYINT(1) NOT NULL DEFAULT 1,
            activar_envio_remision_whatsapp TINYINT(1) NOT NULL DEFAULT 1,
            mensaje_remision_default TEXT DEFAULT NULL,
            pie_remision TEXT DEFAULT NULL,
            sonido_pedido_nuevo VARCHAR(255) DEFAULT NULL,
            sonido_pedido_listo VARCHAR(255) DEFAULT NULL,
            sonido_pedido_vencer VARCHAR(255) DEFAULT NULL,
            sonido_pedido_prioritario VARCHAR(255) DEFAULT NULL,
            sonido_mensajes VARCHAR(255) DEFAULT NULL,
            activar_sonidos TINYINT(1) NOT NULL DEFAULT 1,
            modulo_produccion TINYINT(1) NOT NULL DEFAULT 1,
            modulo_pedidos TINYINT(1) NOT NULL DEFAULT 1,
            modulo_proveedores TINYINT(1) NOT NULL DEFAULT 1,
            modulo_reportes TINYINT(1) NOT NULL DEFAULT 1,
            permitir_bitacora TINYINT(1) NOT NULL DEFAULT 1,
            permitir_papelera TINYINT(1) NOT NULL DEFAULT 1,
            backup_automatico TINYINT(1) NOT NULL DEFAULT 0,
            modo_mantenimiento TINYINT(1) NOT NULL DEFAULT 0,
            dias_alerta_vencer INT NOT NULL DEFAULT 1,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

$columnasConfig = [
    'logo' => "VARCHAR(255) DEFAULT 'logo.png'",
    'fondo_login' => "VARCHAR(255) DEFAULT NULL",
    'fondo_sidebar' => "VARCHAR(255) DEFAULT NULL",
    'fondo_contenido' => "VARCHAR(255) DEFAULT NULL",
    'imagen_publicitaria' => "VARCHAR(255) DEFAULT NULL",
    'logo_tiktok_shop' => "VARCHAR(255) DEFAULT NULL",
    'transparencia_panel' => "DECIMAL(4,2) NOT NULL DEFAULT 0.38",
    'transparencia_sidebar' => "DECIMAL(4,2) NOT NULL DEFAULT 0.88",
    'nombre_negocio' => "VARCHAR(150) DEFAULT 'Suave Urban Studio'",
    'descripcion_negocio' => "VARCHAR(255) DEFAULT NULL",
    'telefono_negocio' => "VARCHAR(50) DEFAULT NULL",
    'correo_negocio' => "VARCHAR(150) DEFAULT NULL",
    'direccion_negocio' => "TEXT DEFAULT NULL",
    'greenapi_instance' => "VARCHAR(120) DEFAULT NULL",
    'greenapi_token' => "VARCHAR(255) DEFAULT NULL",
    'activar_whatsapp' => "TINYINT(1) NOT NULL DEFAULT 1",
    'activar_qr_remision' => "TINYINT(1) NOT NULL DEFAULT 1",
    'activar_envio_remision_whatsapp' => "TINYINT(1) NOT NULL DEFAULT 1",
    'mensaje_remision_default' => "TEXT DEFAULT NULL",
    'pie_remision' => "TEXT DEFAULT NULL",
    'sonido_pedido_nuevo' => "VARCHAR(255) DEFAULT NULL",
    'sonido_pedido_listo' => "VARCHAR(255) DEFAULT NULL",
    'sonido_pedido_vencer' => "VARCHAR(255) DEFAULT NULL",
    'sonido_pedido_prioritario' => "VARCHAR(255) DEFAULT NULL",
    'sonido_mensajes' => "VARCHAR(255) DEFAULT NULL",
    'activar_sonidos' => "TINYINT(1) NOT NULL DEFAULT 1",
    'modulo_produccion' => "TINYINT(1) NOT NULL DEFAULT 1",
    'modulo_pedidos' => "TINYINT(1) NOT NULL DEFAULT 1",
    'modulo_proveedores' => "TINYINT(1) NOT NULL DEFAULT 1",
    'modulo_reportes' => "TINYINT(1) NOT NULL DEFAULT 1",
    'permitir_bitacora' => "TINYINT(1) NOT NULL DEFAULT 1",
    'permitir_papelera' => "TINYINT(1) NOT NULL DEFAULT 1",
    'backup_automatico' => "TINYINT(1) NOT NULL DEFAULT 0",
    'modo_mantenimiento' => "TINYINT(1) NOT NULL DEFAULT 0",
    'dias_alerta_vencer' => "INT NOT NULL DEFAULT 1"
];
foreach ($columnasConfig as $col => $def) {
    asegurarColumna($conn, 'configuracion', $col, $def);
}

$verificarConfig = $conn->query("SELECT id FROM configuracion WHERE id = 1 LIMIT 1");
if ($verificarConfig && $verificarConfig->num_rows === 0) {
    $conn->query("INSERT INTO configuracion (id) VALUES (1)");
}

/*
|--------------------------------------------------------------------------
| TABLA DE CONFIGURACIÓN DE USUARIOS
|--------------------------------------------------------------------------
*/
$sqlCrearTablaUsuariosConfig = "CREATE TABLE IF NOT EXISTS configuracion_usuarios (
    id INT AUTO_INCREMENT PRIMARY KEY,
    permitir_registro TINYINT(1) NOT NULL DEFAULT 1,
    solo_admin_registra TINYINT(1) NOT NULL DEFAULT 1,
    roles_permitidos VARCHAR(255) NOT NULL DEFAULT 'admin,mostrador,produccion',
    actualizado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
$conn->query($sqlCrearTablaUsuariosConfig);

$verificarConfigUsuarios = $conn->query("SELECT id FROM configuracion_usuarios WHERE id = 1 LIMIT 1");
if ($verificarConfigUsuarios && $verificarConfigUsuarios->num_rows === 0) {
    $conn->query("
        INSERT INTO configuracion_usuarios (
            id, permitir_registro, solo_admin_registra, roles_permitidos
        ) VALUES (
            1, 1, 1, 'admin,mostrador,produccion'
        )
    ");
} else {
    $conn->query("
        UPDATE configuracion_usuarios
        SET roles_permitidos = 'admin,mostrador,produccion'
        WHERE id = 1
    ");
}

/*
|--------------------------------------------------------------------------
| ACCIONES ESPECIALES
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'descargar_backup') {
    ejecutarRespaldo($conn);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'reiniciar_sistema') {
    try {
        reiniciarSistemaEnCeros($conn);
        $mensaje = "✅ Sistema reiniciado en ceros correctamente. Inventario, usuarios y configuración siguen intactos.";
        $tipoMensaje = 'ok';
    } catch (Throwable $e) {
        $mensaje = "❌ Error al reiniciar sistema: " . $e->getMessage();
        $tipoMensaje = 'error';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'optimizar_sistema') {
    try {
        $tablas = optimizarSistema($conn);
        $mensaje = "✅ Sistema optimizado y reparado. Tablas revisadas: " . $tablas;
        $tipoMensaje = 'ok';
    } catch (Throwable $e) {
        $mensaje = "❌ Error al optimizar el sistema: " . $e->getMessage();
        $tipoMensaje = 'error';
    }
}

/*
|--------------------------------------------------------------------------
| GUARDAR
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (isset($_POST['accion']) && $_POST['accion'] === 'guardar_apariencia') {
        $target_dir = 'uploads/';
        $errores = [];
        $updates = [];

        $archivos = [
            'logo',
            'fondo_login',
            'fondo_sidebar',
            'fondo_contenido',
            'imagen_publicitaria',
            'logo_tiktok_shop'
        ];

        foreach ($archivos as $campo) {
            $rutaNueva = subirArchivo($campo, $target_dir, $errores, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true);
            if ($rutaNueva !== null) {
                $rutaEscapada = $conn->real_escape_string($rutaNueva);
                $updates[] = "`$campo` = '{$rutaEscapada}'";
            }
        }

        $transparencia_panel = isset($_POST['transparencia_panel']) ? (float)$_POST['transparencia_panel'] : 0.38;
        $transparencia_sidebar = isset($_POST['transparencia_sidebar']) ? (float)$_POST['transparencia_sidebar'] : 0.88;

        $transparencia_panel = max(0.10, min(0.95, $transparencia_panel));
        $transparencia_sidebar = max(0.10, min(0.98, $transparencia_sidebar));

        $updates[] = "`transparencia_panel` = " . number_format($transparencia_panel, 2, '.', '');
        $updates[] = "`transparencia_sidebar` = " . number_format($transparencia_sidebar, 2, '.', '');

        if (empty($errores)) {
            if (!empty($updates)) {
                $sqlUpdate = "UPDATE configuracion SET " . implode(', ', $updates) . " WHERE id = 1";
                if ($conn->query($sqlUpdate)) {
                    $mensaje = "✅ ¡Configuración visual guardada exitosamente!";
                    $tipoMensaje = 'ok';
                } else {
                    $mensaje = "❌ Error al guardar configuración visual: " . $conn->error;
                    $tipoMensaje = 'error';
                }
            } else {
                $mensaje = "✅ No hubo cambios nuevos, pero la configuración sigue correcta.";
                $tipoMensaje = 'ok';
            }
        } else {
            $mensaje = "❌ " . implode(' | ', $errores);
            $tipoMensaje = 'error';
        }
    }

    if (isset($_POST['accion']) && $_POST['accion'] === 'guardar_datos_negocio') {
        $nombre_negocio = $conn->real_escape_string(trim((string)($_POST['nombre_negocio'] ?? 'Suave Urban Studio')));
        $descripcion_negocio = $conn->real_escape_string(trim((string)($_POST['descripcion_negocio'] ?? '')));
        $telefono_negocio = $conn->real_escape_string(trim((string)($_POST['telefono_negocio'] ?? '')));
        $correo_negocio = $conn->real_escape_string(trim((string)($_POST['correo_negocio'] ?? '')));
        $direccion_negocio = $conn->real_escape_string(trim((string)($_POST['direccion_negocio'] ?? '')));

        if ($conn->query("
            UPDATE configuracion SET
                nombre_negocio = '{$nombre_negocio}',
                descripcion_negocio = '{$descripcion_negocio}',
                telefono_negocio = '{$telefono_negocio}',
                correo_negocio = '{$correo_negocio}',
                direccion_negocio = '{$direccion_negocio}'
            WHERE id = 1
        ")) {
            $mensaje = "✅ Datos del negocio guardados correctamente.";
            $tipoMensaje = 'ok';
        } else {
            $mensaje = "❌ Error al guardar datos del negocio: " . $conn->error;
            $tipoMensaje = 'error';
        }
    }

    if (isset($_POST['accion']) && $_POST['accion'] === 'guardar_whatsapp_remision') {
        $greenapi_instance = $conn->real_escape_string(trim((string)($_POST['greenapi_instance'] ?? '')));
        $greenapi_token = $conn->real_escape_string(trim((string)($_POST['greenapi_token'] ?? '')));
        $mensaje_remision_default = $conn->real_escape_string(trim((string)($_POST['mensaje_remision_default'] ?? '')));
        $pie_remision = $conn->real_escape_string(trim((string)($_POST['pie_remision'] ?? '')));
        $activar_whatsapp = isset($_POST['activar_whatsapp']) ? 1 : 0;
        $activar_qr_remision = isset($_POST['activar_qr_remision']) ? 1 : 0;
        $activar_envio_remision_whatsapp = isset($_POST['activar_envio_remision_whatsapp']) ? 1 : 0;
        $dias_alerta_vencer = max(0, (int)($_POST['dias_alerta_vencer'] ?? 1));

        if ($conn->query("
            UPDATE configuracion SET
                greenapi_instance = '{$greenapi_instance}',
                greenapi_token = '{$greenapi_token}',
                activar_whatsapp = {$activar_whatsapp},
                activar_qr_remision = {$activar_qr_remision},
                activar_envio_remision_whatsapp = {$activar_envio_remision_whatsapp},
                mensaje_remision_default = '{$mensaje_remision_default}',
                pie_remision = '{$pie_remision}',
                dias_alerta_vencer = {$dias_alerta_vencer}
            WHERE id = 1
        ")) {
            $mensaje = "✅ Configuración de WhatsApp y remisión guardada correctamente.";
            $tipoMensaje = 'ok';
        } else {
            $mensaje = "❌ Error al guardar WhatsApp/remisión: " . $conn->error;
            $tipoMensaje = 'error';
        }
    }

    if (isset($_POST['accion']) && $_POST['accion'] === 'guardar_sonidos') {
        $target_dir = 'sonidos/';
        $errores = [];
        $updates = [];

        $archivosSonido = [
            'sonido_pedido_nuevo',
            'sonido_pedido_listo',
            'sonido_pedido_vencer',
            'sonido_pedido_prioritario',
            'sonido_mensajes'
        ];

        foreach ($archivosSonido as $campo) {
            $rutaNueva = subirArchivo($campo, $target_dir, $errores, ['mp3', 'wav', 'ogg'], false);
            if ($rutaNueva !== null) {
                $rutaEscapada = $conn->real_escape_string($rutaNueva);
                $updates[] = "`$campo` = '{$rutaEscapada}'";
            }
        }

        $activar_sonidos = isset($_POST['activar_sonidos']) ? 1 : 0;
        $updates[] = "`activar_sonidos` = {$activar_sonidos}";

        if (empty($errores)) {
            if (!empty($updates)) {
                $sqlUpdate = "UPDATE configuracion SET " . implode(', ', $updates) . " WHERE id = 1";
                if ($conn->query($sqlUpdate)) {
                    $mensaje = "✅ Configuración de sonidos guardada correctamente.";
                    $tipoMensaje = 'ok';
                } else {
                    $mensaje = "❌ Error al guardar sonidos: " . $conn->error;
                    $tipoMensaje = 'error';
                }
            }
        } else {
            $mensaje = "❌ " . implode(' | ', $errores);
            $tipoMensaje = 'error';
        }
    }

    if (isset($_POST['accion']) && $_POST['accion'] === 'guardar_modulos_sistema') {
        $modulo_produccion = isset($_POST['modulo_produccion']) ? 1 : 0;
        $modulo_pedidos = isset($_POST['modulo_pedidos']) ? 1 : 0;
        $modulo_proveedores = isset($_POST['modulo_proveedores']) ? 1 : 0;
        $modulo_reportes = isset($_POST['modulo_reportes']) ? 1 : 0;
        $permitir_bitacora = isset($_POST['permitir_bitacora']) ? 1 : 0;
        $permitir_papelera = isset($_POST['permitir_papelera']) ? 1 : 0;
        $backup_automatico = isset($_POST['backup_automatico']) ? 1 : 0;
        $modo_mantenimiento = isset($_POST['modo_mantenimiento']) ? 1 : 0;

        if ($conn->query("
            UPDATE configuracion SET
                modulo_produccion = {$modulo_produccion},
                modulo_pedidos = {$modulo_pedidos},
                modulo_proveedores = {$modulo_proveedores},
                modulo_reportes = {$modulo_reportes},
                permitir_bitacora = {$permitir_bitacora},
                permitir_papelera = {$permitir_papelera},
                backup_automatico = {$backup_automatico},
                modo_mantenimiento = {$modo_mantenimiento}
            WHERE id = 1
        ")) {
            $mensaje = "✅ Módulos y sistema guardados correctamente.";
            $tipoMensaje = 'ok';
        } else {
            $mensaje = "❌ Error al guardar módulos: " . $conn->error;
            $tipoMensaje = 'error';
        }
    }

    if (isset($_POST['accion']) && $_POST['accion'] === 'guardar_config_usuarios') {
        $permitir_registro = isset($_POST['permitir_registro']) ? 1 : 0;
        $solo_admin_registra = isset($_POST['solo_admin_registra']) ? 1 : 0;

        $roles = [];
        if (!empty($_POST['roles_permitidos']) && is_array($_POST['roles_permitidos'])) {
            foreach ($_POST['roles_permitidos'] as $rol) {
                $rol = trim((string)$rol);
                if (in_array($rol, ['admin', 'mostrador', 'produccion'], true)) {
                    $roles[] = $rol;
                }
            }
        }

        if (empty($roles)) {
            $roles[] = 'admin';
        }

        $rolesTexto = $conn->real_escape_string(implode(',', $roles));

        if ($conn->query("
            UPDATE configuracion_usuarios SET
                permitir_registro = {$permitir_registro},
                solo_admin_registra = {$solo_admin_registra},
                roles_permitidos = '{$rolesTexto}'
            WHERE id = 1
        ")) {
            $mensaje = "✅ Configuración de usuarios guardada correctamente.";
            $tipoMensaje = 'ok';
        } else {
            $mensaje = "❌ Error al guardar configuración de usuarios: " . $conn->error;
            $tipoMensaje = 'error';
        }
    }
}

/*
|--------------------------------------------------------------------------
| OBTENER CONFIGURACIÓN
|--------------------------------------------------------------------------
*/
$configRes = $conn->query("SELECT * FROM configuracion WHERE id = 1 LIMIT 1");
$config = $configRes ? $configRes->fetch_assoc() : [];

$configUsuariosRes = $conn->query("SELECT * FROM configuracion_usuarios WHERE id = 1 LIMIT 1");
$configUsuarios = $configUsuariosRes ? $configUsuariosRes->fetch_assoc() : [];

$rolesGuardados = [];
if (!empty($configUsuarios['roles_permitidos'])) {
    $rolesGuardados = explode(',', $configUsuarios['roles_permitidos']);
}

$logoActual = $config['logo'] ?? 'logo.png';
$fondoSidebar = $config['fondo_sidebar'] ?? '';
$fondoContenido = $config['fondo_contenido'] ?? '';
$transparenciaPanel = isset($config['transparencia_panel']) ? (float)$config['transparencia_panel'] : 0.38;
$transparenciaSidebar = isset($config['transparencia_sidebar']) ? (float)$config['transparencia_sidebar'] : 0.88;

$alphaPanel = max(0.10, min(0.95, $transparenciaPanel));
$alphaSidebar = max(0.10, min(0.98, $transparenciaSidebar));
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Configuración - Suave Urban Studio</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { box-sizing: border-box; }

        body {
            margin: 0;
            min-height: 100vh;
            font-family: 'Segoe UI', sans-serif;
            background:
                <?php echo !empty($fondoContenido)
                    ? "linear-gradient(rgba(0,0,0,0.46), rgba(0,0,0,0.64)), url('" . esc($fondoContenido) . "') center/cover fixed no-repeat"
                    : "linear-gradient(135deg,#090909,#16161b)"; ?>;
            color: #fff;
            display: flex;
            overflow-x: hidden;
            position: relative;
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
        }

        .mobile-topbar { display: none; }
        .mobile-menu-toggle { display: none; }
        .sidebar-overlay { display: none; }

        .sidebar {
            width: 85px;
            position: fixed;
            top: 0;
            left: 0;
            height: 100vh;
            padding: 15px 0;
            display: flex;
            flex-direction: column;
            align-items: center;
            overflow-y: auto;
            z-index: 1000;
            background:
                <?php echo !empty($fondoSidebar)
                    ? "linear-gradient(rgba(0,0,0," . $alphaSidebar . "), rgba(0,0,0," . $alphaSidebar . ")), url('" . esc($fondoSidebar) . "') center/cover no-repeat"
                    : "rgba(0,0,0," . $alphaSidebar . ")"; ?>;
            border-right: 1px solid rgba(200,155,60,0.18);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            box-shadow: 0 10px 40px rgba(0,0,0,0.35);
        }

        .logo-wrap {
            width: 56px;
            height: 56px;
            border-radius: 16px;
            overflow: hidden;
            margin-bottom: 16px;
            box-shadow: 0 0 18px rgba(200,155,60,0.25);
            animation: logoPulse 4s ease-in-out infinite, logoGlow 3s infinite alternate;
        }

        .logo-wrap img {
            width: 100%;
            height: 100%;
            object-fit: contain;
            background: rgba(255,255,255,0.04);
            animation: logoFloat 4s ease-in-out infinite;
        }

        nav {
            width: 100%;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 16px;
            padding-bottom: 22px;
            border-bottom: 1px solid rgba(200,155,60,0.14);
            margin-bottom: 24px;
        }

        .nav-link {
            color: #666;
            font-size: 20px;
            text-decoration: none;
            transition: .25s ease;
        }

        .nav-link:hover, .nav-link.active {
            color: #c89b3c;
            filter: drop-shadow(0 0 8px #c89b3c);
        }

        .main {
            flex: 1;
            margin-left: 85px;
            padding: 24px;
            width: calc(100% - 85px);
            position: relative;
            z-index: 2;
        }

        .page-wrap { max-width: 1400px; margin: 0 auto; }

        .page-title {
            margin: 0 0 18px;
            font-size: 34px;
            font-weight: 800;
            text-shadow: 0 0 12px rgba(200,155,60,0.18);
        }

        .msg-box {
            margin-bottom: 18px;
            padding: 14px 16px;
            border-radius: 16px;
            font-weight: 600;
            box-shadow: 0 12px 28px rgba(0,0,0,0.22);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            border: 1px solid rgba(200,155,60,0.20);
            transition: opacity .4s ease;
        }

        .msg-box.ok {
            background: rgba(34,197,94,0.10);
            color: #bbf7d0;
        }

        .msg-box.error {
            background: rgba(239,68,68,0.12);
            color: #fecaca;
            border-color: rgba(239,68,68,0.25);
        }

        .section-title {
            margin: 26px 0 12px;
            color: #c89b3c;
            font-size: 18px;
            font-weight: 800;
            letter-spacing: .6px;
            text-transform: uppercase;
        }

        .card {
            background: rgba(255,255,255,<?php echo max(0.03, min(0.18, $alphaPanel * 0.35)); ?>);
            border: 1px solid rgba(200,155,60,0.18);
            border-radius: 22px;
            padding: 20px;
            margin-bottom: 16px;
            box-shadow: 0 14px 34px rgba(0,0,0,0.26);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            transition: all .35s ease;
        }

        .card:hover{
            transform: translateY(-4px);
            box-shadow:
                0 10px 25px rgba(0,0,0,.6),
                0 0 18px rgba(200,155,60,.35);
        }

        .grid-2, .grid-3 {
            display: grid;
            gap: 16px;
        }

        .grid-2 { grid-template-columns: repeat(2, minmax(0,1fr)); }
        .grid-3 { grid-template-columns: repeat(3, minmax(0,1fr)); }

        .row-flex {
            display: flex;
            gap: 16px;
            align-items: flex-start;
            flex-wrap: wrap;
        }

        .label-title {
            display: block;
            font-size: 15px;
            font-weight: 800;
            margin-bottom: 10px;
            color: #fff;
        }

        .info-text {
            margin-top: 8px;
            color: #d1d1d1;
            font-size: 13px;
            line-height: 1.55;
        }

        input[type="text"],
        input[type="email"],
        input[type="number"],
        input[type="file"],
        textarea {
            width: 100%;
            border: 1px solid rgba(255,255,255,0.10);
            background: rgba(0,0,0,0.26);
            color: #fff;
            border-radius: 14px;
            padding: 12px 14px;
            font-family: inherit;
            outline: none;
        }

        input[type="text"]:focus,
        input[type="email"]:focus,
        input[type="number"]:focus,
        textarea:focus{
            border-color:#c89b3c;
            box-shadow:0 0 14px rgba(200,155,60,0.18);
        }

        textarea { min-height: 110px; resize: vertical; }

        .preview {
            width: 180px;
            height: 180px;
            object-fit: cover;
            border-radius: 16px;
            border: 1px solid rgba(255,255,255,0.08);
            background: rgba(0,0,0,0.22);
        }

        .logo-preview { object-fit: contain; }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            border: none;
            border-radius: 14px;
            padding: 12px 18px;
            font-weight: 800;
            cursor: pointer;
            background: #c89b3c;
            color: #111;
            transition: .25s ease;
            position: relative;
            overflow: hidden;
        }

        .btn::after{
            content:"";
            position:absolute;
            top:-50%;
            left:-60%;
            width:20%;
            height:200%;
            background:rgba(255,255,255,.4);
            transform:rotate(30deg);
            animation:btnshine 4s infinite;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 24px rgba(200,155,60,0.25);
        }

        .btn-danger { background: #ef4444; color: #fff; }
        .btn-dark { background: #20222b; color: #fff; }
        .btn-green { background: #16a34a; color: #fff; }

        .check-group {
            display: grid;
            gap: 10px;
        }

        .check-item {
            padding: 12px 14px;
            border-radius: 14px;
            background: rgba(255,255,255,0.04);
            border: 1px solid rgba(255,255,255,0.06);
        }

        .check-item label {
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 600;
        }

        .mini-title {
            font-weight: 800;
            color: #c89b3c;
            margin-bottom: 8px;
        }

        .glass-demo {
            min-height: 160px;
            border-radius: 18px;
            padding: 16px;
            border: 1px solid rgba(255,255,255,0.08);
            background:
                <?php echo !empty($fondoSidebar)
                    ? "linear-gradient(rgba(0,0,0," . $alphaSidebar . "), rgba(0,0,0," . $alphaSidebar . ")), url('" . esc($fondoSidebar) . "') center/cover no-repeat"
                    : "rgba(20,21,26," . $alphaSidebar . ")"; ?>;
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
        }

        @keyframes logoPulse{
            0%,100%{transform:scale(1);}
            50%{transform:scale(1.06);}
        }

        @keyframes logoGlow{
            from{box-shadow:0 0 12px rgba(200,155,60,0.18);}
            to{box-shadow:0 0 30px rgba(200,155,60,0.45);}
        }

        @keyframes logoFloat{
            0%,100%{transform:translateY(0);}
            50%{transform:translateY(-3px);}
        }

        @keyframes btnshine{
            0%{left:-60%;}
            15%,100%{left:120%;}
        }

        @keyframes particlesMove{
            0%{transform:translateY(0);}
            100%{transform:translateY(-40px);}
        }

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
                background: rgba(0,0,0,0.9);
                border-bottom: 1px solid rgba(200,155,60,0.15);
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
                animation: logoPulse 4s ease-in-out infinite, logoGlow 3s infinite alternate;
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
                color: #c89b3c;
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

            body.menu-open .sidebar {
                transform: translateX(0);
            }

            body.menu-open .sidebar-overlay {
                opacity: 1;
                visibility: visible;
            }

            .main {
                margin-left: 0;
                width: 100%;
                padding: 18px 14px;
            }

            .grid-2, .grid-3 { grid-template-columns: 1fr; }
        }

        @media (max-width: 768px) {
            .page-title { font-size: 28px; }
            .preview { width: 100%; height: 140px; }
            .btn { width: 100%; }
        }
    </style>
</head>
<body>

    <div class="mobile-topbar">
        <div class="mobile-topbar-left">
            <img src="<?php echo esc($logoActual ?: 'logo.png'); ?>" alt="Logo" class="mobile-topbar-logo">
            <div class="mobile-topbar-title">Configuración Studio</div>
        </div>
        <button class="mobile-menu-toggle" id="mobileMenuToggle" aria-label="Abrir menú">
            <i class="fas fa-bars"></i>
        </button>
    </div>

    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <aside class="sidebar" id="sidebar">
        <div class="logo-wrap">
            <img src="<?php echo esc($logoActual ?: 'logo.png'); ?>" alt="Logo">
        </div>

        <nav>
            <a href="dashboard.php" class="nav-link"><i class="fas fa-home"></i></a>
            <a href="ventas.php" class="nav-link"><i class="fas fa-cash-register"></i></a>
            <a href="productos.php" class="nav-link"><i class="fas fa-box"></i></a>
            <a href="clientes.php" class="nav-link"><i class="fas fa-users"></i></a>
            <a href="usuarios.php" class="nav-link"><i class="fas fa-user-shield"></i></a>
            <a href="pedidos.php" class="nav-link"><i class="fas fa-tasks"></i></a>
            <a href="configuracion.php" class="nav-link active"><i class="fas fa-cog"></i></a>
            <a href="logout.php" class="nav-link"><i class="fas fa-sign-out-alt"></i></a>
        </nav>
    </aside>

    <main class="main">
        <div class="page-wrap">
            <h1 class="page-title">Configuración visual y del sistema</h1>

            <?php if ($mensaje): ?>
                <div id="mensajeFlash" class="msg-box <?php echo $tipoMensaje === 'error' ? 'error' : 'ok'; ?>">
                    <?php echo $mensaje; ?>
                </div>
            <?php endif; ?>

            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="accion" value="guardar_apariencia">

                <div class="section-title">Apariencia general</div>

                <div class="card">
                    <span class="label-title">Logotipo principal</span>
                    <div class="row-flex">
                        <?php if (!empty($config['logo'])): ?>
                            <img src="<?php echo esc($config['logo']); ?>" class="preview logo-preview" alt="Logo actual">
                        <?php endif; ?>
                        <div style="flex:1; min-width:260px;">
                            <input type="file" name="logo" accept="image/*">
                            <p class="info-text">Este logo se usa en login, dashboard, ventas y demás módulos.</p>
                        </div>
                    </div>
                </div>

                <div class="grid-2">
                    <div class="card">
                        <span class="label-title">Logo TikTok Shop</span>
                        <div class="row-flex">
                            <?php if (!empty($config['logo_tiktok_shop'])): ?>
                                <img src="<?php echo esc($config['logo_tiktok_shop']); ?>" class="preview logo-preview" alt="Logo TikTok">
                            <?php endif; ?>
                            <div style="flex:1; min-width:260px;">
                                <input type="file" name="logo_tiktok_shop" accept="image/*">
                                <p class="info-text">Logo usado en la pantalla de acceso si lo ocupas.</p>
                            </div>
                        </div>
                    </div>

                    <div class="card">
                        <span class="label-title">Imagen publicitaria del login</span>
                        <div class="row-flex">
                            <?php if (!empty($config['imagen_publicitaria'])): ?>
                                <img src="<?php echo esc($config['imagen_publicitaria']); ?>" class="preview" alt="Imagen publicitaria">
                            <?php endif; ?>
                            <div style="flex:1; min-width:260px;">
                                <input type="file" name="imagen_publicitaria" accept="image/*">
                                <p class="info-text">Esta imagen sale en el bloque publicitario del login.</p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="grid-2">
                    <div class="card">
                        <span class="label-title">Fondo del sidebar</span>
                        <div class="row-flex">
                            <?php if (!empty($config['fondo_sidebar'])): ?>
                                <img src="<?php echo esc($config['fondo_sidebar']); ?>" class="preview" alt="Fondo sidebar">
                            <?php endif; ?>
                            <div style="flex:1; min-width:260px;">
                                <input type="file" name="fondo_sidebar" accept="image/*">
                                <p class="info-text">Imagen que se mostrará detrás del menú lateral.</p>
                            </div>
                        </div>
                    </div>

                    <div class="card">
                        <span class="label-title">Fondo del contenido</span>
                        <div class="row-flex">
                            <?php if (!empty($config['fondo_contenido'])): ?>
                                <img src="<?php echo esc($config['fondo_contenido']); ?>" class="preview" alt="Fondo contenido">
                            <?php endif; ?>
                            <div style="flex:1; min-width:260px;">
                                <input type="file" name="fondo_contenido" accept="image/*">
                                <p class="info-text">Imagen general para el fondo principal del sistema.</p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="grid-2">
                    <div class="card">
                        <span class="label-title">Fondo login</span>
                        <div class="row-flex">
                            <?php if (!empty($config['fondo_login'])): ?>
                                <img src="<?php echo esc($config['fondo_login']); ?>" class="preview" alt="Fondo login">
                            <?php endif; ?>
                            <div style="flex:1; min-width:260px;">
                                <input type="file" name="fondo_login" accept="image/*">
                                <p class="info-text">Fondo exclusivo para la pantalla de acceso.</p>
                            </div>
                        </div>
                    </div>

                    <div class="card">
                        <span class="label-title">Transparencias</span>
                        <div class="grid-2">
                            <div>
                                <label class="label-title" style="font-size:14px;">Transparencia de paneles</label>
                                <input type="number" step="0.01" min="0.10" max="0.95" name="transparencia_panel" value="<?php echo esc($config['transparencia_panel'] ?? '0.38'); ?>">
                                <p class="info-text">Entre 0.10 y 0.95</p>
                            </div>
                            <div>
                                <label class="label-title" style="font-size:14px;">Transparencia del sidebar</label>
                                <input type="number" step="0.01" min="0.10" max="0.98" name="transparencia_sidebar" value="<?php echo esc($config['transparencia_sidebar'] ?? '0.88'); ?>">
                                <p class="info-text">Entre 0.10 y 0.98</p>
                            </div>
                        </div>

                        <div class="glass-demo" style="margin-top:12px;">
                            Vista previa del sidebar<br>
                            <small style="color:#ddd;">Así se mezcla la imagen con el menú lateral.</small>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <button type="submit" class="btn">Guardar apariencia</button>
                </div>
            </form>

            <form method="POST">
                <input type="hidden" name="accion" value="guardar_datos_negocio">
                <div class="section-title">Datos del negocio</div>
                <div class="card">
                    <div class="grid-2">
                        <div>
                            <span class="label-title">Nombre del negocio</span>
                            <input type="text" name="nombre_negocio" value="<?php echo esc($config['nombre_negocio'] ?? 'Suave Urban Studio'); ?>">
                        </div>
                        <div>
                            <span class="label-title">Descripción</span>
                            <input type="text" name="descripcion_negocio" value="<?php echo esc($config['descripcion_negocio'] ?? ''); ?>">
                        </div>
                    </div>
                    <div class="grid-2" style="margin-top:12px;">
                        <div>
                            <span class="label-title">Teléfono</span>
                            <input type="text" name="telefono_negocio" value="<?php echo esc($config['telefono_negocio'] ?? ''); ?>">
                        </div>
                        <div>
                            <span class="label-title">Correo</span>
                            <input type="email" name="correo_negocio" value="<?php echo esc($config['correo_negocio'] ?? ''); ?>">
                        </div>
                    </div>
                    <div style="margin-top:12px;">
                        <span class="label-title">Dirección</span>
                        <textarea name="direccion_negocio"><?php echo esc($config['direccion_negocio'] ?? ''); ?></textarea>
                    </div>
                    <div style="margin-top:18px;">
                        <button type="submit" class="btn">Guardar datos del negocio</button>
                    </div>
                </div>
            </form>

            <form method="POST">
                <input type="hidden" name="accion" value="guardar_whatsapp_remision">
                <div class="section-title">WhatsApp y remisión</div>
                <div class="card">
                    <div class="grid-2">
                        <div>
                            <span class="label-title">Green API Instance</span>
                            <input type="text" name="greenapi_instance" value="<?php echo esc($config['greenapi_instance'] ?? ''); ?>">
                        </div>
                        <div>
                            <span class="label-title">Green API Token</span>
                            <input type="text" name="greenapi_token" value="<?php echo esc($config['greenapi_token'] ?? ''); ?>">
                        </div>
                    </div>

                    <div class="check-group" style="margin-top:14px;">
                        <div class="check-item"><label><input type="checkbox" name="activar_whatsapp" <?php echo valorCheck($config, 'activar_whatsapp', 1) ? 'checked' : ''; ?>> Activar WhatsApp</label></div>
                        <div class="check-item"><label><input type="checkbox" name="activar_qr_remision" <?php echo valorCheck($config, 'activar_qr_remision', 1) ? 'checked' : ''; ?>> Activar QR en remisión</label></div>
                        <div class="check-item"><label><input type="checkbox" name="activar_envio_remision_whatsapp" <?php echo valorCheck($config, 'activar_envio_remision_whatsapp', 1) ? 'checked' : ''; ?>> Enviar remisión por WhatsApp automáticamente</label></div>
                    </div>

                    <div class="grid-2" style="margin-top:14px;">
                        <div>
                            <span class="label-title">Mensaje de remisión por defecto</span>
                            <textarea name="mensaje_remision_default"><?php echo esc($config['mensaje_remision_default'] ?? ''); ?></textarea>
                        </div>
                        <div>
                            <span class="label-title">Pie de remisión</span>
                            <textarea name="pie_remision"><?php echo esc($config['pie_remision'] ?? ''); ?></textarea>
                        </div>
                    </div>

                    <div style="margin-top:12px;">
                        <span class="label-title">Días para alerta de vencimiento</span>
                        <input type="number" min="0" max="30" name="dias_alerta_vencer" value="<?php echo esc($config['dias_alerta_vencer'] ?? 1); ?>">
                    </div>

                    <div style="margin-top:18px;">
                        <button type="submit" class="btn">Guardar WhatsApp y remisión</button>
                    </div>
                </div>
            </form>

            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="accion" value="guardar_sonidos">
                <div class="section-title">Sonidos del sistema</div>
                <div class="card">
                    <div class="check-group">
                        <div class="check-item"><label><input type="checkbox" name="activar_sonidos" <?php echo valorCheck($config, 'activar_sonidos', 1) ? 'checked' : ''; ?>> Activar sonidos del sistema</label></div>
                    </div>

                    <div class="grid-2" style="margin-top:14px;">
                        <div>
                            <span class="label-title">Sonido pedido nuevo</span>
                            <input type="file" name="sonido_pedido_nuevo" accept=".mp3,.wav,.ogg">
                            <?php if (!empty($config['sonido_pedido_nuevo'])): ?><p class="info-text">Actual: <?php echo esc($config['sonido_pedido_nuevo']); ?></p><?php endif; ?>
                        </div>
                        <div>
                            <span class="label-title">Sonido pedido listo</span>
                            <input type="file" name="sonido_pedido_listo" accept=".mp3,.wav,.ogg">
                            <?php if (!empty($config['sonido_pedido_listo'])): ?><p class="info-text">Actual: <?php echo esc($config['sonido_pedido_listo']); ?></p><?php endif; ?>
                        </div>
                    </div>

                    <div class="grid-2" style="margin-top:12px;">
                        <div>
                            <span class="label-title">Sonido pedido por vencer</span>
                            <input type="file" name="sonido_pedido_vencer" accept=".mp3,.wav,.ogg">
                            <?php if (!empty($config['sonido_pedido_vencer'])): ?><p class="info-text">Actual: <?php echo esc($config['sonido_pedido_vencer']); ?></p><?php endif; ?>
                        </div>
                        <div>
                            <span class="label-title">Sonido pedido prioritario</span>
                            <input type="file" name="sonido_pedido_prioritario" accept=".mp3,.wav,.ogg">
                            <?php if (!empty($config['sonido_pedido_prioritario'])): ?><p class="info-text">Actual: <?php echo esc($config['sonido_pedido_prioritario']); ?></p><?php endif; ?>
                        </div>
                    </div>

                    <div style="margin-top:12px;">
                        <span class="label-title">Sonido de mensajes internos</span>
                        <input type="file" name="sonido_mensajes" accept=".mp3,.wav,.ogg">
                        <?php if (!empty($config['sonido_mensajes'])): ?><p class="info-text">Actual: <?php echo esc($config['sonido_mensajes']); ?></p><?php endif; ?>
                    </div>

                    <div style="margin-top:18px;">
                        <button type="submit" class="btn">Guardar sonidos</button>
                    </div>
                </div>
            </form>

            <form method="POST">
                <input type="hidden" name="accion" value="guardar_modulos_sistema">
                <div class="section-title">Módulos, bitácora y sistema</div>
                <div class="card">
                    <div class="grid-2">
                        <div class="check-group">
                            <div class="check-item"><label><input type="checkbox" name="modulo_produccion" <?php echo valorCheck($config, 'modulo_produccion', 1) ? 'checked' : ''; ?>> Activar módulo Producción</label></div>
                            <div class="check-item"><label><input type="checkbox" name="modulo_pedidos" <?php echo valorCheck($config, 'modulo_pedidos', 1) ? 'checked' : ''; ?>> Activar módulo Pedidos</label></div>
                            <div class="check-item"><label><input type="checkbox" name="modulo_proveedores" <?php echo valorCheck($config, 'modulo_proveedores', 1) ? 'checked' : ''; ?>> Activar módulo Proveedores</label></div>
                            <div class="check-item"><label><input type="checkbox" name="modulo_reportes" <?php echo valorCheck($config, 'modulo_reportes', 1) ? 'checked' : ''; ?>> Activar módulo Reportes</label></div>
                        </div>
                        <div class="check-group">
                            <div class="check-item"><label><input type="checkbox" name="permitir_bitacora" <?php echo valorCheck($config, 'permitir_bitacora', 1) ? 'checked' : ''; ?>> Permitir bitácora</label></div>
                            <div class="check-item"><label><input type="checkbox" name="permitir_papelera" <?php echo valorCheck($config, 'permitir_papelera', 1) ? 'checked' : ''; ?>> Permitir papelera</label></div>
                            <div class="check-item"><label><input type="checkbox" name="backup_automatico" <?php echo valorCheck($config, 'backup_automatico', 0) ? 'checked' : ''; ?>> Activar backup automático</label></div>
                            <div class="check-item"><label><input type="checkbox" name="modo_mantenimiento" <?php echo valorCheck($config, 'modo_mantenimiento', 0) ? 'checked' : ''; ?>> Modo mantenimiento</label></div>
                        </div>
                    </div>

                    <div style="margin-top:18px;">
                        <button type="submit" class="btn">Guardar módulos y sistema</button>
                    </div>
                </div>
            </form>

            <form method="POST">
                <input type="hidden" name="accion" value="guardar_config_usuarios">
                <div class="section-title">Usuarios</div>
                <div class="card">
                    <div class="check-group">
                        <div class="check-item">
                            <label>
                                <input type="checkbox" name="permitir_registro" <?php echo !empty($configUsuarios['permitir_registro']) ? 'checked' : ''; ?>>
                                Permitir registro de usuarios
                            </label>
                        </div>
                        <div class="check-item">
                            <label>
                                <input type="checkbox" name="solo_admin_registra" <?php echo !empty($configUsuarios['solo_admin_registra']) ? 'checked' : ''; ?>>
                                Solo admin puede registrar
                            </label>
                        </div>
                    </div>

                    <div style="margin-top:18px;">
                        <span class="label-title">Roles permitidos</span>
                        <div class="check-group">
                            <?php foreach (['admin' => 'Admin', 'mostrador' => 'Mostrador', 'produccion' => 'Producción'] as $rol => $texto): ?>
                                <div class="check-item">
                                    <label>
                                        <input type="checkbox" name="roles_permitidos[]" value="<?php echo esc($rol); ?>" <?php echo in_array($rol, $rolesGuardados, true) ? 'checked' : ''; ?>>
                                        <?php echo esc($texto); ?>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <p class="info-text">Selecciona los roles que podrán usarse al registrar usuarios.</p>
                    </div>

                    <div style="margin-top:18px;">
                        <button type="submit" class="btn">Guardar configuración de usuarios</button>
                    </div>
                </div>
            </form>

            <div class="section-title">Respaldo, mantenimiento y reinicio</div>
            <div class="grid-3">
                <form method="POST" class="card">
                    <input type="hidden" name="accion" value="descargar_backup">
                    <div class="mini-title">Descargar respaldo</div>
                    <p class="info-text">Descarga un respaldo SQL completo del sistema actual.</p>
                    <button type="submit" class="btn btn-green">Descargar respaldo</button>
                </form>

                <form method="POST" class="card" onsubmit="return confirm('¿Optimizar y reparar tablas del sistema?');">
                    <input type="hidden" name="accion" value="optimizar_sistema">
                    <div class="mini-title">Optimizar sistema</div>
                    <p class="info-text">Optimiza y repara tablas para dejar la web más estable en Hostinger.</p>
                    <button type="submit" class="btn btn-dark">Optimizar y reparar</button>
                </form>

                <form method="POST" class="card" onsubmit="return confirm('¿Reiniciar sistema en ceros? No borrará inventario, usuarios ni configuración.');">
                    <input type="hidden" name="accion" value="reiniciar_sistema">
                    <div class="mini-title">Reiniciar sistema en ceros</div>
                    <p class="info-text">Vacía ventas, clientes, pedidos, proveedores, facturas y demás datos operativos.</p>
                    <button type="submit" class="btn btn-danger">Reiniciar sistema</button>
                </form>
            </div>
        </div>
    </main>

    <script>
    document.addEventListener('DOMContentLoaded', function () {
        const body = document.body;
        const mobileMenuToggle = document.getElementById('mobileMenuToggle');
        const sidebarOverlay = document.getElementById('sidebarOverlay');
        const mensajeFlash = document.getElementById('mensajeFlash');

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

        window.addEventListener('resize', function () {
            if (window.innerWidth > 980) {
                cerrarMenu();
            }
        });

        if (mensajeFlash) {
            setTimeout(function () {
                mensajeFlash.style.opacity = '0';
                setTimeout(function () {
                    mensajeFlash.style.display = 'none';
                }, 400);
            }, 1800);
        }
    });
    </script>
</body>
</html>
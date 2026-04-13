<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function obtenerConfiguracion($conn) {
    $res = $conn->query("SELECT * FROM configuracion WHERE id = 1 LIMIT 1");
    return $res ? $res->fetch_assoc() : [];
}

function existeTabla($conn, $tabla) {
    $tabla = mysqli_real_escape_string($conn, $tabla);
    $res = $conn->query("SHOW TABLES LIKE '$tabla'");
    return ($res && $res->num_rows > 0);
}

function existeColumnaTabla($conn, $tabla, $columna) {
    $tabla = mysqli_real_escape_string($conn, $tabla);
    $columna = mysqli_real_escape_string($conn, $columna);
    $res = $conn->query("SHOW COLUMNS FROM `$tabla` LIKE '$columna'");
    return ($res && $res->num_rows > 0);
}

function asegurarTablaPapelera($conn) {
    $sql = "CREATE TABLE IF NOT EXISTS papelera (
        id INT AUTO_INCREMENT PRIMARY KEY,
        modulo VARCHAR(100) NOT NULL,
        registro_id INT DEFAULT NULL,
        datos_json LONGTEXT NOT NULL,
        eliminado_por INT DEFAULT NULL,
        fecha_eliminacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    $conn->query($sql);

    if (existeTabla($conn, 'papelera')) {
        if (!existeColumnaTabla($conn, 'papelera', 'registro_id')) {
            $conn->query("ALTER TABLE papelera ADD COLUMN registro_id INT DEFAULT NULL AFTER modulo");
        }

        if (!existeColumnaTabla($conn, 'papelera', 'eliminado_por')) {
            $conn->query("ALTER TABLE papelera ADD COLUMN eliminado_por INT DEFAULT NULL AFTER datos_json");
        }

        if (!existeColumnaTabla($conn, 'papelera', 'fecha_eliminacion')) {
            $conn->query("ALTER TABLE papelera ADD COLUMN fecha_eliminacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP");
        }
    }

    return true;
}

function enviarRegistroAPapelera($conn, $modulo, $registroId, $datos, $eliminadoPor = null) {
    asegurarTablaPapelera($conn);

    $modulo = mysqli_real_escape_string($conn, (string)$modulo);
    $registroId = (int)$registroId;

    if ($eliminadoPor === null || $eliminadoPor === '') {
        $eliminadoPorSql = "NULL";
    } else {
        $eliminadoPorSql = (int)$eliminadoPor;
    }

    $json = json_encode($datos, JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        return false;
    }

    $json = mysqli_real_escape_string($conn, $json);

    $sql = "INSERT INTO papelera (modulo, registro_id, datos_json, eliminado_por)
            VALUES ('$modulo', $registroId, '$json', $eliminadoPorSql)";

    return $conn->query($sql);
}

function obtenerRolActualSesion() {
    $campos = ['rol', 'usuario_rol', 'tipo_usuario', 'cargo', 'perfil', 'puesto', 'usuario'];

    foreach ($campos as $campo) {
        if (!empty($_SESSION[$campo])) {
            $valor = trim((string)$_SESSION[$campo]);
            return function_exists('mb_strtolower') ? mb_strtolower($valor) : strtolower($valor);
        }
    }

    return '';
}

function usuarioEsAdminSesion() {
    if (isset($_SESSION['is_admin']) && (int)$_SESSION['is_admin'] === 1) {
        return true;
    }

    $rol = obtenerRolActualSesion();
    $adminsValidos = ['admin', 'administrador', 'administrator', 'root', 'superadmin', 'administrador_general'];

    return in_array($rol, $adminsValidos, true);
}
?>
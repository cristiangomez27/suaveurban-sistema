<?php

/*
==============================
CONEXIÓN BASE DE DATOS
==============================
*/

$DB_HOST = getenv('DB_HOST') ?: "localhost";
$DB_USER = getenv('DB_USER') ?: "u412805401_suaveurbanst";
$DB_PASS = getenv('DB_PASS') ?: "Adamitas27@";
$DB_NAME = getenv('DB_NAME') ?: "u412805401_suaveurbanst";

/*
==============================
URL DEL SISTEMA
==============================
*/

$APP_URL = getenv('APP_URL') ?: "https://suaveurbanstudio.com.mx";

/*
==============================
CONEXIÓN MYSQL
==============================
*/

$conn = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);

if ($conn->connect_error) {
    die("Error de conexión a la base de datos: " . $conn->connect_error);
}

$conn->set_charset("utf8mb4");

/*
==============================
CONSTANTES DEL SISTEMA
==============================
*/

define("APP_URL", $APP_URL);

/*
==============================
CREDENCIALES GREEN API SEGURAS
==============================
*/

$rutasCredenciales = [
    __DIR__ . "/../private/secure_greenapi.php",
    __DIR__ . "/secure_greenapi.php"
];

foreach ($rutasCredenciales as $rutaCredencial) {
    if (file_exists($rutaCredencial)) {
        require_once $rutaCredencial;
        break;
    }
}

if (!defined('GREEN_API_INSTANCE_ID') && defined('GREENAPI_INSTANCE')) {
    define('GREEN_API_INSTANCE_ID', GREENAPI_INSTANCE);
}

if (!defined('GREEN_API_TOKEN') && defined('GREENAPI_TOKEN')) {
    define('GREEN_API_TOKEN', GREENAPI_TOKEN);
}

?>

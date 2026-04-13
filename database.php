<?php

/*
==============================
CONEXIÓN BASE DE DATOS
==============================
*/

$DB_HOST = "localhost";
$DB_USER = "u412805401_suaveurbanst";
$DB_PASS = "Adamitas27@";
$DB_NAME = "u412805401_suaveurbanst";

/*
==============================
URL DEL SISTEMA
==============================
*/

$APP_URL = "https://suaveurbanstudio.com.mx";

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

require_once __DIR__ . "/../private/secure_greenapi.php";

?>
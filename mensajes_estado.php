<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
mysqli_report(MYSQLI_REPORT_OFF);

session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['usuario_id'])) {
    echo json_encode(['ok' => false]);
    exit;
}

require_once 'config/database.php';

$usuarioId = (int)$_SESSION['usuario_id'];

if ($conn->query("UPDATE usuarios SET last_seen = NOW() WHERE id = {$usuarioId} LIMIT 1")) {
    echo json_encode(['ok' => true]);
} else {
    echo json_encode(['ok' => false]);
}
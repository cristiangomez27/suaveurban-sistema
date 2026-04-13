<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
mysqli_report(MYSQLI_REPORT_OFF);

session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['usuario_id'])) {
    echo json_encode(['ok' => false, 'mensaje' => 'Sesión no válida']);
    exit;
}

require_once 'config/database.php';

$usuarioId = (int)$_SESSION['usuario_id'];

$conn->query("UPDATE usuarios SET last_seen = NOW() WHERE id = {$usuarioId} LIMIT 1");

$tipo = trim($_POST['tipo'] ?? 'grupal');
$destinatarioId = (int)($_POST['destinatario_id'] ?? 0);
$mensaje = trim($_POST['mensaje'] ?? '');

if ($mensaje === '') {
    echo json_encode(['ok' => false, 'mensaje' => 'Escribe un mensaje']);
    exit;
}

if (!in_array($tipo, ['grupal', 'privado'], true)) {
    echo json_encode(['ok' => false, 'mensaje' => 'Tipo inválido']);
    exit;
}

if ($tipo === 'privado' && $destinatarioId <= 0) {
    echo json_encode(['ok' => false, 'mensaje' => 'Selecciona un usuario']);
    exit;
}

if ($tipo === 'privado' && $destinatarioId === $usuarioId) {
    echo json_encode(['ok' => false, 'mensaje' => 'No puedes enviarte mensajes privados a ti mismo']);
    exit;
}

if ($tipo === 'privado') {
    $stmtUser = $conn->prepare("SELECT id FROM usuarios WHERE id = ? AND estado = 'activo' LIMIT 1");
    $stmtUser->bind_param("i", $destinatarioId);
    $stmtUser->execute();
    $resUser = $stmtUser->get_result();
    $okUser = ($resUser && $resUser->num_rows > 0);
    $stmtUser->close();

    if (!$okUser) {
        echo json_encode(['ok' => false, 'mensaje' => 'Usuario destino no válido']);
        exit;
    }
} else {
    $destinatarioId = null;
}

$stmt = $conn->prepare("INSERT INTO mensajes_internos (remitente_id, destinatario_id, tipo, mensaje, creado_en) VALUES (?, ?, ?, ?, NOW())");

if ($tipo === 'privado') {
    $stmt->bind_param("iiss", $usuarioId, $destinatarioId, $tipo, $mensaje);
} else {
    $nullDest = null;
    $stmt->bind_param("iiss", $usuarioId, $nullDest, $tipo, $mensaje);
}

if ($stmt->execute()) {
    echo json_encode(['ok' => true, 'mensaje' => 'Mensaje enviado']);
} else {
    echo json_encode(['ok' => false, 'mensaje' => 'No se pudo enviar el mensaje: ' . $stmt->error]);
}
$stmt->close();
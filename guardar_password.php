<?php
session_start();
require_once 'config/database.php';

function passwordFuerte(string $password): bool
{
    if (strlen($password) < 8) return false;
    if (!preg_match('/[A-Z]/', $password)) return false;
    if (!preg_match('/[a-z]/', $password)) return false;
    if (!preg_match('/[0-9]/', $password)) return false;
    if (!preg_match('/[\W_]/', $password)) return false;
    return true;
}

$token = trim($_POST['token'] ?? '');
$password = $_POST['password'] ?? '';
$confirmar = $_POST['confirmar_password'] ?? '';

if ($token === '') {
    header("Location: reset_password.php?error=" . urlencode("Token inválido."));
    exit;
}

if ($password === '' || $confirmar === '') {
    header("Location: reset_password.php?token=" . urlencode($token) . "&error=" . urlencode("Completa todos los campos."));
    exit;
}

if ($password !== $confirmar) {
    header("Location: reset_password.php?token=" . urlencode($token) . "&error=" . urlencode("Las contraseñas no coinciden."));
    exit;
}

if (!passwordFuerte($password)) {
    header("Location: reset_password.php?token=" . urlencode($token) . "&error=" . urlencode("La contraseña no cumple con la seguridad mínima."));
    exit;
}

$stmt = $conn->prepare("SELECT * FROM password_resets WHERE token = ? AND usado = 0 AND expiracion >= NOW() LIMIT 1");
$stmt->bind_param("s", $token);
$stmt->execute();
$res = $stmt->get_result();
$resetRow = $res ? $res->fetch_assoc() : null;
$stmt->close();

if (!$resetRow) {
    header("Location: reset_password.php?error=" . urlencode("El token ya no es válido o expiró."));
    exit;
}

$email = trim((string)$resetRow['email']);
$nuevoHash = password_hash($password, PASSWORD_DEFAULT);

$stmtUser = $conn->prepare("UPDATE usuarios SET password = ? WHERE correo = ? LIMIT 1");
$stmtUser->bind_param("ss", $nuevoHash, $email);

if ($stmtUser->execute()) {
    $stmtUser->close();

    $stmtDone = $conn->prepare("UPDATE password_resets SET usado = 1 WHERE token = ? LIMIT 1");
    $stmtDone->bind_param("s", $token);
    $stmtDone->execute();
    $stmtDone->close();

    header("Location: index.php?reset=ok");
    exit;
} else {
    $stmtUser->close();
    header("Location: reset_password.php?token=" . urlencode($token) . "&error=" . urlencode("No se pudo actualizar la contraseña."));
    exit;
}
?>
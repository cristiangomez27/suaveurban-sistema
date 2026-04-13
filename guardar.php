<?php
header('Content-Type: application/json; charset=utf-8');

if (!isset($_POST['id']) || !is_numeric($_POST['id'])) {
    echo json_encode(['status' => 'error', 'mensaje' => 'ID inválido']);
    exit;
}

$id = intval($_POST['id']);
$imagen = $_POST['imagen'] ?? '';

if ($imagen === '') {
    echo json_encode(['status' => 'error', 'mensaje' => 'No llegó la imagen']);
    exit;
}

if (!preg_match('/^data:image\/png;base64,/', $imagen)) {
    echo json_encode(['status' => 'error', 'mensaje' => 'Formato de imagen inválido']);
    exit;
}

$imagen = substr($imagen, strpos($imagen, ',') + 1);
$binario = base64_decode($imagen);

if ($binario === false) {
    echo json_encode(['status' => 'error', 'mensaje' => 'No se pudo decodificar']);
    exit;
}

$dir = __DIR__ . '/uploads/remisiones';
if (!is_dir($dir)) {
    @mkdir($dir, 0777, true);
}

if (!is_dir($dir)) {
    echo json_encode(['status' => 'error', 'mensaje' => 'No se pudo crear carpeta']);
    exit;
}

$nombre = 'remision_' . $id . '.png';
$rutaFisica = $dir . '/' . $nombre;
$rutaPublica = 'uploads/remisiones/' . $nombre;

if (file_put_contents($rutaFisica, $binario) === false) {
    echo json_encode(['status' => 'error', 'mensaje' => 'No se pudo guardar']);
    exit;
}

echo json_encode([
    'status' => 'success',
    'mensaje' => 'Imagen guardada',
    'url' => $rutaPublica
], JSON_UNESCAPED_UNICODE);
?>
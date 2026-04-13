<?php
require_once 'config/database.php';

header('Content-Type: application/json; charset=utf-8');

if (!extension_loaded('gd')) {
    echo json_encode([
        'status' => 'error',
        'mensaje' => 'La extensión GD no está habilitada en el servidor'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    echo json_encode([
        'status' => 'error',
        'mensaje' => 'ID de venta inválido'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$id = intval($_GET['id']);

function existeTabla(mysqli $conn, string $tabla): bool
{
    $tabla = $conn->real_escape_string($tabla);
    $res = $conn->query("SHOW TABLES LIKE '{$tabla}'");
    return ($res && $res->num_rows > 0);
}

function safeText($text): string
{
    $text = (string)$text;
    $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
    return trim($text);
}

function wrapTextLines(string $text, int $maxChars = 42): array
{
    $text = preg_replace('/\s+/', ' ', trim($text));
    if ($text === '') return ['-'];

    $words = explode(' ', $text);
    $lines = [];
    $current = '';

    foreach ($words as $word) {
        $test = $current === '' ? $word : $current . ' ' . $word;
        if (mb_strlen($test, 'UTF-8') <= $maxChars) {
            $current = $test;
        } else {
            if ($current !== '') {
                $lines[] = $current;
            }
            $current = $word;
        }
    }

    if ($current !== '') {
        $lines[] = $current;
    }

    return !empty($lines) ? $lines : ['-'];
}

$stmtVenta = $conn->prepare("SELECT * FROM ventas WHERE id = ? LIMIT 1");
$stmtVenta->bind_param("i", $id);
$stmtVenta->execute();
$venta = $stmtVenta->get_result()->fetch_assoc();
$stmtVenta->close();

if (!$venta) {
    echo json_encode([
        'status' => 'error',
        'mensaje' => 'Venta no encontrada'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$detalles = [];
if (existeTabla($conn, 'ventas_detalle')) {
    $stmtDetalles = $conn->prepare("SELECT * FROM ventas_detalle WHERE venta_id = ? ORDER BY id ASC");
    $stmtDetalles->bind_param("i", $id);
    $stmtDetalles->execute();
    $resDetalles = $stmtDetalles->get_result();
    while ($row = $resDetalles->fetch_assoc()) {
        $detalles[] = $row;
    }
    $stmtDetalles->close();
}

$logoActual = __DIR__ . '/logo.png';
if (existeTabla($conn, 'configuracion')) {
    $resConfig = $conn->query("SELECT logo FROM configuracion WHERE id = 1 LIMIT 1");
    if ($resConfig && $resConfig->num_rows > 0) {
        $cfg = $resConfig->fetch_assoc();
        if (!empty($cfg['logo'])) {
            $tmpLogo = __DIR__ . '/' . ltrim($cfg['logo'], '/');
            if (file_exists($tmpLogo)) {
                $logoActual = $tmpLogo;
            }
        }
    }
}

$folio = safeText($venta['folio'] ?? ('REM-' . $id));
$fechaVenta = safeText($venta['fecha_venta'] ?? ($venta['fecha'] ?? date('Y-m-d')));
$fechaEntrega = safeText($venta['fecha_entrega'] ?? '');
$diaEntrega = safeText($venta['dia_entrega'] ?? '');
$clienteNombre = safeText($venta['cliente_nombre'] ?? 'Público en general');
$clienteTelefono = safeText($venta['cliente_telefono'] ?? '-');
$clienteDireccion = safeText($venta['cliente_direccion'] ?? '-');
$metodoPago = safeText($venta['metodo_pago'] ?? '-');
$mensajeRemision = safeText($venta['mensaje_remision'] ?? '');
$observaciones = safeText($venta['observaciones'] ?? '');
$total = (float)($venta['total'] ?? 0);
$subtotal = (float)($venta['subtotal'] ?? $total);

$qrUrl = "https://api.qrserver.com/v1/create-qr-code/?size=220x220&data=" . urlencode('https://suaveurbanstudio.com.mx/verificar_remision.php?id=' . $id);

$width = 1100;
$height = 1500;
$img = imagecreatetruecolor($width, $height);

$white = imagecolorallocate($img, 255, 255, 255);
$black = imagecolorallocate($img, 0, 0, 0);
$gray = imagecolorallocate($img, 80, 80, 80);
$light = imagecolorallocate($img, 245, 245, 245);
$border = imagecolorallocate($img, 220, 220, 220);

imagefill($img, 0, 0, $white);

/* Header */
imagefilledrectangle($img, 0, 0, $width, 180, $black);
imagestring($img, 5, 170, 35, 'Suave Urban Studio', $white);
imagestring($img, 3, 170, 65, 'Nota de remision', $white);

imagestring($img, 4, 760, 30, 'FOLIO:', $white);
imagestring($img, 5, 840, 30, $folio, $white);
imagestring($img, 3, 760, 65, 'Fecha venta: ' . $fechaVenta, $white);
if ($fechaEntrega !== '') {
    imagestring($img, 3, 760, 90, 'Fecha entrega: ' . $fechaEntrega, $white);
}
if ($diaEntrega !== '') {
    imagestring($img, 3, 760, 115, 'Dia: ' . $diaEntrega, $white);
}

/* Logo */
if (file_exists($logoActual)) {
    $ext = strtolower(pathinfo($logoActual, PATHINFO_EXTENSION));
    $logoImg = null;

    if ($ext === 'png') $logoImg = @imagecreatefrompng($logoActual);
    if (($ext === 'jpg' || $ext === 'jpeg')) $logoImg = @imagecreatefromjpeg($logoActual);
    if ($ext === 'gif') $logoImg = @imagecreatefromgif($logoActual);
    if ($ext === 'webp' && function_exists('imagecreatefromwebp')) $logoImg = @imagecreatefromwebp($logoActual);

    if ($logoImg) {
        imagecopyresampled(
            $img,
            $logoImg,
            30, 20,
            0, 0,
            110, 110,
            imagesx($logoImg),
            imagesy($logoImg)
        );
        imagedestroy($logoImg);
    }
}

/* Boxes */
imagefilledrectangle($img, 30, 220, 530, 430, $light);
imagerectangle($img, 30, 220, 530, 430, $border);

imagefilledrectangle($img, 570, 220, 1070, 430, $light);
imagerectangle($img, 570, 220, 1070, 430, $border);

imagestring($img, 4, 45, 235, 'Datos del cliente', $black);
imagestring($img, 4, 585, 235, 'Datos de la venta', $black);

$y1 = 270;
foreach ([
    'Nombre: ' . $clienteNombre,
    'Telefono: ' . $clienteTelefono,
    'Direccion: ' . $clienteDireccion,
] as $line) {
    $wrapped = wrapTextLines($line, 52);
    foreach ($wrapped as $w) {
        imagestring($img, 3, 45, $y1, $w, $gray);
        $y1 += 22;
    }
}

$y2 = 270;
foreach ([
    'Metodo de pago: ' . $metodoPago,
    'Subtotal: $' . number_format($subtotal, 2),
    'Total: $' . number_format($total, 2),
] as $line) {
    imagestring($img, 3, 585, $y2, $line, $gray);
    $y2 += 24;
}

if ($mensajeRemision !== '') {
    imagestring($img, 3, 585, $y2, 'Mensaje:', $black);
    $y2 += 20;
    foreach (wrapTextLines($mensajeRemision, 52) as $w) {
        imagestring($img, 2, 585, $y2, $w, $gray);
        $y2 += 18;
    }
}

if ($observaciones !== '') {
    $y2 += 8;
    imagestring($img, 3, 585, $y2, 'Observaciones:', $black);
    $y2 += 20;
    foreach (wrapTextLines($observaciones, 52) as $w) {
        imagestring($img, 2, 585, $y2, $w, $gray);
        $y2 += 18;
    }
}

/* Tabla */
imagefilledrectangle($img, 30, 470, 1070, 540, $black);
imagestring($img, 4, 50, 495, 'Producto', $white);
imagestring($img, 4, 350, 495, 'Detalles', $white);
imagestring($img, 4, 910, 495, 'Precio', $white);

$y = 555;
foreach ($detalles as $item) {
    $rowHeight = 90;

    imagefilledrectangle($img, 30, $y, 1070, $y + $rowHeight, $white);
    imageline($img, 30, $y + $rowHeight, 1070, $y + $rowHeight, $border);

    $producto = safeText($item['nombre_producto'] ?? 'Producto');
    imagestring($img, 4, 50, $y + 10, mb_substr($producto, 0, 28, 'UTF-8'), $black);

    $detY = $y + 10;
    $detalleLineas = [];

    if (!empty($item['talla'])) $detalleLineas[] = 'Talla: ' . safeText($item['talla']);
    if (!empty($item['color'])) $detalleLineas[] = 'Color: ' . safeText($item['color']);
    if (!empty($item['diseno'])) $detalleLineas[] = 'Diseno: ' . safeText($item['diseno']);
    if (!empty($item['descripcion_corta'])) $detalleLineas[] = 'Detalle: ' . safeText($item['descripcion_corta']);

    if (empty($detalleLineas)) {
        $detalleLineas[] = '-';
    }

    foreach ($detalleLineas as $dl) {
        imagestring($img, 2, 350, $detY, mb_substr($dl, 0, 70, 'UTF-8'), $gray);
        $detY += 16;
    }

    imagestring($img, 4, 910, $y + 25, '$' . number_format((float)($item['precio'] ?? 0), 2), $black);

    $y += $rowHeight;
    if ($y > 1180) break;
}

/* Total */
imagefilledrectangle($img, 720, 1210, 1070, 1310, $black);
imagestring($img, 4, 750, 1235, 'TOTAL', $white);
imagestring($img, 5, 860, 1265, '$' . number_format($total, 2), $white);

/* Firmas */
imageline($img, 90, 1400, 360, 1400, $black);
imagestring($img, 4, 150, 1410, 'QUIEN ENTREGA', $black);

imageline($img, 740, 1400, 1010, 1400, $black);
imagestring($img, 4, 805, 1410, 'QUIEN RECIBE', $black);

/* QR */
$qrData = @file_get_contents($qrUrl);
if ($qrData !== false) {
    $tmpQr = @imagecreatefromstring($qrData);
    if ($tmpQr) {
        imagecopyresampled(
            $img,
            $tmpQr,
            485, 1320,
            0, 0,
            120, 120,
            imagesx($tmpQr),
            imagesy($tmpQr)
        );
        imagedestroy($tmpQr);
    }
}
imagestring($img, 2, 485, 1445, 'Validacion de remision', $gray);

/* Guardar */
$dir = __DIR__ . '/uploads/remisiones';
if (!is_dir($dir)) {
    @mkdir($dir, 0777, true);
}

if (!is_dir($dir)) {
    imagedestroy($img);
    echo json_encode([
        'status' => 'error',
        'mensaje' => 'No se pudo crear la carpeta de remisiones'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$nombre = 'remision_' . $id . '.png';
$rutaFisica = $dir . '/' . $nombre;
$rutaPublica = 'uploads/remisiones/' . $nombre;

imagepng($img, $rutaFisica);
imagedestroy($img);

echo json_encode([
    'status' => 'success',
    'mensaje' => 'Imagen generada correctamente',
    'url' => $rutaPublica
], JSON_UNESCAPED_UNICODE);
?>
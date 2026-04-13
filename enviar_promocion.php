<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

require_once 'config/database.php';

if (file_exists(__DIR__ . '/helpers/greenapi_helper.php')) {
    require_once __DIR__ . '/helpers/greenapi_helper.php';
}

if (!isset($_SESSION['usuario_id'])) {
    echo json_encode([
        'ok' => false,
        'mensaje' => 'Sesión inválida'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/*
|--------------------------------------------------------------------------
| CARGAR CREDENCIALES GREEN API
|--------------------------------------------------------------------------
*/
$greenInstance = '';
$greenToken = '';

if (function_exists('cargarCredencialesGreenApi')) {
    $cred = cargarCredencialesGreenApi();
    if (!empty($cred['ok'])) {
        $greenInstance = trim((string)($cred['instance'] ?? ''));
        $greenToken = trim((string)($cred['token'] ?? ''));
    }
}

if ($greenInstance === '' && defined('GREEN_API_INSTANCE_ID')) {
    $greenInstance = trim((string)GREEN_API_INSTANCE_ID);
}

if ($greenToken === '' && defined('GREEN_API_TOKEN')) {
    $greenToken = trim((string)GREEN_API_TOKEN);
}

if ($greenInstance === '' || $greenToken === '') {
    echo json_encode([
        'ok' => false,
        'mensaje' => 'Green API no está configurada correctamente'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function responder($ok, $mensaje, $extra = [])
{
    echo json_encode(array_merge([
        'ok' => (bool)$ok,
        'mensaje' => $mensaje
    ], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

function limpiarTelefono(string $telefono): string
{
    return preg_replace('/\D+/', '', $telefono);
}

function normalizarTelefonoMX521(string $telefono): string
{
    $telefono = limpiarTelefono($telefono);

    if ($telefono === '') {
        return '';
    }

    // Si viene local de 10 dígitos
    if (strlen($telefono) === 10) {
        return '521' . $telefono;
    }

    // Si ya viene con 521
    if (strlen($telefono) === 13 && substr($telefono, 0, 3) === '521') {
        return $telefono;
    }

    // Si viene con 52, convertirlo a 521
    if (strlen($telefono) === 12 && substr($telefono, 0, 2) === '52') {
        return '521' . substr($telefono, 2);
    }

    // Si viene con 52152 duplicado raro, limpiar a 521 + últimos 10
    if (strlen($telefono) > 13 && str_starts_with($telefono, '521')) {
        return '521' . substr($telefono, -10);
    }

    return $telefono;
}

function enviarMensajeGreenApi(string $telefono, string $mensaje, string $greenInstance, string $greenToken): array
{
    $telefono = normalizarTelefonoMX521($telefono);

    if ($telefono === '') {
        return ['ok' => false, 'error' => 'Teléfono vacío'];
    }

    // Este endpoint es el que ya te funcionó
    $url = 'https://7107.api.greenapi.com/waInstance' . $greenInstance . '/sendMessage/' . $greenToken;

    $payload = [
        'chatId' => $telefono . '@c.us',
        'message' => $mensaje
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2
    ]);

    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($curlError) {
        return [
            'ok' => false,
            'error' => 'cURL: ' . $curlError
        ];
    }

    $decoded = json_decode((string)$response, true);

    if ($httpCode >= 200 && $httpCode < 300 && is_array($decoded) && !empty($decoded['idMessage'])) {
        return [
            'ok' => true,
            'data' => $decoded,
            'chatId' => $telefono . '@c.us'
        ];
    }

    $errorTexto = 'Respuesta inválida de Green API';

    if (is_array($decoded)) {
        if (!empty($decoded['message'])) {
            $errorTexto = (string)$decoded['message'];
        } elseif (!empty($decoded['error'])) {
            $errorTexto = (string)$decoded['error'];
        }
    } elseif (!empty($response)) {
        $errorTexto = mb_substr((string)$response, 0, 220);
    }

    return [
        'ok' => false,
        'error' => $errorTexto,
        'http_code' => $httpCode,
        'raw' => $response,
        'chatId' => $telefono . '@c.us'
    ];
}

try {
    $data = json_decode(file_get_contents('php://input'), true);

    if (!is_array($data)) {
        responder(false, 'No se recibieron datos válidos');
    }

    $mensaje = trim((string)($data['mensaje'] ?? ''));
    $clientes = $data['clientes'] ?? [];

    if ($mensaje === '') {
        responder(false, 'Escribe el mensaje de promoción');
    }

    if (!is_array($clientes) || count($clientes) === 0) {
        responder(false, 'No hay clientes seleccionados');
    }

    $enviados = 0;
    $fallidos = 0;
    $detalleOk = [];
    $detalleFallidos = [];

    foreach ($clientes as $cliente) {
        $nombre = trim((string)($cliente['nombre'] ?? 'Cliente'));
        $telefonoOriginal = trim((string)($cliente['telefono'] ?? ''));

        if ($telefonoOriginal === '') {
            $fallidos++;
            $detalleFallidos[] = $nombre . ': sin teléfono';
            continue;
        }

        $mensajeFinal = "Hola {$nombre} 👋\n\n{$mensaje}\n\nSuave Urban Studio";
        $resultado = enviarMensajeGreenApi($telefonoOriginal, $mensajeFinal, $greenInstance, $greenToken);

        if (!empty($resultado['ok'])) {
            $enviados++;
            $detalleOk[] = $nombre . ': ' . ($resultado['chatId'] ?? 'ok');
        } else {
            $fallidos++;
            $detalleFallidos[] = $nombre . ': ' . ($resultado['error'] ?? 'No se pudo enviar') . ' | ' . ($resultado['chatId'] ?? '');
        }

        usleep(350000);
    }

    if ($enviados > 0 && $fallidos === 0) {
        responder(true, 'Promoción enviada', [
            'enviados' => $enviados,
            'fallidos' => $fallidos,
            'detalle_ok' => $detalleOk,
            'detalle_fallidos' => $detalleFallidos
        ]);
    }

    responder(false, "No se pudo completar el envío. Correctos: {$enviados}. Fallidos: {$fallidos}.", [
        'enviados' => $enviados,
        'fallidos' => $fallidos,
        'detalle_ok' => $detalleOk,
        'detalle_fallidos' => $detalleFallidos
    ]);

} catch (Throwable $e) {
    responder(false, 'Error al enviar promociones: ' . $e->getMessage());
}
?>
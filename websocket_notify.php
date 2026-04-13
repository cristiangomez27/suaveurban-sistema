<?php
function websocket_notify($tipo = 'refresh', $modulo = 'general', $mensaje = '', $extra = []) {
    $host = '127.0.0.1';
    $port = 8080;

    $payload = json_encode([
        'tipo' => $tipo,
        'modulo' => $modulo,
        'mensaje' => $mensaje,
        'extra' => $extra,
        'fecha' => date('Y-m-d H:i:s')
    ], JSON_UNESCAPED_UNICODE);

    $key = base64_encode(random_bytes(16));

    $headers =
        "GET / HTTP/1.1\r\n" .
        "Host: {$host}:{$port}\r\n" .
        "Upgrade: websocket\r\n" .
        "Connection: Upgrade\r\n" .
        "Sec-WebSocket-Key: {$key}\r\n" .
        "Sec-WebSocket-Version: 13\r\n\r\n";

    $socket = @fsockopen($host, $port, $errno, $errstr, 2);

    if (!$socket) {
        return false;
    }

    fwrite($socket, $headers);
    fread($socket, 1500);

    $frame = websocket_encode($payload);
    fwrite($socket, $frame);

    fclose($socket);
    return true;
}

function websocket_encode($payload) {
    $length = strlen($payload);
    $frameHead = [];
    $frameHead[0] = 129;

    if ($length <= 125) {
        $frameHead[1] = $length | 128;
    } elseif ($length <= 65535) {
        $frameHead[1] = 126 | 128;
        $frameHead[2] = ($length >> 8) & 255;
        $frameHead[3] = $length & 255;
    } else {
        $frameHead[1] = 127 | 128;
        for ($i = 7; $i >= 0; $i--) {
            $frameHead[$i + 2] = ($length >> (8 * (7 - $i))) & 255;
        }
    }

    $mask = [];
    for ($i = 0; $i < 4; $i++) {
        $mask[$i] = rand(0, 255);
    }

    $frame = '';
    foreach ($frameHead as $b) {
        $frame .= chr($b);
    }
    foreach ($mask as $b) {
        $frame .= chr($b);
    }

    for ($i = 0; $i < $length; $i++) {
        $frame .= chr(ord($payload[$i]) ^ $mask[$i % 4]);
    }

    return $frame;
}
?>
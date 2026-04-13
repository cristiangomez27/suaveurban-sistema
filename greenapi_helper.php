<?php

function cargarCredencialesGreenApi(): array
{
    $rutasPosibles = [
        __DIR__ . '/../private/secure_greenapi.php',
        dirname(__DIR__) . '/private/secure_greenapi.php',
        $_SERVER['DOCUMENT_ROOT'] . '/private/secure_greenapi.php'
    ];

    $archivoEncontrado = null;

    foreach ($rutasPosibles as $ruta) {
        if (!empty($ruta) && file_exists($ruta)) {
            $archivoEncontrado = $ruta;
            break;
        }
    }

    if ($archivoEncontrado === null) {
        return [
            'ok' => false,
            'instance' => '',
            'token' => '',
            'mensaje' => 'No se encontró el archivo seguro de Green API.'
        ];
    }

    require_once $archivoEncontrado;

    $instance = defined('GREENAPI_INSTANCE') ? trim((string)GREENAPI_INSTANCE) : '';
    $token = defined('GREENAPI_TOKEN') ? trim((string)GREENAPI_TOKEN) : '';

    if ($instance === '' || $token === '') {
        return [
            'ok' => false,
            'instance' => '',
            'token' => '',
            'mensaje' => 'Las credenciales de Green API están vacías.'
        ];
    }

    return [
        'ok' => true,
        'instance' => $instance,
        'token' => $token,
        'mensaje' => 'Credenciales cargadas correctamente.'
    ];
}

function greenApiMascara(string $valor, int $inicio = 4, int $fin = 3): string
{
    $valor = trim($valor);
    $len = strlen($valor);

    if ($len <= ($inicio + $fin)) {
        return str_repeat('*', $len);
    }

    return substr($valor, 0, $inicio)
        . str_repeat('*', $len - ($inicio + $fin))
        . substr($valor, -$fin);
}
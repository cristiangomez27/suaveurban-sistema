<?php

function enviarCorreoSMTP($to, $subject, $htmlBody)
{
    if (!defined('MAIL_HOST')) {
        require_once __DIR__ . '/../config/mail.php';
    }

    $host = MAIL_HOST;
    $port = (int)MAIL_PORT;
    $username = MAIL_USERNAME;
    $password = MAIL_PASSWORD;
    $from = MAIL_FROM_ADDRESS;
    $fromName = MAIL_FROM_NAME;
    $encryption = strtolower(MAIL_ENCRYPTION);

    $remote = ($encryption === 'ssl' ? 'ssl://' : '') . $host;

    $fp = @stream_socket_client(
        $remote . ':' . $port,
        $errno,
        $errstr,
        20,
        STREAM_CLIENT_CONNECT
    );

    if (!$fp) {
        return ['ok' => false, 'mensaje' => "No se pudo conectar al servidor SMTP: $errstr ($errno)"];
    }

    stream_set_timeout($fp, 20);

    $leer = function () use ($fp) {
        $data = '';
        while ($line = fgets($fp, 515)) {
            $data .= $line;
            if (preg_match('/^\d{3}\s/', $line)) {
                break;
            }
        }
        return $data;
    };

    $escribir = function ($cmd) use ($fp) {
        fwrite($fp, $cmd . "\r\n");
    };

    $esperar = function ($codes, $respuesta) {
        foreach ((array)$codes as $code) {
            if (strpos($respuesta, (string)$code) === 0) {
                return true;
            }
        }
        return false;
    };

    $respuesta = $leer();
    if (!$esperar(220, $respuesta)) {
        fclose($fp);
        return ['ok' => false, 'mensaje' => 'SMTP no respondió correctamente: ' . $respuesta];
    }

    $escribir('EHLO suaveurbanstudio.com.mx');
    $respuesta = $leer();
    if (!$esperar(250, $respuesta)) {
        fclose($fp);
        return ['ok' => false, 'mensaje' => 'Error EHLO: ' . $respuesta];
    }

    if ($encryption === 'tls') {
        $escribir('STARTTLS');
        $respuesta = $leer();
        if (!$esperar(220, $respuesta)) {
            fclose($fp);
            return ['ok' => false, 'mensaje' => 'Error STARTTLS: ' . $respuesta];
        }

        if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            fclose($fp);
            return ['ok' => false, 'mensaje' => 'No se pudo activar TLS'];
        }

        $escribir('EHLO suaveurbanstudio.com.mx');
        $respuesta = $leer();
        if (!$esperar(250, $respuesta)) {
            fclose($fp);
            return ['ok' => false, 'mensaje' => 'Error EHLO después de TLS: ' . $respuesta];
        }
    }

    $escribir('AUTH LOGIN');
    $respuesta = $leer();
    if (!$esperar(334, $respuesta)) {
        fclose($fp);
        return ['ok' => false, 'mensaje' => 'Error AUTH LOGIN: ' . $respuesta];
    }

    $escribir(base64_encode($username));
    $respuesta = $leer();
    if (!$esperar(334, $respuesta)) {
        fclose($fp);
        return ['ok' => false, 'mensaje' => 'Error usuario SMTP: ' . $respuesta];
    }

    $escribir(base64_encode($password));
    $respuesta = $leer();
    if (!$esperar(235, $respuesta)) {
        fclose($fp);
        return ['ok' => false, 'mensaje' => 'Error contraseña SMTP: ' . $respuesta];
    }

    $escribir("MAIL FROM:<$from>");
    $respuesta = $leer();
    if (!$esperar(250, $respuesta)) {
        fclose($fp);
        return ['ok' => false, 'mensaje' => 'Error MAIL FROM: ' . $respuesta];
    }

    $escribir("RCPT TO:<$to>");
    $respuesta = $leer();
    if (!$esperar([250, 251], $respuesta)) {
        fclose($fp);
        return ['ok' => false, 'mensaje' => 'Error RCPT TO: ' . $respuesta];
    }

    $escribir('DATA');
    $respuesta = $leer();
    if (!$esperar(354, $respuesta)) {
        fclose($fp);
        return ['ok' => false, 'mensaje' => 'Error DATA: ' . $respuesta];
    }

    $subjectEncoded = '=?UTF-8?B?' . base64_encode($subject) . '?=';

    $headers = [];
    $headers[] = "From: {$fromName} <{$from}>";
    $headers[] = "To: <{$to}>";
    $headers[] = "Subject: {$subjectEncoded}";
    $headers[] = "MIME-Version: 1.0";
    $headers[] = "Content-Type: text/html; charset=UTF-8";
    $headers[] = "Content-Transfer-Encoding: 8bit";

    $message = implode("\r\n", $headers) . "\r\n\r\n" . $htmlBody . "\r\n.";

    fwrite($fp, $message . "\r\n");
    $respuesta = $leer();

    if (!$esperar(250, $respuesta)) {
        fclose($fp);
        return ['ok' => false, 'mensaje' => 'Error enviando correo: ' . $respuesta];
    }

    $escribir('QUIT');
    fclose($fp);

    return ['ok' => true, 'mensaje' => 'Correo enviado correctamente'];
}
<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/config/database.php';

ini_set('display_errors', '0');
error_reporting(E_ALL);

function responder(array $data): void
{
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function log_debug(string $texto): void
{
    $dir = __DIR__ . '/logs';
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    @file_put_contents($dir . '/guardar_venta.log', "[" . date('Y-m-d H:i:s') . "] " . $texto . PHP_EOL, FILE_APPEND);
}

function tableExists(mysqli $conn, string $table): bool
{
    $table = $conn->real_escape_string($table);
    $res = $conn->query("SHOW TABLES LIKE '{$table}'");
    return ($res && $res->num_rows > 0);
}

function getColumns(mysqli $conn, string $table): array
{
    $cols = [];
    if (!tableExists($conn, $table)) {
        return $cols;
    }

    $res = $conn->query("SHOW COLUMNS FROM `{$table}`");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $cols[] = $row['Field'];
        }
    }
    return $cols;
}

function hasColumn(array $cols, string $name): bool
{
    return in_array($name, $cols, true);
}

function obtenerClienteDesdeTabla(mysqli $conn, ?int $clienteId): array
{
    if (!$clienteId || $clienteId <= 0 || !tableExists($conn, 'clientes')) {
        return [];
    }

    $colsClientes = getColumns($conn, 'clientes');
    if (empty($colsClientes)) {
        return [];
    }

    $select = [];
    foreach (['id', 'nombre', 'telefono', 'direccion', 'email', 'tipo_cliente'] as $col) {
        if (hasColumn($colsClientes, $col)) {
            $select[] = $col;
        }
    }

    if (empty($select)) {
        return [];
    }

    $stmt = $conn->prepare("SELECT " . implode(', ', $select) . " FROM clientes WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $clienteId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return is_array($row) ? $row : [];
}


function normalizePhone(string $phone): string
{
    $phone = preg_replace('/\D+/', '', $phone);

    if ($phone === '') {
        return '';
    }

    if (strpos($phone, '521') === 0) {
        return $phone;
    }

    if (strpos($phone, '52') === 0) {
        return '521' . substr($phone, 2);
    }

    if (strlen($phone) === 10) {
        return '521' . $phone;
    }

    return $phone;
}

function buildAbsoluteUrl(string $relativeOrAbsolute): string
{
    $relativeOrAbsolute = trim($relativeOrAbsolute);
    if ($relativeOrAbsolute === '') {
        return '';
    }

    if (preg_match('#^https?://#i', $relativeOrAbsolute)) {
        return $relativeOrAbsolute;
    }

    $base = defined('APP_URL') ? rtrim(APP_URL, '/') : '';
    if ($base === '') {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? '';
        if ($host !== '') {
            $base = $scheme . '://' . $host;
        }
    }

    if ($base === '') {
        return '';
    }

    return $base . '/' . ltrim($relativeOrAbsolute, '/');
}

function publicFileExists(string $absoluteUrl): bool
{
    if ($absoluteUrl === '' || !function_exists('curl_init')) {
        return false;
    }

    $ch = curl_init($absoluteUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_NOBODY => true,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2
    ]);

    curl_exec($ch);
    $error = curl_error($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($error) {
        return false;
    }

    return ($http >= 200 && $http < 400);
}

function callInternalUrl(string $absoluteUrl): array
{
    if ($absoluteUrl === '' || !function_exists('curl_init')) {
        return ['ok' => false, 'mensaje' => 'No se pudo invocar URL interna'];
    }

    $ch = curl_init($absoluteUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 40,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2
    ]);

    $response = curl_exec($ch);
    $error = curl_error($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($error) {
        return ['ok' => false, 'mensaje' => $error, 'response' => ''];
    }

    if ($http < 200 || $http >= 300) {
        return ['ok' => false, 'mensaje' => 'HTTP ' . $http, 'response' => (string)$response];
    }

    return ['ok' => true, 'mensaje' => 'OK', 'response' => (string)$response];
}

function greenApiSendMessageSafe(string $phone, string $message): array
{
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'mensaje' => 'cURL no está disponible en el servidor'];
    }

    $instanceId = defined('GREEN_API_INSTANCE_ID') ? GREEN_API_INSTANCE_ID : '';
    $token = defined('GREEN_API_TOKEN') ? GREEN_API_TOKEN : '';

    if ($instanceId === '' || $token === '') {
        return ['ok' => false, 'mensaje' => 'Green API no configurado'];
    }

    $phone = normalizePhone($phone);
    if ($phone === '') {
        return ['ok' => false, 'mensaje' => 'Teléfono vacío'];
    }

    $url = "https://7107.api.greenapi.com/waInstance{$instanceId}/sendMessage/{$token}";
    $payload = json_encode([
        'chatId' => $phone . '@c.us',
        'message' => $message
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2
    ]);

    $response = curl_exec($ch);
    $error = curl_error($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($error) {
        return ['ok' => false, 'mensaje' => $error];
    }

    if ($http < 200 || $http >= 300) {
        return ['ok' => false, 'mensaje' => 'HTTP ' . $http . ' - ' . $response];
    }

    return ['ok' => true, 'mensaje' => $response];
}

function greenApiSendFileByUrlSafe(string $phone, string $urlFile, string $fileName, string $caption = ''): array
{
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'mensaje' => 'cURL no está disponible en el servidor'];
    }

    $instanceId = defined('GREEN_API_INSTANCE_ID') ? GREEN_API_INSTANCE_ID : '';
    $token = defined('GREEN_API_TOKEN') ? GREEN_API_TOKEN : '';

    if ($instanceId === '' || $token === '') {
        return ['ok' => false, 'mensaje' => 'Green API no configurado'];
    }

    $phone = normalizePhone($phone);
    if ($phone === '') {
        return ['ok' => false, 'mensaje' => 'Teléfono vacío'];
    }

    if ($urlFile === '') {
        return ['ok' => false, 'mensaje' => 'No hay URL pública'];
    }

    $url = "https://7107.api.greenapi.com/waInstance{$instanceId}/sendFileByUrl/{$token}";
    $payload = json_encode([
        'chatId' => $phone . '@c.us',
        'urlFile' => $urlFile,
        'fileName' => $fileName,
        'caption' => $caption
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2
    ]);

    $response = curl_exec($ch);
    $error = curl_error($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($error) {
        return ['ok' => false, 'mensaje' => $error];
    }

    if ($http < 200 || $http >= 300) {
        return ['ok' => false, 'mensaje' => 'HTTP ' . $http . ' - ' . $response];
    }

    return ['ok' => true, 'mensaje' => $response];
}

function saveBase64Image(string $base64, string $originalName = ''): array
{
    if ($base64 === '') {
        return ['ok' => false, 'path' => '', 'error' => 'Imagen vacía'];
    }

    if (!preg_match('/^data:image\/(\w+);base64,/', $base64, $matches)) {
        return ['ok' => false, 'path' => '', 'error' => 'Formato inválido'];
    }

    $ext = strtolower($matches[1]);
    $permitidas = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    if (!in_array($ext, $permitidas, true)) {
        return ['ok' => false, 'path' => '', 'error' => 'Extensión no permitida'];
    }

    $base64Body = substr($base64, strpos($base64, ',') + 1);
    $binary = base64_decode($base64Body);

    if ($binary === false) {
        return ['ok' => false, 'path' => '', 'error' => 'No se pudo decodificar'];
    }

    $dir = __DIR__ . '/uploads/disenos';
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }

    if (!is_dir($dir)) {
        return ['ok' => false, 'path' => '', 'error' => 'No se pudo crear carpeta'];
    }

    $base = 'diseno_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3));
    if ($originalName !== '') {
        $tmp = pathinfo($originalName, PATHINFO_FILENAME);
        $tmp = preg_replace('/[^a-zA-Z0-9_-]+/', '_', $tmp);
        if ($tmp !== '') {
            $base = substr($tmp, 0, 50) . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(2));
        }
    }

    $filename = $base . '.' . $ext;
    $full = $dir . '/' . $filename;
    $relative = 'uploads/disenos/' . $filename;

    if (file_put_contents($full, $binary) === false) {
        return ['ok' => false, 'path' => '', 'error' => 'No se pudo guardar'];
    }

    return ['ok' => true, 'path' => $relative, 'error' => ''];
}

if (!isset($_SESSION['usuario_id'])) {
    responder([
        'status' => 'error',
        'mensaje' => 'Sesión no válida'
    ]);
}

$raw = file_get_contents("php://input");
$data = json_decode($raw, true);

if (!$data || !isset($data['carrito']) || !is_array($data['carrito']) || count($data['carrito']) === 0) {
    responder([
        'status' => 'error',
        'mensaje' => 'No hay productos en la venta'
    ]);
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $carrito = $data['carrito'];
    $total = isset($data['total']) ? floatval($data['total']) : 0;
    $metodo = isset($data['metodo']) ? trim((string)$data['metodo']) : 'EFECTIVO';
    $imprimir = !empty($data['imprimir']) ? 1 : 0;
    $soloTicket = !empty($data['solo_ticket']) ? 1 : 0;
    $enviarRemisionWhatsapp = !empty($data['enviar_remision_whatsapp']) ? 1 : 0;

    $clienteId = isset($data['cliente_id']) && $data['cliente_id'] !== '' ? intval($data['cliente_id']) : null;
    $clienteNombre = isset($data['cliente_nombre']) ? trim((string)$data['cliente_nombre']) : 'Público en general';
    $clienteTelefono = isset($data['cliente_telefono']) ? trim((string)$data['cliente_telefono']) : '';
    $clienteDireccion = isset($data['cliente_direccion']) ? trim((string)$data['cliente_direccion']) : '';
    $clienteEmail = isset($data['cliente_email']) ? trim((string)$data['cliente_email']) : '';
    $tipoCliente = isset($data['tipo_cliente']) ? trim((string)$data['tipo_cliente']) : 'Personalizado';

    $fechaVenta = isset($data['fecha_venta']) ? trim((string)$data['fecha_venta']) : date('Y-m-d');
    $fechaEntrega = isset($data['fecha_entrega']) ? trim((string)$data['fecha_entrega']) : '';
    $diaEntrega = isset($data['dia_entrega']) ? trim((string)$data['dia_entrega']) : '';
    $mensajeRemision = isset($data['mensaje_remision']) ? trim((string)$data['mensaje_remision']) : '';
    $observaciones = isset($data['observaciones']) ? trim((string)$data['observaciones']) : '';

    $imagenDisenoBase64 = isset($data['imagen_diseno_base64']) ? trim((string)$data['imagen_diseno_base64']) : '';
    $imagenDisenoNombre = isset($data['imagen_diseno_nombre']) ? trim((string)$data['imagen_diseno_nombre']) : '';

    $clienteTabla = obtenerClienteDesdeTabla($conn, $clienteId);

    if (!empty($clienteTabla)) {
        $clienteNombre = trim((string)($clienteTabla['nombre'] ?? $clienteNombre));
        if ($clienteTelefono === '' && !empty($clienteTabla['telefono'])) {
            $clienteTelefono = trim((string)$clienteTabla['telefono']);
        }
        if ($clienteDireccion === '' && !empty($clienteTabla['direccion'])) {
            $clienteDireccion = trim((string)$clienteTabla['direccion']);
        }
        if ($clienteEmail === '' && !empty($clienteTabla['email'])) {
            $clienteEmail = trim((string)$clienteTabla['email']);
        }
        if (!empty($clienteTabla['tipo_cliente'])) {
            $tipoCliente = trim((string)$clienteTabla['tipo_cliente']);
        }
    }

    if ($clienteNombre === '') {
        $clienteNombre = 'Público en general';
    }

    if (!in_array($tipoCliente, ['Personalizado', 'DTF'], true)) {
        $tipoCliente = 'Personalizado';
    }

    if ($fechaVenta === '') {
        $fechaVenta = date('Y-m-d');
    }

    if ($fechaEntrega === '') {
        responder([
            'status' => 'error',
            'mensaje' => 'Falta la fecha de entrega'
        ]);
    }

    if ($diaEntrega === '') {
        $dias = ['Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'];
        $ts = strtotime($fechaEntrega);
        if ($ts !== false) {
            $diaEntrega = $dias[(int)date('w', $ts)] ?? '';
        }
    }

    $warnings = [];
    $telefonoNormalizado = normalizePhone($clienteTelefono);

    if (!tableExists($conn, 'ventas_detalle')) {
        $conn->query("
            CREATE TABLE IF NOT EXISTS ventas_detalle (
                id INT AUTO_INCREMENT PRIMARY KEY,
                venta_id INT NOT NULL,
                producto_id INT NULL,
                nombre_producto VARCHAR(255) NOT NULL,
                precio DECIMAL(10,2) NOT NULL DEFAULT 0,
                cantidad INT NOT NULL DEFAULT 1,
                categoria VARCHAR(100) DEFAULT NULL,
                tipo_producto VARCHAR(100) DEFAULT NULL,
                talla VARCHAR(100) DEFAULT NULL,
                color VARCHAR(100) DEFAULT NULL,
                diseno TEXT DEFAULT NULL,
                descripcion_corta VARCHAR(255) DEFAULT NULL,
                descripcion TEXT DEFAULT NULL,
                imagen_diseno VARCHAR(255) DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_venta_id (venta_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }

    if (!tableExists($conn, 'pedidos')) {
        $conn->query("
            CREATE TABLE IF NOT EXISTS pedidos (
                id INT AUTO_INCREMENT PRIMARY KEY,
                venta_id INT NULL,
                folio VARCHAR(100) DEFAULT NULL,
                cliente_id INT NULL,
                cliente_nombre VARCHAR(150) DEFAULT NULL,
                cliente_telefono VARCHAR(30) DEFAULT NULL,
                tipo_cliente VARCHAR(30) NOT NULL DEFAULT 'Personalizado',
                producto VARCHAR(255) DEFAULT NULL,
                color VARCHAR(100) DEFAULT NULL,
                talla VARCHAR(255) DEFAULT NULL,
                diseno TEXT DEFAULT NULL,
                observaciones TEXT DEFAULT NULL,
                recomendaciones TEXT DEFAULT NULL,
                imagen_diseno VARCHAR(255) DEFAULT NULL,
                fecha_entrega DATE DEFAULT NULL,
                dia_entrega VARCHAR(30) DEFAULT NULL,
                estado VARCHAR(50) NOT NULL DEFAULT 'NUEVO',
                estatus VARCHAR(50) NOT NULL DEFAULT 'NUEVO',
                prioridad TINYINT(1) NOT NULL DEFAULT 0,
                fecha_registro DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_venta_id (venta_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }

    $imagenDisenoRuta = '';
    if ($imagenDisenoBase64 !== '') {
        $img = saveBase64Image($imagenDisenoBase64, $imagenDisenoNombre);
        if ($img['ok']) {
            $imagenDisenoRuta = $img['path'];
        } else {
            $warnings[] = 'No se pudo guardar imagen: ' . $img['error'];
        }
    }

    $conn->begin_transaction();

    $folio = 'REM-' . date('YmdHis');
    $estadoInicial = 'NUEVO';

    $colsVentas = getColumns($conn, 'ventas');
    if (empty($colsVentas)) {
        throw new Exception('La tabla ventas no existe o no se puede leer');
    }

    $ventaData = [
        'folio' => $folio,
        'cliente_id' => $clienteId,
        'cliente_nombre' => $clienteNombre,
        'cliente_telefono' => $clienteTelefono,
        'cliente_direccion' => $clienteDireccion,
        'cliente_email' => $clienteEmail,
        'tipo_cliente' => $tipoCliente,
        'fecha_venta' => $fechaVenta,
        'fecha_entrega' => $fechaEntrega,
        'dia_entrega' => $diaEntrega,
        'mensaje_remision' => $mensajeRemision,
        'observaciones' => $observaciones,
        'imagen_diseno' => $imagenDisenoRuta,
        'metodo_pago' => $metodo,
        'subtotal' => $total,
        'total' => $total,
        'estado' => $estadoInicial,
        'estatus' => $estadoInicial
    ];

    $insertCols = [];
    $insertVals = [];
    $insertTypes = '';
    $insertParams = [];

    foreach ($ventaData as $col => $val) {
        if (hasColumn($colsVentas, $col)) {
            $insertCols[] = $col;
            $insertVals[] = '?';
            if (is_int($val)) {
                $insertTypes .= 'i';
            } elseif (is_float($val)) {
                $insertTypes .= 'd';
            } else {
                $insertTypes .= 's';
            }
            $insertParams[] = $val;
        }
    }

    if (hasColumn($colsVentas, 'fecha')) {
        $insertCols[] = 'fecha';
        $insertVals[] = 'NOW()';
    }

    $sqlVenta = "INSERT INTO ventas (" . implode(', ', $insertCols) . ") VALUES (" . implode(', ', $insertVals) . ")";
    $stmtVenta = $conn->prepare($sqlVenta);
    if (!empty($insertParams)) {
        $stmtVenta->bind_param($insertTypes, ...$insertParams);
    }
    $stmtVenta->execute();
    $ventaId = intval($conn->insert_id);
    $stmtVenta->close();

    $stmtStock = null;
    if (tableExists($conn, 'productos')) {
        $colsProductos = getColumns($conn, 'productos');
        if (hasColumn($colsProductos, 'stock')) {
            $stmtStock = $conn->prepare("UPDATE productos SET stock = stock - 1 WHERE id = ? AND stock > 0");
        }
    }

    $primerProducto = null;
    $resumenTallas = [];
    $resumenColores = [];
    $resumenDisenos = [];
    $resumenUbicaciones = [];

    $colsDetalle = getColumns($conn, 'ventas_detalle');

    foreach ($carrito as $item) {
        $productoId = (isset($item['id']) && is_numeric($item['id']) && intval($item['id']) > 0) ? intval($item['id']) : null;
        $nombreProducto = isset($item['nombre']) ? trim((string)$item['nombre']) : 'Producto';
        $precio = isset($item['precio']) ? floatval($item['precio']) : 0;
        $cantidad = isset($item['cantidad']) ? intval($item['cantidad']) : 1;
        if ($cantidad <= 0) {
            $cantidad = 1;
        }

        $meta = isset($item['personalizacion']) && is_array($item['personalizacion']) ? $item['personalizacion'] : [];

        $categoria = isset($meta['categoria']) ? trim((string)$meta['categoria']) : '';
        $tipoProducto = isset($meta['tipo_producto']) ? trim((string)$meta['tipo_producto']) : '';
        $talla = isset($meta['talla']) ? trim((string)$meta['talla']) : '';
        $color = isset($meta['color']) ? trim((string)$meta['color']) : '';
        $diseno = isset($meta['diseno']) ? trim((string)$meta['diseno']) : '';
        $descripcionCorta = isset($meta['descripcion_corta']) ? trim((string)$meta['descripcion_corta']) : '';
        $descripcion = isset($meta['descripcion']) ? trim((string)$meta['descripcion']) : '';

        if ($primerProducto === null) {
            $primerProducto = $tipoProducto !== '' ? $tipoProducto : $nombreProducto;
        }

        if ($talla !== '') $resumenTallas[] = $talla;
        if ($color !== '') $resumenColores[] = $color;
        if ($diseno !== '') $resumenDisenos[] = $diseno;
        if ($descripcionCorta !== '') $resumenUbicaciones[] = $descripcionCorta;

        $detalleData = [
            'venta_id' => $ventaId,
            'producto_id' => $productoId,
            'nombre_producto' => $nombreProducto,
            'precio' => $precio,
            'cantidad' => $cantidad,
            'categoria' => $categoria,
            'tipo_producto' => $tipoProducto,
            'talla' => $talla,
            'color' => $color,
            'diseno' => $diseno,
            'descripcion_corta' => $descripcionCorta,
            'descripcion' => $descripcion,
            'imagen_diseno' => $imagenDisenoRuta
        ];

        $dCols = [];
        $dVals = [];
        $dTypes = '';
        $dParams = [];

        foreach ($detalleData as $col => $val) {
            if (hasColumn($colsDetalle, $col)) {
                $dCols[] = $col;
                $dVals[] = '?';

                if (is_int($val) || $val === null) {
                    $dTypes .= 'i';
                    $dParams[] = $val ?? 0;
                } elseif (is_float($val)) {
                    $dTypes .= 'd';
                    $dParams[] = $val;
                } else {
                    $dTypes .= 's';
                    $dParams[] = $val;
                }
            }
        }

        $sqlDetalle = "INSERT INTO ventas_detalle (" . implode(', ', $dCols) . ") VALUES (" . implode(', ', $dVals) . ")";
        $stmtDetalle = $conn->prepare($sqlDetalle);
        if (!empty($dParams)) {
            $stmtDetalle->bind_param($dTypes, ...$dParams);
        }
        $stmtDetalle->execute();
        $stmtDetalle->close();

        if ($stmtStock && $productoId && $productoId > 0) {
            for ($i = 0; $i < $cantidad; $i++) {
                $stmtStock->bind_param("i", $productoId);
                $stmtStock->execute();
            }
        }
    }

    $colsPedidos = getColumns($conn, 'pedidos');
    if (!empty($colsPedidos)) {
        $pedidoData = [
            'venta_id' => $ventaId,
            'folio' => $folio,
            'cliente_id' => $clienteId,
            'cliente_nombre' => $clienteNombre,
            'cliente_telefono' => $clienteTelefono,
            'tipo_cliente' => $tipoCliente,
            'producto' => $primerProducto ?: 'Producto',
            'color' => !empty($resumenColores) ? implode(', ', array_unique($resumenColores)) : '',
            'talla' => !empty($resumenTallas) ? implode(', ', array_unique($resumenTallas)) : '',
            'diseno' => !empty($resumenDisenos) ? implode(' | ', array_unique($resumenDisenos)) : '',
            'observaciones' => $observaciones,
            'recomendaciones' => !empty($resumenUbicaciones) ? implode(' | ', array_unique($resumenUbicaciones)) : '',
            'imagen_diseno' => $imagenDisenoRuta,
            'fecha_entrega' => $fechaEntrega,
            'dia_entrega' => $diaEntrega,
            'estado' => 'NUEVO',
            'estatus' => 'NUEVO'
        ];

        $pCols = [];
        $pVals = [];
        $pTypes = '';
        $pParams = [];

        foreach ($pedidoData as $col => $val) {
            if (hasColumn($colsPedidos, $col)) {
                $pCols[] = $col;
                $pVals[] = '?';

                if (is_int($val) || $val === null) {
                    $pTypes .= 'i';
                    $pParams[] = $val ?? 0;
                } else {
                    $pTypes .= 's';
                    $pParams[] = $val;
                }
            }
        }

        if (hasColumn($colsPedidos, 'fecha_registro')) {
            $pCols[] = 'fecha_registro';
            $pVals[] = 'NOW()';
        }

        $sqlPedido = "INSERT INTO pedidos (" . implode(', ', $pCols) . ") VALUES (" . implode(', ', $pVals) . ")";
        $stmtPedido = $conn->prepare($sqlPedido);
        if (!empty($pParams)) {
            $stmtPedido->bind_param($pTypes, ...$pParams);
        }
        $stmtPedido->execute();
        $stmtPedido->close();
    }

    $remisionRelativeUrl = 'imprimir_remision.php?id=' . $ventaId;
    $ticketRelativeUrl = 'ticket_venta.php?id=' . $ventaId;
    $remisionAbsoluteUrl = buildAbsoluteUrl($remisionRelativeUrl);

    if ($telefonoNormalizado !== '') {
        $mensajeWhatsapp = "Hola {$clienteNombre} 👋\n\n"
            . "Tu pedido fue registrado correctamente con el folio *{$folio}*.\n"
            . "Gracias por tu compra en Suave Urban Studio.";

        $sendMsg = greenApiSendMessageSafe($telefonoNormalizado, $mensajeWhatsapp);
        if (!$sendMsg['ok']) {
            $warnings[] = 'No se pudo enviar el mensaje de WhatsApp: ' . $sendMsg['mensaje'];
        }

        $debeEnviarRemision = ($imprimir === 1 || $enviarRemisionWhatsapp === 1);

        if ($debeEnviarRemision) {
            $generadorUrl = buildAbsoluteUrl('generar_remision_imagen.php?id=' . $ventaId);
            $callGen = callInternalUrl($generadorUrl);

            if (!$callGen['ok']) {
                $warnings[] = 'No se pudo generar la imagen de remisión: ' . $callGen['mensaje'];
                log_debug('generar_remision_imagen fallo: ' . $callGen['mensaje'] . ' | ' . $callGen['response']);
            } else {
                log_debug('generar_remision_imagen OK: ' . $callGen['response']);
            }

            $remisionImageRelativeUrl = 'uploads/remisiones/remision_' . $ventaId . '.png';
            $remisionImageAbsoluteUrl = buildAbsoluteUrl($remisionImageRelativeUrl);

            if ($remisionImageAbsoluteUrl !== '' && publicFileExists($remisionImageAbsoluteUrl)) {
                $caption = "Hola {$clienteNombre} 👋\n\nAquí está tu nota de remisión.\nFolio: *{$folio}*";
                $sendImage = greenApiSendFileByUrlSafe(
                    $telefonoNormalizado,
                    $remisionImageAbsoluteUrl,
                    'remision-' . $folio . '.png',
                    $caption
                );

                if (!$sendImage['ok']) {
                    $warnings[] = 'No se pudo enviar la remisión por imagen: ' . $sendImage['mensaje'];
                }
            } else {
                $warnings[] = 'No se pudo generar o enviar la remisión en imagen.';
            }
        }
    }

    $conn->commit();

    $mensajeRespuesta = 'Venta registrada correctamente';

    if ($imprimir) {
        $mensajeRespuesta = 'Venta guardada e impresión lista';
    } elseif ($soloTicket) {
        $mensajeRespuesta = 'Pago registrado y ticket listo';
    } elseif ($enviarRemisionWhatsapp) {
        $mensajeRespuesta = 'Venta registrada y remisión enviada por WhatsApp';
    }

    if (!empty($warnings)) {
        $mensajeRespuesta .= ' | Avisos: ' . implode(' | ', $warnings);
    }

    responder([
        'status' => 'success',
        'mensaje' => $mensajeRespuesta,
        'venta_id' => $ventaId,
        'folio' => $folio,
        'ticket_url' => $ticketRelativeUrl,
        'remision_url' => $remisionRelativeUrl,
        'imagen_diseno' => $imagenDisenoRuta,
        'warnings' => $warnings
    ]);
} catch (Throwable $e) {
    try {
        $conn->rollback();
    } catch (Throwable $rollbackError) {
    }

    log_debug('ERROR: ' . $e->getMessage());
    responder([
        'status' => 'error',
        'mensaje' => 'Error al guardar la venta: ' . $e->getMessage()
    ]);
}
?>
<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/conexion.php';

function responder($ok, $data = [], $code = 200) {
    http_response_code($code);
    echo json_encode([
        'ok' => $ok,
        'data' => $data
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function normalizarTelefono($telefono) {
    $telefono = preg_replace('/\D+/', '', (string)$telefono);

    if (strpos($telefono, '521') === 0) {
        return $telefono;
    }

    if (strpos($telefono, '52') === 0) {
        return '521' . substr($telefono, 2);
    }

    if (strlen($telefono) === 10) {
        return '521' . $telefono;
    }

    return $telefono;
}

function obtenerInputJson() {
    $raw = file_get_contents('php://input');
    if (!$raw) {
        return [];
    }

    $json = json_decode($raw, true);
    return is_array($json) ? $json : [];
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $_GET['action'] ?? ($_POST['action'] ?? null);

if (!$action) {
    responder(false, ['mensaje' => 'Falta action'], 400);
}

try {
    if ($action === 'buscar_cliente') {
        $telefono = $_GET['telefono'] ?? '';
        $telefonoNormalizado = normalizarTelefono($telefono);

        if (!$telefonoNormalizado) {
            responder(false, ['mensaje' => 'Falta teléfono'], 400);
        }

        $sql = "SELECT 
                    cw.id,
                    cw.nombre,
                    cw.telefono,
                    cw.telefono_normalizado,
                    cw.notas,
                    cw.ultima_interaccion,
                    cw.fecha_registro,
                    conv.id AS conversacion_id,
                    conv.producto,
                    conv.estado_conversacion,
                    conv.estado_venta,
                    conv.tomado_por_asesor,
                    conv.nombre_asesor,
                    conv.pedido_id,
                    conv.remision_id,
                    conv.tiene_pedido_activo,
                    conv.tiene_anticipo,
                    conv.comprobante_recibido,
                    conv.fecha_ultimo_mensaje
                FROM clientes_whatsapp cw
                LEFT JOIN conversaciones_whatsapp conv 
                    ON conv.cliente_id = cw.id
                WHERE cw.telefono_normalizado = ?
                ORDER BY conv.fecha_actualizacion DESC
                LIMIT 1";

        $stmt = $conn->prepare($sql);
        $stmt->bind_param('s', $telefonoNormalizado);
        $stmt->execute();
        $result = $stmt->get_result();
        $cliente = $result->fetch_assoc();

        if (!$cliente) {
            responder(true, [
                'existe' => false,
                'telefono' => $telefonoNormalizado
            ]);
        }

        responder(true, [
            'existe' => true,
            'cliente' => $cliente
        ]);
    }

    if ($action === 'crear_o_actualizar_cliente') {
        $input = $method === 'POST' ? array_merge($_POST, obtenerInputJson()) : obtenerInputJson();

        $telefono = $input['telefono'] ?? '';
        $nombre = trim($input['nombre'] ?? '');
        $notas = trim($input['notas'] ?? '');

        $telefonoNormalizado = normalizarTelefono($telefono);

        if (!$telefonoNormalizado) {
            responder(false, ['mensaje' => 'Falta teléfono'], 400);
        }

        $sqlBuscar = "SELECT id FROM clientes_whatsapp WHERE telefono_normalizado = ? LIMIT 1";
        $stmtBuscar = $conn->prepare($sqlBuscar);
        $stmtBuscar->bind_param('s', $telefonoNormalizado);
        $stmtBuscar->execute();
        $resBuscar = $stmtBuscar->get_result();
        $existente = $resBuscar->fetch_assoc();

        if ($existente) {
            $clienteId = (int)$existente['id'];

            $sqlUpdate = "UPDATE clientes_whatsapp 
                          SET nombre = COALESCE(NULLIF(?, ''), nombre),
                              notas = COALESCE(NULLIF(?, ''), notas),
                              ultima_interaccion = NOW()
                          WHERE id = ?";
            $stmtUpdate = $conn->prepare($sqlUpdate);
            $stmtUpdate->bind_param('ssi', $nombre, $notas, $clienteId);
            $stmtUpdate->execute();

            responder(true, [
                'mensaje' => 'Cliente actualizado',
                'cliente_id' => $clienteId
            ]);
        }

        $sqlInsert = "INSERT INTO clientes_whatsapp (
                        nombre,
                        telefono,
                        telefono_normalizado,
                        notas,
                        ultima_interaccion
                      ) VALUES (?, ?, ?, ?, NOW())";
        $stmtInsert = $conn->prepare($sqlInsert);
        $stmtInsert->bind_param('ssss', $nombre, $telefono, $telefonoNormalizado, $notas);
        $stmtInsert->execute();

        responder(true, [
            'mensaje' => 'Cliente creado',
            'cliente_id' => $conn->insert_id
        ]);
    }

    if ($action === 'guardar_conversacion') {
        $input = $method === 'POST' ? array_merge($_POST, obtenerInputJson()) : obtenerInputJson();

        $telefono = $input['telefono'] ?? '';
        $telefonoNormalizado = normalizarTelefono($telefono);

        if (!$telefonoNormalizado) {
            responder(false, ['mensaje' => 'Falta teléfono'], 400);
        }

        $nombre = trim($input['nombre'] ?? '');
        $producto = trim($input['producto'] ?? '');
        $cantidad = isset($input['cantidad']) && $input['cantidad'] !== '' ? (int)$input['cantidad'] : null;
        $color = trim($input['color'] ?? '');
        $talla = trim($input['talla'] ?? '');
        $disenoTipo = trim($input['diseno_tipo'] ?? '');
        $disenoDetalle = trim($input['diseno_detalle'] ?? '');
        $ubicacionEstampado = trim($input['ubicacion_estampado'] ?? '');
        $estadoConversacion = trim($input['estado_conversacion'] ?? 'inicio');
        $estadoVenta = trim($input['estado_venta'] ?? 'nueva');
        $tomadoPorAsesor = !empty($input['tomado_por_asesor']) ? 1 : 0;
        $nombreAsesor = trim($input['nombre_asesor'] ?? '');
        $pedidoId = isset($input['pedido_id']) && $input['pedido_id'] !== '' ? (int)$input['pedido_id'] : null;
        $remisionId = isset($input['remision_id']) && $input['remision_id'] !== '' ? (int)$input['remision_id'] : null;
        $tienePedidoActivo = !empty($input['tiene_pedido_activo']) ? 1 : 0;
        $tieneAnticipo = !empty($input['tiene_anticipo']) ? 1 : 0;
        $comprobanteRecibido = !empty($input['comprobante_recibido']) ? 1 : 0;
        $ultimaRespuesta = trim($input['ultima_respuesta'] ?? '');

        $sqlBuscarCliente = "SELECT id FROM clientes_whatsapp WHERE telefono_normalizado = ? LIMIT 1";
        $stmtBuscarCliente = $conn->prepare($sqlBuscarCliente);
        $stmtBuscarCliente->bind_param('s', $telefonoNormalizado);
        $stmtBuscarCliente->execute();
        $resCliente = $stmtBuscarCliente->get_result();
        $cliente = $resCliente->fetch_assoc();

        if ($cliente) {
            $clienteId = (int)$cliente['id'];

            if ($nombre !== '') {
                $sqlNom = "UPDATE clientes_whatsapp SET nombre = ?, ultima_interaccion = NOW() WHERE id = ?";
                $stmtNom = $conn->prepare($sqlNom);
                $stmtNom->bind_param('si', $nombre, $clienteId);
                $stmtNom->execute();
            } else {
                $sqlNom = "UPDATE clientes_whatsapp SET ultima_interaccion = NOW() WHERE id = ?";
                $stmtNom = $conn->prepare($sqlNom);
                $stmtNom->bind_param('i', $clienteId);
                $stmtNom->execute();
            }
        } else {
            $sqlNuevoCliente = "INSERT INTO clientes_whatsapp (
                                    nombre, telefono, telefono_normalizado, ultima_interaccion
                                ) VALUES (?, ?, ?, NOW())";
            $stmtNuevoCliente = $conn->prepare($sqlNuevoCliente);
            $stmtNuevoCliente->bind_param('sss', $nombre, $telefono, $telefonoNormalizado);
            $stmtNuevoCliente->execute();
            $clienteId = $conn->insert_id;
        }

        $sqlBuscarConv = "SELECT id FROM conversaciones_whatsapp WHERE telefono = ? ORDER BY fecha_actualizacion DESC LIMIT 1";
        $stmtBuscarConv = $conn->prepare($sqlBuscarConv);
        $stmtBuscarConv->bind_param('s', $telefonoNormalizado);
        $stmtBuscarConv->execute();
        $resConv = $stmtBuscarConv->get_result();
        $conv = $resConv->fetch_assoc();

        if ($conv) {
            $convId = (int)$conv['id'];

            $sqlUpdateConv = "UPDATE conversaciones_whatsapp SET
                                cliente_id = ?,
                                producto = ?,
                                cantidad = ?,
                                color = ?,
                                talla = ?,
                                diseno_tipo = ?,
                                diseno_detalle = ?,
                                ubicacion_estampado = ?,
                                estado_conversacion = ?,
                                estado_venta = ?,
                                tomado_por_asesor = ?,
                                nombre_asesor = ?,
                                pedido_id = ?,
                                remision_id = ?,
                                tiene_pedido_activo = ?,
                                tiene_anticipo = ?,
                                comprobante_recibido = ?,
                                ultima_respuesta = ?,
                                fecha_ultimo_mensaje = NOW()
                              WHERE id = ?";
            $stmtUpdateConv = $conn->prepare($sqlUpdateConv);
            $stmtUpdateConv->bind_param(
                'isisssssssisiiiisi',
                $clienteId,
                $producto,
                $cantidad,
                $color,
                $talla,
                $disenoTipo,
                $disenoDetalle,
                $ubicacionEstampado,
                $estadoConversacion,
                $estadoVenta,
                $tomadoPorAsesor,
                $nombreAsesor,
                $pedidoId,
                $remisionId,
                $tienePedidoActivo,
                $tieneAnticipo,
                $comprobanteRecibido,
                $ultimaRespuesta,
                $convId
            );
            $stmtUpdateConv->execute();

            responder(true, [
                'mensaje' => 'Conversación actualizada',
                'cliente_id' => $clienteId,
                'conversacion_id' => $convId
            ]);
        }

        $sqlInsertConv = "INSERT INTO conversaciones_whatsapp (
                            cliente_id,
                            telefono,
                            producto,
                            cantidad,
                            color,
                            talla,
                            diseno_tipo,
                            diseno_detalle,
                            ubicacion_estampado,
                            estado_conversacion,
                            estado_venta,
                            tomado_por_asesor,
                            nombre_asesor,
                            pedido_id,
                            remision_id,
                            tiene_pedido_activo,
                            tiene_anticipo,
                            comprobante_recibido,
                            ultima_respuesta,
                            fecha_ultimo_mensaje
                          ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
        $stmtInsertConv = $conn->prepare($sqlInsertConv);
        $stmtInsertConv->bind_param(
            'ississsssssisiiiis',
            $clienteId,
            $telefonoNormalizado,
            $producto,
            $cantidad,
            $color,
            $talla,
            $disenoTipo,
            $disenoDetalle,
            $ubicacionEstampado,
            $estadoConversacion,
            $estadoVenta,
            $tomadoPorAsesor,
            $nombreAsesor,
            $pedidoId,
            $remisionId,
            $tienePedidoActivo,
            $tieneAnticipo,
            $comprobanteRecibido,
            $ultimaRespuesta
        );
        $stmtInsertConv->execute();

        responder(true, [
            'mensaje' => 'Conversación creada',
            'cliente_id' => $clienteId,
            'conversacion_id' => $conn->insert_id
        ]);
    }

    if ($action === 'guardar_mensaje') {
        $input = $method === 'POST' ? array_merge($_POST, obtenerInputJson()) : obtenerInputJson();

        $telefono = $input['telefono'] ?? '';
        $telefonoNormalizado = normalizarTelefono($telefono);

        if (!$telefonoNormalizado) {
            responder(false, ['mensaje' => 'Falta teléfono'], 400);
        }

        $direccion = trim($input['direccion'] ?? '');
        $tipoMensaje = trim($input['tipo_mensaje'] ?? 'texto');
        $mensaje = trim($input['mensaje'] ?? '');
        $archivoUrl = trim($input['archivo_url'] ?? '');
        $archivoNombre = trim($input['archivo_nombre'] ?? '');

        if (!in_array($direccion, ['entrante', 'saliente'], true)) {
            responder(false, ['mensaje' => 'Dirección inválida'], 400);
        }

        $clienteId = null;
        $convId = null;

        $sqlCliente = "SELECT id FROM clientes_whatsapp WHERE telefono_normalizado = ? LIMIT 1";
        $stmtCliente = $conn->prepare($sqlCliente);
        $stmtCliente->bind_param('s', $telefonoNormalizado);
        $stmtCliente->execute();
        $resCliente = $stmtCliente->get_result();
        $cli = $resCliente->fetch_assoc();
        if ($cli) {
            $clienteId = (int)$cli['id'];
        }

        $sqlConv = "SELECT id FROM conversaciones_whatsapp WHERE telefono = ? ORDER BY fecha_actualizacion DESC LIMIT 1";
        $stmtConv = $conn->prepare($sqlConv);
        $stmtConv->bind_param('s', $telefonoNormalizado);
        $stmtConv->execute();
        $resConv = $stmtConv->get_result();
        $cv = $resConv->fetch_assoc();
        if ($cv) {
            $convId = (int)$cv['id'];
        }

        $sqlInsertMsg = "INSERT INTO mensajes_whatsapp (
                            conversacion_id,
                            cliente_id,
                            telefono,
                            direccion,
                            tipo_mensaje,
                            mensaje,
                            archivo_url,
                            archivo_nombre
                         ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
        $stmtInsertMsg = $conn->prepare($sqlInsertMsg);
        $stmtInsertMsg->bind_param(
            'iissssss',
            $convId,
            $clienteId,
            $telefonoNormalizado,
            $direccion,
            $tipoMensaje,
            $mensaje,
            $archivoUrl,
            $archivoNombre
        );
        $stmtInsertMsg->execute();

        responder(true, [
            'mensaje' => 'Mensaje guardado',
            'mensaje_id' => $conn->insert_id
        ]);
    }

    responder(false, ['mensaje' => 'Action no válida'], 400);

} catch (Throwable $e) {
    responder(false, [
        'mensaje' => 'Error interno',
        'error' => $e->getMessage()
    ], 500);
}
<?php
session_start();
if (!isset($_SESSION['usuario_id'])) {
    header("Location: index.php");
    exit;
}

require_once 'config/database.php';

function existeTabla(mysqli $conn, string $tabla): bool
{
    $tablaSegura = $conn->real_escape_string($tabla);
    $res = $conn->query("SHOW TABLES LIKE '{$tablaSegura}'");
    return ($res && $res->num_rows > 0);
}

function obtenerColumnasTabla(mysqli $conn, string $tabla): array
{
    $columnas = [];

    if (!existeTabla($conn, $tabla)) {
        return $columnas;
    }

    $res = $conn->query("SHOW COLUMNS FROM `$tabla`");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $columnas[] = $row['Field'];
        }
    }

    return $columnas;
}

function tieneColumna(array $columnas, string $columna): bool
{
    return in_array($columna, $columnas, true);
}

function e($valor): string
{
    return htmlspecialchars((string)$valor, ENT_QUOTES, 'UTF-8');
}

function normalizarEstado(string $estado): string
{
    $estado = trim($estado);
    $base = function_exists('mb_strtolower') ? mb_strtolower($estado, 'UTF-8') : strtolower($estado);

    return match ($base) {
        'nuevo', 'pedido', 'pendiente', '' => 'NUEVO',
        'recibido', 'pedido recibido' => 'RECIBIDO',
        'en proceso', 'proceso' => 'EN PROCESO',
        'listo', 'terminado', 'listo para entrega' => 'LISTO',
        'entregado' => 'ENTREGADO',
        default => strtoupper($estado),
    };
}

function obtenerEstadoPedido(array $pedido): string
{
    $estado = trim((string)($pedido['estado'] ?? ''));
    $estatus = trim((string)($pedido['estatus'] ?? ''));

    if ($estado !== '') {
        return normalizarEstado($estado);
    }

    if ($estatus !== '') {
        return normalizarEstado($estatus);
    }

    return 'NUEVO';
}

function obtenerPrimerValor(array $fila, array $campos, string $default = ''): string
{
    foreach ($campos as $campo) {
        if (isset($fila[$campo]) && trim((string)$fila[$campo]) !== '') {
            return trim((string)$fila[$campo]);
        }
    }
    return $default;
}

function obtenerImagenPedido(array $fila): string
{
    $camposImagen = [
        'imagen_diseno',
        'ruta_imagen_diseno',
        'imagen',
        'foto_diseno',
        'diseno_imagen',
        'imagen_producto'
    ];

    foreach ($camposImagen as $campo) {
        if (!empty($fila[$campo])) {
            return trim((string)$fila[$campo]);
        }
    }

    return '';
}

$fondoSidebar = '';
$fondoContenido = '';
$logoActual = 'logo.png';
$transparenciaPanel = 0.32;
$transparenciaSidebar = 0.88;
$sonidoNuevoPedido = '';
$sonidoPedidoVencer = '';

$configColumns = obtenerColumnasTabla($conn, 'configuracion');
if (!empty($configColumns)) {
    $selectConfig = [];

    foreach ([
        'logo',
        'fondo_sidebar',
        'fondo_contenido',
        'transparencia_panel',
        'transparencia_sidebar',
        'sonido_nuevo_pedido',
        'sonido_pedido_vencer'
    ] as $col) {
        if (tieneColumna($configColumns, $col)) {
            $selectConfig[] = $col;
        }
    }

    if (!empty($selectConfig)) {
        $sqlConfig = "SELECT " . implode(', ', $selectConfig) . " FROM configuracion WHERE id = 1 LIMIT 1";
        $resConfig = $conn->query($sqlConfig);

        if ($resConfig && $resConfig->num_rows > 0) {
            $config = $resConfig->fetch_assoc();

            if (!empty($config['logo'])) $logoActual = $config['logo'];
            if (!empty($config['fondo_sidebar'])) $fondoSidebar = $config['fondo_sidebar'];
            if (!empty($config['fondo_contenido'])) $fondoContenido = $config['fondo_contenido'];
            if (isset($config['transparencia_panel'])) $transparenciaPanel = (float)$config['transparencia_panel'];
            if (isset($config['transparencia_sidebar'])) $transparenciaSidebar = (float)$config['transparencia_sidebar'];
            if (!empty($config['sonido_nuevo_pedido'])) $sonidoNuevoPedido = $config['sonido_nuevo_pedido'];
            if (!empty($config['sonido_pedido_vencer'])) $sonidoPedidoVencer = $config['sonido_pedido_vencer'];
        }
    }
}

$alphaPanel = max(0.10, min(0.95, $transparenciaPanel));
$alphaSidebar = max(0.10, min(0.98, $transparenciaSidebar));

$pedidosProduccion = [];
$nuevos = [];
$recibidos = [];
$enProceso = [];
$listos = [];
$porVencer = [];
$prioridad = [];

if (existeTabla($conn, 'pedidos')) {
    $resPedidos = $conn->query("SELECT * FROM pedidos ORDER BY id DESC");

    if ($resPedidos) {
        while ($pedido = $resPedidos->fetch_assoc()) {
            $estadoNormalizado = obtenerEstadoPedido($pedido);

            if ($estadoNormalizado === 'ENTREGADO') {
                continue;
            }

            $ventaId = (int)($pedido['venta_id'] ?? 0);

            $venta = [];
            if ($ventaId > 0 && existeTabla($conn, 'ventas')) {
                $stmtVenta = $conn->prepare("SELECT * FROM ventas WHERE id = ? LIMIT 1");
                if ($stmtVenta) {
                    $stmtVenta->bind_param("i", $ventaId);
                    $stmtVenta->execute();
                    $resVenta = $stmtVenta->get_result();
                    $venta = ($resVenta && $resVenta->num_rows > 0) ? $resVenta->fetch_assoc() : [];
                    $stmtVenta->close();
                }
            }

            $detalle = [];
            if ($ventaId > 0 && existeTabla($conn, 'ventas_detalle')) {
                $stmtDetalle = $conn->prepare("SELECT * FROM ventas_detalle WHERE venta_id = ? ORDER BY id ASC LIMIT 1");
                if ($stmtDetalle) {
                    $stmtDetalle->bind_param("i", $ventaId);
                    $stmtDetalle->execute();
                    $resDetalle = $stmtDetalle->get_result();
                    $detalle = ($resDetalle && $resDetalle->num_rows > 0) ? $resDetalle->fetch_assoc() : [];
                    $stmtDetalle->close();
                }
            }

            $mezcla = array_merge($venta, $detalle, $pedido);

            $folio = obtenerPrimerValor($mezcla, ['folio', 'folio_remision'], 'SIN FOLIO');
            $cliente = obtenerPrimerValor($mezcla, ['cliente_nombre', 'cliente', 'nombre_cliente'], 'Público en general');
            $telefono = obtenerPrimerValor($mezcla, ['cliente_telefono', 'telefono'], '');
            $producto = obtenerPrimerValor($mezcla, ['nombre_producto', 'tipo_producto', 'producto'], 'Producto');
            $talla = obtenerPrimerValor($mezcla, ['talla'], '-');
            $color = obtenerPrimerValor($mezcla, ['color'], '-');
            $diseno = obtenerPrimerValor($mezcla, ['diseno'], '-');
            $observaciones = obtenerPrimerValor($mezcla, ['observaciones', 'observacion'], '');
            $recomendaciones = obtenerPrimerValor($mezcla, ['recomendaciones', 'recomendacion', 'mensaje_remision'], '');
            $fechaEntrega = obtenerPrimerValor($mezcla, ['fecha_entrega'], '');
            $diaEntrega = obtenerPrimerValor($mezcla, ['dia_entrega'], '');
            $imagenDiseno = obtenerImagenPedido($mezcla);
            $esPrioridad = false;

            foreach (['prioridad', 'urgente', 'es_prioridad'] as $campoPrioridad) {
                if (isset($mezcla[$campoPrioridad])) {
                    $valor = strtolower(trim((string)$mezcla[$campoPrioridad]));
                    if (in_array($valor, ['1', 'si', 'sí', 'true', 'alta', 'urgente', 'prioridad'], true)) {
                        $esPrioridad = true;
                    }
                }
            }

            $faltanDias = null;
            if ($fechaEntrega !== '') {
                $hoy = new DateTime(date('Y-m-d'));
                $entrega = DateTime::createFromFormat('Y-m-d', $fechaEntrega);

                if ($entrega instanceof DateTime) {
                    $interval = $hoy->diff($entrega);
                    $dias = (int)$interval->format('%r%a');
                    $faltanDias = $dias;
                }
            }

            $item = [
                'id' => (int)($pedido['id'] ?? 0),
                'venta_id' => $ventaId,
                'estado' => $estadoNormalizado,
                'folio' => $folio,
                'cliente' => $cliente,
                'telefono' => $telefono,
                'producto' => $producto,
                'talla' => $talla,
                'color' => $color,
                'diseno' => $diseno,
                'observaciones' => $observaciones,
                'recomendaciones' => $recomendaciones,
                'fecha_entrega' => $fechaEntrega,
                'dia_entrega' => $diaEntrega,
                'imagen_diseno' => $imagenDiseno,
                'es_prioridad' => $esPrioridad,
                'faltan_dias' => $faltanDias
            ];

            $pedidosProduccion[] = $item;

            if ($faltanDias !== null && $faltanDias <= 1 && $estadoNormalizado !== 'LISTO') {
                $porVencer[] = $item;
            }

            if ($esPrioridad) {
                $prioridad[] = $item;
            }

            if ($estadoNormalizado === 'NUEVO') {
                $nuevos[] = $item;
            } elseif ($estadoNormalizado === 'RECIBIDO') {
                $recibidos[] = $item;
            } elseif ($estadoNormalizado === 'EN PROCESO') {
                $enProceso[] = $item;
            } elseif ($estadoNormalizado === 'LISTO') {
                $listos[] = $item;
            }
        }
    }
}

$tipoMensaje = trim($_GET['tipo'] ?? '');
$mensajeSistema = trim($_GET['msg'] ?? '');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Producción - Suave Urban Studio</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root{
            --gold:#c89b3c;
            --gold-soft:rgba(200,155,60,.18);
            --border:rgba(200,155,60,.16);
            --danger:#ff4d4d;
            --green:#1fb96d;
            --blue:#3b82f6;
            --orange:#f59e0b;
            --purple:#8b5cf6;
            --text-soft:#bcbcbc;
        }

        *{ box-sizing:border-box; }

        html, body{
            margin:0;
            padding:0;
            min-height:100%;
        }

        body{
            background:
                <?php echo !empty($fondoContenido)
                    ? "linear-gradient(rgba(0,0,0,0.42), rgba(0,0,0,0.62)), url('" . e($fondoContenido) . "') center/cover fixed no-repeat"
                    : "#050505"; ?>;
            color:#fff;
            font-family:'Segoe UI',sans-serif;
            display:flex;
            min-height:100vh;
            overflow-x:hidden;
        }

        .mobile-topbar { display:none; }
        .mobile-menu-toggle { display:none; }
        .sidebar-overlay { display:none; }

        .sidebar{
            width:85px;
            background:
                <?php echo !empty($fondoSidebar)
                    ? "linear-gradient(rgba(0,0,0," . $alphaSidebar . "), rgba(0,0,0," . $alphaSidebar . ")), url('" . e($fondoSidebar) . "') center/cover no-repeat"
                    : "rgba(0,0,0," . $alphaSidebar . ")"; ?>;
            border-right:1px solid var(--border);
            display:flex;
            flex-direction:column;
            align-items:center;
            padding:15px 0;
            position:fixed;
            top:0;
            left:0;
            height:100vh;
            overflow-y:auto;
            z-index:1000;
            backdrop-filter:blur(12px);
            -webkit-backdrop-filter:blur(12px);
            box-shadow:0 12px 40px rgba(0,0,0,.32);
        }

        .logo-pos{
            width:55px;
            height:auto;
            margin-bottom:16px;
            filter:drop-shadow(0 0 8px rgba(200,155,60,.45));
            animation:logoPulse 4s ease-in-out infinite, glow 3s infinite alternate;
        }

        .nav-controls{
            display:flex;
            flex-direction:column;
            gap:18px;
            margin-bottom:30px;
            border-bottom:1px solid var(--border);
            padding-bottom:20px;
            width:100%;
            align-items:center;
        }

        .sidebar a{
            color:#555;
            font-size:20px;
            transition:.3s;
            text-decoration:none;
        }

        .sidebar a:hover,
        .sidebar a.active{
            color:var(--gold);
            filter:drop-shadow(0 0 8px var(--gold));
        }

        .exit-btn:hover{
            color:#ff4d4d !important;
            filter:drop-shadow(0 0 8px #ff4d4d) !important;
        }

        .main{
            flex:1;
            margin-left:85px;
            padding:26px;
            min-width:0;
        }

        .header-card,
        .alerts-card,
        .kanban-card{
            background:rgba(15,15,15,<?php echo $alphaPanel; ?>);
            border:1px solid var(--border);
            border-radius:20px;
            padding:20px;
            margin-bottom:20px;
            backdrop-filter:blur(12px);
            -webkit-backdrop-filter:blur(12px);
            box-shadow:0 10px 40px rgba(0,0,0,.35), 0 0 24px rgba(200,155,60,.06);
            position:relative;
            overflow:hidden;
        }

        .header-card::before,
        .alerts-card::before,
        .kanban-card::before{
            content:"";
            position:absolute;
            inset:0;
            background:linear-gradient(135deg, rgba(255,255,255,.04), transparent 35%, transparent 65%, rgba(200,155,60,.04));
            pointer-events:none;
        }

        .top-row{
            display:flex;
            justify-content:space-between;
            align-items:center;
            gap:12px;
            flex-wrap:wrap;
            position:relative;
            z-index:1;
        }

        .title-main{
            margin:0;
            font-weight:200;
            letter-spacing:1px;
            font-size:34px;
        }

        .title-main span{
            color:var(--gold);
            font-weight:800;
        }

        .toast{
            margin-top:16px;
            padding:14px 16px;
            border-radius:14px;
            font-size:14px;
            font-weight:700;
            position:relative;
            z-index:1;
        }

        .toast.ok{
            background:rgba(22,163,74,.14);
            border:1px solid rgba(22,163,74,.35);
            color:#bbf7d0;
        }

        .toast.error{
            background:rgba(220,38,38,.14);
            border:1px solid rgba(220,38,38,.35);
            color:#fecaca;
        }

        .stats{
            display:grid;
            grid-template-columns:repeat(auto-fit,minmax(160px,1fr));
            gap:14px;
            margin-top:18px;
            position:relative;
            z-index:1;
        }

        .stat-box{
            background:rgba(255,255,255,.04);
            border:1px solid rgba(255,255,255,.06);
            border-radius:16px;
            padding:16px;
        }

        .stat-box .label{
            color:var(--gold);
            font-size:12px;
            text-transform:uppercase;
            letter-spacing:.8px;
            margin-bottom:8px;
        }

        .stat-box .value{
            font-size:28px;
            font-weight:800;
        }

        .alerts-grid{
            display:grid;
            grid-template-columns:1fr 1fr;
            gap:16px;
            position:relative;
            z-index:1;
        }

        .alert-panel{
            background:rgba(255,255,255,.04);
            border:1px solid rgba(255,255,255,.08);
            border-radius:16px;
            padding:16px;
            min-height:120px;
        }

        .alert-panel h3{
            margin:0 0 14px 0;
            font-size:14px;
            font-weight:700;
            letter-spacing:.8px;
            text-transform:uppercase;
        }

        .alert-vencer h3{ color:#ffb454; }
        .alert-prioridad h3{ color:#ff7a7a; }

        .alert-item{
            padding:10px 12px;
            margin-bottom:10px;
            border-radius:12px;
            background:rgba(255,255,255,.04);
            border:1px solid rgba(255,255,255,.05);
            font-size:13px;
            line-height:1.45;
        }

        .kanban{
            display:grid;
            grid-template-columns:repeat(4, minmax(280px, 1fr));
            gap:16px;
            align-items:flex-start;
            position:relative;
            z-index:1;
        }

        .column{
            background:rgba(255,255,255,.03);
            border:1px solid rgba(255,255,255,.06);
            border-radius:18px;
            padding:14px;
            min-height:420px;
        }

        .column-header{
            display:flex;
            justify-content:space-between;
            align-items:center;
            gap:8px;
            margin-bottom:14px;
            padding-bottom:10px;
            border-bottom:1px solid rgba(255,255,255,.06);
        }

        .column-title{
            margin:0;
            font-size:14px;
            text-transform:uppercase;
            letter-spacing:.8px;
            font-weight:800;
        }

        .count-pill{
            min-width:34px;
            height:34px;
            padding:0 10px;
            display:inline-flex;
            align-items:center;
            justify-content:center;
            border-radius:999px;
            font-size:13px;
            font-weight:800;
            background:rgba(255,255,255,.06);
            border:1px solid rgba(255,255,255,.08);
        }

        .col-nuevo .column-title{ color:#ffb454; }
        .col-recibido .column-title{ color:var(--blue); }
        .col-proceso .column-title{ color:var(--orange); }
        .col-listo .column-title{ color:var(--green); }

        .card-pedido{
            background:rgba(18,18,18,.78);
            border:1px solid rgba(255,255,255,.08);
            border-radius:18px;
            overflow:hidden;
            margin-bottom:14px;
            box-shadow:0 8px 24px rgba(0,0,0,.25);
            transition:.25s;
            position:relative;
        }

        .card-pedido:hover{
            transform:translateY(-3px);
            border-color:rgba(200,155,60,.24);
            box-shadow:0 12px 28px rgba(0,0,0,.28), 0 0 18px rgba(200,155,60,.09);
        }

        .card-pedido.nuevo-blink{
            animation:blinkPedido 1.1s infinite;
        }

        .card-head{
            padding:14px 14px 10px 14px;
            display:flex;
            justify-content:space-between;
            align-items:flex-start;
            gap:10px;
        }

        .folio{
            font-size:13px;
            color:var(--gold);
            font-weight:800;
            letter-spacing:.5px;
        }

        .estado-badge{
            display:inline-flex;
            align-items:center;
            justify-content:center;
            padding:6px 10px;
            border-radius:999px;
            font-size:11px;
            font-weight:800;
            text-transform:uppercase;
            letter-spacing:.5px;
            white-space:nowrap;
        }

        .estado-nuevo{ background:rgba(255,180,84,.16); color:#ffca80; border:1px solid rgba(255,180,84,.22); }
        .estado-recibido{ background:rgba(59,130,246,.16); color:#bfdbfe; border:1px solid rgba(59,130,246,.22); }
        .estado-proceso{ background:rgba(245,158,11,.16); color:#fde68a; border:1px solid rgba(245,158,11,.22); }
        .estado-listo{ background:rgba(31,185,109,.16); color:#bbf7d0; border:1px solid rgba(31,185,109,.22); }

        .img-box{
            width:100%;
            height:190px;
            background:rgba(255,255,255,.03);
            border-top:1px solid rgba(255,255,255,.04);
            border-bottom:1px solid rgba(255,255,255,.04);
            display:flex;
            align-items:center;
            justify-content:center;
            overflow:hidden;
        }

        .img-box img{
            width:100%;
            height:100%;
            object-fit:cover;
            display:block;
        }

        .img-empty{
            text-align:center;
            color:#888;
            font-size:13px;
            padding:10px;
        }

        .card-body{
            padding:14px;
        }

        .pedido-title{
            margin:0 0 10px 0;
            font-size:17px;
            font-weight:800;
            line-height:1.35;
        }

        .detail-grid{
            display:grid;
            grid-template-columns:1fr 1fr;
            gap:8px;
            margin-bottom:10px;
        }

        .detail{
            background:rgba(255,255,255,.03);
            border:1px solid rgba(255,255,255,.04);
            border-radius:12px;
            padding:10px;
        }

        .detail .k{
            display:block;
            font-size:10px;
            color:var(--text-soft);
            text-transform:uppercase;
            letter-spacing:.8px;
            margin-bottom:4px;
        }

        .detail .v{
            font-size:13px;
            font-weight:700;
            word-break:break-word;
        }

        .note-box{
            margin-top:8px;
            background:rgba(255,255,255,.03);
            border:1px solid rgba(255,255,255,.04);
            border-radius:12px;
            padding:10px;
            font-size:13px;
            line-height:1.5;
        }

        .note-box strong{
            color:var(--gold);
            display:block;
            margin-bottom:5px;
            font-size:11px;
            letter-spacing:.7px;
            text-transform:uppercase;
        }

        .badges-row{
            display:flex;
            flex-wrap:wrap;
            gap:8px;
            margin:10px 0 12px 0;
        }

        .mini-badge{
            display:inline-flex;
            align-items:center;
            justify-content:center;
            padding:6px 10px;
            border-radius:999px;
            font-size:11px;
            font-weight:800;
            letter-spacing:.4px;
        }

        .badge-prioridad{
            background:rgba(255,77,77,.14);
            color:#ff9f9f;
            border:1px solid rgba(255,77,77,.24);
        }

        .badge-vencer{
            background:rgba(255,180,84,.14);
            color:#ffd18d;
            border:1px solid rgba(255,180,84,.24);
        }

        .actions{
            display:flex;
            gap:10px;
            margin-top:12px;
        }

        .btn-action{
            width:100%;
            border:none;
            padding:14px;
            border-radius:12px;
            font-weight:800;
            cursor:pointer;
            transition:.25s;
            text-transform:uppercase;
            letter-spacing:.7px;
        }

        .btn-recibido{
            background:linear-gradient(135deg, #3b82f6, #2563eb);
            color:#fff;
        }

        .btn-proceso{
            background:linear-gradient(135deg, #f59e0b, #d97706);
            color:#fff;
        }

        .btn-listo{
            background:linear-gradient(135deg, #1fb96d, #16a34a);
            color:#fff;
        }

        .btn-action:hover{
            transform:translateY(-2px);
            box-shadow:0 10px 22px rgba(0,0,0,.22);
        }

        .empty-col{
            border:1px dashed rgba(255,255,255,.08);
            color:#888;
            border-radius:16px;
            padding:18px;
            text-align:center;
            font-size:13px;
        }

        @keyframes blinkPedido{
            0%,100%{
                box-shadow:0 0 0 rgba(255,77,77,0);
                border-color:rgba(255,180,84,.22);
            }
            50%{
                box-shadow:0 0 20px rgba(255,77,77,.28);
                border-color:rgba(255,77,77,.42);
            }
        }

        @keyframes logoPulse{
            0%,100%{ transform:scale(1); }
            50%{ transform:scale(1.08); }
        }

        @keyframes glow{
            from{ filter:drop-shadow(0 0 5px rgba(200,155,60,.4)); }
            to{ filter:drop-shadow(0 0 15px rgba(200,155,60,.7)); }
        }

        @media (max-width:1400px){
            .kanban{
                grid-template-columns:repeat(2, minmax(280px, 1fr));
            }
        }

        @media (max-width:980px){
            body{
                flex-direction:column;
                overflow-y:auto;
            }

            .mobile-topbar{
                display:flex;
                align-items:center;
                justify-content:space-between;
                gap:12px;
                position:sticky;
                top:0;
                z-index:1100;
                padding:14px 16px;
                background:rgba(5,5,5,.92);
                border-bottom:1px solid var(--border);
                backdrop-filter:blur(10px);
                -webkit-backdrop-filter:blur(10px);
            }

            .mobile-topbar-left{
                display:flex;
                align-items:center;
                gap:10px;
                min-width:0;
            }

            .mobile-topbar-logo{
                width:38px;
                height:38px;
                object-fit:contain;
            }

            .mobile-topbar-title{
                font-size:14px;
                font-weight:700;
                color:#fff;
                white-space:nowrap;
                overflow:hidden;
                text-overflow:ellipsis;
            }

            .mobile-menu-toggle{
                display:inline-flex;
                align-items:center;
                justify-content:center;
                width:42px;
                height:42px;
                background:rgba(255,255,255,.06);
                color:var(--gold);
                border:1px solid rgba(255,255,255,.08);
                border-radius:12px;
                font-size:18px;
                cursor:pointer;
            }

            .sidebar-overlay{
                display:block;
                position:fixed;
                inset:0;
                background:rgba(0,0,0,.45);
                opacity:0;
                visibility:hidden;
                transition:.3s ease;
                z-index:999;
            }

            .sidebar{
                width:280px;
                max-width:82vw;
                transform:translateX(-100%);
                transition:transform .3s ease;
                padding-top:20px;
            }

            body.menu-open .sidebar{ transform:translateX(0); }
            body.menu-open .sidebar-overlay{
                opacity:1;
                visibility:visible;
            }

            .main{
                margin-left:0;
                padding:18px 12px 22px 12px;
            }

            .alerts-grid,
            .kanban{
                grid-template-columns:1fr;
            }
        }

        @media (max-width:620px){
            .detail-grid{
                grid-template-columns:1fr;
            }

            .title-main{
                font-size:28px;
            }

            .header-card,
            .alerts-card,
            .kanban-card{
                padding:16px;
                border-radius:16px;
            }
        }
    </style>
</head>
<body>

    <div class="mobile-topbar">
        <div class="mobile-topbar-left">
            <img src="<?php echo e($logoActual); ?>" alt="Logo" class="mobile-topbar-logo">
            <div class="mobile-topbar-title">Producción</div>
        </div>
        <button class="mobile-menu-toggle" id="mobileMenuToggle" aria-label="Abrir menú">
            <i class="fas fa-bars"></i>
        </button>
    </div>

    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <?php if ($sonidoNuevoPedido !== ''): ?>
        <audio id="audioNuevoPedido" src="<?php echo e($sonidoNuevoPedido); ?>" preload="auto"></audio>
    <?php endif; ?>

    <?php if ($sonidoPedidoVencer !== ''): ?>
        <audio id="audioPedidoVencer" src="<?php echo e($sonidoPedidoVencer); ?>" preload="auto"></audio>
    <?php endif; ?>

    <div class="sidebar" id="sidebar">
        <img src="<?php echo e($logoActual); ?>" alt="Logo" class="logo-pos">

        <div class="nav-controls">
            <a href="dashboard.php" title="Volver al Dashboard"><i class="fas fa-home"></i></a>
            <a href="logout.php" class="exit-btn" title="Salir del Sistema"><i class="fas fa-power-off"></i></a>
        </div>

        <a href="ventas.php" title="Ventas"><i class="fas fa-cash-register"></i></a>
        <a href="pedidos.php" title="Pedidos"><i class="fas fa-list-check"></i></a>
        <a href="produccion.php" class="active" title="Producción"><i class="fas fa-screwdriver-wrench"></i></a>
        <a href="configuracion.php" title="Configuración"><i class="fas fa-cog"></i></a>
    </div>

    <div class="main">
        <div class="header-card">
            <div class="top-row">
                <h1 class="title-main">Módulo <span>PRODUCCIÓN</span></h1>
                <div style="display:flex; gap:10px; flex-wrap:wrap;">
                    <div class="count-pill" title="Total nuevos"><i class="fas fa-bell"></i>&nbsp; <?php echo count($nuevos); ?></div>
                    <div class="count-pill" title="Total producción"><i class="fas fa-layer-group"></i>&nbsp; <?php echo count($pedidosProduccion); ?></div>
                </div>
            </div>

            <?php if ($mensajeSistema !== ''): ?>
                <div class="toast <?php echo $tipoMensaje === 'ok' ? 'ok' : 'error'; ?>">
                    <?php echo e($mensajeSistema); ?>
                </div>
            <?php endif; ?>

            <div class="stats">
                <div class="stat-box">
                    <div class="label">Nuevo</div>
                    <div class="value"><?php echo count($nuevos); ?></div>
                </div>
                <div class="stat-box">
                    <div class="label">Recibido</div>
                    <div class="value"><?php echo count($recibidos); ?></div>
                </div>
                <div class="stat-box">
                    <div class="label">En proceso</div>
                    <div class="value"><?php echo count($enProceso); ?></div>
                </div>
                <div class="stat-box">
                    <div class="label">Listo</div>
                    <div class="value"><?php echo count($listos); ?></div>
                </div>
                <div class="stat-box">
                    <div class="label">Por vencer</div>
                    <div class="value"><?php echo count($porVencer); ?></div>
                </div>
                <div class="stat-box">
                    <div class="label">Prioridad</div>
                    <div class="value"><?php echo count($prioridad); ?></div>
                </div>
            </div>
        </div>

        <div class="alerts-card">
            <div class="alerts-grid">
                <div class="alert-panel alert-vencer">
                    <h3><i class="fas fa-hourglass-half"></i> Pedidos por vencer</h3>
                    <?php if (!empty($porVencer)): ?>
                        <?php foreach ($porVencer as $item): ?>
                            <div class="alert-item">
                                <strong><?php echo e($item['folio']); ?></strong><br>
                                <?php echo e($item['cliente']); ?> · <?php echo e($item['producto']); ?><br>
                                Entrega: <?php echo e($item['fecha_entrega']); ?> <?php echo $item['dia_entrega'] !== '' ? '(' . e($item['dia_entrega']) . ')' : ''; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="alert-item">No hay pedidos por vencer.</div>
                    <?php endif; ?>
                </div>

                <div class="alert-panel alert-prioridad">
                    <h3><i class="fas fa-triangle-exclamation"></i> Pedidos prioridad</h3>
                    <?php if (!empty($prioridad)): ?>
                        <?php foreach ($prioridad as $item): ?>
                            <div class="alert-item">
                                <strong><?php echo e($item['folio']); ?></strong><br>
                                <?php echo e($item['cliente']); ?> · <?php echo e($item['producto']); ?><br>
                                Fecha entrega: <?php echo e($item['fecha_entrega']); ?>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="alert-item">No hay pedidos marcados como prioridad.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="kanban-card">
            <div class="kanban">

                <div class="column col-nuevo">
                    <div class="column-header">
                        <h3 class="column-title">NUEVO</h3>
                        <div class="count-pill"><?php echo count($nuevos); ?></div>
                    </div>

                    <?php if (!empty($nuevos)): ?>
                        <?php foreach ($nuevos as $item): ?>
                            <div class="card-pedido nuevo-blink">
                                <div class="card-head">
                                    <div class="folio"><?php echo e($item['folio']); ?></div>
                                    <div class="estado-badge estado-nuevo">NUEVO</div>
                                </div>

                                <div class="img-box">
                                    <?php if ($item['imagen_diseno'] !== ''): ?>
                                        <img src="<?php echo e($item['imagen_diseno']); ?>" alt="Diseño">
                                    <?php else: ?>
                                        <div class="img-empty">
                                            <i class="fas fa-image" style="font-size:30px; color:#666;"></i><br>
                                            Sin imagen del diseño
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <div class="card-body">
                                    <h4 class="pedido-title"><?php echo e($item['producto']); ?></h4>

                                    <div class="detail-grid">
                                        <div class="detail">
                                            <span class="k">Cliente</span>
                                            <span class="v"><?php echo e($item['cliente']); ?></span>
                                        </div>
                                        <div class="detail">
                                            <span class="k">Entrega</span>
                                            <span class="v"><?php echo e($item['fecha_entrega']); ?></span>
                                        </div>
                                        <div class="detail">
                                            <span class="k">Talla</span>
                                            <span class="v"><?php echo e($item['talla']); ?></span>
                                        </div>
                                        <div class="detail">
                                            <span class="k">Color</span>
                                            <span class="v"><?php echo e($item['color']); ?></span>
                                        </div>
                                    </div>

                                    <div class="note-box">
                                        <strong>Diseño</strong>
                                        <?php echo e($item['diseno']); ?>
                                    </div>

                                    <?php if ($item['observaciones'] !== ''): ?>
                                        <div class="note-box">
                                            <strong>Observaciones</strong>
                                            <?php echo e($item['observaciones']); ?>
                                        </div>
                                    <?php endif; ?>

                                    <?php if ($item['recomendaciones'] !== ''): ?>
                                        <div class="note-box">
                                            <strong>Recomendaciones</strong>
                                            <?php echo e($item['recomendaciones']); ?>
                                        </div>
                                    <?php endif; ?>

                                    <div class="badges-row">
                                        <?php if ($item['es_prioridad']): ?>
                                            <span class="mini-badge badge-prioridad">PRIORIDAD</span>
                                        <?php endif; ?>
                                        <?php if ($item['faltan_dias'] !== null && $item['faltan_dias'] <= 1): ?>
                                            <span class="mini-badge badge-vencer">POR VENCER</span>
                                        <?php endif; ?>
                                    </div>

                                    <div class="actions">
                                        <form method="POST" action="produccion_estado.php" style="width:100%;">
                                            <input type="hidden" name="pedido_id" value="<?php echo (int)$item['id']; ?>">
                                            <input type="hidden" name="nuevo_estado" value="RECIBIDO">
                                            <button type="submit" class="btn-action btn-recibido">RECIBIDO</button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty-col">No hay pedidos nuevos.</div>
                    <?php endif; ?>
                </div>

                <div class="column col-recibido">
                    <div class="column-header">
                        <h3 class="column-title">RECIBIDO</h3>
                        <div class="count-pill"><?php echo count($recibidos); ?></div>
                    </div>

                    <?php if (!empty($recibidos)): ?>
                        <?php foreach ($recibidos as $item): ?>
                            <div class="card-pedido">
                                <div class="card-head">
                                    <div class="folio"><?php echo e($item['folio']); ?></div>
                                    <div class="estado-badge estado-recibido">RECIBIDO</div>
                                </div>

                                <div class="img-box">
                                    <?php if ($item['imagen_diseno'] !== ''): ?>
                                        <img src="<?php echo e($item['imagen_diseno']); ?>" alt="Diseño">
                                    <?php else: ?>
                                        <div class="img-empty">
                                            <i class="fas fa-image" style="font-size:30px; color:#666;"></i><br>
                                            Sin imagen del diseño
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <div class="card-body">
                                    <h4 class="pedido-title"><?php echo e($item['producto']); ?></h4>

                                    <div class="detail-grid">
                                        <div class="detail">
                                            <span class="k">Cliente</span>
                                            <span class="v"><?php echo e($item['cliente']); ?></span>
                                        </div>
                                        <div class="detail">
                                            <span class="k">Entrega</span>
                                            <span class="v"><?php echo e($item['fecha_entrega']); ?></span>
                                        </div>
                                        <div class="detail">
                                            <span class="k">Talla</span>
                                            <span class="v"><?php echo e($item['talla']); ?></span>
                                        </div>
                                        <div class="detail">
                                            <span class="k">Color</span>
                                            <span class="v"><?php echo e($item['color']); ?></span>
                                        </div>
                                    </div>

                                    <div class="note-box">
                                        <strong>Diseño</strong>
                                        <?php echo e($item['diseno']); ?>
                                    </div>

                                    <?php if ($item['observaciones'] !== ''): ?>
                                        <div class="note-box">
                                            <strong>Observaciones</strong>
                                            <?php echo e($item['observaciones']); ?>
                                        </div>
                                    <?php endif; ?>

                                    <?php if ($item['recomendaciones'] !== ''): ?>
                                        <div class="note-box">
                                            <strong>Recomendaciones</strong>
                                            <?php echo e($item['recomendaciones']); ?>
                                        </div>
                                    <?php endif; ?>

                                    <div class="badges-row">
                                        <?php if ($item['es_prioridad']): ?>
                                            <span class="mini-badge badge-prioridad">PRIORIDAD</span>
                                        <?php endif; ?>
                                        <?php if ($item['faltan_dias'] !== null && $item['faltan_dias'] <= 1): ?>
                                            <span class="mini-badge badge-vencer">POR VENCER</span>
                                        <?php endif; ?>
                                    </div>

                                    <div class="actions">
                                        <form method="POST" action="produccion_estado.php" style="width:100%;">
                                            <input type="hidden" name="pedido_id" value="<?php echo (int)$item['id']; ?>">
                                            <input type="hidden" name="nuevo_estado" value="EN PROCESO">
                                            <button type="submit" class="btn-action btn-proceso">EN PROCESO</button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty-col">No hay pedidos recibidos.</div>
                    <?php endif; ?>
                </div>

                <div class="column col-proceso">
                    <div class="column-header">
                        <h3 class="column-title">EN PROCESO</h3>
                        <div class="count-pill"><?php echo count($enProceso); ?></div>
                    </div>

                    <?php if (!empty($enProceso)): ?>
                        <?php foreach ($enProceso as $item): ?>
                            <div class="card-pedido">
                                <div class="card-head">
                                    <div class="folio"><?php echo e($item['folio']); ?></div>
                                    <div class="estado-badge estado-proceso">EN PROCESO</div>
                                </div>

                                <div class="img-box">
                                    <?php if ($item['imagen_diseno'] !== ''): ?>
                                        <img src="<?php echo e($item['imagen_diseno']); ?>" alt="Diseño">
                                    <?php else: ?>
                                        <div class="img-empty">
                                            <i class="fas fa-image" style="font-size:30px; color:#666;"></i><br>
                                            Sin imagen del diseño
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <div class="card-body">
                                    <h4 class="pedido-title"><?php echo e($item['producto']); ?></h4>

                                    <div class="detail-grid">
                                        <div class="detail">
                                            <span class="k">Cliente</span>
                                            <span class="v"><?php echo e($item['cliente']); ?></span>
                                        </div>
                                        <div class="detail">
                                            <span class="k">Entrega</span>
                                            <span class="v"><?php echo e($item['fecha_entrega']); ?></span>
                                        </div>
                                        <div class="detail">
                                            <span class="k">Talla</span>
                                            <span class="v"><?php echo e($item['talla']); ?></span>
                                        </div>
                                        <div class="detail">
                                            <span class="k">Color</span>
                                            <span class="v"><?php echo e($item['color']); ?></span>
                                        </div>
                                    </div>

                                    <div class="note-box">
                                        <strong>Diseño</strong>
                                        <?php echo e($item['diseno']); ?>
                                    </div>

                                    <?php if ($item['observaciones'] !== ''): ?>
                                        <div class="note-box">
                                            <strong>Observaciones</strong>
                                            <?php echo e($item['observaciones']); ?>
                                        </div>
                                    <?php endif; ?>

                                    <?php if ($item['recomendaciones'] !== ''): ?>
                                        <div class="note-box">
                                            <strong>Recomendaciones</strong>
                                            <?php echo e($item['recomendaciones']); ?>
                                        </div>
                                    <?php endif; ?>

                                    <div class="badges-row">
                                        <?php if ($item['es_prioridad']): ?>
                                            <span class="mini-badge badge-prioridad">PRIORIDAD</span>
                                        <?php endif; ?>
                                        <?php if ($item['faltan_dias'] !== null && $item['faltan_dias'] <= 1): ?>
                                            <span class="mini-badge badge-vencer">POR VENCER</span>
                                        <?php endif; ?>
                                    </div>

                                    <div class="actions">
                                        <form method="POST" action="produccion_estado.php" style="width:100%;">
                                            <input type="hidden" name="pedido_id" value="<?php echo (int)$item['id']; ?>">
                                            <input type="hidden" name="nuevo_estado" value="LISTO">
                                            <button type="submit" class="btn-action btn-listo">LISTO</button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty-col">No hay pedidos en proceso.</div>
                    <?php endif; ?>
                </div>

                <div class="column col-listo">
                    <div class="column-header">
                        <h3 class="column-title">LISTO</h3>
                        <div class="count-pill"><?php echo count($listos); ?></div>
                    </div>

                    <?php if (!empty($listos)): ?>
                        <?php foreach ($listos as $item): ?>
                            <div class="card-pedido">
                                <div class="card-head">
                                    <div class="folio"><?php echo e($item['folio']); ?></div>
                                    <div class="estado-badge estado-listo">LISTO</div>
                                </div>

                                <div class="img-box">
                                    <?php if ($item['imagen_diseno'] !== ''): ?>
                                        <img src="<?php echo e($item['imagen_diseno']); ?>" alt="Diseño">
                                    <?php else: ?>
                                        <div class="img-empty">
                                            <i class="fas fa-image" style="font-size:30px; color:#666;"></i><br>
                                            Sin imagen del diseño
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <div class="card-body">
                                    <h4 class="pedido-title"><?php echo e($item['producto']); ?></h4>

                                    <div class="detail-grid">
                                        <div class="detail">
                                            <span class="k">Cliente</span>
                                            <span class="v"><?php echo e($item['cliente']); ?></span>
                                        </div>
                                        <div class="detail">
                                            <span class="k">Entrega</span>
                                            <span class="v"><?php echo e($item['fecha_entrega']); ?></span>
                                        </div>
                                        <div class="detail">
                                            <span class="k">Talla</span>
                                            <span class="v"><?php echo e($item['talla']); ?></span>
                                        </div>
                                        <div class="detail">
                                            <span class="k">Color</span>
                                            <span class="v"><?php echo e($item['color']); ?></span>
                                        </div>
                                    </div>

                                    <div class="note-box">
                                        <strong>Diseño</strong>
                                        <?php echo e($item['diseno']); ?>
                                    </div>

                                    <?php if ($item['observaciones'] !== ''): ?>
                                        <div class="note-box">
                                            <strong>Observaciones</strong>
                                            <?php echo e($item['observaciones']); ?>
                                        </div>
                                    <?php endif; ?>

                                    <?php if ($item['recomendaciones'] !== ''): ?>
                                        <div class="note-box">
                                            <strong>Recomendaciones</strong>
                                            <?php echo e($item['recomendaciones']); ?>
                                        </div>
                                    <?php endif; ?>

                                    <div class="badges-row">
                                        <?php if ($item['es_prioridad']): ?>
                                            <span class="mini-badge badge-prioridad">PRIORIDAD</span>
                                        <?php endif; ?>
                                        <?php if ($item['faltan_dias'] !== null && $item['faltan_dias'] <= 1): ?>
                                            <span class="mini-badge badge-vencer">POR VENCER</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty-col">No hay pedidos listos.</div>
                    <?php endif; ?>
                </div>

            </div>
        </div>
    </div>

    <script>
        const body = document.body;
        const mobileMenuToggle = document.getElementById('mobileMenuToggle');
        const sidebarOverlay = document.getElementById('sidebarOverlay');

        function abrirMenu() { body.classList.add('menu-open'); }
        function cerrarMenu() { body.classList.remove('menu-open'); }

        if (mobileMenuToggle) {
            mobileMenuToggle.addEventListener('click', function () {
                body.classList.contains('menu-open') ? cerrarMenu() : abrirMenu();
            });
        }

        if (sidebarOverlay) {
            sidebarOverlay.addEventListener('click', cerrarMenu);
        }

        document.querySelectorAll('.sidebar a').forEach(link => {
            link.addEventListener('click', function () {
                if (window.innerWidth <= 980) cerrarMenu();
            });
        });

        window.addEventListener('resize', function () {
            if (window.innerWidth > 980) cerrarMenu();
        });

        window.addEventListener('load', function () {
            const totalNuevos = <?php echo count($nuevos); ?>;
            const totalPorVencer = <?php echo count($porVencer); ?>;

            const audioNuevo = document.getElementById('audioNuevoPedido');
            const audioVencer = document.getElementById('audioPedidoVencer');

            if (totalNuevos > 0 && audioNuevo) {
                audioNuevo.play().catch(() => {});
            }

            if (totalPorVencer > 0 && audioVencer) {
                audioVencer.play().catch(() => {});
            }
        });
    </script>

<script>
(function () {
    let ultimaActividad = Date.now();
    const intervaloMs = 8000;

    ['click', 'keydown', 'mousemove', 'touchstart'].forEach(function (evento) {
        document.addEventListener(evento, function () {
            ultimaActividad = Date.now();
        }, { passive: true });
    });

    setInterval(function () {
        const tiempoInactivo = Date.now() - ultimaActividad;
        if (tiempoInactivo >= 6000) {
            window.location.reload();
        }
    }, intervaloMs);
})();
</script>

</body>
</html>
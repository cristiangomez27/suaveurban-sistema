<?php
session_start();
require_once 'config/database.php';

if (!isset($_SESSION['usuario_id'])) { 
    header("Location: index.php"); 
    exit; 
}

$mensaje = '';

/*
|--------------------------------------------------------------------------
| CARGAR CONFIGURACIÓN VISUAL COMPLETA
|--------------------------------------------------------------------------
*/
function existeTablaVisualPapelera(mysqli $conn, string $tabla): bool {
    $tabla = $conn->real_escape_string($tabla);
    $res = $conn->query("SHOW TABLES LIKE '$tabla'");
    return ($res && $res->num_rows > 0);
}

function obtenerColumnasVisualPapelera(mysqli $conn, string $tabla): array {
    $columnas = [];
    if (!existeTablaVisualPapelera($conn, $tabla)) return $columnas;

    $res = $conn->query("SHOW COLUMNS FROM `$tabla`");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $columnas[] = $row['Field'];
        }
    }
    return $columnas;
}

function tieneColumnaVisualPapelera(array $columnas, string $columna): bool {
    return in_array($columna, $columnas, true);
}

$fondoSidebar = '';
$fondoContenido = '';
$logoActual = 'logo.png';
$transparenciaPanel = 0.32;
$transparenciaSidebar = 0.88;

$configCols = obtenerColumnasVisualPapelera($conn, 'configuracion');
if (!empty($configCols)) {
    $selectConfig = [];

    foreach (['logo', 'fondo_sidebar', 'fondo_contenido', 'transparencia_panel', 'transparencia_sidebar'] as $col) {
        if (tieneColumnaVisualPapelera($configCols, $col)) {
            $selectConfig[] = $col;
        }
    }

    if (!empty($selectConfig)) {
        $sqlConfig = "SELECT " . implode(', ', $selectConfig) . " FROM configuracion WHERE id = 1 LIMIT 1";
        $resConfigVisual = $conn->query($sqlConfig);

        if ($resConfigVisual && $resConfigVisual->num_rows > 0) {
            $configVisual = $resConfigVisual->fetch_assoc();
            if (!empty($configVisual['fondo_sidebar'])) $fondoSidebar = $configVisual['fondo_sidebar'];
            if (!empty($configVisual['fondo_contenido'])) $fondoContenido = $configVisual['fondo_contenido'];
            if (!empty($configVisual['logo'])) $logoActual = $configVisual['logo'];
            if (isset($configVisual['transparencia_panel'])) $transparenciaPanel = (float)$configVisual['transparencia_panel'];
            if (isset($configVisual['transparencia_sidebar'])) $transparenciaSidebar = (float)$configVisual['transparencia_sidebar'];
        }
    }
}

$transparenciaPanel = max(0.10, min(0.95, $transparenciaPanel));
$transparenciaSidebar = max(0.10, min(0.98, $transparenciaSidebar));

/*
|--------------------------------------------------------------------------
| LÓGICA DE RESTAURACIÓN
|--------------------------------------------------------------------------
*/
if (isset($_POST['accion']) && $_POST['accion'] === 'restaurar') {

    $id_papelera = (int)$_POST['id_papelera'];

    $res = $conn->query("SELECT * FROM papelera WHERE id = $id_papelera");

    if ($res && $fila = $res->fetch_assoc()) {

        $datos = json_decode($fila['datos_json'], true);
        $tabla = $fila['modulo'];

        $columnas = implode(", ", array_keys($datos));
        $valores = "'" . implode("', '", array_map([$conn, 'real_escape_string'], array_values($datos))) . "'";

        if ($conn->query("INSERT INTO $tabla ($columnas) VALUES ($valores)")) {
            $conn->query("DELETE FROM papelera WHERE id = $id_papelera");
            $mensaje = "✅ Registro restaurado con éxito.";
        }
    }
}

/*
|--------------------------------------------------------------------------
| LÓGICA DE ELIMINAR PERMANENTE
|--------------------------------------------------------------------------
*/
if (isset($_POST['accion']) && $_POST['accion'] === 'borrar_fin') {

    $id_papelera = (int)$_POST['id_papelera'];

    $conn->query("DELETE FROM papelera WHERE id = $id_papelera");

    $mensaje = "🗑️ Registro eliminado permanentemente.";
}

$items = $conn->query("SELECT * FROM papelera ORDER BY fecha_eliminacion DESC");
?>
<!DOCTYPE html>
<html lang="es">
<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Papelera - Suave Urban Studio</title>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

<style>
:root{
    --gold:#c89b3c;
    --gold-glow:rgba(200,155,60,0.4);
    --bg:#050505;
    --glass-border:rgba(200,155,60,0.15);
    --text-muted:#888;
    --shadow-gold:0 0 20px rgba(200,155,60,0.16);
}

*{
    box-sizing:border-box;
}

html, body{
    margin:0;
    padding:0;
    min-height:100%;
}

@keyframes fadeIn{
    from{opacity:0;transform:translateY(15px);}
    to{opacity:1;transform:translateY(0);}
}

@keyframes logoPulse{
    0%,100%{transform:scale(1);}
    50%{transform:scale(1.05);}
}

@keyframes glow{
    from{filter:drop-shadow(0 0 5px rgba(200,155,60,0.4));}
    to{filter:drop-shadow(0 0 15px rgba(200,155,60,0.7));}
}

body{
    background:
        <?php echo !empty($fondoContenido)
            ? "linear-gradient(rgba(0,0,0,0.45), rgba(0,0,0,0.60)), url('" . htmlspecialchars($fondoContenido, ENT_QUOTES, 'UTF-8') . "') center/cover fixed no-repeat"
            : "radial-gradient(circle at top right, rgba(200,155,60,0.10), transparent 25%), #050505"; ?>;
    color:white;
    font-family:'Segoe UI',sans-serif;
    min-height:100vh;
    overflow-x:hidden;
    position:relative;
    display:flex;
}

body::before{
    content:"";
    position:fixed;
    inset:0;
    background:rgba(0,0,0,0.28);
    z-index:-1;
}

.mobile-topbar{ display:none; }
.mobile-menu-toggle{ display:none; }
.sidebar-overlay{ display:none; }

.sidebar{
    width:85px;
    background:
        <?php echo !empty($fondoSidebar)
            ? "linear-gradient(rgba(0,0,0," . $transparenciaSidebar . "), rgba(0,0,0," . $transparenciaSidebar . ")), url('" . htmlspecialchars($fondoSidebar, ENT_QUOTES, 'UTF-8') . "') center/cover no-repeat"
            : "rgba(0,0,0," . $transparenciaSidebar . ")"; ?>;
    backdrop-filter:blur(15px);
    -webkit-backdrop-filter:blur(15px);
    border-right:1px solid var(--glass-border);
    display:flex;
    flex-direction:column;
    align-items:center;
    padding:15px 0;
    z-index:1000;
    position:fixed;
    top:0;
    left:0;
    height:100vh;
    overflow-y:auto;
    box-shadow:0 10px 40px rgba(0,0,0,0.35);
}

.logo-pos{
    width:55px;
    height:auto;
    margin-bottom:20px;
    animation:logoPulse 4s infinite, glow 3s infinite alternate;
}

.nav-controls{
    display:flex;
    flex-direction:column;
    gap:20px;
    margin-bottom:30px;
    border-bottom:1px solid var(--glass-border);
    padding-bottom:20px;
    width:100%;
    align-items:center;
}

.sidebar a{
    color:#555;
    font-size:22px;
    transition:0.3s;
    text-decoration:none;
    margin-bottom:18px;
}

.sidebar a:hover,
.sidebar a.active{
    color:var(--gold);
    filter:drop-shadow(0 0 8px var(--gold));
}

.main{
    flex:1;
    margin-left:85px;
    padding:40px;
    width:calc(100% - 85px);
    min-width:0;
    animation:fadeIn 0.6s ease-out;
}

.glass-card{
    background:rgba(15,15,15,<?php echo $transparenciaPanel; ?>);
    backdrop-filter:blur(12px);
    -webkit-backdrop-filter:blur(12px);
    border:1px solid var(--glass-border);
    border-radius:20px;
    padding:25px;
    margin-bottom:30px;
    box-shadow:0 10px 40px rgba(0,0,0,0.5), 0 0 20px rgba(200,155,60,0.08);
    overflow:hidden;
    position:relative;
}

.glass-card::before{
    content:"";
    position:absolute;
    inset:0;
    background:linear-gradient(135deg, rgba(255,255,255,0.04), transparent 35%, transparent 70%, rgba(200,155,60,0.04));
    pointer-events:none;
}

.titulo{
    margin:0 0 25px 0;
    font-size:30px;
    font-weight:200;
    letter-spacing:1px;
}

.titulo span{
    color:var(--gold);
    font-weight:800;
}

.mensaje{
    padding:15px;
    border-radius:14px;
    margin-bottom:25px;
    border:1px solid rgba(200,155,60,0.35);
    transition:opacity .4s ease;
    box-shadow:0 10px 30px rgba(0,0,0,0.25);
    word-break:break-word;
    background:rgba(200,155,60,0.10);
    position:relative;
    z-index:1;
}

.table-wrap{
    width:100%;
    overflow-x:auto;
    -webkit-overflow-scrolling:touch;
    margin-top:10px;
    position:relative;
    z-index:1;
}

table{
    width:100%;
    border-collapse:collapse;
    min-width:860px;
}

th, td{
    padding:14px 12px;
    border-bottom:1px solid rgba(255,255,255,0.06);
    text-align:left;
    vertical-align:middle;
}

th{
    color:var(--gold);
    font-size:12px;
    text-transform:uppercase;
    letter-spacing:1px;
    white-space:nowrap;
}

tr:hover td{
    background:rgba(200,155,60,0.04);
}

.tag{
    padding:5px 10px;
    border-radius:999px;
    font-size:10px;
    font-weight:800;
    background:rgba(200,155,60,0.10);
    color:var(--gold);
    border:1px solid rgba(200,155,60,0.45);
    display:inline-block;
    text-transform:uppercase;
    letter-spacing:0.8px;
}

.detalle-json{
    font-size:12px;
    color:#aaa;
    max-width:320px;
    overflow:hidden;
    word-break:break-word;
}

.btn-restaurar{
    background:linear-gradient(45deg, #1f9d49, #2ecc71);
    color:#fff;
    border:none;
    padding:10px 14px;
    border-radius:10px;
    cursor:pointer;
    transition:0.3s;
    font-weight:700;
}

.btn-restaurar:hover{
    transform:translateY(-2px);
    box-shadow:0 8px 20px rgba(46,204,113,0.30);
}

.btn-eliminar{
    background:linear-gradient(45deg, #a61d24, #dc3545);
    color:#fff;
    border:none;
    padding:10px 14px;
    border-radius:10px;
    cursor:pointer;
    transition:0.3s;
    font-weight:700;
}

.btn-eliminar:hover{
    transform:translateY(-2px);
    box-shadow:0 8px 20px rgba(220,53,69,0.35);
}

.acciones-celda form{
    display:inline-flex;
    gap:8px;
    align-items:center;
    flex-wrap:wrap;
    justify-content:flex-end;
}

.empty-row{
    text-align:center;
    color:var(--text-muted);
    padding:30px;
}

@media (max-width: 980px){
    body{ display:block; }

    .mobile-topbar{
        display:flex;
        align-items:center;
        justify-content:space-between;
        gap:12px;
        position:sticky;
        top:0;
        z-index:1100;
        padding:14px 16px;
        background:rgba(0,0,0,0.9);
        border-bottom:1px solid var(--glass-border);
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
        border:1px solid rgba(255,255,255,0.08);
        background:rgba(255,255,255,0.06);
        color:var(--gold);
        border-radius:12px;
        font-size:18px;
        cursor:pointer;
    }

    .sidebar-overlay{
        display:block;
        position:fixed;
        inset:0;
        background:rgba(0,0,0,0.45);
        opacity:0;
        visibility:hidden;
        transition:0.3s ease;
        z-index:999;
    }

    .sidebar{
        width:280px;
        max-width:82vw;
        transform:translateX(-100%);
        transition:transform 0.3s ease;
    }

    body.menu-open .sidebar{ transform:translateX(0); }
    body.menu-open .sidebar-overlay{
        opacity:1;
        visibility:visible;
    }

    .main{
        margin-left:0;
        width:100%;
        padding:20px 16px;
    }

    .glass-card{
        padding:18px;
        border-radius:18px;
    }

    .titulo{
        font-size:28px;
        margin-bottom:18px;
    }

    table{ min-width:760px; }
}

@media (max-width: 700px){
    .main{ padding:16px 12px; }

    .glass-card{
        padding:14px;
        border-radius:16px;
    }

    .titulo{ font-size:24px; }
    table{ min-width:680px; }
    .mensaje{ font-size:14px; }
}

@media (max-width: 520px){
    .main{ padding:14px 10px; }
    .glass-card{ padding:12px; }
    .mobile-topbar-title{ font-size:13px; }
    table{ min-width:620px; }
    .detalle-json{ max-width:220px; }
}
</style>

</head>

<body>

    <div class="mobile-topbar">
        <div class="mobile-topbar-left">
            <img src="<?php echo $logoActual; ?>" alt="Logo" class="mobile-topbar-logo">
            <div class="mobile-topbar-title">Papelera Studio</div>
        </div>
        <button class="mobile-menu-toggle" id="mobileMenuToggle" aria-label="Abrir menú">
            <i class="fas fa-bars"></i>
        </button>
    </div>

    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <div class="sidebar" id="sidebar">
        <img src="<?php echo $logoActual; ?>" alt="Logo" class="logo-pos">

        <div class="nav-controls">
            <a href="dashboard.php" title="Inicio"><i class="fas fa-home"></i></a>
            <a href="usuarios.php" title="Usuarios"><i class="fas fa-users"></i></a>
            <a href="papelera.php" class="active" title="Papelera"><i class="fas fa-trash-alt"></i></a>
            <a href="configuracion.php" title="Configuración"><i class="fas fa-cog"></i></a>
        </div>
    </div>

    <div class="main">
        <h1 class="titulo">Centro de <span>RECICLAJE</span></h1>

        <?php if($mensaje): ?>
            <div id="mensajeFlash" class="mensaje">
                <?php echo $mensaje; ?>
            </div>
        <?php endif; ?>

        <div class="glass-card">
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Origen</th>
                            <th>Detalle (JSON)</th>
                            <th>Fecha Eliminación</th>
                            <th style="text-align:right;">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if($items && $items->num_rows > 0): ?>
                            <?php while($f = $items->fetch_assoc()): ?>
                            <tr>
                                <td>
                                    <span class="tag">
                                        <?php echo strtoupper($f['modulo']); ?>
                                    </span>
                                </td>

                                <td class="detalle-json">
                                    <?php echo $f['datos_json']; ?>
                                </td>

                                <td>
                                    <?php echo $f['fecha_eliminacion']; ?>
                                </td>

                                <td style="text-align:right;" class="acciones-celda">
                                    <form method="POST">
                                        <input type="hidden" name="id_papelera" value="<?php echo $f['id']; ?>">

                                        <button type="submit" name="accion" value="restaurar" class="btn-restaurar">
                                            <i class="fas fa-undo"></i>
                                        </button>

                                        <button type="submit" name="accion" value="borrar_fin" class="btn-eliminar" onclick="return confirm('¿Borrar para siempre?')">
                                            <i class="fas fa-times"></i>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="4" class="empty-row">
                                    La papelera está vacía
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

<script>
document.addEventListener("DOMContentLoaded",function(){

    const msg=document.getElementById("mensajeFlash");

    if(msg){
        setTimeout(function(){
            msg.style.opacity="0";
            setTimeout(function(){
                msg.style.display="none";
            },400);
        },1500);
    }

    const body = document.body;
    const mobileMenuToggle = document.getElementById('mobileMenuToggle');
    const sidebarOverlay = document.getElementById('sidebarOverlay');

    function abrirMenu() {
        body.classList.add('menu-open');
    }

    function cerrarMenu() {
        body.classList.remove('menu-open');
    }

    if (mobileMenuToggle) {
        mobileMenuToggle.addEventListener('click', function () {
            if (body.classList.contains('menu-open')) {
                cerrarMenu();
            } else {
                abrirMenu();
            }
        });
    }

    if (sidebarOverlay) {
        sidebarOverlay.addEventListener('click', cerrarMenu);
    }

    document.querySelectorAll('.sidebar a').forEach(link => {
        link.addEventListener('click', function () {
            if (window.innerWidth <= 980) {
                cerrarMenu();
            }
        });
    });

    window.addEventListener('resize', function () {
        if (window.innerWidth > 980) {
            cerrarMenu();
        }
    });

});
</script>

</body>
</html>

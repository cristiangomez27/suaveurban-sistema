<?php
session_start();
if (!isset($_SESSION['usuario_id']) || $_SESSION['rol'] != 'admin') { 
    header("Location: index.php"); exit; 
}
require_once 'config/database.php';

// --- LOGO Y FONDO ---
$logoActual = 'logo.png'; 
$resConfig = $conn->query("SELECT logo FROM configuracion WHERE id = 1 LIMIT 1");
if ($resConfig && $resConfig->num_rows > 0) {
    $config = $resConfig->fetch_assoc();
    if (!empty($config['logo'])) $logoActual = $config['logo'];
}

$notificacion = "";

// --- 1. REGISTRO DE PROVEEDOR ---
if (isset($_POST['btn_proveedor'])) {
    $nombre = mysqli_real_escape_string($conn, $_POST['nombre']);
    $contacto = mysqli_real_escape_string($conn, $_POST['contacto']);
    $categoria = mysqli_real_escape_string($conn, $_POST['categoria']);
    $conn->query("INSERT INTO proveedores (nombre, contacto, categoria) VALUES ('$nombre', '$contacto', '$categoria')");
    $notificacion = "Proveedor registrado.";
}

// --- 2. REGISTRO DE FACTURA / GASTO ---
if (isset($_POST['btn_factura'])) {
    $id_prov = $_POST['id_proveedor'];
    $monto = $_POST['monto'];
    $desc = mysqli_real_escape_string($conn, $_POST['descripcion']);
    $fecha = $_POST['fecha'];
    $conn->query("INSERT INTO facturas_gastos (id_proveedor, monto, descripcion, fecha) VALUES ('$id_prov', '$monto', '$desc', '$fecha')");
    $notificacion = "Gasto ingresado y descontado de caja.";
}

// --- 3. CÁLCULOS MENSUALES ---
$mes_actual = date('m');
$anio_actual = date('Y');

$ingresos = $conn->query("SELECT SUM(total) as t FROM ventas WHERE MONTH(fecha) = '$mes_actual' AND YEAR(fecha) = '$anio_actual'")->fetch_assoc()['t'] ?? 0;
$gastos = $conn->query("SELECT SUM(monto) as t FROM facturas_gastos WHERE MONTH(fecha) = '$mes_actual' AND YEAR(fecha) = '$anio_actual'")->fetch_assoc()['t'] ?? 0;
$utilidad = $ingresos - $gastos;

$lista_prov = $conn->query("SELECT * FROM proveedores ORDER BY nombre ASC");
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Proveedores y Gastos - Suave Urban Studio</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root { --gold: #c89b3c; --bg: #050505; --glass: rgba(20, 20, 20, 0.85); --glass-border: rgba(200, 155, 60, 0.2); }
        body { background: var(--bg); color: white; font-family: 'Segoe UI', sans-serif; margin: 0; display: flex; height: 100vh; overflow: hidden; }
        
        /* SIDEBAR COMPACTO */
        .sidebar { width: 85px; background: rgba(0,0,0,0.9); border-right: 1px solid var(--glass-border); display: flex; flex-direction: column; align-items: center; padding: 20px 0; }
        .logo-pos { width: 50px; margin-bottom: 30px; animation: pulse 3s infinite; filter: drop-shadow(0 0 5px var(--gold)); }
        @keyframes pulse { 0% { transform: scale(1); } 50% { transform: scale(1.1); } 100% { transform: scale(1); } }
        .sidebar a { color: #555; font-size: 22px; margin-bottom: 25px; transition: 0.3s; }
        .sidebar a.active { color: var(--gold); }

        .main-content { flex: 1; padding: 40px; overflow-y: auto; background: linear-gradient(135deg, #050505 0%, #151515 100%); }
        
        /* RESUMEN FINANCIERO */
        .finance-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; margin-bottom: 30px; }
        .stat-card { background: var(--glass); border: 1px solid var(--glass-border); border-radius: 15px; padding: 20px; text-align: center; backdrop-filter: blur(10px); }
        .stat-card h2 { margin: 10px 0 0 0; font-size: 28px; }

        /* FORMULARIOS */
        .glass-card { background: var(--glass); border: 1px solid var(--glass-border); border-radius: 20px; padding: 25px; margin-bottom: 25px; backdrop-filter: blur(10px); }
        .form-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 15px; align-items: end; }
        
        input, select { background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1); color: white; padding: 12px; border-radius: 10px; width: 100%; box-sizing: border-box; outline: none; }
        input:focus { border-color: var(--gold); }
        
        .btn-gold { background: var(--gold); color: black; border: none; padding: 12px 20px; border-radius: 10px; font-weight: 800; cursor: pointer; text-transform: uppercase; transition: 0.3s; }
        .btn-gold:hover { transform: scale(1.05); box-shadow: 0 5px 15px rgba(200,155,60,0.3); }

        table { width: 100%; border-collapse: collapse; margin-top: 15px; }
        th { color: var(--gold); text-align: left; padding: 12px; border-bottom: 1px solid var(--glass-border); font-size: 12px; }
        td { padding: 12px; border-bottom: 1px solid rgba(255,255,255,0.05); font-size: 14px; }
    </style>
</head>
<body>

    <div class="sidebar">
        <img src="<?php echo $logoActual; ?>" class="logo-pos">
        <a href="dashboard.php"><i class="fas fa-home"></i></a>
        <a href="ventas.php"><i class="fas fa-cash-register"></i></a>
        <a href="clientes.php"><i class="fas fa-users"></i></a>
        <a href="proveedores.php" class="active"><i class="fas fa-truck-loading"></i></a>
        <a href="configuracion.php"><i class="fas fa-cog"></i></a>
    </div>

    <div class="main-content">
        <h1 style="font-weight: 200;">Panel de <span style="color:var(--gold); font-weight: 800;">FINANZAS</span></h1>

        <div class="finance-grid">
            <div class="stat-card">
                <span style="color: #25d366; font-size: 12px; font-weight: bold;">INGRESOS (VENTAS)</span>
                <h2 style="color: #25d366;">$<?php echo number_format($ingresos, 2); ?></h2>
            </div>
            <div class="stat-card">
                <span style="color: #ff4d4d; font-size: 12px; font-weight: bold;">EGRESOS (FACTURAS)</span>
                <h2 style="color: #ff4d4d;">$<?php echo number_format($gastos, 2); ?></h2>
            </div>
            <div class="stat-card" style="border: 1px solid var(--gold);">
                <span style="color: var(--gold); font-size: 12px; font-weight: bold;">UTILIDAD REAL NETA</span>
                <h2 style="text-shadow: 0 0 10px var(--gold);">$<?php echo number_format($utilidad, 2); ?></h2>
            </div>
        </div>

        <div class="glass-card">
            <h3 style="margin-top:0; color:var(--gold);"><i class="fas fa-file-invoice-dollar"></i> Ingresar Nueva Factura de Compra</h3>
            <form method="POST" class="form-row">
                <div>
                    <label style="font-size: 10px;">PROVEEDOR</label>
                    <select name="id_proveedor" required>
                        <?php while($p = $lista_prov->fetch_assoc()): ?>
                            <option value="<?php echo $p['id']; ?>"><?php echo $p['nombre']; ?></option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div>
                    <label style="font-size: 10px;">MONTO $</label>
                    <input type="number" step="0.01" name="monto" placeholder="0.00" required>
                </div>
                <div>
                    <label style="font-size: 10px;">DESCRIPCIÓN (Factura # o Concepto)</label>
                    <input type="text" name="descripcion" placeholder="Ej: Compra de 50 hoodies Yazbek" required>
                </div>
                <div>
                    <label style="font-size: 10px;">FECHA</label>
                    <input type="date" name="fecha" value="<?php echo date('Y-m-d'); ?>" required>
                </div>
                <button type="submit" name="btn_factura" class="btn-gold">Registrar Gasto</button>
            </form>
        </div>

        <div style="display: grid; grid-template-columns: 1fr 1.5fr; gap: 20px;">
            <div class="glass-card">
                <h3 style="margin-top:0; color:var(--gold);"><i class="fas fa-truck"></i> Nuevo Proveedor</h3>
                <form method="POST">
                    <input type="text" name="nombre" placeholder="Nombre de la empresa" style="margin-bottom: 10px;" required>
                    <input type="text" name="contacto" placeholder="WhatsApp / Teléfono" style="margin-bottom: 10px;">
                    <select name="categoria" style="margin-bottom: 15px;">
                        <option value="Insumos">Insumos (Tinta/Vinil)</option>
                        <option value="Textiles">Textiles (Playeras/Gorras)</option>
                        <option value="Servicios">Servicios (Luz/Renta)</option>
                    </select>
                    <button type="submit" name="btn_proveedor" class="btn-gold" style="width:100%;">Guardar Proveedor</button>
                </form>
            </div>

            <div class="glass-card">
                <h3 style="margin-top:0; color:var(--gold);"><i class="fas fa-history"></i> Últimos Gastos</h3>
                <table>
                    <thead>
                        <tr>
                            <th>Fecha</th>
                            <th>Proveedor</th>
                            <th>Concepto</th>
                            <th>Monto</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $ultimos = $conn->query("SELECT f.*, p.nombre FROM facturas_gastos f JOIN proveedores p ON f.id_proveedor = p.id ORDER BY f.id DESC LIMIT 5");
                        while($u = $ultimos->fetch_assoc()): ?>
                        <tr>
                            <td><?php echo $u['fecha']; ?></td>
                            <td><?php echo $u['nombre']; ?></td>
                            <td style="color:#888; font-size: 12px;"><?php echo $u['descripcion']; ?></td>
                            <td style="color:#ff4d4d; font-weight: bold;">-$<?php echo number_format($u['monto'], 2); ?></td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</body>
</html>
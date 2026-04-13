<?php
session_start();
if ($_SESSION['rol'] != 'admin') { die("Acceso denegado"); }
require_once 'config/database.php';

// --- LÓGICA DE EXPORTACIÓN A EXCEL (CSV) ---
if (isset($_GET['exportar'])) {
    $tipo = $_GET['exportar'];
    $filename = $tipo . "_" . date('Ymd') . ".csv";
    
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=' . $filename);
    $output = fopen('php://output', 'w');
    
    if ($tipo == 'ventas') {
        fputcsv($output, array('ID', 'Cliente', 'Total', 'Fecha', 'Metodo Pago'));
        $rows = $conn->query("SELECT v.id, c.nombre, v.total, v.fecha, v.metodo FROM ventas v JOIN clientes c ON v.id_cliente = c.id");
    } elseif ($tipo == 'inventario') {
        fputcsv($output, array('ID', 'Producto', 'Stock', 'Precio Venta', 'Costo Compra'));
        $rows = $conn->query("SELECT id, nombre, stock, precio, costo FROM productos");
    }
    
    while ($row = $rows->fetch_assoc()) fputcsv($output, $row);
    fclose($output);
    exit;
}

// --- DATOS PARA GRÁFICAS (GANANCIAS MENSUALES) ---
$grafica_datos = $conn->query("SELECT DATE_FORMAT(fecha, '%M') as mes, SUM(total) as total FROM ventas GROUP BY MONTH(fecha)");
$meses = []; $totales = [];
while($g = $grafica_datos->fetch_assoc()){
    $meses[] = $g['mes'];
    $totales[] = $g['total'];
}
?>

<div class="glass-card">
    <h2 style="color:var(--gold)"><i class="fas fa-database"></i> Centro de Respaldos</h2>
    <div class="grid-form">
        <a href="?exportar=ventas" class="btn-gold"><i class="fas fa-file-excel"></i> Exportar Ventas</a>
        <a href="?exportar=inventario" class="btn-gold"><i class="fas fa-tshirt"></i> Exportar Inventario</a>
        <a href="?exportar=clientes" class="btn-gold"><i class="fas fa-users"></i> Exportar Clientes</a>
    </div>
    
    <div style="margin-top:40px;">
        <canvas id="graficaVentas" style="max-height: 300px;"></canvas>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
const ctx = document.getElementById('graficaVentas').getContext('2d');
new Chart(ctx, {
    type: 'line',
    data: {
        labels: <?php echo json_encode($meses); ?>,
        datasets: [{
            label: 'Ventas Mensuales ($)',
            data: <?php echo json_encode($totales); ?>,
            borderColor: '#c89b3c',
            backgroundColor: 'rgba(200, 155, 60, 0.2)',
            fill: true,
            tension: 0.4
        }]
    },
    options: { plugins: { legend: { labels: { color: 'white' } } } }
});
</script>
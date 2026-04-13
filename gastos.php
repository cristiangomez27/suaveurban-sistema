// --- CALCULO DE GANANCIA REAL ---
$ingresos = $conn->query("SELECT SUM(total) as total FROM ventas WHERE MONTH(fecha) = MONTH(CURRENT_DATE)")->fetch_assoc()['total'];
$gastos = $conn->query("SELECT SUM(monto) as total FROM facturas_gastos WHERE MONTH(fecha) = MONTH(CURRENT_DATE)")->fetch_assoc()['total'];
$ganancia_neta = $ingresos - $gastos;
?>

<div class="glass-card" style="border-left: 5px solid var(--gold);">
    <h3>Resumen Mensual: <span style="color:var(--gold)"><?php echo date('F'); ?></span></h3>
    <div style="display:flex; justify-content: space-around; text-align:center;">
        <div><p>Ingresos</p><h2 style="color:#25d366">+$<?php echo number_format($ingresos, 2); ?></h2></div>
        <div><p>Gastos (Facturas)</p><h2 style="color:#ff4d4d">-$<?php echo number_format($gastos, 2); ?></h2></div>
        <hr>
        <div><p>Utilidad Real</p><h2 style="text-shadow: 0 0 10px var(--gold)">$<?php echo number_format($ganancia_neta, 2); ?></h2></div>
    </div>
</div>
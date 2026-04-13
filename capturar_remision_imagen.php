<?php
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    die("Venta no válida");
}
$id = intval($_GET['id']);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Captura remisión</title>
<script src="https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js"></script>
<style>
body{margin:0;background:#111;color:#fff;font-family:Arial,sans-serif;text-align:center;padding:20px}
iframe{width:1100px;height:1700px;border:0;background:#fff}
#status{margin-bottom:15px;font-size:16px}
</style>
</head>
<body>
<div id="status">Generando imagen de remisión...</div>
<iframe id="frame" src="remision_imagen.php?id=<?php echo $id; ?>"></iframe>

<script>
const frame = document.getElementById('frame');
const status = document.getElementById('status');

frame.onload = async function () {
    try {
        const frameDoc = frame.contentDocument || frame.contentWindow.document;
        const canvasElement = frameDoc.querySelector('.canvas');
        if (!canvasElement) {
            status.textContent = 'No se encontró la remisión para capturar.';
            return;
        }

        const canvas = await html2canvas(canvasElement, {
            scale: 2,
            useCORS: true,
            backgroundColor: '#ffffff'
        });

        const dataUrl = canvas.toDataURL('image/png');

        const formData = new FormData();
        formData.append('id', '<?php echo $id; ?>');
        formData.append('imagen', dataUrl);

        const res = await fetch('guardar_remision_imagen.php', {
            method: 'POST',
            body: formData
        });

        const json = await res.json();

        if (json.status === 'success') {
            status.innerHTML = 'Imagen generada correctamente.<br><a href="' + json.url + '" target="_blank" style="color:#7CFF8A">Abrir imagen</a>';
        } else {
            status.textContent = 'Error: ' + json.mensaje;
        }
    } catch (e) {
        status.textContent = 'Error generando imagen: ' + e.message;
    }
};
</script>
</body>
</html>
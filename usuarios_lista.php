<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['usuario_id'])) {
    exit;
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    require_once __DIR__ . '/config/database.php';
}

function usuariosListaEscape($valor): string {
    return htmlspecialchars((string)$valor, ENT_QUOTES, 'UTF-8');
}

$usuarioSesionLista = (int)($_SESSION['usuario_id'] ?? 0);
$rolSesionLista = '';

$camposRol = ['rol', 'usuario_rol', 'tipo_usuario', 'cargo', 'perfil', 'puesto'];
foreach ($camposRol as $campoRol) {
    if (!empty($_SESSION[$campoRol])) {
        $rolSesionLista = strtolower(trim((string)$_SESSION[$campoRol]));
        break;
    }
}

if ($usuarioSesionLista > 0) {
    $resRolLista = $conn->query("SELECT rol FROM usuarios WHERE id = {$usuarioSesionLista} LIMIT 1");
    if ($resRolLista && $filaRolLista = $resRolLista->fetch_assoc()) {
        if (!empty($filaRolLista['rol'])) {
            $rolSesionLista = strtolower(trim((string)$filaRolLista['rol']));
        }
    }
}

$esAdminLista = ($rolSesionLista === 'admin');

$usuarios = [];
$resLista = $conn->query("SELECT id, nombre, usuario, correo, rol, estado, creado_en FROM usuarios ORDER BY id DESC");
if ($resLista) {
    while ($fila = $resLista->fetch_assoc()) {
        $usuarios[] = $fila;
    }
}
?>

<div class="tabla-wrap">
    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>Nombre</th>
                <th>Usuario</th>
                <th>Correo</th>
                <th>Rol</th>
                <th>Estado</th>
                <th>Fecha</th>
                <th>Acciones</th>
            </tr>
        </thead>
        <tbody>
            <?php if (!empty($usuarios)): ?>
                <?php foreach ($usuarios as $u): ?>
                    <tr>
                        <td><?php echo (int)($u['id'] ?? 0); ?></td>
                        <td><?php echo usuariosListaEscape($u['nombre'] ?? ''); ?></td>
                        <td><?php echo usuariosListaEscape($u['usuario'] ?? ''); ?></td>
                        <td><?php echo usuariosListaEscape($u['correo'] ?? ''); ?></td>
                        <td><?php echo usuariosListaEscape(ucfirst($u['rol'] ?? '')); ?></td>
                        <td>
                            <span class="estado <?php echo usuariosListaEscape($u['estado'] ?? ''); ?>">
                                <?php echo usuariosListaEscape($u['estado'] ?? ''); ?>
                            </span>
                        </td>
                        <td><?php echo usuariosListaEscape($u['creado_en'] ?? ''); ?></td>
                        <td>
                            <?php if ($esAdminLista): ?>
                                <div class="acciones-flex">
                                    <a href="usuarios.php?editar=<?php echo (int)($u['id'] ?? 0); ?>" class="btn-sec">Editar</a>

                                    <?php if ((int)($u['id'] ?? 0) !== $usuarioSesionLista): ?>
                                        <form method="POST" action="usuarios.php" onsubmit="return confirm('¿Enviar este usuario a papelera?');">
                                            <input type="hidden" name="accion" value="eliminar_usuario">
                                            <input type="hidden" name="usuario_id" value="<?php echo (int)($u['id'] ?? 0); ?>">
                                            <button type="submit" class="btn-sec btn-delete">Eliminar</button>
                                        </form>
                                    <?php else: ?>
                                        <span style="color:#666;">Sesión actual</span>
                                    <?php endif; ?>
                                </div>
                            <?php else: ?>
                                <span style="color:#666;">Sin permisos</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="8" style="text-align:center; color:#777; padding:30px;">No hay usuarios registrados todavía.</td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>
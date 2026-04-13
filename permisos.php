<?php
function usuarioTieneAcceso($moduloPermitido = []) {
    if (!isset($_SESSION['rol'])) {
        return false;
    }

    $rol = $_SESSION['rol'];

    $permisos = [
        'administrador_general' => [
            'dashboard',
            'ventas',
            'clientes',
            'remisiones',
            'imprimir_remision',
            'pedidos',
            'productos',
            'proveedores',
            'configuracion',
            'usuarios',
            'papelera',
            'taller',
            'entregas'
        ],
        'ejecutivo_mostrador' => [
            'ventas',
            'clientes',
            'remisiones',
            'imprimir_remision'
        ],
        'coordinador_produccion' => [
            'pedidos',
            'taller'
        ]
    ];

    if (!isset($permisos[$rol])) {
        return false;
    }

    foreach ($moduloPermitido as $modulo) {
        if (in_array($modulo, $permisos[$rol], true)) {
            return true;
        }
    }

    return false;
}

function esAdministradorGeneral() {
    return isset($_SESSION['rol']) && $_SESSION['rol'] === 'administrador_general';
}
?>
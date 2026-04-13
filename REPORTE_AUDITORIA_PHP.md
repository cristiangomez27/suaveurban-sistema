# Reporte de auditoría PHP del sistema

Fecha: 2026-04-13 (UTC)

## Alcance y método
- Archivos PHP analizados: 45 (revisión estática).
- Comandos ejecutados: `php -l` por archivo, inspección de includes/requires y revisión de consultas SQL.

## Resumen ejecutivo
- Sintaxis: **45/45 archivos sin errores de sintaxis**.
- Riesgo más crítico: **dependencias rotas** (`config/database.php`, `conexion.php`, `config/mail.php`, `helpers/mail.php`) que pueden causar error 500 inmediato.
- Seguridad: se detectaron consultas con interpolación directa (ej. `proveedores.php`, `usuarios.php`, `usuarios_lista.php`).
- Confiabilidad: hay rutas de ejecución con variables potencialmente no definidas (`respaldos.php` con `$rows`).
- Exposición de secretos: credenciales/tokens en texto plano (`database.php`, `secure_greenapi.php`).

## Revisión archivo por archivo
| Archivo | Sintaxis | Hallazgos principales |
|---|---|---|
| `actualizar_estatus_pedido.php` | OK | ❗ Depende de config/database.php (no existe en repo). |
| `api_whatsapp_cliente.php` | OK | ❗ require_once conexion.php (archivo no existe). Provoca fatal error. |
| `capturar_remision_imagen.php` | OK | ✅ Sin hallazgos críticos estáticos. |
| `clientes.php` | OK | ❗ Requiere config/database.php y config/functions.php (no existen). |
| `configuracion.php` | OK | ❗ Requiere config/database.php (no existe). |
| `dashboard.php` | OK | ❗ Requiere config/database.php (no existe). |
| `database.php` | OK | ❗ Credenciales hardcodeadas + require ../private/secure_greenapi.php (ruta probablemente inexistente). |
| `enviar_promocion.php` | OK | ❗ Requiere config/database.php y busca helpers/greenapi_helper.php (ruta no existe). |
| `facturas_proveedor.php` | OK | ❗ Requiere config/database.php (no existe). |
| `functions.php` | OK | ⚠️ Usa SQL dinámico (SHOW TABLES/COLUMNS) con escape; riesgo bajo pero mejor whitelist. |
| `gastos.php` | OK | ⚠️ Consultas sin validación de errores ($conn->query()->fetch_assoc() encadenado). |
| `generar_remision_imagen.php` | OK | ❗ Requiere config/database.php (no existe). |
| `greenapi_helper.php` | OK | ⚠️ Busca credenciales en rutas externas; si faltan devuelve error controlado. |
| `guardar.php` | OK | ⚠️ Guarda archivos con @mkdir y permisos 0777; puede fallar por permisos y es inseguro. |
| `guardar_password.php` | OK | ❗ Requiere config/database.php (no existe). |
| `guardar_venta.php` | OK | ❗ Requiere config/database.php (no existe). |
| `imprimir_remision.php` | OK | ❗ Requiere config/database.php (no existe). |
| `index.php` | OK | ❗ Requiere config/database.php (no existe). |
| `logout.php` | OK | ✅ Sin hallazgos críticos estáticos. |
| `mail.php` | OK | ❗ Carga ../config/mail.php (no existe). |
| `mensajes_cargar.php` | OK | ❗ Requiere config/database.php (no existe). |
| `mensajes_enviar.php` | OK | ❗ Requiere config/database.php (no existe). |
| `mensajes_estado.php` | OK | ❗ Requiere config/database.php (no existe). |
| `papelera.php` | OK | ❗ Requiere config/database.php (no existe). |
| `pedidos.php` | OK | ❗ Requiere config/database.php (no existe). |
| `pedidos_entregados.php` | OK | ❗ Requiere config/database.php (no existe). |
| `permisos.php` | OK | ✅ Sin hallazgos críticos estáticos. |
| `produccion.php` | OK | ❗ Requiere config/database.php (no existe). |
| `produccion_estado.php` | OK | ❗ Requiere config/database.php (no existe). |
| `productos.php` | OK | ❗ Requiere config/database.php y config/functions.php (no existen). |
| `promociones_whatsapp.php` | OK | ❗ Requiere config/database.php (no existe). |
| `proveedores.php` | OK | ❗ SQL con interpolación directa en INSERT/SELECT (riesgo de SQL injection). |
| `proveedores_modulo_nuevo.php` | OK | ❗ Requiere config/database.php (no existe). |
| `recuperar_password.php` | OK | ❗ Requiere config/database.php, config/mail.php y helpers/mail.php (faltantes). |
| `remision_imagen.php` | OK | ❗ Requiere config/database.php (no existe). |
| `reportes.php` | OK | ❗ Requiere config/database.php (no existe). |
| `reset_password.php` | OK | ❗ Requiere config/database.php (no existe). |
| `respaldos.php` | OK | ❗ Caso exportar=clientes deja $rows indefinida; posible warning/fatal (error 500). |
| `secure_greenapi.php` | OK | ❗ Contiene tokens/credenciales en texto plano. |
| `ticket_venta.php` | OK | ❗ Requiere config/database.php (no existe). |
| `usuarios.php` | OK | ❗ Requiere config/database.php (no existe) y tiene consultas interpoladas con IDs. |
| `usuarios_lista.php` | OK | ❗ Requiere config/database.php (no existe) y usa query interpolada con id sesión. |
| `ventas.php` | OK | ❗ Requiere config/database.php (no existe). |
| `verificar_remision.php` | OK | ❗ Requiere config/database.php (no existe). |
| `websocket_notify.php` | OK | ✅ Sin hallazgos críticos estáticos. |

## Revisión específica del flujo: Cliente → Venta (`guardar_venta.php`) → Remisión

### Resultado
**El flujo está bien planteado funcionalmente, pero NO está “cerrado” al 100%** por dependencias y puntos de fallo de infraestructura/rutas.

### Flujo validado
1. `ventas.php` prepara cliente y carrito para enviar JSON al backend de guardado.  
2. `guardar_venta.php` valida sesión, crea venta en `ventas`, detalle en `ventas_detalle`, y pedido en `pedidos` dentro de transacción.  
3. Después devuelve `ticket_url` y `remision_url` para impresión/consulta.  
4. `verificar_remision.php` consulta `ventas` + `ventas_detalle` y renderiza la remisión al cliente.

### Hallazgos puntuales en este flujo
- **Bloqueante**: `guardar_venta.php`, `ventas.php` y `verificar_remision.php` dependen de `config/database.php`; si no existe, todo el flujo cae con fatal error (500).  
- **Riesgo al guardar diseño/remisión**: `guardar_venta.php` y `guardar.php` crean carpetas con `0777` y suprimen errores con `@mkdir`; en hosting con permisos rígidos puede fallar el guardado de archivos.  
- **Riesgo de consistencia de tipos**: en `guardar_venta.php` algunos `NULL` se convierten a `0` en binds dinámicos (por ejemplo, IDs opcionales), lo que puede enlazar cliente/pedido incorrectamente si la BD está en modo estricto o si se esperaba `NULL`.  
- **Riesgo operativo**: el envío de WhatsApp/remisión depende de cURL + Green API + URL pública accesible; la venta sí se guarda, pero la remisión puede no enviarse y quedar como warning.

### Veredicto del flujo hoy
- **Guardado en BD**: razonablemente robusto en la parte transaccional.
- **Conexión entre archivos**: incompleta por rutas `config/*` faltantes.
- **Entrega de remisión**: funcional pero sensible a infraestructura/permisos.

## Errores y correcciones recomendadas
1. **Dependencias rotas (Error 500 inmediato)**  
   - Problema: gran parte del sistema hace `require_once 'config/database.php'`, pero ese archivo no existe en el repositorio actual.  
   - Corrección: crear `config/database.php` como wrapper de `../database.php`, o actualizar todos los `require_once` a `require_once __DIR__ . '/database.php';`.
2. **Rutas de helpers/config inexistentes**  
   - Problema: `api_whatsapp_cliente.php` requiere `conexion.php`; `recuperar_password.php`/`mail.php` requieren rutas `config/mail.php` y `helpers/mail.php` no presentes.  
   - Corrección: unificar estructura real (`mail.php`, `greenapi_helper.php`, `database.php`) y usar rutas absolutas con `__DIR__`.
3. **SQL injection por interpolación**  
   - Problema: en `proveedores.php`, `usuarios.php`, `usuarios_lista.php` existen queries con variables embebidas en SQL.  
   - Corrección: migrar a `prepare()` + `bind_param()` en todos los `INSERT/UPDATE/SELECT` con entrada de usuario/sesión.
4. **Variables potencialmente no definidas**  
   - Problema: en `respaldos.php`, si `exportar=clientes`, no se asigna `$rows` antes de iterar.  
   - Corrección: agregar rama `elseif ($tipo == 'clientes')` o `else` con validación antes de `while`.
5. **Errores por retorno no validado en DB**  
   - Problema: patrones como `$conn->query(...)->fetch_assoc()` sin verificar `false` pueden romper ejecución.  
   - Corrección: validar resultado (`if ($res)`) y registrar `$conn->error`.
6. **Credenciales sensibles en repositorio**  
   - Problema: usuario/contraseña MySQL y tokens Green API están en código fuente.  
   - Corrección: mover a variables de entorno (`$_ENV`) + archivo `.env` fuera de control de versiones.
7. **Guardado de archivos y permisos inseguros**  
   - Problema: `guardar.php` crea directorios con `0777` y usa `@mkdir` ocultando errores.  
   - Corrección: usar permisos mínimos (`0755`/`0750`), manejar errores explícitos y validar tamaño MIME real.

## Archivos con mayor probabilidad de fallo al guardar datos
- `guardar_venta.php` (depende de conexión ausente).
- `proveedores.php` (inserciones con SQL interpolado y sin manejo robusto de errores).
- `usuarios.php` (operaciones CRUD con consultas mezcladas, parte sin prepared statements).
- `recuperar_password.php` (depende de módulos mail no presentes).
- `guardar.php` (escritura de archivos vulnerable a permisos/ruta).

## Conclusión
El sistema no presenta errores de sintaxis, pero sí **riesgos altos de ejecución (500)** por includes inexistentes y problemas de arquitectura de rutas. Antes de producción, prioriza: (1) normalizar estructura `config/`, (2) migrar SQL a prepared statements, (3) externalizar secretos, (4) endurecer escritura de archivos.

# REPORTE_REORGANIZACION.md

## 1) Análisis general de la estructura actual
Se detectó que el sistema mantiene la mayoría de módulos PHP en la raíz del proyecto y depende fuertemente de `require_once` relativos.

Para mantener compatibilidad con el sistema actual (sin romper URLs ni módulos visibles), se priorizó:
- unificación de includes con rutas absolutas basadas en `__DIR__`,
- consolidación de archivos de configuración en `config/`,
- consolidación de helpers en `helpers/`,
- wrappers de compatibilidad para dependencias históricas.

---

## 2) Duplicados, dependencias rotas e includes incorrectos

### Dependencias rotas / includes inconsistentes detectados y corregidos
- Uso extendido de `require_once 'config/database.php'` relativo al CWD (frágil dependiendo del punto de ejecución).
- Uso extendido de `require_once 'config/functions.php'` y `require_once 'config/mail.php'` con el mismo problema.
- Uso de `require_once 'helpers/mail.php'` relativo al CWD.
- Ruta incorrecta en `mail.php` que apuntaba a `../config/mail.php`.

### Archivos potencialmente duplicados o funcionalmente solapados (NO eliminados)
- `proveedores.php` vs `proveedores_modulo_nuevo.php`.
- `remision_imagen.php` vs `generar_remision_imagen.php` (flujos relacionados de remisión).

> No se eliminó ningún archivo duplicado/obsoleto automáticamente. Se deja para revisión manual funcional.

---

## 3) Estructura final propuesta de carpetas

### Estructura aplicada (sin romper compatibilidad)
- `config/`
  - `database.php`
  - `functions.php`
  - `mail.php`
- `helpers/`
  - `mail.php`
  - `greenapi_helper.php`
- Raíz del proyecto
  - módulos PHP existentes (se conservan por compatibilidad de rutas/URLs)
  - `conexion.php` (wrapper de compatibilidad)

### Estructura recomendada a futuro (no aplicada para evitar romper producción)
- `modules/` para páginas de negocio (`ventas`, `clientes`, `proveedores`, etc.).
- `assets/` para recursos estáticos no PHP.
- Mantener en raíz solo front-controller y bootstrap.

---

## 4) Cambios aplicados

### Qué moví
- **No se movieron archivos de módulos** para evitar ruptura de enlaces/URLs existentes.

### Qué creé
- `config/database.php` (wrapper central).
- `config/functions.php` (wrapper central).
- `config/mail.php` (config SMTP por entorno).
- `helpers/mail.php` (wrapper helper).
- `helpers/greenapi_helper.php` (wrapper helper).
- `conexion.php` (wrapper de compatibilidad para endpoints que lo requieren).

### Qué eliminé
- **No se eliminó ningún archivo** en esta reorganización.

### Qué rutas corregí
- Se estandarizó include/require en módulos a formato absoluto con `__DIR__`:
  - `require_once __DIR__ . '/config/database.php';`
  - `require_once __DIR__ . '/config/functions.php';`
  - `require_once __DIR__ . '/config/mail.php';`
  - `require_once __DIR__ . '/helpers/mail.php';`
- Corrección puntual en `mail.php` para cargar `config/mail.php` desde la ruta real del proyecto.

---

## 5) Archivos que requieren revisión manual
1. `proveedores.php` y `proveedores_modulo_nuevo.php`  
   Revisar cuál será el módulo oficial para evitar lógica duplicada.

2. `remision_imagen.php` y `generar_remision_imagen.php`  
   Revisar y definir claramente responsabilidades de cada flujo para evitar duplicidad.

3. `database.php` / `secure_greenapi.php`  
   Confirmar variables de entorno reales en servidor (`DB_*`, `APP_URL`, `GREENAPI_*`) antes de despliegue.

4. Flujo de autenticación/roles en páginas legacy  
   Aún hay módulos con validaciones mínimas de sesión que conviene normalizar en un middleware común.

---

## 6) Resultado
La base del repositorio quedó más consistente en rutas de include/require sin cambiar diseño visual ni nombres de módulos visibles. Se mantuvo compatibilidad PHP/MySQL y se evitó eliminar archivos de riesgo sin validación funcional previa.

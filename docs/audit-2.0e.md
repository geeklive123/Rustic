# Auditoría técnica 2.0E

## Superficie y permisos

Las rutas privadas usan `auth:sanctum`, token personal de Sanctum y comprobación de usuario activo y rol. Los permisos se calculan en servidor. `logout` revoca el token actual; cambiar contraseña, rol o actividad revoca los tokens del usuario. No hay registro público.

| Operación | Administrador | Vendedor | Cocina |
|---|---|---|---|
| Usuarios y cambios de catálogo | Sí | No | No |
| Ver catálogo | Sí, con precios | Sí, con precios | Sí, sin precios |
| Crear pedidos | No | Sí | No |
| Ver pedidos | Todos, financieros | Solo propios | Todos, sin importes |
| Cola de cocina | Sí | No | Sí |
| Preparar / listo | Sí | No | Sí |
| Entregar | Sí | Solo propios y listos | No |
| Reportes | Sí | No | No |
| Caja | Todos los turnos; puede abrir el propio | Solo turno propio | No |

Los UUID de pedidos se filtran por vendedor para detalle, estado y sincronización. La actualización de cocina comprueba permiso, dueño cuando corresponde, estado esperado e idempotencia dentro de una transacción. Caja serializa cierre y pagos explícitos por turno y no vincula pagos históricos automáticamente. Los Resources de cocina usan listas de campos permitidos; las respuestas financieras exigen permisos específicos.

## Hallazgos y medidas

* Una respuesta 500 generada directamente podía contener datos internos y los detalles de una excepción también podían propagarse. La API normaliza respuestas 5xx y solo conserva detalles de validación en 422. `SecurityAuditTest` prueba ambos casos.
* No existía un archivo CORS explícito. Ahora se deniega por defecto y solo se permiten orígenes indicados en `CORS_ALLOWED_ORIGINS`; no se permiten credenciales de navegador. Android nativo no requiere CORS.
* El login conserva 5 intentos por minuto; las rutas autenticadas tienen un máximo general de 120 por minuto y reportes/caja conservan su límite de 60. Sus contadores están separados; se comprobó con pruebas. Para varios procesos se necesita un almacén de caché compartido y configuración de proxy/IP correcta.
* `.env.*` queda excluido por `.gitignore`, excepto ejemplos. Los respaldos actuales están en `storage/app/private`, fuera de `public/` e ignorados por el proyecto. No se inspeccionaron ni copiaron secretos del `.env` real en los entregables.
* Sanctum no tiene caducidad global configurada; los tokens persisten hasta su revocación. Definir una política de vida útil y recuperación antes del servidor compartido, considerando clientes offline. El cierre de sesión offline no puede revocar el token remoto.
* No se detectó registro explícito de contraseñas o tokens en código de aplicación. Los logs de excepciones pueden contener contexto operativo o datos personales; deben restringirse y rotarse. El saneamiento de la respuesta HTTP no sustituye esa protección.
* El proxy y TLS no se habilitan automáticamente: deben configurarse y verificarse en infraestructura. `TRUSTED_PROXIES` está vacío por defecto y rechaza comodines; se deben configurar solo IP/CIDR bajo control. Confiar indiscriminadamente en cabeceras `X-Forwarded-*` permitiría suplantar origen/esquema.
* La cola de cocina incremental es una vista de estado con solapamiento, no un registro de entrega garantizada de cada evento. La auditoría persistente registra transiciones; el cliente debe deduplicar y consultar según el contrato.

## Contrato Android y caja

Los importes y números centrales son enteros; el identificador y número local no son el número comercial confirmado. El `operation_id` y cuerpo del pedido deben permanecer estables ante timeout; 409 requiere resolución explícita. El QR simulado no representa dinero bancario. Las fechas se guardan en UTC y los límites de reportes usan `America/La_Paz`.

Android 2.1A no envía `cash_shift_id`: su venta continúa aceptándose, pero su pago no se imputa a ningún turno. Para una futura versión, abrir turno con UUID y operación estables, consultar `cash-shifts/current`, conservar el ID y enviar `cash_shift_id` únicamente con `CASH`. El servidor comprueba propiedad y estado abierto en la transacción; rechaza un turno ajeno, cerrado o un pago incompatible. El cliente debe conservar el mismo ID en un reintento y resolver 409 sin volver a cobrar. No se han reasignado pagos históricos.

## Ensayo y límites

Se verificó instalación de dependencias bloqueadas sin red, sin scripts y sin paquetes de desarrollo en una copia desechable sin `.env` real. En este equipo la generación de autoload optimizado se interrumpió por demora; el ensayo se completó con autoload no optimizado. PHP 8.4 y requisitos de plataforma Composer pasaron; `pdo_mysql` y `pdo_sqlite` están disponibles. No hay tareas scheduler definidas.

Con `APP_ENV=staging`, `APP_DEBUG=false` y SQLite desechable se aplicaron nueve migraciones desde cero, `config:cache` y `route:cache`. HTTP en loopback verificó health 200, ruta privada 401 y error 500 sin el marcador interno. Se creó un respaldo SQLite y se restauró en otra SQLite desechable, verificando nueve migraciones y un registro ficticio. Esto no prueba una restauración MariaDB ni TLS/proxy reales; deben ensayarse en el servidor de pruebas antes de usar datos necesarios.

Los escenarios MariaDB usan únicamente `rustik_pos_concurrency_test`, con guarda de entorno y nombre efectivo. Las pruebas incluyen dos vendedores, UUID duplicado, reintentos y pago único, cocina frente a entrega, cierres simultáneos, venta frente a cierre, rollback y reportes tras pedidos idempotentes. Sus datos ficticios permanecen en esa base de prueba.

La colección importable `rustik-api-v1.postman_collection.json` cubre las 30 rutas registradas; los valores de credenciales son variables vacías. La ejecución de las rutas con autenticación requiere cuentas ficticias en un entorno aislado. Las instrucciones y checklist para infraestructura están en `staging.md`.

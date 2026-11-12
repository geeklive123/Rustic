# RUSTIK POS ? API v1 (Sprint 2.0A)

Base local: `http://127.0.0.1:8001/api/v1`. Enviar `Accept: application/json` y, para cuerpos JSON, `Content-Type: application/json`. En rutas privadas enviar `Authorization: Bearer <access_token>`. En Android almacenar el token en almacenamiento seguro; se muestra solamente al crear la sesi?n. Usar HTTPS fuera del entorno local.

## Respuestas

?xito: `{"data": ...}`; listas de usuarios incluyen tambi?n `meta` de paginaci?n. Error: `{"error":{"message":"...","details":{"campo":["..."]}}}`; `details` solo aparece en errores de validaci?n. Los c?digos relevantes son 200, 201, 401 (sin token v?lido o usuario inactivo), 403 (sin permiso), 404, 422 (validaci?n o credenciales inv?lidas) y 429 (l?mite de login). El l?mite de login es 5 intentos por minuto por IP. No hay registro p?blico.

## Rutas

| M?todo y ruta | Acceso | Cuerpo / resultado |
|---|---|---|
| `GET /health` | P?blico | `{"data":{"status":"ok"}}`; comprueba la aplicaci?n, no la base de datos. |
| `POST /login` | P?blico | `email`, `password`, `device_name` obligatorios. Devuelve `data.token_type`, `data.access_token`, `data.user`. |
| `POST /logout` | Token | Revoca solo el token utilizado. |
| `GET /me` | Token | Usuario y permisos actuales. |
| `GET /users?page=1` | `users.manage` | Usuarios paginados de 25 en 25. |
| `GET /users/{id}` | `users.manage` | Usuario. |
| `POST /users` | `users.manage` | `name`, `email`, `password` (m?nimo 12 caracteres), `role`; `is_active` opcional, por defecto true. |
| `PATCH /users/{id}` | `users.manage` | Cualquiera de `name`, `email`, `password`, `role`, `is_active`. |

Roles v?lidos: `administrador`, `vendedor`, `cocina`. Los objetos de usuario contienen `id`, `name`, `email`, `role`, `is_active`, `permissions`, `created_at`; nunca incluyen contrase?a. El administrador no puede desactivarse ni quitarse su propio rol. Cambiar contrase?a, rol o estado revoca todos los tokens del usuario. Desactivar una cuenta impide usar incluso un token preexistente. El campo `role` puede ser null ?nicamente para cuentas anteriores a la migraci?n, que quedan inactivas por defecto; el administrador debe asignarles un rol y activarlas. No se elimina informaci?n existente.

Ejemplo de login:

```json
{"email":"usuario@example.com","password":"contrase?a del usuario","device_name":"Android caja 1"}
```

## Matriz de permisos del servidor

| Permiso | Administrador | Vendedor | Cocina |
|---|:---:|:---:|:---:|
| `users.manage`, `menu.manage`, `settings.manage`, `reports.view` | Si | No | No |
| `orders.create` | No | Si | Si |
| `payments.create`, `orders.deliver` | Si | Si | No |
| `cash.manage` | Si | No | No |
| `cash.view`, `catalog.view`, `orders.view`, `preparation.view` | Si | Si | Si |
| `orders.prepare`, `orders.ready`, `kitchen.view` | Si | No | Si |
| `orders.receive`, `tickets.view` | No | No | Si |

Los permisos se comprueban en el servidor; la lista enviada al cliente sirve para presentar la interfaz.

## Puesta en marcha local

En PowerShell con PHP 8.4 y Composer de Herd en PATH:

```powershell
Set-Location C:\Dev\FoodTruckPOS-API
php artisan config:clear
php artisan migrate:status
php artisan migrate
php artisan route:list --path=api/v1
php artisan test
php artisan serve --port=8001
```

Antes de migrar, comprobar que `.env` apunta a `rustik_pos_dev`. `migrate` solo aplica migraciones pendientes; no usar `migrate:fresh` en desarrollo. El comando de primer administrador solicita y confirma la contrase?a sin mostrarla ni aceptarla como argumento; se niega si ya existe un administrador. Usa el bloqueo del almac?n de cach? configurado, que debe admitir bloqueos compartidos entre procesos. Los administradores posteriores se crean desde la API. Si un administrador ya existe pero est? inactivo, el comando sigue neg?ndose; resolver la recuperaci?n mediante un procedimiento administrativo controlado.

Las pruebas exigen `APP_ENV=testing` y SQLite `:memory:`; se detienen antes de migrar si la configuraci?n efectiva no coincide. No se debe usar configuraci?n cacheada de desarrollo para pruebas. Habilitar `pdo_sqlite` y `sqlite3` en PHP de Herd. Para verificar MariaDB sin `db:show`, ejecutar `SELECT VERSION(), DATABASE()` con el cliente SQL y `php artisan migrate:status`.


# Sprint 2.0B: catalogo y pedidos

Todas las rutas requieren `Authorization: Bearer <token>`. Las respuestas siguen el formato `data` / `error` anterior. `401` indica token ausente, invalido o revocado; `403` falta de permiso; `404` recurso no visible; `422` datos invalidos; `409` conflicto de precio, UUID o idempotencia; `500` error inesperado. Los mensajes no autorizan al cliente a reintentar una venta con una nueva clave.

## Catalogo

`GET /catalog` devuelve `data.categories`, `data.products`, `data.extras` y `data.options`. `GET /categories`, `/extras` y `/options` devuelven cada lista. Solo se muestran registros activos; se excluyen productos de categorias inactivas y extras inactivos. Los precios son enteros `price_cents` (100 centavos = Bs 1). Cocina recibe el mismo catalogo operativo sin precios.

Administracion: `POST /admin/catalog/{type}` y `PATCH /admin/catalog/{type}/{id}` requieren `menu.manage`; `type` es `categories`, `products`, `extras` u `options`. Nombre es obligatorio al crear, `is_active` es opcional. Productos requieren `category_id` y `price_cents`, y aceptan `extra_ids` para reemplazar sus extras permitidos; extras requieren `price_cents`. Una lista vacia elimina asociaciones, no elimina extras ni historial. `GET /catalog?include_inactive=1` permite a administradores consultar tambien los registros inactivos. Las opciones son gratuitas y no aceptan precio.

El seeder solo se ejecuta explicitamente en `local`, no crea cuentas y no reemplaza precios existentes:

```powershell
php artisan db:seed --class=RustikCatalogSeeder
```

La API nativa no necesita CORS. Por defecto no se autorizan origenes web; `CORS_ALLOWED_ORIGINS` acepta una lista explicita separada por comas para clientes web aprobados, sin cookies. El acceso autenticado tiene un limite general de 120 solicitudes por minuto por usuario; caja y reportes conservan ademas su limite independiente de 60. Login mantiene 5 intentos por minuto por IP. Las respuestas 5xx de la API no incluyen detalles internos.

## Pedidos

`POST /orders` requiere vendedor y recibe:

```json
{
  "uuid": "4a376b0b-4677-4a98-9bb4-7ae26a1777b0",
  "operation_id": "3a4341b9-1418-4744-b9f2-c9bb83f47420",
  "device_id": "android-caja-1",
  "customer_name": "Cliente",
  "notes": "Para llevar",
  "payment_method": "CASH",
  "expected_total_cents": 5400,
  "items": [{
    "product_id": 1, "quantity": 2, "expected_unit_price_cents": 2500,
    "extras": [{"extra_id": 1, "expected_unit_price_cents": 200}],
    "option_ids": [1], "notes": "Bien cocido"
  }]
}
```

IDs de catalogo deben venir del servidor y los del ejemplo son ilustrativos. `uuid` identifica el pedido; `operation_id` identifica globalmente el intento logico y `device_id` identifica al dispositivo. Guardar los tres junto con el cuerpo completo en la cola offline antes de enviar. Reintentar con los mismos valores y contenido. Las claves de objeto pueden cambiar de orden, pero el orden de las listas y los tipos son parte del contenido. `201` crea; `200` devuelve la misma venta previamente registrada. Cualquier cambio de contenido o usuario con la misma operacion, o un UUID ya asignado a otra operacion, devuelve `409`. Ambos identificadores estan protegidos por restricciones unicas. El numero comercial es el ID incremental central, unico globalmente, puede tener huecos y no se asigna en el dispositivo. Un identificador provisional de Android no es un numero comercial confirmado.

Precio y total son calculados por servidor. Se exige precio esperado de cada producto y extra y total esperado; si cualquiera difiere, se devuelve `409` y no se registra pedido ni pago. El cliente debe reconciliar y solicitar una decision explicita antes de crear una nueva operacion. Si ya se cobro offline, conservar la evidencia y resolver la diferencia manualmente; el servidor no modifica ni registra silenciosamente ese cobro. Las ventas aceptadas conservan nombres y precios historicos.

`payment_method` es `CASH`, `QR_SIMULATED` o `UNPAID`. CASH genera un registro `cash_collected`; QR_SIMULATED genera `simulated` y no representa un pago bancario; UNPAID no genera registro de pago. Ninguna integracion bancaria ni reporte de ingresos se implementa aqui.

`GET /orders` pagina en bloques de 25 (`?page=1`, `meta`); `GET /orders/{uuid}` devuelve detalle y `GET /orders/{uuid}/sync-status` confirma `synced` y numero central. Vendedor solo ve sus pedidos; administrador y cocina ven todos. Cocina recibe campos operativos, sin totales, precios ni pagos, incluso dentro de las lineas y extras. El estado de cocina inicial es `NEW`; el de pago es independiente. Las transiciones de cocina se describen mas abajo. No se implementan cobros posteriores.

## Verificacion de concurrencia en MariaDB aislada

La prueba opcional `tests/Concurrency/run.php` utiliza dos procesos para reintento simultaneo y para dos dispositivos. Requiere una base vacia y exclusiva llamada `rustik_pos_concurrency_test` en `127.0.0.1`. No utiliza `rustik_pos_dev` y verifica el nombre efectivo antes de escribir. No crea ni borra la base; deja datos temporales de prueba en ella. Crear la base por separado, confirmar que es descartable y ejecutar con PHP 8.4 de Herd y `pdo_mysql`:

```powershell
php artisan config:clear
$env:APP_ENV = 'testing'
$env:DB_CONNECTION = 'mysql'
$env:DB_HOST = '127.0.0.1'
$env:DB_DATABASE = 'rustik_pos_concurrency_test'
$env:DB_URL = ''
php tests/Concurrency/check.php
# Solo si check.php confirma la base exclusiva:
php artisan migrate --force
php tests/Concurrency/run.php
Remove-Item Env:APP_ENV,Env:DB_CONNECTION,Env:DB_HOST,Env:DB_DATABASE,Env:DB_URL
```

Las variables de usuario y contrasena de MariaDB se pueden especificar en el entorno local. Cerrar la sesion de PowerShell tras la prueba si se han definido otras variables DB. No usar una base que contenga datos necesarios.

## Cocina y estados (Sprint 2.0C)

Todos los endpoints siguientes requieren token Sanctum activo. Cocina y administrador pueden consultar la cola; el vendedor puede consultar el estado de sus propios pedidos. Las respuestas de cocina contienen unicamente datos de preparacion: numero, cliente, fechas, observaciones, productos, cantidades, extras y opciones. No contienen precios ni pagos.

* `GET /api/v1/kitchen/orders`: filtros opcionales `status` (`NEW`, `PREPARING`, `READY`, `DELIVERED`), `updated_since` (ISO 8601 con zona), `timezone` (IANA; por defecto `America/La_Paz`), `per_page` (1–100), `after_id` y `snapshot_until`.
* `GET /api/v1/kitchen/orders/{uuid}`: detalle de preparacion.
* `PATCH /api/v1/kitchen/orders/{uuid}/status`: JSON con `expected_status`, `status` y `operation_id` (UUID obligatorio). La cocina o el administrador llevan `NEW` a `PREPARING` y `PREPARING` a `READY`, y pueden reabrir `READY` a `PREPARING` con `orders.prepare`; el vendedor propietario o administrador llevan `READY` a `DELIVERED`.
* `GET /api/v1/orders/{uuid}/status`: estado de preparacion, sin informacion financiera.

La transicion devuelve `200` con `replayed: false`; repetir exactamente la misma operacion por el mismo usuario devuelve `200` y `replayed: true` sin un evento adicional. Una operacion reutilizada con otros datos o un estado anterior desactualizado devuelve `409`. Una transicion no permitida, estado final repetido con nueva operacion o dato invalido devuelve `422`; sin token devuelve `401`, sin permiso `403` y un pedido inexistente o ajeno al vendedor `404`. El reintento devuelve el estado actual del pedido, que podria haber avanzado desde la operacion original.

Ejemplo de reapertura: `{"expected_status":"READY","status":"PREPARING","operation_id":"4a376b0b-4677-4a98-9bb4-7ae26a1777b0"}`. Esta accion no cancela ni modifica la venta o el pago. Al reabrir, `preparing_at` se actualiza a la fecha UTC de la nueva preparacion y `ready_at` se limpia; los eventos conservan las fechas anteriores. Un pedido `DELIVERED` no se puede reabrir y su `delivered_at` no se modifica.

Cada transicion exitosa guarda usuario, estados, pedido, operacion y fecha UTC en `order_status_events`, en la misma transaccion que el pedido. Los tiempos de preparacion, listo y entrega se guardan en UTC; `created_at_display` usa la zona solicitada. Los pedidos antiguos `pending` se convierten a `NEW` sin crear eventos historicos.

Para recorrer la cola incrementalmente, conservar `snapshot_until` y los filtros de la primera pagina; pedir las siguientes con `after_id=next_after_id`, el mismo `snapshot_until` y `page=1` hasta que `next_after_id` sea `null`. En el siguiente ciclo usar `updated_since` inclusivo, con solapamiento, y deduplicar por UUID; consultar sin filtro de estado para detectar pedidos que salieron de un estado. La paginacion no es una instantanea transaccional entre solicitudes y timestamps tienen precision de segundos: el solapamiento debe cubrir la duracion maxima de transacciones y consultas. No se garantiza entrega de cada evento individual mediante esta cola; el historial de auditoria registra las transiciones. La integracion entre dispositivos Android queda pendiente de pruebas posteriores.

Para las carreras de cocina en la base aislada documentada arriba, despues de verificarla y aplicar sus migraciones, ejecutar `php tests/Concurrency/kitchen-run.php`. El script deja datos ficticios en esa base.

## Reportes y caja (Sprint 2.0D)

La politica actual de turnos compartidos y sus contratos estan en [caja web](caja-web-turnos.md); reemplazan las instrucciones de caja historicas de esta seccion.

Todas estas rutas requieren Sanctum y verificacion de permiso en servidor. `reports.view` corresponde al administrador; `cash.manage` corresponde al administrador. Cocina puede consultar la caja compartida actual y sus propias ventas, pero no administrar turnos, consultar reportes ni confirmar pagos. Solo el administrador puede abrir, listar y cerrar turnos.

* `GET /api/v1/reports/summary`, `/reports/sales`, `/reports/products`, `/reports/payments`: requieren `reports.view`. Aceptan `period=today|yesterday|week|month|custom` (por defecto `today`); para `custom`, `start_date` y `end_date` son fechas inclusivas `YYYY-MM-DD`. Los listados aceptan `page` y `per_page` de 1 a 100. Los limites usan `America/La_Paz` y consultas UTC con fin exclusivo.
* `POST /api/v1/cash-shifts/open`: requiere `cash.manage`. Cuerpo: `{"uuid":"4a376b0b-4677-4a98-9bb4-7ae26a1777b0","operation_id":"3a4341b9-1418-4744-b9f2-c9bb83f47420","opening_cents":1000}`. Devuelve `201`, o `200` con `replayed: true` al repetir los mismos datos por el mismo usuario.
* `GET /api/v1/cash-shifts/current` requiere `cash.view` y devuelve el turno compartido abierto o `data: null`. `GET /api/v1/cash-shifts` lista historial con paginacion para administradores.
* `POST /api/v1/cash-shifts/{id}/close`: cuerpo `{"operation_id":"fd92052f-431f-48c1-9203-f13b016294fb","counted_cents":6200}`. Reintentar con el mismo usuario, operacion e importe devuelve `200` y `replayed: true`; otra operacion sobre turno cerrado devuelve `409`.

En un pedido `CASH`, `cash_shift_id` es un campo entero opcional que vincula el pedido al turno compartido abierto, para todos los metodos de pago. La operacion de pedido queda ligada a ese campo para la idempotencia. Si se omite, el pedido se registra normalmente pero no se atribuye a ninguna caja. Esto conserva compatibilidad con Android 2.1A, cuyo contrato no envia turno, y evita asignar erroneamente ventas offline o historicas. Un turno inexistente o cerrado devuelve `409`. El cierre serializa las ventas asociadas y congela efectivo, esperado, contado y diferencia en centavos.

Ver [reportes](reports.md) y [caja](cash.md) para reglas contables y pruebas. No se han implementado anulaciones, devoluciones, costos ni ganancia; QR simulado no es dinero bancario ni efectivo.

Las consultas `GET /orders`, `GET /orders/{uuid}`, `GET /orders/{uuid}/status` y `GET /orders/{uuid}/sync-status` se limitan a ventas propias para vendedor y cocina; el administrador puede consultar todas. La cola `/kitchen/orders` sigue mostrando los datos de preparaci?n de todos los pedidos sin importes. Cocina puede crear QR est?tico pendiente, pero no tiene `payments.create`: solo un administrador puede confirmar un QR creado por cocina.

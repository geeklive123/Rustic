# Auditor?a preproducci?n ? 12 de noviembre de 2026

**Veredicto: NO APTO PARA PRODUCCI?N.** Se desconoce el estado real de SiteGround, no se ha probado ninguna APK instalada contra un staging equivalente y las APK cuyo contrato se inspeccion? no env?an caja. Su efectivo no entra al arqueo. El bloqueo persiste hasta reconciliar estos puntos, verificar la configuraci?n y ejecutar el plan en un entorno equivalente.

## Alcance y versiones

- GitHub `main` consultado por API: `b45e322234773182a2e1ffef0837e5c0a87ffafc`, igual a `origin/main` local. La rama integrada est? en `3afd83a`, con cambios web sin commit. El backend original local est? en `e196952`, integrado como ancestro. Android no tiene historial Git y se inspeccion? el c?digo local: esto no identifica las APK instaladas.
- SiteGround no fue inspeccionado. Sus ajustes manuales en `/reportes`, `routes/web.php`, middleware, configuraci?n y comportamiento sin APP_KEY no se conocen; el diff de GitHub no es un inventario de producci?n.
- El estudio de APK es de contrato fuente y pruebas de servidor, no una ejecuci?n de APK f?sica ni una prueba contra el servidor de SiteGround.

## Base local, lectura solamente

`rustik_pos_dev`: 112 pedidos, 112 pagos (71 CASH/cash_collected, 30 QR_SIMULATED/simulated, 11 QR_STATIC/qr_verified); 112 en estado READY; 112 sin `orders.cash_shift_id`; sin cajas; un usuario activo de Cocina y uno de Vendedor, ning?n administrador activo. El conteo y los roles describen la copia, no necesariamente producci?n. No se encontr? pedido sin pago, pago hu?rfano, vendedor hu?rfano, referencia de turno hu?rfana ni pago duplicado por pedido en las consultas realizadas. Esto no demuestra la integridad de todas las tablas o de producci?n. Las 12 migraciones figuran aplicadas en la copia. No se modific? esa base.

Los 112 pedidos READY merecen validaci?n operativa con las pesta?as de Cocina: podr?an aparecer como listos seg?n los filtros de la APK; no se cambi? su estado ni se comprob? la UI instalada.

## Pruebas y l?mites

- PHPUnit en SQLite en memoria: **68 aprobadas, 2 omitidas, 991 aserciones**. Incluye reportes, autenticaci?n, permisos, Cocina, pagos, turnos e idempotencia. Dos pruebas de creaci?n del primer administrador se omiten por decisi?n previa del proyecto.
- Pint `--test`: aprobado en la comprobaci?n final.
- Concurrencia real MariaDB en `127.0.0.1/rustik_pos_concurrency_test`, base local dedicada ya existente: pasaron UUID y replay, dos dispositivos, una apertura global, cierres concurrentes, reintento de venta, venta contra cierre, transiciones y reapertura de Cocina, e informes API. Se a?adieron datos ficticios; no se borr? la base. Un resultado de carrera concreto no prueba todos los intercalados posibles.
- Ensayo de migraciones MariaDB en base nueva `rustik_audit_ff3f6f8fb435`: se aplicaron las diez migraciones de `main`, se insertaron tres ventas y pagos ficticios (CASH, QR_SIMULATED, QR_STATIC) y una caja abierta, y luego las dos migraciones nuevas. Comparaci?n de los campos previos de orders y payments sin cambios; los pedidos permanecieron sin vincular y la caja abierta qued? activa. Es una reconstrucci?n del esquema GitHub, no una copia verificada del esquema anterior real de SiteGround; no se usaron datos reales ni se demuestra rendimiento o equivalencia con su motor. La base de ensayo queda conservada para inspecci?n.
- No se ejecutaron pruebas en producci?n, APK f?sicas, navegador contra SiteGround, ni restauraci?n real de respaldo durante esta auditor?a.

## Compatibilidad y riesgos

La APK inspeccionada env?a login, cat?logo, ?rdenes, historial, sync-status, estado, cola/polling y confirmaci?n QR seg?n las rutas API actuales. Las pruebas del servidor cubren esos contratos, incluyendo los tres pagos sin `cash_shift_id` con caja abierta, reintentos y n?mero estable. No se ha verificado la versi?n o configuraci?n real de cada APK. Con `CASH_SHIFT_REQUIRED=false` se aceptan pedidos nuevos sin turno, sin asignaci?n autom?tica por fecha. **Los totales y efectivo esperado de caja son incompletos si existen esas ventas**; se agreg? una advertencia local y se requiere conciliaci?n externa acordada antes de usar el cierre. No habilitar obligatoriedad hasta actualizar y validar todos los dispositivos, tratamiento offline y pendientes, y acordar el manejo de ventas que llegan despu?s del cierre.

El backend mantiene `seller_id`, n?mero por ID y aislamiento de historial por vendedor. La cola usa paginaci?n incremental y la suite cubre las transiciones NEW ? PREPARING ? READY ? DELIVERED y reapertura READY ? PREPARING. Esta reapertura y la autorizaci?n de ventas de Cocina son cambios respecto a main. El rol Cocina ahora recibe importes en sus propios recursos de ?rdenes para vender, aunque el recurso de cola de cocina conserva la separaci?n. Revisar expresamente este cambio de acceso financiero y los permisos operativos antes de aprobarlo.

QR_SIMULATED permanece simulado; QR_STATIC queda pendiente hasta confirmaci?n autorizada. La confirmaci?n posterior al cierre actualiza el pedido, no la instant?nea de cierre. Las pruebas cubren reintentos y un pago por pedido; verificar este proceso con clientes y datos de prueba en staging. Para cajas antiguas sin instant?nea, el resumen hist?rico no se reconstruye. Pagos antiguos vinculados solo en `payments.cash_shift_id` no aparecen en el nuevo listado de pedidos de caja.

La nueva web administrativa exige cuenta activa `cash.manage`, CSRF, APP_KEY v?lida, HTTPS y sesiones seguras en producci?n; ante configuraci?n insuficiente bloquea `/admin`. La copia local no tiene administrador activo y no se cre? ninguno. APP_KEY ausente en SiteGround y las adaptaciones manuales requieren an?lisis antes de tocar claves o `routes/web.php`; cambiar una clave existente puede invalidar cookies y datos cifrados. Los tokens Sanctum son hashes, pero debe verificarse cualquier dependencia adicional. No usar `key:generate` ni los scripts de setup autom?ticamente. Ver [caja-web-despliegue.md](caja-web-despliegue.md).

## Migraciones y despliegue propuesto

1. Inventariar c?digo y configuraci?n reales, versiones PHP/extensiones, MySQL/MariaDB, esquema, migraciones registradas, n?mero de cajas abiertas, dependencias de APP_KEY y sesiones. Comparar manualmente con GitHub y la rama local, preservando los ajustes de reportes. Asegurar usuario administrativo existente, sin compartir credenciales.
2. Preparar respaldos consistentes y privados de c?digo, configuraci?n y base; ensayar su restauraci?n en un entorno aislado. Definir ventana, responsable, supervisi?n, reversi?n y gesti?n de ventas offline. El inventario siguiente es respecto de GitHub, no una lista autorizada de sobrescritura en SiteGround.
3. Reproducir el esquema real anterior y datos anonimizados representativos en staging aislado de la misma versi?n de motor. En particular, si hay m?s de una caja abierta, la migraci?n `2026_11_06_090000_add_shared_cash_shift` falla antes de agregar el ?ndice; se requiere conciliaci?n operativa. Luego aplicar `2026_11_11_000000_add_shift_order_and_closing_snapshot`. MySQL DDL puede quedar parcialmente aplicado ante error; inspeccionar esquema y `migrations`, no repetir a ciegas.
4. Validar en staging HTTPS, proxy confiable, cookies, CSRF, claves, `/reportes`, todos los m?todos de pago, confirmaci?n QR, pedidos previos, APK instaladas, Cocina y sincronizaci?n offline. Mantener `CASH_SHIFT_REQUIRED=false`. No exponer caja financiera sin autorizaci?n administrativa.
5. Solo tras aprobaci?n y respaldo probado, realizar el despliegue con el procedimiento acordado; contrastar archivos reales antes de cualquier reemplazo. Probar login, cat?logo, venta ficticia sin recobro, QR, consulta de estados, Cocina, reportes y controles de caja; observar errores, sincronizaci?n y conciliaci?n. No se ejecut? despliegue en esta auditor?a.
6. Para revertir, restaurar c?digo/configuraci?n verificados compatibles con el esquema aditivo y mantener las columnas nuevas; las migraciones financieras deliberadamente no implementan rollback. No ejecutar `migrate:rollback`, `migrate:fresh` ni restaurar una base antigua sobre ventas nuevas. Si hiciera falta recuperar datos, detener escrituras, preservar transacciones posteriores y ejecutar un plan de recuperaci?n conciliado y ensayado.

## Diagn?stico de SiteGround: solo lectura, a ejecutar por el operador

En el directorio real de la aplicaci?n, sin copiar `.env` ni publicar su contenido:

```sh
pwd
php -v
sha256sum routes/web.php routes/api.php bootstrap/app.php app/Http/Controllers/DailyReportController.php app/Http/Middleware/RestrictReportNetwork.php resources/views/reports/daily.blade.php 2>/dev/null
ls -l routes/web.php routes/api.php bootstrap/app.php
ls -1 database/migrations
```

Revisar localmente el contenido de `routes/web.php` y de los archivos de reportes en una copia protegida; comparar hashes y diferencias sin mostrar claves, tokens ni credenciales. Determinar de forma segura la presencia y validez de APP_KEY, configuraci?n efectiva de proxy/sesi?n/cach? y diferencias operativas sin imprimir secretos. Con un cliente SQL y credenciales gestionadas interactivamente, usar solo consultas de lectura como las siguientes; confirmar primero la base seleccionada. No ejecutar migraciones:

```sql
SELECT DATABASE(), VERSION();
SELECT migration, batch FROM migrations ORDER BY id;
SELECT COUNT(*) AS pedidos FROM orders;
SELECT method, status, COUNT(*) AS cantidad FROM payments GROUP BY method, status;
SELECT kitchen_status, COUNT(*) AS cantidad FROM orders GROUP BY kitchen_status;
SELECT COUNT(*) AS cajas_abiertas FROM cash_shifts WHERE closed_at IS NULL;
SHOW CREATE TABLE orders;
SHOW CREATE TABLE payments;
SHOW CREATE TABLE cash_shifts;
```

Las ?ltimas consultas requieren que las tablas existan; no modificar su esquema si fallan. Las salidas pueden revelar estructura sensible y deben tratarse como privadas.

## Archivos versionados que difieren de GitHub main

- `.env.example`
- `app/Enums/KitchenStatus.php`
- `app/Enums/Role.php`
- `app/Http/Controllers/Api/CashShiftController.php`
- `app/Http/Controllers/Api/KitchenController.php`
- `app/Http/Controllers/Api/OrderController.php`
- `app/Http/Controllers/DailyReportController.php`
- `app/Http/Middleware/RestrictReportNetwork.php`
- `app/Http/Requests/CloseCashShiftRequest.php`
- `app/Http/Requests/OpenCashShiftRequest.php`
- `app/Http/Requests/StoreOrderRequest.php`
- `app/Http/Resources/CashShiftResource.php`
- `app/Http/Resources/OrderResource.php`
- `app/Models/CashShift.php`
- `app/Services/CashShiftService.php`
- `app/Services/KitchenService.php`
- `app/Services/ManualQrPaymentService.php`
- `app/Services/OrderService.php`
- `app/Services/ReportService.php`
- `config/cash.php`
- `config/reports.php`
- `database/migrations/2026_11_06_090000_add_shared_cash_shift.php`
- `database/migrations/2026_11_11_000000_add_shift_order_and_closing_snapshot.php`
- `docs/api-v1.md`
- `docs/caja-compartida.md`
- `docs/caja-web-turnos.md`
- `docs/cocina.md`
- `docs/reportes-web.md`
- `resources/views/reports/daily.blade.php`
- `routes/api.php`
- `routes/web.php`
- `tests/Concurrency/cash-run.php`
- `tests/Concurrency/cash-worker.php`
- `tests/Concurrency/kitchen-run.php`
- `tests/Feature/ApiSprintTest.php`
- `tests/Feature/CashShiftWebTest.php`
- `tests/Feature/DailyWebReportTest.php`
- `tests/Feature/KitchenSalesTest.php`
- `tests/Feature/KitchenTest.php`
- `tests/Feature/ManualQrPaymentTest.php`
- `tests/Feature/OrderTest.php`
- `tests/Feature/ReportsCashTest.php`
- `tests/Feature/SecurityAuditTest.php`
- `tests/Feature/SharedCashShiftTest.php`
- `tests/Unit/KitchenStatusTest.php`

Adem?s hay cambios locales sin commit en `bootstrap/app.php`, `routes/web.php`, `app/Http/Controllers/Admin/`, `app/Http/Middleware/SecureAdminConfiguration.php`, `resources/views/admin/`, `tests/Feature/AdminCashWebTest.php`, `tests/Feature/AndroidContractTest.php` y `docs/caja-web-despliegue.md`. Este informe tambi?n es nuevo. El conjunto final a desplegar debe elaborarse despu?s de comparar con SiteGround, sus dependencias y configuraci?n.

# RUSTIK POS — transferencia de contexto

Actualizado: 2026-11-06, hora local America/La_Paz. Este documento no contiene credenciales ni autoriza despliegues.

## Objetivo y proyectos

Sistema POS de comida rápida con Android offline-first y backend central Laravel. Backend: `C:\Dev\FoodTruckPOS-API`. Android: `C:\Dev\FoodTruckPOS`, proyecto independiente; no modificar sin instrucción. Android gestiona catálogo, carrito, cobro, ventas históricas Room, comandas PNG y sincronización inicial con Laravel. La integración inicial se validó con datos aislados; no afirmar integración física entre dos teléfonos. El módulo remoto completo de cocina Android queda para trabajo posterior.

Backend: Laravel 13.40.0, Sanctum 4.3.3, PHP 8.4.24 de Herd (`C:\Users\Capibara Solution\.config\herd\bin\php84\php.exe`), MariaDB local 10.4.32. `composer.json` admite PHP ^8.3 pero la consigna del proyecto es utilizar 8.4. El código se organiza en `app/Enums`, `Models`, `Services`, controladores, Form Requests, Resources, middleware y `routes/api.php`; hay 30 rutas API v1. No reinstalar PHP portable solo para simular SiteGround.

## Datos y migraciones

`rustik_pos_dev` es la base local de desarrollo. `rustik_pos_concurrency_test` es la única base MariaDB autorizada para pruebas destructivas/concurrentes; los scripts verifican host, entorno y nombre antes de escribir y dejan datos ficticios. PHPUnit fuerza SQLite en memoria y no usa la base de desarrollo. No ejecutar `migrate:fresh`, `db:wipe` ni operaciones destructivas sobre desarrollo. No crear usuarios reales ni ejecutar el comando de primer administrador sin nueva instrucción explícita.

Tablas de negocio: usuarios y tokens Sanctum; categorías, productos, extras, opciones y relaciones; pedidos, líneas, extras/opciones de línea y pagos; eventos de estado de cocina; turnos de caja. También existen tablas Laravel de migraciones, caché y trabajos. Los importes son centavos enteros y los precios de pedidos son instantáneas históricas. Fechas UTC; reportes usan límites de `America/La_Paz`.

Las nueve migraciones aparecen aplicadas en desarrollo al 2026-11-06:

1. `0001_01_01_000000_create_users_table`.
2. `0001_01_01_000001_create_cache_table`.
3. `0001_01_01_000002_create_jobs_table`.
4. `2026_11_06_035747_create_personal_access_tokens_table`.
5. `2026_11_06_040000_add_role_and_active_to_users_table`.
6. `2026_11_06_050000_create_catalog_tables`.
7. `2026_11_06_050100_create_orders_tables`.
8. `2026_11_06_060000_add_kitchen_workflow`.
9. `2026_11_06_070000_create_cash_shifts`.

## Reglas y seguridad

Roles del servidor: `administrador`, `vendedor`, `cocina` (`Role::Administrator`, `Seller`, `Kitchen`). Administrador gestiona usuarios/catálogo y reportes; vendedor crea ventas, maneja su caja y entrega sus pedidos listos; cocina consulta y prepara pedidos sin importes ni acceso financiero. Las comprobaciones se realizan en servidor. Sanctum usa bearer; logout revoca el token, desactivación y cambios sensibles revocan acceso. Tokens sin caducidad global: acordar política antes de staging. Login limitado a 5/minuto, API autenticada 120/minuto y caja/reportes 60/minuto con contadores separados. CORS cerrado por defecto; `TRUSTED_PROXIES` solo IP/CIDR explícitos. No registrar credenciales ni tokens.

Catálogo local: Salchiburguer Bs 25, Rustik Clásica Bs 20, La Huérfana Bs 15, Hamburguesa Aniversario Bs 30, Costillitas BBQ Bs 30, Combo Especial Pareja Bs 40 y Salchipapa Bs 20. Extras: queso Bs 2, huevo Bs 2, tocino Bs 3; opciones gratuitas con llajua, sin pimentón, sin mostaza. `RustikCatalogSeeder` es explícito, idempotente y solo local; no sustituye precios existentes. No ejecutarlo en producción.

Pedidos: UUID y `operation_id` estables; reintento con mismo cuerpo 200, creación 201, conflicto 409; número comercial asignado por servidor. No generar un nuevo cobro al reintentar. Estados de cocina NEW → PREPARING → READY → DELIVERED, con control de rol, estado anterior, transacción e historial de cambios. Pago, cocina y sincronización son independientes. `CASH` es efectivo, `QR_SIMULATED` no es pago bancario, `UNPAID` sin pago. Caja abre/cierra turnos con idempotencia y bloqueos; pagos solo se asocian al turno explícito del vendedor. Reportes suman una sola vez y no inventan ganancias.

Android 2.1A todavía no envía `cash_shift_id`: los pagos de esa versión no se vinculan automáticamente a caja. Las ventas locales antiguas y los documentos conservan sus importes y números. Ante timeout se reusa UUID/cuerpo/operación; 409 exige resolución, sin recobro. El backend usa fecha de recepción, no la fecha histórica offline. Consultar `docs/android-laravel-contract-2.1a.md` y `docs/api-v1.md`.

## Pruebas y resultados

Al 2026-11-06, ejecutados en esta sesión: PHPUnit **46 aprobadas, 0 fallidas, 2 omitidas, 559 aserciones**; Pint aprobado; 30 rutas; nueve migraciones aplicadas. Las dos omitidas prueban la creación del primer administrador, excluida por decisión del proyecto. Concurrencia MariaDB dedicada aprobada: UUID duplicado, pago único por reintento, dos vendedores, carreras de caja y cocina, auditoría/rollback y reportes. Los scripts conservan datos ficticios en la base dedicada. En sesión anterior del 2026-11-06 Newman 6.2.1 ejecutó 35 solicitudes y 72 aserciones en SQLite desechable; no se repitió en la sesión de cierre.

```powershell
$php = 'C:\Users\Capibara Solution\.config\herd\bin\php84\php.exe'
& $php artisan test
& $php vendor\bin\pint --test
& $php artisan route:list --path=api/v1
& $php artisan migrate:status
# Solo base dedicada; verificar antes de ejecutar los scripts que escriben:
$env:APP_ENV='testing'
$env:DB_CONNECTION='mysql'
$env:DB_HOST='127.0.0.1'
$env:DB_DATABASE='rustik_pos_concurrency_test'
$env:DB_URL=''
$env:DB_SOCKET=''
& $php tests\Concurrency\check.php
& $php tests\Concurrency\cash-run.php
& $php tests\Concurrency\report-check.php
& $php tests\Concurrency\run.php
& $php tests\Concurrency\kitchen-run.php
```

Ejecutar la concurrencia en un proceso PowerShell separado para que sus variables no alteren comandos de desarrollo. Comprobar los mensajes de resultado, no solo el código de salida. En una sesión donde el sandbox no inicie PHP, usar exclusivamente el mecanismo de autorización disponible; no bajar a PHP 8.2 ni desactivar seguridad.

## Staging, recuperación y próximos pasos

Consultar `docs/staging.md`, `docs/staging-2.0f.md`, `docs/siteground-staging.md`, `docs/siteground-http-checklist.md`, `docs/staging.env.example`, `docs/audit-2.0e.md`, `docs/cash.md`, `docs/reports.md` y la colección Postman `docs/rustik-api-v1.postman_collection.json`.

Ensayo anterior: instalación limpia con dependencias bloqueadas, sin dev y autoload optimizado; nueve migraciones en SQLite; HTTP local con datos ficticios. Restauración MariaDB local comprobada en base desechable: respaldo anterior a caja con ocho migraciones, no un respaldo actualizado. La colección pasó Newman en entorno desechable. No hay validación de MySQL del servidor SiteGround, HTTPS/proxy, document root o integración con Android físico. CLI PHP 8.4 independiente de Herd tampoco se ha comprobado.

Antes de solicitar despliegue: confirmar sitio independiente y aislamiento de otros proyectos, document root en `public/`, PHP web/CLI 8.4 y extensiones, Composer, MySQL exacto y nueve migraciones/concurrencia en base desechable equivalente; preparar HTTPS, credenciales y APP_KEY exclusivas, respaldos/restauración y rollback, y ejecutar el checklist HTTP con usuarios ficticios. SiteGround Ultrafast puede compartir PHP/variables entre subdominios: no alterar otros sitios. No desplegar automáticamente.

## Git y entrega

Remoto previsto: `https://github.com/geeklive123/Rustic.git`, rama `main`. La API pública de GitHub confirmó el 2026-11-06 que el repositorio es público. La publicación queda pendiente salvo autorización explícita para publicar código comercial en un repositorio público o que la visibilidad sea privada y se verifique de nuevo. No cambiar visibilidad automáticamente ni hacer push forzado. Antes de publicar, comprobar el remoto, su historial, visibilidad, índice y secretos; no sobrescribir trabajo remoto. Consultar `git status`, `git log -1 --oneline` y `git remote -v` para conocer el estado real de esta copia.

Restricciones vigentes: no modificar Android, no crear usuarios reales, no desplegar ni iniciar otro sprint, no usar bases ajenas, no eliminar datos históricos ni compartir secretos. El directorio `android-integration-work` y respaldos locales son ajenos al commit y deben permanecer excluidos.

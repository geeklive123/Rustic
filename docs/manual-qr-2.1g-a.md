# QR est?tico con verificaci?n manual ? Sprint 2.1G-A

El cliente presenta su propio QR est?tico fuera de esta API. No hay consulta bancaria ni confirmaci?n autom?tica: el usuario autorizado comprueba el abono por un medio independiente antes de confirmar. No se guarda imagen ni dato bancario.

## Contrato

`POST /api/v1/orders` conserva su contrato; enviar `payment_method: "QR_STATIC"` crea un pago por el total calculado, con `payment.status` y `payment_status` iguales a `qr_pending`. La creaci?n no acredita el pago. Los m?todos `CASH`, `QR_SIMULATED` y `UNPAID` permanecen iguales. Un QR no acepta `cash_shift_id`.

`POST /api/v1/orders/{uuid}/qr-confirmation` requiere bearer Sanctum y permiso `payments.create`: vendedor para sus propios pedidos, administrador para cualquier pedido. Cocina no tiene acceso. Ejemplo:

```json
{"operation_id":"7e785a56-f4aa-4b1d-84b0-2baa2cd74429","amount_cents":5400,"reference":"Comprobante 123"}
```

`operation_id` es UUID ?nico de la confirmaci?n (distinto del identificador de creaci?n del pedido), `amount_cents` entero obligatorio igual al total completo y `reference` opcional, texto de hasta 255 caracteres. La primera confirmaci?n responde 201 con `replayed: false` y el pedido en `data`; `payment` contiene `method: QR_STATIC`, `status: qr_verified`, `amount_cents`, `confirmed_by`, `confirmed_at` ISO 8601 UTC, `reference` y `operation_id`. Un reintento con mismo pedido, actor, operaci?n, importe y referencia responde 200 y `replayed: true`. Si cambia cualquier dato, si el importe no coincide, si el pedido no est? pendiente de QR est?tico o si se reutiliza la operaci?n en otro pago responde 409; datos inv?lidos 422, sin autenticaci?n 401, sin permiso 403, pedido inaccesible 404. La referencia omitida y null son equivalentes. El servidor asigna actor y fecha; no acepta confirmaciones bancarias autom?ticas. No hay pagos parciales, reversi?n ni correcci?n de confirmaci?n.

## Reportes y caja

Los reportes siguen agrupando por fecha de creaci?n del pedido en horario America/La_Paz, no por fecha de confirmaci?n: confirmar m?s tarde cambia retrospectivamente el estado del per?odo del pedido. Resumen agrega `verified_qr_count` y `verified_qr_cents`; `unpaid_count` incluye QR pendiente y UNPAID. El reporte de pagos agrupa QR pendiente y verificado por m?todo y estado; el importe pendiente es un importe esperado, no dinero recibido. Los pagos simulados siguen identificados por separado. La caja f?sica solo suma CASH asociado al turno; QR nunca aumenta el efectivo esperado. No se modifican pagos hist?ricos.

## Validaci?n local con Herd

En una consola PowerShell local, sin configuraci?n Laravel cacheada, ejecutar:

```powershell
Set-Location 'C:\Dev\FoodTruckPOS-API'
$php84 = 'C:\Users\Capibara Solution\.config\herd\bin\php84\php.exe'
& $php84 --version
& $php84 artisan test
& $php84 vendor\bin\pint --test
& $php84 artisan route:list --path=api/v1
```

PHPUnit usa SQLite en memoria; habilitar sus extensiones. Si Pint se?ala formato, ejecutar `& $php84 vendor\bin\pint` y repetir las comprobaciones.

Para MariaDB, usar otra consola y solo la base exclusiva y descartable `rustik_pos_concurrency_test`, ya creada en 127.0.0.1. Configurar `DB_USERNAME` y `DB_PASSWORD` locales mediante el entorno sin escribir credenciales en el repositorio. No usar datos reales. `check.php` comprueba entorno, conexi?n y nombre efectivo antes de migrar. Los scripts agregan datos ficticios y no los borran.

```powershell
Set-Location 'C:\Dev\FoodTruckPOS-API'
$php84 = 'C:\Users\Capibara Solution\.config\herd\bin\php84\php.exe'
$env:APP_ENV='testing'
$env:DB_CONNECTION='mysql'
$env:DB_HOST='127.0.0.1'
$env:DB_DATABASE='rustik_pos_concurrency_test'
$env:DB_URL=''
$env:DB_SOCKET=''
& $php84 tests\Concurrency\check.php
# Continuar solo si la verificaci?n anterior termin? correctamente.
& $php84 artisan migrate --force
& $php84 artisan migrate:status
& $php84 tests\Concurrency\qr-run.php
& $php84 tests\Concurrency\run.php
& $php84 tests\Concurrency\cash-run.php
& $php84 tests\Concurrency\report-check.php
& $php84 tests\Concurrency\kitchen-run.php
```

No usar `migrate:fresh`. Cerrar la consola de prueba para descartar sus variables. Ejecutar y revisar resultados antes del despliegue.

## Despliegue

Verificar las pruebas, compatibilidad de PHP y extensiones, y la base de destino; respaldar y ensayar restauraci?n. Publicar c?digo y dependencias compatibles, aplicar solo migraciones pendientes con `php artisan migrate --force` en el destino confirmado y verificar `migrate:status` y rutas. Coordinar versi?n de clientes y probar con datos de prueba autorizados. La migraci?n es aditiva, no rellena ni altera registros anteriores y su rollback autom?tico est? deliberadamente deshabilitado para conservar auditor?a; planificar recuperaci?n desde respaldo y compatibilidad antes de actualizar.

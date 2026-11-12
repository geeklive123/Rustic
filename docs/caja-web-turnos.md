# Caja web y turnos compartidos

Este contrato sustituye las instrucciones anteriores de caja compartida. La autenticacion usa `POST /api/v1/login` y un token Sanctum de un administrador activo; nunca depende de `/reportes` publico. Solo `administrador` tiene `cash.manage`, necesario para apertura, cierre, historial y detalle de caja. `vendedor` y `cocina` conservan `cash.view` y pueden consultar `GET /api/v1/cash-shifts/current`. Las credenciales administrativas deben mantenerse fuera de los dispositivos de venta. El backend no puede verificar que un token administrativo provenga fisicamente del sitio web.

## Android y transicion

`POST /api/v1/orders` acepta `cash_shift_id` opcional para CASH, QR_SIMULATED, QR_STATIC y UNPAID. Enviarlo vincula el pedido al turno abierto y conserva el vendedor, el UUID, la operacion y su hash. Un turno cerrado o inexistente devuelve 409. `data.cash_shift_id` informa el vinculo o null. No se asignan turnos por fecha ni se atribuyen automaticamente pedidos anteriores. Una APK anterior que omite el campo sigue registrando pedidos sin turno, excluidos del historial por caja. El turno se bloquea durante la transaccion para serializar ventas y cierre.

`CASH_SHIFT_REQUIRED=false` es el valor inicial. Cuando todos los dispositivos hayan sido verificados, un responsable puede configurar `CASH_SHIFT_REQUIRED=true` y actualizar la configuracion del despliegue: pedidos nuevos sin turno devuelven 409; los reintentos identicos ya aceptados siguen funcionando. No habilitar antes de actualizar y verificar Android. Las ventas offline deben conservar su turno original y resolver un 409 si este ya cerro.

## Web administrativa

- `POST /api/v1/cash-shifts/open`: `uuid`, `operation_id`, `opening_cents` y opcional `opening_qr_cents` (referencia bancaria, predeterminado 0). Misma operacion y datos devuelve replay; datos distintos o segunda caja abierta devuelven 409.
- `GET /api/v1/cash-shifts/current`: caja abierta compartida o null.
- `GET /api/v1/cash-shifts`: historial paginado.
- `GET /api/v1/cash-shifts/{id}`: datos y `summary`, con cantidad, total, metodos y trabajadores. Para cierres anteriores sin instantanea, `summary` es null para no presentar calculos retroactivos como historicos.
- `GET /api/v1/cash-shifts/{id}/orders?per_page=25`: pedidos vinculados paginados.
- `GET /api/v1/cash-shifts/{id}/orders/{uuid}`: detalle del pedido solo si pertenece a la caja.
- `POST /api/v1/cash-shifts/{id}/close`: `operation_id`, `counted_cents`, `note` opcional salvo diferencia, cuando es obligatoria. El mismo usuario, operacion y datos devuelven replay. El efectivo esperado es fondo mas CASH cobrado; no existe modulo de salidas en el esquema actual, por lo que `cash_outflows_cents` es cero. QR y referencia bancaria nunca se suman al efectivo.

El resumen de cierre es una instantanea inmutable. Se permite cerrar con QR pendientes y confirmar posteriormente con los permisos existentes: el detalle del pedido refleja su estado actual, pero los totales del cierre siguen reflejando el estado al cerrar. El cambio de estado de cocina no confirma pagos. Para turnos anteriores con pagos CASH vinculados solo mediante `payments.cash_shift_id`, se conserva su efectivo en `cash_sales_cents`, pero no se atribuyen esos pedidos al nuevo listado ni al desglose por pedido. No se modifican registros historicos.

## Migraciones y validacion

La migracion pendiente `2026_11_06_090000_add_shared_cash_shift` impide dos cajas abiertas y falla si ya hay varias; requiere reconciliacion previa. La migracion `2026_11_11_000000_add_shift_order_and_closing_snapshot` agrega el vinculo nullable y los campos nuevos sin backfill. Validar respaldo, migraciones, permisos, contrato de APK y concurrencia en MariaDB aislada antes de cualquier despliegue. La base de pruebas debe ser exclusiva y no estar en recuperacion InnoDB. No esta autorizado ejecutar migraciones en produccion como parte de este sprint.

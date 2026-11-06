# Reportes de ventas

Solo el administrador autorizado (`reports.view`) consulta los cuatro endpoints documentados en `api-v1.md`; sin token responden 401 y sin permiso 403. El periodo por defecto es hoy. `yesterday` es el dia natural anterior; `week` comienza el lunes; `month` comienza el primer dia del mes; `custom` incluye ambas fechas. Los limites se calculan en `America/La_Paz`, se convierten a UTC y el extremo final es exclusivo. Los filtros usan `orders.created_at`, que es fecha de recepcion del servidor y no necesariamente la fecha de una venta offline.

Se incluyen todos los pedidos registrados, con cualquier estado de cocina, tambien los no pagados y entregados. No existe un estado de cancelacion o anulacion en el modelo actual. `order_count` y `total_cents` cuentan cada pedido una vez; el total registrado incluye pedidos impagos y QR simulados y no equivale a ingresos cobrados. `cash_cents` incluye solo pagos `CASH/cash_collected`; `simulated_qr_count` y `simulated_qr_cents` agrupan QR simulados por separado y nunca son dinero bancario. `unpaid_count` cuenta pedidos `unpaid`.

Productos suma cantidades y `line_total_cents` historicos, incluidos extras, agrupados por ID y nombre historico; un cambio de nombre puede producir filas separadas para el mismo ID. El orden descendente por cantidad identifica los mas vendidos. `payments` agrupa por metodo y estado; `is_actual_cash` solo es verdadero para efectivo registrado. Las cantidades y sumas se expresan como enteros; no se calculan costos ni utilidades.

Las pruebas `ReportServiceTest` y `ReportsCashTest` comprueban limites de fecha, vacios, pagos, productos, permisos, reintentos y permanencia de datos al cambiar estado de cocina. Las pruebas utilizan SQLite en memoria con el guarda de `tests/TestCase.php`.

# Caja compartida

La migración `2026_11_06_090000_add_shared_cash_shift` conserva los cierres históricos y asigna el turno abierto existente a la caja global. Si hay más de un turno abierto, se detiene antes de cambiar el esquema: un responsable debe reconciliarlos y cerrarlos usando la versión anterior antes de volver a intentar. Preparar respaldo y verificar el estado antes de cualquier despliegue.

`GET /api/v1/cash-shifts/current` devuelve el mismo turno abierto para cualquier usuario autorizado. Una sola apertura puede ganar; una apertura distinta recibe 409. La apertura y el cierre se reintentan con los mismos UUID y datos. El cierre corresponde a quien abrió o a un administrador. La base impide dos claves activas y la venta en efectivo bloquea el turno hasta terminar, de modo que el cierre espera las ventas aceptadas.

Android debe consultar el turno actual tras iniciar sesión y antes de cobrar en efectivo, enviar su `data.id` como `cash_shift_id` con cada pedido CASH y mantener sus propios `uuid`, `operation_id` y sesión. Ante un 409, consultar de nuevo el turno y tratar el cierre o la apertura concurrente. Los reintentos de una venta ya aceptada deben conservar exactamente los datos originales. Los pagos QR no llevan `cash_shift_id`; QR estático sigue requiriendo confirmación manual. Una venta CASH sin `cash_shift_id` sigue admitida por compatibilidad y no se suma a la caja.

El resumen administrativo incorpora `qr_cents` (QR simulado y QR estático verificado) y `sellers`, con identificador, nombre, cantidad de pedidos y total por vendedor. `total_cents` y los totales por vendedor incluyen pedidos pendientes; `cash_cents` cuenta efectivo cobrado y no incluye el fondo inicial. QR pendiente no se incluye en `qr_cents`.

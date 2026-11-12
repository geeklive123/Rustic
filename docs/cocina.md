# Cocina: reapertura de pedidos listos

La ruta autenticada `PATCH /api/v1/kitchen/orders/{uuid}/status` acepta `NEW → PREPARING → READY`. Cocina y administrador pueden volver de `READY → PREPARING` con `orders.prepare`, y luego marcar `PREPARING → READY` otra vez. El vendedor propietario o administrador puede entregar desde `READY` con `orders.deliver`; `DELIVERED` es final.

Para cancelar listo enviar, con token Sanctum:

```json
{"expected_status":"READY","status":"PREPARING","operation_id":"4a376b0b-4677-4a98-9bb4-7ae26a1777b0"}
```

La respuesta usa `data` y `replayed`: 200 con `false` para una transicion aceptada y 200 con `true` para un reintento identico del mismo usuario. Reutilizar la operacion con otros datos o enviar un estado esperado desactualizado produce 409; sin autenticacion 401, sin permiso 403 y transicion invalida 422. Los errores siguen el formato `error` de la API. El replay devuelve el estado actual del pedido.

Al reabrir se actualiza `preparing_at` a UTC y se limpia `ready_at`. Los eventos de auditoria conservan los estados, usuario, fecha UTC y operacion de cada cambio. La entrega conserva `delivered_at`; no se altera un pedido entregado. La reapertura no crea un pedido ni cancela o modifica la venta o el pago.

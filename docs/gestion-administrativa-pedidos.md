# Gesti?n administrativa de pedidos

Las rutas bajo `/admin/pedidos` requieren sesi?n activa y `cash.manage`. La lista permite combinar n?mero, fechas de Bolivia, vendedor, m?todo y estado de pago, estado de Cocina y n?mero de caja o `none`; el detalle presenta precios hist?ricos y observaciones. La nueva ruta POST de observaciones exige CSRF.

## Correcci?n disponible

?nicamente se permite corregir la observaci?n general destinada a Cocina en pedidos NEW y PREPARING. Durante PREPARING hay confirmaci?n expl?cita. El servidor bloquea el pedido, verifica estado y huella del contenido anterior, requiere motivo y registra usuario, fecha, valores anteriores y nuevos. Un `operation_id` ?nico permite reintentar la misma solicitud sin duplicar la auditor?a; una operaci?n o contenido obsoleto devuelve 409. No se modifican productos, importes, UUID ni operaci?n original. La actualizaci?n normal de `orders.updated_at` hace que la informaci?n actualizada est? disponible en el recurso y polling existente de Cocina; el cliente y su intervalo de sondeo no garantizan una alerta inmediata, por lo que se debe comunicar el cambio al personal. No incluir datos personales innecesarios en la observaci?n o motivo: ambos quedan en la auditor?a.

La migraci?n aditiva `2026_11_13_000000_create_order_admin_corrections` crea la tabla de auditor?a e impide su rollback autom?tico. Es necesaria para el detalle administrativo y el formulario; no se aplic? a `rustik_pos_dev` ni a producci?n. La migraci?n se ensay? en `rustik_pos_concurrency_test` con datos ficticios. Planificar respaldo, revisi?n de permisos de auditor?a y retenci?n antes de cualquier despliegue.

## Operaciones bloqueadas

No se implement? edici?n de productos, cantidades, precios, extras u opciones de un pedido, ni cambios de estado arbitrarios. Es necesario dise?ar una revisi?n versionada de preparaci?n compatible con la APK y un libro de ajustes que preserve las instant?neas financieras y los reintentos offline antes de habilitar esas operaciones.

No existen rutas para corregir m?todo de pago, anular o devolver. El esquema permite un solo pago por pedido y los res?menes leen ese pago y el estado del pedido; sobrescribirlos o inventar un estado de anulaci?n modificar?a reportes o romper?a las expectativas de los clientes. En particular, no se corrige autom?ticamente el pedido #105 ni se vinculan pedidos por fecha. Un futuro ajuste debe acordar representaci?n inmutable de m?todo anterior y nuevo, responsable, motivo y operaci?n idempotente; serializarse con caja, confirmaci?n QR y cierre; distinguir caja abierta, cerrada y venta sin caja; exponer conciliaci?n separada para cierres congelados y mantener los res?menes y APK compatibles. QR simulado nunca debe convertirse autom?ticamente en verificado.

Una anulaci?n no es un reembolso. El contrato pendiente debe definir inventario, preparaci?n, tratamiento de caja y pago, reversi?n, presentaci?n en reportes y clientes, autorizaci?n, auditor?a y atenci?n de duplicados antes de habilitar cualquiera. No se han realizado operaciones en producci?n ni cambios sobre ventas reales.

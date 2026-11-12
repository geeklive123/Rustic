# Release local de backend: alcance y despliegue incremental

Este documento es una preparación; no autoriza despliegue, push ni modificaciones del servidor. El repositorio GitHub `geeklive123/Rustic` es público y `main` estaba en `b45e322234773182a2e1ffef0837e5c0a87ffafc` al consultarlo en esta sesión. El servidor SiteGround no fue inspeccionado: no se conocen su código, configuración, ajustes manuales ni esquema actuales. El respaldo local del 12 de noviembre de 2026 es un punto histórico, no una prueba del estado actual.

## Alcance

La rama incluye API de ventas, Cocina, catálogo, combos, precios especiales, Caja Compartida, caja y administración web, reportes, movimientos y confirmación manual QR. El vendedor puede confirmar QR_STATIC propio tras comprobar el abono real; administrador conserva sus permisos y Cocina no obtiene confirmación QR. La operación no verifica el banco automáticamente. No modificar este contrato de permisos para este release.

Persisten bloqueadas las anulaciones, devoluciones, correcciones financieras generales, correcciones de pago sobre caja cerrada y compensaciones de transferencias o pagos. Solo se compensan entradas o salidas completas y la corrección de pago se limita a QR simulado → efectivo con caja abierta o sin caja. Las pruebas no sustituyen revisión financiera y operativa.

## Migraciones

El respaldo local analizado registraba diez migraciones hasta `2026_11_06_080000_add_manual_qr_confirmation_to_payments`. En una copia aislada del esquema y del registro de migraciones de ese respaldo, sin filas de negocio, se aplicaron en orden:

1. `2026_11_06_090000_add_shared_cash_shift`
2. `2026_11_11_000000_add_shift_order_and_closing_snapshot`
3. `2026_11_12_000000_add_catalog_admin_audit`
4. `2026_11_13_000000_create_order_admin_corrections`
5. `2026_11_14_000000_create_order_item_components`
6. `2026_11_15_000000_create_combo_catalog`
7. `2026_11_16_000000_create_special_price_authorizations`
8. `2026_11_17_000000_create_cash_movements`
9. `2026_11_18_000000_add_cash_movement_compensations`

La rama contiene 19 migraciones en total. Las siete últimas están añadidas en el trabajo local; las dos anteriores ya pertenecían a la rama integrada. El ensayo comprobó que las 19 aparecen registradas en la base de prueba; no prueba datos reales ni que las mismas migraciones estén pendientes en SiteGround. El esquema extraído y los datos ficticios permanecieron aislados de desarrollo. No reutilizar `rustik_pos_concurrency_test`: tiene una discrepancia entre una tabla y el registro de migraciones. Si la migración de precios especiales ya existe en destino, comparar su esquema efectivo: la modificación local de columnas a `datetime` no se aplica automáticamente a una migración ya registrada.

## Procedimiento previo a cualquier despliegue autorizado

1. Inventariar en modo lectura el código y las migraciones reales del servidor, la versión exacta de PHP y motor, las personalizaciones de `/reportes`, claves y configuración sin divulgarlas, así como el número de cajas abiertas. Comparar con GitHub, esta rama y el respaldo; resolver divergencias sin sobrescribirlas. Confirmar que exista un administrador autorizado y un plan para sesiones, tokens y `APP_KEY`, sin regenerarla automáticamente.
2. Preparar respaldo consistente y privado de base, código y configuración, ensayar restauración en entorno aislado y definir ventana, monitoreo y tratamiento de ventas offline. Reproducir versión y esquema efectivos en staging con datos ficticios o anonimizados. Revisar especialmente que exista como máximo una caja abierta antes de la migración de caja compartida; el DDL de MariaDB/MySQL puede quedar parcialmente aplicado si falla.
3. En staging aplicar solo migraciones pendientes en orden, con `CASH_SHIFT_REQUIRED=false`, sin `migrate:fresh`, y verificar ventas CASH, QR simulado y QR estático, confirmación manual, Cocina, catálogo, combos, precios, permisos, caja, reportes y reintentos desde APK reales. Las APK sin `cash_shift_id` generan ventas fuera de caja: conciliar por separado.
4. Solo con autorización posterior y tras aprobar el ensayo, aplicar el plan incremental acordado en ventana controlada; verificar salud, autenticación, funcionamiento de APK, errores, cola y conciliación sin crear ventas reales de prueba.

## Reversión

Las migraciones de auditoría y caja rechazan rollback destructivo. Preparar una versión anterior de código compatible con el esquema resultante o restaurar respaldos consistentes y comprobados según el punto de recuperación acordado. No ejecutar `migrate:fresh`, `db:wipe`, `down` destructivo ni reescribir cierres. Un retorno del código no revierte automáticamente operaciones financieras posteriores: detener y conciliar nuevas ventas antes de restaurar. Documentar los cambios aplicados y el estado de la base si se interrumpe una migración.

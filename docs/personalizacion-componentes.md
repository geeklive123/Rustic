> **Contrato reemplazado:** el formato basado en nombres de este documento describe el ensayo anterior. Para pedidos nuevos use [contrato-combos-antigravity.md](contrato-combos-antigravity.md). Solo se acepta el replay exacto de un pedido anterior ya registrado.

# Personalizaci?n individual de componentes

## Alcance y riesgos

El backend anterior ya admite varias l?neas del mismo producto: cada l?nea tiene su cantidad, opciones, extras y notas, y el costo de sus extras se multiplica por esa cantidad. Cuatro hamburguesas con tres configuraciones pueden enviarse como tres l?neas con cantidades 2, 1 y 1. Solo agrupar unidades que tengan exactamente la misma configuraci?n; el backend no fusiona l?neas. Un extra de una l?nea de cantidad 2 se cobra dos veces y se aplica a ambas unidades.

Los combos no ten?an estructura interna ni una definici?n de composici?n en el cat?logo. La extensi?n opcional `components` almacena instant?neas independientes de nombre, notas, opciones y extras; el servidor valida opciones activas, que cada extra est? activo y permitido por el producto padre, y los precios esperados. **La composici?n y el nombre de los componentes son declarados por el cliente y no existe un cat?logo que permita verificar que el combo realmente contiene dos hamburguesas, que sus nombres sean correctos o qu? opciones espec?ficas corresponden a cada componente.** No habilitar su uso operativo hasta definir y validar esa pol?tica, la interfaz y el flujo f?sico. No se crean precios de producto hijo ni se presupone una receta.

## Petici?n nueva

El cuerpo anterior contin?a acept?ndose sin cambios. Para una l?nea personalizada el cliente agrega `components` (1?10), env?a `quantity: 1` y deja los arrays de l?nea `extras` y `option_ids` vac?os para evitar la doble interpretaci?n. Si se venden varios combos con configuraciones independientes, enviar una l?nea por combo. Cada componente contiene `name` (m?ximo 80, sin caracteres de control), `notes` opcional (m?ximo 200), `extras` (m?ximo 20, sin IDs duplicados dentro de un componente) y `option_ids` (m?ximo 20, sin IDs duplicados dentro de un componente):

```json
{
  "product_id": 7,
  "quantity": 1,
  "expected_unit_price_cents": 4000,
  "extras": [],
  "option_ids": [],
  "notes": "Combo pareja",
  "components": [
    {"name": "Hamburguesa 1", "notes": "Con tomate y mostaza", "extras": [{"extra_id": 3, "expected_unit_price_cents": 200}], "option_ids": []},
    {"name": "Hamburguesa 2", "notes": "Sin tomate y sin mostaza", "extras": [], "option_ids": []}
  ]
}
```

Es una l?nea de ejemplo; debe incluirse en el cuerpo de `POST /api/v1/orders` habitual con UUID, `operation_id`, dispositivo, cliente, m?todo, `expected_total_cents` y, cuando corresponda, `cash_shift_id`. El precio de esta l?nea es 4000 + 200 = 4200 centavos. El mismo extra en dos componentes se cobra una vez por componente. El servidor comprueba el precio vigente del producto y de cada extra, rechaza diferencias con 409, calcula el total y rechaza un total esperado distinto. Una opci?n o extra inv?lido o no autorizado, arrays de l?nea mezclados con componentes o cantidad mayor a uno reciben 422. Los precios hist?ricos y nombres de extras/opciones quedan como instant?nea. Los pedidos existentes no se modifican.

Conservar cuerpo, orden de arrays, UUID, `operation_id` y turno ante reintentos. La creaci?n devuelve 201 y el replay id?ntico 200; cambiar cualquier dato con la misma operaci?n causa 409. No cobrar de nuevo mientras el resultado sea incierto.

## Cocina y Android

El recurso de pedido y Cocina a?ade `components` solo para l?neas que los tienen. Las respuestas de pedidos antiguos no cambian de forma. En respuestas de lectura de una l?nea nueva, `items[].extras` contiene la lista plana de extras de todos los componentes, incluyendo repeticiones, para que el historial Android anterior reconstruya el subtotal; es una representaci?n derivada de `components`, no un cobro adicional. En las solicitudes con `components` el array de extras de l?nea debe permanecer vac?o. Los clientes nuevos no deben sumar ambas representaciones. Las versiones actuales de Cocina no conocen ese campo: para esas l?neas, `items[].notes` incluye un texto generado con posici?n, nombre y detalle de cada componente, usando nombres guardados. La APK de Cocina local inspeccionada usa `items[].notes` en `remoteKitchenDocument` y al crear PNG; esta lectura del c?digo no sustituye una prueba real de impresi?n. Las notas originales de la l?nea est?n al principio de ese texto. Un cliente nuevo debe usar `components` como fuente estructurada y evitar imprimir tambi?n el texto de compatibilidad para no duplicar informaci?n. Las respuestas de Cocina no incluyen precios.

Android a?n debe implementar captura individual, persistencia offline de todas las configuraciones y precios, preservaci?n exacta del cuerpo en reintentos, selecci?n de IDs de opciones y extras, c?lculo de precios de acuerdo con el servidor, presentaci?n en historial y comanda, y resoluci?n expl?cita de 409. La APK instalada no enviar? `components` por s? sola. Se necesitan pruebas en dispositivos, formato visual e impresi?n real, conectividad y sincronizaci?n offline antes de activar el flujo.

## Migraci?n y despliegue

La migraci?n aditiva `2026_11_14_000000_create_order_item_components` crea tres tablas de instant?neas, sin tocar ventas existentes; su rollback autom?tico est? bloqueado para proteger el historial. Debe estar aplicada antes de cargar el c?digo nuevo: las lecturas de pedidos y Cocina consultan estas tablas incluso para pedidos antiguos. No se migr? `rustik_pos_dev` ni SiteGround. No se declara apto para producci?n sin comparar el esquema real y los ajustes de SiteGround, probar restauraci?n y desplegar/validar en un entorno equivalente.

El primer ensayo en `rustik_pos_concurrency_test` fall? por un nombre de ?ndice MySQL demasiado largo; MySQL dej? tablas parciales y no registr? la migraci?n. No se borraron ni repararon esas tablas. El nombre se corrigi? y las migraciones completas y el ensayo pasaron en la base nueva `rustik_pos_component_test_20261114`. La primera base de concurrencia debe considerarse con esquema parcial para esta migraci?n; no reintentar all? sin revisar y reconciliar la estructura de manera segura.

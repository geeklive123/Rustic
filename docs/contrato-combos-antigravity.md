# Contrato de combos desde cat?logo para Antigravity

Este contrato es local y no autoriza despliegue. No modificar Android ni activar el flujo en producci?n hasta probar la API, APK instaladas, operaci?n offline y comandas en dispositivos.

## Definici?n administrativa

En Administraci?n ? Cat?logo ? Productos ? Editar ? Configurar componentes, un administrador con `menu.manage` crea o edita cada tipo de componente: nombre, cantidad incluida (1?10), disponibilidad, posibilidad de personalizaci?n, extras y opciones permitidos. El total de unidades activas del combo no puede superar diez. Un extra debe estar asociado al producto padre; las opciones son gratuitas. Los componentes tienen un ID estable y se desactivan, no se borran. La edici?n usa bloqueo del producto y revisi?n optimista: si otro administrador ya modific? la composici?n, responde 409 y hay que recargar. Las solicitudes de escritura est?n protegidas por sesi?n y CSRF.

## Consulta de cat?logo

`GET /api/v1/catalog` conserva todos los campos anteriores. Cuando un producto tiene componentes activos, a?ade por ejemplo:

```json
{
  "id": 7,
  "name": "Combo Pareja",
  "category_id": 2,
  "is_active": true,
  "price_cents": 4000,
  "extra_ids": [3],
  "combo_revision": 4,
  "combo_components": [
    {"id": 21, "name": "Hamburguesa", "quantity": 2, "customizable": true, "extra_ids": [3], "option_ids": [8, 9]}
  ]
}
```

Los IDs de extras listados son la intersecci?n de extras activos del componente con extras activos asociados al producto. Las opciones publicadas est?n activas. Para Cocina se mantienen las restricciones existentes que ocultan precios. Un producto sin composici?n activa no incluye `combo_components` ni `combo_revision`.

## Pedido nuevo

Conservar el resto de `POST /api/v1/orders` exactamente como en el contrato vigente. Si se env?an componentes, usar el `combo_revision` le?do para ese producto, `quantity: 1` por combo y listas de l?nea `extras` y `option_ids` vac?as. Enviar una entrada por unidad incluida: para la definici?n con ID 21 y cantidad 2, el ID 21 aparece exactamente dos veces. El servidor genera los nombres hist?ricos `Hamburguesa 1` y `Hamburguesa 2`; **no se env?a `name` en un pedido nuevo**.

```json
{
  "product_id": 7,
  "combo_revision": 4,
  "quantity": 1,
  "expected_unit_price_cents": 4000,
  "extras": [],
  "option_ids": [],
  "notes": "Combo pareja",
  "components": [
    {"component_id": 21, "notes": "Con tomate", "extras": [{"extra_id": 3, "expected_unit_price_cents": 200}], "option_ids": [8]},
    {"component_id": 21, "notes": "Sin tomate", "extras": [], "option_ids": [9]}
  ]
}
```

Cada componente requiere `component_id`, `extras` y `option_ids`; `notes` es opcional, m?ximo 200 caracteres. Se admite como m?ximo diez componentes, veinte extras y veinte opciones por componente; no se repite un mismo extra u opci?n dentro del mismo componente. Si `customizable=false`, debe enviarse sin extras, opciones ni observaciones. Cada ID debe pertenecer a la composici?n activa y aparecer exactamente las veces configuradas; solo se aceptan extras activos autorizados tanto para el producto como para el componente y opciones activas autorizadas para ese componente. La l?nea vale 4200 centavos en el ejemplo; si ambas hamburguesas llevan queso, el extra se cobra dos veces. Los precios se consultan y comprueban en servidor.

Una receta cambiada devuelve 409 si la revisi?n es antigua; un precio cambiado o total discrepante tambi?n devuelve 409. No generar un nuevo cobro: actualizar el cat?logo y resolver expl?citamente el pedido no aceptado. Errores de pertenencia, cantidades, campos o autorizaciones de componentes devuelven 422. El servidor no valida una receta si el cliente omite por completo `components`: esta excepci?n es intencional para no bloquear APK antiguas. La venta queda con el formato hist?rico, sin detalle individual. Un producto sin composici?n configurada rechaza componentes nuevos; tampoco se aceptan nombres arbitrarios. El contrato experimental anterior basado en nombres solo puede reproducirse si esa operaci?n exacta ya fue aceptada: un pedido nuevo con ese formato se rechaza.

Persistir localmente cuerpo, orden de listas, UUID, `operation_id`, revisi?n y turno antes de enviar. Reintentar exactamente el mismo cuerpo tras un resultado incierto; el servidor comprueba el replay existente antes de validar la receta vigente. Una creaci?n devuelve 201, un replay id?ntico 200 y una operaci?n reutilizada con cambios 409. Las ventas offline con receta o turno obsoleto necesitan una decisi?n operativa; no reasignar ni recobrar autom?ticamente.

## Respuestas, Cocina y precios hist?ricos

Los recursos devuelven `components` con `component_id`, posici?n, nombre hist?rico, observaci?n y extras/opciones hist?ricas solo cuando existen. Pedidos anteriores al nuevo cat?logo pueden tener `component_id: null`; sus instant?neas permanecen legibles. El historial financiero incluye tambi?n `items[].extras` como lista plana derivada de extras de componentes, con repeticiones, para la APK anterior: **no sumar esa lista y los extras de `components` dos veces**. En Cocina, los precios no se exponen y `items[].notes` contiene el texto de compatibilidad con los componentes para la comanda antigua. Un cliente nuevo debe usar la estructura y no imprimir nuevamente su representaci?n plana. La comprobaci?n del c?digo Android no equivale a probar la impresora o APK f?sicas.

## Migraciones y l?mites

La nueva migraci?n `2026_11_15_000000_create_combo_catalog` a?ade revisi?n de producto, definiciones y sus relaciones, y un ID nullable en las instant?neas de componentes. No se asignan valores hist?ricos. Sus tablas y las de la migraci?n anterior deben estar presentes antes de activar este c?digo, incluso para leer el cat?logo. El rollback autom?tico se bloquea para proteger las instant?neas; MySQL DDL puede quedar parcial ante fallo y debe inspeccionarse antes de reintentar. Se ensay? ?nicamente en una base MariaDB local nueva y descartable. La base antigua `rustik_pos_concurrency_test` conserva tablas parciales del ensayo anterior y no se us?. No se modific? SiteGround ni `rustik_pos_dev`.

La revisi?n del cat?logo evita aceptar una receta desactualizada, pero no reemplaza pruebas de UX, recetas, impresoras, alto volumen, seguridad, sincronizaci?n offline y diferencias reales de SiteGround. No se declara apto para producci?n.

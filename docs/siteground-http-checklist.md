# Verificación HTTP para SiteGround

Preparado para después de autorizar y completar un despliegue aislado. No ejecutar carpetas de escritura contra datos reales. Usar TLS verificado, cuentas ficticias temporales, catálogo y pedidos ficticios en una base desechable; nunca exportar tokens/contraseñas. La colección `rustik-api-v1.postman_collection.json` contiene las 30 rutas y cinco casos adicionales. Cada solicitud necesita las variables y el token del rol indicado: las carpetas son agrupaciones, no una secuencia automática lista para ejecutar de una vez. No compartir `operation_id` entre operaciones diferentes; conservarlo y conservar el cuerpo para cada reintento de la misma operación.

En un entorno desechable: asignar tokens temporales de Administrador (A), Vendedor (V) y Cocina (C); crear primero producto y categoría ficticios, luego abrir un turno V, crear un pedido V y guardar sus UUID, operación y número central. Preparar una cuenta ficticia distinta para actualizar; no desactivar el actor. Los identificadores de la colección son variables que deben sustituirse por valores válidos de ese entorno. La creación de usuarios descrita a continuación es exclusivamente de **usuarios ficticios en una base desechable**, no de usuarios reales.

| # | Método y ruta `/api/v1` | Actor y resultado a comprobar |
|---|---|---|
| 1 | GET `/health` | Público 200 y `data.status=ok`; no garantiza conexión a BD. |
| 2 | POST `/login` | Credencial ficticia válida 200; inválida 422; rate limit 429. |
| 3 | POST `/logout` | Token válido 200; ese token después recibe 401. |
| 4 | GET `/me` | Token válido 200, sin token 401. |
| 5 | GET `/users` | A 200 paginado; V/C 403. |
| 6 | POST `/users` | A 201 con cuenta ficticia; V/C 403; datos inválidos 422. |
| 7 | GET `/users/{user}` | A 200 con ID ficticio; V/C 403. |
| 8 | PATCH `/users/{user}` | A 200 sobre cuenta ficticia distinta; V/C 403. |
| 9 | GET `/catalog` | A/V 200 con precios; C 200 sin precios. |
| 10 | GET `/categories` | Roles autenticados 200. |
| 11 | GET `/extras` | A/V 200; C sin precios. |
| 12 | GET `/options` | Roles autenticados 200. |
| 13 | POST `/admin/catalog/{type}` | A 201 con categoría ficticia; V/C 403. |
| 14 | PATCH `/admin/catalog/{type}/{id}` | A 200; V/C 403. |
| 15 | POST `/orders` | V 201; mismo UUID/operación/cuerpo 200; diferente cuerpo 409; 422 inválido. |
| 16 | GET `/orders` | V solo propios; A todos; C sin importes; 200 y paginación. |
| 17 | GET `/orders/{uuid}` | V propio 200 y ajeno 404; C sin importes. |
| 18 | GET `/orders/{uuid}/sync-status` | V propio 200 con número central; UUID ajeno/no existente 404. |
| 19 | GET `/orders/{uuid}/status` | Autorizado 200; V ajeno 404. |
| 20 | GET `/kitchen/orders` | A/C 200, V 403; filtros NEW/PREPARING/READY y sin importes anidados. |
| 21 | GET `/kitchen/orders/{uuid}` | A/C 200 sin importes; V 403; inexistente 404. |
| 22 | PATCH `/kitchen/orders/{uuid}/status` | C NEW→PREPARING→READY; V propietario READY→DELIVERED; salto 422, estado antiguo 409. |
| 23 | GET `/reports/summary` | A 200; V/C 403; QR simulado separado. |
| 24 | GET `/reports/sales` | A 200 con paginación; V/C 403. |
| 25 | GET `/reports/products` | A 200; V/C 403. |
| 26 | GET `/reports/payments` | A 200; V/C 403; QR no es efectivo. |
| 27 | POST `/cash-shifts/open` | V/A autorizados 201 o 200 por reintento idéntico; C 403. |
| 28 | GET `/cash-shifts/current` | V/A 200 para su turno o null; C 403. |
| 29 | POST `/cash-shifts/{id}/close` | Propietario/A autorizado 200, reintento 200; cierre incompatible 409, ajeno V 404, C 403. |
| 30 | GET `/cash-shifts` | A todos; V solo propios; C 403; paginación. |

La comprobación de los 30 métodos/rutas no implica cobertura exhaustiva por ejecutar una sola solicitud por ruta. Repetir con los roles, errores y estados indicados, inspeccionar el resultado y verificar en la base desechable que no hay pagos, pedidos, movimientos o eventos de auditoría duplicados. El smoke local Newman automatiza una pasada de 35 solicitudes; no reemplaza estas pruebas externas ni la concurrencia MySQL del servidor.

## Resultado Newman local y repetición

Se ejecutó Newman 6.2.1 en una copia y SQLite locales desechables: 35 solicitudes, 72 aserciones y cero fallos. Se generaron roles, cuentas, contraseñas y tokens ficticios y la copia se eliminó. Newman fue instalado temporalmente con `--ignore-scripts`, sin instalación global; npm informó dependencias obsoletas, por lo que no se incluye en el artefacto ni se recomienda como dependencia de aplicación.

Para repetir, instalar Newman 6.2.1 en un directorio temporal aislado y revisado. Preparar una copia del backend **sin `.env` ni datos**, llamada `.staging-newman-disposable`, con `tests/Staging/newman-run.cjs`, `tests/Staging/newman-fixtures.php`, la colección, dependencias y un archivo SQLite vacío `isolated.sqlite`. En el proceso definir `APP_ENV=testing`, `APP_DEBUG=false`, `DB_CONNECTION=sqlite`, `DB_DATABASE` como ruta absoluta de ese archivo, `DB_URL` vacío, `CACHE_STORE=array`, `SESSION_DRIVER=file`, `QUEUE_CONNECTION=sync` y una `APP_KEY` aleatoria temporal. Definir `STAGING_PHP` con la ruta a PHP 8.4 y `NEWMAN_MODULE` con la ruta absoluta al módulo Newman temporal. Crear `storage` y `bootstrap/cache`, ejecutar las migraciones solo en esa SQLite y luego:

```powershell
& $env:STAGING_PHP artisan migrate --force --no-interaction
node tests/Staging/newman-run.cjs
```

El runner rechaza otros nombres de copia y rutas de BD, usa el puerto local 18764 y debe ejecutarse desde la copia. `newman-fixtures.php` está diseñado para ser llamado por el runner y transmite tokens ficticios por su salida al proceso padre: no ejecutarlo en una terminal registrada. No conservar la salida con credenciales. Una vez terminado, verificar que el puerto dejó de escuchar y que las rutas son exactamente las temporales antes de eliminarlas. Las credenciales son solo de esa ejecución; el test no se conecta a SiteGround.

# RUSTIK POS: plan de staging en SiteGround

Este es un plan para ejecutar después de validar y autorizar la infraestructura. No se ha accedido a SiteGround ni se ha desplegado. Complementa `staging.md` y `staging-2.0f.md`. Sustituir todos los marcadores por valores propios de RUSTIK; no reutilizar bases, claves ni credenciales. El entorno de pruebas debe ser independiente de los otros sitios.

## Puertas de entrada: detenerse si alguna falla

1. En Site Tools del sitio **independiente de RUSTIK**, confirmar plan, dominio, acceso SSH/SFTP y posibilidad de apuntar el document root exclusivamente a `public/`. SiteGround exige que el subdominio Laravel apunte a esa carpeta, pero su documentación consultada no garantiza que se pueda editar dicho destino en todos los planes. Confirmar el procedimiento concreto con soporte antes de subir archivos. El directorio padre del proyecto no debe poder servirse por ningún otro dominio o alias. No usar redirecciones `.htaccess` como sustituto de un document root seguro.
2. Confirmar PHP web **8.4**, extensiones y PHP CLI **8.4** con el `composer.lock`. Laravel 13 requiere PHP >= 8.3, pero este proyecto exige 8.4. SiteGround ofrece selección 8.4; la disponibilidad y configuración real deben verificarse en la cuenta. Site Tools > Devs > PHP Manager permite examinar la selección del dominio. Su modalidad Ultrafast comparte versión y variables del sitio principal con subdominios: no cambiar ese ajuste en un sitio que aloja otros proyectos. Usar un sitio independiente o confirmar aislamiento con soporte.
3. En Site Tools > Domain > Subdomains crear posteriormente el subdominio acordado. Confirmar DNS y, en Security > SSL Manager, emitir certificado para ese nombre; comprobar cadena, hostname y renovación. Activar HTTPS Enforce solo para el sitio/subdominio RUSTIK, después de comprobar el alcance. No debilitar la verificación TLS con `-k` ni enviar credenciales por HTTP.
4. En Site Tools > Site > MySQL crear una base y un usuario exclusivos, y asignar el usuario a la base con los privilegios precisos; SiteGround puede generar los nombres efectivos. Registrar host, puerto y nombre exactos en un gestor seguro. La versión y el tipo exactos del servidor, InnoDB, privilegios, `utf8mb4`, SQL mode y zona UTC se deben confirmar en esa cuenta: no suponer que coinciden con MariaDB local.
5. Sin acceso a un servidor MySQL equivalente a SiteGround, las migraciones y la concurrencia en ese motor **no están verificadas**. Laravel admite MySQL 5.7+ y MariaDB 10.3+; eso no certifica estas migraciones o este plan de alojamiento. Se han probado localmente en MariaDB. Exigir ensayo de las nueve migraciones desde una base vacía desechable del mismo motor y versión, claves, índices, transacciones, `SELECT ... FOR UPDATE`, restricciones únicas con NULL, collation y concurrencia antes de usar datos útiles. Revisar `migrate --pretend` y privilegios. Nunca apuntar el ensayo a otro proyecto.

Fuentes: [requisitos Laravel 13](https://laravel.com/docs/13.x/deployment), [bases Laravel](https://laravel.com/docs/13.x/database), [Laravel en SiteGround](https://www.siteground.com/kb/install-laravel), [PHP SiteGround](https://www.siteground.com/kb/what-is-managed-php), [Ultrafast PHP](https://www.siteground.com/kb/ultrafast-php-enable), [subdominios y SSL](https://www.siteground.com/kb/subdomain), [MySQL](https://www.siteground.com/tutorials/php-mysql/create-user-database), [SSH](https://www.siteground.com/kb/access-site-ssh-connection).

## Estructura y PHP

Ejemplo conceptual; las rutas reales dependen de la cuenta y deben confirmarse. El padre no puede ser raíz de ningún sitio:

```text
<directorio privado RUSTIK>/
  releases/<version>/        # app, vendor, bootstrap, .env protegido
    public/                  # único document root del subdominio
  backups/                   # privado, fuera de todos los document roots
```

No crear `.env`, copias, `vendor`, `storage` o `bootstrap` en un árbol público. Proteger `bootstrap/cache`, logs y archivos privados; `storage` y `bootstrap/cache` requieren escritura por el proceso web. Preparar estrategia para preservar `storage` y `.env` entre versiones sin incluirlos en el artefacto, validando enlaces/propietarios y el soporte del hosting. No crear archivos de diagnóstico `phpinfo()` públicos; comprobar la versión web seleccionada en Site Tools y, de requerir verificación adicional, usar un diagnóstico temporal protegido y retirarlo inmediatamente sin exponer valores sensibles.

SSH: utilizar usuario, hostname, puerto y clave mostrados en Site Tools > Devs > SSH Keys Manager; el puerto 18765 figura en la documentación de SiteGround, confirmar el real. No mostrar claves ni valores del entorno.

```sh
ssh -p <PUERTO_SSH> <USUARIO_SSH>@<HOST_SSH>
command -v php
php -v
php --ini
php -m
command -v composer
composer --version
```

Si `php` CLI no es 8.4, localizar con SiteGround la ruta autorizada del binario CLI 8.4 y usarla explícitamente como `PHP84=/ruta/confirmada/php`; no asumir rutas. Verificar `"$PHP84" -v`, `"$PHP84" -m` y usar ese binario también para ejecutar Composer; obtener la ruta de `composer.phar` compatible. CLI y web pueden usar `php.ini` diferentes. Comprobar Ctype, cURL, DOM, Fileinfo, Filter, Hash, Mbstring, OpenSSL, PCRE, PDO, Session, Tokenizer, XML y `pdo_mysql`, y `"$PHP84" <composer.phar> check-platform-reqs --no-dev` con el lock instalado. Verificar también límites compartidos de memoria y procesos.

## Preparación e instalación posterior

1. Transferir mediante canal autenticado solo una versión revisada: app, bootstrap sin cachés, config, database/migrations y seeders autorizados, public, resources, routes, artisan, composer.json/lock. Excluir `.env`, backups, logs, pruebas, `.staging-*`, `android-integration-work` y artefactos locales. No publicar un ZIP que no haya sido inspeccionado.
2. Crear `.env` protegido a partir de `docs/staging.env.example`, con `APP_ENV=staging`, `APP_DEBUG=false`, `APP_URL=https://<SUBDOMINIO>`, `DB_CONNECTION=mysql` y datos exclusivos del servidor. `DB_URL` vacío; CORS cerrado salvo orígenes web HTTPS aprobados. `TRUSTED_PROXIES` vacío salvo IP/CIDR concretos confirmados por SiteGround; restringir Host en infraestructura. `APP_KEY` se genera en staging una sola vez y se protege, manteniéndola estable entre versiones. No imprimir credenciales ni la caché de configuración.
3. Ya dentro de la release privada y con `PHP84` y Composer confirmados:

```sh
cd <RELEASE_PRIVADA>
"$PHP84" <COMPOSER_PHAR> validate --no-check-publish
"$PHP84" <COMPOSER_PHAR> install --no-dev --no-interaction --no-scripts --optimize-autoloader
"$PHP84" <COMPOSER_PHAR> check-platform-reqs --no-dev
"$PHP84" artisan package:discover
"$PHP84" artisan key:generate   # solo en .env nuevo, jamás sobre clave existente
```

Comprobar primero que no exista una clave previa; generar una nueva en una release existente invalidaría datos cifrados. El lock y los scripts se revisan antes de ejecutarse; no usar `composer setup` ni actualizar dependencias. En una actualización se conserva la clave anterior, no se ejecuta `key:generate`.

## MySQL, respaldo y migraciones

Antes de cualquier escritura, usar credenciales exclusivas por un archivo de opciones protegido o autenticación interactiva; nunca colocar contraseñas en argumentos, comandos históricos ni logs. Identificar host y base efectiva con lectura:

```sh
mysql --defaults-extra-file=<ARCHIVO_PRIVADO> --database=<BASE_STAGING_EXACTA> \
  -e 'SELECT DATABASE(), VERSION(), @@version_comment, @@sql_mode, @@time_zone, @@system_time_zone; SHOW ENGINES;'
```

Confirmar el destino y permisos en Site Tools. Verificar respaldo consistente de la base correcta fuera del árbol web, con `umask 077`, ruta/nombre nuevo, sin sobrescribir archivos y con hash registrado; probar previamente el cliente y opciones disponibles:

```sh
umask 077
mysqldump --defaults-extra-file=<ARCHIVO_PRIVADO> --single-transaction \
  --routines --triggers --events <BASE_STAGING_EXACTA> > <RESPALDO_NUEVO_PRIVADO.sql>
test -s <RESPALDO_NUEVO_PRIVADO.sql> && sha256sum <RESPALDO_NUEVO_PRIVADO.sql>
```

No incluir `--databases` si el plan de restauración exige seleccionar otro destino. `--single-transaction` cubre tablas transaccionales; confirmar motores y objetos/permisos, consistencia y recuperación. Un archivo no vacío no demuestra restaurabilidad. Tratarlo como sensible y cifrarlo según política. Restaurar solo a otra base desechable recién identificada, inspeccionando antes el volcado para descartar `USE`, cambios de esquema y referencias cruzadas:

```sh
mysql --defaults-extra-file=<ARCHIVO_PRIVADO> --database=<BASE_DESECHABLE_CONFIRMADA> < <RESPALDO_REVISADO.sql>
```

Comparar migraciones, tablas, índices, FK y conteos; comprobar huérfanos e InnoDB. No usar datos de desarrollo en staging ni restaurar sobre una base activa como ensayo.

Tras ensayo de MySQL y respaldo verificado, con el destino efectivo confirmado:

```sh
"$PHP84" artisan migrate:status
"$PHP84" artisan migrate --pretend
# Inspeccionar el SQL y detenerse si hay discrepancias.
"$PHP84" artisan migrate --force
"$PHP84" artisan migrate:status
"$PHP84" artisan config:cache
"$PHP84" artisan route:cache
"$PHP84" artisan route:list --path=api/v1
```

No usar `migrate:fresh`, `db:wipe` ni rollback automático. El estado de desarrollo actual tiene nueve migraciones; no asumir que estén aplicadas en staging. `QUEUE_CONNECTION=sync` y el scheduler actual no requieren worker/cron permanente; verificar de nuevo antes de habilitar ninguno. Probar rate limits con cache compartido y configurar logs privados con rotación y retención.

## Smoke externo y recuperación

Solo tras infraestructura y despliegue autorizados, ejecutar desde cliente confiable:

```sh
curl --fail --silent --show-error https://<SUBDOMINIO>/api/v1/health
curl --silent --show-error -o /dev/null -w '%{http_code}\n' -H 'Accept: application/json' https://<SUBDOMINIO>/api/v1/me
# Esperado: JSON status=ok y 401, respectivamente.
```

Comprobar redirección o rechazo HTTP sin credenciales y respuesta 403/404 para `/.env`, `/vendor/`, `/storage/`, `/bootstrap/cache/` y rutas de respaldo; asegurarse de que no haya exposición vía otros aliases. La ausencia de un archivo no demuestra por sí sola el aislamiento. No enviar tokens a un certificado o dominio no validados. La colección Postman cubre las 30 rutas; sus carpetas de escritura solo deben ejecutarse en una base desechable con credenciales ficticias temporales y valores de variables configurados por rol. `docs/siteground-http-checklist.md` enumera casos. No generar usuarios reales ni usar datos de desarrollo.

Ante fallo, detener escrituras y conservar evidencia sin secretos. Reapuntar únicamente el sitio RUSTIK a la release anterior compatible y regenerar sus cachés; confirmar storage, configuración y clave. Las migraciones de cocina/caja no se revierten automáticamente. Si el esquema no es compatible, restaurar únicamente según plan aprobado y con respaldo previo del estado actual; reconciliar operaciones realizadas desde el respaldo para evitar perder ventas. Ensayar recuperación en otra base desechable antes de actuar sobre una activa.

## Checklist previo a solicitar despliegue

- [ ] Sitio independiente, DNS, document root y aislamiento de otros proyectos confirmados por cuenta/soporte.
- [ ] PHP 8.4 CLI y web, extensiones y Composer comprobados en servidor.
- [ ] Certificado válido y HTTPS, host, proxy, CORS y acceso a archivos privados comprobados.
- [ ] MySQL exacto y nueve migraciones probados en base desechable equivalente; concurrencia y permisos comprobados.
- [ ] Base, usuario, clave y variables exclusivos; respaldos y restauración ensayados.
- [ ] Release revisada, cachés, smoke y plan de rollback verificados; cuentas de prueba temporales previstas.

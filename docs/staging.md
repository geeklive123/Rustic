# Preparación del servidor de pruebas

Este documento describe una instalación posterior; este sprint no despliega servicios. Usar PHP 8.4 y Composer compatibles con el `composer.lock`, y MariaDB con InnoDB y `utf8mb4`. Comprobar `composer check-platform-reqs --no-dev` y las extensiones exigidas por Composer y Laravel, entre ellas `pdo_mysql`, `mbstring`, `openssl`, `fileinfo`, `xml`, `ctype`, `json` y `tokenizer`. SQLite y `pdo_sqlite` se necesitan para las pruebas locales, no para la conexión MariaDB de staging.

1. Crear una cuenta de base y una base exclusivas para staging, con privilegios mínimos adecuados a migraciones y operación. No usar credenciales ni bases de desarrollo. Preparar un respaldo verificable y un procedimiento de restauración antes de cualquier cambio.
2. Instalar el proyecto fuera del directorio público del servidor y configurar el document root exclusivamente en `public/`; negar acceso directo a `.env`, `storage/`, respaldos, `vendor/` y archivos internos. Respaldos cifrados y fuera del árbol web.
3. Copiar `docs/staging.env.example` a la configuración protegida del entorno, no copiar el `.env` de desarrollo. Asignar `APP_ENV=staging`, `APP_DEBUG=false`, `APP_URL` HTTPS, base y credenciales exclusivas. Generar una `APP_KEY` única con `php artisan key:generate` en el entorno aislado y mantenerla protegida; perderla afecta datos cifrados. Nunca incluirla en reportes ni repositorios.
4. Instalar dependencias bloqueadas con `composer install --no-dev --no-interaction --optimize-autoloader`; revisar los scripts de Composer antes de ejecutarlos. No ejecutar `composer setup`, que incluye migraciones y otras acciones. Dar escritura limitada al usuario del proceso en `storage/` y `bootstrap/cache/`; proteger `.env` y logs.
5. Terminar HTTPS en el servidor o proxy, redirigir/rechazar HTTP expuesto y proteger el tráfico interno. `TRUSTED_PROXIES` queda vacío por defecto y acepta IP/CIDR concretos separados por coma; se rechazan comodines. Configurar solo proxies bajo control y probar cabeceras y URL generadas. `APP_URL` no impone TLS por sí sola. La API Android usa bearer, no cookies de navegador. CORS queda cerrado por defecto; configurar `CORS_ALLOWED_ORIGINS` con orígenes HTTPS exactos separados por coma solo si existe un cliente web autorizado.
6. Verificar destino efectivo y estado con `php artisan migrate:status`, respaldar y aplicar `php artisan migrate --force` sin `migrate:fresh`. Comprobar el resultado. Compilar cachés con `php artisan config:cache` y `php artisan route:cache`; contienen configuración sensible y deben quedar fuera de `public/`. Tras cambiar entorno o rutas, limpiar y reconstruir cuidadosamente los cachés del despliegue activo.
7. Verificar health, autenticación y permisos con usuarios temporales aislados y datos ficticios. Configurar rotación y acceso restringido de logs; pueden contener detalles de excepciones y datos operativos. No registrar tokens, contraseñas ni cuerpos completos de solicitudes.

La configuración propuesta usa `QUEUE_CONNECTION=sync`; no requiere worker permanente para los flujos implementados. No hay tarea programada de negocio identificada que exija cron: verificar `php artisan schedule:list` antes de habilitarlo. No habilitar workers o cron por costumbre.

## Respaldo, restauración y reversión

Realizar respaldo consistente de MariaDB con una herramienta y permisos apropiados, incluyendo esquema, datos y objetos necesarios; conservar también configuración y versión de aplicación por separado, con controles de acceso. Verificar el archivo y ensayar regularmente la restauración en otra base desechable con credenciales dedicadas, comparando tablas y conteos; nunca restaurar sobre desarrollo o staging activo durante una prueba.

Para revertir un despliegue, detener escrituras, evaluar compatibilidad de esquema y restaurar el artefacto anterior. Las migraciones de cocina y caja impiden deliberadamente su `down()` porque borrarían historial. Si se requiere restaurar una base, hacerlo solo mediante un plan aprobado, con respaldo del estado actual y reconciliación de operaciones posteriores: restaurar un respaldo antiguo puede perder ventas. No ejecutar rollback destructivo automáticamente.

## Checklist

- [ ] Dependencias y extensiones verificadas en servidor.
- [ ] Base, usuario y secretos exclusivos; `APP_DEBUG=false` y clave protegida.
- [ ] HTTPS real, document root en `public/`, proxies confiables concretos.
- [ ] CORS limitado a consumidores web reales y logs protegidos.
- [ ] Respaldo y restauración ensayados en base desechable.
- [ ] Migraciones inspeccionadas, respaldo previo y cachés regeneradas.
- [ ] Health, autenticación, permisos, caja y reportes verificados con datos ficticios.
- [ ] Plan de recuperación de ventas offline y asociación explícita de caja acordado.

# Instalación y despliegue en producción

ConciliaRH se instala en un servidor Linux **sin Docker**: Apache 2.4 + PHP-FPM, PostgreSQL, PHP CLI para el procesador en segundo plano (worker) y systemd para los servicios. El servidor no necesita Python. Docker sólo se usa en desarrollo y pruebas.

Esta guía es suficiente para instalar desde el paquete `conciliarh-<versión>-production.zip` sin conocer el código. Cada comando se probó en un servidor Debian 13 limpio (registro de la prueba en la suite de pruebas, «Instalación limpia desde el ZIP»).

**Convenciones:**
- Instalación en `/opt/conciliarh`.
- Datos en `/var/lib/conciliarh`.
- Configuración en `/etc/conciliarh`.
- Los comandos con `#` se ejecutan como root; los demás, como cualquier usuario.
- Ajuste nombres y rutas si su política lo exige, pero mantenga la **separación de usuarios y de archivos de entorno**.

| § | Contenido |
|---|---|
| 0 | Inventario del servidor (antes de instalar) |
| 1 | Requisitos |
| 2 | Paquete y verificación |
| 3 | PostgreSQL |
| 4 | Usuarios del sistema operativo |
| 5 | Almacenamiento y permisos |
| 6 | Configuración y llaves |
| 7 | PHP |
| 8 | Apache, HTTPS y proxy inverso |
| 9 | Worker |
| 10 | Scheduler, verificación y respaldo (timers) |
| 11 | Preflight, migraciones, GOD y verificación posterior |
| 12 | Actualizar a una versión nueva y regresar (rollback) |
| 13 | Logs |
| 14 | Monitoreo |
| 15 | Política de respaldos |
| 16 | Conexiones a la BD y *poolers* |

---

## 0. Inventario del servidor (antes de instalar)

Antes de tocar el servidor, levante su inventario con el script de **sólo lectura** incluido en el paquete:

```
# bash deploy/setup/inventario-servidor.sh
```

- Escribe un único archivo `conciliarh-inventario-<host>-<fecha>.txt` (0600) en el directorio actual.
- No instala, no modifica, no reinicia, no cambia el firewall, no se conecta a PostgreSQL y no lee el contenido de archivos de configuración ni secretos.
- Recoge: distribución, kernel, CPU, memoria, discos y espacio, zona horaria y NTP, red y rutas, puertos en escucha, firewall, SELinux o AppArmor, Apache (módulos y resumen de VirtualHosts), PHP y sus módulos (incluidos `gd` y `pcntl`), PostgreSQL, systemd y servicios.
- Con `sudo` es más completo: el firewall y el proceso de cada puerto sólo los ve root.

Revise el archivo y compárelo con los requisitos del §1 antes de continuar.

**Nombres:** el usuario del sistema del scheduler es `conciliarh-sched`. Las unidades de systemd se llaman `conciliarh-scheduler.service` y `conciliarh-scheduler.timer`, y el rol de la base de datos, `conciliarh_scheduler`.

## 1. Requisitos

| Componente | Versión | Notas |
|---|---|---|
| Linux | Con soporte vigente y systemd | NTP activo; zona horaria del sistema `America/Mexico_City` |
| PHP (FPM y CLI) | **8.3 o superior** | Extensiones: `pdo_pgsql`, `sodium`, `openssl`, `mbstring`, `bcmath`, `zip`, `fileinfo`, `intl`, `xmlreader`, `dom`, `iconv` |
| Apache | 2.4.7+ | Módulos `ssl`, `headers`, `rewrite`, `proxy`, `proxy_fcgi`, `setenvif`, `reqtimeout`; `remoteip` sólo con balanceador |
| PostgreSQL | **14 o superior** (probado en 17) | En el mismo servidor o en otro con TLS |
| Procesador en segundo plano | El mismo PHP CLI | Extensiones adicionales: `gd`, `ctype`, `filter`, `libxml`, `simplexml`, `xml`, `xmlwriter`, `zlib`; `pcntl` recomendada (detención ordenada). No requiere Python |
| Otros | `unzip`, `gnupg`, `postgresql-client` (respaldo) | |

**Paquetes de ejemplo en Debian 13 / Ubuntu 24.04** (los nombres varían por distribución):

```bash
# apt-get install -y apache2 php-fpm php-cli php-pgsql php-mbstring php-bcmath php-zip php-intl php-xml php-gd \
      postgresql postgresql-client unzip gnupg
# a2enmod ssl headers rewrite proxy proxy_fcgi setenvif reqtimeout
```

En Debian/Ubuntu, `sodium`, `openssl`, `fileinfo`, `iconv`, `ctype`, `filter`, `zlib` y `pcntl` ya vienen en `php-common`/`php-cli`; `php-gd` instala `php8.x-gd` de la misma versión de PHP. Verifique con `php -m`. El preflight (§11) confirma todas las extensiones.

**Recursos mínimos orientativos:**
- 4 vCPU y 8 GB de RAM;
- disco para originales y reportes, idealmente cifrado (LUKS), con ≥ 15 % libre;
- el worker admite hasta 2 GB por libro (`MemoryMax`).

## 2. Paquete y verificación

El paquete se genera en un equipo de construcción con `bash scripts/build-release.sh`, siempre desde un commit. Contiene:
- código, `vendor/` (sin dependencias de desarrollo) y migraciones;
- el procesador en segundo plano (`bin/worker.php`, PHP) y `deploy/` (plantillas de configuración);
- la documentación de operación, `BUILD.json`, `MANIFEST.sha256` y `SBOM.cdx.json`.

**No contiene:**
- `.env` ni secretos;
- pruebas, fixtures ni capturas;
- Docker, `.git`, Excel ni respaldos.

Verifique el paquete **antes** de descomprimir:

```bash
sha256sum -c conciliarh-0.1.0-production.zip.sha256     # debe decir «La suma coincide» / OK
```

Descomprimir en una carpeta por versión (nunca encima de otra) y verificar cada archivo:

```bash
# install -d -m 0755 /opt/conciliarh/releases
# unzip -q conciliarh-0.1.0-production.zip -d /opt/conciliarh/releases/
# cd /opt/conciliarh/releases/conciliarh-0.1.0 && sha256sum --quiet -c MANIFEST.sha256 && echo "Paquete íntegro"
# chown -R root:root /opt/conciliarh/releases/conciliarh-0.1.0
# chmod -R u=rwX,go=rX /opt/conciliarh/releases/conciliarh-0.1.0
# ln -sfn /opt/conciliarh/releases/conciliarh-0.1.0 /opt/conciliarh/current
# install -m 0755 /opt/conciliarh/current/deploy/bin/conciliarh-run /usr/local/sbin/conciliarh-run
```

El código es de `root` y de **sólo lectura** para todos los servicios: un servicio comprometido no puede modificar la aplicación.

## 3. PostgreSQL

### 3.1 Roles y base

Hay cinco roles, ninguno superusuario:

| Rol | Uso | Archivo de entorno |
|---|---|---|
| `conciliarh_owner` | Dueño del esquema: migraciones, alta de GOD, rotaciones | `cli.env`, sólo mientras se usa |
| `conciliarh_web` | Aplicación PHP | `web.env` |
| `conciliarh_worker` | Procesador en segundo plano, que procesa libros no confiables | `worker.env` |
| `conciliarh_scheduler` | Reportes programados | `scheduler.env` |
| `conciliarh_backup` | Respaldo, de sólo lectura (`pg_read_all_data`) | `backup.env` |

Genere contraseñas largas y distintas (por ejemplo `openssl rand -base64 33`) y guárdelas en la bóveda institucional. El DBA ejecuta,
desde un directorio que el usuario `postgres` pueda leer:

```bash
$ cd /tmp && sudo -u postgres psql -v ON_ERROR_STOP=1 -d postgres -v db=conciliarh -f /opt/conciliarh/current/deploy/postgres/01-roles-y-base.sql
$ for r in owner web worker scheduler backup; do sudo -u postgres psql -c "\\password conciliarh_$r"; done   # interactivo: pide cada contraseña
```

Las contraseñas se escriben en el aviso de `\password` (nunca en la línea de comandos ni en el historial ni en los logs del servidor). El script:
- crea la base `conciliarh` (UTF-8, dueño `conciliarh_owner`);
- revoca `PUBLIC`;
- da `CONNECT` y `USAGE` a los roles de servicio;
- fija la zona horaria `America/Mexico_City`;
- limita conexiones y tiempos por rol (§16).

### 3.2 `pg_hba.conf`

Sólo los roles y orígenes necesarios, con `scram-sha-256`; nunca `trust`. Ejemplo con la BD en el mismo servidor:

```
# TYPE  DATABASE    USER                  ADDRESS        METHOD
host    conciliarh  conciliarh_web        127.0.0.1/32   scram-sha-256
host    conciliarh  conciliarh_worker     127.0.0.1/32   scram-sha-256
host    conciliarh  conciliarh_scheduler  127.0.0.1/32   scram-sha-256
host    conciliarh  conciliarh_owner      127.0.0.1/32   scram-sha-256
host    conciliarh  conciliarh_backup     127.0.0.1/32   scram-sha-256
```

Con la BD en otro servidor:
- use `hostssl` y `DB_SSLMODE=verify-full` (con el certificado de la CA en el cliente);
- permita sólo la IP del servidor de aplicación.

### 3.3 `postgresql.conf` (recomendado)

- `log_min_duration_statement = 2000`
- `log_statement = 'none'`: no registrar parámetros de consultas, que pueden contener nombres.
- `password_encryption = scram-sha-256`
- `log_error_verbosity = terse`: sin él, el `DETAIL` de una violación de restricción escribe la fila completa en el log del servidor.
- `max_connections` ≥ suma de los límites de §16 + margen del DBA.

Las migraciones crean extensiones o funciones sólo dentro del esquema `public` del dueño. No requieren superusuario.

### 3.4 Después de migrar

Paso obligatorio tras el primer `migrate` (§11). Da al rol de respaldo permiso para registrar su resultado:

```bash
$ sudo -u postgres psql -v ON_ERROR_STOP=1 -d conciliarh -f /opt/conciliarh/current/deploy/postgres/02-despues-de-migrar.sql
```

## 4. Usuarios del sistema operativo

Ningún servicio corre como `root`. El script crea los usuarios y el almacenamiento (§5); es idempotente:

```bash
# STORAGE_PATH=/var/lib/conciliarh bash /opt/conciliarh/current/deploy/setup/preparar-sistema.sh
```

| Usuario (sin shell, sin home) | Proceso | Puede |
|---|---|---|
| `conciliarh-web` | Pool de PHP-FPM | Cargar originales, exportar, descargar reportes cifrados, escribir sus logs |
| `conciliarh-worker` | Procesador en segundo plano, PHP CLI (systemd) | **Leer** originales; escribir sus logs y temporales |
| `conciliarh-sched` | Scheduler (timer) | Escribir reportes cifrados, sus logs y temporales |
| `conciliarh-backup` | Respaldo (timer) | **Leer** la BD (rol de sólo lectura) y el almacenamiento; escribir en el destino del respaldo |
| `conciliarh-admin` | Comandos con credenciales del **propietario** (`conciliarh-run migrate`, `create-god`, `god-recovery`, rotaciones de cuentas, mantenimiento) | Nada permanente: existe para que ningún servicio comparta su UID ni pueda leer su entorno en `/proc` |

Los cinco pertenecen al grupo `conciliarh-data`, que sólo da acceso a `/var/lib/conciliarh`. Apache (`www-data`) **no** está en ese grupo: sólo habla con PHP-FPM por su socket.

## 5. Almacenamiento y permisos

`STORAGE_PATH=/var/lib/conciliarh`, **fuera** del web root:

| Ruta | Dueño:grupo | Modo | Quién escribe | Contenido |
|---|---|---|---|---|
| `./` | `root:conciliarh-data` | `2770` | CLI de mantenimiento | `mantenimiento.json` (sólo durante mantenimiento) |
| `imports/` | `conciliarh-web:conciliarh-data` | `2750` | web | Originales `imports/{periodo}/{lote}/{uuid}/original.{ext}`, **archivos 0440** inmutables |
| `imports/.staging/` | `conciliarh-web:conciliarh-web` | `0700` | web | Cargas en curso (privado) |
| `reportes/generados/` | `conciliarh-sched:conciliarh-data` | `2750` | scheduler | Reportes cifrados (`*.enc`, 0640); la web sólo los lee |
| `trabajo/` y `trabajo/reportes/` | `root:conciliarh-data` | `1770` | todos | Temporales `0600` de cada servicio (bit *sticky*: nadie borra los de otro) |
| `logs/` | `root:conciliarh-data` | `3770` (sticky) | todos | Cada proceso escribe sólo el suyo, `0640`: `{canal}.log` (web), `{canal}-cli-{usuario}.log` (CLI; el procesador escribe `motor-cli-conciliarh-worker.log`) |
| `cuarentena/` | `conciliarh-web:conciliarh-data` | `2750` | verificación | Huérfanos movidos por `verificar-almacenamiento --cuarentena` |

Reglas:
- La web **no puede** modificar originales: son 0440 y nadie tiene escritura sobre ellos.
- El worker los lee con `ReadOnlyPaths` de systemd.
- El volumen debe tener `noexec,nosuid,nodev` y, de preferencia, estar cifrado (LUKS). Los temporales de la generación de reportes existen unos instantes en claro (docs/REPORT_ENCRYPTION.md §5).
- Respalde `imports/` y `reportes/generados/`; `trabajo/` y `logs/` no son datos.

## 6. Configuración y llaves

Cada servicio lee **sólo su archivo** de `/etc/conciliarh` (directorio `0711 root:root`: se atraviesa pero no se lista):

| Archivo | Permisos | Lo lee | Contiene |
|---|---|---|---|
| `web.env` | `0640 root:conciliarh-web` | PHP-FPM, por el enlace `/opt/conciliarh/current/.env` | `APP_KEY`, llaves de cuentas y de reportes, rol web |
| `worker.env` | `0600 root:root` | systemd | Rol del worker, llaves de cuentas y **HMAC** |
| `scheduler.env` | `0600 root:root` | systemd | Rol del scheduler y **llaves de reportes** |
| `cli.env` | `0600 root:root` | `conciliarh-run`, vía systemd-run | Credenciales del **propietario** (sólo `DB_MIGRATE_*`) |
| `backup.env` | `0600 root:root` | systemd | Conexión del rol de respaldo, destino y llaves GPG |

Las plantillas están en `deploy/env/*.env.example`; el detalle de cada variable, en `.env.example`. Para preparar los archivos:

```bash
# cd /opt/conciliarh/current/deploy/env
# for f in web worker scheduler cli backup; do install -m 0600 -o root -g root $f.env.example /etc/conciliarh/$f.env; done
# chown root:conciliarh-web /etc/conciliarh/web.env && chmod 0640 /etc/conciliarh/web.env
# ln -sfn /etc/conciliarh/web.env /opt/conciliarh/current/.env
```

**Llaves:** 64 hexadecimales cada una, todas distintas. La aplicación **no arranca** si se repiten, si falta una o si su formato es inválido.

```bash
openssl rand -hex 32   # APP_KEY
openssl rand -hex 32   # ACCOUNT_ENC_KEYS=k1:<…>      (web, worker)
openssl rand -hex 32   # ACCOUNT_HMAC_KEYS=h1:<…>     (worker)
openssl rand -hex 32   # REPORT_ENC_KEYS=r1:<…>       (web, scheduler)
```

- La misma llave de cuentas va en `web.env` y `worker.env`; la misma llave de reportes, en `web.env` y `scheduler.env`.
- **Resguarde todas las llaves fuera del servidor**, en una bóveda o en un sobre sellado con dos custodios. Sin ellas un respaldo no recupera cuentas ni reportes (docs/BACKUP_RESTORE.md §4).

**Obligatorio en producción:**
- `APP_ENV=production`
- `APP_DEBUG=false`
- `APP_URL=https://…`
- `SESSION_SECURE=true`
- `STORAGE_PATH=/var/lib/conciliarh`

## 7. PHP

```bash
# cp /opt/conciliarh/current/deploy/php/conciliarh-production.ini /etc/php/8.4/fpm/conf.d/90-conciliarh.ini
# cp /opt/conciliarh/current/deploy/php/conciliarh-production.ini /etc/php/8.4/cli/conf.d/90-conciliarh.ini
# cp /opt/conciliarh/current/deploy/php/conciliarh-fpm.conf /etc/php/8.4/fpm/pool.d/conciliarh.conf
# rm -f /etc/php/8.4/fpm/pool.d/www.conf        # si el servidor no aloja otras aplicaciones PHP
# install -d /etc/systemd/system/php8.4-fpm.service.d
# cp /opt/conciliarh/current/deploy/systemd/php-fpm-conciliarh.conf /etc/systemd/system/php8.4-fpm.service.d/conciliarh.conf
# systemctl daemon-reload && systemctl restart php8.4-fpm
```

Ajuste `8.4` a la versión instalada. El pool:
- corre como `conciliarh-web` con `clear_env`;
- deshabilita la ejecución de comandos (`exec`, `proc_open`, …), `mail` y `putenv`;
- con el complemento de systemd, crea archivos sin permisos para «otros» (`UMask=0027`) y no ve procesos de otros usuarios (`ProtectProc=invisible`);
- limita `open_basedir`;
- envía los temporales de carga a `/var/lib/conciliarh/trabajo`, en el mismo sistema de archivos que los originales (movimiento atómico).

Con `opcache.validate_timestamps=0`, **recargue PHP-FPM en cada despliegue**.

## 8. Apache, HTTPS y proxy inverso

### 8.1 VirtualHost

```bash
# cp /opt/conciliarh/current/deploy/apache/conciliarh.conf /etc/apache2/sites-available/conciliarh.conf
# (edite ServerName, certificados y el socket de PHP-FPM)
# a2ensite conciliarh && a2dissite 000-default
# apachectl configtest && systemctl reload apache2
```

El VirtualHost:
- sirve **sólo** `public/` y ejecuta **sólo** `index.php`;
- deniega archivos ocultos;
- redirige HTTP a HTTPS (301);
- limita el cuerpo a 32 MiB;
- admite sólo `GET`/`HEAD`/`POST`;
- no registra cadenas de consulta en el log de acceso.

En la configuración global agregue `ServerTokens Prod`, `ServerSignature Off`, `TraceEnable Off` y `FileETag None`. En Debian/Ubuntu,
póngalos en `/etc/apache2/conf-available/zz-conciliarh.conf` y actívelo con `a2enconf zz-conciliarh`: el prefijo `zz-` hace que
se cargue **después** de `security.conf`, que de lo contrario vuelve a anunciar la versión (`ServerTokens OS`).

### 8.2 HTTPS y cabeceras

Use el certificado institucional, con TLS 1.2 y 1.3. La aplicación envía:
- CSP estricta (sin código en línea);
- `X-Frame-Options: DENY` y `frame-ancestors 'none'`;
- `X-Content-Type-Options: nosniff`;
- `Referrer-Policy: same-origin`;
- `Permissions-Policy`;
- COOP/CORP;
- `Cache-Control: no-store`;
- HSTS (`max-age=31536000; includeSubDomains`), también garantizado por Apache en respuestas que no pasan por PHP.

La cookie de sesión es `__Host-conciliarh_sid`, con `Secure`, `HttpOnly` y `SameSite=Lax`. Verifique las cabeceras con `curl -sI https://<servidor>/login` (§11).

### 8.3 Detrás de un balanceador o proxy inverso

- La aplicación usa **sólo** `REMOTE_ADDR` para la IP del cliente: bloqueo de login, auditoría y límites. **Nunca lee `X-Forwarded-For`.**
- Con un proxy delante, active `mod_remoteip` con `RemoteIPHeader X-Forwarded-For` y `RemoteIPInternalProxy <IP del proxy>`, que son las líneas comentadas del VirtualHost. Apache reemplaza `REMOTE_ADDR` por la IP real **sólo** si la petición viene de ese proxy; un cliente que envía su propio `X-Forwarded-For` no puede suplantar su IP.
- Si el proxy termina TLS, marque `HTTPS=on` sólo para las peticiones que vienen de él (línea `SetEnvIfExpr` del VirtualHost). Así se emiten HSTS y la cookie `Secure`.
- No use `RemoteIPTrustedProxy` con rangos amplios ni confíe en cabeceras de orígenes no controlados.

## 9. Worker (procesador en segundo plano)

El worker es PHP CLI: `/usr/bin/php /opt/conciliarh/current/bin/worker.php worker`, con `WorkingDirectory=/opt/conciliarh/current`. Usa `vendor/` del paquete (incluida PhpSpreadsheet para los libros `.xls`); no hay entorno virtual ni dependencias que instalar.

```bash
# php -m | grep -iE '^(gd|ctype|filter|libxml|simplexml|xml|xmlwriter|zlib|pcntl)$'   # nueve líneas
# cp /opt/conciliarh/current/deploy/systemd/conciliarh-worker.service /etc/systemd/system/
# systemctl daemon-reload && systemctl enable --now conciliarh-worker
# systemctl status conciliarh-worker      # «active (running)»; journalctl -u conciliarh-worker
```

Si el worker se activa antes de migrar (§11), falla y systemd lo reintenta cada 5 s hasta que exista el esquema: es
normal en una instalación nueva.

Órdenes del programa (`bin/worker.php`): `worker [--once]`, `run-job ID`, `reap` y `version`. En producción, la recuperación
manual de trabajos huérfanos se hace con `conciliarh-run worker-reap`, que ejecuta `reap` como `conciliarh-worker` con
`worker.env`.

`pcntl` es opcional: con ella, al recibir `SIGTERM` el worker termina el trabajo en curso y libera su *lease*; sin ella se
detiene de inmediato y el trabajo se recupera solo al vencer su latido. En Debian/Ubuntu viene en `php-cli`; el preflight la
reporta como advertencia si falta.

El worker:
- **no arranca** si le falta su rol, el anillo de cuentas, la llave HMAC o el almacenamiento: lo informa y sale con código 2;
- no toma trabajos durante el mantenimiento;
- al detenerse termina el trabajo en curso (con `pcntl`).

La unidad lo aísla:
- `ProtectSystem=strict`;
- originales de sólo lectura;
- `MemoryMax=2G`, `CPUQuota=200%`;
- sin privilegios nuevos.

## 10. Scheduler, verificación y respaldo (timers)

```bash
# cp /opt/conciliarh/current/deploy/systemd/conciliarh-{scheduler,storage-check,backup}.{service,timer} /etc/systemd/system/
# systemctl daemon-reload
# systemctl enable --now conciliarh-scheduler.timer conciliarh-storage-check.timer
# systemctl enable --now conciliarh-backup.timer          # después de configurar el respaldo (§15)
# systemctl list-timers 'conciliarh*'
```

- **Scheduler** (cada 5 min):
  - encola y genera reportes programados;
  - vence solicitudes de fusión y purga notificaciones;
  - no arranca sin su rol y su anillo de reportes;
  - no hace nada durante el mantenimiento.
- **Verificación de almacenamiento** (diaria, 03:30): SHA-256 de cada original y huérfanos. Con un código de salida distinto de 0 la unidad queda *failed*; conéctela a su sistema de alertas (`OnFailure=`) o al monitoreo de `systemctl --failed`.
- **Respaldo** (diario, 02:00): §15 y docs/BACKUP_RESTORE.md.

Las unidades usan systemd porque aísla cada proceso, entrega el entorno sin exponerlo y registra cada ejecución. Si el servidor no tuviera systemd, use cron con el mismo usuario y el archivo de entorno cargado por un envoltorio propio que no imprima secretos; ese escenario no está probado en esta versión.

## 11. Preflight, migraciones, GOD y verificación posterior

Todos los comandos de administración pasan por `conciliarh-run`. Este ejecuta el programa con el usuario y los archivos de entorno correctos mediante `systemd-run`, y **los secretos nunca aparecen en la línea de comandos**.

```bash
# conciliarh-run preflight                # 1. sólo lectura: PHP, extensiones, llaves, storage, BD, procesador. 0 errores para seguir
# conciliarh-run migrate status           #    valida las credenciales del PROPIETARIO (cli.env) y lista lo pendiente
# conciliarh-run migrate up               # 2. crea/actualiza el esquema (rol propietario)
# sudo -u postgres psql -d conciliarh -f /opt/conciliarh/current/deploy/postgres/02-despues-de-migrar.sql   # 3. (§3.4)
# conciliarh-run create-god               # 4. único GOD: pide usuario, nombre y contraseña sin eco
# systemctl reload php8.4-fpm apache2     # 5.
# conciliarh-run postdeploy               # 6. preflight + salud: worker con latido, migraciones al día, versión, mantenimiento apagado
```

**`preflight`** no modifica nada:
- abre la BD con `default_transaction_read_only = on`;
- sólo inspecciona el almacenamiento, sin crear archivos;
- nunca imprime secretos.

Sale con 0 si no hay errores y con 1 si hay alguno; `--json` produce salida para automatizar.

En una instalación nueva es normal que **`postdeploy`** advierta «scheduler sin ejecuciones» o «sin respaldo» hasta que corran los timers. Comprobación desde fuera:

```bash
curl -sI https://conciliarh.municipio.example/login | grep -iE 'strict-transport|content-security|x-frame|set-cookie'
curl -s  https://conciliarh.municipio.example/api/health          # {"success":true,"data":{"estado":"ok"},"message":null}
```

Después:
- Inicie sesión con el GOD y cambie la contraseña temporal si se le pide.
- Revise `/sistema/salud`.
- Cree los usuarios: el panel de administración.

## 12. Actualizar a una versión nueva y regresar (rollback)

Cada versión vive en su propia carpeta; `current` es un enlace. Así actualizar y regresar es cambiar un enlace, **más** la base de datos, que es la parte delicada.

**Actualización:**
1. Verifique e instale el paquete nuevo en `/opt/conciliarh/releases/<nueva>` (§2), sin tocar `current`.
2. Prepare el modo mantenimiento y detenga los servicios que escriben:
   ```bash
   # conciliarh-run maintenance on --motivo="Actualización a 0.1.0"
   # systemctl stop conciliarh-scheduler.timer conciliarh-worker
   ```
3. **Respaldo obligatorio antes de migrar** (no hay excepción):
   ```bash
   # systemctl start conciliarh-backup.service && systemctl status conciliarh-backup.service   # debe terminar «succeeded»
   ```
   Anote la carpeta del respaldo (`/var/backups/conciliarh/conciliarh-AAAAMMDDTHHMMSSZ`).
4. Cambie el enlace y vuelva a enlazar el `.env`:
   ```bash
   # ln -sfn /opt/conciliarh/releases/<nueva> /opt/conciliarh/current
   # ln -sfn /etc/conciliarh/web.env /opt/conciliarh/current/.env
   # install -m 0755 /opt/conciliarh/current/deploy/bin/conciliarh-run /usr/local/sbin/conciliarh-run
   ```
5. Revise las notas de la versión: variables nuevas en `.env.example` y unidades o permisos nuevos en `deploy/`.
6. Ejecute `conciliarh-run preflight`, `conciliarh-run migrate status` y luego `conciliarh-run migrate up`.
7. Reactive:
   ```bash
   # systemctl reload php8.4-fpm && systemctl start conciliarh-worker conciliarh-scheduler.timer
   # conciliarh-run maintenance off
   ```
8. Ejecute `conciliarh-run postdeploy` y revise `/sistema/salud` en la web.

**Servidor que tenía el worker en Python** (primera actualización a la versión con el procesador en PHP):
- Antes del paso 6 instale la extensión `gd` de la misma versión de PHP (`apt-get install php8.x-gd`) y confirme `pcntl` con `php -m`.
- En el paso 7, copie la unidad nueva y reinicie el worker en lugar de sólo iniciarlo:
  ```bash
  # cp /opt/conciliarh/current/deploy/systemd/conciliarh-worker.service /etc/systemd/system/
  # systemctl daemon-reload && systemctl restart conciliarh-worker
  ```
- `worker.env` no cambia y no hay secretos nuevos; la unidad ya no usa `CONCILIARH_BASE_PATH`.
- Cuando `/sistema/salud` muestre el latido reciente del procesador, puede borrar `/opt/conciliarh/venv`. Consérvelo mientras
  quiera poder regresar a una versión con el worker en Python: esa versión lo necesita.

**Rollback** (si la versión nueva falla):
- **Sin migraciones nuevas** en la versión: basta regresar el enlace `current` a la carpeta anterior, volver a enlazar el `.env` y recargar PHP-FPM y los servicios.
- **Con migraciones nuevas:** el esquema ya cambió y el código anterior puede no ser compatible.
  - **No confíe en las migraciones `down`**: en producción están bloqueadas y no recuperan datos que una migración transformó.
  - El rollback seguro es restaurar el respaldo del paso 3 en una base **nueva** (docs/RESTORE_RUNBOOK.md), regresar `current` a la versión anterior y apuntar `DB_DATABASE` a la base restaurada.
  - Se pierden los cambios hechos entre la actualización y el rollback; por eso la ventana con mantenimiento debe ser corta y verificarse antes de abrir a los usuarios.
- Conserve la base actualizada (renómbrela; no la borre) hasta confirmar la causa.

Mantenga al menos las **dos versiones anteriores** en `/opt/conciliarh/releases`.

**Desde 0.1.0-alpha.8** (sin paquete de producción ni modo mantenimiento; probado en la suite de pruebas, «Actualización alpha.8 → rc.1»):
- En el paso 2, en lugar de `maintenance on`, detenga también Apache: `systemctl stop apache2`. Vuelva a iniciarlo en el paso 7.
- En el paso 3, ejecute el respaldo con el `scripts/backup.sh` de la versión **nueva** ya descomprimida (el de alpha.8 no omite
  `imports/.staging`):
  ```bash
  # systemd-run --wait --pipe --collect --uid=conciliarh-backup --gid=conciliarh-backup -p SupplementaryGroups=conciliarh-data \
        -p EnvironmentFile=/etc/conciliarh/backup.env -E GNUPGHOME=/var/lib/conciliarh-backup/gnupg \
        /opt/conciliarh/releases/<nueva>/scripts/backup.sh
  ```
- La migración nueva es `0023` (endurecimiento de roles); no transforma datos.

## 13. Logs

| Log | Dónde | Contenido |
|---|---|---|
| Aplicación web | `/var/lib/conciliarh/logs/{app,security,import}.log` | JSON por línea; claves sensibles redactadas; cuentas, RFC y CURP enmascarados |
| CLI (scheduler, verificación, rotaciones) | `/var/lib/conciliarh/logs/{canal}-cli-{usuario}.log` | Ídem |
| Procesador en segundo plano | `/var/lib/conciliarh/logs/motor-cli-conciliarh-worker.log` y `journalctl -u conciliarh-worker` | Ídem |
| PHP-FPM | `/var/lib/conciliarh/logs/php-fpm-error.log` | Errores de PHP (sin `display_errors`) |
| Apache | `/var/log/apache2/conciliarh-{access,error}.log` | Acceso sin cadena de consulta |
| Timers | `journalctl -u conciliarh-scheduler -u conciliarh-storage-check -u conciliarh-backup` | Resultado de cada ejecución |

```bash
# cp /opt/conciliarh/current/deploy/logrotate/conciliarh /etc/logrotate.d/conciliarh
```

Rotación diaria, 30 días, comprimidos, con `copytruncate` (conserva dueño y modo `0640`; los servicios mantienen el archivo abierto). Los logs **no** son la auditoría: la auditoría vive en la BD con cadena de hash, se consulta en `/auditoria` y nunca se borra.

## 14. Monitoreo

| Qué | Cómo | Alerta si |
|---|---|---|
| Disponibilidad | `GET https://…/api/health` cada 1–5 min, sin sesión. Sólo dice `ok`; no revela versiones | No es 200, o responde 503 `MANTENIMIENTO` fuera de una ventana programada |
| Salud completa | `conciliarh-run postdeploy --json` cada 15 min desde el servidor (errores = salida 1) o `/sistema/salud` con una cuenta de sólo lectura con `sistema.salud` | Cualquier ERROR |
| Worker | `systemctl is-active conciliarh-worker` y latido en `/sistema/salud` | Inactivo, o sin latido más de 2 min con trabajos pendientes |
| Scheduler | `systemctl list-timers` y «scheduler» en la salud | Más de 15 min sin ejecución |
| Cola | «cola» en la salud | Trabajos atascados o con error en 24 h |
| Disco | Espacio de `/var/lib/conciliarh` y del destino de respaldos | Menos de 15 % (advertencia) o 10 % (error) libre |
| PostgreSQL | Servicio, conexiones por rol (`pg_stat_activity`), tamaño | Rol cerca de su `CONNECTION LIMIT` |
| Respaldo | Unidad `conciliarh-backup.service` y «respaldo» en la salud | Falló, o más de 26 h sin respaldo |
| Verificación de originales | Unidad `conciliarh-storage-check.service` | *failed*: un original falta o cambió (incidente) |
| Seguridad | `security.log` (accesos denegados, bloqueos de login) | Ráfagas de bloqueos o de 403 |
| Recuperación de emergencia | Auditoría, acciones `emergencia.god_*` | Cualquier aparición no planeada (docs/BREAK_GLASS_RECOVERY.md) |
| Ancla de auditoría | `conciliarh-run audit-anchor --verificar < última ancla` cada mes | `NO COINCIDE` (incidente) |

## 15. Política de respaldos

**Política recomendada** (a confirmar con el área de TI; no está implementada en el servidor todavía):

| Aspecto | Política |
|---|---|
| Frecuencia | Diario (02:00) con `conciliarh-backup.timer`, más uno **manual antes de cada actualización** (§12) |
| Contenido | BD completa (`pg_dump -Fc`), `imports/` (originales) y `reportes/generados/`, con manifiesto y `SHA256SUMS` |
| Cifrado | GPG en flujo con la llave **pública** del área responsable (`BACKUP_GPG_RECIPIENT`). La llave privada **no** vive en el servidor |
| Firma | `BACKUP_GPG_SIGNER`; la restauración exige la firma (`RESTORE_REQUIRE_SIGNATURE=1`) |
| Obligatorio | `BACKUP_REQUIRE_ENCRYPTION=1` (en la plantilla): sin las dos llaves el respaldo falla en lugar de quedar en claro |
| Retención local | 30 días (`BACKUP_RETENTION_DAYS=30`) |
| Fuera del sitio | Copia diaria a un destino institucional distinto (otro edificio o nube autorizada), retenida 12 meses: los diarios 30 días, uno semanal 3 meses y uno mensual 12 meses |
| Llaves de la aplicación | **No** van en el respaldo. Resguardo aparte (§6); sin ellas no se leen cuentas ni reportes cifrados |
| Prueba | Restauración de prueba **trimestral** en un entorno aislado (docs/RESTORE_RUNBOOK.md §4), con registro de tiempos (RTO) y del punto recuperado (RPO) |

**Configuración del usuario de respaldo:**
1. Importe en `GNUPGHOME=/var/lib/conciliarh-backup/gnupg` la llave pública de cifrado y la de firma, como usuario `conciliarh-backup`.
2. Cree `PGPASSFILE` con modo `0600`.
3. Complete `backup.env`.
4. Pruebe con `systemctl start conciliarh-backup.service`.

## 16. Conexiones a la BD y *poolers*

**Límites por rol** (`01-roles-y-base.sql`):

| Rol | Límite | Tiempo de espera |
|---|---|---|
| `conciliarh_web` | 60 conexiones (≥ `pm.max_children` del pool) | `statement_timeout` 300 s; sesión inactiva en transacción 60 s |
| `conciliarh_worker` | 10 | — |
| `conciliarh_scheduler` | 5 | — |
| `conciliarh_backup` | 3 | — |

La web abre **una conexión por petición** y la cierra al terminar, sin conexiones persistentes.

**Atribución del actor:**
- La identidad del usuario en la BD se fija **por conexión** con `set_config('conciliarh.actor_token', …, false)` al inicio de cada petición.
- La BD la deriva de la sesión con `fn_actor_actual()`, nunca de un parámetro: el token debe corresponder a una sesión **vigente**, no revocada, de un usuario activo y con actividad en las últimas 12 horas.
- Cada petición abre una conexión **nueva** (sin conexiones persistentes), así que ninguna hereda el actor de otra. En una misma conexión, una sesión revocada deja de atribuir y otra sesión reemplaza al actor anterior; está probado en `tests/Http/ReleaseHttpTest.php` y `tests/Http/ActorHttpTest.php`.

**Poolers:**
- **Permitido:** PgBouncer en modo **session**, o conexión directa (recomendado).
- **No permitido:** PgBouncer en modo **transaction** o **statement**. Con esos modos, el `set_config` de sesión de una petición podría quedar en una conexión que luego atiende la petición de **otro** usuario, o perderse entre sentencias, y la atribución del actor (auditoría y permisos en la BD) dejaría de ser confiable.
- Si en el futuro se requiere un pooler en modo transacción, la atribución debe migrarse a `SET LOCAL` dentro de una transacción por petición, como un cambio de diseño con su propia revisión de seguridad.

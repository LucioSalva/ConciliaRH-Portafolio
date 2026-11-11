#!/usr/bin/env bash
# ConciliaRH — usuarios del sistema operativo y permisos del almacenamiento (docs/DEPLOYMENT.md §4–5).
# Se ejecuta como root, una vez por servidor (idempotente). No contiene secretos.
#
#   STORAGE_PATH=/var/lib/conciliarh LOG_DIR=/var/lib/conciliarh/logs bash deploy/setup/preparar-sistema.sh
#
# Modelo (ningún servicio corre como root; el código es de root y sólo lectura para todos):
#   conciliarh-web    PHP-FPM: carga originales, exporta, descarga reportes        grupo conciliarh-data
#   conciliarh-worker procesador PHP CLI: LEE originales (nunca los modifica)       grupo conciliarh-data
#   conciliarh-sched  scheduler: genera reportes cifrados; rotación de su llave     grupo conciliarh-data
#   conciliarh-backup respaldo: LEE la BD (rol de sólo lectura) y el almacenamiento  grupo conciliarh-data
#   conciliarh-admin  comandos con credenciales del PROPIETARIO (migrar, GOD, rotaciones, mantenimiento): usuario propio
#                     para que ningún servicio pueda leer su entorno (/proc) mientras corren          grupo conciliarh-data
set -euo pipefail
[ "$(id -u)" = 0 ] || { echo "Ejecute como root." >&2; exit 1; }
STORAGE="${STORAGE_PATH:-/var/lib/conciliarh}"
case "$STORAGE" in /*) ;; *) echo "STORAGE_PATH debe ser absoluta." >&2; exit 1;; esac
case "$STORAGE" in */..*|*/.|"/") echo "STORAGE_PATH inválida." >&2; exit 1;; esac

getent group conciliarh-data >/dev/null || groupadd --system conciliarh-data
for u in conciliarh-web conciliarh-worker conciliarh-sched conciliarh-backup conciliarh-admin; do
  id "$u" >/dev/null 2>&1 || useradd --system --no-create-home --home-dir /nonexistent --shell /usr/sbin/nologin "$u"
  usermod -a -G conciliarh-data "$u"
done

# d <ruta> <dueño> <grupo> <modo>
d() { install -d -o "$2" -g "$3" -m "$4" "$1"; chown "$2:$3" "$1"; chmod "$4" "$1"; }
d "$STORAGE"                         root              conciliarh-data 2770   # mantenimiento.json (CLI)
d "$STORAGE/imports"                 conciliarh-web    conciliarh-data 2750   # originales: sólo la web escribe
d "$STORAGE/imports/.staging"        conciliarh-web    conciliarh-web  0700   # cargas en curso: privado de la web
d "$STORAGE/reportes"                conciliarh-sched  conciliarh-data 2750
d "$STORAGE/reportes/generados"      conciliarh-sched  conciliarh-data 2750   # cifrados: el scheduler escribe, la web lee
d "$STORAGE/trabajo"                 root              conciliarh-data 1770   # temporales 0600 de cada servicio (sticky)
d "$STORAGE/trabajo/reportes"        root              conciliarh-data 1770
d "$STORAGE/logs"                    root              conciliarh-data 3770   # cada proceso su archivo (0640); sticky: nadie borra los ajenos
d "$STORAGE/cuarentena"              conciliarh-web    conciliarh-data 2750   # huérfanos movidos por verificar-almacenamiento

# Originales existentes (reinstalación o restauración): archivos 0440, directorios 2750, dueño web
if [ -d "$STORAGE/imports" ]; then
  find "$STORAGE/imports" -path "$STORAGE/imports/.staging" -prune -o -type d -exec chown conciliarh-web:conciliarh-data {} + -exec chmod 2750 {} +
  find "$STORAGE/imports" -path "$STORAGE/imports/.staging" -prune -o -type f -exec chown conciliarh-web:conciliarh-data {} + -exec chmod 0440 {} +
  find "$STORAGE/reportes/generados" -type d -exec chown conciliarh-sched:conciliarh-data {} + -exec chmod 2750 {} +
  find "$STORAGE/reportes/generados" -type f -exec chown conciliarh-sched:conciliarh-data {} + -exec chmod 0640 {} +
fi

# 0711: PHP-FPM (conciliarh-web) debe ATRAVESAR el directorio para leer web.env; nadie puede listarlo y cada archivo
# conserva sus permisos (web.env 0640 root:conciliarh-web; los demás 0600 root, que sólo lee systemd).
install -d -o root -g root -m 0711 /etc/conciliarh
chmod 0711 /etc/conciliarh
# Respaldo: destino y llavero GPG propios del usuario de respaldo (sólo la llave PÚBLICA de cifrado y la de firma)
BACKUP_DIR="${BACKUP_DIR:-/var/backups/conciliarh}"
d "$BACKUP_DIR"                      conciliarh-backup conciliarh-backup 0700
d /var/lib/conciliarh-backup         conciliarh-backup conciliarh-backup 0700
d /var/lib/conciliarh-backup/gnupg   conciliarh-backup conciliarh-backup 0700
echo "Usuarios y permisos listos en $STORAGE."

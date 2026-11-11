#!/usr/bin/env bash
# Actualización alpha.8 → versión nueva según docs/DEPLOYMENT.md §12, con huellas antes/después y validación funcional.
set -euo pipefail
ZIP="$1"; V="$(basename "$ZIP" | sed 's/-production.zip//')"
paso() { echo "=== $(date +%T) $*"; }
huella() { (cd /tmp && sudo -u postgres psql -d conciliarh -XAt -F= < /root/huella-bd.sql); }
archivos() { (cd /var/lib/conciliarh && find imports reportes/generados -name .staging -prune -o -type f -print0 | sort -z | xargs -0 sha256sum); }
paso "Huella del entorno alpha.8"
huella > /root/huella-antes.txt; archivos > /root/archivos-antes.txt
paso "§12.1 instalar el paquete nuevo sin tocar current"
cd /root/paquete && sha256sum -c "$(basename "$ZIP").sha256"
[ -d "/opt/conciliarh/releases/$V" ] && mv "/opt/conciliarh/releases/$V" "/opt/conciliarh/releases/$V.previo-$(date +%s)"
unzip -q "$ZIP" -d /opt/conciliarh/releases/ && (cd /opt/conciliarh/releases/$V && sha256sum --quiet -c MANIFEST.sha256 && echo "Paquete íntegro")
chown -R root:root /opt/conciliarh/releases/$V; chmod -R u=rwX,go=rX /opt/conciliarh/releases/$V
paso "§12.2 detener la escritura (alpha.8 no tiene modo mantenimiento: se detiene Apache)"
systemctl stop apache2 conciliarh-scheduler.timer conciliarh-worker
paso "§12.3 respaldo obligatorio (con el backup.sh de la versión nueva)"
export GNUPGHOME=/root/gpg-area; install -d -m 0700 $GNUPGHOME
gpg --batch --quiet --pinentry-mode loopback --passphrase '' --quick-gen-key "Respaldo (prueba) <respaldo@prueba.local>" rsa3072 encr 1d
B=/var/lib/conciliarh-backup/gnupg; gpg --batch --export respaldo@prueba.local > /var/lib/conciliarh-backup/area.asc; chown conciliarh-backup /var/lib/conciliarh-backup/area.asc
runuser -u conciliarh-backup -- env GNUPGHOME=$B gpg --batch --quiet --import /var/lib/conciliarh-backup/area.asc
runuser -u conciliarh-backup -- env GNUPGHOME=$B gpg --batch --quiet --pinentry-mode loopback --passphrase '' --quick-gen-key "Firma (prueba) <firma@prueba.local>" rsa3072 sign 1d
printf '127.0.0.1:5432:conciliarh:conciliarh_backup:%s\n' "$(cat /root/pw-backup)" > /var/lib/conciliarh-backup/pgpass
chown conciliarh-backup:conciliarh-backup /var/lib/conciliarh-backup/pgpass; chmod 0600 /var/lib/conciliarh-backup/pgpass
sed -i 's|^BACKUP_GPG_RECIPIENT=.*|BACKUP_GPG_RECIPIENT=respaldo@prueba.local|; s|^BACKUP_GPG_SIGNER=.*|BACKUP_GPG_SIGNER=firma@prueba.local|' /etc/conciliarh/backup.env
systemd-run --wait --pipe --collect --uid=conciliarh-backup --gid=conciliarh-backup -p SupplementaryGroups=conciliarh-data \
  -p EnvironmentFile=/etc/conciliarh/backup.env -E GNUPGHOME=$B /opt/conciliarh/releases/$V/scripts/backup.sh 2>&1 | grep -E "Respaldo|FALL"
paso "§12.4 cambiar el enlace"
ln -sfn /opt/conciliarh/releases/$V /opt/conciliarh/current
ln -sfn /etc/conciliarh/web.env /opt/conciliarh/current/.env
install -m 0755 /opt/conciliarh/current/deploy/bin/conciliarh-run /usr/local/sbin/conciliarh-run
rm -f /etc/systemd/system/conciliarh-worker.service.d/alpha8.conf      # la RC ya tolera el .env de la web
cp /opt/conciliarh/current/deploy/systemd/conciliarh-*.{service,timer} /etc/systemd/system/; systemctl daemon-reload
paso "§12.6 preflight y migraciones"
conciliarh-run preflight | tail -1; conciliarh-run migrate status | tail -2
conciliarh-run migrate up | tail -3
(cd /tmp && sudo -u postgres psql -q -d conciliarh -f /opt/conciliarh/current/deploy/postgres/02-despues-de-migrar.sql)
paso "§12.7 reactivar"
systemctl reload php8.4-fpm; systemctl start apache2 conciliarh-worker conciliarh-scheduler.timer
sleep 4
paso "§12.8 post-despliegue"
conciliarh-run postdeploy | grep -E "✘|!|Resultado"
paso "Comparación (sin contar las migraciones nuevas)"
huella > /root/huella-despues.txt; archivos > /root/archivos-despues.txt
diff <(grep -v '^migraciones=' /root/huella-antes.txt) <(grep -v '^migraciones=' /root/huella-despues.txt) && echo "BD: datos IDÉNTICOS ($(grep -vc '^migraciones=' /root/huella-antes.txt) huellas)"
grep '^migraciones=' /root/huella-despues.txt
diff /root/archivos-antes.txt /root/archivos-despues.txt && echo "Archivos: IDÉNTICOS ($(wc -l < /root/archivos-antes.txt) SHA-256)"
conciliarh-run storage-check --hash | tail -1
cat /opt/conciliarh/current/VERSION; cat /opt/conciliarh/current/BUILD.json

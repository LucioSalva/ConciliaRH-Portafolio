#!/usr/bin/env bash
# Prueba de RESTAURACIÓN siguiendo docs/RESTORE_RUNBOOK.md §2 (mismo servidor, destinos nuevos) y comparación completa.
set -euo pipefail
paso() { echo "=== $(date +%T) $*"; }
huella() { (cd /tmp && sudo -u postgres psql -d "$1" -XAt -F= < /root/huella-bd.sql); }
archivos() { (cd "$1" && find imports reportes/generados -name .staging -prune -o -type f -print0 | sort -z | xargs -0 sha256sum); }
inicio=$(date +%s)
paso "§2.1 detener la escritura"
conciliarh-run maintenance on --motivo="Prueba de restauración (RC)"
systemctl stop conciliarh-scheduler.timer conciliarh-worker conciliarh-backup.timer
curl -sk -o /dev/null -w "web en mantenimiento: %{http_code}\n" https://127.0.0.1/login
paso "Respaldo de partida y huella del estado respaldado"
systemctl start conciliarh-backup.service
R=$(ls -d /var/backups/conciliarh/conciliarh-* | sort | tail -1); echo "Respaldo: $R"
huella conciliarh > /root/huella-antes.txt; archivos /var/lib/conciliarh > /root/archivos-antes.txt
paso "§2.2 verificar el respaldo sin restaurar (firma y sumas)"
export GNUPGHOME=/root/gpg-area; gpg --batch --quiet --import /root/firma-publica.asc 2>/dev/null || true
cp -r "$R" /root/respaldo-copia && cd /root/respaldo-copia && gpg --batch --verify SHA256SUMS.sig SHA256SUMS 2>&1 | grep -E "Good signature|Firma correcta|BAD" ; sha256sum -c SHA256SUMS
paso "§2.3 destinos vacíos (DBA)"
(cd /tmp && sudo -u postgres psql -q -v ON_ERROR_STOP=1 -d postgres -c "CREATE DATABASE conciliarh_r1 OWNER conciliarh_owner TEMPLATE template0 ENCODING 'UTF8'" \
  -c "REVOKE ALL ON DATABASE conciliarh_r1 FROM PUBLIC" \
  -c "GRANT CONNECT ON DATABASE conciliarh_r1 TO conciliarh_web, conciliarh_worker, conciliarh_scheduler, conciliarh_backup" \
  -c "ALTER DATABASE conciliarh_r1 SET timezone TO 'America/Mexico_City'")
(cd /tmp && sudo -u postgres psql -q -v ON_ERROR_STOP=1 -d conciliarh_r1 -c "ALTER SCHEMA public OWNER TO conciliarh_owner" \
  -c "REVOKE ALL ON SCHEMA public FROM PUBLIC" -c "GRANT USAGE ON SCHEMA public TO conciliarh_web, conciliarh_worker, conciliarh_scheduler, conciliarh_backup" \
  -c "GRANT pg_read_all_data TO conciliarh_backup")
install -d -o root -g conciliarh-data -m 2770 /var/lib/conciliarh-r1
sed -i 's/^host    conciliarh  /host    conciliarh,conciliarh_r1  /' /etc/postgresql/17/main/pg_hba.conf; systemctl reload postgresql
paso "§2.4 restaurar (restore.sh, credenciales del propietario por restore.env)"
umask 077; printf 'PGHOST=127.0.0.1\nPGPORT=5432\nPGUSER=conciliarh_owner\nPGPASSWORD=%s\n' "$(cat /root/pw-owner)" > /etc/conciliarh/restore.env; umask 022
systemd-run --wait --pipe --collect -p EnvironmentFile=/etc/conciliarh/restore.env \
  -E PGDATABASE=conciliarh_r1 -E CONCILIARH_STORAGE=/var/lib/conciliarh-r1 -E RESTORE_REQUIRE_SIGNATURE=1 -E GNUPGHOME=/root/gpg-area \
  /opt/conciliarh/current/scripts/restore.sh "$R"
paso "§2.5 permisos y cambio a los destinos restaurados (se conserva el original)"
mv /var/lib/conciliarh /var/lib/conciliarh-original; mv /var/lib/conciliarh-r1 /var/lib/conciliarh
STORAGE_PATH=/var/lib/conciliarh bash /opt/conciliarh/current/deploy/setup/preparar-sistema.sh
for f in web worker scheduler backup; do sed -i 's/^DB_DATABASE=conciliarh$/DB_DATABASE=conciliarh_r1/; s/^PGDATABASE=conciliarh$/PGDATABASE=conciliarh_r1/' /etc/conciliarh/$f.env; done
sed -i 's/:conciliarh:conciliarh_backup:/:conciliarh_r1:conciliarh_backup:/' /var/lib/conciliarh-backup/pgpass
(cd /tmp && sudo -u postgres psql -q -d conciliarh_r1 -f /opt/conciliarh/current/deploy/postgres/02-despues-de-migrar.sql)
conciliarh-run preflight | tail -1
conciliarh-run storage-check --hash | tail -4
paso "§2.6 reabrir"
systemctl reload php8.4-fpm; systemctl start conciliarh-worker conciliarh-scheduler.timer conciliarh-backup.timer
# El archivo de mantenimiento viajó en el almacenamiento original: el restaurado no lo tiene
conciliarh-run maintenance status || true
conciliarh-run maintenance off >/dev/null 2>&1 || true
sleep 3; conciliarh-run postdeploy | grep -E "✘|Resultado"
echo "RTO_SEGUNDOS=$(( $(date +%s) - inicio ))"
paso "Comparación con el estado respaldado"
huella conciliarh_r1 > /root/huella-despues.txt; archivos /var/lib/conciliarh > /root/archivos-despues.txt
diff /root/huella-antes.txt /root/huella-despues.txt && echo "BD: IDÉNTICA ($(wc -l < /root/huella-antes.txt) huellas)"
diff /root/archivos-antes.txt /root/archivos-despues.txt && echo "Archivos: IDÉNTICOS ($(wc -l < /root/archivos-antes.txt) SHA-256)"

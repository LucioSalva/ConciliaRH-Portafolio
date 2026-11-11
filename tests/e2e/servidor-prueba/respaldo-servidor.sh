#!/usr/bin/env bash
# Configura el respaldo como dice docs/DEPLOYMENT.md §15 y lo ejecuta con su timer/servicio. Las llaves GPG son de PRUEBA.
set -euo pipefail
paso() { echo "=== $(date +%T) $*"; }
paso "Llaves de prueba: el área responsable (privada fuera del servidor) y el firmante del respaldo"
export GNUPGHOME=/root/gpg-area; install -d -m 0700 $GNUPGHOME
gpg --batch --quiet --pinentry-mode loopback --passphrase '' --quick-gen-key "Respaldo ConciliaRH (prueba) <respaldo@prueba.local>" rsa3072 encr 1d
B=/var/lib/conciliarh-backup/gnupg
gpg --batch --export respaldo@prueba.local > /var/lib/conciliarh-backup/area-publica.asc; chown conciliarh-backup /var/lib/conciliarh-backup/area-publica.asc
runuser -u conciliarh-backup -- env GNUPGHOME=$B gpg --batch --quiet --import /var/lib/conciliarh-backup/area-publica.asc
runuser -u conciliarh-backup -- env GNUPGHOME=$B gpg --batch --quiet --pinentry-mode loopback --passphrase '' --quick-gen-key "Firma respaldo (prueba) <firma@prueba.local>" rsa3072 sign 1d
runuser -u conciliarh-backup -- env GNUPGHOME=$B gpg --batch --export firma@prueba.local > /root/firma-publica.asc
paso "PGPASSFILE y backup.env"
printf '127.0.0.1:5432:conciliarh:conciliarh_backup:%s\n' "$(cat /root/pw-backup)" > /var/lib/conciliarh-backup/pgpass
chown conciliarh-backup:conciliarh-backup /var/lib/conciliarh-backup/pgpass; chmod 0600 /var/lib/conciliarh-backup/pgpass
sed -i 's|^BACKUP_GPG_RECIPIENT=.*|BACKUP_GPG_RECIPIENT=respaldo@prueba.local|; s|^BACKUP_GPG_SIGNER=.*|BACKUP_GPG_SIGNER=firma@prueba.local|' /etc/conciliarh/backup.env
paso "Respaldo (systemctl start conciliarh-backup.service)"
systemctl daemon-reload; systemctl enable --now conciliarh-backup.timer >/dev/null 2>&1
systemctl start conciliarh-backup.service; systemctl show -p Result --value conciliarh-backup.service
journalctl -u conciliarh-backup -n 15 --no-pager | tail -15
ls -la /var/backups/conciliarh/*/

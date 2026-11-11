#!/usr/bin/env bash
# Guion de PRUEBA: ejecuta docs/DEPLOYMENT.md §1–11 al pie de la letra dentro del servidor de prueba (Debian 13 + systemd).
# Lo que la guía deja al operador (contraseñas, llaves, certificado, ServerName) se simula con valores generados aquí.
set -euo pipefail
ZIP="$1"; V="$(basename "$ZIP" | sed 's/-production.zip//')"
paso() { echo "=== $(date +%T) $*"; }
paso "§1 módulos de Apache"; a2enmod ssl headers rewrite proxy proxy_fcgi setenvif reqtimeout >/dev/null
paso "§2 paquete"; cd "$(dirname "$ZIP")"; sha256sum -c "$(basename "$ZIP").sha256"
install -d -m 0755 /opt/conciliarh/releases; unzip -q "$ZIP" -d /opt/conciliarh/releases/
(cd /opt/conciliarh/releases/$V && sha256sum --quiet -c MANIFEST.sha256 && echo "Paquete íntegro")
chown -R root:root /opt/conciliarh/releases/$V; chmod -R u=rwX,go=rX /opt/conciliarh/releases/$V
ln -sfn /opt/conciliarh/releases/$V /opt/conciliarh/current
install -m 0755 /opt/conciliarh/current/deploy/bin/conciliarh-run /usr/local/sbin/conciliarh-run
paso "§3.1 roles y base"; umask 077; for r in owner web worker scheduler backup; do openssl rand -base64 33 | tr -d "\n" > /root/pw-$r; done; umask 022
(cd /tmp && sudo -u postgres psql -q -v ON_ERROR_STOP=1 -d postgres -v db=conciliarh -f /opt/conciliarh/current/deploy/postgres/01-roles-y-base.sql)
# Equivalente no interactivo de «\password»: la contraseña viaja por stdin, nunca por la línea de comandos
for r in owner web worker scheduler backup; do printf "ALTER ROLE conciliarh_%s PASSWORD '%s';\n" "$r" "$(cat /root/pw-$r)" | (cd /tmp && sudo -u postgres psql -q -d postgres); done
paso "§3.2 pg_hba"; H=/etc/postgresql/17/main/pg_hba.conf; cp $H $H.orig; grep -vE "^host\s+all\s+all\s+(127\.0\.0\.1/32|::1/128)" $H.orig > $H
for r in web worker scheduler owner backup; do printf 'host    conciliarh  conciliarh_%-10s 127.0.0.1/32   scram-sha-256\n' "$r" >> $H; done; systemctl reload postgresql
paso "§4–5 usuarios y almacenamiento"; STORAGE_PATH=/var/lib/conciliarh bash /opt/conciliarh/current/deploy/setup/preparar-sistema.sh
paso "§6 entornos y llaves"; cd /opt/conciliarh/current/deploy/env
for f in web worker scheduler cli backup; do install -m 0600 -o root -g root $f.env.example /etc/conciliarh/$f.env; done
chown root:conciliarh-web /etc/conciliarh/web.env && chmod 0640 /etc/conciliarh/web.env
ln -sfn /etc/conciliarh/web.env /opt/conciliarh/current/.env
APP_KEY=$(openssl rand -hex 32); ENC=$(openssl rand -hex 32); HM=$(openssl rand -hex 32); REP=$(openssl rand -hex 32)
set_kv() { sed -i "s|^$2=.*|$2=$3|" /etc/conciliarh/$1.env; }
for f in web scheduler; do set_kv $f APP_URL https://conciliarh.prueba.local; done
set_kv web APP_KEY "$APP_KEY"; set_kv web ACCOUNT_ENC_KEYS "k1:$ENC"; set_kv web REPORT_ENC_KEYS "r1:$REP"; set_kv web DB_PASSWORD "$(cat /root/pw-web)"
set_kv worker ACCOUNT_ENC_KEYS "k1:$ENC"; set_kv worker ACCOUNT_HMAC_KEYS "h1:$HM"; set_kv worker WORKER_DB_PASSWORD "$(cat /root/pw-worker)"
set_kv scheduler REPORT_ENC_KEYS "r1:$REP"; set_kv scheduler SCHEDULER_DB_PASSWORD "$(cat /root/pw-scheduler)"
set_kv cli DB_MIGRATE_PASSWORD "$(cat /root/pw-owner)"
paso "§7 PHP"; cp /opt/conciliarh/current/deploy/php/conciliarh-production.ini /etc/php/8.4/fpm/conf.d/90-conciliarh.ini
cp /opt/conciliarh/current/deploy/php/conciliarh-production.ini /etc/php/8.4/cli/conf.d/90-conciliarh.ini
cp /opt/conciliarh/current/deploy/php/conciliarh-fpm.conf /etc/php/8.4/fpm/pool.d/conciliarh.conf; rm -f /etc/php/8.4/fpm/pool.d/www.conf
install -d /etc/systemd/system/php8.4-fpm.service.d
cp /opt/conciliarh/current/deploy/systemd/php-fpm-conciliarh.conf /etc/systemd/system/php8.4-fpm.service.d/conciliarh.conf
systemctl daemon-reload && systemctl restart php8.4-fpm
paso "§8 Apache"; openssl req -x509 -newkey rsa:2048 -nodes -days 30 -subj "/CN=conciliarh.prueba.local" \
   -keyout /etc/ssl/private/conciliarh.key -out /etc/ssl/certs/conciliarh.crt 2>/dev/null
cp /opt/conciliarh/current/deploy/apache/conciliarh.conf /etc/apache2/sites-available/conciliarh.conf
sed -i "s/conciliarh.municipio.example/conciliarh.prueba.local/" /etc/apache2/sites-available/conciliarh.conf
printf "ServerName conciliarh.prueba.local\nServerTokens Prod\nServerSignature Off\nTraceEnable Off\nFileETag None\n" > /etc/apache2/conf-available/zz-conciliarh.conf
a2enconf zz-conciliarh >/dev/null; a2ensite conciliarh >/dev/null; a2dissite 000-default >/dev/null
apachectl configtest; systemctl reload apache2
paso "§9 worker"; test "$(php -m | grep -ciE '^(gd|ctype|filter|libxml|simplexml|xml|xmlwriter|zlib|pcntl)$')" = 9
cp /opt/conciliarh/current/deploy/systemd/conciliarh-worker.service /etc/systemd/system/
systemctl daemon-reload && systemctl enable --now conciliarh-worker
paso "§10 timers"; cp /opt/conciliarh/current/deploy/systemd/conciliarh-{scheduler,storage-check,backup}.{service,timer} /etc/systemd/system/
systemctl daemon-reload; systemctl enable --now conciliarh-scheduler.timer conciliarh-storage-check.timer
paso "§11.1 preflight"; conciliarh-run preflight; conciliarh-run migrate status | tail -1
paso "§11.2 migrate"; conciliarh-run migrate up
paso "§11.3 permisos después de migrar"; (cd /tmp && sudo -u postgres psql -q -v ON_ERROR_STOP=1 -d conciliarh -f /opt/conciliarh/current/deploy/postgres/02-despues-de-migrar.sql)
paso "§11.4 GOD"; printf '%s\n' "$(cat /root/god-pass)" | conciliarh-run create-god --username=god.prueba --nombre="GOD de Prueba" --password-stdin
paso "§11.5 recarga"; systemctl reload php8.4-fpm apache2
paso "§11.6 postdeploy"; conciliarh-run postdeploy || true
paso "fin"

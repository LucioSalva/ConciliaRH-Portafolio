#!/usr/bin/env bash
# Entorno alpha.8 REALISTA: código del tag v0.1.0-alpha.8 sobre la misma infraestructura de operación (deploy/ de la RC).
set -euo pipefail
A8=/root/paquete/conciliarh-0.1.0-alpha.8-codigo.tar.gz; RC="$1"
paso() { echo "=== $(date +%T) $*"; }
a2enmod ssl headers rewrite proxy proxy_fcgi setenvif reqtimeout >/dev/null
mkdir -p /opt/rc-infra && unzip -q "$RC" -d /opt/rc-infra && INF=$(ls -d /opt/rc-infra/conciliarh-*)/deploy
install -d -m 0755 /opt/conciliarh/releases; tar -xzf $A8 -C /opt/conciliarh/releases/
chown -R root:root /opt/conciliarh/releases/conciliarh-0.1.0-alpha.8; chmod -R u=rwX,go=rX /opt/conciliarh/releases/conciliarh-0.1.0-alpha.8
ln -sfn /opt/conciliarh/releases/conciliarh-0.1.0-alpha.8 /opt/conciliarh/current
install -m 0755 $INF/bin/conciliarh-run /usr/local/sbin/conciliarh-run
umask 077; for r in owner web worker scheduler backup; do openssl rand -base64 33 | tr -d "\n" > /root/pw-$r; done; umask 022
(cd /tmp && sudo -u postgres psql -q -v ON_ERROR_STOP=1 -d postgres -v db=conciliarh -f $INF/postgres/01-roles-y-base.sql)
# Equivalente no interactivo de «\password»: la contraseña viaja por stdin, nunca por la línea de comandos
for r in owner web worker scheduler backup; do printf "ALTER ROLE conciliarh_%s PASSWORD '%s';\n" "$r" "$(cat /root/pw-$r)" | (cd /tmp && sudo -u postgres psql -q -d postgres); done
H=/etc/postgresql/17/main/pg_hba.conf; grep -vE "^host\s+all\s+all\s+(127\.0\.0\.1/32|::1/128)" $H > $H.n && mv $H.n $H
for r in web worker scheduler owner backup; do printf 'host    conciliarh  conciliarh_%-10s 127.0.0.1/32   scram-sha-256\n' "$r" >> $H; done; systemctl reload postgresql
STORAGE_PATH=/var/lib/conciliarh bash $INF/setup/preparar-sistema.sh
for f in web worker scheduler cli backup; do install -m 0600 -o root -g root $INF/env/$f.env.example /etc/conciliarh/$f.env; done
chown root:conciliarh-web /etc/conciliarh/web.env && chmod 0640 /etc/conciliarh/web.env; ln -sfn /etc/conciliarh/web.env /opt/conciliarh/current/.env
APP_KEY=$(openssl rand -hex 32); ENC=$(openssl rand -hex 32); HM=$(openssl rand -hex 32); REP=$(openssl rand -hex 32)
set_kv() { sed -i "s|^$2=.*|$2=$3|" /etc/conciliarh/$1.env; }
for f in web scheduler; do set_kv $f APP_URL https://conciliarh.prueba.local; done
set_kv web APP_KEY "$APP_KEY"; set_kv web ACCOUNT_ENC_KEYS "k1:$ENC"; set_kv web REPORT_ENC_KEYS "r1:$REP"; set_kv web DB_PASSWORD "$(cat /root/pw-web)"
set_kv worker ACCOUNT_ENC_KEYS "k1:$ENC"; set_kv worker ACCOUNT_HMAC_KEYS "h1:$HM"; set_kv worker WORKER_DB_PASSWORD "$(cat /root/pw-worker)"
set_kv scheduler REPORT_ENC_KEYS "r1:$REP"; set_kv scheduler SCHEDULER_DB_PASSWORD "$(cat /root/pw-scheduler)"
set_kv cli DB_MIGRATE_PASSWORD "$(cat /root/pw-owner)"
cp $INF/php/conciliarh-production.ini /etc/php/8.4/fpm/conf.d/90-conciliarh.ini; cp $INF/php/conciliarh-production.ini /etc/php/8.4/cli/conf.d/90-conciliarh.ini
cp $INF/php/conciliarh-fpm.conf /etc/php/8.4/fpm/pool.d/conciliarh.conf; rm -f /etc/php/8.4/fpm/pool.d/www.conf; systemctl restart php8.4-fpm
openssl req -x509 -newkey rsa:2048 -nodes -days 30 -subj "/CN=conciliarh.prueba.local" -keyout /etc/ssl/private/conciliarh.key -out /etc/ssl/certs/conciliarh.crt 2>/dev/null
cp $INF/apache/conciliarh.conf /etc/apache2/sites-available/conciliarh.conf; sed -i "s/conciliarh.municipio.example/conciliarh.prueba.local/" /etc/apache2/sites-available/conciliarh.conf
printf "ServerName conciliarh.prueba.local\nServerTokens Prod\nServerSignature Off\nTraceEnable Off\nFileETag None\n" > /etc/apache2/conf-available/zz-conciliarh.conf
a2enconf zz-conciliarh >/dev/null; a2ensite conciliarh >/dev/null; a2dissite 000-default >/dev/null; systemctl reload apache2
apt-get install -y -q python3 python3-venv >/dev/null; python3 -m venv /opt/conciliarh/venv; /opt/conciliarh/venv/bin/pip install -q --no-cache-dir -r /opt/conciliarh/current/python/requirements.txt
cp $INF/systemd/conciliarh-*.{service,timer} /etc/systemd/system/
# alpha.8 lee <instalación>/.env sin tolerar que no sea legible (corregido en la RC): su worker apunta a una base sin .env
install -d /etc/systemd/system/conciliarh-worker.service.d
printf '[Service]\nEnvironment=CONCILIARH_BASE_PATH=/opt/conciliarh\n' > /etc/systemd/system/conciliarh-worker.service.d/alpha8.conf
systemctl daemon-reload
paso "migraciones de alpha.8 y GOD"
conciliarh-run migrate up | tail -2
(cd /tmp && sudo -u postgres psql -q -d conciliarh -f $INF/postgres/02-despues-de-migrar.sql)
printf '%s\n' "$(cat /root/god-pass)" | conciliarh-run create-god --username=god.prueba --nombre="GOD de Prueba" --password-stdin
systemctl enable --now conciliarh-worker conciliarh-scheduler.timer conciliarh-storage-check.timer >/dev/null 2>&1
systemctl reload php8.4-fpm apache2
paso "alpha.8 instalado: $(cat /opt/conciliarh/current/VERSION)"

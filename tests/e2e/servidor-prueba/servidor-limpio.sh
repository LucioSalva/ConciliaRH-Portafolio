#!/usr/bin/env bash
# Crea un servidor de prueba LIMPIO (Debian 13 + systemd) e instala el ZIP indicado con el guion de DEPLOYMENT.md.
set -euo pipefail
ZIP="$1"; S="$(cd "$(dirname "$0")" && pwd)"; export DOCKER_CONTEXT=default; C="${CRH_CONTAINER:-crh-rc}"
docker rm -f "$C" >/dev/null 2>&1 || true
docker run -d --name "$C" --hostname conciliarh-rc --privileged --cgroupns=host -v /sys/fs/cgroup:/sys/fs/cgroup:rw --tmpfs /run --tmpfs /run/lock --tmpfs /tmp \
  -p 127.0.0.1:${CRH_HTTPS_PORT:-18443}:443 -p 127.0.0.1:${CRH_HTTP_PORT:-18080}:80 conciliarh-rc-server:debian13 >/dev/null
for i in $(seq 1 30); do docker exec "$C" systemctl is-system-running 2>/dev/null | grep -qE "running|degraded" && break; sleep 1; done
docker exec "$C" mkdir -p /root/paquete
docker cp "$ZIP" "$C":/root/paquete/; docker cp "$ZIP.sha256" "$C":/root/paquete/; docker cp "$S/instalar-segun-deployment.sh" "$C":/root/
docker exec "$C" bash -c 'umask 077; printf "Rc1-%s-Prueba!" "$(openssl rand -hex 8)" > /root/god-pass'
inicio=$(date +%s)
docker exec "$C" bash /root/instalar-segun-deployment.sh "/root/paquete/$(basename "$ZIP")"
echo "INSTALACION_SEGUNDOS=$(( $(date +%s) - inicio ))"
# La contraseña de prueba NUNCA se escribe dentro del repositorio: va a GOD_PASS_FILE (por omisión, un temporal privado)
GOD_PASS_FILE="${GOD_PASS_FILE:-${TMPDIR:-/tmp}/conciliarh-rc-god-pass}"
(umask 077; docker exec "$C" cat /root/god-pass > "$GOD_PASS_FILE"); echo "Contraseña GOD de prueba en $GOD_PASS_FILE (0600)"
for _ in $(seq 1 30); do docker exec "$C" conciliarh-run postdeploy --json 2>/dev/null | grep -q '"chequeo": "workers"' && \
   docker exec "$C" conciliarh-run postdeploy --json | jq -e 'any(.chequeos[]; .chequeo=="workers" and .estado=="OK")' >/dev/null && break; sleep 2; done

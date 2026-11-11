#!/usr/bin/env bash
# =====================================================================
# ConciliaRH — Respaldo (docs/BACKUP_RESTORE.md). NO contiene secretos: todo llega por el entorno.
#
#   PGHOST PGPORT PGDATABASE PGUSER   conexión (PGPASSWORD o ~/.pgpass; nunca en este archivo)
#   CONCILIARH_STORAGE                 raíz de storage/ de la aplicación (p. ej. /opt/conciliarh/storage)
#   BACKUP_DIR                         destino (disco cifrado o montaje exclusivo del respaldo)
#   CONCILIARH_BASE (opcional)         raíz de la aplicación (para VERSION); por omisión, la del script
#   BACKUP_GPG_RECIPIENT (opcional)    cifra EN FLUJO (sin claro en disco) la BD y el storage con la llave PÚBLICA indicada (gpg)
#   BACKUP_GPG_SIGNER (opcional)       firma SHA256SUMS con esa llave PRIVADA (firma separada); restore.sh la verifica
#   BACKUP_REQUIRE_ENCRYPTION=1        (producción) se niega a respaldar sin BACKUP_GPG_RECIPIENT y BACKUP_GPG_SIGNER
#   BACKUP_RETENTION_DAYS (opcional)   borra respaldos «conciliarh-*» de BACKUP_DIR más antiguos (sólo dentro de BACKUP_DIR)
#
# Incluye: base de datos completa (pg_dump -Fc: datos, auditoría, reportes, identidades), originales importados (storage/imports,
# inmutables), reportes generados persistentes (storage/reportes/generados, los sensibles ya cifrados) y configuración NO secreta
# (VERSION y la LISTA de variables requeridas, sin valores). Excluye temporales (trabajo, cache), logs y credenciales de desarrollo.
# Salida: BACKUP_DIR/conciliarh-AAAAMMDDTHHMMSSZ/{db.dump, storage.tar, manifest.json, SHA256SUMS}. Código 0 = respaldo verificado.
# =====================================================================
set -euo pipefail
umask 077

: "${PGHOST:?PGHOST requerido}" "${PGDATABASE:?PGDATABASE requerido}" "${PGUSER:?PGUSER requerido}"
: "${CONCILIARH_STORAGE:?CONCILIARH_STORAGE requerido}" "${BACKUP_DIR:?BACKUP_DIR requerido}"
BASE="${CONCILIARH_BASE:-$(cd "$(dirname "$0")/.." && pwd)}"
for bin in pg_dump pg_restore psql tar sha256sum; do
  command -v "$bin" >/dev/null || { echo "Falta $bin en PATH" >&2; exit 1; }
done
if [ "${BACKUP_REQUIRE_ENCRYPTION:-0}" = "1" ] && { [ -z "${BACKUP_GPG_RECIPIENT:-}" ] || [ -z "${BACKUP_GPG_SIGNER:-}" ]; }; then
  echo "BACKUP_REQUIRE_ENCRYPTION=1: configure BACKUP_GPG_RECIPIENT y BACKUP_GPG_SIGNER (un respaldo en claro no se genera)." >&2; exit 1
fi
[ -d "$CONCILIARH_STORAGE/imports" ] || { echo "CONCILIARH_STORAGE no parece la raíz de storage/ (falta imports/)" >&2; exit 1; }

inicio="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
ts="$(date -u +%Y%m%dT%H%M%SZ)"
dest="$BACKUP_DIR/conciliarh-$ts"
mkdir -p "$dest"
registrar() {   # salud del sistema: sólo resultado y conteos (nunca rutas)
  psql -X -q -v ON_ERROR_STOP=1 -At -c "SELECT fn_sistema_ejecucion('RESPALDO', 'CLI', '$inicio'::timestamptz, '$1', '$2'::jsonb)" >/dev/null 2>&1 || true
}
trap 'echo "Respaldo FALLIDO; se conserva lo generado en $dest para diagnóstico." >&2; registrar ERROR "{\"etapa\": \"$etapa\"}"' ERR
etapa=db

gpgenc() { gpg --batch --yes --auto-key-locate local --trust-model always --recipient "$BACKUP_GPG_RECIPIENT" --encrypt; }
# 1. Base de datos (formato custom: restauración selectiva y paralela; incluye privilegios de los roles de la aplicación)
if [ -n "${BACKUP_GPG_RECIPIENT:-}" ]; then
  pg_dump --format=custom --compress=6 --no-password | gpgenc > "$dest/db.dump.gpg"
else
  pg_dump --format=custom --compress=6 --no-password --file "$dest/db.dump"
fi

# 2. Almacenamiento requerido (rutas relativas; sin seguir enlaces simbólicos)
etapa=storage
incl=(imports)
[ -d "$CONCILIARH_STORAGE/reportes/generados" ] && incl+=(reportes/generados)
if [ -n "${BACKUP_GPG_RECIPIENT:-}" ]; then
  tar --create --file - --directory "$CONCILIARH_STORAGE" --exclude='.staging' --exclude='*.tmp' --no-same-owner "${incl[@]}" | gpgenc > "$dest/storage.tar.gpg"
  # -prune: .staging es privado de la web (0700) y el usuario de respaldo no debe ni entrar (docs/DEPLOYMENT.md §5)
  nfiles="$(find "${incl[@]/#/$CONCILIARH_STORAGE/}" -name .staging -prune -o -type f ! -name "*.tmp" -print | wc -l)"
else
  tar --create --file "$dest/storage.tar" --directory "$CONCILIARH_STORAGE" \
      --exclude='.staging' --exclude='*.tmp' --no-same-owner "${incl[@]}"
  nfiles="$(tar --list --file "$dest/storage.tar" | grep -vc '/$' || true)"
fi

# 3. Manifiesto (versiones, migraciones y conteos; sin datos personales ni secretos)
etapa=manifiesto
version="$(cat "$BASE/VERSION" 2>/dev/null || echo desconocida)"
migraciones="$(psql -X -At -c "SELECT string_agg(version, ',' ORDER BY version) FROM schema_migraciones")"
archivos="$(psql -X -At -c "SELECT count(*) FROM archivos_importados WHERE estado <> 'ANULADO'")"
reportes="$(psql -X -At -c "SELECT count(*) FROM reportes_generados WHERE estado = 'DISPONIBLE'")"
pgver="$(psql -X -At -c "SHOW server_version")"
cat > "$dest/manifest.json" <<JSON
{
  "sistema": "ConciliaRH",
  "version": "$version",
  "creado_utc": "$inicio",
  "postgresql": "$pgver",
  "base_datos": "$PGDATABASE",
  "migraciones": "$migraciones",
  "archivos_importados_vigentes": ${archivos:-0},
  "reportes_disponibles": ${reportes:-0},
  "archivos_en_storage_tar": ${nfiles:-0},
  "incluye": ["db.dump", "storage.tar (${incl[*]})"],
  "cifrado_gpg": $([ -n "${BACKUP_GPG_RECIPIENT:-}" ] && echo true || echo false),
  "variables_requeridas_sin_valor": ["APP_KEY", "ACCOUNT_ENC_KEY_ID", "ACCOUNT_ENC_KEYS", "ACCOUNT_HMAC_KEYS", "REPORT_ENC_KEY_ID", "REPORT_ENC_KEYS",
                                     "DB_PASSWORD", "WORKER_DB_PASSWORD", "SCHEDULER_DB_PASSWORD", "DB_MIGRATE_PASSWORD"],
  "nota": "Las llaves NO van en el respaldo. Sin ACCOUNT_ENC_KEYS y REPORT_ENC_KEYS las cuentas y los reportes cifrados no se pueden leer: resguárdelas aparte (docs/BACKUP_RESTORE.md §4)."
}
JSON

# 4. Integridad
etapa=integridad
if [ -n "${BACKUP_GPG_RECIPIENT:-}" ]; then
  (cd "$dest" && sha256sum db.dump.gpg storage.tar.gpg manifest.json > SHA256SUMS && sha256sum --quiet -c SHA256SUMS)
else
  (cd "$dest" && sha256sum db.dump storage.tar manifest.json > SHA256SUMS && sha256sum --quiet -c SHA256SUMS)
  pg_restore --list "$dest/db.dump" >/dev/null   # el volcado es legible
fi

# 5. Firma (autenticidad): sin ella, quien pueda escribir en BACKUP_DIR podría sustituir el volcado Y sus sumas
if [ -n "${BACKUP_GPG_SIGNER:-}" ]; then
  etapa=firma
  gpg --batch --yes --local-user "$BACKUP_GPG_SIGNER" --output "$dest/SHA256SUMS.sig" --detach-sign "$dest/SHA256SUMS"
fi

# 6. Retención (sólo directorios de respaldo dentro de BACKUP_DIR)
if [ -n "${BACKUP_RETENTION_DAYS:-}" ] && [ "$BACKUP_RETENTION_DAYS" -ge 1 ] 2>/dev/null; then
  find "$BACKUP_DIR" -mindepth 1 -maxdepth 1 -type d -name 'conciliarh-*' -mtime +"$BACKUP_RETENTION_DAYS" -print -exec rm -rf {} +
fi

trap - ERR
bytes="$(du -sb "$dest" | cut -f1)"
registrar OK "{\"bytes\": $bytes, \"archivos\": $nfiles, \"migraciones\": $(echo "$migraciones" | tr ',' '\n' | grep -c .), \"cifrado_gpg\": $([ -n "${BACKUP_GPG_RECIPIENT:-}" ] && echo true || echo false), \"firmado\": $([ -n "${BACKUP_GPG_SIGNER:-}" ] && echo true || echo false)}"
echo "Respaldo verificado: $dest ($bytes bytes; $nfiles archivo(s) de storage)."

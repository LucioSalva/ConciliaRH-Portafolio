#!/usr/bin/env bash
# =====================================================================
# ConciliaRH — Restauración (docs/BACKUP_RESTORE.md). Sólo sobre destinos VACÍOS: nunca sobrescribe una instalación.
#
#   scripts/restore.sh <directorio-del-respaldo>
#
#   PGHOST PGPORT PGDATABASE PGUSER   BD DESTINO: debe existir, estar VACÍA y pertenecer a conciliarh_owner (la crea el DBA);
#                                      los roles conciliarh_web/_worker/_scheduler deben existir en el clúster destino.
#   CONCILIARH_STORAGE                 raíz de storage/ DESTINO: debe estar vacía (o no existir)
#   (si el respaldo está cifrado con gpg, la llave privada debe estar disponible en el llavero de quien restaura)
#   RESTORE_REQUIRE_SIGNATURE=1         exige SHA256SUMS.sig válida (recomendado en producción; llave pública del firmante en el llavero)
#   RESTORE_SIGNER_FPR=<huella>          además exige que la firma sea de ESA llave (huella completa, 40 hexadecimales)
#   El usuario de BD NO debe ser superusuario: el volcado se ejecuta con sus privilegios (use conciliarh_owner).
#
# Verifica SHA256SUMS ANTES de tocar nada, restaura en UNA transacción (todo o nada) y comprueba después:
# migraciones iguales al manifiesto, cadena de auditoría íntegra y SHA-256 de cada original contra la BD.
# =====================================================================
set -euo pipefail
umask 027

src="${1:?Uso: scripts/restore.sh <directorio-del-respaldo>}"
: "${PGHOST:?}" "${PGDATABASE:?}" "${PGUSER:?}" "${CONCILIARH_STORAGE:?}"
for bin in pg_restore psql tar sha256sum; do
  command -v "$bin" >/dev/null || { echo "Falta $bin en PATH" >&2; exit 1; }
done
[ -d "$src" ] || { echo "No existe el respaldo $src" >&2; exit 1; }

work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT
# 1. Autenticidad e integridad ANTES de conectarse, descifrar o tocar nada (las sumas cubren los artefactos tal como se guardaron)
if [ -f "$src/SHA256SUMS.sig" ]; then
  estado="$(gpg --batch --status-fd 1 --verify "$src/SHA256SUMS.sig" "$src/SHA256SUMS" 2>/dev/null)" \
    || { echo "La firma del respaldo NO es válida: no se restaura." >&2; exit 2; }
  # Con RESTORE_SIGNER_FPR sólo vale la llave del firmante institucional (no cualquiera del llavero)
  if [ -n "${RESTORE_SIGNER_FPR:-}" ] && ! grep -q "^\[GNUPG:\] VALIDSIG ${RESTORE_SIGNER_FPR} " <<<"$estado"; then
    echo "El respaldo está firmado por una llave distinta de RESTORE_SIGNER_FPR: no se restaura." >&2; exit 2
  fi
elif [ "${RESTORE_REQUIRE_SIGNATURE:-0}" = "1" ]; then
  echo "El respaldo no está firmado y RESTORE_REQUIRE_SIGNATURE=1: no se restaura." >&2; exit 2
fi
(cd "$src" && sha256sum --quiet -c SHA256SUMS) || { echo "El respaldo NO coincide con su SHA256SUMS: no se restaura." >&2; exit 2; }

# Conexión y rol: un error de conexión no se confunde con «superusuario»
super="$(psql -X -At -c "SELECT rolsuper FROM pg_roles WHERE rolname = current_user")" \
  || { echo "No se pudo conectar a la BD destino «$PGDATABASE» como $PGUSER: revise que exista, pg_hba.conf y la contraseña." >&2; exit 1; }
[ "$super" = "f" ] || { echo "No restaure con un superusuario: use el rol propietario (conciliarh_owner)." >&2; exit 1; }
# 0. Descifrado (si aplica) a un temporal privado
for f in db.dump storage.tar manifest.json; do
  if [ -f "$src/$f" ]; then cp "$src/$f" "$work/$f"
  elif [ -f "$src/$f.gpg" ]; then gpg --batch --quiet --output "$work/$f" --decrypt "$src/$f.gpg"
  else echo "Falta $f en el respaldo" >&2; exit 1; fi
done
esperadas="$(sed -n 's/.*"migraciones": "\([^"]*\)".*/\1/p' "$work/manifest.json")"

# 2. Destinos vacíos (nunca sobrescribe)
tablas="$(psql -X -At -c "SELECT count(*) FROM pg_tables WHERE schemaname = 'public'")"
[ "$tablas" = "0" ] || { echo "La BD destino $PGDATABASE no está vacía ($tablas tablas): no se restaura encima." >&2; exit 3; }
if [ -d "$CONCILIARH_STORAGE" ] && [ -n "$(ls -A "$CONCILIARH_STORAGE" 2>/dev/null)" ]; then
  echo "El storage destino no está vacío: no se restaura encima." >&2; exit 3
fi

# 3. Base de datos: todo o nada
pg_restore --exit-on-error --single-transaction --no-password --dbname "$PGDATABASE" "$work/db.dump"

# 4. Almacenamiento (sin rutas absolutas ni «..»: tar las rechaza por omisión)
trap 'rm -rf "$work"; echo "FALLÓ la extracción del almacenamiento: la BD $PGDATABASE YA quedó restaurada; vacíe el storage destino y la BD antes de reintentar." >&2' ERR
mkdir -p "$CONCILIARH_STORAGE"
tar --extract --file "$work/storage.tar" --directory "$CONCILIARH_STORAGE" --no-same-owner --no-overwrite-dir
mkdir -p "$CONCILIARH_STORAGE"/{trabajo/reportes,cache,logs,cuarentena,exportaciones}
trap - ERR

# 5. Verificación posterior
aplicadas="$(psql -X -At -c "SELECT string_agg(version, ',' ORDER BY version) FROM schema_migraciones")"
[ "$aplicadas" = "$esperadas" ] || { echo "Migraciones restauradas ($aplicadas) distintas del manifiesto ($esperadas)." >&2; exit 4; }
integra="$(psql -X -At -c "SELECT integra FROM fn_auditoria_verificar()")"
[ "$integra" = "t" ] || { echo "La cadena de auditoría restaurada NO es íntegra." >&2; exit 4; }
malos=0
# Los registros que el propio sistema ya marcó ORIGINAL_AUSENTE antes del respaldo no se cuentan como falla de la restauración
ausentes="$(psql -X -At -c "SELECT count(*) FROM archivos_importados WHERE estado = 'ERROR' AND error_codigo = 'ORIGINAL_AUSENTE'")"
# La lista se obtiene ANTES del ciclo: si psql falla, set -e detiene todo (un ciclo sobre «< <(psql …)» ocultaría la falla)
psql -X -v ON_ERROR_STOP=1 -At -F '|' -c "SELECT id, ruta_relativa, sha256 FROM archivos_importados WHERE estado <> 'ANULADO'
    AND NOT (estado = 'ERROR' AND error_codigo = 'ORIGINAL_AUSENTE') ORDER BY id" > "$work/originales.lst"
while IFS='|' read -r id ruta sha; do
  [ -n "$id" ] || continue
  case "$ruta" in imports/*) ;; *) echo "  ruta inesperada en #$id" >&2; malos=$((malos + 1)); continue;; esac
  f="$CONCILIARH_STORAGE/$ruta"
  if [ ! -f "$f" ] || [ "$(sha256sum "$f" | cut -d' ' -f1)" != "$sha" ]; then
    echo "  original #$id ausente o distinto" >&2; malos=$((malos + 1))
  fi
done < "$work/originales.lst"
[ "$malos" = "0" ] || { echo "$malos original(es) no coinciden con su SHA-256." >&2; exit 4; }
n="$(psql -X -At -c "SELECT count(*) FROM archivos_importados WHERE estado <> 'ANULADO' AND NOT (estado = 'ERROR' AND error_codigo = 'ORIGINAL_AUSENTE')")"
echo "Restauración verificada en $PGDATABASE: migraciones $aplicadas; auditoría íntegra; $n original(es) con SHA-256 correcto."
[ "$ausentes" = "0" ] || echo "Aviso: $ausentes registro(s) ya estaban marcados ORIGINAL_AUSENTE en el origen (no son parte de esta restauración)."
echo "Siguiente paso: configure las MISMAS llaves (ACCOUNT_ENC_KEYS, REPORT_ENC_KEYS, ACCOUNT_HMAC_KEYS, APP_KEY) y ejecute bin/verificar-almacenamiento.php --hash."

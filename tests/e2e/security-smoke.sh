#!/usr/bin/env bash
# ConciliaRH — pruebas de seguridad ofensiva de extremo a extremo contra el entorno de DESARROLLO levantado.
# Uso: make security-smoke   (usa storage/dev-credenciales.txt de make seed-dev)
# Cubre: SQLi (booleana, UNION, temporal, por error), XSS reflejado/almacenado, IDOR, CSRF, escalamiento de privilegios,
# asignación masiva, inyección de fórmulas, autorización de exportaciones, SSRF por PDF y suplantación de actor.
set -euo pipefail
URL="${SMOKE_URL:-http://127.0.0.1:8120}"
CREDS="${CREDS:-storage/dev-credenciales.txt}"
COMPOSE="${COMPOSE:-docker compose --env-file .env -f infra/docker/compose.yml}"
umask 077
TMP="$(mktemp -d)"; trap 'rm -rf "$TMP"' EXIT
pass=0; fail=0
ok()  { echo "  ✔ $1"; pass=$((pass+1)); }
bad() { echo "  ✘ $1"; fail=$((fail+1)); }
check() { if eval "$2"; then ok "$1"; else bad "$1"; fi; }
csrf() { grep -oE 'name="(_token|csrf-token)"[^>]*(value|content)="[0-9a-f]{64}"' "$1" | grep -oE '[0-9a-f]{64}' | head -1; }
psql_dev() { $COMPOSE exec -T db psql -qtA -U postgres -d conciliarh -c "$1" 2>/dev/null | tr -d '[:space:]'; }

login() {  # login <usuario> → jar en $TMP/<usuario>.jar
  local u="$1" jar="$TMP/$1.jar" pw="$TMP/$1.pw"
  sed -n "s/^$u=//p" "$CREDS" | tr -d '\n' > "$pw"
  curl -s -c "$jar" -b "$jar" -o "$TMP/p" "$URL/login"
  curl -s -o /dev/null -c "$jar" -b "$jar" --data-urlencode "_token=$(csrf "$TMP/p")" --data-urlencode "username=$u" --data-urlencode "password@$pw" "$URL/login"
  rm -f "$pw"
}
get()  { curl -s -b "$TMP/$1.jar" -o "$TMP/out" -w "%{http_code} %{time_total}" "$URL$2"; }
token() { curl -s -b "$TMP/$1.jar" -c "$TMP/$1.jar" -o "$TMP/t" "$URL${2:-/}"; csrf "$TMP/t"; }
post() { # post <usuario> <ruta> [campos curl…]
  local u="$1" path="$2"; shift 2
  curl -s -b "$TMP/$u.jar" -o "$TMP/out" -D "$TMP/hdr" -w "%{http_code} %{time_total}" --data-urlencode "_token=$(token "$u" /reportes)" "$@" "$URL$path"
}

urlenc() {
  local LC_ALL=C s="$1" i c out=""
  for ((i = 0; i < ${#s}; i++)); do
    c="${s:i:1}"
    case "$c" in [A-Za-z0-9.~_-]) out+="$c" ;; *) out+=$(printf '%%%02X' "'$c") ;; esac
  done
  printf '%s' "$out"
}

[ -f "$CREDS" ] || { echo "Ejecute make seed-dev primero."; exit 1; }
for u in dev.god dev.admin dev.consulta; do login "$u"; done
CONC=$(psql_dev "SELECT id FROM conciliaciones WHERE estado IN ('COMPLETADA','COMPLETADA_CON_INCIDENCIAS','REVISION_MANUAL','CERRADA') ORDER BY id DESC LIMIT 1")
OPERADOR=$(psql_dev "SELECT id FROM usuarios WHERE username = 'dev.admin'")

echo "== Inyección SQL (booleana, UNION, temporal, por error)"
for p in "' OR '1'='1" "1 UNION SELECT username,password_hash FROM usuarios--" "'||(SELECT pg_sleep(3))||'" "1; SELECT pg_sleep(3)--" \
         "1' AND 1=CAST((SELECT version()) AS int)--" "%' AND 1=1 AND '%'='"; do
  q=$(urlenc "$p")
  for path in "/revision-masiva?q=$q&estado=$q&min=$q" "/beneficiarios?q=$q&banco=$q" "/reportes/ver?tipo=resumen&conciliacion=$q" \
              "/reportes/ver?tipo=$q&conciliacion=${CONC:-1}" "/conciliaciones/${CONC:-1}/empleados?q=$q&area=$q"; do
    read -r code t <<<"$(get dev.admin "$path")"
    check "GET ${path%%\?*} [$p] → $code en ${t}s" "[ \"$code\" != 500 ] && awk 'BEGIN{exit !($t < 2.5)}' && ! grep -qE 'SQLSTATE|syntax error|\\\$argon2' $TMP/out"
  done
done
read -r code t <<<"$(post dev.admin /reportes/exportar --data-urlencode "tipo=grupos" --data-urlencode "conciliacion=1 OR 1=1" --data-urlencode "formato=csv")"
check "POST /reportes/exportar con conciliación inyectada → 404" '[ "$code" = 404 ]'

echo "== XSS"
X='<script>alert(1)</script><img src=x onerror=alert(2)>'
xq=$(urlenc "$X")
get dev.admin "/revision-masiva?q=$xq" >/dev/null
check "Reflejado en /revision-masiva escapado" '! grep -q "<script>alert(1)" "$TMP/out" && ! grep -q "onerror=alert(2)>" "$TMP/out"'
get dev.admin "/beneficiarios?q=$xq" >/dev/null
check "Reflejado en /beneficiarios escapado" '! grep -q "<script>alert(1)" "$TMP/out"'
BEN=$(psql_dev "SELECT id FROM beneficiarios_pension ORDER BY id LIMIT 1")
if [ -n "$BEN" ]; then
  post dev.admin "/beneficiarios/$BEN/observaciones" --data-urlencode "observaciones=$X" --data-urlencode "actualizado_por=1" >/dev/null
  get dev.admin "/beneficiarios/$BEN" >/dev/null
  check "Almacenado (observaciones) escapado" '! grep -q "<script>alert(1)" "$TMP/out" && grep -q "&lt;script&gt;" "$TMP/out"'
  check "Actor no suplantable: actualizado_por = sesión (dev.admin)" '[ "$(psql_dev "SELECT actualizado_por FROM beneficiarios_pension WHERE id = $BEN")" = "$OPERADOR" ]'
else
  echo "  (sin beneficiarios en desarrollo: se omite XSS almacenado de observaciones)"
fi

echo "== IDOR"
read -r code _ <<<"$(get dev.admin /beneficiarios/99999999)"; check "Beneficiario inexistente → 404" '[ "$code" = 404 ]'
read -r code _ <<<"$(get dev.admin "/reportes/ver?tipo=resumen&conciliacion=99999999")"; check "Reporte de conciliación inexistente → 404" '[ "$code" = 404 ]'
read -r code _ <<<"$(get dev.admin /revision-masiva/acciones/99999999)"; check "Acción masiva inexistente → 404" '[ "$code" = 404 ]'
if [ -n "$BEN" ]; then
  OTRA=$(psql_dev "SELECT id FROM cuentas_bancarias WHERE beneficiario_id IS DISTINCT FROM $BEN ORDER BY id LIMIT 1")
  [ -n "$OTRA" ] && { read -r code _ <<<"$(post dev.admin "/beneficiarios/$BEN/cuentas/$OTRA/revelar")"; check "Revelar cuenta ajena vía otro beneficiario → 404" '[ "$code" = 404 ]'; }
fi

echo "== CSRF y métodos"
code=$(curl -s -o /dev/null -w "%{http_code}" -b "$TMP/dev.admin.jar" --data "tipo=grupos&conciliacion=${CONC:-1}&formato=csv" "$URL/reportes/exportar")
check "Exportar sin token CSRF → 403" '[ "$code" = 403 ]'
code=$(curl -s -o /dev/null -w "%{http_code}" -b "$TMP/dev.admin.jar" --data "accion=CONFIRMAR_PROPUESTA&items[]=1:0" "$URL/revision-masiva/previsualizar")
check "Revisión masiva sin token CSRF → 403" '[ "$code" = 403 ]'
read -r code _ <<<"$(get dev.admin "/reportes/exportar")"; check "Exportar por GET → 405" '[ "$code" = 405 ]'
code=$(curl -s -o /dev/null -w "%{http_code}" "$URL/reportes/ver?tipo=resumen&conciliacion=${CONC:-1}")
check "Reporte sin sesión → redirección al login" '[ "$code" = 303 ] || [ "$code" = 302 ] || [ "$code" = 401 ]'

echo "== Escalamiento de privilegios"
read -r code _ <<<"$(post dev.consulta /revision-masiva/previsualizar --data-urlencode "accion=CONFIRMAR_PROPUESTA" --data-urlencode "items[]=1:0")"
check "CONSULTA no ejecuta revisión masiva → 403" '[ "$code" = 403 ]'
read -r code _ <<<"$(post dev.consulta /reportes/exportar --data-urlencode "tipo=empleados" --data-urlencode "conciliacion=${CONC:-1}" --data-urlencode "formato=csv" --data-urlencode "sensible=1")"
check "CONSULTA no exporta RFC/CURP → 403" '[ "$code" = 403 ]'
read -r code _ <<<"$(post dev.consulta /reportes/exportar --data-urlencode "tipo=empleados" --data-urlencode "conciliacion=${CONC:-1}" --data-urlencode "formato=csv")"
check "CONSULTA no exporta ni sin RFC/CURP → 403" '[ "$code" = 403 ]'
read -r code _ <<<"$(post dev.consulta "/conciliaciones/${CONC:-1}/cerrar" --data-urlencode "motivo=Escalamiento de prueba")"
check "CONSULTA no cierra conciliaciones → 403" '[ "$code" = 403 ]'
read -r code _ <<<"$(post dev.consulta "/conciliaciones/${CONC:-1}/invalidar" --data-urlencode "motivo=Escalamiento de prueba")"
check "CONSULTA no invalida conciliaciones → 403" '[ "$code" = 403 ]'
read -r code _ <<<"$(post dev.consulta "/lotes/1/oficial" --data-urlencode "motivo=Escalamiento de prueba")"
check "CONSULTA no declara el lote oficial → 403" '[ "$code" = 403 ]'
for path in /rotar-llave /api/rotate-account-key /cuentas/rotar; do
  read -r code _ <<<"$(post dev.god "$path")"; check "Rotación de llaves no existe en web ($path → 404)" '[ "$code" = 404 ]'
done

if [ -n "$CONC" ]; then
  echo "== Exportaciones: inyección de fórmulas, autorización, nombre de archivo"
  read -r code _ <<<"$(post dev.admin /reportes/exportar --data-urlencode "tipo=empleados" --data-urlencode "conciliacion=$CONC" --data-urlencode "formato=csv")"
  cp "$TMP/out" "$TMP/rep.csv"
  check "CSV exportado (200) sin celdas que inicien con = + @" '[ "$code" = 200 ] && ! grep -qE "(^|,)\"?[=+@]" "$TMP/rep.csv"'
  check "Nombre de archivo controlado por el sistema" 'grep -qiE "^content-disposition: attachment; filename=\"conciliarh-[a-z_]+-c[0-9]+-v[0-9]+-[0-9]{8}-[0-9]{6}\.csv\"" "$TMP/hdr"'
  check "Descarga no cacheable" 'grep -qi "^cache-control: no-store" "$TMP/hdr"'
  read -r code _ <<<"$(post dev.admin /reportes/exportar --data-urlencode "tipo=empleados$(printf '\r\n')X-Inyectado: 1" --data-urlencode "conciliacion=$CONC" --data-urlencode "formato=csv")"
  check "CRLF en el tipo de reporte → 404, sin encabezado inyectado" '[ "$code" = 404 ] && ! grep -qi "^x-inyectado" "$TMP/hdr"'
  read -r code _ <<<"$(post dev.admin /reportes/exportar --data-urlencode "tipo=../../../../etc/passwd" --data-urlencode "conciliacion=$CONC" --data-urlencode "formato=../x")"
  check "Recorrido de rutas en tipo/formato → 404" '[ "$code" = 404 ]'
  echo "== SSRF por PDF (dompdf sin recursos remotos)"
  INC=$(psql_dev "SELECT id FROM conciliacion_incidencias WHERE conciliacion_id = $CONC AND estado IN ('ABIERTA','EN_REVISION') ORDER BY id LIMIT 1")
  MARK="ssrf$(date +%s%N)"
  if [ -n "$INC" ] && [ "$(psql_dev "SELECT estado FROM conciliaciones WHERE id = $CONC")" != "CERRADA" ]; then
    EST=$(psql_dev "SELECT CASE estado WHEN 'ABIERTA' THEN 'EN_REVISION' ELSE 'ABIERTA' END FROM conciliacion_incidencias WHERE id = $INC")
    post dev.admin "/conciliaciones/$CONC/incidencias/$INC" --data-urlencode "estado=$EST" \
      --data-urlencode "motivo=<img src=\"http://127.0.0.1/api/health?$MARK\"><link rel=stylesheet href=\"file:///etc/passwd\">" >/dev/null
  fi
  read -r code _ <<<"$(post dev.admin /reportes/exportar --data-urlencode "tipo=incidencias" --data-urlencode "conciliacion=$CONC" --data-urlencode "formato=pdf")"
  sleep 1
  check "PDF generado (200, %PDF)" '[ "$code" = 200 ] && head -c 5 "$TMP/out" | grep -q "%PDF"'
  check "dompdf no solicitó la URL inyectada ($MARK)" '! $COMPOSE logs --since 2m web 2>/dev/null | grep -q "$MARK"'
  check "Sin contenido del sistema de archivos en el PDF" '! grep -aq "root:x:" "$TMP/out"'

  echo "== Asignación masiva y suplantación de actor"
  LOTE=$(psql_dev "SELECT lote_id FROM conciliaciones WHERE id = $CONC")
  CERR=$(psql_dev "SELECT count(*) FROM conciliaciones WHERE lote_id = $LOTE AND estado = 'CERRADA'")
  if [ "$CERR" = "0" ]; then
    post dev.admin /conciliaciones --data-urlencode "lote_id=$LOTE" --data-urlencode "motivo=Prueba de seguridad" \
      --data-urlencode "solicitado_por=1" --data-urlencode "numero=999" --data-urlencode "tolerancia=100" --data-urlencode "estado=CERRADA" >/dev/null
    RES=$(psql_dev "SELECT solicitado_por || ':' || (numero < 999)::text || ':' || (tolerancia < 100)::text || ':' || (estado <> 'CERRADA')::text FROM conciliaciones WHERE lote_id = $LOTE ORDER BY id DESC LIMIT 1")
    check "La nueva ejecución la solicita la sesión, con número, tolerancia y estado de la BD" '[ "$RES" = "$OPERADOR:true:true:true" ]'
  fi
fi

echo "== FASE 7: inyección SQL en rutas nuevas"
for p in "' OR '1'='1" "1 UNION SELECT username,password_hash FROM usuarios--" "'||(SELECT pg_sleep(3))||'" "1' AND 1=CAST((SELECT version()) AS int)--"; do
  q=$(urlenc "$p")
  for path in "/empleados/duplicados?q=$q&estado=$q&confianza=$q" "/reportes/generados?tipo=$q&estado=$q&formato=$q&periodo=$q&desde=$q&hasta=$q" \
              "/empleados/fusion?origen=$q&destino=1" "/reportes/programados?todos=$q"; do
    read -r code t <<<"$(get dev.admin "$path")"
    check "GET ${path%%\?*} [$p] → $code en ${t}s" "[ \"$code\" != 500 ] && awk 'BEGIN{exit !($t < 2.5)}' && ! grep -qE 'SQLSTATE|syntax error|\\\$argon2' $TMP/out"
  done
done
for path in /rotar-hmac /api/rotate-account-hmac /cuentas/rotar-hmac /scheduler /api/scheduler /reportes/scheduler; do
  read -r code _ <<<"$(post dev.god "$path")"; check "Rotación HMAC / scheduler no existen en web ($path → 404)" '[ "$code" = 404 ]'
done

echo "== FASE 7: reportes programados (permisos, XSS, IDOR, CSRF, actor, asignación masiva)"
ADMIN=$(psql_dev "SELECT id FROM usuarios WHERE username = 'dev.admin'")
GODID=$(psql_dev "SELECT id FROM usuarios WHERE username = 'dev.god'")
read -r code _ <<<"$(get dev.consulta /reportes/programados)"; check "CONSULTA no ve programaciones → 403" '[ "$code" = 403 ]'
read -r code _ <<<"$(post dev.consulta /reportes/programados --data-urlencode "nombre=Intento" --data-urlencode "tipo=resumen" --data-urlencode "formato=PDF" --data-urlencode "frecuencia=MANUAL")"
check "CONSULTA no crea programaciones → 403" '[ "$code" = 403 ]'
MARK7="smk$(date +%s%N)"
read -r code _ <<<"$(post dev.consulta /reportes/programados --data-urlencode "nombre=Sensible $MARK7" --data-urlencode "tipo=empleados" --data-urlencode "formato=CSV" \
  --data-urlencode "frecuencia=MANUAL" --data-urlencode "hora=7" --data-urlencode "retencion_dias=30" --data-urlencode "sensible=1")"
check "CONSULTA no programa reportes con RFC/CURP (403, nada creado)" "[ \"\$code\" = 403 ] && [ \"\$(psql_dev \"SELECT count(*) FROM reportes_programados WHERE nombre = 'Sensible $MARK7'\")\" = 0 ]"
post dev.admin /reportes/programados --data-urlencode "nombre=<script>alert(7)</script> $MARK7" --data-urlencode "tipo=resumen" --data-urlencode "formato=PDF" \
  --data-urlencode "frecuencia=MANUAL" --data-urlencode "hora=7" --data-urlencode "retencion_dias=30" --data-urlencode "creado_por=$GODID" \
  --data-urlencode "version=77" --data-urlencode "ultimo_estado=DISPONIBLE" >/dev/null
PROG=$(psql_dev "SELECT id FROM reportes_programados WHERE nombre LIKE '%$MARK7' AND nombre NOT LIKE 'Sensible%' ORDER BY id DESC LIMIT 1")
check "Actor y asignación masiva: el dueño es la sesión y la versión la de la BD" "[ \"\$(psql_dev \"SELECT creado_por || ':' || version || ':' || coalesce(ultimo_estado, '-') FROM reportes_programados WHERE id = ${PROG:-0}\")\" = \"$ADMIN:1:-\" ]"
read -r code _ <<<"$(get dev.admin /reportes/programados)"
check "XSS almacenado en el nombre de la programación escapado" '! grep -q "<script>alert(7)" "$TMP/out" && grep -q "&lt;script&gt;alert(7)" "$TMP/out"'
read -r code _ <<<"$(get dev.god "/reportes/programados/${PROG:-0}")"; check "GOD (administrador de programaciones) sí la ve → 200" '[ "$code" = 200 ]'
read -r code _ <<<"$(get dev.consulta "/reportes/programados/${PROG:-0}")"; check "CONSULTA no ve la programación ajena → 403" '[ "$code" = 403 ]'
read -r code _ <<<"$(post dev.consulta "/reportes/programados/${PROG:-0}/generar")"; check "CONSULTA no genera la programación ajena → 403" '[ "$code" = 403 ]'
read -r code _ <<<"$(get dev.admin "/reportes/programados/999999999")"; check "IDOR: programación inexistente → 404" '[ "$code" = 404 ]'
read -r code _ <<<"$(post dev.admin "/reportes/programados/999999999/generar")"; check "IDOR: generar programación inexistente → 404" '[ "$code" = 404 ]'
read -r code _ <<<"$(curl -s -b "$TMP/dev.admin.jar" -o /dev/null -w "%{http_code} 0" --data-urlencode "nombre=Sin token" "$URL/reportes/programados")"
check "Programar sin token CSRF → 403" '[ "$code" = 403 ]'

if [ -n "$CONC" ] && [ -n "$PROG" ]; then
  echo "== FASE 7: generación y descarga protegida"
  post dev.admin "/reportes/programados/$PROG/generar" >/dev/null
  $COMPOSE --profile tools run --rm --no-deps tools php bin/scheduler.php --solo-ejecutar >/dev/null 2>&1 || true
  GEN=$(psql_dev "SELECT id FROM reportes_generados WHERE programacion_id = $PROG ORDER BY id DESC LIMIT 1")
  check "Reporte generado DISPONIBLE" "[ \"\$(psql_dev \"SELECT estado FROM reportes_generados WHERE id = ${GEN:-0}\")\" = DISPONIBLE ]"
  read -r code _ <<<"$(post dev.admin "/reportes/generados/${GEN:-0}/descargar")"
  check "El dueño descarga (200, %PDF, no-store)" '[ "$code" = 200 ] && head -c 5 "$TMP/out" | grep -q "%PDF" && grep -qi "^cache-control: no-store" "$TMP/hdr"'
  read -r code _ <<<"$(post dev.consulta "/reportes/generados/${GEN:-0}/descargar")"; check "CONSULTA no descarga → 403" '[ "$code" = 403 ]'
  read -r code _ <<<"$(post dev.admin "/reportes/generados/999999999/descargar")"; check "IDOR: reporte inexistente → 404" '[ "$code" = 404 ]'
  read -r code _ <<<"$(post dev.admin "/reportes/generados/..%2F..%2Fetc%2Fpasswd/descargar")"; check "Recorrido de rutas en el id → 404" '[ "$code" = 404 ]'
  RUTA=$(psql_dev "SELECT ruta FROM reportes_generados WHERE id = ${GEN:-0}")
  check "El archivo vive fuera de public/ y no se sirve por URL directa" "[ \"\$(curl -s -o /dev/null -w '%{http_code}' \"$URL/storage/reportes/$RUTA\")\" = 404 ] && [ \"\$(curl -s -o /dev/null -w '%{http_code}' \"$URL/reportes/$RUTA\")\" != 200 ]"
  $COMPOSE exec -T web sh -c "printf '%%alterado' >> /var/www/html/storage/reportes/$RUTA" 2>/dev/null || true
  read -r code _ <<<"$(post dev.admin "/reportes/generados/${GEN:-0}/descargar")"
  check "Archivo alterado: 409, marcado ALTERADO y no se entrega" "[ \"\$code\" = 409 ] && [ \"\$(psql_dev \"SELECT estado FROM reportes_generados WHERE id = ${GEN:-0}\")\" = ALTERADO ] && ! head -c 5 \"$TMP/out\" | grep -q '%PDF'"
fi

echo "== FASE 7: fusión de empleados (privilegios, CSRF, huella)"
E1=$(psql_dev "SELECT id FROM empleados WHERE fusionado_en_id IS NULL ORDER BY id LIMIT 1")
E2=$(psql_dev "SELECT id FROM empleados WHERE fusionado_en_id IS NULL ORDER BY id OFFSET 1 LIMIT 1")
if [ -n "$E1" ] && [ -n "$E2" ]; then
  read -r code _ <<<"$(get dev.consulta "/empleados/fusion?origen=$E2&destino=$E1")"; check "CONSULTA no previsualiza fusiones → 403" '[ "$code" = 403 ]'
  read -r code _ <<<"$(post dev.consulta /empleados/fusion --data-urlencode "origen=$E2" --data-urlencode "destino=$E1" --data-urlencode "motivo=Intento de escalamiento de privilegios" --data-urlencode "huella=$(printf 'a%.0s' $(seq 64))")"
  check "CONSULTA no fusiona → 403" '[ "$code" = 403 ]'
  read -r code _ <<<"$(curl -s -b "$TMP/dev.admin.jar" -o /dev/null -w "%{http_code} 0" --data-urlencode "origen=$E2" --data-urlencode "destino=$E1" "$URL/empleados/fusion")"
  check "Fusión sin token CSRF → 403" '[ "$code" = 403 ]'
  N0=$(psql_dev "SELECT count(*) FROM fusiones_empleados")
  post dev.admin /empleados/fusion --data-urlencode "origen=$E2" --data-urlencode "destino=$E1" --data-urlencode "motivo=Huella inventada para forzar una fusión" \
    --data-urlencode "huella=$(printf 'b%.0s' $(seq 64))" --data-urlencode "usuario_id=1" --data-urlencode "aceptados[]=RFC_DIFERENTE" >/dev/null
  check "Una huella falsa nunca fusiona (ni con autor ni conflictos inventados)" "[ \"\$(psql_dev 'SELECT count(*) FROM fusiones_empleados')\" = \"$N0\" ]"
  read -r code _ <<<"$(post dev.consulta /empleados/fusiones/1/separar --data-urlencode "motivo=Intento de separar sin permiso en la prueba")"
  check "CONSULTA no separa fusiones → 403" '[ "$code" = 403 ]'
  read -r code _ <<<"$(get dev.consulta /empleados/duplicados)"; check "CONSULTA no ve duplicados → 403" '[ "$code" = 403 ]'
fi

echo "== FASE 8: inyección SQL en rutas nuevas"
for p in "' OR '1'='1" "1 UNION SELECT username,password_hash FROM usuarios--" "'||(SELECT pg_sleep(3))||'" "1' AND 1=CAST((SELECT version()) AS int)--"; do
  q=$(urlenc "$p")
  for path in "/notificaciones?estado=$q&tipo=$q&page=$q" "/revision-pensiones?q=$q&banco=$q&revision=$q" "/empleados/solicitudes-fusion?estado=$q" \
              "/auditoria?accion=$q&usuario=$q&desde=$q" "/api/notificaciones/resumen?x=$q"; do
    who=dev.admin; case "$path" in /auditoria*) who=dev.god ;; esac
    read -r code t <<<"$(get "$who" "$path")"
    check "GET ${path%%\?*} [$p] ($who) → $code en ${t}s" "[ \"$code\" != 500 ] && awk 'BEGIN{exit !($t < 2.5)}' && ! grep -qE 'SQLSTATE|syntax error|\\\$argon2' $TMP/out"
  done
done
for path in "/revision-pensiones/1%20OR%201=1" "/empleados/solicitudes-fusion/1%20OR%201=1" "/notificaciones/1%20OR%201=1/abrir"; do
  read -r code _ <<<"$(get dev.admin "$path")"; check "Id inyectado en la ruta ($path) → 404/405" '[ "$code" = 404 ] || [ "$code" = 405 ]'
done

echo "== FASE 8: notificaciones (IDOR, CSRF, XSS, sin PII)"
MARK8="smk8$(date +%s%N)"
psql_dev "SELECT fn_notificar($GODID, 'SISTEMA', 'NORMAL', '<script>alert(8)</script> $MARK8', 'Aviso ficticio de prueba', '/notificaciones', NULL, NULL, NULL)" >/dev/null
NGOD=$(psql_dev "SELECT id FROM notificaciones WHERE usuario_id = $GODID AND titulo LIKE '%$MARK8' ORDER BY id DESC LIMIT 1")
read -r code _ <<<"$(post dev.admin "/notificaciones/${NGOD:-0}/abrir")"; check "IDOR: abrir notificación ajena → 404" '[ "$code" = 404 ]'
read -r code _ <<<"$(post dev.admin "/notificaciones/${NGOD:-0}/eliminar")"; check "IDOR: borrar notificación ajena → 404 y sigue existiendo" "[ \"\$code\" = 404 ] && [ \"\$(psql_dev \"SELECT count(*) FROM notificaciones WHERE id = ${NGOD:-0}\")\" = 1 ]"
get dev.admin /api/notificaciones/resumen >/dev/null; check "La API no expone notificaciones ajenas" "! grep -q '$MARK8' \"$TMP/out\""
get dev.god /notificaciones >/dev/null; check "XSS almacenado en una notificación escapado" '! grep -q "<script>alert(8)" "$TMP/out" && grep -q "&lt;script&gt;alert(8)" "$TMP/out"'
read -r code _ <<<"$(curl -s -b "$TMP/dev.god.jar" -o /dev/null -w "%{http_code} 0" -X POST "$URL/notificaciones/leer-todas")"; check "Leer todas sin token CSRF → 403" '[ "$code" = 403 ]'
check "Una URL externa nunca entra en una notificación (BD)" "[ -z \"\$(psql_dev \"SELECT fn_notificar($GODID, 'SISTEMA', 'NORMAL', 'Ext', 'x', '//evil.example', NULL, NULL, NULL)\")\" ] && [ \"\$(psql_dev \"SELECT count(*) FROM notificaciones WHERE url LIKE '//%'\")\" = 0 ]"
check "Ninguna notificación contiene RFC, CURP ni CLABE" "[ \"\$(psql_dev \"SELECT count(*) FROM notificaciones WHERE titulo || mensaje ~ '(^|[^A-Za-z])[A-Z]{4}[0-9]{6}|(^|[^0-9])[0-9]{18}([^0-9]|\$)'\")\" = 0 ]"
psql_dev "DELETE FROM notificaciones WHERE titulo LIKE '%$MARK8'" >/dev/null

echo "== FASE 8: salud del sistema y mantenimiento"
read -r code _ <<<"$(get dev.consulta /sistema/salud)"; check "CONSULTA no ve la salud → 403" '[ "$code" = 403 ]'
read -r code _ <<<"$(get dev.consulta /api/sistema/salud)"; check "CONSULTA no consulta la API de salud → 403" '[ "$code" = 403 ]'
read -r code _ <<<"$(get dev.admin /api/sistema/salud)"; check "ADMIN no consulta la API de salud → 403" '[ "$code" = 403 ]'
read -r code _ <<<"$(post dev.admin /sistema/salud/almacenamiento)"; check "ADMIN no ejecuta mantenimiento → 403" '[ "$code" = 403 ]'
read -r code _ <<<"$(get dev.admin /sistema/salud)"; check "ADMIN no ve la salud desde la migración 0024 → 403" '[ "$code" = 403 ]'
read -r code _ <<<"$(get dev.admin /auditoria)"; check "ADMIN no ve la auditoría desde la migración 0026 → 403" '[ "$code" = 403 ]'
read -r code _ <<<"$(post dev.admin /auditoria/verificar)"; check "ADMIN no verifica la cadena de auditoría → 403" '[ "$code" = 403 ]'
read -r code _ <<<"$(get dev.god /auditoria)"; check "GOD ve la auditoría → 200" '[ "$code" = 200 ]'
read -r code _ <<<"$(get dev.god /sistema/salud)"
check "GOD ve la salud sin secretos ni rutas absolutas" "[ \"\$code\" = 200 ] && ! grep -qE '/var/www|$(sed -n 's/^APP_KEY=//p' .env | head -c 16)|$(sed -n 's/^DB_PASSWORD=//p' .env | head -c 12)' \"$TMP/out\""
curl -s -o "$TMP/out" "$URL/api/health"; check "/api/health pública y mínima" 'grep -q "\"estado\":\"ok\"" "$TMP/out" && ! grep -qiE "version|php|postgres" "$TMP/out"'
read -r code _ <<<"$(curl -s -b "$TMP/dev.god.jar" -o /dev/null -w "%{http_code} 0" -X POST "$URL/sistema/salud/almacenamiento")"; check "Mantenimiento sin token CSRF → 403" '[ "$code" = 403 ]'

echo "== FASE 8: doble aprobación de fusiones (cuatro ojos) y escalamiento"
read -r code _ <<<"$(get dev.consulta /empleados/solicitudes-fusion)"; check "CONSULTA no ve solicitudes de fusión → 403" '[ "$code" = 403 ]'
read -r code _ <<<"$(post dev.consulta /empleados/solicitudes-fusion/999999/aprobar --data-urlencode "motivo=Intento de aprobar sin permiso")"; check "CONSULTA no aprueba → 403" '[ "$code" = 403 ]'
read -r code _ <<<"$(post dev.consulta /empleados/solicitudes-fusion/999999/rechazar --data-urlencode "motivo=Intento de rechazar sin permiso")"; check "CONSULTA no rechaza → 403" '[ "$code" = 403 ]'
read -r code _ <<<"$(post dev.admin /empleados/solicitudes-fusion/999999/aprobar --data-urlencode "motivo=Solicitud inexistente")"; check "ADMIN aprueba (segunda firma), pero una solicitud inexistente → 404" '[ "$code" = 404 ]'
read -r code _ <<<"$(post dev.god /empleados/solicitudes-fusion/999999/aprobar --data-urlencode "motivo=Solicitud inexistente")"; check "IDOR: solicitud inexistente → 404" '[ "$code" = 404 ]'
check "Cuatro ojos en la BD: nadie puede decidir su propia solicitud (CHECK)" "[ \"\$(psql_dev \"SELECT count(*) FROM pg_constraint WHERE conname = 'sf_cuatro_ojos'\")\" = 1 ]"
check "La web no inserta ni modifica solicitudes de fusión directamente" "[ \"\$(psql_dev \"SELECT has_table_privilege('conciliarh_web', 'solicitudes_fusion', 'INSERT') OR has_table_privilege('conciliarh_web', 'solicitudes_fusion', 'UPDATE')\")\" = f ]"
ADMINU=$(psql_dev "SELECT id FROM usuarios WHERE username = 'dev.admin'")
CONSU=$(psql_dev "SELECT id FROM usuarios WHERE username = 'dev.consulta'")
read -r code _ <<<"$(post dev.admin "/usuarios/$CONSU" --data-urlencode "nombre=Consulta de desarrollo" --data-urlencode "roles[]=CONSULTA" --data-urlencode "roles[]=ADMIN")"
check "ADMIN no administra usuarios ni asigna el rol protegido ADMIN (403, sin cambios)" "[ \"\$code\" = 403 ] && [ \"\$(psql_dev \"SELECT count(*) FROM usuario_rol ur JOIN roles r ON r.id = ur.rol_id WHERE ur.usuario_id = $CONSU AND r.codigo = 'ADMIN'\")\" = 0 ]"
post dev.admin "/usuarios/$ADMINU" --data-urlencode "nombre=Administrador" --data-urlencode "roles[]=ADMIN" --data-urlencode "roles[]=GOD" >/dev/null
check "ADMIN no se convierte en GOD" "[ \"\$(psql_dev \"SELECT count(*) FROM usuario_rol ur JOIN roles r ON r.id = ur.rol_id WHERE ur.usuario_id = $ADMINU AND r.codigo = 'GOD'\")\" = 0 ]"
ROLADM=$(psql_dev "SELECT id FROM roles WHERE codigo = 'ADMIN'")
read -r code _ <<<"$(post dev.admin "/roles/$ROLADM/permisos" --data-urlencode "permisos[]=1")"; check "ADMIN no se asigna permisos → 403" '[ "$code" = 403 ]'
check "Sólo quedan los roles GOD, ADMIN y CONSULTA (0026)" "[ \"\$(psql_dev \"SELECT string_agg(codigo, '-' ORDER BY codigo) FROM roles WHERE es_sistema\")\" = ADMIN-CONSULTA-GOD ]"

echo "== FASE 8: revisión de pensiones MEDIA"
read -r code _ <<<"$(get dev.consulta /revision-pensiones)"; check "CONSULTA ve la bandeja (sólo lectura) → 200" '[ "$code" = 200 ]'
read -r code _ <<<"$(post dev.consulta /revision-pensiones/1/confirmar --data-urlencode "version=0" --data-urlencode "motivo=Sin permiso")"; check "CONSULTA no confirma → 403" '[ "$code" = 403 ]'
read -r code _ <<<"$(curl -s -b "$TMP/dev.admin.jar" -o /dev/null -w "%{http_code} 0" --data-urlencode "version=0" "$URL/revision-pensiones/1/pendiente")"; check "Dejar pendiente sin token CSRF → 403" '[ "$code" = 403 ]'
PEN=$(psql_dev "SELECT id FROM registros_tesoreria WHERE categoria = 'NOMINA' LIMIT 1")
[ -z "$PEN" ] || { read -r code _ <<<"$(get dev.admin "/revision-pensiones/$PEN")"; check "Un pago de nómina no es un caso de pensión → 404" '[ "$code" = 404 ]'; }
MEDIA=$(psql_dev "SELECT r.id FROM registros_tesoreria r WHERE r.categoria = 'PENSION' AND r.asociacion_estado = 'SUGERENCIA' AND r.asociacion_evidencia->>'categoria' = 'MEDIA' LIMIT 1")
if [ -n "$MEDIA" ]; then
  get dev.admin "/revision-pensiones/$MEDIA" >/dev/null
  check "El detalle no muestra la CLABE completa" '! grep -qE "[0-9]{18}" "$TMP/out"'
fi

if [ -n "$CONC" ]; then
  echo "== FASE 8: cifrado en reposo de reportes con datos de personas"
  post dev.admin /reportes/programados --data-urlencode "nombre=Diferencias cifradas $MARK8" --data-urlencode "tipo=diferencias" --data-urlencode "formato=CSV" \
    --data-urlencode "frecuencia=MANUAL" --data-urlencode "hora=7" --data-urlencode "retencion_dias=30" >/dev/null
  PROG8=$(psql_dev "SELECT id FROM reportes_programados WHERE nombre = 'Diferencias cifradas $MARK8'")
  post dev.admin "/reportes/programados/${PROG8:-0}/generar" >/dev/null
  $COMPOSE --profile tools run --rm --no-deps tools php bin/scheduler.php --solo-ejecutar >/dev/null 2>&1 || true
  GEN8=$(psql_dev "SELECT id FROM reportes_generados WHERE programacion_id = ${PROG8:-0} ORDER BY id DESC LIMIT 1")
  RUTA8=$(psql_dev "SELECT ruta FROM reportes_generados WHERE id = ${GEN8:-0}")
  check "Guardado cifrado (DISPONIBLE, .enc, llave registrada)" "[ \"\$(psql_dev \"SELECT estado || ':' || cifrado || ':' || (cifrado_llave IS NOT NULL) FROM reportes_generados WHERE id = ${GEN8:-0}\")\" = DISPONIBLE:true:true ] && [ \"\${RUTA8##*.}\" = enc ]"
  check "En disco no hay claro (encabezado CRHRPT1, sin encabezados CSV)" "$COMPOSE exec -T web sh -c 'head -c 7 /var/www/html/storage/reportes/$RUTA8' | grep -q CRHRPT1 && ! $COMPOSE exec -T web sh -c 'cat /var/www/html/storage/reportes/$RUTA8' | grep -qa 'Diferencia'"
  read -r code _ <<<"$(post dev.admin "/reportes/generados/${GEN8:-0}/descargar")"
  check "El dueño descarga el claro (200, CSV legible, no-store)" '[ "$code" = 200 ] && ! head -c 7 "$TMP/out" | grep -q CRHRPT1 && grep -qa "," "$TMP/out" && grep -qi "^cache-control: no-store" "$TMP/hdr"'
  check "No quedan temporales en claro tras la descarga" "[ \"\$($COMPOSE exec -T web sh -c 'ls /var/www/html/storage/trabajo/reportes 2>/dev/null | grep -c reporte-descarga' | tr -d '[:space:]')\" = 0 ]"
  read -r code _ <<<"$(post dev.consulta "/reportes/generados/${GEN8:-0}/descargar")"; check "CONSULTA no descarga el reporte cifrado → 403" '[ "$code" = 403 ]'
  $COMPOSE exec -T web sh -c "printf 'X' | dd of=/var/www/html/storage/reportes/$RUTA8 bs=1 seek=200 conv=notrunc status=none" 2>/dev/null || true
  read -r code _ <<<"$(post dev.admin "/reportes/generados/${GEN8:-0}/descargar")"
  check "Cifrado manipulado: 409, ALTERADO y nada entregado" "[ \"\$code\" = 409 ] && [ \"\$(psql_dev \"SELECT estado FROM reportes_generados WHERE id = ${GEN8:-0}\")\" = ALTERADO ] && ! grep -qa 'Diferencia' \"$TMP/out\""
fi

# Limpieza: las programaciones creadas por esta corrida quedan PAUSADAS (la cuota de 20 activas por usuario no se agota
# con corridas repetidas en desarrollo). Sólo las de esta corrida, identificadas por sus marcas.
psql_dev "UPDATE reportes_programados SET activo = false, pausa_motivo = 'Fin de security-smoke'
           WHERE activo AND (nombre LIKE '%${MARK7:-sin-marca}' OR nombre LIKE '%${MARK8:-sin-marca}')" >/dev/null || true

echo "Resultado: $pass correctas, $fail fallidas"
[ "$fail" -eq 0 ]

#!/usr/bin/env bash
# ConciliaRH — prueba de humo HTTP contra un entorno levantado (desarrollo).
# Uso: SMOKE_USER=god.dev SMOKE_PASSWORD='...' [SMOKE_URL=http://127.0.0.1:8120] bash tests/e2e/smoke.sh
# Verifica: salud, cabeceras de seguridad, CSRF, login real con cookies, regeneración de sesión,
# permisos, cola PHP → PostgreSQL → worker (DIAGNOSTICO) y cierre de sesión.
set -euo pipefail
URL="${SMOKE_URL:-http://127.0.0.1:8120}"
: "${SMOKE_USER:?Defina SMOKE_USER}"
: "${SMOKE_PASSWORD:?Defina SMOKE_PASSWORD}"
umask 077
JAR="$(mktemp)"; OUT="$(mktemp)"; PWF="$(mktemp)"
trap 'rm -f "$JAR" "$OUT" "$PWF"' EXIT
# La contraseña se pasa a curl desde un archivo temporal (no aparece en la lista de procesos)
printf '%s' "$SMOKE_PASSWORD" > "$PWF"
pass=0; fail=0
ok()   { echo "  ✔ $1"; pass=$((pass+1)); }
bad()  { echo "  ✘ $1"; fail=$((fail+1)); }
check() { if eval "$2"; then ok "$1"; else bad "$1"; fi; }
csrf() { grep -oE 'name="(_token|csrf-token)"[^>]*(value|content)="[0-9a-f]{64}"' "$OUT" | grep -oE '[0-9a-f]{64}' | head -1; }
sid()  { awk '$6 ~ /conciliarh_sid$/ {print $7}' "$JAR" | tail -1; }

echo "== Salud y cabeceras"
code=$(curl -s -o "$OUT" -D - "$URL/api/health" | tee /dev/stderr 2>/dev/null | head -1 | awk '{print $2}') || true
check "GET /api/health responde 200" '[ "$code" = "200" ]'
check "health devuelve sobre JSON success" 'grep -q "\"success\":true" "$OUT"'
H=$(curl -s -D - -o /dev/null "$URL/login")
check "CSP estricta presente" 'grep -qi "content-security-policy: default-src '"'"'self'"'"'" <<<"$H"'
check "X-Frame-Options DENY" 'grep -qi "x-frame-options: DENY" <<<"$H"'
check "Cookie de sesión HttpOnly + SameSite" 'grep -qiE "set-cookie: (__Host-)?conciliarh_sid=.*HttpOnly.*SameSite=Lax" <<<"$H"'
check "Sin cabecera X-Powered-By" '! grep -qi "x-powered-by" <<<"$H"'

echo "== Autenticación"
curl -s -c "$JAR" -b "$JAR" -o "$OUT" "$URL/login"
T=$(csrf); S1=$(sid)
check "Formulario de login con token CSRF" '[ -n "$T" ]'
check "Login no pide correo" '! grep -qi "type=\"email\"" "$OUT"'
check "No existe registro público" '[ "$(curl -s -o /dev/null -w "%{http_code}" "$URL/registro")" = "404" ]'
code=$(curl -s -o /dev/null -w "%{http_code}" -c "$JAR" -b "$JAR" --data-urlencode "username=$SMOKE_USER" --data-urlencode "password@$PWF" "$URL/login")
check "POST /login sin CSRF → 403" '[ "$code" = "403" ]'
code=$(curl -s -o /dev/null -w "%{http_code}" -c "$JAR" -b "$JAR" --data-urlencode "_token=$T" --data-urlencode "username=$SMOKE_USER" --data-urlencode "password=incorrecta-123456" "$URL/login")
check "Credenciales inválidas → redirección 303" '[ "$code" = "303" ]'
curl -s -c "$JAR" -b "$JAR" -o "$OUT" "$URL/login"
check "Mensaje genérico (sin enumeración)" 'grep -q "Usuario o contraseña incorrectos" "$OUT"'
T=$(csrf)
loc=$(curl -s -o /dev/null -w "%{redirect_url}" -c "$JAR" -b "$JAR" --data-urlencode "_token=$T" --data-urlencode "username=$SMOKE_USER" --data-urlencode "password@$PWF" "$URL/login")
S2=$(sid)
check "Login válido redirige al tablero" '[[ "$loc" == */ ]]'
check "ID de sesión regenerado al autenticar" '[ -n "$S2" ] && [ "$S1" != "$S2" ]'
curl -s -c "$JAR" -b "$JAR" -o "$OUT" "$URL/"
check "Tablero accesible autenticado" 'grep -q "Tablero" "$OUT"'
check "Identidad institucional aplicada (vino)" 'curl -s "$URL/assets/css/tokens.css" | grep -q "#691C32"'

echo "== API y cola de procesos"
T=$(csrf)
code=$(curl -s -o /dev/null -w "%{http_code}" -H "Accept: application/json" "$URL/api/procesos/1")
check "API sin sesión → 401 JSON" '[ "$code" = "401" ]'
loc=$(curl -s -o /dev/null -w "%{redirect_url}" -c "$JAR" -b "$JAR" --data-urlencode "_token=$T" "$URL/procesos/diagnostico")
JOB=$(grep -oE '[0-9]+$' <<<"$loc" || true)
check "Diagnóstico encolado" '[ -n "$JOB" ]'
state=""
for _ in $(seq 1 20); do
  state=$(curl -s -c "$JAR" -b "$JAR" -H "Accept: application/json" "$URL/api/procesos/$JOB" | grep -oE '"estado":"[A-Z_]+"' | head -1 | cut -d'"' -f4)
  [ "$state" = "COMPLETADO" ] && break; sleep 1
done
check "El procesador completó el trabajo ($state)" '[ "$state" = "COMPLETADO" ]'

echo "== FASE 2: periodos, lotes y carga segura"
FIX="$(dirname "$0")/../fixtures/excel"
if [ -f "$FIX/valid.xlsx" ]; then
  YEAR=$((2050 + RANDOM % 40)); MON=$((1 + RANDOM % 12))
  curl -s -c "$JAR" -b "$JAR" -o "$OUT" "$URL/periodos/nuevo"; T=$(csrf)
  loc=$(curl -s -o /dev/null -w "%{redirect_url}" -c "$JAR" -b "$JAR" --data-urlencode "_token=$T" -d "anio=$YEAR&mes=$MON&quincena=1" "$URL/periodos")
  PER=$(grep -oE '[0-9]+$' <<<"$loc" || true)
  check "Periodo creado" '[ -n "$PER" ]'
  curl -s -c "$JAR" -b "$JAR" -o "$OUT" "$URL/periodos/nuevo"; T=$(csrf)
  loc=$(curl -s -o /dev/null -w "%{redirect_url}" -c "$JAR" -b "$JAR" --data-urlencode "_token=$T" -d "anio=$YEAR&mes=$MON&quincena=2" "$URL/periodos")
  QNA=$(grep -oE '[0-9]+$' <<<"$loc" || true)
  curl -s -c "$JAR" -b "$JAR" -o "$OUT" "$URL/periodos/$QNA"; T=$(csrf)
  check "Quincena con espacios de carga por población" 'grep -q "Archivos de la quincena" "$OUT" && grep -q "Falta este archivo" "$OUT"'
  curl -s -o /dev/null -c "$JAR" -b "$JAR" -F "_token=$T" -F "columna=PAGOS" -F "poblacion=POLICIA" -F "archivos[]=@$FIX/valid.xlsx;filename=humo-pagos-policias.xlsx" "$URL/periodos/$QNA/archivos"
  QPAGE=$(curl -s -b "$JAR" "$URL/periodos/$QNA")
  check "Carga en el espacio de la quincena (lote interno automático)" 'grep -q "humo-pagos-policias.xlsx" <<<"$QPAGE"'
  curl -s -c "$JAR" -b "$JAR" -o "$OUT" "$URL/periodos/$PER"; T=$(csrf)
  loc=$(curl -s -o /dev/null -w "%{redirect_url}" -c "$JAR" -b "$JAR" --data-urlencode "_token=$T" --data-urlencode "origen=Humo" "$URL/periodos/$PER/lotes")
  LOT=$(grep -oE '[0-9]+$' <<<"$loc" || true)
  LOTPAGE=$(curl -s -b "$JAR" "$URL/lotes/$LOT")
  check "Lote creado (Carga 001)" '[ -n "$LOT" ] && grep -q "Carga 001" <<<"$LOTPAGE"'
  curl -s -c "$JAR" -b "$JAR" -o "$OUT" "$URL/importaciones/nueva?lote=$LOT"; T=$(csrf)
  TIPO=$(grep -oE 'value="[0-9]+"[^>]*>Dispersión de Tesorería' "$OUT" | grep -oE '[0-9]+' | head -1)
  ORIG=$(grep -oE 'value="[0-9]+"[^>]*>Sin definir' "$OUT" | grep -oE '[0-9]+' | head -1)
  loc=$(curl -s -o /dev/null -w "%{redirect_url}" -c "$JAR" -b "$JAR" -F "_token=$T" -F "lote_id=$LOT" -F "tipo_documento_id=$TIPO" \
        -F "origen_administrativo_id=$ORIG" -F "confirmar_duplicado=1" -F "archivo=@$FIX/valid.xlsm;filename=NÓMINA HUMO (1).xlsm" "$URL/importaciones")
  ARC=$(grep -oE '/importaciones/[0-9]+$' <<<"$loc" | grep -oE '[0-9]+$' || true)
  check "Carga multipart real aceptada" '[ -n "$ARC" ]'
  est=""
  for _ in $(seq 1 25); do
    est=$(curl -s -b "$JAR" -H "Accept: application/json" "$URL/api/importaciones/$ARC" | grep -oE '"estado":"[A-Z_]+"' | head -1 | cut -d'"' -f4)
    case "$est" in VERIFICADO|ANALIZANDO|ANALIZADO|ANALIZADO_CON_ADVERTENCIAS|REVISION_MANUAL) break;; esac; sleep 1
  done
  check "Worker verificó el archivo ($est)" 'case "$est" in VERIFICADO|ANALIZANDO|ANALIZADO|ANALIZADO_CON_ADVERTENCIAS|REVISION_MANUAL) true;; *) false;; esac'
  DUP=$(curl -s -b "$JAR" -H "Accept: application/json" "$URL/api/importaciones/$ARC" | grep -oE '"clasificacion_duplicidad":"[A-Z_]+"' | cut -d'"' -f4)
  check "Clasificación de duplicidad registrada ($DUP)" 'case "$DUP" in PENDIENTE|NUEVO|DUPLICADO_BINARIO|DUPLICADO_CONTENIDO|POSIBLE_VERSION|SIN_HUELLA|REVISION_MANUAL) true;; *) false;; esac'
  check "Descarga por GET rechazada (405)" '[ "$(curl -s -o /dev/null -w "%{http_code}" -b "$JAR" "$URL/importaciones/$ARC/descargar")" = "405" ]'
  curl -s -c "$JAR" -b "$JAR" -o "$OUT" "$URL/importaciones/$ARC"; T=$(csrf)
  curl -s -c "$JAR" -b "$JAR" -o "$OUT" --data-urlencode "_token=$T" -d "confirmar_macros=1" "$URL/importaciones/$ARC/descargar"
  check "Descarga autorizada (POST+CSRF, macros confirmadas) idéntica al original (SHA-256)" '[ "$(sha256sum < "$OUT" | cut -c1-64)" = "$(sha256sum < "$FIX/valid.xlsm" | cut -c1-64)" ]'
  curl -s -c "$JAR" -b "$JAR" -o "$OUT" "$URL/importaciones/nueva?lote=$LOT"; T=$(csrf)
  curl -s -o /dev/null -c "$JAR" -b "$JAR" -F "_token=$T" -F "lote_id=$LOT" -F "tipo_documento_id=$TIPO" -F "origen_administrativo_id=$ORIG" \
       -F "archivo=@$FIX/fake-extension.xlsx" "$URL/importaciones"
  FORM=$(curl -s -c "$JAR" -b "$JAR" "$URL/importaciones/nueva")
  check "Archivo falso rechazado con código" 'grep -q "FIRMA_INVALIDA" <<<"$FORM"'
  echo "== FASE 3: análisis del libro"
  if [ -f "$FIX/analisis/a09_total.xlsx" ]; then
    curl -s -c "$JAR" -b "$JAR" -o "$OUT" "$URL/importaciones/nueva?lote=$LOT"; T=$(csrf)
    loc=$(curl -s -o /dev/null -w "%{redirect_url}" -c "$JAR" -b "$JAR" -F "_token=$T" -F "lote_id=$LOT" -F "tipo_documento_id=$TIPO" \
          -F "origen_administrativo_id=$ORIG" -F "confirmar_duplicado=1" -F "archivo=@$FIX/analisis/a09_total.xlsx;filename=DISPERSION FICTICIA.xlsx" "$URL/importaciones")
    AN=$(grep -oE '/importaciones/[0-9]+$' <<<"$loc" | grep -oE '[0-9]+$' || true)
    est=""
    for _ in $(seq 1 30); do
      est=$(curl -s -b "$JAR" -H "Accept: application/json" "$URL/api/importaciones/$AN" | grep -oE '"estado":"[A-Z_]+"' | head -1 | cut -d'"' -f4)
      case "$est" in ANALIZADO|ANALIZADO_CON_ADVERTENCIAS|REVISION_MANUAL|ERROR) break;; esac; sleep 1
    done
    check "Worker analizó el libro tras verificarlo ($est)" '[ "$est" = "ANALIZADO" ]'
    APIAN=$(curl -s -b "$JAR" -H "Accept: application/json" "$URL/api/importaciones/$AN/analisis")
    check "Perfil detectado por contenido (TESORERIA_SANTANDER)" 'grep -q "\"perfil\":\"TESORERIA_SANTANDER\"" <<<"$APIAN"'
    check "Control de total CUADRA" 'grep -q "\"codigo\":\"TOTAL_IMPORTE\",\"estado\":\"CUADRA\"" <<<"$APIAN"'
    SHOW=$(curl -s -b "$JAR" "$URL/importaciones/$AN")
    check "Detalle muestra el análisis sin datos de personas" 'grep -q "Análisis del archivo" <<<"$SHOW" && ! grep -q "PERSONA FICTICIA" <<<"$SHOW"'
    echo "== FASE 4: normalización e identidades"
    NORM=""
    for _ in $(seq 1 30); do
      NORM=$(curl -s -b "$JAR" -H "Accept: application/json" "$URL/api/importaciones/$AN/normalizacion")
      grep -q '"registros":' <<<"$NORM" && break; sleep 1
    done
    check "Worker normalizó los registros (NORMALIZAR_REGISTROS encadenado)" 'grep -q "\"tipo_registro\":\"TESORERIA\"" <<<"$NORM" && grep -q "\"registros\":6" <<<"$NORM"'
    check "Tesorería nunca crea empleados (altas = 0)" 'grep -q "\"altas\":0" <<<"$NORM"'
    check "API de normalización sin datos de personas" '! grep -qi "PATERNO\|PERSONA" <<<"$NORM"'
    SHOW=$(curl -s -b "$JAR" "$URL/importaciones/$AN")
    check "Detalle muestra «Registros normalizados»" 'grep -q "Registros normalizados" <<<"$SHOW"'
    check "Módulo Empleados disponible" '[ "$(curl -s -o /dev/null -w "%{http_code}" -b "$JAR" "$URL/empleados")" = "200" ]'
    ASOC=$(curl -s -b "$JAR" "$URL/asociaciones"); CONC=$(curl -s -b "$JAR" "$URL/conceptos")
    check "Bandeja de Asociaciones disponible" 'grep -q "Registros importados que requieren decisión" <<<"$ASOC"'
    check "Catálogo de conceptos con pensión 044 por código" 'grep -q "<code>044</code>" <<<"$CONC"'
    echo "== FASE 5: conciliación del lote"
    curl -s -c "$JAR" -b "$JAR" -o "$OUT" "$URL/conciliaciones"; T=$(csrf)
    check "Módulo Conciliaciones con formulario de ejecución" 'grep -q "Nueva ejecución" "$OUT"'
    loc=$(curl -s -o /dev/null -w "%{redirect_url}" -c "$JAR" -b "$JAR" --data-urlencode "_token=$T" --data-urlencode "lote_id=$LOT" \
          --data-urlencode "motivo=Prueba de humo" --data-urlencode "solicitado_por=1" "$URL/conciliaciones")
    CID=$(grep -oE '/conciliaciones/[0-9]+$' <<<"$loc" | grep -oE '[0-9]+$' || true)
    check "Conciliación encolada (redirige a su tablero)" '[ -n "$CID" ]'
    if [ -n "$CID" ]; then
      CAPI=""; cest=""
      for _ in $(seq 1 60); do
        CAPI=$(curl -s -b "$JAR" -H "Accept: application/json" "$URL/api/conciliaciones/$CID")
        cest=$(grep -oE '"estado":"[A-Z_]+"' <<<"$CAPI" | head -1 | cut -d'"' -f4)
        case "$cest" in COMPLETADA|COMPLETADA_CON_INCIDENCIAS|REVISION_MANUAL|ERROR) break;; esac; sleep 1
      done
      check "Worker concilió el lote con CONCILIAR_LOTE ($cest)" '[ "$cest" = "REVISION_MANUAL" ] || [ "$cest" = "COMPLETADA_CON_INCIDENCIAS" ] || [ "$cest" = "COMPLETADA" ]'
      check "API de conciliación sin datos de personas" '! grep -qi "PATERNO\|PERSONA\|FICTICI" <<<"$CAPI"'
      CS=$(curl -s -b "$JAR" "$URL/conciliaciones/$CID")
      check "Tablero con tarjetas y sello de resultado provisional" 'grep -q "Total pagado" <<<"$CS" && grep -q "% conciliado" <<<"$CS" && grep -q "No oficial: resultado provisional" <<<"$CS"'
      check "Incidencias de la conciliación accesibles" '[ "$(curl -s -o /dev/null -w "%{http_code}" -b "$JAR" "$URL/conciliaciones/$CID/incidencias")" = "200" ]'
      check "Exportar por GET no está permitido (405)" '[ "$(curl -s -o /dev/null -w "%{http_code}" -b "$JAR" "$URL/conciliaciones/$CID/exportar")" = "405" ]'
      curl -s -c "$JAR" -b "$JAR" -o "$OUT" "$URL/conciliaciones/$CID"; T=$(csrf)
      EH=$(curl -s -D - -o /dev/null -b "$JAR" --data-urlencode "_token=$T" --data-urlencode "formato=csv" "$URL/conciliaciones/$CID/exportar")
      check "Exportación CSV por POST con no-store" 'grep -qi "cache-control: no-store" <<<"$EH" && grep -qi "text/csv" <<<"$EH"'
      echo "== FASE 6: beneficiarios, revisión masiva y reportes"
      check "Módulo Beneficiarios disponible" '[ "$(curl -s -o /dev/null -w "%{http_code}" -b "$JAR" "$URL/beneficiarios")" = "200" ]'
      RM=$(curl -s -b "$JAR" "$URL/revision-masiva")
      check "Bandeja de revisión masiva disponible" 'grep -q "Revisión masiva" <<<"$RM"'
      RV=$(curl -s -b "$JAR" "$URL/reportes/ver?tipo=resumen&conciliacion=$CID")
      check "Reporte ejecutivo en pantalla con sello de lote no oficial" 'grep -q "Cifras principales" <<<"$RV" && grep -q "LOTE NO DECLARADO OFICIAL" <<<"$RV"'
      curl -s -c "$JAR" -b "$JAR" -o "$OUT" "$URL/reportes"; T=$(csrf)
      curl -s -D "$OUT.h" -o "$OUT.pdf" -b "$JAR" --data-urlencode "_token=$T" --data-urlencode "tipo=resumen" --data-urlencode "conciliacion=$CID" --data-urlencode "formato=pdf" "$URL/reportes/exportar"
      check "PDF ejecutivo generado localmente (application/pdf, %PDF)" 'grep -qi "content-type: application/pdf" "$OUT.h" && head -c 5 "$OUT.pdf" | grep -q "%PDF"'
      TB=$(curl -s -b "$JAR" "$URL/conciliaciones/$CID")
      check "Tablero con gráficas accesibles (tabla de datos)" 'grep -q "Estado de las personas" <<<"$TB"'
      rm -f "$OUT.h" "$OUT.pdf"
      echo "== FASE 7: duplicados, fusiones y reportes programados"
      DUP=$(curl -s -b "$JAR" "$URL/empleados/duplicados")
      check "Bandeja de posibles duplicados disponible" 'grep -q "Posibles duplicados de empleados" <<<"$DUP"'
      check "Historial de fusiones disponible" '[ "$(curl -s -o /dev/null -w "%{http_code}" -b "$JAR" "$URL/empleados/fusiones")" = "200" ]'
      curl -s -c "$JAR" -b "$JAR" -o "$OUT" "$URL/reportes/programados/nuevo"; T=$(csrf)
      curl -s -o /dev/null -b "$JAR" --data-urlencode "_token=$T" --data-urlencode "nombre=Smoke resumen quincenal" --data-urlencode "tipo=resumen" \
        --data-urlencode "formato=PDF" --data-urlencode "frecuencia=QUINCENAL" --data-urlencode "hora=7" --data-urlencode "retencion_dias=30" "$URL/reportes/programados"
      PR=$(curl -s -b "$JAR" "$URL/reportes/programados")
      check "Programación quincenal creada con próxima ejecución" 'grep -q "Smoke resumen quincenal" <<<"$PR" && grep -q "Días 1 y 16" <<<"$PR"'
      check "Bandeja de reportes generados disponible" '[ "$(curl -s -o /dev/null -w "%{http_code}" -b "$JAR" "$URL/reportes/generados")" = "200" ]'
    fi
  fi
  check "storage/ no es accesible por web" '[ "$(curl -s -o /dev/null -w "%{http_code}" "$URL/storage/logs/app.log")" = "404" ] && [ "$(curl -s -o /dev/null -w "%{http_code}" "$URL/../storage/imports/")" != "200" ]'
else
  echo "  (fixtures ausentes: ejecute make fixtures para la parte de FASE 2)"
fi

echo "== Cierre de sesión"
T=$(csrf)
curl -s -o /dev/null -c "$JAR" -b "$JAR" --data-urlencode "_token=$T" "$URL/logout"
code=$(curl -s -o /dev/null -w "%{http_code}" -c "$JAR" -b "$JAR" "$URL/usuarios")
check "Tras logout, /usuarios redirige (303)" '[ "$code" = "303" ]'

echo "Resultado: $pass correctas, $fail fallidas"
[ "$fail" -eq 0 ]

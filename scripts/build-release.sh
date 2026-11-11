#!/usr/bin/env bash
# ConciliaRH — construye el paquete de PRODUCCIÓN (docs/DEPLOYMENT.md §2; FASE 9 §27–32, §48, §75).
#
#   bash scripts/build-release.sh            → dist/conciliarh-<versión>-production.zip (+ .sha256, MANIFEST, SBOM)
#   DIST=<carpeta> bash scripts/build-release.sh  → la misma salida en otra carpeta (nunca sobrescribe un ZIP existente)
#
# Garantías:
#   · parte de `git archive` del COMMIT actual (árbol limpio obligatorio): nada sin versionar (.env, storage, credenciales,
#     respaldos, Excel) puede entrar;
#   · LISTA BLANCA de rutas: sólo lo necesario para producción; fuentes, pruebas, Docker y documentación de desarrollo
#     se quedan en el repositorio;
#   · vendor/ con `composer install --no-dev` (producción no ejecuta Composer ni pip contra Internet si no quiere);
#   · BUILD.json (versión, commit, fecha), MANIFEST.sha256 de cada archivo, SBOM CycloneDX y SHA-256 del ZIP;
#   · escaneo final: si aparece un secreto, una llave privada, un Excel, un respaldo o un artefacto de desarrollo, FALLA.
# No contiene secretos ni ejecuta nada del paquete.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"
for bin in git composer php zip sha256sum; do command -v "$bin" >/dev/null || { echo "Falta $bin" >&2; exit 1; }; done
if [ -n "$(git status --porcelain)" ] && [ "${ALLOW_DIRTY:-0}" != 1 ]; then
  echo "El árbol de trabajo tiene cambios sin commit: el paquete sólo se construye desde un commit." >&2; exit 1
fi
VERSION="$(tr -d '[:space:]' < VERSION)"
[[ "$VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.]+)?$ ]] || { echo "VERSION inválida" >&2; exit 1; }
COMMIT="$(git rev-parse HEAD)"
BUILT_AT="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
NAME="conciliarh-$VERSION"
DIST="${DIST:-$ROOT/dist}"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

echo "→ Exportando el commit ${COMMIT:0:12}"
mkdir -p "$WORK/src" "$WORK/$NAME"
git archive --format=tar HEAD | tar -x -C "$WORK/src"

echo "→ Dependencias PHP de producción (composer install --no-dev)"
# Las extensiones se exigen en el SERVIDOR (bin/preflight.php), no en el equipo que empaqueta: se ignoran aquí. La versión de
# PHP objetivo la fija composer.json (config.platform.php = 8.3.0).
( cd "$WORK/src" && COMPOSER_ALLOW_SUPERUSER=0 composer install --no-dev --no-interaction --no-progress --no-scripts \
    --prefer-dist --optimize-autoloader --classmap-authoritative --ignore-platform-req='ext-*' >/dev/null )

echo "→ Copiando la lista blanca"
S="$WORK/src"; P="$WORK/$NAME"
copy() { # copy <ruta relativa> — conserva la estructura
  local rel="$1"; [ -e "$S/$rel" ] || { echo "No existe $rel" >&2; exit 1; }
  mkdir -p "$P/$(dirname "$rel")"; cp -a "$S/$rel" "$P/$rel"
}
for rel in app config public vendor database/migrations deploy \
           bin/bootstrap.php bin/_cli.php bin/create-god.php bin/god-recovery.php bin/audit-anchor.php bin/maintenance.php \
           bin/migrate.php bin/preflight.php bin/worker.php \
           bin/rotate-account-hmac.php bin/rotate-account-key.php bin/rotate-report-key.php bin/scheduler.php \
           bin/verificar-almacenamiento.php scripts/backup.sh scripts/restore.sh \
           composer.json composer.lock .env.example VERSION \
           docs/DEPLOYMENT.md docs/OPERATIONS_RUNBOOK.md docs/RESTORE_RUNBOOK.md docs/BACKUP_RESTORE.md \
           docs/DEPENDENCIES.md docs/BREAK_GLASS_RECOVERY.md; do
  copy "$rel"
done
# Coherencia del paquete: un comando nuevo que no entre en la lista blanca rompe la instalación (pasó con la rc.2 antes de publicarse)
for f in "$S"/bin/*.php; do
  n="$(basename "$f")"
  case "$n" in dev-*.php) continue ;; esac                      # herramientas de desarrollo: nunca en producción
  [ -f "$P/bin/$n" ] || { echo "bin/$n existe en el repositorio y NO está en la lista blanca del paquete" >&2; exit 1; }
done
grep -oE '"\$APP/bin/[a-z_-]+\.php"' "$P/deploy/bin/conciliarh-run" | sed -E 's#"\$APP/##; s#"$##' | sort -u | while read -r rel; do
  [ -f "$P/$rel" ] || { echo "conciliarh-run invoca $rel, que no está en el paquete" >&2; exit 1; }
done
grep -ohE "require(_once)? __DIR__ \. '/[A-Za-z_.-]+\.php'" "$P"/bin/*.php | sed -E "s#.*'/##; s#'\$##" | sort -u | while read -r n; do
  [ -f "$P/bin/$n" ] || { echo "Un comando de bin/ requiere bin/$n, que no está en el paquete" >&2; exit 1; }
done
find "$P/vendor" -type d \( -iname tests -o -iname test -o -iname docs -o -name .github \) -prune -exec rm -rf {} +
find "$P/vendor" -type f \( -name '*.md' -o -name 'phpunit.xml*' -o -name '.gitattributes' -o -name '.gitignore' -o -name '.editorconfig' \) -delete
# Estructura vacía de storage para instalaciones de un solo directorio (en producción se usa STORAGE_PATH)
mkdir -p "$P/storage"

cat > "$P/BUILD.json" <<JSON
{"version": "$VERSION", "commit": "$COMMIT", "built_at": "$BUILT_AT"}
JSON
cat > "$P/INSTALL.txt" <<TXT
ConciliaRH $VERSION — Sistema Integral de Conciliación de Nómina y Pagos
Tesorería Municipal

Instalación y actualización:    docs/DEPLOYMENT.md
Operación diaria y fallas:      docs/OPERATIONS_RUNBOOK.md
Restauración:                   docs/RESTORE_RUNBOOK.md
Verificación de la instalación: php bin/preflight.php  (sólo lectura)

Integridad del paquete: sha256sum -c MANIFEST.sha256
Build: commit $COMMIT · $BUILT_AT
TXT

echo "→ SBOM (CycloneDX 1.5)"
php -r '
[$p, $version, $commit, $built] = array_slice($argv, 1);
$comps = [];
$lock = json_decode(file_get_contents("$p/composer.lock"), true, 512, JSON_THROW_ON_ERROR);
foreach ($lock["packages"] ?? [] as $pkg) {
    $v = ltrim($pkg["version"], "v");
    $c = ["type" => "library", "name" => $pkg["name"], "version" => $v, "purl" => "pkg:composer/{$pkg["name"]}@$v"];
    if (!empty($pkg["license"])) {
        $c["licenses"] = array_map(fn ($l) => ["license" => ["id" => $l]], $pkg["license"]);
    }
    $c["properties"] = [["name" => "conciliarh:ecosistema", "value" => "php (vendor/)"]];
    $comps[] = $c;
}
$front = [];
$bs = substr((string) file_get_contents("$p/public/assets/vendor/bootstrap/bootstrap.min.css"), 0, 400);
$front[] = ["bootstrap", preg_match("/Bootstrap\s+v([\d.]+)/", $bs, $m) ? $m[1] : "desconocida", "MIT", "pkg:npm/bootstrap@"];
$bi = substr((string) file_get_contents("$p/public/assets/vendor/bootstrap-icons/bootstrap-icons.min.css"), 0, 400);
$front[] = ["bootstrap-icons", preg_match("/Bootstrap Icons v([\d.]+)/", $bi, $m) ? $m[1] : "desconocida", "MIT", "pkg:npm/bootstrap-icons@"];
$front[] = ["@fontsource/montserrat (woff2 latin 400–700)", "5", "OFL-1.1", "pkg:npm/%40fontsource/montserrat@"];
foreach ($front as [$name, $ver, $lic, $purl]) {
    $comps[] = ["type" => "library", "name" => $name, "version" => $ver, "purl" => $purl . $ver, "licenses" => [["license" => ["id" => $lic]]],
                "properties" => [["name" => "conciliarh:ecosistema", "value" => "front-end local (public/assets/vendor, sin CDN)"]]];
}
$u = bin2hex(random_bytes(16));
$uuid = vsprintf("%s-%s-4%s-%x%s-%s", [substr($u, 0, 8), substr($u, 8, 4), substr($u, 13, 3), 8 | (hexdec($u[16]) & 3), substr($u, 17, 3), substr($u, 20, 12)]);
$bom = ["bomFormat" => "CycloneDX", "specVersion" => "1.5", "serialNumber" => "urn:uuid:$uuid", "version" => 1,
        "metadata" => ["timestamp" => $built, "component" => ["type" => "application", "name" => "conciliarh", "version" => $version,
                       "properties" => [["name" => "conciliarh:commit", "value" => $commit]]]],
        "components" => $comps];
file_put_contents("$p/SBOM.cdx.json", json_encode($bom, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
echo "   " . count($comps) . " componentes\n";
' "$P" "$VERSION" "$COMMIT" "$BUILT_AT"

echo "→ Escaneo de seguridad del contenido"
fail=0
bad_names="$(cd "$P" && find . \( -name '.env' -o -name '*.env' -o -name '.git' -o -name '.claude' -o -name 'Dockerfile' \
  -o -name 'compose.yml' -o -name 'docker-compose*' -o -name 'tests' -o -name 'fixtures' -o -name 'screenshots' -o -name 'node_modules' \
  -o -name 'venv' -o -name '.venv' -o -name '__pycache__' -o -iname '*.xls' -o -iname '*.xlsx' -o -iname '*.xlsm' -o -iname '*.csv' \
  -o -name '*.dump' -o -name '*.gpg' -o -name '*.sig' -o -name '*.pem' -o -name '*.key' -o -name '*.p12' -o -name '*.pfx' -o -name 'id_rsa*' \
  -o -name 'dev-credenciales*' -o -name 'skills-lock.json' -o -name 'Makefile' -o -name 'phpunit.xml' -o -name 'dev-*.php' -o -name 'dev-*.sh' \
  -o -name '*.log' -o -name 'pgpass' -o -name '.pgpass' \) -not -path './vendor/*/src/*' -print)"
if [ -n "$bad_names" ]; then echo "PROHIBIDO en el paquete:"; echo "$bad_names"; fail=1; fi
# Contenido: llaves privadas, asignaciones de secretos con valor, contraseñas de BD en claro
if grep -rIlE -- '-----BEGIN ([A-Z]+ )?PRIVATE KEY-----' "$P" | grep -v '/vendor/' ; then echo "Llave privada en el paquete"; fail=1; fi
# (los ejemplos de la documentación usan $(…), <…> o {…}: no son valores)
if grep -rIhE '^(APP_KEY|ACCOUNT_HMAC_KEY)=[0-9a-f]{32,}|^(DB_PASSWORD|DB_MIGRATE_PASSWORD|WORKER_DB_PASSWORD|SCHEDULER_DB_PASSWORD|PGPASSWORD)=[^[:space:]$<{(`"]{8,}' "$P" --exclude-dir=vendor; then
  echo "Secreto con valor en el paquete"; fail=1; fi
if grep -rIhE '(ACCOUNT_ENC_KEYS|REPORT_ENC_KEYS|ACCOUNT_HMAC_KEYS)=[a-z0-9_-]+:[0-9a-f]{64}' "$P" --exclude-dir=vendor; then echo "Anillo de llaves con valor en el paquete"; fail=1; fi
[ "$fail" = 0 ] || { echo "El paquete NO se generó." >&2; exit 1; }
echo "   sin hallazgos"

echo "→ Manifiesto y ZIP"
( cd "$P" && find . -type f ! -name MANIFEST.sha256 -print0 | LC_ALL=C sort -z | xargs -0 sha256sum > MANIFEST.sha256 )
mkdir -p "$DIST"
ZIP="$DIST/$NAME-production.zip"
[ ! -e "$ZIP" ] || { echo "Ya existe $ZIP: no se sobrescribe un paquete construido (use DIST=<otra carpeta>)." >&2; exit 1; }
# Fechas uniformes (reproducible) y sin atributos extra del sistema de archivos
find "$WORK/$NAME" -exec touch -h -d "@$(git log -1 --format=%ct)" {} +
( cd "$WORK" && find "$NAME" -print | LC_ALL=C sort | zip -X -q -9 "$ZIP" -@ )
( cd "$DIST" && sha256sum "$(basename "$ZIP")" > "$(basename "$ZIP").sha256" )
cp "$P/MANIFEST.sha256" "$DIST/$NAME-MANIFEST.sha256"
cp "$P/SBOM.cdx.json" "$DIST/$NAME-SBOM.cdx.json"
echo "Paquete: $ZIP"
echo "Tamaño:  $(du -h "$ZIP" | cut -f1) · $(wc -l < "$P/MANIFEST.sha256") archivos"
echo "SHA-256: $(cut -d' ' -f1 "$ZIP.sha256")"

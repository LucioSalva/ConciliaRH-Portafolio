# Dependencias (inventario y auditoría)

Inventario de todo el software de terceros que se ejecuta en producción. El paquete incluye además un **SBOM CycloneDX 1.5** (`SBOM.cdx.json`), generado por `scripts/build-release.sh` desde `composer.lock` y los recursos de interfaz incluidos. El sistema es 100 % PHP: el servidor no necesita Python ni paquetes de `pip`.

**Auditoría de la Release Candidate (2026-09-30):** (en esa fecha también se auditaron con `pip-audit` las dependencias del motor anterior en Python, sin vulnerabilidades; ese motor ya no forma parte del sistema)

| Herramienta | Alcance | Resultado |
|---|---|---|
| `composer audit --no-dev` | Dependencias PHP de producción | **Sin vulnerabilidades conocidas** |
| `composer audit` | Incluye las de desarrollo (PHPUnit) | Sin vulnerabilidades conocidas |
| Revisión manual | Bootstrap, Bootstrap Icons, Montserrat (locales) | Versiones vigentes de su rama; sin avisos aplicables |

Se repite **trimestralmente** y antes de cada versión (OPERATIONS_RUNBOOK.md §11). Una versión mayor no se adopta sin necesidad: requiere su propia fase con pruebas completas.

## PHP (`vendor/`, sin dependencias de desarrollo)

| Paquete | Versión | Licencia | Uso | Observaciones |
|---|---|---|---|---|
| `dompdf/dompdf` | 3.1.6 | LGPL-2.1 | PDF de reportes | Sin recursos remotos ni acceso a archivos (`isRemoteEnabled=false`, chroot) |
| `dompdf/php-font-lib` | 1.0.2 | LGPL-2.1-or-later | Dependencia de dompdf | |
| `dompdf/php-svg-lib` | 1.0.2 | LGPL-3.0-or-later | Dependencia de dompdf | |
| `masterminds/html5` | 2.11.0 | MIT | Dependencia de dompdf | |
| `sabberworm/php-css-parser` | 9.5.0 | MIT | Dependencia de dompdf | |
| `openspout/openspout` | 4.32.0 | MIT | XLSX en flujo | Existe la 5.x (mayor): no se actualiza en la RC; la rama 4.x funciona y no tiene avisos |
| `phpoffice/phpspreadsheet` | 5.9.0 | MIT | Procesador en segundo plano: lector BIFF de libros `.xls` (`app/Engine/Workbook/XlsReader.php`) | Sólo lectura; no evalúa fórmulas ni ejecuta macros. Los `.xlsx`/`.xlsm` los lee un parser propio en streaming (`OoxmlReader.php`, XMLReader). Requiere la extensión `gd` |
| `composer/pcre` | 3.4.0 | MIT | Dependencia de PhpSpreadsheet | |
| `maennchen/zipstream-php` | 3.2.2 | MIT | Dependencia de PhpSpreadsheet | No se usa para leer |
| `markbaker/complex` | 3.0.2 | MIT | Dependencia de PhpSpreadsheet | |
| `markbaker/matrix` | 3.0.1 | MIT | Dependencia de PhpSpreadsheet | |
| `psr/simple-cache` | 3.0.0 | MIT | Dependencia de PhpSpreadsheet | |

Sólo desarrollo, **no** se empaqueta: `phpunit/phpunit` 12.x y sus dependencias.

## Extensiones de PHP

Las que verifica `bin/preflight.php`: `pdo_pgsql`, `sodium`, `openssl`, `mbstring`, `bcmath`, `zip`, `fileinfo`, `intl`, `xmlreader`, `dom`, `iconv` (sección 1) y, para el procesador en segundo plano (sección 6), `ctype`, `filter`, `gd`, `libxml`, `simplexml`, `xml`, `xmlwriter` y `zlib`. `gd` la exige PhpSpreadsheet (en Debian/Ubuntu, paquete `php8.x-gd`). `pcntl` es opcional: permite que el procesador termine el trabajo en curso al recibir SIGTERM; sin ella, el trabajo se recupera al vencer su lease (en Debian/Ubuntu viene incluida en `php-cli`; se comprueba con `php -m`).

Las pruebas usan fixtures Excel ficticios generados en PHP (`tests/Fixtures/*.php`, `make fixtures`); no se requiere `pytest` ni `xlwt`.

## Interfaz (servida localmente desde `public/assets/vendor`; sin CDN)

| Recurso | Versión | Licencia |
|---|---|---|
| Bootstrap (CSS y JS *bundle*) | 5.3.8 | MIT |
| Bootstrap Icons | 1.13.1 | MIT |
| Montserrat (woff2 latin 400–700, @fontsource) | 5 | OFL-1.1 |

El JavaScript propio son módulos ES sin dependencias. 

## Plataforma

| Componente | Mínimo | Probado |
|---|---|---|
| PHP | 8.3 | 8.3.35 (desarrollo), 8.4 (instalación limpia en Debian 13) |
| PostgreSQL | 14 | 17.x |
| Apache | 2.4.7 | 2.4.x |

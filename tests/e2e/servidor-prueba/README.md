# Servidor de prueba de la Release Candidate

Herramientas de **prueba** (no de producción) para validar el paquete como lo instalaría un operador, en un servidor limpio
**Debian 13 con systemd**, sin nada de la infraestructura de desarrollo. Docker sólo hace las veces de máquina virtual.

| Archivo | Para qué |
|---|---|
| `Dockerfile` | Imagen Debian 13 + systemd con EXACTAMENTE los paquetes de docs/DEPLOYMENT.md §1 |
| `instalar-segun-deployment.sh` | Ejecuta docs/DEPLOYMENT.md §1–11 al pie de la letra (contraseñas, llaves y certificado de prueba generados al vuelo) |
| `servidor-limpio.sh <zip>` | Crea el servidor limpio, instala el ZIP y espera al worker |
| `respaldo-servidor.sh` | Configura el respaldo (§15) con llaves GPG de prueba y lo ejecuta con su servicio |
| `restaurar-servidor.sh` | RESTORE_RUNBOOK.md §2 hacia destinos nuevos y comparación completa (huellas de BD y SHA-256) |
| `instalar-alpha8.sh <zip-rc>` | Entorno alpha.8 realista (código del tag) para probar la actualización |
| `actualizar.sh <zip>` | DEPLOYMENT.md §12 con huellas antes/después |
| `huella-bd.sql` | Huellas de la BD (conteos, sumas de verificación de resultados, auditoría) sin datos personales |

Uso típico (ver docs/TESTING.md, «Release Candidate»). Los *harness* son PHP (`tests/e2e/*.php`, con la biblioteca
`tests/e2e/lib/Cliente.php`; sin Composer). En la estación de trabajo se necesita PHP ≥ 8.3 con `curl` (y `pcntl` para
medir el pico de conexiones en la prueba de carga); dentro del servidor basta su `php-cli`. `CRH_CONTAINER`,
`CRH_HTTPS_PORT` y `CRH_HTTP_PORT` permiten tener más de un servidor de prueba a la vez (por omisión `crh-rc`, 18443 y 18080).
Desde la migración 0026 sólo existen los roles GOD, ADMIN y CONSULTA: `release_e2e.php` crea `e2e.admin` y `e2e.admin2`
(ADMIN; el segundo aprueba la fusión que solicita el primero) y `e2e.consulta`; `pentest_rc.php` crea `pt.admin`, `pt.admin2`
y `pt.consulta`, y toma los permisos esperados de la BD.

```bash
docker build -t conciliarh-rc-server:debian13 tests/e2e/servidor-prueba
bash scripts/build-release.sh
php tests/e2e/generar-lote-ficticio.php "$LOTE"            # lote ficticio (incluye quincena/ con Bomberos y fusion/)
GOD_PASS_FILE="$PASS" bash tests/e2e/servidor-prueba/servidor-limpio.sh dist/conciliarh-<versión>-production.zip
php tests/e2e/release_e2e.php --url https://127.0.0.1:18443 --insecure --lote "$LOTE" --god-user god.prueba --god-pass-file "$PASS" \
    --cli "docker exec -i crh-rc conciliarh-run" --sql "docker exec crh-rc sudo -u postgres psql -d conciliarh -XAtc"
docker cp tests/e2e/servidor-prueba/respaldo-servidor.sh crh-rc:/root/ && docker exec crh-rc bash /root/respaldo-servidor.sh
docker cp tests/e2e/servidor-prueba/restaurar-servidor.sh tests/e2e/servidor-prueba/huella-bd.sql crh-rc:/root/   # (uno por uno)
docker exec crh-rc bash /root/restaurar-servidor.sh
DB=$(docker exec crh-rc sed -n 's/^DB_DATABASE=//p' /etc/conciliarh/web.env)      # tras restaurar: conciliarh_r1
php tests/e2e/pentest_rc.php --url https://127.0.0.1:18443 --insecure --god-user god.prueba --god-pass-file "$PASS" \
    --sql "docker exec crh-rc sudo -u postgres psql -d $DB -XAtc" --lote "$LOTE"
php tests/e2e/carga_rc.php --url https://127.0.0.1:18443 --insecure --usuarios god.prueba:"$PASS" --lote "$LOTE" \
    --sql "docker exec crh-rc sudo -u postgres psql -d $DB -XAtc"
docker cp tests/e2e/break_glass_rc.php crh-rc:/root/ && docker cp tests/e2e/lib crh-rc:/root/
docker exec crh-rc php /root/break_glass_rc.php --god-user god.prueba
(umask 077; docker exec crh-rc cat /root/god-pass > "$PASS")                    # break-glass cambió la contraseña del GOD
php tests/e2e/release_fallas.php --url https://127.0.0.1:18443 --insecure --root "docker exec crh-rc" \
    --god-user god.prueba --god-pass-file "$PASS" --lote "$LOTE"                     # SIEMPRE al final
```

**Orden:** `respaldo-servidor.sh` y `restaurar-servidor.sh` se ejecutan **dentro** del servidor y
antes de `release_fallas.php`: las fallas usan la configuración del respaldo (`/etc/conciliarh/restore.env`) y dejan a
propósito reportes alterados, que la salud reporta como ERROR durante 30 días (una restauración posterior no pasaría el
`postdeploy`). `break_glass_rc.php` restablece la contraseña del GOD de prueba (queda en `/root/god-pass` y obliga a
cambiarla); `release_fallas.php` la cambia por una aleatoria al entrar y la guarda en `--god-pass-file`.

Las contraseñas y llaves se generan dentro del servidor de prueba y se destruyen con él.

# Runbook de restauración

Procedimiento paso a paso para recuperar ConciliaRH desde un respaldo de `scripts/backup.sh`. Complementa `docs/BACKUP_RESTORE.md` (qué se respalda y cómo funciona) y `docs/DEPLOYMENT.md`.

**Principios:**
- **Nunca se restaura encima de una instalación en uso.** `restore.sh` exige una BD **vacía** y un almacenamiento **vacío**: la restauración va siempre a destinos nuevos y después se cambia la configuración para apuntar a ellos.
- **Primero se verifica, después se toca.** La firma y las sumas `SHA256SUMS` se comprueban antes de descifrar o escribir nada.
- **Todo o nada.** La BD se restaura en una sola transacción.
- La base anterior se **conserva** (renombrada) hasta confirmar el resultado.

| § | Situación |
|---|---|
| 1 | Antes de empezar: qué se necesita |
| 2 | Restauración en el mismo servidor (pérdida o corrupción de datos, rollback de una versión) |
| 3 | Restauración en un servidor nuevo (pérdida del servidor) |
| 4 | Prueba periódica de restauración (entorno aislado) |
| 5 | Si algo falla |

---

## 1. Antes de empezar

| Se necesita | Dónde está | Sin ello |
|---|---|---|
| Carpeta del respaldo `conciliarh-AAAAMMDDTHHMMSSZ/` | `/var/backups/conciliarh/` o la copia fuera del sitio | — |
| Llave **privada** GPG de descifrado (si el respaldo está cifrado) | Bóveda del área responsable | No se puede descifrar |
| Llave **pública** del firmante | Llavero de quien restaura | `RESTORE_REQUIRE_SIGNATURE=1` rechaza el respaldo |
| `ACCOUNT_ENC_KEYS`, `ACCOUNT_HMAC_KEYS`, `REPORT_ENC_KEYS` y `APP_KEY` **con los que se generó** el respaldo | Bóveda (DEPLOYMENT.md §6) | Las cuentas y los reportes cifrados no se pueden leer. La conciliación con datos no cifrados sí funciona |
| Paquete de la **misma versión** que generó el respaldo | `manifest.json` → `version` y `migraciones` | Restaure con esa versión y luego actualice (DEPLOYMENT.md §12) |
| DBA con acceso de superusuario | — | No se pueden crear la base ni los roles |

Decida el **punto de recuperación** (RPO): el respaldo más reciente **anterior** al incidente. Si el incidente es una alteración, un respaldo posterior podría contenerla.

## 2. Restauración en el mismo servidor

### 2.1 Detener la escritura

```bash
# conciliarh-run maintenance on --motivo="Restauración desde respaldo"
# systemctl stop conciliarh-scheduler.timer conciliarh-worker conciliarh-backup.timer
```

La web responde 503 a todos salvo `/api/health`.

### 2.2 Verificar el respaldo sin restaurar

```bash
# R=/var/backups/conciliarh/conciliarh-20261001T020000Z
# cat $R/manifest.json                          # versión, migraciones, conteos, fecha (sin datos personales)
# cd $R && gpg --verify SHA256SUMS.sig SHA256SUMS && sha256sum -c SHA256SUMS
```

Ambos deben terminar sin error. Si la firma no es válida, **no continúe**: el respaldo pudo ser sustituido (§5).

### 2.3 Destinos vacíos

El DBA crea una base nueva con dueño `conciliarh_owner`; el nombre lleva la fecha para no confundirla:

```bash
$ sudo -u postgres psql -v ON_ERROR_STOP=1 -d postgres -c "CREATE DATABASE conciliarh_r20261001 OWNER conciliarh_owner TEMPLATE template0 ENCODING 'UTF8'" \
      -c "REVOKE ALL ON DATABASE conciliarh_r20261001 FROM PUBLIC" \
      -c "GRANT CONNECT ON DATABASE conciliarh_r20261001 TO conciliarh_web, conciliarh_worker, conciliarh_scheduler, conciliarh_backup" \
      -c "ALTER DATABASE conciliarh_r20261001 SET timezone TO 'America/Mexico_City'"
$ sudo -u postgres psql -v ON_ERROR_STOP=1 -d conciliarh_r20261001 -c "ALTER SCHEMA public OWNER TO conciliarh_owner" \
      -c "REVOKE ALL ON SCHEMA public FROM PUBLIC" \
      -c "GRANT USAGE ON SCHEMA public TO conciliarh_web, conciliarh_worker, conciliarh_scheduler, conciliarh_backup" \
      -c "GRANT pg_read_all_data TO conciliarh_backup"
```

Los **cinco roles** deben existir en el clúster (DEPLOYMENT.md §3.1): el volcado incluye sus privilegios y la restauración falla si falta uno.

Si `pg_hba.conf` lista bases por nombre (DEPLOYMENT.md §3.2), agregue la nueva: `host conciliarh,conciliarh_r20261001 …` y
`systemctl reload postgresql`.

Almacenamiento destino vacío, en el mismo volumen que el actual:

```bash
# install -d -o root -g conciliarh-data -m 2770 /var/lib/conciliarh-r20261001
```

### 2.4 Restaurar

Como **root** (los respaldos son `0700` del usuario de respaldo y nadie más los lee; los archivos restaurados se reasignan en §2.5)
y con las credenciales del **propietario** de la BD (nunca un superusuario de PostgreSQL: `restore.sh` lo rechaza):

```bash
# systemd-run --wait --pipe --collect \
      -p EnvironmentFile=/etc/conciliarh/restore.env \
      -E PGDATABASE=conciliarh_r20261001 -E CONCILIARH_STORAGE=/var/lib/conciliarh-r20261001 -E RESTORE_REQUIRE_SIGNATURE=1 \
      -E GNUPGHOME=/root/.gnupg-restauracion \
      /opt/conciliarh/current/scripts/restore.sh /var/backups/conciliarh/conciliarh-20261001T020000Z
```

`/etc/conciliarh/restore.env` (0600 root) contiene `PGHOST`, `PGPORT` y `PGUSER=conciliarh_owner`, más `PGPASSWORD` o `PGPASSFILE`. Si el respaldo está cifrado, la llave privada se importa **sólo para esta operación** en un llavero temporal y se elimina al terminar.

`restore.sh`:
1. verifica firma y sumas;
2. descifra en flujo;
3. restaura la BD en una transacción;
4. extrae el almacenamiento;
5. comprueba que las migraciones coinciden con el manifiesto;
6. comprueba la **cadena de auditoría**;
7. comprueba el **SHA-256 de cada original** contra la BD.

Termina con código 0 sólo si todo coincide.

### 2.5 Permisos y verificación

Lo más simple es **conservar las rutas**: renombrar el almacenamiento actual y poner el restaurado en su lugar. El archivo de
mantenimiento vive en el almacenamiento, así que se vuelve a activar en el restaurado antes de nada:

```bash
# mv /var/lib/conciliarh /var/lib/conciliarh-incidente-20261001 && mv /var/lib/conciliarh-r20261001 /var/lib/conciliarh
# STORAGE_PATH=/var/lib/conciliarh bash /opt/conciliarh/current/deploy/setup/preparar-sistema.sh   # dueños y modos (originales 0440)
# conciliarh-run maintenance on --motivo="Restauración: verificación"
```

Apunte la configuración a la base restaurada (`DB_DATABASE=conciliarh_r20261001` en `web.env`, `worker.env` y `scheduler.env`;
`PGDATABASE` en `backup.env` y en su `PGPASSFILE`) y vuelva a ejecutar `deploy/postgres/02-despues-de-migrar.sql` sobre ella.
(Si en lugar de renombrar cambia `STORAGE_PATH`, actualice también `ReadOnlyPaths`/`ReadWritePaths` de las unidades systemd y
`open_basedir` del pool de PHP-FPM.)

```bash
# conciliarh-run preflight
# conciliarh-run storage-check --hash          # 0 faltantes, 0 alterados
```

### 2.6 Reabrir

```bash
# systemctl reload php8.4-fpm && systemctl start conciliarh-worker conciliarh-scheduler.timer conciliarh-backup.timer
# conciliarh-run maintenance off
# conciliarh-run postdeploy
```

Comprobaciones manuales:
- Un usuario con permiso descarga un reporte programado cifrado; esto valida `REPORT_ENC_KEYS`.
- Otro revela una cuenta; esto valida `ACCOUNT_ENC_KEYS`.
- `/auditoria` muestra el registro más reciente esperado.

Anote en la bitácora del incidente:
- la hora de inicio y de fin (**RTO real**);
- la fecha del respaldo usado (**RPO real**);
- quién lo ejecutó.

### 2.7 Después

Conserve la base y el almacenamiento anteriores, renombrados (por ejemplo `conciliarh_incidente_20261001`), hasta cerrar el análisis del incidente. **No los borre** sin autorización del responsable.

## 3. Restauración en un servidor nuevo

1. Prepare el servidor **completo** con docs/DEPLOYMENT.md §1–10, **sin** ejecutar `migrate` ni `create-god`. La base debe quedar vacía.
2. Use el paquete de la **misma versión** que `manifest.json`.
3. Coloque los archivos de entorno con las **llaves originales** de la bóveda, no con llaves nuevas.
4. Siga §2.2–2.6, usando como destino la base `conciliarh` recién creada (vacía) y `/var/lib/conciliarh` (vacío).
5. Si la versión del respaldo es anterior a la que se quiere operar, actualice después con DEPLOYMENT.md §12.

## 4. Prueba periódica de restauración

Se hace **trimestralmente**, y además antes de la puesta en producción, en un entorno **aislado**: otra máquina u otra base y otro almacenamiento, sin acceso de usuarios. Pasos:

1. Copiar el respaldo más reciente al entorno de prueba.
2. §2.2–2.5 completos.
3. `conciliarh-run postdeploy` sin errores.
4. Comparar conteos con `manifest.json` (archivos, reportes) y una muestra de totales de conciliación con producción.
5. Descargar un reporte cifrado y revelar una cuenta (llaves correctas).
6. Registrar duración, resultado y diferencias.
7. **Destruir** el entorno de prueba: contiene datos reales.

Resultado de la prueba de la Release Candidate: la suite de pruebas, «Respaldo y restauración como prueba de release».

## 5. Si algo falla

| Síntoma | Causa probable | Qué hacer |
|---|---|---|
| `La firma de SHA256SUMS no es válida` | Respaldo sustituido o firma con otra llave | **Detenerse.** Usar otra copia (fuera del sitio) y reportar el incidente de seguridad |
| `SHA256SUMS no coincide` | Copia dañada o incompleta | Usar otra copia; revisar el medio de transporte |
| `La base de datos destino no está vacía` | Destino equivocado | Crear una base nueva (§2.3); nunca vaciar la de producción |
| `role "conciliarh_…" does not exist` | Falta un rol en el clúster destino | Crear los cinco roles (DEPLOYMENT.md §3.1) y repetir en una base nueva |
| `Restauración como superusuario rechazada` | `PGUSER` es superusuario | Usar `conciliarh_owner` |
| La cadena de auditoría no verifica | Volcado alterado o restauración parcial | **Detenerse**; conservar evidencia; usar otro respaldo |
| Originales con SHA-256 distinto | Almacenamiento alterado en el respaldo | Igual que el anterior |
| La web arranca pero no descarga reportes (503) | `REPORT_ENC_KEYS` no son las del respaldo | Colocar las llaves originales; nunca generar nuevas sobre datos cifrados |
| Cuentas «no descifrables» | `ACCOUNT_ENC_KEYS` distintas | Igual que el anterior |

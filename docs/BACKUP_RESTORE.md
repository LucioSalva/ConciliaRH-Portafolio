# Respaldo y restauración (FASE 8)

## 1. Qué se respalda

| Componente | Cómo | Por qué |
|---|---|---|
| Base de datos completa | `pg_dump --format=custom` | Datos, auditoría con su cadena de hashes, identidades, conciliaciones, reportes (metadatos y huellas) y privilegios de los roles |
| Originales importados | `storage/imports` (tar, sin `.staging`) | Inmutables; son la evidencia |
| Reportes generados persistentes | `storage/reportes/generados` | Los que tienen personas ya van cifrados |
| Configuración no secreta | `manifest.json`: versión, migraciones, conteos y **lista** de variables requeridas, sin valores | Para reconstruir el entorno |

**No se respalda:**
- temporales (`storage/trabajo`, `cache`) y logs;
- credenciales de desarrollo;
- **ninguna llave ni contraseña**.

## 2. Llaves: aparte y con el mismo cuidado

Sin `ACCOUNT_ENC_KEYS` las cuentas y CLABE no se pueden descifrar. Sin `REPORT_ENC_KEYS` los reportes cifrados no se pueden leer. Sin `ACCOUNT_HMAC_KEYS` el matching de cuentas no reconoce las huellas.

Resguárdelas **fuera del respaldo de datos**: bóveda de secretos, o sobre sellado físico con dos custodios. Documente dónde, sin copiar los valores en ningún documento del proyecto.

**Un respaldo sin sus llaves sólo recupera datos no cifrados.**

## 3. Respaldo

En producción el respaldo corre con `conciliarh-backup.timer` y el rol **`conciliarh_backup`**: sólo lectura
(`pg_read_all_data` + `BYPASSRLS` para la tabla con RLS), sin poder escribir datos (docs/DEPLOYMENT.md §3 y §15). A mano, con las
mismas variables:

```bash
# Credenciales por entorno (nunca en el script): PGPASSWORD o PGPASSFILE (0600)
export PGHOST=127.0.0.1 PGPORT=5432 PGDATABASE=conciliarh PGUSER=conciliarh_backup
export CONCILIARH_STORAGE=/var/lib/conciliarh BACKUP_DIR=/var/backups/conciliarh
export BACKUP_GPG_RECIPIENT=respaldos@municipio.example   # opcional: cifrado con llave PÚBLICA (recomendado)
export BACKUP_RETENTION_DAYS=35                          # opcional
scripts/backup.sh
```

**Resultado:** `BACKUP_DIR/conciliarh-AAAAMMDDTHHMMSSZ/` con `db.dump`, `storage.tar`, `manifest.json` y `SHA256SUMS` (o sus `.gpg`).

El script:
- **verifica** las sumas y que el volcado sea legible;
- registra la ejecución en la salud del sistema;
- devuelve código 0 sólo si el respaldo quedó verificado.

**Operación sugerida:**
- **Programación:** diario a las 02:30 con un timer de systemd (`OnCalendar=*-*-* 02:30`, `Persistent=true`).
- **Retención:** 35 diarios más 12 mensuales copiados a otro medio.
- **Copia fuera del servidor:** al menos una, en otro edificio o en un almacenamiento institucional.
- **Contenido:** el respaldo contiene datos personales, así que se guarda **cifrado** (GPG o disco cifrado) y con acceso restringido.

## 4. Restauración

```bash
# 1. El DBA crea la BD destino VACÍA, propiedad de conciliarh_owner; los roles de la aplicación existen en el clúster
# 2. Storage destino vacío
export PGHOST=... PGDATABASE=conciliarh_restaurada PGUSER=conciliarh_owner CONCILIARH_STORAGE=/opt/conciliarh-restaurada/storage
scripts/restore.sh /respaldos/conciliarh/conciliarh-20261001T023000Z
```

El script:
1. Descifra (si hay `.gpg`) a un temporal privado.
2. **Verifica SHA256SUMS antes de tocar nada.**
3. Se **niega** si la BD o el storage destino no están vacíos: nunca sobrescribe una instalación.
4. Restaura la BD en **una transacción** (todo o nada) y extrae el storage.
5. **Comprueba:**
   - migraciones iguales al manifiesto;
   - **cadena de auditoría íntegra** (`fn_auditoria_verificar`);
   - SHA-256 de **cada original** contra la BD.

   Los registros que el sistema ya marcaba ORIGINAL_AUSENTE en el origen se informan aparte.

**Después:**
1. Configure **las mismas llaves** (§2).
2. Ejecute `php bin/verificar-almacenamiento.php --hash`.
3. Abra `/sistema/salud`.

## 5. Prueba de restauración realizada (FASE 8)

Entorno aislado de desarrollo con datos **ficticios**:

| Paso | Resultado |
|---|---|
| Respaldo de `conciliarh` (desarrollo) | 22 migraciones · 31 archivos importados · 35 archivos en `storage.tar` · 2.2 MB · SHA256SUMS verificado |
| Restauración en la BD nueva `conciliarh_restore_prueba` y un storage vacío aparte | Migraciones 0001–0022 iguales al manifiesto · auditoría íntegra · 27 originales con SHA-256 correcto · 4 ya marcados ORIGINAL_AUSENTE en el origen (cargas de otra sesión de desarrollo sin su archivo), informados aparte |
| La aplicación contra la BD restaurada | `bin/migrate.php status` y `bin/verificar-almacenamiento.php --hash` sin archivos alterados |
| Restaurar encima de una BD con datos | Rechazado (código 3) |
| Respaldo manipulado (un byte en `storage.tar`) | Rechazado antes de tocar nada (código 2) |

La BD de prueba se eliminó al terminar. **Repita esta prueba en preproducción con el servidor real** .

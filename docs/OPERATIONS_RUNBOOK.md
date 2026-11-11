# Runbook de operación

Para quien opera el servidor de ConciliaRH. La instalación está en docs/DEPLOYMENT.md, las tareas de la aplicación (usuarios, roles, configuración) en el panel de administración y la restauración en docs/RESTORE_RUNBOOK.md.

| § | Contenido |
|---|---|
| 1 | Componentes y dónde ver su estado |
| 2 | Comandos de administración (`conciliarh-run`) |
| 3 | Worker |
| 4 | Scheduler y timers |
| 5 | Logs |
| 6 | Modo mantenimiento |
| 7 | Respaldo y restauración |
| 8 | Llaves y rotaciones |
| 9 | Fallas y recuperación |
| 10 | Respuesta a incidentes de seguridad |
| 11 | Tareas periódicas |

---

## 1. Componentes

| Componente | Proceso | Estado |
|---|---|---|
| Web | Apache + PHP-FPM (`conciliarh-web`) | `systemctl status apache2 php8.4-fpm`; `curl -s https://…/api/health` |
| Worker | `conciliarh-worker.service` | `systemctl status conciliarh-worker`; latido en `/sistema/salud` |
| Scheduler | `conciliarh-scheduler.timer` (5 min) | `systemctl list-timers 'conciliarh*'`; «scheduler» en la salud |
| Verificación de originales | `conciliarh-storage-check.timer` (diario) | `systemctl status conciliarh-storage-check` |
| Respaldo | `conciliarh-backup.timer` (diario) | `systemctl status conciliarh-backup`; «respaldo» en la salud |
| PostgreSQL | `postgresql` | `pg_isready`; conexiones por rol en `pg_stat_activity` |

**Vista única:**
- `conciliarh-run postdeploy`: todo lo anterior, desde la consola y de sólo lectura;
- `/sistema/salud`: lo mismo en la web (GOD y ADMIN).

## 2. Comandos de administración

`/usr/local/sbin/conciliarh-run` ejecuta cada comando con el usuario y los archivos de entorno correctos, sin exponer secretos en la línea de comandos. Los que usan las credenciales del **propietario** corren con el usuario `conciliarh-admin`, que ningún servicio comparte. Sólo admite esta lista:

| Comando | Para qué | Escribe |
|---|---|---|
| `conciliarh-run preflight [--json]` | Verificar la instalación (como `conciliarh-web`) | No |
| `conciliarh-run postdeploy [--json]` | Preflight + salud completa | No |
| `conciliarh-run migrate status\|up` | Migraciones (rol propietario) | Sí (`up`) |
| `conciliarh-run create-god` | Alta del único GOD | Sí |
| `conciliarh-run god-recovery status\|unlock\|reactivate\|revoke-sessions\|reset-password\|repair --motivo="…"` | Recuperación de **emergencia** de la cuenta GOD (docs/BREAK_GLASS_RECOVERY.md). Interactiva y auditada | Sí (salvo `status`) |
| `conciliarh-run audit-anchor [--verificar < ancla.json]` | Ancla externa de la auditoría (§11): último hash de la cadena, sin datos personales | No |
| `conciliarh-run maintenance status\|on --motivo="…"\|off` | Modo mantenimiento (§6) | Sí |
| `conciliarh-run scheduler --run` | Ejecutar el scheduler a mano | Sí |
| `conciliarh-run storage-check [--hash] [--cuarentena]` | Verificar originales y huérfanos | Sólo con `--cuarentena` (mueve, nunca borra) |
| `conciliarh-run rotate-account-key …` | Rotación de la llave de cuentas (docs/KEY_ROTATION.md) | Sí |
| `conciliarh-run rotate-account-hmac …` | Rotación HMAC (docs/HMAC_ROTATION.md) | Sí |
| `conciliarh-run rotate-report-key …` | Rotación de la llave de reportes (docs/REPORT_ENCRYPTION.md §6). **Detenga antes** `conciliarh-scheduler.timer`: corre con el usuario del scheduler | Sí |
| `conciliarh-run worker-reap` | Liberar trabajos abandonados por un worker caído | Sí |

## 3. Worker

| Acción | Comando |
|---|---|
| Reiniciar | `systemctl restart conciliarh-worker` (termina el trabajo en curso antes, hasta 120 s) |
| Ver qué hace | `journalctl -u conciliarh-worker -f`; en la web, `/procesos` |
| Trabajo «atascado» | El worker recupera solo los trabajos sin latido (`WORKER_HEARTBEAT_TIMEOUT`); a mano: `conciliarh-run worker-reap` |
| No arranca | `journalctl -u conciliarh-worker -n 50`. «Configuración incompleta…» indica qué variable falta en `worker.env` (nunca muestra valores) |

Un trabajo que falla queda en `ERROR` con su código en `/procesos`. Los reintentos son automáticos hasta `max_intentos` y después se reprocesa desde la pantalla del archivo.

## 4. Scheduler y timers

- **Forzar una ejecución:** `systemctl start conciliarh-scheduler.service`, o `conciliarh-run scheduler --run` para ver la salida.
- **Revisar:**
  - `journalctl -u conciliarh-scheduler --since today`;
  - cada ejecución también queda en la salud, en «scheduler».
- **Dos ejecuciones simultáneas no duplican nada:** un candado deja pasar a un solo scheduler.

## 5. Logs

Ubicaciones y rotación: DEPLOYMENT.md §13. Para encontrar una petición concreta, el usuario ve un «Referencia: abc123…» en el mensaje de error; búsquela así:

```bash
# grep '"request_id":"abc123' /var/lib/conciliarh/logs/app.log
```

Los logs enmascaran cuentas, RFC y CURP y redactan contraseñas y tokens. **No** contienen el contenido de los archivos cargados.

## 6. Modo mantenimiento

Sirve para actualizar, restaurar o rotar llaves sin que nadie escriba mientras tanto.

```bash
# conciliarh-run maintenance on --motivo="Actualización a 0.1.0"
# systemctl stop conciliarh-scheduler.timer conciliarh-worker        # si va a migrar o restaurar
…
# systemctl start conciliarh-worker conciliarh-scheduler.timer
# conciliarh-run maintenance off
```

**Mientras está activo:**
- La web responde **503** «en mantenimiento programado» a todo, salvo `/api/health`, que responde 503 `MANTENIMIENTO` para que el monitoreo lo distinga de una caída.
- El scheduler termina sin hacer nada.
- El worker no toma trabajos nuevos.

**Detalles:**
- El motivo **no** se muestra a los usuarios.
- Un archivo de mantenimiento ilegible o alterado **sigue** significando mantenimiento (falla cerrada).
- `conciliarh-run maintenance status` sale con 3 si está activo.

## 7. Respaldo y restauración

- **Respaldo diario:** con `conciliarh-backup.timer` (DEPLOYMENT.md §15).
- **Respaldo manual** (obligatorio antes de actualizar): `systemctl start conciliarh-backup.service`.
- **Restauración:** docs/RESTORE_RUNBOOK.md.

## 8. Llaves y rotaciones

| Llave | Variable | Rotación | Antes de rotar |
|---|---|---|---|
| Cuentas (AES-256-GCM) | `ACCOUNT_ENC_KEYS` | `conciliarh-run rotate-account-key` | Respaldo; agregar la llave nueva al anillo en `web.env` y `worker.env` |
| HMAC de cuentas | `ACCOUNT_HMAC_KEYS` | `conciliarh-run rotate-account-hmac` | Respaldo; llave nueva en `worker.env` |
| Reportes | `REPORT_ENC_KEYS` | `conciliarh-run rotate-report-key` | Respaldo; llave nueva en `web.env` y `scheduler.env` |
| `APP_KEY` | `APP_KEY` | Cambiarla (en `web.env`, con mantenimiento) invalida los bloqueos de login vigentes y las previsualizaciones de revisión masiva en curso (firmadas con ella); no cifra datos guardados | — |

**Reglas:**
- Las rotaciones **sólo** se hacen por consola, nunca desde la web.
- Son reanudables y se auditan.
- Una llave vieja se retira del anillo **sólo** cuando `--status` ya no muestra datos con ella.
- Cada llave nueva se resguarda en la bóveda **antes** de usarla.

## 9. Fallas y recuperación

Comportamiento probado en la Release Candidate (la suite de pruebas, «Recuperación ante fallas»). En todos los casos la aplicación falla de forma **controlada**: mensajes genéricos al usuario, sin trazas ni rutas, y sin datos a medias.

| Falla | Cómo se ve | Qué hacer |
|---|---|---|
| **PostgreSQL no disponible** | La página de acceso se muestra, pero entrar o cualquier operación responde un error genérico con «Referencia» (sin trazas ni rutas); `/api/health` responde 503; el worker termina y systemd lo reinicia cada 5 s | Restablecer PostgreSQL. La web, el worker y el scheduler se recuperan solos |
| **Worker detenido** | Los archivos quedan «En espera de verificación/análisis»; la salud marca el worker en ERROR | `systemctl start conciliarh-worker`. Los trabajos pendientes se procesan solos; ninguno se pierde |
| **Scheduler detenido** | Los reportes programados no se generan; salud «scheduler» en ADVERTENCIA tras 15 min | `systemctl start conciliarh-scheduler.timer`; `Persistent=true` ejecuta lo atrasado |
| **Almacenamiento no escribible** (disco lleno, permisos) | La carga falla con un mensaje claro y no queda registro sin archivo; la salud marca espacio o almacenamiento | Liberar espacio o corregir permisos (`preparar-sistema.sh` es idempotente); reintentar la carga |
| **Original faltante o alterado** | La verificación diaria falla con `ORIGINAL_AUSENTE` o `SHA256_DISTINTO`; la descarga del original se niega (`INTEGRIDAD_ERROR`, auditado) | **Incidente:** restaurar ese original desde el respaldo, verificando su SHA-256; nunca re-cargar otro archivo en su lugar |
| **Reporte alterado** | La descarga se niega (409), el reporte queda `ALTERADO` y la salud marca ERROR 30 días | Investigar con la auditoría (`reportes.integridad_fallida`); regenerar el reporte desde su programación |
| **Proceso abandonado** (worker muerto a mitad de un trabajo) | El trabajo queda «en ejecución» sin latido | Se libera solo tras `WORKER_HEARTBEAT_TIMEOUT` y se reintenta; a mano: `conciliarh-run worker-reap` |
| **Respaldo corrupto o sustituido** | `restore.sh` lo rechaza **antes** de tocar nada (firma o sumas) | Usar otra copia; si la firma falló, tratarlo como incidente de seguridad |
| **Configuración incompleta tras un cambio** | La web responde 503 «problema de configuración» (el detalle sólo va al log); el worker y el scheduler no arrancan y dicen qué variable falta | Corregir el `.env` del servicio; `conciliarh-run preflight` |

## 10. Respuesta a incidentes de seguridad

1. **Contener:**
   - `conciliarh-run maintenance on`;
   - si hay sospecha de credenciales comprometidas, desactivar las cuentas afectadas desde `/usuarios`, lo que revoca sus sesiones.
2. **Preservar evidencia:**
   - **no** borrar logs, auditoría ni reportes alterados;
   - copiar `/var/lib/conciliarh/logs`, los logs de Apache y `journalctl` a un medio seguro;
   - la auditoría en BD es inalterable y su cadena se verifica en `/auditoria`.
3. **Evaluar:**
   - `security.log`: accesos denegados, bloqueos de login;
   - auditoría filtrada por usuario, por resultado «fallido» y por módulo;
   - `conciliarh-run storage-check --hash`;
   - la salud.
4. **Llaves:**
   - si se expuso un archivo de entorno, **rotar** la llave afectada (§8) y las contraseñas de los roles de BD (`ALTER ROLE … PASSWORD`);
   - una llave de cifrado expuesta compromete los respaldos que la usan: evaluar su resguardo.
5. **Recuperar:** RESTORE_RUNBOOK.md si hay datos alterados.
6. **Documentar** en la bitácora institucional: línea de tiempo, alcance, acciones y lecciones.

## 11. Tareas periódicas

| Frecuencia | Tarea |
|---|---|
| Diaria (automática) | Respaldo, verificación de originales, scheduler cada 5 min, rotación de logs |
| Diaria (persona) | Revisar alertas del monitoreo y `/sistema/salud` |
| Semanal | Revisar `security.log` y la auditoría de accesos sensibles: revelar cuentas, exportaciones sensibles, fusiones |
| Mensual | Verificar la cadena de auditoría en `/auditoria` (GOD); revisar usuarios inactivos y roles el panel de administración; aplicar actualizaciones de seguridad del sistema operativo, PHP y PostgreSQL en una ventana con mantenimiento |
| Trimestral | Prueba de restauración aislada (RESTORE_RUNBOOK.md §4); revisar dependencias (`composer audit`, `pip-audit`) |
| Anual | Revisar la política de llaves y considerar su rotación; revisar la matriz de permisos con el área usuaria |

### Ancla externa de la auditoría

La cadena de hash detecta cualquier cambio en la auditoría, salvo que alguien con control total de la base la reescriba **completa**. El ancla cierra ese hueco: guarda fuera del servidor el hash del último registro.

1. **Cada mes** (y antes de cada actualización):
   ```
   # conciliarh-run audit-anchor > ancla-$(date +%Y%m%d).json
   ```
   El archivo contiene la versión, la fecha, el número de registros y la secuencia y el hash del último. No contiene usuarios, acciones, direcciones ni ningún dato personal. El comando es de sólo lectura y **no envía nada**.
2. Saque el archivo del servidor y séllelo: sello de tiempo, firma, correo a la contraloría o impresión en un acta. Lo que importa es que quede en un lugar que el administrador del servidor no controle.
3. **Para comprobar** un ancla guardada:
   ```
   # conciliarh-run audit-anchor --verificar < ancla-20261101.json
   ```
   `COINCIDE` significa que el registro de esa secuencia conserva su hash **y** que la cadena completa es íntegra. `NO COINCIDE` (código 3) significa que la cadena se alteró después del ancla, o que el ancla es de otra base (por ejemplo, anterior a una restauración): si no hay una restauración que lo explique, trátelo como incidente (§10).

### Recuperación de la cuenta GOD

Sólo en emergencia y con dos personas presentes: `docs/BREAK_GLASS_RECOVERY.md`.

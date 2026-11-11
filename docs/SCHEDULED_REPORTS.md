# Reportes programados (FASE 7)

Los reportes de `docs/REPORTS.md` pueden **programarse**: se generan en segundo plano, se guardan fuera de `public/` y aparecen en la bandeja **Reportes generados**. En esta versión **no hay correo electrónico**.

## 1. Programaciones (`reportes_programados`)

**Campos:**
- nombre;
- tipo: los 12 reportes salvo el comparativo;
- formato: XLSX, CSV o PDF (PDF sólo resumen, pensiones, bancos, grupos e incidencias);
- frecuencia;
- hora, día de la semana o día del mes;
- alcance: la conciliación terminada **más reciente** (lote oficial primero) o un **lote** fijo;
- sensible (RFC/CURP, sólo reportes de personas);
- retención de 30, 60 o 90 días;
- activo o pausado, con motivo;
- dueño (`creado_por`) y versión;
- última y próxima ejecución, último estado y fallos consecutivos.

Una programación nunca se borra: se pausa. Cada creación, edición, pausa y reactivación se audita.

## 2. Frecuencias

| Frecuencia | Cuándo |
|---|---|
| MANUAL | Sólo «Generar ahora» |
| DIARIO | Todos los días a la hora indicada |
| SEMANAL | El día indicado (lunes a domingo) a la hora |
| QUINCENAL | Días 1 y 16, después de cada quincena de pago |
| MENSUAL | El día 1 a 28 indicado |

`ScheduleCalculator` es una función pura en la zona horaria de la aplicación: siempre calcula una hora **posterior a ahora**. Si el scheduler estuvo detenido, no se genera un rezago de ejecuciones perdidas: se genera la vencida y se programa la siguiente.

## 3. Scheduler y trabajos (la misma cola)

`php bin/scheduler.php`:
1. toma un candado consultivo: un solo scheduler a la vez (otro termina con `OTRO_SCHEDULER_EN_CURSO`);
2. marca como ERROR los reportes cuyo trabajo quedó abandonado;
3. encola las programaciones vencidas (`FOR UPDATE SKIP LOCKED`) con `fn_reporte_encolar`;
4. encola la limpieza diaria.

**Idempotencia:** hay índices únicos para una sola generación por programación y hora programada, y una sola en curso por programación. Dos schedulers o dos clics no duplican nada.

**Trabajos:** `GENERAR_REPORTE_PROGRAMADO` y `LIMPIAR_REPORTES_EXPIRADOS` usan la tabla `procesos` existente, con reclamo `FOR UPDATE SKIP LOCKED`, lease con token, backoff y la política de reintentos acotados. Los atiende el ejecutor PHP (`--run`), porque los reportes se construyen con el mismo código PHP de la FASE 6. El procesador en segundo plano (`bin/worker.php`) nunca los reclama: sólo atiende los tipos de su catálogo (`app/Engine/Jobs/JobRegistry.php`).

**Ejecución:**
- `php bin/scheduler.php --run`: encola y ejecuta.
- `--solo-ejecutar`: sólo ejecuta.
- `--max-jobs=N`: límite de trabajos por ejecución.
- En desarrollo: `make scheduler`.

**Rol propio `conciliarh_scheduler`** (`SCHEDULER_DB_USERNAME/PASSWORD`, sólo en el entorno del comando). **No** es el rol del procesador en segundo plano (`conciliarh_worker`), que procesa libros no confiables: el worker no puede escribir reportes ni consultar permisos de usuarios.
- **Lectura:** exactamente lo que la web ya puede leer, que es mínimo privilegio y excluye huellas y cifrado de cuentas, menos usuarios, sesiones, auditoría y el valor cifrado de las cuentas. De los usuarios sólo lee `id`, `username` y `nombre`.
- **Escritura:** sólo las columnas de ejecución de las programaciones, los reportes generados y los trabajos de reportes. Un trigger impide que toque trabajos del worker.
- No ejecuta comandos de shell ni recibe parámetros del usuario.
- **Alta del rol:**
  - desarrollo: `make db-roles` (idempotente; `make migrate` lo ejecuta antes);
  - producción: lo crea el DBA antes de migrar (`CREATE ROLE conciliarh_scheduler LOGIN PASSWORD … NOSUPERUSER NOCREATEDB NOCREATEROLE; GRANT CONNECT …; GRANT USAGE ON SCHEMA public …`).

  La migración 0018 se niega si falta.

## 4. Permisos: los del dueño, evaluados al generar

- El reporte se genera con los permisos **vigentes** del dueño de la programación. Los evalúa la BD al momento (`fn_servicio_usuario`); no se copian al crear la programación.
- Si el dueño ya no tiene `reportes.programar` y `reportes.exportar` (o los sensibles, o `incidencias.ver`), la generación falla con `PERMISO_REVOCADO` y la programación **se pausa**.
- Una programación **sensible** sólo la define (o conserva al editarla) quien puede exportar y ver identificadores. Lo verifica también el trigger de la BD. Su retención es siempre de 30 días.
- **La definición es sólo del dueño.** Un administrador puede pausar o reactivar programaciones ajenas, pero **no** cambiar qué se genera: eso se generaría con la identidad del dueño. Lo impiden PHP y el trigger.
- **Cuotas:**
  - programaciones activas por dueño (20); la BD las hace cumplir al crear y al **reactivar**;
  - generaciones manuales por usuario y hora (10);
  - espacio de reportes disponibles por dueño (500 MB; al excederlo, `CUOTA_ALMACENAMIENTO`).
- **Descarga:** se evalúan los permisos de **quien descarga**. Debe ser el dueño o tener `reportes.administrar_programados`, y además **todos los permisos con los que se construyó el contenido** (`permisos_requeridos`, guardados al generar; p. ej. `incidencias.ver` si el resumen incluye la sección de incidencias, o los sensibles). **Compartir el id de un reporte no concede nada.**

**Por qué los permisos del dueño y no los de una cuenta de servicio:** una cuenta de servicio con todos los permisos permitiría escalamiento indirecto (programar lo que uno no puede ver). La evaluación en el momento de generar evita que un permiso revocado siga produciendo reportes.

| Permiso | GOD | ADMIN | CONSULTA |
|---|---|---|---|
| `reportes.programar` | ✔ | ✔ | — |
| `reportes.administrar_programados` (ver, pausar y descargar las de otros) | ✔ | ✔ | — |

Desde la migración 0026 ya no existe el rol ANALISTA (programaba sólo las suyas). Un rol personalizado con `reportes.programar` y sin `reportes.administrar_programados` sigue viendo únicamente sus programaciones: las ajenas le responden 404.

## 5. Archivos, integridad y retención

- **Ruta:** `storage/reportes/generados/AAAA/MM/<32 hex aleatorio>.<ext>`, fuera de `public/`, permisos 0640.
  - La ruta la genera el sistema y una restricción de la BD valida su patrón.
  - `resolve()` rechaza cualquier otra forma, incluido el recorrido de directorios.
- **Generación:**
  1. se escribe a un temporal;
  2. se mueve (`rename`), se calcula el SHA-256 y sólo entonces la fila pasa a `DISPONIBLE`.

  Si algo falla, se borran el temporal y el final: **nunca queda un archivo corrupto**. El motivo es seguro, sin datos: `ERROR_GENERACION`, `PERMISO_REVOCADO`, `REPORTE_INVALIDO` o `SIN_CONCILIACION`.
- **Reintentos:** según la política de la cola (3 por omisión, con backoff). Los fallos consecutivos (`reportes.errores_para_pausar`, 3) pausan la programación; reactivarla reinicia la cuenta. No hay reintentos infinitos.
- **Cercado:**
  - antes de marcar el reporte `DISPONIBLE`, el ejecutor verifica que el trabajo sigue siendo suyo (token del lease). Si otro consumidor lo tomó, no escribe nada y borra su archivo;
  - los cambios de estado nunca pisan un reporte ya terminado;
  - el reaper del worker sólo recupera los trabajos de reportes por su tiempo máximo, no por latidos (el render no emite latidos intermedios).
- **Descarga** (`POST /reportes/generados/{id}/descargar`, siempre por el controlador):
  - copia el archivo a un temporal y verifica el SHA-256 **de la copia**, así que lo que se entrega es exactamente lo verificado;
  - relee el estado bajo el candado y rechaza los vencidos aunque la limpieza aún no haya pasado;
  - un error de E/S propio (disco lleno) es un error del servidor, no una alteración;
  - si no coincide o falta, responde 409, marca el reporte `ALTERADO`, lo audita (`reportes.integridad_fallida`) y no lo entrega;
  - `no-store`;
  - auditada (`reportes.descargar_generado`, sin contenido).
- **Retención y limpieza** (`LIMPIAR_REPORTES_EXPIRADOS`, como máximo una al día):
  - borra el **archivo** de los reportes con `expira_at` vencido y **conserva la fila** (metadatos, huella, conciliación): estado `EXPIRADO`;
  - no borra un archivo que se está copiando para una descarga (candado compartido);
  - la purga se audita (`reportes.expirar`, sólo conteos).

## 6. Producción (sin desplegar todavía)

Temporizador de systemd cada 5 minutos (o cron `*/5 * * * *`):

```ini
# /etc/systemd/system/conciliarh-scheduler.service
[Service]
Type=oneshot
User=conciliarh
WorkingDirectory=/opt/conciliarh
EnvironmentFile=/etc/conciliarh/scheduler.env   # SCHEDULER_DB_*, DB_HOST/DB_DATABASE, APP_TIMEZONE, STORAGE_PATH; sin llaves ni contraseña del propietario
ExecStart=/usr/bin/php bin/scheduler.php --run --max-jobs=50
# /etc/systemd/system/conciliarh-scheduler.timer
[Timer]
OnCalendar=*:0/5
Persistent=true
[Install]
WantedBy=timers.target
```

Dos ejecuciones superpuestas son seguras (candado e índices únicos). El detalle del empaquetado va en la FASE 10.

## 7. Rutas

`/reportes/programados` (listado; «ver todas» para administradores) · `/reportes/programados/nuevo` · `POST /reportes/programados` · `/reportes/programados/{id}` (editar, con bloqueo optimista por versión) · `POST …/pausar` · `…/reactivar` · `…/generar` · `/reportes/generados` (filtros por tipo, periodo, estado, formato y fechas) · `POST /reportes/generados/{id}/descargar`.

## 8. Pruebas

- `tests/Unit/ScheduleCalculatorTest.php`: diario, semanal, quincenal, mensual, manual y sin rezago.
- `tests/Http/ScheduledReportsHttpTest.php`:
  - permisos, XSS, asignación masiva, IDOR y CSRF;
  - sensibilidad por formulario y en la BD;
  - dos schedulers;
  - generación con el rol de servicio y descarga protegida (dueño, otro usuario, CONSULTA);
  - archivo alterado y huella inmutable;
  - fallo, reintento, permiso revocado con pausa;
  - expiración con archivo en uso;
  - recorrido de rutas.
- `make security-smoke`: el ciclo completo contra el entorno levantado.

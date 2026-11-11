# Salud del sistema (FASE 8)

## 1. Dos niveles

| Ruta | Acceso | Qué dice |
|---|---|---|
| `GET /api/health` | **Pública**, para monitoreo externo | Sólo `{"estado":"ok"}` o 503. Sin versiones, sin detalles |
| `GET /sistema/salud` | `sistema.salud` (sólo GOD desde la migración 0024) | Diagnóstico completo para personas |
| `GET /api/sistema/salud` | `sistema.salud` | Resumen para monitoreo interno autenticado: estado general y el estado de cada verificación, sin versiones ni conteos |
| `POST /sistema/salud/almacenamiento` | `sistema.mantenimiento` (sólo GOD) | Revisión de almacenamiento en **sólo lectura** |

## 2. Verificaciones

Cada una devuelve OK, ADVERTENCIA, ERROR o SIN_DATOS con un texto para personas. El estado general es el peor; SIN_DATOS no lo degrada.

| Verificación | OK | Advertencia o error |
|---|---|---|
| Aplicación | Versión, PHP y todas las extensiones requeridas (`pdo_pgsql`, `sodium`, `openssl`, `mbstring`, `bcmath`, `zip`, `fileinfo`, `intl`, `xmlreader`, `dom`, `iconv`) | ERROR si falta alguna |
| Base de datos | Responde; versión ≥ 14 | ADVERTENCIA si es menor a 14 |
| Migraciones | Todas las del código aplicadas | ERROR con la lista de las pendientes |
| Workers | Al menos un worker con latido reciente (`WORKER_HEARTBEAT_TIMEOUT`) | ERROR si ninguno responde |
| Cola | Sin trabajos atascados ni esperas largas | ADVERTENCIA con esperas de más de 30 min o errores en 24 h. ERROR con trabajos **atascados** (sin latido 10 min o más allá de su `timeout_segundos`) |
| Scheduler | Última ejecución hace menos de 15 min | ADVERTENCIA si es más antigua; ERROR si falló |
| Almacenamiento | Última revisión en menos de 26 h sin hallazgos | ADVERTENCIA con huérfanos o staging vencido; ERROR con originales ausentes o alterados |
| Espacio | Más del 15 % libre en `STORAGE_PATH` | ADVERTENCIA por debajo de 15 %; ERROR por debajo de 5 % |
| Reportes | Sin reportes alterados ni fallidos | ADVERTENCIA con fallidos en 7 días o legados en claro que deben cifrarse; ERROR con alterados |
| Respaldo | Último respaldo en menos de 26 h | ADVERTENCIA si es más antiguo; SIN_DATOS si nunca se registró |

Además, la página muestra:
- los workers (host, versión, último latido);
- los procesos con error en 24 h y los atascados, con enlace a Procesos (donde se cancelan con permiso).

## 3. De dónde salen los datos

`sistema_ejecuciones` (0019) guarda sólo resultados y conteos; se conservan las últimas 500 por tipo.

| Tipo | Lo registra |
|---|---|
| SCHEDULER | `bin/scheduler.php` en cada ciclo, junto con el mantenimiento: solicitudes vencidas y retención de notificaciones |
| ALMACENAMIENTO | `bin/verificar-almacenamiento.php` (CLI/cron) y el botón de la web |
| RESPALDO | `scripts/backup.sh` |

La web sólo puede **leer** `sistema_ejecuciones` y escribir mediante `fn_sistema_ejecucion` (con su actor). No inserta a mano.

## 4. Qué nunca se muestra

- Secretos: `APP_KEY`, contraseñas de BD y las llaves de cuentas, HMAC y reportes.
- Rutas absolutas del servidor.
- Contenido de archivos, datos de personas, parámetros de trabajos y mensajes técnicos de error (sólo el código).

El security-smoke y `Fase8HttpTest` lo comprueban buscando los valores reales de los secretos en la página.

## 5. Mantenimiento seguro

Acción disponible: **revisar almacenamiento**.
- Mismo `StorageAuditor` que la CLI, sin `--cuarentena`: **no mueve ni borra nada**.
- Límite: una cada 5 minutos por usuario (429 si se repite).
- Queda registrada en `sistema_ejecuciones` y auditada (`sistema.revisar_almacenamiento`).

Los procesos abandonados:
- se **detectan** y muestran aquí;
- la recuperación automática la hace el reaper del worker;
- la cancelación manual se hace en `/procesos/{id}` (`procesos.cancelar`) y sólo aplica a procesos **PENDIENTES** (en BD sólo PENDIENTE→CANCELADO; uno en ejecución termina o lo recupera el reaper).

No hay ejecución de tareas arbitrarias ni comandos del sistema desde la web.

## 6. Pruebas

- **`tests/Http/Fase8HttpTest.php`** (`testSystemHealthIsAuthorizedAndRevealsNoSecrets`):
  - `/api/health` mínima;
  - 403 para CONSULTA y ADMIN (pantalla, API y mantenimiento);
  - GOD ejecuta la revisión (CSRF, registro, 429 al repetir);
  - la web no inserta ejecuciones.
- **`make security-smoke`**, sección «FASE 8: salud».

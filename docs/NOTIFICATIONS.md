# Notificaciones internas (FASE 8)

Avisos personales dentro de ConciliaRH. **No hay correo** en esta versión.

## 1. Qué son y qué no son

- Son una **bandeja personal**: cada usuario ve sólo las suyas, puede marcarlas como leídas y borrarlas.
- **No son auditoría.** La auditoría (`auditoria`, con cadena de hashes) no cambia cuando se lee o se borra una notificación.
- Se purgan por retención:
  - las leídas, después de `notificaciones.retencion_dias` (90 por omisión);
  - las no leídas, después del doble.

  Lo hace el scheduler en cada ciclo (`fn_mantenimiento_notificaciones`).

## 2. Cuándo se crean

Las crea **la base de datos** (disparadores y `fn_notificar*`, `SECURITY DEFINER`). Funcionan igual si el cambio lo hace la web, el procesador en segundo plano o el scheduler.

| Tipo | Cuándo | Para quién | Prioridad |
|---|---|---|---|
| `CONCILIACION_TERMINADA` | La ejecución termina (completada, con incidencias o en revisión manual) | Quien la solicitó | NORMAL / ALTA |
| `CONCILIACION_ERROR` | La ejecución falla | Quien la solicitó | ALTA |
| `REPORTE_LISTO` | Un reporte programado queda DISPONIBLE | El dueño de la programación | NORMAL |
| `REPORTE_FALLIDO` | Un reporte programado queda en ERROR | El dueño | ALTA |
| `IMPORTACION_REVISION` | Un archivo queda en REVISION_MANUAL | Quien lo cargó y quienes pueden elegir hoja/perfil | ALTA |
| `ASOCIACIONES_PENDIENTES` | Una normalización deja registros por decidir | Quienes pueden resolver asociaciones | NORMAL |
| `FUSION_PENDIENTE_APROBACION` | Se solicita una fusión con conflictos graves | Quienes tienen `empleados.fusionar_conflictos_aprobar` (ADMIN y GOD), excepto el solicitante | ALTA |
| `FUSION_DECIDIDA` | La solicitud se aprueba, rechaza, cancela o expira | El solicitante | NORMAL / ALTA |
| `SISTEMA` | Avisos del sistema (y datos de demostración) | Según el caso | — |

**Deduplicación.** Cada aviso lleva una clave (`conciliacion:ID`, `normalizacion:ID`…). Si el mismo hecho vuelve a ocurrir, se **reactiva** (no leída y fecha nueva) en lugar de duplicarse.

## 3. Seguridad

- **Sin datos personales.** Títulos y mensajes se arman en el servidor sólo con:
  - números de lote, archivo, ejecución o solicitud;
  - estados;
  - la descripción del periodo;
  - el nombre de la programación que el propio usuario escribió.

  Nunca llevan nombres de personas, RFC, CURP, CLABE, cuentas ni contraseñas. El security-smoke lo verifica.
- **Aislamiento (IDOR).** Row-level security: `usuario_id = fn_actor_actual()` para `conciliarh_web`. Aunque una consulta olvidara filtrar, la BD sólo devuelve y modifica las notificaciones del actor de la sesión. Un id ajeno responde 404.
- **Permisos de la web:** sólo `SELECT`, `DELETE` y `UPDATE (leida_at)`. No puede crear notificaciones ni cambiar el destinatario, el tipo o el texto.
- **Enlaces.** Sólo rutas internas: la BD rechaza `http:`, `javascript:` y `//dominio`. Al abrir una notificación (POST + CSRF) se marca leída y se redirige a esa ruta interna.
- **XSS.** Todo se escapa al mostrarse. La API devuelve JSON.
- Todas las acciones son POST con CSRF.

## 4. Interfaz

| Ruta | Qué hace |
|---|---|
| `GET /notificaciones` | Bandeja con filtros (sin leer / leídas, tipo) y paginación |
| `POST /notificaciones/{id}/abrir` | Marca leída y lleva al destino |
| `POST /notificaciones/{id}/eliminar` | Elimina una |
| `POST /notificaciones/leer-todas` | Marca todas como leídas |
| `POST /notificaciones/eliminar-leidas` | Elimina las leídas |
| `GET /api/notificaciones/resumen` | `{no_leidas, ultimas[]}` para la campana del encabezado |

**Sondeo.** La campana consulta el resumen cada **60 segundos**, sólo con la pestaña visible (`document.visibilityState`), y al volver a ella. No se usan WebSockets: el volumen no lo justifica.

## 5. Pruebas

- **`tests/Http/Fase8HttpTest.php`:**
  - sólo las propias;
  - IDOR al abrir y borrar;
  - RLS con SQL directo;
  - la web no crea ni reasigna;
  - CSRF;
  - URL externa rechazada;
  - deduplicación;
  - XSS;
  - SQLi en filtros.
- **`testNotificationTriggersCarryNoPersonalData`:** el disparador de importación no lleva PII.
- **`MergeHttpTest`:** notificación al aprobador y al solicitante.
- **`make security-smoke`**, sección «FASE 8: notificaciones».

# Doble aprobación de fusiones con conflictos graves (FASE 8)

Complementa `docs/IDENTITY_MERGE.md`. Una fusión que **acepta conflictos graves** ya no la decide una sola persona: aplica el principio de **cuatro ojos**.

## 1. Qué cambia

| Fusión | Antes (FASE 7) | Ahora (FASE 8) |
|---|---|---|
| Sin conflictos graves (evidencia ALTA o MEDIA) | Una persona con `empleados.fusionar` | **Igual** (riesgo bajo) |
| Con conflictos graves (RFC, CURP o ISSEMYM válidos distintos; nómina en la misma carga; identificador editado a mano) | GOD aceptaba los conflictos y fusionaba | **A solicita, B distinto aprueba.** Nadie puede hacerlo solo, tampoco GOD |

La regla la hace cumplir **la base de datos**, no sólo PHP:
- `fn_fusionar_empleados` rechaza con `APROBACION_REQUERIDA` cualquier fusión con conflictos graves;
- la única vía es `fn_solicitar_fusion_critica` → `fn_aprobar_solicitud_fusion`.

## 2. Estados

```
(previsualización) ──solicitar──▶ PENDIENTE_APROBACION ──aprobar (otra persona)──▶ EJECUTADA
                                        │ ├──rechazar (aprobador)──▶ RECHAZADA
                                        │ ├──cancelar (solicitante)─▶ CANCELADA
                                        │ └──vence (24–72 h)────────▶ EXPIRADA
                                        └──el análisis cambió al aprobar──▶ CANCELADA (se debe solicitar de nuevo)
```

- **«Borrador»** es la previsualización con su huella, que no se guarda.
- **«Aprobada»** y **«ejecutada»** ocurren en la **misma transacción**: no existe una solicitud aprobada sin aplicar que alguien pudiera ejecutar después con datos distintos.
- Una solicitud sólo se decide **una vez** (trigger) y nunca se borra.

## 3. Quién puede qué

| Permiso | Para | Por omisión |
|---|---|---|
| `empleados.fusionar` | Fusionar sin conflictos graves | GOD, ADMIN |
| `empleados.fusionar_conflictos` | **Solicitar** una fusión con conflictos graves (y cancelar la propia) | GOD, ADMIN |
| `empleados.fusionar_conflictos_aprobar` *(sensible)* | **Aprobar o rechazar** la solicitud de OTRA persona | GOD, ADMIN |

CONSULTA no solicita, no aprueba ni rechaza (403).

**Desde la migración 0026** sólo existen los roles GOD, ADMIN y CONSULTA. El rol `APROBADOR` (antes, la segunda firma) ya no existe: ADMIN recibió sus permisos y quien tenía APROBADOR pasó a ADMIN. La segunda firma la da **otra persona** con el permiso de aprobación: otro ADMIN o GOD.

Flujos típicos:
- el ADMIN A solicita y el ADMIN B aprueba;
- un ADMIN solicita y GOD aprueba, o GOD solicita y un ADMIN aprueba.

**Nadie aprueba lo que solicitó:**
- `CHECK (decidido_por <> solicitado_por)`;
- verificación en `fn_aprobar_solicitud_fusion` (`CUATRO_OJOS`);
- `fn_decidir_solicitud_fusion` tampoco deja que el solicitante rechace su propia solicitud, y sólo él la cancela;
- en la pantalla, `MergeService` sólo ofrece «Aprobar» si la solicitud está pendiente, no es propia y la persona tiene el permiso;
- además, un ADMIN no administra usuarios ni roles: no puede darse roles a sí mismo ni a otros (sólo GOD, también en la BD).

## 4. Al aprobar

`fn_aprobar_solicitud_fusion`:
1. Toma el candado global y la fila de la solicitud.
2. Exige que sea de otra persona, esté vigente y que el solicitante siga activo.
3. **Recalcula el análisis.** Si la huella cambió, aparece un bloqueo, deja de ser fusionable o surgen conflictos graves que el solicitante no aceptó, la solicitud queda **CANCELADA** y no se aplica nada.
4. Aplica la fusión (`fn_fusion_aplicar`, la misma rutina interna que la fusión directa).
   - `fusiones_empleados.usuario_id` = el **aprobador** (quien ejecuta; la BD liga la autoría a la sesión);
   - `solicitado_por` = el solicitante;
   - `solicitud_id` = la solicitud.
5. Notifica al solicitante (sin datos personales).

## 5. Expiración

`fusiones.solicitud_expira_horas`: **48 h** por omisión, acotada a 24–72.
- 24 h obligarían a aprobar en el mismo día hábil.
- Más de 72 h dejaría decisiones de identidad «colgadas» mientras llegan nuevas cargas, lo que las haría cancelarse de todos modos por cambio de análisis.

Una solicitud vencida **nunca** se aplica. Se marca EXPIRADA:
- al intentar aprobarla;
- al solicitar otra;
- al listar;
- en cada ciclo del scheduler (`fn_solicitudes_fusion_expirar`).

## 6. Auditoría

| Acción | Datos |
|---|---|
| `empleados.fusion_solicitar` | Origen, destino, motivo, conflictos graves aceptados, vencimiento y huella |
| `empleados.fusion_aprobar` | Solicitante, aprobador, motivo de la aprobación y fusión resultante |
| `empleados.fusionar` | El registro completo de la fusión (evidencias, conflictos, comparación, conteos), ahora con `solicitud_id`, `solicitado_por` y `aprobado_por` |
| `empleados.fusion_rechazar`, `empleados.fusion_cancelar` | Motivo |
| `empleados.fusion_expirada`, `empleados.fusion_cancelada` | Cuando la aprobación encuentra la solicitud vencida o con el análisis cambiado |

## 7. Límites

Aprobar y rechazar: **10 decisiones por minuto** por usuario (`Throttle`, contado sobre la auditoría).

## 8. Rutas

| Método | Ruta | Permiso |
|---|---|---|
| POST | `/empleados/fusion` (con conflictos graves crea la solicitud) | `empleados.fusionar` + `empleados.fusionar_conflictos` |
| GET | `/empleados/solicitudes-fusion` | `empleados.duplicados` |
| GET | `/empleados/solicitudes-fusion/{id}` | `empleados.duplicados` |
| POST | `/empleados/solicitudes-fusion/{id}/aprobar` | `empleados.fusionar_conflictos_aprobar` |
| POST | `/empleados/solicitudes-fusion/{id}/rechazar` | `empleados.fusionar_conflictos_aprobar` |
| POST | `/empleados/solicitudes-fusion/{id}/cancelar` | `empleados.fusionar_conflictos` (sólo la propia) |

## 9. Pruebas

- **`MergeHttpTest`:**
  - solicitud con asignación masiva ignorada;
  - el ADMIN que solicita no aprueba ni rechaza su propia solicitud (tampoco GOD);
  - CONSULTA recibe 403 al aprobar, rechazar o separar;
  - CSRF;
  - aprobación por un segundo ADMIN;
  - aprobar dos veces;
  - cancelación sólo por el solicitante;
  - una solicitud pendiente por pareja;
  - rechazo con notificación;
  - cuatro ojos y actor no suplantable directamente en la BD;
  - expiración, también en el barrido del scheduler.
- **`DatabaseSecurityTest` y `TresRolesMigrationTest`:** sólo existen GOD, ADMIN y CONSULTA; ADMIN tiene el permiso de aprobación; la migración 0026 pasa a ADMIN a quien tenía ANALISTA o APROBADOR.
- **Procesador (`tests/Engine/Phase7Test.php`):** las fusiones graves pasan por solicitud y aprobación; el nombre solo nunca fusiona.
- **`make security-smoke`**, sección «FASE 8: doble aprobación».

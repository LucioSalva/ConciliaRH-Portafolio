# Beneficiarios de pensión y revisión masiva (FASE 6)

## 1. Trabajador ≠ beneficiario

En los archivos de pensión alimenticia aparecen dos personas:

| Persona | Cómo aparece |
|---|---|
| **Trabajador obligado** | En `empleados`. Santander y BBVA traen su número; la interbancaria no lo trae. |
| **Beneficiario** | Recibe el pago. Sólo traen su nombre y su cuenta o CLABE. |

- Un trabajador puede tener uno, varios o beneficiarios históricos.
- Un beneficiario aparece en varios periodos.
- El sistema **no registra ni infiere parentesco, RFC ni CURP**, porque los archivos no los contienen: la interfaz sólo dice «BENEFICIARIO».

## 2. Modelo (migración 0015)

| Entidad | Contenido |
|---|---|
| `beneficiarios_pension` | Identidad del beneficiario: `nombre_original` (primera aparición), `nombre_normalizado`, identificador interno (`BEN-000123` = id), estado, **origen**, `confirmado_por/_at` (manual), observaciones, primer y último periodo. La identidad es inmutable; sin DELETE. |
| `beneficiario_trabajadores` | **Relación** beneficiario ↔ trabajador con origen, **evidencia** (registro, archivo, lote, periodo, fila, banco), **vigencia** (`vigente_desde/hasta_periodo`), autor y motivo. Sólo se **desactiva** como decisión correctiva, nunca se borra ni se reactiva. Una sola relación ACTIVA por pareja. |
| Cuentas del beneficiario | Se reutiliza `cuentas_bancarias` con uso `PENSION_BENEFICIARIO` y la nueva columna `beneficiario_id`: AES-256-GCM + HMAC, últimos 4, historial (una cuenta anterior queda INACTIVA y **no se sobrescribe**; un beneficiario puede tener varias cuentas), revelación con permiso y auditada, y la misma rotación de llave. La cuenta, una vez ligada a un beneficiario, no cambia de beneficiario. |
| `registros_tesoreria.beneficiario_id` | A qué beneficiario va cada pago de pensión. |

**Por qué no hay una tabla `beneficiario_cuentas` aparte:** duplicaría el cifrado, el HMAC, la unicidad por CLABE, el historial y la rotación que `cuentas_bancarias` ya resuelve y prueba. Ligarla por `beneficiario_id` da el mismo modelo con menos superficie de riesgo.

## 3. Cómo se forma la identidad (nunca sólo por nombre)

| Evidencia | Resultado |
|---|---|
| El archivo trae el **número del trabajador** (Santander/BBVA) y la asociación es por identificador fuerte | El trabajador se asocia automáticamente. El beneficiario se identifica por su **cuenta** ya ligada o, si no la hay, por el **mismo trabajador + mismo nombre exacto** entre sus beneficiarios. Si no existe, se **crea** con origen `EVIDENCIA_FUERTE` y relación `NUMERO_EMPLEADO_ARCHIVO`. |
| **CLABE confirmada** (cuenta `PENSION_BENEFICIARIO` activa ligada a un beneficiario) | El trabajador se asocia automáticamente (`CUENTA_CONFIRMADA`) y queda ligado el beneficiario de esa cuenta. |
| **Confirmación manual autorizada** (`beneficiarios.confirmar` + `cuentas.confirmar`) | `fn_confirmar_beneficiario` crea o reutiliza el beneficiario, la relación `CONFIRMACION_MANUAL` (autor, fecha, motivo, archivo, lote, evidencia) y **aprende la CLABE**. Los periodos posteriores la reutilizan automáticamente. |
| **Sólo nombre** (+ CLABE + importe, primera vez) | **Nunca** asociación definitiva ni beneficiario nuevo. Como mucho una **SUGERENCIA** (ver §4). |

## 4. Pensiones sin trabajador (interbancarias)

El worker propone candidatos, **nunca asocia**, con dos evidencias explicables:

1. **Beneficiario conocido por nombre:** el nombre coincide exactamente con **un solo** beneficiario conocido que tiene trabajador vigente.
2. **Importe único:** el importe es exactamente el **faltante de pensión** de **un solo** trabajador del lote (retención RRHH menos lo ya dispersado e identificado) y ningún otro pago pendiente del lote tiene ese importe.

La confianza se muestra siempre:

| Evidencia | Estado | Confianza |
|---|---|---|
| Ambas coinciden | SUGERENCIA | MEDIA |
| Sólo el beneficiario conocido (sólo nombre) | SUGERENCIA | BAJA |
| Sólo el importe | SUGERENCIA | BAJA |
| Varios trabajadores posibles | AMBIGUO | — |
| Sin evidencia | NO_IDENTIFICADO | — |

- Los candidatos rechazados no se vuelven a proponer.
- Una decisión manual «no identificado» impide el respaldo por nombre.
- **Faltante, no retención total.** El faltante excluye las vistas derivadas y cuenta una sola vez a la persona que aparece en varios tabulados del lote. La validación real demostró que sumar la vista derivada duplicaba la retención y fabricaba faltantes falsos.
- La unicidad del importe se mide contra **todos** los pagos de pensión pendientes del lote.
- Con estas reglas, las sugerencias por importe coinciden con los casos que el motor de conciliación marca como PENDIENTE_IDENTIDAD.

## 4b. Historia entre periodos (FASE 7)

Además del beneficiario conocido y del faltante único, el worker consulta la **misma CLABE** (por su huella) en pagos de pensión de **otros periodos**, siempre con importación vigente y lote no anulado:

| Señal | Qué significa | Efecto |
|---|---|---|
| `CLABE_ASOCIADA_OTRO_PERIODO` | En otro periodo esa CLABE se asoció (manual o automáticamente) a **un** trabajador, pero no se confirmó como cuenta | Evidencia secundaria |
| `MISMO_NOMBRE_CON_ESA_CLABE` | En otro periodo esa CLABE llegó con el mismo nombre de beneficiario | Consistencia del beneficiario; **no** identifica al trabajador |
| `IMPORTE_RECURRENTE_MISMA_CLABE` | En otro periodo la misma CLABE, con el **mismo importe**, sugería por faltante único al **mismo** trabajador que ahora | Importe recurrente + monto retenido compatible |

**Categorías:**

| Categoría | Cuándo | Resultado |
|---|---|---|
| FUERTE | CLABE confirmada (cuenta de beneficiario activa, relación activa) o número del trabajador en el archivo | Asociación automática (ya existía) |
| MEDIA | CLABE asociada en otro periodo **y** el mismo nombre (`CLABE_Y_NOMBRE_HISTORICOS`) · importe recurrente en la misma CLABE con el mismo trabajador · beneficiario conocido **y** faltante único que coinciden | SUGERENCIA, entra en revisión masiva |
| BAJA | Una sola señal: sólo el nombre, sólo el importe o sólo la CLABE asociada en otro periodo | SUGERENCIA, sólo individual |
| SIN_EVIDENCIA | Ninguna señal con trabajador; p. ej. **nombre + importe** sin trabajador conocido | NO_IDENTIFICADO |

**Reglas:**
- Señales que apuntan a trabajadores **distintos** ⇒ AMBIGUO (`SENALES_CONTRADICTORIAS`); nunca se elige por prioridad.
- **El importe por sí mismo nunca es identidad**: $2,000.00 puede corresponder a muchas personas.
- Ninguna señal nueva asocia: la asociación definitiva exige evidencia fuerte o una persona.
- Una CLABE confirmada manualmente en un periodo **sí** identifica en los siguientes (FUERTE, desde la FASE 6).
- La evidencia de cada sugerencia guarda `categoria`, `senales` y `detalle_senales`.
- Se compara sobre la identidad **canónica** (fusiones).

**Principio:** las señales sólo sugieren; ninguna pensión se confirma automáticamente.

## 5. Revisión masiva (`/revision-masiva`)

**Bandeja.** Filtros por tipo (nómina/pensión), estado, banco, grupo (del candidato), periodo, lote, importe, nombre y confianza. Se puede ordenar por importe, nombre, «misma CLABE» o periodo. Cada fila muestra:
- la propuesta y la **evidencia**;
- la confianza;
- la cuenta enmascarada, si la CLABE es nueva o ya está registrada;
- el tamaño de su **grupo de misma CLABE**, mediante una clave opaca de la vista `v_revision_grupos_cuenta` que no expone el HMAC. Con ella se puede revisar en conjunto todas las transferencias de una CLABE.

**Acciones:**
- confirmar las propuestas, opcionalmente confirmando la CLABE (nómina) o registrando al beneficiario (pensión);
- marcar como no identificado;
- ignorar la sugerencia (rechazar al candidato).

**Seguridad de la acción masiva:**
1. **Nunca a ciegas.**
   - Sólo se confirma una SUGERENCIA con **un** candidato y confianza **MEDIA o mayor**. Una señal débil (BAJA: sólo el importe o sólo el nombre) se decide uno por uno, porque confirmarla aprendería una CLABE.
   - CONFLICTO y AMBIGUO **nunca** entran en ninguna acción masiva, ni siquiera en «no identificado».
   - Si la misma CLABE tiene, en **cualquier** registro pendiente (aunque no esté seleccionado ni en la página), otro candidato, un conflicto o una ambigüedad, sus registros se excluyen.
2. **Previsualización obligatoria:** cantidad de registros, **monto total**, empleados afectados, beneficiarios (y cuántos nuevos), **cuentas que se crearán**, incidencias que se resolverán, advertencias y los registros excluidos con su motivo.
3. **Doble confirmación:** la página de previsualización más un modal accesible («Confirmará N asociaciones por un total de $X…»), con motivo obligatorio y casilla de revisión. No se usa `window.confirm()`.
4. **No se confía en el cliente:**
   - La previsualización firma con HMAC (`APP_KEY`) la selección exacta: `id:versión:candidato`, la acción, las opciones, el usuario y la expiración (15 min).
   - La ejecución verifica la firma y vuelve a **bloquear y validar** cada registro (`FOR UPDATE`): versión, candidato, importación vigente, periodo abierto y permisos.
   - Los campos extra (actor, estado, importe, empleado) se ignoran; el actor lo fija la BD (§2e de SECURITY).
5. **Concurrencia:** todo o nada. Si otra persona o el motor modificó un registro después de la previsualización, **nada** se aplica y se informa `REGISTRO_MODIFICADO`.
6. **Trazabilidad:**
   - `acciones_masivas` guarda acción, usuario, motivo, conteos, monto y huella de la selección.
   - `acciones_masivas_registros` guarda **cada registro**: versión, estado antes y después, empleado, beneficiario, cuenta creada e importe.
   - `asociaciones_historial.accion_masiva_id` liga cada decisión.
   - Nunca se guarda sólo «se confirmaron N».
7. **Sin «deshacer»:** una decisión equivocada se corrige con **otra** decisión (reasociar, desactivar la relación), y el historial conserva ambas.
   - Desactivar una relación beneficiario–trabajador también desactiva las cuentas de ese beneficiario con ese trabajador, para que la CLABE no vuelva a asociar automáticamente, y cierra la vigencia.
   - La cuenta sólo identifica al beneficiario si la relación sigue ACTIVA.
8. **Orden de candados:** la ejecución toma primero el candado consultivo global (el mismo que usan el worker y las funciones de cuentas) y después las filas, así que no hay interbloqueo con la normalización.
9. **Procedencia:** una acción masiva sólo puede citarse (en el historial o en `fn_confirmar_beneficiario`) por su propio usuario y en su misma transacción.

## 6. Módulo Beneficiarios (`/beneficiarios`)

- **Listado:** nombre, trabajadores relacionados, banco, últimos 4, estado, origen y última aparición. Se busca por nombre o por número del trabajador.
- **Detalle**, con pestañas:
  - Resumen, con observaciones editables por `beneficiarios.editar` y auditadas;
  - Trabajadores: relaciones con origen, confianza (FUERTE o MANUAL), vigencia, evidencia y desactivación correctiva;
  - Cuentas: enmascaradas, con «Revelar» mediante `cuentas.ver_sensible` (POST, auditado, `no-store`, verificación de que la cuenta pertenece al beneficiario);
  - Pensiones;
  - Historial, con enlace a las acciones masivas;
  - Auditoría.

## 7. Permisos

Desde la migración 0026 sólo existen GOD, ADMIN y CONSULTA (ADMIN conserva todo lo que hacía el antiguo ANALISTA).

| Permiso | GOD/ADMIN | CONSULTA |
|---|---|---|
| `beneficiarios.ver` | ✔ | ✔ (cuentas enmascaradas) |
| `beneficiarios.confirmar` | ✔ | — |
| `beneficiarios.editar` | ✔ | — |
| `asociaciones.masivo` (exige además `asociaciones.resolver`) | ✔ | — |

## 8. Impacto en la conciliación

- Una confirmación **no modifica** una conciliación histórica.
- El tablero de la conciliación vigente muestra «**Existen cambios de identidad posteriores a esta conciliación**» con una vista previa técnica: asociaciones nuevas, cuentas confirmadas, relaciones de beneficiario, importaciones nuevas e incidencias potencialmente afectadas.
- El botón «Reconciliar» crea una nueva ejecución. El comparativo v1 vs v2 explica cada cambio a partir de las fuentes y del historial, por ejemplo: «Se confirmó una transferencia previamente pendiente de identidad… (asociación manual de X, acción masiva #N)».

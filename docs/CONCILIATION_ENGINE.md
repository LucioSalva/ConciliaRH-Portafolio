# Motor de conciliación financiera (FASE 5)

> Versión del motor: `Engine::VERSION = 2` (`app/Engine/Conciliation/Engine.php`). Los cambios de la versión 2 (población declarada del archivo y pensiones cotejadas por total de población) están en el §6b.
> El cálculo vive **sólo** en el motor del procesador en segundo plano (`app/Engine/Conciliation/Engine.php`, puro, sin BD). El trabajo `CONCILIAR_LOTE`
> (`app/Engine/Jobs/ConciliateLotJob.php`) carga y persiste. La web orquesta y muestra, y nunca recalcula dinero.
> Hasta la versión 0.1.0-rc.2 este motor estaba escrito en Python; la versión PHP produjo resultados idénticos en la regresión con datos reales.

## 1. Principios

1. **Por lote.** Cada ejecución concilia **un** `lote_importacion` de **un** periodo. Nunca se mezclan lotes.
   - El lote no necesita ser oficial: la ejecución guarda `lote_oficial` como fotografía (se muestra OFICIAL / NO OFICIAL).
   - Sólo el tablero institucional prioriza el lote oficial del periodo más reciente (`ConciliationService::dashboardConciliation`).
2. **Completitud antes que exactitud.** Primero se decide qué archivos cuentan y por qué (`conciliacion_archivos.rol`); luego se comparan importes.
3. **Partida por partida.** Un total global que cuadra **no** sustituye al detalle.
   - Ejemplo: A cobra +100 y B cobra −100. El total cuadra, pero se generan **dos** incidencias.
4. **Sin cifras de ajuste.**
   - Lo no identificado se reporta aparte, como IMPORTE_PENDIENTE_IDENTIDAD; nunca se asigna a una persona.
   - La palabra «fraude» no se usa: el motor describe hechos.
5. **Explicabilidad.** Cada resultado guarda:
   - esperado, pagado, diferencia y regla;
   - las **fuentes exactas** (archivo, hoja, fila y asociación vigente);
   - la versión del motor y la huella de entradas.
6. **Decimal siempre.**
   - Los importes son `NUMERIC` en PostgreSQL y cadenas decimales exactas con `bcmath` en el motor (`app/Engine/Support/Dec.php`); nunca `float`.
   - La tolerancia se lee de `configuracion.conciliacion.tolerancia_monetaria` (por omisión 0.01) y queda fija en cada ejecución.

## 2. Ciclo de vida de una ejecución

1. **Solicitud.** `fn_solicitar_conciliacion(lote, usuario, motivo)` es SECURITY DEFINER.
   - Toma un candado del lote.
   - Asigna el número de ejecución `max(numero)+1` (v1, v2…).
   - Congela la tolerancia vigente y la marca de lote oficial.
   - Es la única vía de alta; `solicitado_por` lo verifica el trigger de actor contra la sesión.
2. **Encolado.** La misma función encola `CONCILIAR_LOTE {conciliacion_id}` en la **misma transacción**, así que nunca queda una ejecución sin trabajo. Para una conexión web verifica además:
   - que el actor sea el de la sesión;
   - el permiso `conciliaciones.ejecutar`, o `conciliaciones.reprocesar` con motivo si ya hubo ejecuciones;
   - que no haya archivos en proceso;
   - que no exista una conciliación CERRADA del lote (hay que invalidarla antes).
3. **Ejecución.** El trabajo:
   - bloquea la fila (`FOR UPDATE`);
   - si el lote tiene archivos en proceso o una normalización pendiente, **reintenta** (`LOTE_EN_PROCESO`) en lugar de concluir con datos incompletos;
   - lee todo en una transacción `REPEATABLE READ`, calcula, persiste con `INSERT` por lotes (`app/Engine/Support/BatchInsert.php`) y cierra en la **misma** transacción.
4. **Estados.**

   | Estado | Significado |
   |---|---|
   | PENDIENTE | Ejecución solicitada y encolada. |
   | EJECUTANDO | Sólo el motor la fija. |
   | COMPLETADA | Todos los controles cuadran. |
   | COMPLETADA_CON_INCIDENCIAS | Hay alguna incidencia de severidad mayor que INFO. |
   | REVISION_MANUAL | Hay alguna incidencia CRITICA. |
   | ERROR | Error del motor; lo fija el trabajo en ERROR/CANCELADO mediante trigger. |
   | CERRADA | Cerrada por un rol autorizado. |
   | INVALIDADA | Invalidada; la ejecución se conserva en el historial. |

5. **Inmutabilidad.**
   - Los resultados se insertan **sólo** mientras la ejecución está EJECUTANDO (`fn_conciliacion_resultado_escribible`).
   - Una ejecución terminada no cambia totales, resumen, huella ni versión.
   - Nada se borra (`fn_solo_insercion`).
   - Reprocesar crea una ejecución nueva y el historial nunca se reescribe.
6. **Cierre e invalidación.**
   - Cerrar (`conciliaciones.cerrar`) congela las incidencias. Sólo se cierra la ejecución **más reciente** vigente del lote.
   - La autoría de cierre e invalidación se fija una vez, con la hora de la BD, y no se borra ni se reescribe (se conserva al invalidar una cerrada).
   - Se puede cerrar con incidencias abiertas o justificadas; su conteo queda en la auditoría del cierre.
   - Invalidar (`conciliaciones.invalidar`) conserva todo. Invalidar una conciliación **cerrada** es exclusivo de GOD, y la BD también lo verifica.

### Reproducibilidad (opción C elegida)

| Opción | Descripción | Decisión |
|---|---|---|
| A | Guardar sólo los totales. | No es explicable. |
| B | Copiar íntegros los registros de entrada. | Duplica PII y volumen. |
| **C** | Resultados por persona + **fuentes** (id de registro, archivo, hoja, fila, importe original y aplicado, estado de asociación **vigente al ejecutar**) + **huella SHA-256** de las entradas canónicas + versión del motor. | **Elegida.** |

- Con la opción C, una ejecución antigua se explica aunque después cambien las asociaciones.
- La huella cubre, en forma canónica y con orden estable:
  - archivos y sus roles de entrada;
  - registros RRHH, con conceptos de pensión;
  - registros de Tesorería, con asociación y candidatos;
  - pólizas GLOBAL y de egreso;
  - empleados usados;
  - relaciones manuales póliza ↔ dispersión;
  - tolerancia y versión.
- Dos ejecuciones con la misma huella y la misma versión producen resultados idénticos. La segunda se marca `igual_a_id`, lo que da la idempotencia.

## 3. Selección de archivos (roles)

| Rol | Regla |
|---|---|
| `FUENTE_RRHH` | Tabulado RRHH no derivado; por grupo, el de **población mayor** (superconjunto). |
| `EXCLUIDO_DERIVADO` | Vista filtrada u oculta del tabulado (`VISTA_DERIVADA` del análisis). |
| `EXCLUIDO_SUBCONJUNTO` | Tabulado cuyo conjunto de personas está contenido en la fuente del grupo. Evita el doble conteo. |
| `FUENTE_RRHH_AMBIGUA` (incidencia CRITICA) | Dos tabulados del mismo grupo que se traslapan parcialmente. |
| `TESORERIA_NOMINA` / `TESORERIA_PENSION` | Dispersiones incluidas. |
| `EXCLUIDO_DUPLICADO` | Duplicado binario o de contenido **del mismo lote** cuyo original sí participa: el dinero no se duplica. Si el original se anuló, se usa el duplicado (`DUPLICADO_SIN_ORIGINAL_VIGENTE`). |
| `EXCLUIDO_VERSION_AMBIGUA` | «Posible versión» de otro archivo del lote que también es fuente candidata (mismo tipo y grupo, utilizable, no derivado); se genera CRITICA. Un tabulado completo que es «versión» de su propia vista filtrada **no** es ambiguo. |
| `EXCLUIDO_ANULADO` / `EXCLUIDO_NO_NORMALIZADO` | Archivo anulado, o sin importación vigente o en un estado distinto de ANALIZADO* (incidencia `ARCHIVO_EXCLUIDO_NO_NORMALIZADO`). |
| `POLIZA_GLOBAL` / `POLIZA_EGRESO` | Pólizas. |

- Un duplicado de un archivo de **otro** lote sí cuenta en este lote: los lotes no se mezclan.
- **Grupo de una dispersión.** Es el grupo de al menos el 90 % de sus registros identificados; si no se alcanza, MIXTO.
- **Dispersión sin personas identificadas** (p. ej. interbancaria sin número de empleado):
  - desde la v2, toma la **población declarada** del archivo (`archivos_importados.poblacion`, que fija el espacio de carga de la pantalla de la quincena);
  - si el archivo no tiene población declarada (archivos anteriores), toma el grupo de la póliza de egreso con la que cuadra por importe exacto, si esa póliza es de un solo grupo. El origen queda en `detalle.grupo_origen = POLIZA_EGRESO`.
- **Población declarada contradicha.** Si el archivo tiene personas identificadas y el grupo que resulta de ellas (regla del 90 %) es distinto de la población declarada, manda el de las personas y se genera `POBLACION_ARCHIVO_NO_COINCIDE` (ADVERTENCIA, nivel 0) con `detalle.declarada` e `detalle.identificada`.

## 4. Los cinco niveles

| Nivel | Control (`conciliacion_controles.codigo`) | Comparación |
|---|---|---|
| 1 | `RRHH_GLOBAL_PERCEPCIONES`, `_DEDUCCIONES`, `_NETO`, `_EMPLEADOS` | Suma del detalle RRHH frente a los valores declarados en la póliza GLOBAL, por grupo. **Sólo si el lote tiene alguna póliza GLOBAL**: sin ella (v2) no se generan controles ni avisos de nivel 1. |
| 2 | `GLOBAL_VS_EGRESO_NETO` | Neto de la GLOBAL frente a la suma de las hojas bancarias de nómina de las pólizas de egreso del grupo. |
| 3 | `DISPERSION_TOTAL_ARCHIVO`, `POLIZA_EGRESO_VS_DISPERSION`, `DISPERSION_SIN_POLIZA_EGRESO` | Detalle de la dispersión frente al total del archivo; hoja de egreso frente a la dispersión relacionada. |
| 4 | Por persona + `NOMINA_RRHH_VS_TESORERIA` (resumen) | Neto RRHH frente a los pagos de nómina identificados. |
| 5 | Por persona + `PENSION_RETENIDA_VS_DISPERSADA` (resumen) | Pensión retenida por RRHH frente a la pensión dispersada. |

**Relación de una hoja de egreso con su dispersión** (nivel 3):
- **Monto de la hoja.** Es el HABER de la cuenta de bancos (`conciliacion.cuenta_bancos`, por omisión 1112).
  - Se contrasta con el monto del nombre de la hoja; si difieren, se genera `POLIZA_HOJA_MONTO_INCONSISTENTE`.
- **Orden de relación:**
  1. la relación **manual** vigente (`relaciones_poliza_dispersion`, auditada), que se reserva **antes** del emparejamiento automático;
  2. si no la hay, una dispersión compatible no reservada (el banco de la hoja; una hoja «SANTANDER» también paga INTERBANCARIA) con importe exacto.
- **Resultado:**

  | Situación | Estado |
  |---|---|
  | Varias candidatas exactas | REVISION_MANUAL |
  | Ninguna exacta, pero hay dispersiones compatibles libres | REVISION_MANUAL, con las candidatas y sus diferencias; el motor propone, no elige |
  | Ninguna candidata | NO_DETERMINABLE |
  | Hoja con importe 0 | INFORMATIVO |

## 5. Reglas monetarias por persona (nivel 4)

- `esperado` = neto RRHH del registro fuente.
- `pagado` = suma de los pagos de nómina **identificados**, desglosados en SANTANDER, BBVA, INTERBANCARIA y OTROS. Los pagos divididos se suman.
- `diferencia = pagado − esperado`. La BD lo garantiza con una CHECK, igual que `pagado_total` = suma de las columnas por banco.

| Estado | Regla |
|---|---|
| CORRECTO | \|diferencia\| ≤ tolerancia |
| PAGO_MENOR / PAGO_MAYOR | Diferencia negativa / positiva fuera de tolerancia |
| NO_PAGADO | Esperado > 0 y ningún pago identificado |
| PAGO_SIN_RESPALDO_RRHH | Pago identificado sin registro RRHH en la fuente del lote (o esperado 0) |
| POSIBLE_PAGO_DUPLICADO | Dos pagos con el mismo importe y la misma cuenta (HMAC) o el mismo banco y referencia; sin uno de ellos el pago sería correcto |
| PENDIENTE_IDENTIDAD | Faltante que un pago **pendiente de identidad** completaría exactamente. El estado matemático (p. ej. NO_PAGADO) se conserva en `detalle.estado_matematico` |
| CONFLICTO_IDENTIDAD | Igual que el anterior, pero el pago pendiente está en CONFLICTO |
| ERROR_DATOS_RRHH | Registro RRHH con importes inválidos o neto negativo |
| NO_APLICA | Sin neto a pagar y sin pagos |

**Pagos pendientes de identidad:**
- Los candidatos salen de la evidencia del matching (SUGERENCIA, AMBIGUO o CONFLICTO), o del nombre exacto único dentro de la fuente RRHH del lote (nunca si un usuario decidió «no identificado»).
- Sólo reclasifica un pago con **un único dueño posible**. Si tiene varios candidatos, únicamente se marca una alerta, sin contarlo dos veces.
- Si no completan el faltante, sólo se marca la alerta `POSIBLE_PAGO_PENDIENTE`; el resultado matemático no cambia.
- **Nunca** se suman al pagado de la persona.

**Alertas** (no cambian el estado): `PAGOS_DIVIDIDOS`, `POSIBLE_PAGO_PENDIENTE`, `REGISTRO_EN_CONFLICTO`, `BAJA_EN_RRHH`.

## 6. Pensiones (nivel 5, independiente)

- **Retenido.** Son los conceptos de pensión del catálogo (044, 060, 171, 249, 263, configurables en `conceptos_nomina.es_pension`).
- **Signo de la deducción.** Cada fuente guarda:
  - `importe_original`: el valor tal como viene;
  - `importe`: la magnitud aplicada, **una sola vez**, según la convención detectada del archivo (`signo_deducciones`):

    | Convención | Cálculo del retenido |
    |---|---|
    | NEGATIVO | `retenido = −importe_original` |
    | POSITIVO | `retenido = importe_original` |
    | Desconocida | `abs` |

- **Dispersado.** Pagos con categoría PENSION identificados con el trabajador.
- **Estados:** CORRECTA, PENSION_MENOR, PENSION_MAYOR, NO_DISPERSADA, SIN_RETENCION_RRHH y PENDIENTE_IDENTIDAD.
- **PENDIENTE_IDENTIDAD** se asigna sólo si se cumple una de estas condiciones:
  - un pago pendiente cuyo **único** trabajador candidato es la persona completa el faltante; o
  - el importe es **único en ambos lados**: exactamente un pago pendiente tiene ese importe y exactamente una persona tiene ese faltante.
- En cualquier otro caso, el estado matemático se conserva y se añade la alerta `POSIBLE_PAGO_PENDIENTE`.
- Con registros RRHH duplicados cuenta la retención de uno solo (alerta `REGISTRO_EN_CONFLICTO`).
- El total retenido incluye los registros RRHH sin persona y los informa aparte como `retenido_sin_empleado`.
- La pensión **nunca** se mezcla con el neto de nómina.

## 6b. Versión 2: población del archivo y pensiones por total de población

Las pólizas son opcionales y la pantalla de la quincena carga cada archivo en un espacio Policías/Bomberos × documento. La v2 aprovecha esa población declarada:

1. **Población declarada.** El trabajo `CONCILIAR_LOTE` lee `archivos_importados.poblacion` (POLICIA, BOMBEROS o NULL). Un archivo de Tesorería **sin personas identificadas** queda en esa población (§3). Si las personas identificadas la contradicen, prevalecen ellas y se avisa con `POBLACION_ARCHIVO_NO_COINCIDE` (ADVERTENCIA).
2. **Pensiones cubiertas por el total de la población** (`PensionLevel::coverByPopulation`). Para cada población con pagos de pensión pendientes de identidad (archivos sin número de empleado):
   - se suman los faltantes (retenido − dispersado) de las personas en NO_DISPERSADA, PENSION_MENOR o PENDIENTE_IDENTIDAD;
   - si esa suma es igual, dentro de la tolerancia (0.01), al importe pendiente de identidad de la población, todas esas personas pasan a **PENDIENTE_IDENTIDAD** («Pendiente de identificar»), con `detalle.cubierta_por_total_poblacion = true`, el estado matemático en `detalle.estado_matematico` y la regla explicada en `detalle.regla`;
   - su incidencia por persona baja a **INFO** («La pensión retenida a la población se dispersó completa; falta identificar al beneficiario de esta retención.»);
   - si la suma **no** coincide, los estados y errores (`PENSION_NO_DISPERSADA`, `PENSION_PENSION_MENOR`…) se conservan sin cambio.
3. **Pendiente global de pensiones.** `IMPORTE_PENSION_PENDIENTE_IDENTIDAD` es INFO (en lugar de ADVERTENCIA) cuando todo el pendiente pertenece a Policías o Bomberos y en cada una de ellas la pensión retenida por RH es igual a la dispersada (tolerancia 0.01). En cualquier otro caso sigue en ADVERTENCIA. El pendiente de **nómina** (`IMPORTE_PENDIENTE_IDENTIDAD`) no cambia: los pagos de nómina sin persona se resuelven en «Pendientes de identificar» (confirmación en bloque o uno por uno).
4. **Sin póliza GLOBAL** no hay controles ni avisos de nivel 1 (§4).
5. **Lo que no cambia:** la tolerancia sigue en 0.01; el importe sin persona nunca se asigna a nadie (se cuenta en el total de la población, no en la persona); los nombres **nunca** se comparan por parecido: el único uso del nombre es la coincidencia **exacta** y única ya descrita en el §5.

**Estado de la ejecución.** Las incidencias INFO no cuentan: una ejecución cuyas únicas incidencias son INFO queda COMPLETADA. En la interfaz, «Por atender» cuenta sólo las incidencias abiertas distintas de INFO.

## 7. Totales, tablero y % conciliado

- Por lote (TOTAL) y por grupo (POLICIA, BOMBEROS):
  - **RRHH:** percepciones, deducciones, neto, registros y pensión retenida;
  - **Tesorería (nómina):** total, identificado, pendiente de identidad y desglose por banco;
  - **Pensiones:** retenido, dispersado, identificado y pendiente;
  - `diferencia_global_nomina = total dispersado − neto RRHH`.
- **% conciliado** = personas CORRECTAS ÷ personas evaluables (se excluyen sólo las NO_APLICA).
  - Los pendientes de identidad **no** cuentan como correctos ni como error.
  - Se informan aparte: `pct_pendiente_identidad` y `pct_monto_conciliado` (neto esperado de personas correctas ÷ neto esperado evaluable).
- **Lote sin fuente RRHH** (p. ej. un lote sólo de Tesorería):
  - no hay neto esperado;
  - los resultados por persona se conservan, pero la causa se informa con **una** incidencia CRITICA `LOTE_SIN_FUENTE_RRHH`, no con una por persona.

## 8. Incidencias

| Severidad | Uso |
|---|---|
| INFO | Hechos informativos; desde la v2, también las pensiones pendientes de identificar cubiertas por el total de su población (§6b). |
| ADVERTENCIA | Pendientes de identidad, relaciones por decidir, hojas sin dispersión igual. |
| ERROR | Diferencias monetarias y controles que no cuadran. |
| CRITICA | El lote no es conciliable con confianza: sin RRHH, fuente ambigua, versiones ambiguas. |

**Tipos por nivel:**
- **Nivel 0:** LOTE_SIN_FUENTE_RRHH, FUENTE_RRHH_AMBIGUA, VERSION_AMBIGUA_EN_LOTE, LOTE_SIN_DISPERSION, POBLACION_ARCHIVO_NO_COINCIDE (v2).
- **Nivel 1:** RRHH_POLIZA_{PERCEPCIONES,DEDUCCIONES,NETO,EMPLEADOS}_NO_CUADRA, POLIZA_GLOBAL_MULTIPLE.
- **Nivel 2:** GLOBAL_EGRESO_NO_CUADRA.
- **Nivel 3:** DISPERSION_TOTAL_NO_CUADRA, POLIZA_BANCO_NO_CUADRA, POLIZA_BANCO_RELACION_AMBIGUA, POLIZA_BANCO_SIN_DISPERSION(_IGUAL), POLIZA_HOJA_MONTO_INCONSISTENTE.
- **Nivel 4:** los estados por persona y IMPORTE_PENDIENTE_IDENTIDAD.
- **Nivel 5:** PENSION_*, IMPORTE_PENSION_PENDIENTE_IDENTIDAD (INFO o ADVERTENCIA según el §6b).

**Gestión de incidencias:**
- Estados de gestión: ABIERTA → EN_REVISION → RESUELTA / ACEPTADA_JUSTIFICADA, con comentario obligatorio y evidencia opcional.
- El historial es append-only y queda auditado.
- **Resolver ≠ cambiar dinero.** Tipo, severidad, importe y detalle son inmutables en BD. Una corrección de origen llega con un archivo nuevo y una nueva ejecución.

## 9. Rendimiento

- La lectura usa 9 consultas y la escritura, `INSERT` por lotes.
- El cálculo es lineal en memoria, con índices por persona, archivo y nombre.
- La métrica queda en `conciliaciones.metricas`: ms por fase, consultas, RSS máximo y registros por segundo.

## 9b. Cambios de identidad posteriores y comparativo (FASE 6)

- **Cambios posteriores.** Si después de la ejecución vigente hay asociaciones nuevas en registros del lote, cuentas confirmadas, relaciones de beneficiario o importaciones nuevas o sustituidas, el tablero lo indica y ofrece «Reconciliar» (nueva ejecución). La ejecución histórica nunca cambia. La vista previa es técnica (conteos): **los importes sólo los calcula el motor**.
- **Comparativo `/conciliaciones/{a}/comparar/{b}`** (mismo lote):
  - personas por estado y % conciliado;
  - montos (diferencia global, identificado, pendientes, pensiones);
  - la lista de personas que cambiaron.
- **Motivos del comparativo.** Cada motivo se deriva de las **fuentes** de ambas ejecuciones y del **historial**:
  - pago antes pendiente y ahora identificado, indicando quién y si fue una acción masiva;
  - pago nuevo en un archivo;
  - pago que dejó de atribuirse;
  - cambio del registro RRHH fuente;
  - cambio de versión del motor o de tolerancia.
- Si no hay evidencia en las fuentes, se dice explícitamente: nunca se inventa una causa.

## 9c. Identidad canónica (FASE 7)

- **Resolución:** al cargar las entradas, `CONCILIAR_LOTE` resuelve cada `empleado_id` (y cada candidato de un pago pendiente) a su identidad **canónica** (`coalesce(fusionado_en_id, id)`). Los registros de un empleado fusionado cuentan para su canónico. Los registros no se modifican.
- **Huella de entradas:** incluye la identidad resuelta, así que la primera ejecución tras una fusión nunca se marca «igual» a una anterior.
- **Ejecuciones anteriores:** conservan sus resultados (inmutables), con ambos empleados por separado.
- **Aviso y comparativo:** el aviso de cambios de identidad cuenta las fusiones y separaciones que involucran registros del lote. El comparativo explica «se fusionó en el número…» para el origen y «recibe los registros del número…» para el destino, sin repetir como «pago nuevo» los registros que llegaron por la fusión.

## 11. Pruebas

- `tests/Engine/ConciliationEngineTest.php` son pruebas puras de los casos obligatorios de la fase: estados, tolerancia 0.01 / 0.02, compensación, duplicados, pendientes, pólizas, pensiones, selección de archivos y determinismo. Reglas de la v2:
  - `testDeclaredPopulationCoversUnidentifiedInterbankPensionsByTotal`;
  - `testPopulationTotalThatDoesNotMatchKeepsTheErrors`;
  - `testWithoutDeclaredPopulationUnidentifiedPensionIsNotAttributed`;
  - `testWithoutGlobalPolicyThereAreNoLevelOneControlsNorWarnings`;
  - `testDeclaredPopulationContradictedByIdentifiedPeopleIsFlagged`.
- `tests/Engine/ConciliateLotJobTest.php` recorre el lote de extremo a extremo con PostgreSQL y el procesador real:
  - explicabilidad y deducción negativa;
  - duplicado en el lote frente a otro lote;
  - nueva ejecución, idempotencia y congelamiento;
  - lote en proceso;
  - privilegios web.
- `tests/Http/ConciliationHttpTest.php` cubre por HTTP:
  - tablero y permisos;
  - resolver sin cambiar dinero;
  - cierre, invalidación exclusiva de GOD y reejecución con actor de sesión;
  - exportación segura e IDOR.

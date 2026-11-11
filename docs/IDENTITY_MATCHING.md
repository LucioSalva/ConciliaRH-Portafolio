# Identidades, asociación y registros importados (FASE 4)

Este documento describe la **capa maestra de personas**: el empleado maestro, su historial de identidad, los registros definitivos (RRHH, Tesorería, pólizas), las cuentas/CLABE protegidas y las reglas **determinísticas** que asocian cada registro con una persona.

La conciliación monetaria (pago menor, pago mayor, no pagado, diferencias RRHH vs Tesorería) **no** es parte de esta fase: corresponde a la FASE 5.

---

## 1. Principios

1. **Sin aprendizaje automático.** Las reglas están escritas y versionadas (`MATCHING_VERSION = 1`). El sistema no "aprende" relaciones: una relación nueva sólo se vuelve confiable por **evidencia fuerte** o por **confirmación de un usuario autorizado**.
2. **El nombre nunca asocia por sí solo.** Nombre exacto y único ⇒ **SUGERENCIA** (confianza MEDIA). Varios ⇒ **AMBIGUO**. Parecido (fuzzy) ⇒ sólo apoyo visual.
3. **Nunca se elige por prioridad ante contradicciones.** Dos identificadores fuertes que señalan a personas distintas ⇒ **CONFLICTO** y revisión manual.
4. **RRHH es la fuente maestra.** Sólo un tabulado RRHH **confiable** crea o actualiza empleados. **Tesorería nunca crea empleados** (restricción en BD: `empleados_fuente` sólo admite `RRHH_TABULADO` y el origen es FK a `registros_rrhh`).
5. **Nada se sobrescribe ni se borra.** Maestro con historial por campo; registros por importación versionada; cuentas que se desactivan pero no se borran; historiales de sólo inserción.
6. **Trazabilidad completa** en ambos sentidos: empleado → registro → fila → hoja → archivo → lote → periodo, y al revés.

## 2. Modelo

| Entidad | Propósito |
|---|---|
| `empleados` | Maestro. `numero_empleado` **texto** único (conserva ceros iniciales). RFC/CURP con validez calculada en BD (columnas `GENERATED`), **sin UNIQUE**: un duplicado es incidencia, no error de carga. Datos actuales (última quincena conocida). |
| `empleado_historial` | Append-only: campo, anterior, nuevo, fuente (`ALTA_RRHH`, `RRHH_TABULADO`, `MANUAL`), importación/registro/periodo, usuario/proceso, motivo. |
| `importaciones_normalizadas` | Una por corrida de normalización de un análisis. Estados `VIGENTE` / `SUSTITUIDA` / `INVALIDADA`; **una VIGENTE por archivo** (índice único parcial). |
| `registros_rrhh` | **Snapshot de la quincena** por persona: identificación original y normalizada, área/puesto/cargo, fechas, fuente, clave programática, importes `NUMERIC(14,2)`, asociación. |
| `registros_rrhh_conceptos` | Importe por código de concepto (sólo importes distintos de cero). El tipo y la marca de pensión se leen del catálogo. |
| `conceptos_nomina` | Catálogo por **código**. 044, 060, 171, 249 y 263 sembrados como pensión alimenticia (`CONFIGURADA`). Ampliable. |
| `registros_tesoreria` | Dispersión de nómina o pensión: banco, categoría, identificación original (en pensión el nombre es del **beneficiario**), cuenta protegida, importe, asociación (en pensión: el **trabajador**). |
| `cuentas_bancarias` | Identidad bancaria: empleado, uso (`NOMINA` / `PENSION_BENEFICIARIO`), tipo (`CUENTA`/`CLABE`), HMAC, últimos 4, cifrado, origen (`EVIDENCIA_FUERTE` / `CONFIRMACION_MANUAL`), vistas. **Una ACTIVA por (HMAC, uso)**. |
| `polizas_globales` (+ líneas) | Póliza GLOBAL por grupo y hoja: bloques PERCEPCIONES/DEDUCCIONES con código, importe y totales. No crea empleados. |
| `polizas_egreso_lineas` | Póliza de egreso por hoja: banco derivado del nombre de la hoja, cuenta/subcuentas, concepto, DEBE/HABER. |
| `incidencias_identidad` | Conflictos, identificadores inválidos o duplicados, cuentas nuevas, etc. Clave determinística (`clave` única): no se duplican al reprocesar. |
| `asociaciones_historial` | Append-only: cada decisión automática o manual con estado anterior/nuevo, empleado, usuario/proceso y motivo. |
| `lotes_relaciones` | CORRIGE / SUSTITUYE / COMPLEMENTA / RELACIONADO entre lotes vigentes del mismo periodo. Sólo documenta: no mezcla datos. |

**Beneficiarios:** en esta fase no hay maestro de beneficiarios. El nombre del beneficiario queda como snapshot en el registro y en la cuenta `PENSION_BENEFICIARIO`; el modelo permite crecer a una entidad propia sin inventar relaciones familiares.

## 3. Normalización de identificadores (`app/Engine/Identity/PersonIds.php`)

| Dato | Regla | Inválido |
|---|---|---|
| Nombre | Se conserva el **original** (NFC, sin invisibles, espacios simples). Clave de comparación: NFKD sin acentos (Ñ→N), mayúsculas, sin puntuación, espacios simples. **No se eliminan palabras** (DE, DEL, LA…). | — |
| RFC | Mayúsculas, sin espacios/guiones/puntos. Estructura `^[A-ZÑ&]{3,4}\d{6}[A-Z0-9]{3}$`. | Se marca `rfc_valido = false`, incidencia `RFC_INVALIDO`; no se usa para asociar. |
| CURP | Igual; estructura con entidad federativa válida y consonantes internas. | `CURP_INVALIDO`. |
| Número de empleado | Texto; `123.0` → `123`; válido si `^[0-9A-Z][0-9A-Z-]{0,19}$` y contiene algún dígito. | `NUMERO_EMPLEADO_INVALIDO` (caso real: nombres en la columna del número en tabulados derivados). |

Un identificador inválido **nunca descarta el registro**. Las mismas expresiones se aplican en SQL (columnas `GENERATED` de `empleados`) y en PHP (`EmployeeService::RFC_RE/CURP_RE`); una prueba (`tests/Engine/NormalizeRecordsTest.php`) verifica que SQL y el procesador coinciden.

## 4. Estados, métodos y confianza

| Estado | Significado | ¿Cerrado? |
|---|---|---|
| `ASOCIADO_AUTOMATICO` | Evidencia fuerte, única y no contradictoria. | Sí |
| `ASOCIADO_MANUAL` | Decisión de un usuario (con motivo). | Sí |
| `SUGERENCIA` | Nombre exacto y único: requiere confirmación. | Pendiente |
| `AMBIGUO` | Nombre exacto con varios candidatos. | Pendiente |
| `CONFLICTO` | Evidencia fuerte contradictoria o identificador fuerte discrepante con el maestro. | Pendiente |
| `NO_IDENTIFICADO` | Sin evidencia suficiente. | Pendiente |
| `NO_IDENTIFICADO_CONFIRMADO` | Un usuario confirmó que no corresponde a nadie del maestro. | Sí |
| `INVALIDO` | Sin ningún dato de identidad. | Sí |

"Revisión manual" es la bandeja de los cuatro estados pendientes. Métodos: `NUMERO_EMPLEADO`, `RFC`, `CURP`, `CUENTA_CONFIRMADA`, `NOMBRE_EXACTO` (sólo en sugerencias), `ALTA_RRHH`, `MANUAL`. Confianza: `ALTA`, `MEDIA`, `BAJA`, `MANUAL`, `NINGUNA`.

## 5. Reglas de asociación (`app/Engine/Identity/Matcher.php`)

Orden de evidencia:

1. número de empleado exacto (válido);
2. RFC exacto normalizado (válido);
3. CURP exacta normalizada (válida);
4. cuenta/CLABE **activa** en `cuentas_bancarias` con el mismo uso (nómina o pensión);
5. nombre normalizado exacto y único → **SUGERENCIA** (nunca asociación);
6. parecido ≥ 0.85 (`SequenceMatcher`, equivalente a difflib) (hasta 3, entre quienes comparten primer o último token) → sólo apoyo visual.

Reglas:

* Se reúnen los candidatos de **todas** las evidencias fuertes. Un solo candidato ⇒ `ASOCIADO_AUTOMATICO` (método = primera evidencia). Varios ⇒ `CONFLICTO` (`IDENTIFICADORES_CONTRADICTORIOS`).
* Con un candidato, si el registro trae un RFC o CURP **válido** distinto del válido del maestro ⇒ `CONFLICTO` (`RFC_DISCREPANTE` / `CURP_DISCREPANTE`). El nombre distinto sólo genera `NOMBRE_DIFERENTE` (INFO).
* RFC/CURP repetido en el maestro: si incluye a la persona ya señalada por el número, concuerda (incidencia aparte); si no, es ambiguo ⇒ `CONFLICTO`.
* Un número **válido que no existe** en el maestro junto con otra evidencia fuerte (RFC, CURP o cuenta de otra persona) ⇒ `CONFLICTO` (`NUMERO_DESCONOCIDO_CON_OTRA_EVIDENCIA`).
* **RRHH:** un número **válido y nuevo** cuyo RFC/CURP ya pertenece a otro empleado ⇒ `CONFLICTO` (`NUMERO_NUEVO_IDENTIDAD_EXISTENTE`), nunca un segundo empleado. Un número inválido no se usa; el registro puede asociarse por RFC/CURP.
* **Pensiones:** el nombre es del beneficiario y **nunca** se compara. Se asocia al **trabajador** sólo por número de empleado o por una cuenta `PENSION_BENEFICIARIO` ya registrada. Sin eso ⇒ `NO_IDENTIFICADO` con motivo `PENSION_SIN_TRABAJADOR`.
* **Santander:** se compara «PATERNO MATERNO NOMBRE», el mismo orden del tabulado.
* **Sugerencias rechazadas** (`rechazados`) no se vuelven a proponer para ese registro (ni al re-normalizar el mismo archivo).

### Interbancaria (decisión funcional vigente)

* **Primera vez:** nombre exacto y único ⇒ `SUGERENCIA` (MEDIA) ⇒ revisión manual. No se crea cuenta.
* El analista **asocia** y, si marca «Confirmar esta CLABE como medio de pago conocido» (permiso `cuentas.confirmar`), la BD copia HMAC y cifrado desde el registro (`fn_confirmar_cuenta`), con usuario, fecha, motivo, historial y auditoría (sólo últimos 4). La función exige que la asociación manual la haya hecho ese mismo usuario en la misma transacción, con permiso, importación vigente y periodo abierto.
* **Cargas futuras:** la CLABE confirmada es evidencia fuerte ⇒ `ASOCIADO_AUTOMATICO` por `CUENTA_CONFIRMADA`, salvo conflicto con otra evidencia (⇒ `CONFLICTO`).
* Al confirmar se re-asocian automáticamente los pendientes de importaciones vigentes que comparten esa cuenta.

## 6. Alta y actualización del maestro (RRHH)

Una importación RRHH **puede actualizar el maestro** (`puede_actualizar_maestro`) sólo si:

* el perfil es un tabulado RRHH (la confianza MEDIA de la variante policía/bomberos **no** lo impide: el perfil general es seguro);
* el análisis **no** trae `VISTA_DERIVADA` (filtro o filas ocultas: los tabulados manipulados sólo asocian);
* el archivo no es `DUPLICADO_BINARIO` ni `DUPLICADO_CONTENIDO`;
* el lote y el periodo no están anulados.

**Alta automática:** número válido y nuevo + nombre + ningún RFC/CURP de otra persona ⇒ empleado nuevo (`fuente_creacion = RRHH_TABULADO`, confianza ALTA), historial `alta` y auditoría `empleados.alta_automatica`.

**Correcciones manuales:** un campo corregido por un usuario (RFC, CURP, ISSEMYM, activo) prevalece: RRHH ya no lo cambia y la diferencia queda como incidencia `DATOS_RRHH_CONTRADICTORIOS`. Una decisión manual trasladada (asociado o no identificado) impide el alta automática de esa fila.

**Actualización:** sólo si la quincena del registro es igual o posterior a la última conocida del empleado (un periodo anterior nunca pisa datos más recientes). Cada campo cambiado va a `empleado_historial`. RFC/CURP sólo se reemplazan por valores **válidos**, y nunca por uno que ya tiene otro empleado (incidencia `ACTUALIZACION_IDENTIFICADOR_DUPLICADO`). El número de empleado y el origen son inmutables (trigger). No hay fusión de empleados: posibles duplicados ⇒ incidencia (`RFC_DUPLICADO_EN_MAESTRO`, `CURP_DUPLICADO_EN_MAESTRO`).

**Snapshot:** cada `registros_rrhh` conserva nombre, área, puesto, cargo, RFC y CURP tal como venían en su quincena, aunque el maestro cambie después.

## 7. Cuentas y CLABE

* **Búsqueda exacta:** `HMAC-SHA256(ACCOUNT_HMAC_KEY, "cuenta:" + dígitos)`. Nunca SHA-256 sin llave (una CLABE es enumerable).
* **Valor completo:** AES-256-GCM, formato `0x01 ‖ nonce(12) ‖ ciphertext ‖ tag(16)`, AAD `conciliarh:cuenta:v1:<key_id>`; `key_id` en su propia columna. Anillo `ACCOUNT_ENC_KEYS="k1:64hex[,k2:64hex]"` y llave activa `ACCOUNT_ENC_KEY_ID`, desde configuración (nunca en el código). La app no arranca sin un anillo válido que contenga la llave activa.
* **Visualización:** siempre `••••1234`. Revelar exige `cuentas.ver_sensible`, es un POST explícito con CSRF, la respuesta es `no-store` y la auditoría registra sólo los últimos 4.
* **Quién cifra:** el motor (análisis). El staging guarda HMAC + últimos 4 + cifrado; la web **no** puede leer HMAC ni cifrado de registros ni de staging (privilegios por columna). PHP sólo descifra al revelar.
* **Evidencia fuerte:** un registro de Tesorería asociado automáticamente con cuenta: si la cuenta está activa para ese empleado se registra la vista (`ultima_vista_at`, `veces_vista`); si no existe se crea (`EVIDENCIA_FUERTE`); si el empleado ya tenía otra cuenta activa del mismo uso se **conservan ambas** e informa `CUENTA_NUEVA` (INFO). Una cuenta activa de otra persona hace el registro `CONFLICTO`.
* **Qué cuenta es evidencia:** una confirmada manualmente, o una respaldada por al menos un registro de una importación VIGENTE asociado a su dueño por otro identificador fuerte (la cuenta de un archivo anulado deja de identificar por sí sola).
* **Desactivar** (`cuentas.confirmar`, con motivo): la cuenta deja de identificar; no se borra ni se reactiva (hay que confirmarla de nuevo).

### Rotación de llave de cifrado

1. Generar `k2` (32 bytes aleatorios en hex) y agregarla al anillo: `ACCOUNT_ENC_KEYS="k1:…,k2:…"`.
2. Cambiar la activa: `ACCOUNT_ENC_KEY_ID=k2` y reiniciar web y worker. Lo nuevo se cifra con `k2`; lo existente sigue descifrándose con `k1` (el `key_id` va en cada fila y en el AAD).
3. Re-cifrar (proceso de mantenimiento con el rol del worker: `UPDATE cuentas_bancarias SET cuenta_cifrada, key_id`; staging y registros con el propietario): descifrar con `k1`, cifrar con `k2`. El HMAC no cambia.
4. Cuando no quede ninguna fila con `key_id = 'k1'`, retirar `k1` del anillo.

Rotar `ACCOUNT_HMAC_KEY` exige recalcular todos los HMAC descifrando cada cuenta (el cifrado lo permite); hacerlo en mantenimiento, en una transacción, junto con `staging_registros.cuenta_hmac` y `registros_tesoreria.cuenta_hmac`.

## 8. Trabajo `NORMALIZAR_REGISTROS`

* **Encadenamiento:** el trigger `archivos_encolar_normalizacion` lo encola cuando un análisis cierra en `ANALIZADO` o `ANALIZADO_CON_ADVERTENCIAS` (misma transacción). `REVISION_MANUAL` no se normaliza.
* **Idempotencia:** si ya existe la importación VIGENTE de ese análisis con la misma versión de reglas y no se pidió «completa», sólo **re-asocia pendientes**. Nunca duplica empleados, registros, cuentas, asociaciones ni incidencias (índices únicos + claves determinísticas).
* **Importación completa:** una transacción por archivo con candado consultivo global (dos normalizaciones concurrentes no crean el mismo empleado ni la misma cuenta):
  1. la importación anterior VIGENTE del archivo queda `SUSTITUIDA` (sus incidencias, descartadas);
  2. se trasladan decisiones **manuales** y rechazos a los registros idénticos (mismo `hash_registro`);
  3. altas y actualización del maestro (sólo RRHH confiable);
  4. registros y conceptos con **`INSERT` por lotes** (ids reservados de la secuencia);
  5. cuentas, incidencias, historial de asociaciones;
  6. auditoría de cada alta y un resumen por archivo;
  7. métricas agregadas en `importaciones_normalizadas.metricas`.
* **Versionado:** un nuevo análisis del archivo (otra hoja/perfil, reanálisis) o su anulación ⇒ la importación vigente pasa a `INVALIDADA` por trigger; sus registros se conservan (trazabilidad) pero dejan de contar. La siguiente normalización crea la nueva VIGENTE.
* **Periodos cerrados:** quedan congelados; ni la web ni el motor re-asocian sus registros.
* **Errores:** un error de integridad se registra sólo con tipo, SQLSTATE, tabla y restricción (el mensaje de PostgreSQL puede contener la fila).
* **Re-asociación:** tras un RRHH con altas (o Tesorería con cuentas nuevas) se re-evalúan los pendientes de las demás importaciones vigentes del periodo (cubre Tesorería cargada antes que RRHH). Sólo pendientes; cada UPDATE exige la misma `asociacion_version` leída (una decisión manual simultánea gana).
* **Tipo de concepto:** por **cuadre** en el tabulado (columnas de percepciones antes que las de deducciones: se busca el corte donde Σ = PERCEP y |Σ| = DEDUC en ≥ 90 % de filas; los tabulados reales registran las deducciones en negativo) o por el bloque de la póliza GLOBAL. Los códigos nuevos quedan `INFERIDA`; `CONFIGURADA` nunca la cambia el motor. Discrepancias ⇒ métrica `conceptos_tipo_discrepante`.

## 9. Asociación manual (web)

Bandeja `/asociaciones` (filtros: pendientes, conflicto, sugerencia, ambiguo, no identificado, asociados manualmente; origen, banco, archivo, nombre) y detalle `/asociaciones/{rrhh|tesoreria}/{id}` con origen, identificadores, candidatos, evidencia contradictoria, parecidos (con las palabras que difieren resaltadas) e historial.

Acciones (`asociaciones.resolver`, con motivo obligatorio, bloqueo `FOR UPDATE` y verificación de versión):

* **Asociar** (a un candidato o por número de empleado); en pensiones, al trabajador. Opción de **confirmar la cuenta/CLABE** (`cuentas.confirmar`).
* **Rechazar sugerencia** (queda en `rechazados`; se re-evalúa el registro).
* **Marcar no identificado** (cierra el registro sin empleado).

No se modifican asociaciones de importaciones no vigentes, periodos cerrados o lotes anulados. Cada acción: `asociaciones_historial` + auditoría (`asociaciones.asociar`, `asociaciones.rechazar_sugerencia`, `asociaciones.no_identificado`, `cuentas.confirmar`), con `resuelve_conflicto` cuando aplica.

## 10. Permisos

| Permiso | GOD | ADMIN | CONSULTA |
|---|---|---|---|
| `empleados.ver` | ✔ | ✔ | ✔ (RFC/CURP enmascarados) |
| `empleados.ver_sensible` (RFC/CURP completos y búsqueda por ellos) | ✔ | ✔ | — |
| `empleados.editar` | ✔ | ✔ | — |
| `asociaciones.ver` | ✔ | ✔ | ✔ |
| `asociaciones.resolver` | ✔ | ✔ | — |
| `cuentas.ver_sensible` (revelar) | ✔ | ✔ | — |
| `cuentas.confirmar` (confirmar/desactivar) | ✔ | ✔ | — |
| `lotes.relacionar` | ✔ | ✔ | — |
| `importacion.normalizar` | ✔ | ✔ | — |
| `conceptos.editar` | ✔ | — | — |

ADMIN recibe estos permisos explícitamente (no hereda los de GOD). Desde la migración 0026 sólo existen GOD, ADMIN y CONSULTA; el antiguo ANALISTA pasó a ADMIN. Los conceptos de nómina los edita sólo GOD.

## 11. Incidencias de identidad

`NUMERO_EMPLEADO_INVALIDO`, `RFC_INVALIDO`, `CURP_INVALIDO`, `NUMERO_EMPLEADO_DESCONOCIDO`, `IDENTIFICADORES_CONTRADICTORIOS`, `IDENTIFICADOR_DUPLICADO_EN_MAESTRO`, `RFC_DISCREPANTE`, `CURP_DISCREPANTE`, `NUMERO_NUEVO_IDENTIDAD_EXISTENTE`, `NUMERO_DUPLICADO_EN_ARCHIVO`, `NOMBRE_DIFERENTE`, `NOMBRE_AMBIGUO`, `NOMBRE_SIN_COINCIDENCIA`, `IDENTIDAD_INCOMPLETA`, `ALTA_SIN_NOMBRE`, `ACTUALIZACION_IDENTIFICADOR_DUPLICADO`, `RFC_DUPLICADO_EN_MAESTRO`, `CURP_DUPLICADO_EN_MAESTRO`, `CUENTA_NUEVA`, `CLABE_CONFLICTO`, `NUMERO_DESCONOCIDO_CON_OTRA_EVIDENCIA`, `DATOS_RRHH_CONTRADICTORIOS`, `IMPORTE_INVALIDO`. Una incidencia cerrada por un usuario no se reabre sola. Severidad `INFO` / `ADVERTENCIA` / `ALTA`. Se resuelven o descartan con motivo; una re-asociación que cambia el estado las resuelve automáticamente.

## 11b. Uso de la identidad en la conciliación (FASE 5)

- La conciliación usa la asociación **vigente al ejecutar**, y cada fuente guarda su `asociacion_estado`. Confirmar después una CLABE o una asociación cambia sólo las ejecuciones **nuevas**; el historial no se reescribe.
- Los registros en SUGERENCIA, AMBIGUO, CONFLICTO o NO_IDENTIFICADO **no** suman al pagado de nadie. Se reportan como IMPORTE_PENDIENTE_IDENTIDAD / IMPORTE_PENSION_PENDIENTE_IDENTIDAD.
- Sólo explican el faltante de una persona cuando tienen un **único** dueño posible (estado PENDIENTE_IDENTIDAD / CONFLICTO_IDENTIDAD, con el estado matemático conservado).
- **FASE 7:** señales entre periodos para las pensiones interbancarias (misma CLABE asociada o con el mismo faltante en otro periodo): sólo sugieren. Ver `docs/BENEFICIARIES.md` §4b.
- **FASE 6:** el nombre del beneficiario sigue sin compararse **nunca contra empleados**. Sí se compara contra **beneficiarios ya conocidos** (aprendidos por evidencia fuerte) para producir una sugerencia de confianza BAJA; ver `docs/BENEFICIARIES.md`.
- Una decisión manual «no identificado» impide el respaldo por nombre. Ver `docs/CONCILIATION_ENGINE.md` §5.

## Identidad canónica y rotación HMAC (FASE 7)

- **Alias:** un empleado fusionado sigue identificando por sus identificadores. Toda comparación se hace sobre su **canónico**:
  - número de A + RFC de B, ya fusionados → ASOCIADO, no CONFLICTO;
  - RFC/CURP discrepantes se evalúan contra el grupo completo.
- **Qué guarda el registro:** el empleado cuyo identificador coincidió, más `evidencia.canonico`. Las sugerencias por nombre sólo proponen canónicos. Ver `docs/IDENTITY_MERGE.md`.
- **Llave de la huella:** la huella de una cuenta usa la llave HMAC **activa** de la BD y declara su `key_id`. Las cuentas confirmadas siguen identificando tras una rotación porque todas las huellas cambian en un solo corte (`docs/HMAC_ROTATION.md`).

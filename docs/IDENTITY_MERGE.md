# Fusión de identidades y posibles duplicados (FASE 7)

## 1. Modelo: empleado canónico por alias

Cuando dos empleados resultan ser la misma persona (p. ej. un número corregido entre cargas), se **fusionan**. La fusión:

- **No mueve ni borra nada.** El empleado origen queda **FUSIONADO**: `empleados.fusionado_en_id` apunta al canónico y `fusion_id` a la decisión. Sus registros RRHH y de Tesorería, cuentas, pensiones, relaciones de beneficiario, incidencias e historial siguen apuntando a él.
- **Se resuelve al leer.** Las lecturas actuales usan `coalesce(fusionado_en_id, id)`:
  - el matching del worker;
  - la conciliación;
  - las sugerencias de pensión;
  - la ficha del empleado, con registros, pagos y cuentas de sus alias;
  - la asociación manual.

  La resolución es siempre de **un salto**: si el canónico se fusiona después en un tercero, quienes apuntaban a él se re-apuntan y quedan registrados en `fusiones_empleados.reapuntados`.
- **No reescribe la historia.** Las conciliaciones ya ejecutadas guardaron sus propios resultados, que son inmutables. La siguiente ejecución de cada lote usa la identidad canónica, cambia su huella de entradas y el comparativo v1/v2 lo explica («se fusionó en…», «recibe los registros de…»).

**Por qué alias y no mover registros:**
- mover reescribiría evidencia (a quién se asoció cada registro y por qué);
- tocaría tablas con triggers de inmutabilidad;
- haría imposible una separación exacta.

Con alias, una separación restaura exactamente el estado anterior.

**Matching con alias.** El número, RFC, CURP o cuenta del origen siguen identificando a la persona. El registro guarda el empleado cuyo identificador coincidió, y la evidencia agrega `canonico`. El número de A y el RFC de B, ya fusionados, **no** son una contradicción. Las sugerencias por nombre sólo proponen canónicos. La web no asocia registros nuevos a un empleado fusionado: un trigger lo impide y la interfaz ofrece el canónico. Buscar por un número histórico resuelve al canónico.

## 2. Análisis de un par (una sola regla)

`fn_fusion_analisis(origen, destino)` es la **misma** regla para la previsualización, la fusión y el detector. Devuelve evidencias, conflictos, una comparación de campos (IGUAL/DIFERENTE/FALTA, **sin valores**) y conteos de lo que quedaría bajo la identidad canónica.

| Evidencia | Fuerza |
|---|---|
| Mismo RFC válido · misma CURP válida · mismo ISSEMYM · una cuenta registrada para ambos | FUERTE |
| Pagos de nómina asociados a ambos con la misma cuenta · un registro que trajo identificadores de ambos (número histórico) | MEDIA |
| Mismo nombre normalizado · nombre muy parecido (sólo detector) | DÉBIL |

| Conflicto | Severidad |
|---|---|
| Mismo empleado · alguno ya fusionado | BLOQUEO |
| RFC, CURP o ISSEMYM válidos distintos · ambos con nómina en la **misma carga** (lote) · RFC, CURP o ISSEMYM de alguno **editado o borrado a mano** (`IDENTIFICADOR_EDITADO`: el conflicto podría estar oculto) | GRAVE: se acepta expresamente, con permiso y motivo |
| Mismo periodo en cargas distintas · pagos de nómina en el mismo lote · dos cuentas de nómina activas · grupo distinto · nombre distinto | ADVERTENCIA |

**Confianza:**
- ALTA: alguna evidencia fuerte.
- MEDIA: alguna evidencia media.
- BAJA: sólo nombre.

**Sólo ALTA o MEDIA son fusionables. El nombre por sí solo nunca fusiona**, tampoco por una decisión humana: la BD lo rechaza con `EVIDENCIA_INSUFICIENTE`. No hay puntajes numéricos ni aprendizaje automático: las categorías son explicables y se muestran con sus razones.

## 3. Detector de posibles duplicados

`app/Engine/Identity/Duplicates.php` (trabajo `app/Engine/Jobs/DetectDuplicatesJob.php`) se ejecuta después de cada normalización RRHH y a demanda con el trabajo `DETECTAR_DUPLICADOS` (botón «Detectar ahora»).

- **Candidatos:** entre empleados canónicos, pares con RFC, CURP, ISSEMYM o nombre igual, cuenta compartida, pagos a la misma cuenta, incidencias con ambos o un nombre muy parecido (similitud ≥ 0.92 con `SequenceMatcher`, equivalente a difflib, dentro del mismo primer y último apellido).
- **Evaluación:** cada par se evalúa con `fn_fusion_analisis` y se guarda en `duplicados_empleados`.
- **Estados:**
  - `PENDIENTE`;
  - `DESCARTADO`, por una persona con motivo; sólo reaparece si la confianza **sube**;
  - `FUSIONADO`;
  - `OBSOLETO`, si ya no se detecta. Nunca se borra.

## 4. Fusión (uno a uno)

1. **Previsualización** (`GET /empleados/fusion?origen=&destino=`, `empleados.fusionar`). Muestra:
   - números, nombre, RFC/CURP/ISSEMYM (enmascarados sin `empleados.ver_sensible`) y grupo;
   - periodos y cuentas;
   - registros RRHH y de Tesorería, pensiones, beneficiarios e incidencias;
   - conciliaciones históricas (no cambian) y lotes afectables en ejecuciones futuras;
   - evidencias y conflictos.

   Permite invertir la dirección. Nada cambia.
2. **Confirmación** (`POST /empleados/fusion`, modal accesible). Requiere:
   - un motivo de al menos 20 caracteres;
   - una casilla de revisión;
   - aceptar cada conflicto grave (`empleados.fusionar_conflictos`);
   - la **huella** SHA-256 del análisis previsualizado.
3. **En la BD** (`fn_fusionar_empleados`, SECURITY DEFINER):
   - verifica el actor de la sesión y los permisos;
   - toma el candado global del worker y bloquea ambas filas en orden;
   - recalcula el análisis y exige la misma huella; si algo cambió, `REGISTRO_MODIFICADO`;
   - rechaza BLOQUEO, evidencia insuficiente y conflictos graves no aceptados;
   - re-apunta a los alias del origen;
   - marca el origen, escribe `empleado_historial` (fuente FUSION) y marca el par de duplicados;
   - PHP audita `empleados.fusionar` con autor, motivo, evidencias, conflictos aceptados, comparación, conteos, huella y versión.

**No hay fusión masiva.** Cada fusión es una decisión individual.

**Punteros consistentes.** Tras cada fusión o separación, `fn_fusion_recalcular` apunta cada alias a la **raíz de su propia cadena** de fusiones aplicadas: siempre un salto. Así, una separación en medio de una cadena nunca deja un alias con quien no le corresponde.

**Cuentas de un alias.** La cuenta registrada para un alias es de la **misma persona**. Hay tres consecuencias:
- el worker no la trata como conflicto cuando llega con el número del canónico;
- confirmarla desde un registro del canónico no es «cuenta de otra persona» (`fn_empleado_canonico` en `fn_confirmar_cuenta` y `fn_confirmar_beneficiario`);
- la ficha del canónico revela y desactiva las cuentas de sus alias.

**Sugerencias anteriores a una fusión:** si nombran al alias, se evalúan sobre el canónico (revisión masiva y asociación manual).

**Concurrencia:** dos fusiones simultáneas (incluso en sentidos opuestos) se serializan por el candado. La segunda encuentra otra huella y no aplica nada.

## 5. Corrección: separación (no «deshacer»)

Una fusión equivocada se corrige con `fn_separar_fusion`: ADMIN o GOD (`empleados.separar`; desde la migración 0026 ya no es exclusivo de GOD), con un motivo de 20 caracteres o más. CONSULTA no separa.
- La fusión queda `SEPARADA`: no se borra; conserva autor, fecha y motivo.
- El origen vuelve a ser canónico.
- Quienes se habían fusionado en él y siguen fusionados por su propia fusión vuelven a él.
- El par de duplicados vuelve a `PENDIENTE`.
- Todo queda en el historial y la auditoría (`empleados.separar`).

Como nada se movió, la separación es exacta. La siguiente conciliación del lote refleja la identidad vigente.

## 6. Permisos

Desde la migración 0026 sólo existen tres roles: GOD, ADMIN y CONSULTA.

| Permiso | GOD | ADMIN | CONSULTA |
|---|---|---|---|
| `empleados.duplicados` (ver y descartar pares) | ✔ | ✔ | — |
| `empleados.fusionar` | ✔ | ✔ | — |
| `empleados.fusionar_conflictos` (solicitar una fusión con conflictos graves; ver `CRITICAL_MERGE_APPROVAL.md`) | ✔ | ✔ | — |
| `empleados.fusionar_conflictos_aprobar` (aprobar la solicitud de **otra** persona) | ✔ | ✔ | — |
| `empleados.separar` | ✔ | ✔ | — |

## 7. Rutas

`/empleados/duplicados` · `POST /empleados/duplicados/detectar` · `POST /empleados/duplicados/{id}/descartar` · `GET/POST /empleados/fusion` · `/empleados/fusiones` · `/empleados/fusiones/{id}` · `POST /empleados/fusiones/{id}/separar`.

## 8. Pruebas

- Procesador (`tests/Engine/Phase7Test.php`):
  - duplicado fuerte, medio y sólo nombre;
  - descartar y reabrir;
  - fusión con cuentas, RRHH, Tesorería y pensiones sin mover nada;
  - conciliación histórica intacta y nueva canónica;
  - matching por alias;
  - conflictos grave y no aceptado;
  - huella caducada;
  - fusiones concurrentes en sentidos opuestos;
  - cadena de fusiones;
  - separación sólo por GOD.
- PHP (`MergeHttpTest`):
  - permisos por rol y CSRF;
  - asignación masiva y XSS en el motivo;
  - IDOR;
  - separación por HTTP;
  - SQL injection en las rutas nuevas.

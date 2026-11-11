# Rotación de la llave HMAC de cuentas/CLABE (FASE 7)

La cuenta o CLABE completa se protege con dos mecanismos independientes:

| Llave | Función | Rotación |
|---|---|---|
| `ACCOUNT_ENC_KEYS` (AES-256-GCM) | **Confidencialidad**: recuperar el número sólo al revelarlo con permiso | `bin/rotate-account-key.php` (FASE 6, `KEY_ROTATION.md`) |
| `ACCOUNT_HMAC_KEYS` (HMAC-SHA256) | **Búsqueda exacta**: `HMAC(llave, "cuenta:" ‖ dígitos)` para comparar sin descifrar | `bin/rotate-account-hmac.php` (este documento) |

## 1. Por qué la rotación HMAC es distinta

La huella HMAC es una **clave de búsqueda** que se compara por igualdad entre tablas:
- el matching del worker (cuenta confirmada → empleado);
- el aprendizaje de CLABE (registro → `cuentas_bancarias`);
- el índice único «una cuenta activa por huella y uso»;
- la agrupación opaca de la revisión masiva;
- la detección de pagos duplicados de la conciliación.

Si unas filas tuvieran la huella de la llave nueva y otras la de la anterior, dejarían de coincidir y el sistema «olvidaría» cuentas ya confirmadas. Por eso **no se rota fila por fila sobre la columna viva**.

## 2. Diseño: sombra + corte atómico

**Llave activa en la BD.** La tabla `hmac_llaves_estado` (una fila) guarda el `key_id` activo. Los procesos sólo tienen el **anillo** de secretos (`ACCOUNT_HMAC_KEYS="h1:secreto,h2:secreto"`); qué llave usar lo decide la BD. Así web, worker y CLI nunca quedan desincronizados, y no hace falta reiniciar nada en el momento del corte.

**Cada huella declara su llave.** Las columnas nuevas son `cuenta_hmac_key_id` en `staging_registros` y `registros_tesoreria` y `hmac_key_id` en `cuentas_bancarias`. Un trigger (`fn_hmac_huella_reglas`) exige que toda huella nueva o modificada use la llave **activa**. Un trabajo que calculó con la llave anterior y escribe después del corte falla con `40001` y se **reintenta**: nunca mezcla llaves. `cuentas_bancarias` copia la llave y la sombra de su registro de origen (`fn_cuenta_hmac_origen`); nunca las acepta de quien inserta.

**Fase 1 · Preparación.** Es reanudable, en lotes transaccionales con `FOR UPDATE SKIP LOCKED`. Para cada fila:
1. descifra la cuenta;
2. **verifica** que la huella viva corresponde a ese valor con la llave anterior (detecta datos inconsistentes);
3. escribe la huella con la llave nueva en la columna **sombra** `cuenta_hmac_nueva`.

La columna viva no cambia: el matching es idéntico durante toda la preparación.

**Fase 2 · Corte.** Es una sola transacción:
1. toma el candado consultivo global del worker (el mismo de la normalización, la revisión masiva y la fusión);
2. bloquea las tres tablas **para escritura** con `SHARE ROW EXCLUSIVE`; las lecturas siguen;
3. calcula la sombra de lo que llegó durante la preparación;
4. exige que la correspondencia anterior→nueva sea **biyectiva** en las tres tablas juntas;
5. cambia la llave activa y copia la sombra a la columna viva;
6. verifica que no quede ninguna huella con otra llave.

Antes del commit todos ven las huellas anteriores; después, todas las nuevas. **Nunca hay una ventana con mezcla.**

**Índices.** El índice único vivo `(cuenta_hmac, uso) WHERE ACTIVA` **no se elimina ni se recrea**. La sombra tiene su propio índice único `cuentas_bancarias_activa_nueva_uk`: si dos cuentas activas distintas produjeran la misma huella nueva (colisión o dato inconsistente), la preparación falla en ese momento en lugar de fusionarlas en silencio.

## 3. Colisiones e inconsistencias

Una colisión criptográfica de HMAC-SHA256 es prácticamente imposible, pero un dato inconsistente no. Nada continúa en silencio:

| Situación | Detección | Resultado |
|---|---|---|
| La huella viva no corresponde al valor cifrado | Verificación por fila (preparación y dry-run) | Error `HUELLA_NO_CORRESPONDE_AL_VALOR`; no se escribe el lote |
| Dos huellas anteriores → una nueva, o una → dos | Chequeo biyectivo antes del corte (y en el dry-run) | El corte se niega; nada cambia |
| Dos cuentas activas con la misma huella nueva | Índice único de la sombra | La preparación falla en esa fila |
| Llave de cifrado faltante o valor no descifrable | Descifrado | Error por fila (tabla e id, nunca valores) |
| Huella sin valor cifrado (datos anteriores a la FASE 4) | Se toma la sombra de otra fila con la **misma** huella anterior | Si no existe, el corte se niega salvo `--descartar-sin-cifrado`, que quita esa huella y queda auditado |

Desde la FASE 7, el worker sólo calcula una huella cuando también cifra la cuenta (6 a 20 dígitos). Así, toda huella nueva puede recalcularse.

## 4. Uso

```bash
# 0. Respaldo verificado.
# 1. Agregar la llave nueva al anillo SIN quitar la activa (worker, CLI y, si se usa, web):
ACCOUNT_HMAC_KEYS="h1:<secreto actual>,h2:<secreto nuevo>"     # openssl rand -hex 32
#    reiniciar el worker: su registro en `workers` declara los ids del anillo (nunca los secretos).
php bin/rotate-account-hmac.php --status
php bin/rotate-account-hmac.php --dry-run --to=h2                # sin escribir: totales, llaves, errores, colisiones
php bin/rotate-account-hmac.php --to=h2 --confirm-backup [--batch-size=500] [--max-batches=N] [--no-cutover]
php bin/rotate-account-hmac.php --resume                          # continúa una preparación interrumpida y hace el corte
php bin/rotate-account-hmac.php --cancel                          # borra la sombra; la llave activa nunca cambió
# 2. Retirar h1 del anillo sólo cuando --status la marque como retirable.
```

- **Credenciales:** del rol propietario (`DB_MIGRATE_USERNAME/PASSWORD`), sólo al ejecutar el comando.
- **Una sola CLI a la vez:** candado consultivo de sesión, además de un índice único que admite una sola rotación `PREPARANDO`.
- **Workers:** el corte se niega si un worker vivo no declara la llave destino. Así se evita que, tras el corte, un worker sin la llave falle todos sus trabajos.
- **Valor de verificación (KCV):** `HMAC(secreto, "conciliarh:kcv:v1")` por llave.
  - Evita que un anillo mal configurado (el mismo id con otro secreto) calcule huellas que nunca coinciden.
  - El KCV de la llave activa se registra en su primer uso y el de la destino al iniciar la rotación.
  - El worker y la CLI lo verifican antes de escribir; si no coincide, el trabajo se reintenta (`LLAVE_HMAC_INCORRECTA`) y la CLI no modifica nada.
- **Secretos:** 64 hexadecimales (`openssl rand -hex 32`).
- **Corte con `lock_timeout` de 30 s:** si una transacción larga retiene los candados, el corte falla y se reintenta en vez de bloquear la normalización.
- **Huellas irrecuperables:** si al terminar la preparación sólo quedan huellas sin valor cifrado ni correspondencia, la CLI pasa al corte. El corte se niega salvo con `--descartar-sin-cifrado`.
- **Una fila inconsistente detiene la rotación.** Es deliberado: nunca se omite en silencio. Se corrige el dato (o se reanaliza el archivo) o se cancela.
- **Salida:** sólo tablas, `key_id`, conteos e ids de fila. Nunca llaves, huellas ni valores. Un error de PostgreSQL se reduce a su SQLSTATE.
- **Auditoría** (origen CLI, sólo conteos): `cuentas.rotacion_hmac_simulada`, `_iniciada`, `_parcial`, `_completada`, `_cancelada`, `_rechazada` y `_error`.
- **Primitivas:** `hash_hmac('sha256')` en PHP (aplicación web, CLI y procesador en segundo plano, `app/Engine/Identity/AccountCrypto.php`) y `hash_equals` al verificar. No se inventan primitivas.
- **Web:** no existe ninguna ruta; la web no lee huellas ni `key_id`.

## 5. Compatibilidad

Instalaciones existentes: la llave única `ACCOUNT_HMAC_KEY` es la **«h1»** del anillo. La migración 0016 marca todas las huellas existentes con `h1`. Sin `ACCOUNT_HMAC_KEYS`, el anillo es `{h1: ACCOUNT_HMAC_KEY}`; nada cambia hasta que se inicia una rotación.

## 6. Otros usos de la llave

| Uso | Antes | FASE 7 |
|---|---|---|
| Huella de cuentas | `ACCOUNT_HMAC_KEY` | Anillo; se rota con este procedimiento |
| `hash_registro` / `clave_hash` del staging (llave derivada `HMAC(llave, "conciliarh:staging:v1")`) | La misma llave | Llave derivada de la llave **activa**; cada análisis guarda `hash_key_id` |
| HMAC del usuario en el bloqueo de login | `ACCOUNT_HMAC_KEY` | Llave propia derivada de `APP_KEY` (`HMAC(APP_KEY, "conciliarh:login-throttle:v1")`). La web ya no necesita la llave de cuentas |

**Huellas de registro.** Sirven para comparar dos análisis del mismo archivo y para trasladar las decisiones manuales al volver a normalizar. No se recalculan en la rotación: harían falta los libros originales.
- **Traslado de decisiones:** si la importación anterior y el nuevo análisis usan llaves distintas, la decisión se traslada por **posición** (hoja y fila), exigiendo el mismo número de empleado y el mismo nombre normalizado, y en Tesorería el mismo importe y los mismos últimos 4. Sin número ni nombre, no se traslada. Nunca se pierde en silencio, y hay una prueba de ello.
- **Comparación de análisis y detección de «posible versión»:** con llaves distintas los análisis se marcan **no comparables**, con una indicación para reanalizar el archivo anterior, en lugar de mostrar «0 registros en común».
- **Llaves retirables:** `--status` las calcula con las huellas de cuentas. Retirar una llave que todavía usan análisis anteriores sólo hace que esas comparaciones sean «no comparables».

**Consecuencia del cambio de llave del bloqueo de login.** Al actualizar, los contadores de intentos de la ventana en curso (15 min) empiezan de cero una vez. Es un riesgo bajo y aceptado (SECURITY §4g).

## 7. Pruebas

- PHP (`tests/Integration/HmacRotationTest.php`):
  - dry-run sin escritura;
  - preparación interrumpida y reanudada;
  - llaves vieja y nueva conviviendo;
  - partición del matching idéntica antes, durante y después;
  - escritura con la llave retirada rechazada (`40001`);
  - dato inconsistente y colisión forzada, que hacen que el corte se niegue sin escribir;
  - huella sin cifrado que exige una decisión explícita;
  - dos rotaciones simultáneas;
  - la web no toca llaves ni huellas.
- Procesador en segundo plano (`tests/Engine/Phase7Test.php`, suite `Engine` de PHPUnit):
  - el worker usa la llave activa de la BD y reintenta si no la tiene;
  - una decisión manual sobrevive a un reanálisis tras el cambio de llave.

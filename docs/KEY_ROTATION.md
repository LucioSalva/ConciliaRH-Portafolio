# Rotación de la llave de cifrado de cuentas/CLABE (FASE 6)

Las cuentas y CLABE completas se guardan cifradas con AES-256-GCM:
- **Formato:** `0x01 ‖ nonce(12) ‖ ciphertext ‖ tag(16)`.
- **AAD:** `conciliarh:cuenta:v1:<key_id>`.
- **Llaves:** vienen del anillo `ACCOUNT_ENC_KEYS="id:64hex[,id:64hex]"`. La llave activa para cifrar lo nuevo es `ACCOUNT_ENC_KEY_ID`.

Cada fila guarda su `key_id`, así que conviven valores cifrados con llaves distintas mientras dura una rotación.

**La rotación sólo existe por CLI** (`bin/rotate-account-key.php`):
- No hay ruta web.
- El rol web no puede escribir el cifrado.
- El comando usa las credenciales del **rol propietario**, que sólo se entregan al ejecutarlo.

## Qué tablas se re-cifran

| Tabla | Columna cifrada | Columna key_id |
|---|---|---|
| `cuentas_bancarias` | `cuenta_cifrada` | `key_id` |
| `registros_tesoreria` | `cuenta_cifrada` | `cuenta_key_id` |
| `staging_registros` | `cuenta_cifrada` | `cuenta_key_id` |

## Procedimiento

1. **Respaldo.** Respalde la base de datos y verifique que se restaura. El comando no respalda; exige `--confirm-backup` para ejecutar.
2. **Agregar la llave nueva al anillo sin quitar la anterior**, en el `.env` del servidor web, del worker y de la CLI:
   `ACCOUNT_ENC_KEYS="k1:<hex anterior>,k2:<hex nuevo>"` y `ACCOUNT_ENC_KEY_ID=k2`.
   - Genere la llave con `openssl rand -hex 32`.
   - Desde ese momento, lo nuevo se cifra con `k2` y lo anterior sigue legible con `k1`.
3. **Reiniciar** el worker y PHP-FPM para que tomen el anillo.
4. **Simular:** `php bin/rotate-account-key.php --dry-run`.
   - Descifra **todas** las filas pendientes con su llave y verifica los últimos 4 dígitos.
   - Informa filas por tabla y `key_id`, cuántas se actualizarían y los errores (tabla e id de fila, nunca valores).
   - Termina con código 2 si hay errores.
5. **Rotar:** `php bin/rotate-account-key.php --confirm-backup [--batch=500]`.
   - Repite la simulación y **no escribe nada** si hay una sola fila no descifrable (llave faltante o incorrecta).
   - Recorre cada tabla por lotes. **Cada lote es una transacción** (`FOR UPDATE SKIP LOCKED`), que hace lo siguiente con cada fila:
     1. descifra con la llave anterior;
     2. cifra con la nueva (nonce aleatorio y AAD con el nuevo `key_id`);
     3. **verifica** descifrando el resultado y comparándolo con los últimos 4 dígitos;
     4. sólo entonces escribe, con la guarda `WHERE key_id = <anterior>`.
   - Una caída revierte únicamente el lote en curso. Todo lo confirmado queda consistente: cifrado y `key_id` cambian juntos en la misma sentencia.
6. **Reanudar** si se interrumpió: ejecute el mismo comando. Lo ya rotado tiene el `key_id` destino y no se vuelve a tocar (idempotente). La opción `--max-batches=N` permite rotar por tramos.
7. **Verificar:** `php bin/rotate-account-key.php --status`. Muestra filas por tabla y `key_id` y las **llaves retirables**, es decir, las que ya no cifran ninguna fila.
8. **Retirar la llave anterior** del anillo sólo cuando `--status` la marque como retirable. Después, reinicie los servicios.

## Seguridad del comando

- **Nunca** imprime, registra ni audita llaves ni valores en claro. Sólo muestra tablas, `key_id`, conteos e ids de fila.
- Un error de PostgreSQL se informa sólo con su SQLSTATE, porque el `DETAIL` de una violación puede traer la fila.
- **Auditoría** (origen CLI, sólo conteos): `cuentas.rotacion_simulada`, `_iniciada`, `_completada`, `_parcial`, `_rechazada` y `_error`.
- El trigger `fn_cuenta_inmutable` permite cambiar sólo `cuenta_cifrada` y `key_id`: la identidad de la cuenta (HMAC, dueño, uso, origen) sigue inmutable.

## HMAC: ¿hay que rotarlo también?

**No.** Son llaves con funciones distintas y la rotación del cifrado no toca el HMAC:
- **`ACCOUNT_ENC_KEYS`** protege la **confidencialidad**: permite recuperar el número completo, sólo al revelarlo con permiso.
- **`ACCOUNT_HMAC_KEY`** permite **comparar** cuentas sin descifrar: `HMAC-SHA256("cuenta:" ‖ número)`.
  - Lo usan los índices únicos de `cuentas_bancarias`, el matching del worker y la agrupación opaca de la revisión masiva.

Cambiar la llave de cifrado deja intactos los HMAC, así que la búsqueda exacta y el aprendizaje de CLABE siguen funcionando durante y después de la rotación; una prueba lo verifica.

Rotar la llave HMAC es otra operación, más costosa, y **no está implementada**:
- Exige recalcular el HMAC de cada cuenta, lo cual obliga a descifrarlas todas.
- Esas filas están en `cuentas_bancarias`, `registros_tesoreria` y `staging_registros`, con índices únicos que cambian de valor.
- Hay que hacerlo con el worker detenido y con una ventana de mantenimiento.
- Sólo se justifica si la llave HMAC se compromete: sin la llave de cifrado, el HMAC por sí solo no revela números, pero permite probar si una cuenta candidata existe.

Queda como pendiente documentado en `SECURITY.md` §6.

## Pruebas

`tests/Integration/KeyRotationTest.php` cubre:
- simulación sin escritura;
- rotación real con verificación del valor;
- nonce nuevo;
- HMAC intacto;
- interrupción tras un lote y reanudación;
- idempotencia;
- llave incorrecta (se detiene sin escribir);
- destino inexistente;
- ausencia de ruta web.

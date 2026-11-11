# Recuperación de emergencia de la cuenta GOD

ConciliaRH tiene **una sola** cuenta GOD. Si esa persona pierde la contraseña, queda bloqueada, se desactiva la cuenta por error o hay que cortar sus sesiones de inmediato, nadie dentro de la aplicación puede arreglarlo: ADMIN no administra a GOD. Este documento describe el único camino para recuperarla.

**Qué es:** un comando que se ejecuta en la terminal del servidor, con privilegios de administrador del sistema operativo, y que deja constancia en la auditoría.

**Qué no es:** no hay contraseña maestra, usuario oculto, ruta web, cookie especial ni acceso remoto. El comando no abre sesión, no crea usuarios, no asigna el rol GOD a otra cuenta y no puede tocar a ningún usuario que no sea la cuenta GOD.

| § | Contenido |
|---|---|
| 1 | Quién puede ejecutarlo |
| 2 | Operaciones |
| 3 | Procedimiento |
| 4 | Qué queda registrado |
| 5 | Por qué no es una puerta trasera |
| 6 | Límites y casos que no cubre |
| 7 | Pruebas |

---

## 1. Quién puede ejecutarlo

Sólo quien ya administra el servidor: hay que ser root (o tener `sudo`) **en la máquina**.

- El comando es `sudo conciliarh-run god-recovery …`. `conciliarh-run` exige root y ejecuta el programa como `conciliarh-admin`, el único usuario que recibe las credenciales del rol propietario de la base de datos (`/etc/conciliarh/cli.env`, legible sólo por root).
- Los usuarios de servicio (`conciliarh-web`, `conciliarh-worker`, `conciliarh-sched`, `conciliarh-backup`) no pueden: no son root y no leen `cli.env`. Sin esas credenciales el programa se detiene.
- La aplicación web no puede: `bin/` está fuera del directorio servido, PHP-FPM tiene deshabilitada la ejecución de procesos y la base de datos rechaza que el rol web cambie las credenciales de una cuenta GOD ajena.

Quien tiene root en el servidor ya puede leer la base de datos y las llaves; este comando no le da nada nuevo. Lo que añade es **orden y registro**: una sola forma de hacerlo, que valida, confirma y audita.

## 2. Operaciones

| Operación | Qué hace | Cambia algo |
|---|---|---|
| `status` | Estado de la cuenta GOD: activa, baja, bloqueo por intentos (desde cualquier origen), sesiones abiertas, último acceso e invariantes | No |
| `unlock` | Borra los intentos fallidos recientes de la cuenta GOD. El bloqueo por dirección IP de origen no se toca | Sí |
| `reactivate` | Reactiva la cuenta GOD (activa y sin baja). Se niega si ya hay otra cuenta GOD activa | Sí |
| `revoke-sessions` | Cierra todas las sesiones abiertas de la cuenta GOD | Sí |
| `reset-password` | Contraseña nueva. Obliga a cambiarla en el siguiente acceso, cierra las sesiones y limpia los intentos fallidos | Sí |
| `repair` | Si **ninguna** cuenta tiene el rol GOD, lo devuelve a la cuenta que la auditoría registra como creada por `create-god`, le quita cualquier otro rol, cierra sus sesiones y deja su contraseña **inutilizada** (después hay que ejecutar `reset-password`). Si los invariantes están bien, no cambia nada | Sólo si hace falta |

Todas las que cambian algo exigen `--motivo="…"` (de 10 a 500 caracteres). El motivo se escribe en la línea de comandos y queda en el registro del sistema: no ponga en él datos sensibles.

**Por qué `repair` inutiliza la contraseña:** mientras una cuenta está sin el rol GOD no tiene su protección, y alguien con permiso de editar usuarios pudo cambiarle la contraseña o darle otros roles. Devolverle el rol sin más convertiría a esa persona en GOD.

**Sobre qué cuenta opera:** la cuenta GOD activa. Si no hay ninguna activa, la única que tenga el rol. Si hay varias con el rol y ninguna activa, hay que indicarla con `--usuario=<usuario>`, y debe ser una de ellas. Con más de una GOD activa el comando no elige: informa y se detiene.

## 3. Procedimiento

1. Dos personas presentes: quien administra el servidor y quien responde por la cuenta GOD (o quien la autoridad designe). Anote fecha, hora y motivo en la bitácora de operación.
2. Revise el estado:
   ```
   # conciliarh-run god-recovery status
   ```
3. Ejecute **sólo** la operación necesaria. Ejemplo, contraseña olvidada:
   ```
   # conciliarh-run god-recovery reset-password --motivo="Contraseña olvidada; solicitud de la Tesorería del 2026-11-03"
   ```
   - El comando muestra la operación, la cuenta y quién ejecuta.
   - Pide escribir la frase `RECUPERAR GOD <usuario>` exactamente. Cualquier otra respuesta cancela sin cambios. La operación se hace sobre **esa** cuenta: si entre la confirmación y el cambio la cuenta GOD resultara ser otra, no se hace nada.
   - Pide la contraseña nueva **dos veces y sin eco**. La escribe la persona responsable de la cuenta, no el administrador. Nunca se pasa como argumento: `conciliarh-run` rechaza cualquier argumento que no conozca antes de ejecutar nada, y si la terminal no permite ocultar la escritura, no la pide.
4. La persona responsable entra a la aplicación; el sistema le obliga a cambiar la contraseña.
5. Revise la auditoría (acciones `emergencia.god_*`) y archive la constancia.

En producción el comando sólo funciona en una terminal: no acepta la contraseña por tubería ni confirmaciones automáticas.

**Códigos de salida:** 0 hecho; 1 rechazado (uso, validación, confirmación, o `status` con problemas); 2 falla de la base de datos o de configuración. Ante un 1 o un 2 no se hizo ningún cambio: cada operación es una sola transacción.

## 4. Qué queda registrado

Cada operación que cambia algo escribe en la auditoría, dentro de la misma transacción:

| Campo | Valor |
|---|---|
| Acción | `emergencia.god_unlock`, `emergencia.god_reactivate`, `emergencia.god_revoke_sessions`, `emergencia.god_reset_password`, `emergencia.god_repair` |
| Usuario | `SYSTEM_BREAK_GLASS` (sin usuario de la aplicación) |
| Origen | `CLI` |
| Entidad | La cuenta GOD |
| Contexto | `tipo: SYSTEM_BREAK_GLASS`, operación, motivo, usuario del sistema operativo (y el operador que invocó `sudo`), nombre del servidor |
| Resultado | Lo que cambió: sesiones revocadas, intentos eliminados, estado anterior |

Además, la base de datos registra por su cuenta `usuarios.cambio_credenciales` con la conexión que hizo el cambio.

Nunca se registran la contraseña ni su hash. El operador que invocó `sudo` lo informa `conciliarh-run`; es un dato declarativo, útil para la bitácora, no una prueba de identidad.

Los registros entran en la cadena de hash como cualquier otro: no se pueden borrar ni modificar sin que la verificación lo detecte.

## 5. Por qué no es una puerta trasera

- **No autentica.** No produce una sesión ni un acceso: sólo modifica el estado de la cuenta GOD. Para entrar hay que pasar por el login normal.
- **No hay secreto compartido.** No existe una contraseña, llave o token que alguien fuera del servidor pueda usar.
- **No es remoto.** No escucha en la red, no hay ruta web y no hace llamadas externas.
- **No es silencioso.** No hay forma de usarlo sin dejar el registro: la auditoría va en la misma transacción que el cambio.
- **No amplía privilegios.** Requiere root en el servidor, que ya implica control total. No da a nadie algo que no tuviera.
- **No crea ni promueve.** No da de alta usuarios y no asigna el rol GOD a una cuenta que no lo tuviera (salvo `repair`, que sólo lo devuelve a la cuenta original registrada en la auditoría).
- **Está a la vista.** El código es `bin/god-recovery.php` y `app/Domain/Users/GodRecovery.php`, viaja en el paquete y aparece en la lista de comandos de `conciliarh-run`.

## 6. Límites y casos que no cubre

- **Más de una cuenta GOD activa:** el comando no decide cuál conservar. Es una decisión de la autoridad; se resuelve con el DBA y queda en la bitácora.
- **Bloqueo por dirección IP:** `unlock` no lo quita (protege contra ataques desde esa dirección). Caduca solo al terminar la ventana configurada.
- **Cuentas de desarrollo con el rol GOD:** no deben existir fuera de desarrollo. `status` las reporta; `reactivate` y `repair` se niegan mientras haya una activa. El comando nunca opera sobre ellas.
- **Cuenta GOD que nunca existió:** se usa `conciliarh-run create-god` (docs/DEPLOYMENT.md §11).
- **Cambiar de titular:** la persona responsable cambia; la cuenta es la misma. Se restablece la contraseña con la nueva persona presente y ella actualiza su nombre en su perfil.
- **Base de datos caída:** el comando no hace nada (código 2). Primero se recupera la base.

## 7. Pruebas

| Qué | Dónde |
|---|---|
| Sólo la cuenta GOD, sin segundo GOD, auditoría sin secretos, validaciones | `tests/Integration/GodRecoveryTest.php` |
| Argumentos, falta de credenciales, producción sólo interactiva, falla de BD, `stty` por ruta absoluta, sin llamadas de red | `tests/Http/BreakGlassCliTest.php` |
| En el servidor de prueba: usuarios de servicio, invocación web, abuso de `sudo`, inyección, PATH y entorno manipulados, terminal real, concurrencia, BD detenida, contraseña fuera de procesos y de logs | `tests/e2e/break_glass_rc.php` |

# Seguridad — ConciliaRH

- **Alcance:** FASE 1 (base, autenticación, RBAC, auditoría, cola de trabajos), FASE 2 (periodos, lotes, carga y almacenamiento de archivos) y FASE 3 (motor de análisis de archivos).
- **Revisión:** 2026-09-29. Se hicieron dos revisiones independientes, una de código y otra de seguridad (OWASP Top 10). Las correcciones se validaron con pruebas automatizadas.

## 1. Modelo de amenazas (resumen)

| Activo | Amenaza principal | Controles |
|---|---|---|
| Datos de nómina y cuentas (fases 3+) | Filtración, alteración | Mínimo privilegio en BD, sin datos reales en Git, cuentas con HMAC, últimos 4 dígitos o cifrado, auditoría |
| Cuenta GOD | Toma de control, escalada desde ADMIN | Alta sólo por CLI con rol propietario, trigger en BD, roles protegidos, permisos `solo_god` |
| Bitácora de auditoría | Borrado, edición | Append-only (privilegios + triggers incluso para el propietario), cadena SHA-256 verificable |
| Sesiones | Secuestro, fijación | ID con hash en BD, `use_strict_mode`, regeneración, revocación, expiración, cookie `HttpOnly`, `SameSite`, `Secure` y prefijo `__Host-` bajo HTTPS |
| Archivos cargados (FASE 2) | Ejecución de código, *path traversal*, bomba ZIP, XXE, suplantación de tipo, alteración del original, descarga no autorizada | §2b |
| Cuentas de desarrollo | Acceso a producción con credenciales de prueba | `usuarios.es_desarrollo` + rechazo en `AuthService` si `APP_ENV=production`; `seed-dev`/`reset-dev` exigen `APP_ENV=development` |
| Procesador en segundo plano (worker PHP, `bin/worker.php`) | Inyección de comandos, doble procesamiento | Ni la web ni el procesador ejecutan shell; contrato validado en ambos lados; `SKIP LOCKED` + lease con *fencing* |

## 2. Controles implementados (OWASP Top 10 2021)

| Categoría | Controles |
|---|---|
| **A01 Control de acceso** | <ul><li>`perm:*` en cada ruta; los servicios revalidan la autorización.</li><li>Roles `protegido` sólo los asigna GOD.</li><li>Un no-GOD sólo asigna roles cuyos permisos ya posee.</li><li>Nadie cambia sus propios roles, ni se desactiva o elimina a sí mismo.</li><li>La cuenta GOD sólo la gestiona su titular; su rol no se asigna ni se retira desde la web (trigger `usuario_rol_god_protegido`).</li><li>`solo_god` protegido también por trigger.</li><li>Privilegios **por columna** en BD (0005).</li><li>IDs de ruta sólo numéricos.</li></ul> |
| **A02 Criptografía** | <ul><li>Argon2id (m=64 MiB, t=4) con rehash.</li><li>SHA-256 del ID de sesión.</li><li>HMAC-SHA256 del usuario en `intentos_login`.</li><li>`APP_KEY` y `ACCOUNT_HMAC_KEY` de 256 bits: la app no arranca sin ellas.</li><li>TLS en producción (FASE 10).</li></ul> |
| **A03 Inyección** | <ul><li>PDO con prepares nativos (web y procesador).</li><li>Fragmentos SQL dinámicos sólo constantes; `LIKE` escapado.</li><li>Escape `e()` en toda vista; JS sólo con `textContent`; CSP sin `unsafe-inline`.</li><li>PHP nunca ejecuta shell (`create-god` usa `proc_open` con arreglo constante para `stty`).</li><li>`unserialize` con `allowed_classes=false`.</li><li>Entradas con UTF-8 inválido se descartan.</li></ul> |
| **A04 Diseño inseguro** | <ul><li>Mensajes genéricos.</li><li>Límite de intentos por usuario+IP, por usuario global y por IP, serializado por usuario (sin carrera).</li><li>Límite en el cambio de contraseña propia.</li><li>Confirmación y motivo en operaciones sensibles.</li><li>Reintentos de trabajos acotados por `CHECK` en BD.</li></ul> |
| **A05 Configuración** | <ul><li>`assertSecure()` bloquea el arranque en producción con debug o sin llaves.</li><li>Cabeceras: CSP, XFO DENY, nosniff, Referrer-Policy, Permissions-Policy, COOP/CORP, `no-store`, HSTS en producción.</li><li>Sólo `public/` expuesto; PHP sólo en `index.php`; sin listado de directorios.</li><li>`.well-known` permitido.</li><li>Docker de desarrollo con credenciales separadas por servicio y `.env` oculto en los contenedores.</li></ul> |
| **A06 Componentes** | Dependencias fijadas: Bootstrap 5.3.8, Bootstrap Icons 1.13.1, PHPUnit 12, PhpSpreadsheet 5.9 (sólo lectura de `.xls`). Sin CDN. El servidor no tiene dependencias de Python. |
| **A07 Autenticación** | <ul><li>Sin correo ni registro público.</li><li>Verificación ficticia **constante** (mismo coste que un usuario real): sin enumeración por tiempo.</li><li>Sesión: regeneración al autenticar y al cambiar la contraseña; expiración por inactividad (el sondeo de la API no la renueva) y absoluta.</li><li>Revocación al desactivar, eliminar, restablecer contraseña o cambiar roles.</li><li>Contraseña temporal obligatoria.</li></ul> |
| **A08 Integridad** | <ul><li>Auditoría encadenada v2: incluye `user_agent` y `clock_timestamp()`; los registros v1 conservan su fórmula.</li><li>Migraciones con checksum.</li><li>Lease con token en la cola.</li></ul> |
| **A09 Registro** | <ul><li>Logs JSON por canal.</li><li>Redacción de claves sensibles y enmascaramiento de cuentas (también agrupadas o pegadas a letras), CURP y RFC (sin distinguir mayúsculas). Las fechas no se alteran.</li><li>Accesos denegados en `security.log`.</li></ul> |
| **A10 SSRF** | No aplica: la app no realiza peticiones salientes; `allow_url_fopen = Off`. |
| **CSRF** | Token sincronizador de 256 bits (`hash_equals`) en todo método no seguro, incluidos login y logout, más verificación de `Origin`. En rutas protegidas se autentica antes de validar CSRF: con la sesión expirada se redirige a `/login`. Error: **403** `CSRF_INVALIDO` (Apache convierte el 419 en 500). |

## 2b. Controles de carga y almacenamiento (FASE 2; detalle en `docs/IMPORTS.md`)

| Riesgo (CWE) | Control |
|---|---|
| Carga de tipo peligroso (CWE-434) | Lista blanca de extensiones (`xls`, `xlsx`, `xlsm`); **firma** (magic bytes) coherente con la extensión; **MIME real** con `finfo` (el MIME del navegador sólo se registra); verificación de la parte principal del libro (xlsx ≠ xlsm, `.docx`/EPUB renombrados rechazados); `is_uploaded_file` + `move_uploaded_file` |
| *Path traversal* (CWE-22) | El nombre del usuario **nunca** forma parte de la ruta: `imports/{int}/{int}/{uuid v4}/original.{ext}` con `CHECK` regex en BD; nombre saneado (basename, NFC, sin controles, bidi ni ancho cero); rutas de ID sólo numéricas; `StoredOriginal::resolve` en el procesador rechaza rutas fuera de `imports/` y enlaces simbólicos; `discard` sólo borra dentro de `imports/` |
| Bomba ZIP (CWE-409) | PHP con tamaños declarados (entradas, total, ratio, parte XML); **el procesador descomprime en streaming con tope** (`ContainerVerifier`) y no confía en la cabecera; límites en `configuracion` |
| XXE / *billion laughs* (CWE-611/776) | `DOCTYPE` y `ENTITY` rechazados en todas las partes XML (ventana deslizante entre bloques). Ningún XML se parsea con resolución de entidades; el lector del procesador (XMLReader) rechaza cualquier DTD |
| Macros | Nunca se ejecutan: sólo parsers (ZIP y OLE2/CFB propios en PHP, lector OOXML propio con XMLReader y PhpSpreadsheet para `.xls`, todos en sólo lectura); ningún Office ni LibreOffice en el servidor. `tiene_macros` es un metadato |
| Contenido cifrado | Entradas ZIP cifradas y `EncryptedPackage` OLE → `CONTENEDOR_PELIGROSO` |
| Alteración del original | 0440; trigger de inmutabilidad (incluso para el propietario); worker con montaje de sólo lectura; SHA-256 **verificado antes de cada descarga** (409 + `archivos.integridad_fallida`); `bin/verificar-almacenamiento.php --hash` |
| Exposición directa | `storage/` fuera de `public/`; la prueba de humo confirma que `/storage/...` y `/../storage` no se sirven |
| Distribución de contenido peligroso | Descarga por **POST + CSRF** (no desde un enlace externo); los `RECHAZADO` no se descargan; un libro con macros exige confirmación explícita tras un aviso |
| Descarga (XSS/sniffing) | `application/octet-stream`, `attachment` con nombre ASCII neutralizado + `filename*` UTF-8, `nosniff`, `CSP default-src 'none'; sandbox`, `no-store`; permiso `archivos.descargar_original` (sólo ADMIN/GOD) y auditoría |
| IDOR | Todas las rutas con `perm:*`; los servicios buscan por ID y validan estado; no hay pertenencia por usuario (el alcance es institucional por permisos) |
| DoS por tamaño | `MAX_UPLOAD_SIZE` < `upload_max_filesize` < `post_max_size` < `LimitRequestBody`; cuerpo excesivo → 413 antes de enrutar |
| Inconsistencia BD ↔ disco | Staging → rename atómico dentro de la transacción → compensación ante cualquier `Throwable`; `StorageAuditor` detecta huérfanos y los mueve a cuarentena (nunca borra) |
| Repudio | Auditoría de cargas, rechazos (`archivos.rechazar_carga`), clasificación, reproceso, anulación, descarga, integridad fallida, cambios de periodo y lote, y verificación del worker (origen `WORKER`) |

## 2c. Controles del motor de análisis (FASE 3; detalle en `docs/FILE_ANALYSIS.md`)

| Riesgo (CWE) | Control |
|---|---|
| Ejecución de código (macros, DDE, OLE) | Sólo parsers (lector OOXML propio con XMLReader, PhpSpreadsheet para `.xls`); VBA sólo se detecta por nombre; DDE/OLE, vínculos externos, nombres definidos externos e hipervínculos se **cuentan y reportan**, nunca se resuelven; sin red, sin ejecución de procesos, sin `eval`; `FormulaTranslator` sólo manipula texto |
| XXE / *billion laughs* (611/776) | XMLReader en streaming con `LIBXML_NONET` que rechaza cualquier DTD en TODAS las partes (libro, relaciones, cadenas compartidas, estilos, hojas, vínculos externos) + la verificación previa rechaza DOCTYPE/ENTITY |
| Agotamiento de CPU (400/407/834) | `tick()` cooperativo cada 20 000 eventos XML (independiente de números de fila) y cada 2 000 filas en .xls; columna implícita O(1); fórmulas > 8 192 caracteres rechazadas; traducción de fórmulas compartidas sólo para SUM/SUBTOTAL cortas y acotada; **tope duro** `set_time_limit` (plazo del trabajo + 60 s); un análisis que excede el tiempo es error **permanente** (no se reintenta) |
| Agotamiento de memoria (770) | El XML de `.xlsx`/`.xlsm` se lee en streaming con XMLReader (memoria constante por parte; los `.xls`, acotados por tamaño de carga, los abre PhpSpreadsheet en memoria); topes: `analisis.max_celdas` (≤ 2 000 000, celdas con valor), 4× ese valor para toda `<c>`/celda en blanco, 100 000 celdas combinadas, 10 000 relaciones por parte, tope de bytes por parte XML; `memory_limit` de PHP del procesador (`WORKER_MEMORY_LIMIT`, por omisión 1536M), `mem_limit` del contenedor en Docker (producción: `MemoryMax` de systemd, FASE 10) |
| TOCTOU del original (367) | El análisis vuelve a comprobar **tamaño y SHA-256** antes de leer (`INTEGRIDAD_FALLIDA`); montaje de sólo lectura en el worker |
| Rutas en el ZIP (22) | Destinos de relaciones (`../`, absolutos) sólo se usan como nombres dentro del ZIP y se validan contra la lista de partes; `resolve_original` confina y rechaza enlaces simbólicos |
| Exposición de cuentas (759/328/359) | Staging: cuenta principal sólo como HMAC (`ACCOUNT_HMAC_KEY`) + últimos 4; `hash_registro` y `clave_hash` son **HMAC con llave derivada** (no SHA-256 sin llave, que sería reversible por fuerza bruta); columnas no reconocidas con aspecto de cuenta se enmascaran; el registro canónico con cuentas completas sólo vive en memoria |
| Datos personales en metadatos (359) | Hojas sin perfil no guardan columnas; un "encabezado" con forma de RFC/CURP o 8+ dígitos se guarda como `[dato enmascarado]`; ninguna vista muestra filas de datos; la huella se muestra truncada (16 caracteres) |
| Datos personales en errores y logs (209/532) | Errores inesperados → `ESTRUCTURA_ILEGIBLE` con sólo tipo y línea del código del motor; errores de BD al persistir → `ANALISIS_INCONSISTENTE` con sólo el tipo; la auditoría guarda códigos, conteos y prefijo de huella |
| Privilegios (269/284) | Web: `SELECT` de análisis y de staging **sólo por columnas** (sin `datos` ni HMAC); `UPDATE` sólo de las columnas de selección manual; no puede fijar estados del motor (comprobado por **pertenencia** a `conciliarh_web`, no sólo por nombre). Worker: `INSERT/SELECT` en `analisis_archivo` + `UPDATE(vigente)`, `DELETE` sólo en tablas derivadas; no inserta en la cola (lo hace un trigger `SECURITY DEFINER` con `search_path` fijo) |
| Integridad de la huella (345) | La huella sólo se fija al **cerrar** un análisis; una huella distinta con la misma versión queda como advertencia `HUELLA_DISTINTA_MISMA_VERSION` y en la auditoría (comparada con la última huella no nula del historial); el historial no es modificable por el worker |
| Abuso del reanálisis (770/799) | Un análisis a la vez por archivo, espera mínima configurable (`analisis.reanalisis_espera_segundos`, 30 s), sólo en lotes/periodos abiertos (comprobado también en el trigger) y con permiso `analisis.reprocesar` |
| Autorización / CSRF | Rutas nuevas con `perm:analisis.*`; POST con CSRF; CONSULTA sólo `analisis.ver` (sin diagnóstico ni decisiones) |

## 2d. Controles de identidades y cuentas (FASE 4; detalle en `docs/IDENTITY_MATCHING.md`)

| Riesgo (CWE) | Control |
|---|---|
| Exposición de cuentas/CLABE (311/312/359) | AES-256-GCM con anillo de llaves desde configuración (`ACCOUNT_ENC_KEYS`, `ACCOUNT_ENC_KEY_ID`; la app no arranca sin él), nonce aleatorio por valor, AAD con `key_id`, versión de formato; búsqueda por **HMAC** con llave (nunca SHA simple); visualización `••••1234`; revelar = permiso `cuentas.ver_sensible` + POST + CSRF + respuesta `no-store` + auditoría con sólo últimos 4 |
| Lectura indebida desde la web (269/285) | El rol web **no** puede leer `cuenta_hmac`/`cuenta_cifrada` de registros ni de staging, ni `cuenta_hmac` de cuentas (privilegios por columna, probados); la confirmación de CLABE copia los valores dentro de la BD (`fn_confirmar_cuenta`, `SECURITY DEFINER`, `search_path` fijo, exige registro asociado y cuenta no activa para otra persona) |
| PII por rol (359/200) | CONSULTA ve RFC/CURP/ISSEMYM enmascarados y no busca por ellos; búsqueda por RFC/CURP sólo **exacta** y con `empleados.ver_sensible`; API y vista del archivo sólo con conteos; auditoría sin CLABE ni RFC completos (se enmascaran) |
| IDOR (639) | Cuentas se resuelven por `(cuenta, empleado)`; registros sólo en importaciones vigentes; relaciones de lotes sólo dentro del mismo periodo (trigger); ids de ruta sólo enteros |
| Integridad de decisiones (362/367) | Asociación manual con `FOR UPDATE` + `asociacion_version`; el motor re-asocia sólo pendientes con la misma verificación; candado consultivo global en la normalización; motivo obligatorio |
| Repudio (778) | `asociaciones_historial` y `empleado_historial` de sólo inserción (trigger; excepción únicamente para el propietario con un parámetro de sesión explícito, usado en limpieza de pruebas) + auditoría encadenada |
| Suplantación de identidad por datos de Tesorería (287) | Tesorería no crea empleados (CHECK + FK de origen a RRHH); el nombre sólo sugiere; pensiones nunca por nombre (CHECK); contradicciones ⇒ CONFLICTO |
| Inyección SQL (89) | Parámetros enlazados; los únicos identificadores interpolados (tabla de registros, columna rfc/curp) salen de listas blancas en código; `LIKE` con `ESCAPE '!'` y escape propio |

## 2e. Actor no suplantable (migración 0012)

**Requisito:** ninguna columna de autoría (`creado_por`, `confirmado_por`, `resuelto_por`, `asociado_por`, `usuario_id` de historiales y auditoría, `cancelado_por`, `revocada_por`, `asignado_por`, …) puede ser elegida por una petición HTTP; aunque PHP falle, PostgreSQL no debe aceptar que una acción se atribuya a otra persona.

**Mecanismo:**
1. **Prueba de identidad = token crudo de la sesión.** La tabla `sesiones` sólo guarda `SHA-256(token)`. En cada petición autenticada, `Authenticate` entrega a PostgreSQL el token crudo con `SELECT set_config('conciliarh.actor_token', :t, false)` (parámetro enlazado; sin `SET` concatenado). Leer la tabla de sesiones no permite obtener el token de otra persona.
2. **`fn_actor_actual()`** (`SECURITY DEFINER`, `search_path` fijo): usuario de la sesión cuyo hash coincide, no revocada, con actividad en las últimas 12 h y usuario activo; si no, `NULL`.
3. **Triggers `*_actor`** (genéricos, `fn_actor_verificar(columnas…)`) en las 18 tablas con autoría escrita por la web: si la conexión es del rol web (`session_user`, lo que cubre también funciones `SECURITY DEFINER` como `fn_crear_lote` o `fn_confirmar_cuenta`), toda columna de autoría nueva o modificada debe ser **exactamente** el actor de la sesión; si no, `insufficient_privilege`. Sin sesión, ninguna autoría es válida.
4. **Auditoría web:** la web sólo inserta origen `WEB`; `usuario_id` debe ser el actor (o nulo en eventos anónimos) y `username`/`roles` los fija la BD.
5. **Vinculación sesión → usuario:** sólo por `fn_sesion_vincular(token, usuario, ip, agente)`, invocada tras verificar la contraseña (y al regenerar el id de una sesión ya autenticada). Una sesión se vincula una vez y no se re-vincula a otra persona; la BD lo audita (`auth.sesion_vinculada`, origen SISTEMA). El rol web perdió `INSERT/UPDATE (usuario_id)` sobre `sesiones` y un trigger lo refuerza.
6. El propietario (migraciones, CLI) y el worker son servicios de confianza: sus escrituras no pasan por esta verificación (el worker no atribuye acciones a usuarios salvo al trasladar una decisión manual existente).

**Riesgo residual (documentado):** la verificación de la contraseña (Argon2id) ocurre en PHP; un atacante con las **credenciales de base de datos del rol web** podría vincular una sesión nueva a otro usuario con `fn_sesion_vincular`, pero **no silenciosamente**: la vinculación queda en la auditoría encadenada (append-only) con IP y agente. Un error de PHP o un parámetro HTTP ya no puede suplantar a nadie.

## 2f. Conciliación financiera (FASE 5, migración 0013)

- **Actor.**
  - `solicitado_por`, `cerrada_por` e `invalidada_por` de `conciliaciones`, así como `actualizado_por` de incidencias, `usuario_id` del historial de incidencias y `creado_por`/`anulado_por` de las relaciones póliza ↔ dispersión, pasan por `fn_actor_verificar` (§2e).
  - Un campo `solicitado_por` enviado en el formulario se ignora; la BD rechaza cualquier otro valor.
- **Resultados inmutables.**
  - La web **no** tiene `INSERT`/`UPDATE`/`DELETE` sobre resultados, controles, fuentes ni archivos de la conciliación.
  - El worker sólo inserta, y sólo mientras la ejecución está EJECUTANDO (trigger).
  - En `conciliaciones`, la web sólo puede actualizar las columnas de cierre, invalidación y `proceso_id`; los estados de ejecución los fija el motor (trigger, `42501`).
  - Totales, resumen, huella y versión quedan congelados al terminar; no hay DELETE ni TRUNCATE.
- **Incidencias.**
  - La web sólo cambia estado, comentario, evidencia y autor.
  - Tipo, severidad, importe y detalle son inmutables (trigger).
  - En una conciliación CERRADA o INVALIDADA no cambia nada: *resolver ≠ cambiar dinero*.
- **Invalidar una conciliación cerrada** es exclusivo de GOD, también en BD: se comprueba el rol del actor de la sesión.
- **Autorización e IDOR.**
  - Cada ruta tiene su permiso (`conciliaciones.ver/ver_sensible/ejecutar/reprocesar/cerrar/invalidar/exportar/relacionar`, `incidencias.ver/resolver`).
  - Los recursos anidados se buscan siempre por la pareja (conciliación, incidencia) o (conciliación, empleado), y responden 404 si no corresponden.
  - RFC y CURP se enmascaran sin `conciliaciones.ver_sensible` **y** `empleados.ver_sensible`; buscar por RFC/CURP exige el permiso sensible.
- **Exportación (XLSX/CSV).**
  - Sólo por POST con CSRF y permiso; queda auditada (formato y filas, sin contenido) y se sirve con `Cache-Control: no-store`.
  - Contra la inyección de fórmulas (CWE-1236):
    - en CSV, el texto que empieza con `= + - @ TAB CR` se prefija con `'`;
    - en XLSX nunca se escribe `<f>`: el texto va como `inlineStr` y sólo los decimales estrictos van como número.
  - Un importe negativo sigue siendo número y no se altera.
- **PII.**
  - La auditoría y las métricas del trabajo `CONCILIAR_LOTE` sólo contienen conteos, ids y tiempos.
  - Los errores de BD se convierten en `CONCILIACION_INCONSISTENTE` sin datos de filas.
  - La API `/api/conciliaciones/{id}` no expone personas.
- **Concurrencia.**
  - Hay como máximo una ejecución en curso por lote (índice único parcial).
  - El trabajo bloquea la fila, lee en `REPEATABLE READ` y reintenta si el lote aún tiene archivos en proceso.

## 2g. Operación, reportes y rotación (FASE 6, migraciones 0014–0015)

- **Revisión masiva:** se sigue esta cadena:
  1. la previsualización se **firma** (HMAC con `APP_KEY`, separado por dominio) sobre usuario, expiración, acción, opciones y `id:versión:candidato`;
  2. la ejecución verifica la firma y **revalida bajo candado** (`FOR UPDATE`, tomando antes el candado consultivo global);
  3. se aplica todo o nada (`REGISTRO_MODIFICADO`).

  No se aceptan en bloque CONFLICTO/AMBIGUO, confianza BAJA ni una CLABE con candidatos distintos en **cualquier** pendiente de su grupo. Los campos extra se ignoran y el actor lo fija la BD.
- **Beneficiarios.** `fn_confirmar_beneficiario` (SECURITY DEFINER) exige:
  - el actor de la sesión;
  - la asociación manual del mismo usuario en la misma transacción;
  - los permisos `beneficiarios.confirmar` + `cuentas.confirmar`;
  - importación vigente y periodo abierto;
  - una acción masiva del mismo usuario y de la misma transacción.

  Una CLABE de otro trabajador o de otro beneficiario responde `unique_violation`. Las ediciones **directas** de la web sólo tocan observaciones, y la autoría se **fuerza** al actor de la sesión (no se puede omitir `actualizado_por` para heredar la anterior). Las relaciones sólo se desactivan (autor, motivo, hora de BD) y la desactivación también desactiva la cuenta que las revivía.
- **Oráculo de CLABE.** `v_revision_grupos_cuenta` da a quien tiene `asociaciones.ver` un **seudónimo estable** (48 bits del SHA-256 del HMAC) por CLABE pendiente y si ya está registrada. No revela el número ni el HMAC, pero permite agrupar. Es un riesgo aceptado y documentado porque evita revisar cientos de transferencias una por una.
- **Reportes:**
  - Vista GET; exportación POST con CSRF, permiso y auditoría (`reportes.exportar`, con huella y sin contenido).
  - Límites por minuto y una generación a la vez por usuario (429).
  - Nombre de archivo del sistema, `no-store` y temporales fuera de `public/`, borrados al transmitir, ante fallos y por barrido.
  - Los reportes exigen los permisos del módulo que muestran.
  - RFC/CURP sólo con `reportes.exportar_sensible` **y** `empleados.ver_sensible`; cuentas siempre con `••••` y los últimos 4.
- **Inyección de fórmulas:**
  - XLSX con celdas `StringCell`/`NumericCell` explícitas (`Cell::fromValue` de OpenSpout crea fórmulas con `=`).
  - CSV RFC 4180 sin carácter de escape.
  - Prefijo `'` para `= + - @ TAB CR`.
- **PDF** (dompdf 3.1.6, local):
  - sin red (`isRemoteEnabled=false`) y protocolos sólo `data://` (ni `file://`);
  - chroot y caché en el directorio de trabajo;
  - sin PHP ni JS;
  - todo valor escapado.

  Probado con un socket local (sin conexión) y en `security-smoke` con el log del servidor.
- **Rotación de llaves:** sólo CLI con el rol propietario, lotes transaccionales, reanudable, `--dry-run`, verifica antes de escribir y nunca muestra llaves (`docs/KEY_ROTATION.md`). «Completa» sólo si no queda ninguna fila con otra llave.
- **Defecto corregido en 0014 (plan genérico):** una función con EXECUTE sólo para la web nunca se menciona en la misma expresión que su guarda dentro de un trigger que también ejecuta el worker.

## 2h. Identidad, llaves y operación (FASE 7, migraciones 0016–0018)

- **Rotación HMAC** (`HMAC_ROTATION.md`):
  - llave activa en la BD;
  - sombra reanudable y corte atómico con chequeo biyectivo;
  - triggers que exigen la llave activa en toda huella;
  - valor de verificación (KCV) por llave, así que un anillo mal configurado nunca escribe;
  - secretos de 64 hex; sólo CLI con el rol propietario;
  - nunca imprime llaves, huellas ni valores;
  - la web ya no recibe la llave HMAC de cuentas (el bloqueo de login usa una llave derivada de `APP_KEY`).
- **Fusión de identidades** (`IDENTITY_MERGE.md`):
  - modelo de alias; no mueve ni borra;
  - `fn_fusionar_empleados` y `fn_separar_fusion` son SECURITY DEFINER, con actor de la sesión, permisos, candado global y huella del análisis previsualizado;
  - el nombre por sí solo nunca fusiona;
  - los conflictos graves sólo los acepta un usuario autorizado, con motivo (FASE 7: GOD; desde la FASE 8 se solicitan y otra persona aprueba; desde 0026 pueden solicitar ADMIN y GOD);
  - separa ADMIN o GOD (`empleados.separar`; exclusivo de GOD hasta la migración 0026);
  - `fn_fusion_analisis` exige permiso a la web (revela igual/diferente de identificadores);
  - la auditoría se confirma en la misma transacción que la fusión.
- **Reportes programados** (`SCHEDULED_REPORTS.md`):
  - rol propio `conciliarh_scheduler`, separado del worker que procesa libros no confiables;
  - permisos del dueño evaluados al generar;
  - la definición sólo la edita el dueño;
  - la descarga exige los permisos con los que se construyó el contenido;
  - archivos fuera de `public/`, ruta del sistema y SHA-256 verificado sobre la copia entregada (`409` y ALTERADO si no coincide);
  - cuotas de programaciones, generaciones manuales y almacenamiento;
  - sensibles con retención de 30 días;
  - limpieza con candado para no borrar lo que se está copiando.
- **Sin comandos de shell** en las funciones nuevas (scheduler, rotación, generación de PDF y trabajos). Ningún parámetro del usuario llega a una ruta: los ids pasan por el router (enteros) y las rutas de archivo las genera el sistema.

## 2h-bis. Tres roles (migración 0026)

Sólo existen **GOD**, **ADMIN** y **CONSULTA**.

| Rol | Qué hace |
|---|---|
| GOD | Todo. Es el único que administra usuarios, roles y permisos, consulta y verifica la auditoría, cambia la configuración, administra catálogos y conceptos, y ve los procesos y la salud del sistema |
| ADMIN | Toda la operación: quincenas, lotes (incluido declarar el oficial y reabrir), archivos, asociaciones, beneficiarios, cotejos, reportes, duplicados y fusiones (solicitar, aprobar la de otra persona y separar). No administra usuarios ni roles y no ve la auditoría (403 en `/auditoria`) |
| CONSULTA | Sólo lectura, con datos sensibles enmascarados. No ve la sección «Avanzado» del menú |

- Permisos que siguen siendo `solo_god`: `auditoria.administrar`, `catalogos.administrar`, `configuracion.editar`, `procesos.diagnostico` y `roles.administrar`.
- `lotes.declarar_oficial`, `lotes.reabrir`, `periodos.reabrir`, `periodos.anular` y `empleados.separar` dejaron de ser `solo_god` y se otorgaron a ADMIN, junto con `empleados.fusionar_conflictos` y `empleados.fusionar_conflictos_aprobar`.
- ADMIN perdió `auditoria.ver` y `auditoria.verificar`.
- La regla de dos personas se conserva: el ADMIN que solicita una fusión con conflictos graves no la aprueba ni la rechaza; lo hace otro ADMIN o GOD.
- Quien tenía ANALISTA o APROBADOR pasó a ADMIN; cada cambio quedó en la auditoría (`usuarios.roles_consolidados`, origen `SISTEMA`, con los roles anteriores y nuevos).
- ADMIN sigue siendo un rol protegido: sólo GOD lo asigna, también en la BD.

## 2i. Cuatro ojos, cifrado de reportes, notificaciones y salud (FASE 8, migraciones 0019–0022)

- **Fusiones con conflictos graves: dos personas distintas** (docs/CRITICAL_MERGE_APPROVAL.md).
  - La BD rechaza la fusión directa (`APROBACION_REQUERIDA`) y exige `decidido_por <> solicitado_por` (CHECK y función).
  - El análisis se recalcula al aprobar; si cambió, la solicitud se cancela.
  - Expira a las 24–72 h.
  - La segunda firma la da **otra persona** con `empleados.fusionar_conflictos_aprobar`: otro ADMIN o GOD. Hasta la migración 0026 la daba el rol protegido APROBADOR; desde 0026 ese rol ya no existe y ADMIN tiene el permiso. Quien solicita no aprueba ni rechaza su propia solicitud (CHECK `sf_cuatro_ojos` y funciones de la 0020).
  - Aprobar y rechazar: 10 por minuto por usuario.
- **Reportes con personas cifrados en reposo** (docs/REPORT_ENCRYPTION.md).
  - Primitiva: libsodium secretstream XChaCha20-Poly1305, con datos asociados que ligan el archivo a su reporte y un KCV por llave.
  - Anillo `REPORT_ENC_KEYS` **separado** de las llaves de cuentas, HMAC y `APP_KEY`: la aplicación no arranca si se repiten.
  - Antes de entregar un byte se verifica todo: el SHA-256 de la copia y cada etiqueta.
  - El claro se descifra por segmentos directo a la respuesta y nunca toca el disco.
  - Manipulación ⇒ 409 y ALTERADO; llave equivocada ⇒ 503 sin marcar.
  - Rotación por CLI con el rol propietario, sin claro en disco.
  - Descargas: 30 por minuto por usuario.
- **Notificaciones** (docs/NOTIFICATIONS.md).
  - RLS por actor, así que el IDOR queda bloqueado en la BD.
  - La web no crea ni reasigna notificaciones.
  - Textos sin datos personales, armados en el servidor.
  - URL sólo interna (sin `//`).
  - Todo por POST con CSRF.
- **Salud del sistema** (docs/SYSTEM_HEALTH.md).
  - `/api/health` pública y mínima.
  - El diagnóstico completo requiere `sistema.salud`.
  - Nunca muestra secretos ni rutas absolutas.
  - La única acción es la revisión de almacenamiento en **sólo lectura** (`sistema.mantenimiento`, GOD), una cada 5 minutos, auditada.
- **Pensiones MEDIA.**
  - Cada caso lo decide una persona, con versión optimista; nada se confirma solo.
  - La historia de la CLABE se compara dentro de la BD (`fn_historial_clabe`), sin exponer la huella.
- **Límites de frecuencia** (`Throttle`) contados sobre la auditoría, la misma fuente que registra la acción.
- **Auditoría sin corrupción.** `Logger::maskText` ya no enmascara resúmenes hex (sha256): antes una racha de dígitos dentro de una huella podía alterarse en la auditoría.
- **Respaldo y restauración** sin secretos en los scripts; destinos vacíos; verificación de sumas antes de restaurar; cadena de auditoría y SHA-256 de cada original después (docs/BACKUP_RESTORE.md).

## 3. Mínimo privilegio en base de datos

- **`conciliarh_owner`**
  - Propietario **NOSUPERUSER**; sólo migraciones y alta del GOD (credenciales **sólo** al ejecutar esos comandos).
- **`conciliarh_web`**
  - `usuarios`: `UPDATE` sólo en `nombre`, `password_hash`, `activo`, `debe_cambiar_password`, `password_cambiado_at`, `ultimo_acceso_at`, `deleted_at` y `deleted_by`.
  - `roles`: `INSERT` en (`codigo`, `nombre`, `descripcion`, `protegido`); `UPDATE` sólo en (`nombre`, `descripcion`, `activo`).
  - `configuracion`: `UPDATE` sólo en (`valor`, `actualizado_por`).
  - `procesos`: `UPDATE` sólo en las columnas de cancelación.
  - `auditoria`: sólo `INSERT` y `SELECT`. Sin `DELETE` en tablas de negocio ni en auditoría.
- **`conciliarh_worker`**
  - `procesos`: `UPDATE` sólo en las columnas de ejecución (no puede cambiar `tipo`, `parametros`, `creado_por`…). Sin `INSERT` ni `DELETE`.
  - Sin acceso a `usuarios`, `sesiones` ni lectura de `auditoria`.

## 4. Hallazgos de la revisión de la FASE 1 y su estado

> **Nota histórica.** Los hallazgos de las secciones 4 a 4j se registraron cuando el procesador en segundo plano estaba escrito en Python; por eso mencionan «Prueba Python», `python.log` y otros detalles de esa implementación. Se conservan tal como se registraron. El procesador actual es PHP CLI (`bin/worker.php`, `app/Engine`): mantiene los mismos controles, sus pruebas están en `tests/Engine/` (PHPUnit) y escribe su log en el canal `motor` (`motor-cli-conciliarh-worker.log`).

### Revisión de seguridad

| # | Severidad | Hallazgo | Estado |
|---|---|---|---|
| S1 | MEDIA | Autoasignación de roles y asignación de roles con más permisos que el actor | ✅ Corregido: bloqueo de roles propios, regla de subconjunto de permisos y marca `protegido` en roles nuevos. Pruebas: `testNobodyCanChangeTheirOwnRoles`, `testNonGodCannotAssignRolesWithPermissionsTheyLack` |
| S2 | MEDIA | Enumeración por tiempo: el hash ficticio se generaba en cada petición | ✅ Hash ficticio **constante** con los mismos parámetros. Prueba: `testDummyVerificationCostsTheSameAsARealOne` |
| S3 | MEDIA | Límite de intentos: condición de carrera, bloqueo como DoS, Argon2 durante el bloqueo, proxy | ✅ `pg_advisory_xact_lock` por usuario; umbrales por usuario+IP (5), por usuario global (20) y por IP (30); sin Argon2 durante el bloqueo. ⚠️ Con un proxy inverso debe configurarse `mod_remoteip` (FASE 10). Pruebas: bloqueo por pareja y global |
| S4 | MEDIA | Credenciales repartidas entre contenedores; propietario superusuario | ✅ Entorno por servicio; `.env` oculto en contenedores; propietario NOSUPERUSER; servicios `tools`/`pytools` bajo demanda. Verificado con `env` en los contenedores |
| S5 | BAJA | Privilegios de BD más amplios que el uso real | ✅ Migración 0005: privilegios por columna y trigger GOD. Pruebas: `testColumnLevelPrivilegesProtectSecurityFlags`, `testGodRoleCannotBeGrantedByTheWebRoleEvenWithDirectSql` |
| S6 | BAJA | `user_agent` fuera de la cadena; ancla externa | ✅ Cadena v2 con `user_agent`. ⏳ Ancla externa periódica del último hash: FASE 9 |
| S7 | BAJA | Cambio de contraseña propia sin límite | ✅ Tras 5 fallos se revocan las sesiones. Prueba: `testChangePasswordAttemptsAreLimited` |
| S8 | BAJA | Sesiones anónimas ilimitadas (cada `GET /login` crea una fila) | ⏳ Mitigado: `gc()` borra las inactivas; `/api/health` no crea sesión. Pendiente: límite de peticiones en el servidor web (FASE 10) |
| S9 | BAJA | Enmascaramiento incompleto (dígitos pegados, minúsculas, auditoría) | ✅ Regex mejoradas en PHP y Python; la auditoría también enmascara valores. Pruebas unitarias |
| S10 | INFO | Redirección `/\`, prefijo `__Host-`, umask de `dev-init`, contraseña en argv del smoke, HSTS detrás de proxy | ✅ Todos corregidos salvo HSTS con proxy (documentado para FASE 10). La cancelación de trabajos por cualquier titular de `procesos.cancelar` es **por diseño** |

### Revisión de código

| # | Severidad | Hallazgo | Estado |
|---|---|---|---|
| C1 | ALTA | `create-god` fallaría con el trigger de la 0005 | ✅ Usa el rol propietario; el rol web recibe un mensaje claro |
| C2 | MEDIA | El sondeo de la API mantenía viva la sesión | ✅ `GET /api/*` no renueva la inactividad; el sondeo tiene un tope de 15 min. Prueba: `testApiPollingDoesNotRenewIdleTimeout` |
| C3 | MEDIA | El tablero marcaba "Sin worker" durante trabajos largos | ✅ El hilo de latido actualiza `workers`. Prueba Python |
| C4 | MEDIA | Una caída del hilo de latido no se detectaba | ✅ Reconexión y `lease_lost` si no se renueva en `timeout/2` |
| C5 | MEDIA | Parámetros de configuración sin uso | ✅ Implementados (umbrales global y de cambio de contraseña) |
| C6 | BAJA | Profundidad de transacción incorrecta si falla `commit` | ✅ Decremento único |
| C7 | BAJA | `logout` invalidaba sesiones anónimas | ✅ Sólo si hubo autenticación |
| C8 | BAJA | Un POST con la sesión expirada mostraba un error CSRF | ✅ Se autentica antes. Prueba HTTP |
| C9 | BAJA | Comparación de roles dependiente de la collation | ✅ Ordenamiento en PHP en ambos lados |
| C10 | BAJA | `syncRoles` retiraba roles fuera del alcance | ✅ Sólo sincroniza roles asignables. Prueba |
| C11 | BAJA | `creado_at` de auditoría con la hora de inicio de la transacción | ✅ `clock_timestamp()` en la v2 |
| C12 | BAJA | Carrera de unicidad producía un error 500 | ✅ 23505 se convierte en error de validación |
| C13 | BAJA | UTF-8 inválido producía un error 500 | ✅ Se descarta o se sustituye. Prueba |
| C14 | BAJA | El reclamo no limpiaba errores previos | ✅ Prueba Python |
| C15 | BAJA | `_estado` inválido se reintentaba | ✅ `PermanentError RESULTADO_INVALIDO`. Prueba |
| C16 | BAJA | El `finally` del worker ocultaba la causa original | ✅ `try`/`except` |
| C17 | BAJA | Clave de idempotencia reutilizable entre usuarios o tipos | ✅ Se verifica tipo y creador. Prueba |
| C18 | BAJA | Página fuera de rango | ✅ Se acota. Prueba |
| C19 | BAJA | "En línea" fijo en 30 min | ✅ Usa `SESSION_IDLE_MINUTES` |
| C20 | BAJA | `confirmed` persistía tras volver en el historial; `aria-valuenow` en el nodo equivocado | ✅ |
| C21 | BAJA | `.htaccess` bloqueaba `/.well-known/` | ✅ |

## 4b. Hallazgos de las revisiones de la FASE 2 y su estado

Dos revisiones independientes de sólo lectura sobre el diff frente a `57e2f3d`: una de código y otra de seguridad (OWASP, CWE-434/22/409/611/776, IDOR, separación desarrollo/producción, privilegios). Todas las correcciones tienen pruebas; las de BD están en la migración **0009**, porque 0006–0008 no se editan.

### Revisión de seguridad

| # | Severidad | Hallazgo | Estado |
|---|---|---|---|
| FS1 | MEDIA | El filtro XXE sólo revisaba partes `.xml`/`.rels` y sólo en UTF-8: se evadía con UTF-16 o con una extensión declarada XML en `[Content_Types].xml` | ✅ Partes XML detectadas **por contenido**; UTF-16/32 rechazado. Fixtures `xxe-utf16.xlsx`, `xxe-otra-extension.xlsx`. Los 62 archivos reales siguen pasando |
| FS2 | MEDIA | Se descargaban archivos `RECHAZADO` y libros con macros sin aviso (canal de distribución entre usuarios internos) | ✅ `RECHAZADO` no se descarga; macros → `MACROS_REQUIERE_CONFIRMACION` + aviso. Pruebas de servicio y HTTP |
| FS3 | BAJA/MEDIA | ADMIN (`lotes.anular`) retiraba la marca oficial (exclusiva de GOD); un lote se anulaba con archivos vigentes | ✅ Lote oficial → sólo quien puede declararlo (GOD; desde 0026 también ADMIN); exige no tener archivos vigentes |
| FS4 | BAJA | ANALISTA cerraba lotes y sólo GOD puede reabrirlos | ✅ 0009 retira `lotes.cerrar` a ANALISTA (queda en ADMIN y GOD). Desde 0026 el rol ANALISTA ya no existe |
| FS5 | BAJA | El rol web podía llevar un archivo a `VERIFICADO` sin el motor | ✅ Trigger v2: el rol web sólo fija `RECIBIDO`/`ANULADO` (42501) |
| FS6 | BAJA | Descarga por GET con efecto auditado (CSRF parcial con `SameSite=Lax`) | ✅ `POST` + CSRF; `GET` → 405 (probado también en humo) |
| FS7 | BAJA/INFO | La auditoría inalterable guardaba el nombre de archivos **rechazados** (puede contener datos personales) | ✅ Sólo el SHA-256 del nombre. Las cargas aceptadas conservan `nombre_original` (metadato operativo; retención documentada en `IMPORTS.md`). ⏳ Límite de frecuencia de cargas: FASE 10 (servidor web) |
| FS8 | BAJA | `dev-seed` prometía un control inexistente; el bloqueo de cuentas `dev.*` dependía de `APP_ENV === 'production'` exacto | ✅ Comentario corregido; **lista de permitidos**: las cuentas de desarrollo sólo funcionan con `APP_ENV=development` |
| FS9 | BAJA | El timeout no se revisaba durante la descompresión | ✅ `check()` cada 4 MiB. Prueba Python |
| FS10 | INFO | TOCTOU entre el hash y la inspección; el usuario web es dueño de los originales (0440 cosmético para él) | ⏳ Mitigado: el SHA-256 inmutable en BD detecta cualquier cambio (descarga y verificador). Producción (FASE 10): directorios finales cuyo dueño no sea el proceso web, o `chattr +i` |
| FS11 | INFO | La compensación podía borrar un definitivo ajeno (colisión de UUID) | ✅ La ruta sólo se reclama si no existía |
| FS12 | INFO | El mensaje de duplicado revela el lote; sin cuota de disco; inyección de fórmulas en futuras exportaciones; `reset-dev` sobre un contexto Docker remoto | ✅ Aceptado (el usuario ya tiene `archivos.ver`). ⏳ Cuota: FASE 10. ✅ Neutralizar `nombre_original` en exportaciones: cubierto por `Exporter::text` en toda celda de texto (FASE 6, verificado en la FASE 8). `reset-dev` exige confirmación escrita |

### Revisión de código

| # | Severidad | Hallazgo | Estado |
|---|---|---|---|
| FC1 | ALTA | `fn_proceso_a_archivo` intentaba RECIBIDO→ERROR, transición prohibida: el `fail`/reaper abortaba (worker en bucle, reaper envenenado) | ✅ 0009 permite RECIBIDO→ERROR. Prueba Python con el reaper real |
| FC2 | ALTA | Cancelar un `VERIFICAR_ARCHIVO` dejaba el archivo en RECIBIDO sin salida (la prueba lo encubría) | ✅ Cancelado → `ERROR` `VERIFICACION_CANCELADA`, reprocesable. Prueba reescrita sin atajos |
| FC3 | ALTA | Carrera en `--cuarentena`: podía apartar el original de una carga recién confirmada | ✅ Antigüedad mínima de 1 h y nueva consulta a la BD antes de mover. Prueba |
| FC4 | MEDIA | Anular un lote dejaba archivos vigentes y trabajos en cola | ✅ Exige no tener archivos vigentes (igual que el periodo) |
| FC5 | MEDIA | Carrera entre la anulación del periodo y una carga | ✅ Carga con `FOR SHARE` del periodo; la anulación bloquea los lotes antes de contar |
| FC6 | MEDIA | Anular durante `EN_EJECUCION` dejaba un trabajo "completado" sin verificar | ✅ Se rechaza con `ESTADO_INVALIDO` hasta que termine |
| FC7 | MEDIA | CONSULTA sondeaba `/api/procesos` (403) y veía el mensaje del trabajo | ✅ Sondeo de `/api/importaciones/{id}`; detalle y enlace sólo con `procesos.ver`. Prueba HTTP |
| FC8 | MEDIA | = FS5 | ✅ |
| FC9–FC10 | BAJA | Nombre de lote corto o cualquier 23514 traducidos a mensajes engañosos | ✅ Validación previa y distinción por restricción |
| FC11 | BAJA | Duplicado: se pedía confirmación por otro lote aunque existía en el mismo | ✅ Primero el mismo lote; se ignoran lotes anulados. Prueba |
| FC12 | BAJA | = FS11; además, un fallo del COMMIT borraba un original posiblemente confirmado | ✅ No se borra ante un fallo del COMMIT (el verificador lo resuelve) |
| FC13 | BAJA | Orden de bloqueo inverso entre `declareOfficial` y el cierre del periodo (interbloqueo) | ✅ Periodo → lote en ambos |
| FC14 | BAJA | `update` auditaba aunque no afectara filas | ✅ Comprueba filas afectadas |
| FC15 | BAJA | = FS3 | ✅ |
| FC16 | BAJA | Reclasificar o reprocesar en lotes o periodos cerrados | ✅ `LOTE_NO_ABIERTO`. Prueba |
| FC17 | BAJA | El detalle del lote ocultaba sin aviso los archivos a partir del 501 | ✅ Muestra 200 con "ver todos" (listado paginado) |
| FC18 | BAJA | Un lote sólo verificado aparecía como "Listo" | ✅ Estado `VERIFICADO`; `LISTO` = analizado |
| FC19 | BAJA | = FS1 | ✅ |
| FC20 | BAJA | `.xls` con cifrado BIFF (`FILEPASS`) pasaba como verificado | ✅ Detectado → `CONTENEDOR_PELIGROSO`. Fixture `encrypted.xls` |
| FC21 | BAJA | = FS8 | ✅ |
| FC22 | BAJA | `INSERT` directo de lotes innecesario para el rol web | ✅ Revocado (0009) |
| FC23 | BAJA | Parpadeo de `dragleave` en la zona de arrastre | ✅ Se ignora al pasar sobre elementos hijos |
| — | Pruebas | La concurrencia de `fn_crear_lote` se probaba con el propietario | ✅ Ahora con `conciliarh_web` (GRANT EXECUTE + SECURITY DEFINER reales) |

## 4c. Hallazgos de las revisiones de la FASE 3 y su estado

Revisiones independientes de sólo lectura (código y seguridad) sobre el diff frente a `2fe42f9`; la de seguridad incluyó pruebas de concepto con archivos **sintéticos**. Todas las correcciones tienen prueba de regresión; la validación con archivos reales (fuera del repositorio, sólo agregados) se repitió al final (H-16).

### Revisión de seguridad

| # | Sev. | Hallazgo | Estado |
|---|---|---|---|
| FS3-1 | ALTA | CPU: celdas sin atributo `r` (cuadrático), fórmulas compartidas enormes, `tick` dependiente de números de fila (PoC: 160 K celdas → 117 s sin checkpoints) | ✅ Columna O(1), `tick` por eventos, tope de fórmula y de traducciones, tope duro `SIGALRM`; pruebas `test_cells_without_reference_are_linear…`, `test_tick_is_called…`, `test_overlong_formula…`, `test_hard_deadline…` |
| FS3-2 | ALTA | Memoria: elementos fuera de `sheetData` y relaciones retenidos (PoC: 4,8 M `mergeCell` en 22 MB → 2,6 GB) | ✅ Desprendimiento de cada elemento procesado, topes de merges/relaciones, `_Limited` en relaciones, `mem_limit` del worker; prueba `test_merge_flood…` |
| FS3-3 | MEDIA | `hash_registro`/`clave_hash` SHA-256 sin llave (cuenta recuperable en 2 s) | ✅ HMAC con llave derivada; el trabajo exige la llave; prueba `test_staged_hashes_are_hmac` |
| FS3-4 | MEDIA | Huella v1 cambiable pasando por NULL | ✅ La huella sólo se fija al cerrar un análisis; cambio con la misma versión → advertencia auditada comparando con la última huella no nula |
| FS3-5 | MEDIA | Web podía leer `staging_registros.datos` | ✅ `GRANT SELECT` por columnas; prueba 42501 |
| FS3-6 | MEDIA | PII en `encabezado_original` por encabezado mal detectado | ✅ Sin columnas en hojas sin perfil; enmascaramiento de encabezados con forma de dato personal; prueba |
| FS3-7 | BAJA | Errores de BD al persistir (desbordes) reintentados con detalle | ✅ Importes ≥ 10¹² → INVÁLIDO; `IntegrityError`/`DataError` → error permanente sin detalle; `numero` entero |
| FS3-8 | BAJA | Reanálisis sin freno | ✅ Espera mínima configurable; prueba |
| FS3-9 | BAJA | Rol web comprobado sólo por nombre; lote/periodo abiertos sólo en PHP | ✅ `pg_has_role`; comprobación en el trigger. ⏳ `seleccion_manual_por` lo fija PHP (el trigger no conoce el usuario de la aplicación); queda en auditoría |
| FS3-10 | BAJA | El análisis no re-verificaba el original | ✅ Tamaño + SHA-256 antes de analizar; prueba |
| FS3-11 | BAJA | Cuentas con separadores en columnas no reconocidas | ✅ Enmascaradas por conteo de dígitos; prueba |
| FS3-12 | BAJA | XLS: celdas en blanco sin contar | ✅ Cuentan contra el tope |
| — | INFO | Oráculo de pertenencia vía `claves_comunes`; CONSULTA ve nombres de hoja; rotar `ACCOUNT_HMAC_KEY` invalida comparaciones | Aceptado y documentado (`ver_diagnostico` no lo tiene CONSULTA; nombres de hoja no son personales en los formatos reales; rotación ⇒ reanalizar) |

### Revisión de código

| # | Sev. | Hallazgo | Estado |
|---|---|---|---|
| FC3-1 | ALTA | Archivo bloqueado en ERROR si al reanalizar cambiaba la huella con la misma versión | ✅ Regla de huella al cerrar análisis + advertencia `HUELLA_DISTINTA_MISMA_VERSION`; prueba |
| FC3-2 | ALTA | = FS3-3 | ✅ |
| FC3-3 | MEDIA | Encabezados no reconocidos repetidos se pisaban en la huella | ✅ Clave `X:<encabezado>#n` + advertencia `COLUMNA_DUPLICADA`; prueba |
| FC3-4 | MEDIA | Importes enormes → error interno reintentable | ✅ = FS3-7 |
| FC3-5 | MEDIA | Póliza: línea con importe inválido desaparecía | ✅ Se conserva y cuenta si es línea contable (cuenta numérica); los títulos de sección no; prueba |
| FC3-6 | MEDIA | Selección sólo comparaba las dos mejores hojas | ✅ Todas las candidatas dentro del margen deben ser equivalentes o subconjuntos filtrados; prueba |
| FC3-7 | MEDIA | `POSIBLE_VERSION` imposible para póliza GLOBAL | ✅ Estructura fija: basta la coincidencia de conceptos |
| FC3-8 | MEDIA | Reanálisis fallido/cancelado dejaba en ERROR un archivo con análisis válido | ✅ Se conserva el resultado vigente (`fn_proceso_a_archivo` v3); prueba |
| FC3-9 | MEDIA | `TIPO_DOCUMENTAL_DISCREPANTE` pedía una acción imposible | ✅ Reclasificar permitido en estados analizados |
| FC3-10 | MEDIA | Memoria del libro materializado | ✅ Tope bajado a 2 M celdas, `mem_limit`; ⏳ no retener filas de hojas descartadas (mejora futura) |
| FC3-11 | MEDIA | = FS3-5 | ✅ |
| FC3-12 | BAJA | SUBTOTAL con funciones que no suman; SUBTOTAL anidados | ✅ Sólo 9/109; anidados omitidos al re-sumar; prueba |
| FC3-13 | BAJA | Parser: `(-100)`, `-0.00`, `1.234` ambiguo | ✅ Inválido, cero canónico, inválido por ambigüedad; pruebas |
| FC3-14…16 | BAJA | Perfil manual desconocido no enviaba a revisión; dedupe de advertencias; paso a ANALIZANDO sin comprobar | ✅ Corregidos |
| FC3-17 | BAJA | Encolado del trigger podía fallar en silencio por clave repetida | ✅ Clave única por transacción (`txid`) |
| FC3-18 | BAJA | Texto inline de celdas sin `r` | ✅ Prueba |
| FC3-19 | BAJA | Valor absoluto de deducciones línea por línea | ✅ Sólo si todas son ≤ 0 |
| FC3-20 | BAJA | Pie de la póliza GLOBAL podía tragarse conceptos | ✅ Etiqueta exacta |
| FC3-21 | BAJA | Filtros sin los estados nuevos | ✅ |
| FC3-22 | BAJA | `down` de 0010 (FK de `workers`, reactivación de perfiles) | ✅ Probado `down`/`up` varias veces |
| FC3-23 | BAJA | Comparar con número inválido daba 404; `misma_huella` sin versión | ✅ Mensaje de validación; compara versión |
| FC3-24 | INFO | `ON CONFLICT DO NOTHING` en controles | ✅ Eliminado (falla ruidosamente) |
| FC3-25 | INFO | Bug de fechas 1900; vínculos externos en fórmulas largas | ✅ Base corregida antes del serial 60; detección sobre el texto completo |
| FC3-26 | INFO | Determinismo con distintas semillas de hash; UUID fijos en pruebas PHP | ✅ Prueba con `PYTHONHASHSEED` 1/2/12345; UUID aleatorios |
| — | Validación real | Reader de fórmulas compartidas usaba `self` en un método estático (sólo aparece con libros reales) | ✅ Corregido + prueba con fórmula compartida escrita a mano |

## 4d. Hallazgos de las revisiones de la FASE 4 y su estado

Revisiones independientes de sólo lectura (código y seguridad) sobre los cambios de la FASE 4. Todas las correcciones tienen prueba de regresión; la validación con archivos reales (fuera del repositorio, sólo agregados) se repitió al final con métricas idénticas.

### Revisión de seguridad (sin hallazgos críticos ni altos)

| # | Sev. | Hallazgo | Estado |
|---|---|---|---|
| FS4-1 | MEDIA | `fn_confirmar_cuenta` confiaba en la asociación que el rol web puede escribir y aceptaba cualquier `p_usuario_id` | ✅ Exige asociación MANUAL del mismo usuario **en la misma transacción** (`asociado_at = now()`), usuario activo con `cuentas.confirmar`, importación vigente, periodo abierto y lote no anulado; toma el candado de la normalización. Prueba |
| FS4-2 | MEDIA | Re-asociación de registros de periodos CERRADOS (PHP, `fn_importaciones_con_cuenta` y worker) | ✅ Filtrada en los tres niveles. Pruebas PHP y Python |
| FS4-3 | MEDIA | `procesos.error_detalle` y logs podían incluir la fila de un error de PostgreSQL (`DETAIL`/`CONTEXT`) | ✅ Sólo tipo, SQLSTATE, tabla y restricción (en el trabajo y en el manejador genérico del worker). Prueba con una restricción forzada |
| FS4-4 | BAJA | El AAD no liga el cifrado a su fila (el worker podría intercambiar valores) | ✅ Al revelar se exige que los últimos 4 descifrados coincidan con `cuenta_ultimos4` |
| FS4-5 | BAJA | `fn_importaciones_con_cuenta` como oráculo de igualdad de cuentas | ✅ Sólo responde para registros cuya cuenta ya fue confirmada manualmente |
| FS4-6 | BAJA | RFC/CURP en la entrada previa guardada en sesión | ✅ Se excluyen `rfc`, `curp`, `issemym` (y `motivo`) |
| FS4-7 | BAJA | `empleados.editar` sin `ver_sensible` podía escribir RFC/CURP a ciegas | ✅ 403 si intenta cambiarlos. Prueba |
| FS4-8 | BAJA | Revelar sin `empleados.ver` | ✅ Exige ambos permisos |
| FS4-9 | BAJA | Relaciones de lotes en periodos cerrados; carrera de relaciones inversas | ✅ Trigger exige periodo abierto y serializa por par de lotes |
| FS4-10 | BAJA→obligatoria | El rol web podía atribuir un `usuario_id` ajeno al escribir historial | ✅ **Corregido antes de la FASE 5 (0012):** el actor se deriva de la sesión autenticada y PostgreSQL rechaza cualquier autoría distinta (§2e). Pruebas `ActorSpoofingTest`, `ActorHttpTest` |

### Revisión de código

| # | Sev. | Hallazgo | Estado |
|---|---|---|---|
| FC4-1 | ALTA | Un RFC/CURP de relleno ("-", ".", "N/A") abortaba toda la importación de un tabulado (CHECK de `empleados`) | ✅ Relleno = sin dato; el maestro sólo recibe RFC/CURP válidos (el original queda en el registro). Prueba |
| FC4-2 | MEDIA | `nombre_normalizado` no se actualizaba con el nombre | ✅ |
| FC4-3 | MEDIA | RRHH sobrescribía correcciones manuales del maestro; lectura sin bloqueo | ✅ Campos corregidos manualmente prevalecen (incidencia `DATOS_RRHH_CONTRADICTORIOS`); relectura con `FOR UPDATE`. Prueba |
| FC4-4 | MEDIA | Alta automática antes de trasladar una decisión manual (empleado duplicado) | ✅ La decisión manual trasladada impide el alta. Prueba |
| FC4-5 | MEDIA | Número válido desconocido + otra evidencia fuerte se asociaba | ✅ CONFLICTO (`NUMERO_DESCONOCIDO_CON_OTRA_EVIDENCIA`). Pruebas |
| FC4-6 | MEDIA | Incidencias cerradas por un usuario se reabrían; las del maestro se descartaban con la importación | ✅ Sólo se reabren las cerradas por el motor; las del maestro no dependen de una importación. Prueba |
| FC4-7 | MEDIA | = FS4-2 | ✅ |
| FC4-8 | MEDIA | Una asociación automática equivocada no podía corregirse ni confirmarse su cuenta | ✅ Asociar admite ASOCIADO_AUTOMATICO (auditado `corrige_automatica`). Prueba |
| FC4-9 | MEDIA | Carrera worker/web al registrar la misma cuenta | ✅ `ON CONFLICT` + la BD como fuente de verdad; la confirmación toma el candado |
| FC4-10 | MEDIA | Misma cuenta para dos personas en un archivo quedaba asociada en silencio | ✅ CONFLICTO + `CLABE_CONFLICTO`. Prueba |
| FC4-11 | MEDIA | Cuentas de archivos anulados seguían siendo evidencia | ✅ Una cuenta sólo identifica si fue confirmada o si la respalda un registro vigente por otro identificador. Prueba |
| FC4-12 | BAJA | `veces_vista` no idempotente | ✅ Archivos vigentes distintos; el último periodo sólo avanza. Prueba |
| FC4-13 | BAJA | Colisión de claves de idempotencia al encolar | ✅ Clave aleatoria y sin duplicar pendientes |
| FC4-14 | BAJA | Doble normalización completa concurrente | ✅ Verificación bajo el candado |
| FC4-15 | BAJA | `identidad.similitud_minima` sin efecto | ✅ Se aplica. Prueba |
| — | Propia | El `down` de 0011 fallaba con datos (orden de borrado) y la clave del reanálisis impedía repetir `down`+`up` | ✅ Corregido y verificado con datos |

## 4e. Hallazgos de las revisiones de la FASE 5 y su estado

Se hicieron dos revisiones independientes, de sólo lectura, sobre los cambios de 0012/0013, el motor, el trabajo y la capa PHP.
- Todas las correcciones tienen prueba de regresión.
- La validación con archivos reales (fuera del repositorio) se repitió después de las correcciones; los originales siguen intactos (SHA-256).

### Revisión de seguridad (sin hallazgos críticos ni altos)

| # | Sev. | Hallazgo | Estado |
|---|---|---|---|
| FS5-1 | MEDIA | CSV: `fputcsv` con escape `\\` permitía que un valor con `\"` abriera una celda nueva que empezara con `=`, saltándose la neutralización | ✅ Escape `''` (RFC 4180 puro). Prueba con `\"` embebido |
| FS5-2 | MEDIA | Por SQL directo, el rol web podía revivir una relación póliza↔dispersión anulada, borrar o reescribir la autoría de cierre, elegir las horas, reasignar `proceso_id`, cambiar incidencias sin historial o insertar un historial arbitrario | ✅ Triggers: la autoría se fija una vez, en su transición; horas de BD; ANULADA terminal; `proceso_id` inmutable; el historial de incidencias lo escribe la BD (la web perdió `INSERT`). Pruebas Python |
| FS5-3 | BAJA | La web podía fijar ERROR/PENDIENTE y llamar `fn_solicitar_conciliacion` saltándose las reglas de PHP (una ejecución sin trabajo bloqueaba el lote) | ✅ La web sólo cierra o invalida (ERROR sólo si su proceso falló). La función verifica actor, permiso `ejecutar`/`reprocesar`, motivo, archivos en proceso y cierre previo, y **encola el trabajo en la misma transacción**. Pruebas |
| FS5-4 | BAJA | La búsqueda por RFC/CURP y su visualización usaban permisos distintos (oráculo) | ✅ Ambas exigen `conciliaciones.ver_sensible` **y** `empleados.ver_sensible`. Prueba |
| FS5-5 | BAJA | Incidencias visibles sin `incidencias.ver` (detalle, tablero, API) | ✅ Ocultas sin el permiso. Prueba |
| FS5-6 | BAJA | Integridad de la relación manual sólo en PHP; un índice de hoja > 32767 provocaba un 500 | ✅ Trigger: lote abierto, archivos del lote, importación vigente y tipos correctos; tope del índice en PHP |
| FS5-7 | INFO | `fn_sesion_reglas`: la variable de vinculación podía fijarla el cliente | ✅ Además exige `current_user <> session_user` (sólo dentro de la función SECURITY DEFINER). Prueba |
| FS5-8 | INFO | El actor por `set_config` de sesión se arrastraría con un *pooler* en modo transacción o con conexiones persistentes | Documentado para la FASE 10. Hoy hay una conexión por petición y el actor se vuelve a fijar en cada petición autenticada |
| FS5-9 | INFO | El traceback de errores inesperados del worker (anterior a la FASE 5) podría incluir valores | Pendiente (FASE 9). La conciliación sólo copia el mensaje genérico |

### Revisión de código

| # | Sev. | Hallazgo | Estado |
|---|---|---|---|
| FC5-1 | ALTA | Un empleado del grupo OTRO hacía fallar la ejecución (CHECK de grupo) | ✅ Grupo fuera de catálogo ⇒ SIN_GRUPO; OTRO admitido. Prueba |
| FC5-2 | ALTA | Duplicado cuyo original se anuló: el dinero desaparecía | ✅ Se excluye sólo si el original participa; si no, se usa con la incidencia `DUPLICADO_SIN_ORIGINAL_VIGENTE`. Prueba |
| FC5-3 | ALTA | Una versión nueva de un tabulado RRHH se ignoraba en silencio | ✅ `VERSION_AMBIGUA_EN_LOTE` (CRITICA) para cualquier tipo, siempre que la versión relacionada sea también fuente candidata (no una vista derivada: caso real verificado). Pruebas |
| FC5-4 | ALTA | Un pago pendiente de identidad «explicaba» a varias personas (pensiones por cualquier importe, ambiguos con dos candidatos) | ✅ Sólo reclasifica con **un único dueño posible**: candidato único, o en pensiones un importe único en ambos lados. Con varios candidatos, sólo alerta. Pruebas |
| FC5-5 | MEDIA | Las relaciones manuales no se reservaban antes del emparejamiento automático | ✅ Primera pasada manual. Prueba |
| FC5-6 | MEDIA | Total de pensión retenida sin registros RRHH no asociados; retención duplicada | ✅ Se suma todo el RRHH fuente y se informa `retenido_sin_empleado`; con registros duplicados cuenta uno y se marca la alerta. Prueba |
| FC5-7 | MEDIA | Se podía cerrar una versión vieja y reprocesar sobre una cerrada | ✅ Sólo se cierra la más reciente; reprocesar exige invalidar el cierre (BD + PHP). Pruebas |
| FC5-8 | MEDIA | El total declarado tomaba la primera hoja; la GLOBAL por hoja no filtraba | ✅ Suma por hojas con registros; GLOBAL por su hoja |
| FC5-9 | MEDIA | Se usaban archivos en ERROR/RECHAZADO; las exclusiones no generaban incidencia | ✅ Sólo ANALIZADO*; incidencias por archivo excluido. Prueba |
| FC5-10 | BAJA | Progreso escrito dentro de la transacción REPEATABLE READ (posible 40001 con el latido) | ✅ El progreso se escribe fuera de la transacción |
| FC5-11 | BAJA | La exportación omitía a las personas con sólo pensión; la relación manual no validaba la importación vigente | ✅ `FULL JOIN`; validación en PHP y en BD |

## 4f. Hallazgos de las revisiones de la FASE 6 y su estado

Hubo revisiones independientes de sólo lectura (código y seguridad ofensiva), pruebas dinámicas (`make security-smoke`) y la validación con archivos reales (fuera del repositorio, sólo agregados), que se volvió a ejecutar después de las correcciones. Todas las correcciones tienen prueba de regresión.

### Encontrados por la validación real y la regresión previa

| # | Sev. | Hallazgo | Estado |
|---|---|---|---|
| FV6-1 | ALTA (disponibilidad) | Defecto de alpha.5: el worker de larga duración fallaba al terminar conciliaciones tras unas 5 ejecuciones (plan genérico de plpgsql + `fn_actor_actual` sólo para la web) | ✅ 0014. Prueba de 8 ciclos en una sesión |
| FV6-2 | ALTA (exactitud) | Las sugerencias de pensión por importe sumaban la retención de las vistas derivadas: faltantes falsos y una sugerencia errónea que, confirmada, producía PENSION_MAYOR | ✅ Faltante sin vistas derivadas ni duplicados. Prueba que falla con el defecto |
| FV6-3 | MEDIA | Reutilizar un beneficiario de otra quincena en la confirmación hacía fallar la acción (trigger con `session_user` dentro de SECURITY DEFINER) | ✅ Distingue `current_user <> session_user`. Prueba |

### Revisión de seguridad (sin hallazgos críticos ni altos)

| # | Sev. | Hallazgo | Estado |
|---|---|---|---|
| FS6-1 | MEDIA | La confirmación masiva convertía evidencia débil (sólo importe) en identidad fuerte (CLABE aprendida) a escala | ✅ En bloque sólo confianza MEDIA o mayor; la BAJA se decide uno por uno. Prueba |
| FS6-2 | MEDIA | CONSULTA recibía `reportes.exportar` (exportación masiva de nombres) | ✅ CONSULTA sólo ve; GOD puede otorgarlo. Prueba |
| FS6-3 | BAJA | Los reportes no exigían los permisos del módulo (incidencias en el resumen, pendientes) | ✅ `conciliaciones.ver` siempre; `incidencias.ver` y `asociaciones.ver` por sección |
| FS6-4 | BAJA | Una edición directa podía omitir la autoría y heredar la del editor anterior | ✅ Autoría forzada al actor en el trigger; se revoca el UPDATE de estado |
| FS6-5 | BAJA | `p_accion_masiva_id` sin verificar (procedencia atribuible a otra acción) | ✅ Verificado en la función y en un trigger del historial. Prueba |
| FS6-6 | BAJA | Archivos temporales parciales podían quedar si el renderizado o la auditoría fallaban | ✅ Borrado en fallo y barrido de más de 1 h |
| FS6-7 | BAJA | La vista de reportes no tenía candado (DoS por repetición) | ✅ Una generación a la vez por usuario también en la vista |
| FS6-8 | BAJA | «No identificado» masivo aceptaba CONFLICTO/AMBIGUO | ✅ Nunca en bloque. Prueba |
| FS6-9 | BAJA | La rotación podía declararse completa con filas bloqueadas | ✅ Recuento final. Prueba |
| FS6-10 | INFO | Seudónimo de CLABE en la vista de agrupación | Riesgo aceptado (§2g) |

### Revisión de código

| # | Sev. | Hallazgo | Estado |
|---|---|---|---|
| FC6-1 | ALTA (posible) | Interbloqueo entre la ejecución masiva (filas y después candado) y el worker (candado y después filas) | ✅ La ejecución toma primero el candado global; 40P01/42501 se informan sin 500 |
| FC6-2 | ALTA | La consistencia de la misma CLABE sólo se revisaba dentro de la selección | ✅ Todos los pendientes del grupo. Prueba |
| FC6-3 | ALTA (posible) | Desactivar una relación no impedía que la CLABE la reasociara | ✅ Desactiva la cuenta y exige relación ACTIVA. Prueba |
| FC6-4 | MEDIA | El comparativo podía citar una decisión posterior a la ejecución B | ✅ Historial acotado a `terminada_at` de B |
| FC6-5 | MEDIA | Los cambios de identidad contaban cuentas y relaciones de otros lotes | ✅ Filtrados por lote |
| FC6-6 | MEDIA | Conteos de la previsualización (beneficiarios nuevos por fila) y `cuenta_id` en el desglose | ✅ Por CLABE; cuenta de la transacción |
| FC6-7 | MEDIA | Total bancario con bancos sin póliza | ✅ Diferencia total sólo con póliza |
| FC6-8 | MEDIA | Sólo el nombre del beneficiario recibía confianza MEDIA; la unicidad del importe ignoraba otros pendientes | ✅ BAJA; unicidad contra todos los pendientes del lote |
| FC6-9 | BAJA | Conteos del tablero incoherentes con la bandeja; enlace a reportes sin permiso | ✅ |
| FC6-10 | BAJA | Evidencia de sugerencias no refrescada; vigencia no cerrada; «última ejecución» con una en curso | ✅ |

## 4g. Hallazgos de las revisiones de la FASE 7 y su estado

Hubo revisiones independientes de sólo lectura (código y seguridad ofensiva), pruebas dinámicas (`make security-smoke`, 103 comprobaciones) y la validación con archivos reales (fuera del repositorio, sólo agregados). **Resultado final: 0 CRITICAL, 0 HIGH abiertos.** Todos los hallazgos MEDIUM están corregidos; los LOW, corregidos o justificados.

### Revisión de código (21)

| # | Sev. | Hallazgo | Estado |
|---|---|---|---|
| FC7-1 | ALTA | Con huellas sin cifrado ni correspondencia, la CLI nunca llegaba al corte (`--descartar-sin-cifrado` inalcanzable) | ✅ `prepare()` informa lo irrecuperable; la CLI pasa al corte, que exige decidir. Prueba |
| FC7-2 | ALTA | Tras una fusión, un pago con el número del canónico y la cuenta del alias caía en CONFLICTO | ✅ Comparación por identidad canónica en el worker. Prueba |
| FC7-3 | MEDIA | Confirmar la cuenta de un alias fallaba («cuenta de otra persona») | ✅ `fn_empleado_canonico` en `fn_confirmar_cuenta` y `fn_confirmar_beneficiario`. Prueba |
| FC7-4 | MEDIA | Interbloqueo posible entre asociar + confirmar cuenta y el corte HMAC | ✅ Candado global primero en la web; `lock_timeout` en el corte |
| FC7-5 | MEDIA | Huellas de registro no comparables tras una rotación (detección de versión y comparación mostraban 0) | ✅ Se marcan «no comparables» con indicación de reanalizar (riesgo residual aceptado: la versión de un archivo anterior a la rotación se detecta tras reanalizarlo) |
| FC7-6 | MEDIA | Separar en medio de una cadena podía dejar un alias con quien no le corresponde | ✅ `fn_fusion_recalcular` (raíz de la cadena vigente). Prueba |
| FC7-7 | MEDIA | Runner de reportes sin latido ni cercado; un error podía pisar un reporte DISPONIBLE | ✅ Latidos, verificación del token antes de escribir, guardas de estado; el reaper no recupera los trabajos PHP por latido |
| FC7-8 | MEDIA | En la ficha del canónico, revelar o desactivar cuentas del alias daba 404; incidencias y auditoría sin alias | ✅ Consultas por identidad canónica |
| FC7-9 | MEDIA | Sugerencias anteriores a una fusión nombraban al alias y hacían fallar la revisión masiva | ✅ Candidatos resueltos al canónico |
| FC7-10 | MEDIA | La detección de duplicados en cada carga recorría toda la base bajo el candado global (una carga de 2 filas tardó 16 s en la validación real) | ✅ Detección incremental (sólo empleados de la carga); topes a homónimos e incidencias |
| FC7-11 | MEDIA | El `down` de 0018 revocaba permisos anteriores del worker | ✅ Sólo revoca lo del rol del scheduler |
| FC7-12…21 | BAJA | Detector sin alias; fallos consecutivos al reactivar; una fila inconsistente detiene la rotación; evidencia de cuenta sin canónico; carrera descarga/limpieza; auditoría de fusión fuera de la transacción; separaciones sin explicar en el comparativo; huecos silenciosos del scheduler; documentación; costo del trigger por fila | ✅ Corregidos, salvo dos justificados: la fila inconsistente se detiene **a propósito** (nunca se omite) y el detector evalúa canónicos (sus alias ya están fusionados) |

### Revisión de seguridad (sin CRITICAL ni HIGH)

| # | Sev. | Hallazgo | Estado |
|---|---|---|---|
| FS7-1 | MEDIA | El worker (lee Excel no confiables) compartía rol con el scheduler: podría marcar como DISPONIBLE un archivo propio o enumerar permisos | ✅ Rol propio `conciliarh_scheduler`; el worker no escribe reportes ni lee usuarios; trigger de consumidor |
| FS7-2 | MEDIA | El reaper podía duplicar la generación y un reporte listo terminar en ERROR | ✅ Igual que FC7-7 |
| FS7-3 | MEDIA | La cuota de programaciones se evadía pausando y reactivando; «Generar ahora» sin límite | ✅ Cuota en la BD (al crear y reactivar), límite por hora, cuota de almacenamiento. Prueba |
| FS7-4 | MEDIA | Detección de duplicados encolable sin límite, bajo el candado global | ✅ Una a la vez y como máximo cada 10 min; topes. Prueba |
| FS7-5 | MEDIA | ADMIN podía aceptar conflictos graves (RFC/CURP distintos) | ✅ `fusionar_conflictos` sólo GOD por omisión (asignable a un rol autorizado). Desde la FASE 8 la fusión grave exige además la aprobación de otra persona; desde 0026 ADMIN puede solicitarla |
| FS7-6 | BAJA | Reportes vencidos descargables hasta la limpieza | ✅ `expira_at` verificado al descargar |
| FS7-7 | BAJA | Falsa alarma de alteración por carrera con la limpieza | ✅ Estado releído bajo el candado |
| FS7-8 | BAJA | Copia sin verificar escrituras; un error de E/S marcaba ALTERADO | ✅ Se verifica la copia; E/S = error del servidor |
| FS7-9 | BAJA | Un administrador podía redefinir una programación ajena (generada con permisos del dueño) o descargar contenido construido con permisos que él no tiene | ✅ Definición sólo del dueño (PHP + BD); `permisos_requeridos`. Prueba |
| FS7-10 | BAJA | La BD sólo verificaba la sensibilidad al activarla | ✅ Siempre que el dueño guarda una programación sensible |
| FS7-11 | BAJA | Reportes sensibles guardados hasta 90 días | ✅ Retención fija de 30 días para sensibles (riesgo residual documentado: los respaldos los incluyen) |
| FS7-12 | BAJA | Sin valor de verificación por llave HMAC (un secreto equivocado escribiría huellas inservibles) | ✅ KCV por llave y secretos de 64 hex. Prueba |
| FS7-13 | BAJA | La web y el scheduler recibían secretos que no usan | ✅ Retirados de la web; documentado el entorno mínimo del scheduler |
| FS7-14 | BAJA | `fn_fusion_analisis` sin verificación de permiso | ✅ Exige `empleados.fusionar` o `empleados.duplicados`. Prueba |
| FS7-15 | BAJA | Traslado por posición con clave débil | ✅ Además del importe y los últimos 4; nunca sin persona |
| FS7-16 | BAJA | Separación en cadena | ✅ Igual que FC7-6 |
| FS7-17…20 | INFO | Diseño criptográfico correcto; ids de llaves visibles a la web (sin secretos); llaves retirables sin considerar huellas de registro; triggers sin permiso para descartar o para ALTERADO→DISPONIBLE | ✅ Documentado; descartar exige el permiso en la BD y ALTERADO sólo pasa a EXPIRADO |

### Encontrados por las pruebas y la validación

| # | Hallazgo | Estado |
|---|---|---|
| FV7-1 | Buscar duplicados con un apóstrofo daba 500: `ESCAPE '\'` confundía el parser de parámetros de PDO | ✅ Carácter de escape `!`. Prueba de SQL injection |
| FV7-2 | El candado de descarga quedaba tomado en una conexión reutilizada y la limpieza veía el archivo «en uso» | ✅ El candado cubre sólo la copia verificada |
| FV7-3 | Clave de idempotencia `reporte:<id>` repetida tras reconstruir tablas | ✅ Clave única en el tiempo (la idempotencia real la dan los índices únicos) |
| FV7-4 | La auditoría enmascara secuencias largas de dígitos dentro de las huellas SHA-256 | Comportamiento correcto (protección de cuentas); la huella completa vive en `reportes_generados` |

## 4h. Hallazgos de las revisiones de la FASE 8 y su estado

Alcance: notificaciones (0019), doble aprobación de fusiones (0020), cifrado de reportes (0021), revisión de pensiones (0022), salud del sistema, respaldo y restauración, y la nueva interfaz.

### Revisión de seguridad del backend (0 CRITICAL, 0 HIGH)

| Sev. | Hallazgo | Estado |
|---|---|---|
| MEDIUM | Borrar o editar a mano RFC, CURP o ISSEMYM antes de fusionar ocultaba el conflicto grave y evitaba la doble firma | Corregido: `IDENTIFICADOR_EDITADO` (`fn_fusion_conflictos_extra`) cuenta como grave |
| LOW | Un ADMIN podía asignarse el rol APROBADOR y aprobar solicitudes de otros | Corregido: roles protegidos (ADMIN, APROBADOR) sólo los asigna un actor GOD, también en la BD (trigger). Desde 0026 el rol APROBADOR ya no existe |
| LOW | La aprobación se aplicaba aunque el solicitante hubiera perdido el permiso | Corregido: la solicitud pasa a CANCELADA y se notifica |
| LOW | `fn_sistema_ejecucion` aceptaba cualquier componente desde la web | Corregido: desde la web sólo la revisión de almacenamiento, con `sistema.mantenimiento` |
| LOW | «Dejar pendiente» podía escribirse directamente en la tabla | Corregido: sólo mediante `fn_revision_dejar_pendiente` (la web tiene SELECT) |
| LOW | La exención de resúmenes hex al enmascarar se decidía por el valor y podía abusarse para filtrar números | Corregido: exención sólo por nombre de clave (`Logger::isDigest`) |
| LOW | El respaldo dejaba el volcado en claro en disco antes de cifrar, y la restauración no verificaba autoría | Corregido: cifrado GPG en flujo, firma separada de SHA256SUMS verificada antes de descifrar, restauración rechazada como superusuario, sin búsqueda de llaves en red |
| INFO (5) | Límites de descarga, KCV, AAD con id de reporte, URL relativas en notificaciones, `/api/health` mínima | Verificados; sin cambio |

**Verificaciones ofensivas de cifrado** (`ReportCipherTest`, `ScheduledReportsHttpTest`, security-smoke):
- byte alterado, etiqueta y nonce alterados, truncado, basura final;
- archivo de otro reporte, llave equivocada, id de llave cambiado, reporte de otro usuario, ruta con `..`.

En ninguno de esos casos se entrega contenido. No hay claro en logs ni en la auditoría.

### Revisión de código del backend (0 HIGH; 6 MEDIUM, 17 LOW corregidos o justificados)

- Orden de candados en la solicitud crítica.
- El aviso de asociaciones sale sólo si aumentan los pendientes.
- `fsync` y borrado verificado en la generación y la rotación de reportes.
- La descarga cifrada no mezcla un error con la respuesta después de enviar cabeceras.
- Ids de llave en minúsculas y sin duplicados.
- Mensajes de cancelación correctos.
- `restore.sh` no oculta fallas de `psql`.
- Pruebas de RLS sin falsos positivos y prueba del disparador en una transacción revertida.

### Interfaz

- **CSP:** sin estilos, scripts ni manejadores en línea. `UiContractHttpTest` lo exige en todas las pantallas principales (`mainRoutes`).
- **Notificaciones:** se arman con `textContent`, sin HTML desde datos. Sus URL son relativas (CHECK en la BD contra `//host`) y no llevan RFC, CURP, CLABE ni cuentas.
- **Enlaces:** todo enlace interno de las pantallas principales abre para GOD (`testNoBrokenInternalLinksOnMainScreens`). Lo que se oculta en el menú también se rechaza en el servidor (403).

### Revisiones finales de la interfaz (UI de la FASE 8)

**Seguridad** (0 CRITICAL, 0 HIGH, 0 MEDIUM):

| Sev. | Hallazgo | Estado |
|---|---|---|
| LOW | «Pendiente de mi atención» mostraba conteos con enlace a pantallas que el rol no puede abrir | Corregido: cada tarea exige también el permiso de la pantalla enlazada |
| LOW | Las listas de módulos y entidades de `/auditoria` recorrían toda la bitácora en cada carga | Corregido: salto de índice (CTE recursiva sobre los índices existentes) |
| INFO | Búsqueda de variante del modal sobre un objeto con prototipo | Corregido: `Object.hasOwn` |

**Código** (1 HIGH, 3 MEDIUM, 7 LOW, todos corregidos):

| Sev. | Hallazgo | Estado |
|---|---|---|
| HIGH | El buscador de la matriz de permisos (Enter, limpiar, Esc) enviaba el formulario de guardar permisos | Corregido: buscador sin formulario dueño (`form="perm-filtro"`), Enter bloqueado; limpiar sólo envía formularios GET |
| MEDIUM | Canal de importación: un error del análisis aparecía en «Verificado»; el reanálisis y el anulado se leían mal; la revisión manual se mostraba como error | Corregido: lógica pura `pipelineSteps` con estados `warning` y `stopped`, probada estado por estado |
| MEDIUM | Recorridos completos de la auditoría | Ver arriba |
| LOW | «Fallido» incluía decisiones exitosas (rechazar una sugerencia) | Corregido: lista exacta `AuditRepository::FALLOS`, verificada contra el código |
| LOW | Texto «Marcar/Quitar todos» calculado sobre otro conjunto que la acción | Corregido |
| LOW | Buscador y botones «ocupados» persistían al volver con «Atrás» | Corregido: limpieza en `pageshow` (sólo lo que desactivó el JS) |
| LOW | Migas con enlaces a periodos o lotes sin permiso | Corregido: sin enlace si el rol no puede abrirlo |
| LOW | Iniciales con nombres en NFD | Corregido: normalización NFC |
| LOW | «Filtros activos» contaba valores inválidos | Corregido: fechas y listas validadas en el controlador |
| LOW | Recorrido de enlaces aceptaba redirecciones y sólo como GOD | Corregido: sólo 200, también como CONSULTA. Encontró y se corrigió un aviso PHP en el detalle de auditoría |

## 4i. Hallazgos de las revisiones de la FASE 9 y su estado

Alcance: Release Candidate 0.1.0-rc.1. Paquete (`scripts/build-release.sh`), `deploy/` (usuarios del SO, systemd, Apache, PHP-FPM, PostgreSQL, logrotate), `bin/preflight.php`, `bin/maintenance.php`, migración 0023, respaldo y restauración, y pentest sobre una instalación real (la suite de pruebas, *Inventario FASE 9*).

### Auditoría de seguridad de PostgreSQL (1 HIGH, 6 MEDIUM; corregidos en 0023)

| Sev. | Hallazgo | Estado |
|---|---|---|
| HIGH | H-1: un servicio (worker, scheduler) podía insertar auditoría a nombre de una persona | Corregido: `fn_auditoria_actor` sólo admite origen WORKER sin usuario ni roles para los servicios |
| MEDIUM | El worker podía fabricar confirmaciones manuales (cuentas, beneficiarios) o atribuir decisiones a personas | Corregido: triggers `*_a_servicio`; sólo traslada una decisión manual idéntica del mismo archivo |
| MEDIUM | El worker podía reescribir el cifrado de cuentas confirmadas y del staging | Corregido: `REVOKE UPDATE (cuenta_cifrada, key_id …)` |
| MEDIUM | RBAC sólo validado en PHP | Corregido: `fn_rbac_web` en `roles`, `rol_permiso` y `usuario_rol`; filas de GOD protegidas |
| MEDIUM | Credenciales y estado de una cuenta GOD modificables desde la web por otro actor; cambios de credenciales auditados sólo por PHP | Corregido: `fn_usuario_god_protegido` y `fn_usuario_credenciales_auditar` (la BD audita) |
| MEDIUM | Configuración sin validar en la BD | Corregido: `fn_configuracion_validar` (permiso, exclusividad GOD, tipo y rango) |
| MEDIUM | `pg_dump` con un rol sin `BYPASSRLS` fallaba en la tabla con RLS (`notificaciones`) | Corregido: rol `conciliarh_backup` de sólo lectura con `BYPASSRLS` y sin escritura (`deploy/postgres/01-roles-y-base.sql`); documentación con ese rol |
| LOW (7) e INFO | Funciones expuestas a la web sin comprobar permiso; cola y registro de ejecuciones sin restricción por rol; `EXECUTE` de PUBLIC en funciones de trigger; `search_path` variable; `deleted_by` sin verificar; esquema `public` abierto | Corregido: permisos en `fn_crear_lote`, `fn_importaciones_con_cuenta` y `fn_fusion_conflictos_extra`; `fn_procesos_web`, `fn_procesos_consumidor` y `fn_sistema_ejecucion` por rol; el resto, higiene |

Detalle en `docs/DATABASE.md` §4i.

### Revisión de seguridad final independiente (0 CRITICAL, 0 HIGH)

| Sev. | Hallazgo | Estado |
|---|---|---|
| MEDIUM | M-1: un ADMIN podía restablecer la contraseña de (o desactivar) una cuenta con permisos que él no tiene y tomarla | Corregido: `UserService::canManage` exige que la cuenta no tenga permisos que el actor no tenga (salvo GOD) |
| MEDIUM | M-2: los comandos con credenciales del propietario corrían con el mismo UID que un servicio, que podía leer su entorno en `/proc` | Corregido: usuario `conciliarh-admin` sólo para esos comandos y `ProtectProc=invisible` |
| MEDIUM | M-3: el worker podía reasignar a otra persona una decisión humana en `registros_rrhh`/`registros_tesoreria` | Corregido: sólo puede pasarla a CONFLICTO sin persona o trasladar una idéntica dentro del mismo archivo (0023) |
| LOW | Logs compartidos entre procesos | Corregido: un archivo por proceso (`{canal}.log`, `{canal}-cli-{usuario}.log`, `python.log`), `0640`, directorio `3770` con *sticky* |
| LOW | PHP-FPM creaba archivos legibles por todos | Corregido: *drop-in* con `UMask=0027` |
| LOW | `disable_functions` incompleto | Corregido: agrega `mail`, `mb_send_mail` y `putenv` |
| LOW | La restauración aceptaba la firma de cualquier llave del llavero | Corregido: `RESTORE_SIGNER_FPR` (con `RESTORE_REQUIRE_SIGNATURE=1`) |
| LOW | Contraseñas de roles de BD en la línea de órdenes | Corregido: se fijan con `\password` en `psql` |
| LOW | Python aceptaba ids de llave duplicados | Corregido: se rechazan |

### Revisión de código final (0 HIGH)

MEDIUM corregidos:
- rotación de `python.log` (`WatchedFileHandler` y `copytruncate`);
- índice para el trigger de `asociado_por` (`registros_*_manual_idx`);
- contraseña del servidor de prueba versionada por error en la rama: purgada de su historial antes del merge (sin remoto);
- guarda de filas manuales en el worker;
- guarda contra historial MANUAL falsificado en `empleado_historial`.

También se reforzaron pruebas débiles (caso `TRASLADO`, aislamiento del entorno en `test_fase9`, comprobación del scheduler en `release_fallas`, referencia en `release_e2e`) y el worker lleva `RestartPreventExitStatus=2` (configuración incompleta: no reinicia en bucle).

### Riesgos residuales aceptados

| Id | Riesgo | Por qué se acepta | Mitigación y monitoreo |
|---|---|---|---|
| L-1 | `fn_sesion_vincular` se puede invocar con las credenciales web | Inherente al diseño: la web liga la sesión tras autenticar; quien tiene las credenciales web ya controla la aplicación | Alertar en la auditoría por `sesion_vinculada` sin login exitoso previo |
| L-6 | Denegación por bloqueo de login desde pocas IP | El umbral global (20 por usuario desde cualquier IP) protege contra fuerza bruta distribuida | Límite de peticiones en el proxy inverso; monitorear ráfagas en `security.log` |
| L-7 | Los límites por auditoría (`Throttle`) son aproximados con peticiones en paralelo | El exceso está acotado por la concurrencia | Límite de conexiones en el proxy; revisión de la auditoría |
| L-9 | El worker tiene `UPDATE` en todas las columnas de ejecución de `procesos` | Lo necesita para el ciclo de vida de sus trabajos | `fn_procesos_consumidor` restringe los tipos que puede tomar (no reportes) |
| — | `scripts/backup.sh` genera un respaldo **sin cifrar** si `BACKUP_GPG_RECIPIENT` está vacío (el manifiesto registra `cifrado_gpg: false`) | Útil en desarrollo y pruebas | **Corregido en producción:** `deploy/env/backup.env.example` trae `BACKUP_REQUIRE_ENCRYPTION=1` y con ella el respaldo falla sin destinatario ni firmante (la salud muestra el respaldo en ERROR) |
| — | `preflight` no valida `worker.env` ni `scheduler.env` | Cada servicio valida su configuración al arrancar y sale con 2 | `systemctl status`, salud (worker sin latido, scheduler sin ejecución) |
| — | Los timers de verificación de almacenamiento y respaldo siguen corriendo en mantenimiento | Son de sólo lectura y conviene que sigan | Ninguna |
| — | Los valores `booleano` de la configuración sólo se validan por tipo | No hay rangos que validar | — |

## 4j. Hallazgos de la revisión de la FASE 10A (rc.2) y su estado

Revisión independiente del diff rc.1 → rc.2 (recuperación de emergencia, ancla, `conciliarh-run`, inventario, orden determinista y sus pruebas). **0 CRITICAL, 0 HIGH.**

| Sev. | Hallazgo | Estado |
|---|---|---|
| MEDIUM | `status` no veía el bloqueo habitual (5 fallos desde un origen), sólo el global | Corregido: se calcula el bloqueo desde cualquier origen |
| MEDIUM | `repair` devolvía el rol GOD a una cuenta cuya contraseña pudo cambiar un tercero mientras estuvo sin el rol | Corregido: quita otros roles, revoca sesiones e inutiliza la contraseña; nombra la cuenta antes de confirmar |
| MEDIUM | Una prueba de desbloqueo pasaba aunque no desbloqueara | Corregida: comprueba el mismo origen y el estado antes y después |
| LOW | La cuenta confirmada podía no ser la operada si el estado cambiaba entre ambos pasos | Corregido: la operación exige la cuenta confirmada con los candados tomados |
| LOW | Si no se podía apagar el eco, la contraseña se mostraba | Corregido: no se pide; el eco se restituye ante una señal |
| LOW | El registro de auditoría mostraba un campo redactado por su nombre | Corregido: `cambio_obligatorio` |
| LOW | Las cuentas de desarrollo con rol GOD no se consideraban | Corregido: se reportan y bloquean `reactivate` y `repair` |
| LOW | `conciliarh-run` sólo comprobaba dos directorios | Corregido: todo `bin`, `app`, `config` y `vendor` |
| LOW | Un argumento desconocido (por ejemplo una contraseña) llegaba al journal antes de ser rechazado | Corregido: se rechaza antes de lanzar el proceso |
| LOW | El inventario podía sobrescribir un archivo, consultaba `pg_isready` y `composer`, e incluía el identificador de máquina | Corregido |
| LOW | `audit-anchor --verificar` no comprobaba la cadena | Corregido: exige ambas cosas |
| LOW | Tres pruebas con aserciones débiles | Corregidas |
| INFO | El orden decide quién registra una cuenta compartida por dos pagos del mismo archivo | Documentado (la suite de pruebas); ahora es determinista y lo cubre la prueba de reproducibilidad |

**Riesgos residuales aceptados (rc.2):**
- el motivo y el usuario de `god-recovery` son argumentos y quedan en el journal y en el registro de `sudo`: no deben contener datos sensibles;
- el operador que invocó `sudo` es un dato declarativo;
- quien tiene root en el servidor puede modificar la base por otros medios; el ancla externa y la bitácora son el control.

## 5. Mantenimiento técnico de la auditoría (sólo GOD, excepcional)

Los roles de aplicación **no pueden** modificar la auditoría. Para una intervención técnica imprescindible, por ejemplo una depuración aprobada, el propietario del esquema debe:

1. Registrar primero la intervención (acción `auditoria.administrar`).
2. Ejecutar en **una transacción** `SET LOCAL conciliarh.auditoria_mantenimiento = 'on'` y luego la operación.
3. Ejecutar `fn_auditoria_verificar()` y documentar el resultado.

Una función dedicada con doble registro sigue pendiente (ver §6). Cualquier alteración queda **detectable** por la cadena de hash, salvo la eliminación completa de los registros más antiguos: el primer registro existente se toma como ancla.

## 5b. Ancla externa de la auditoría (FASE 10)

`conciliarh-run audit-anchor` imprime el hash y la secuencia del último registro, la versión y la fecha. Es de sólo lectura (`default_transaction_read_only`), corre con el rol web, no contiene datos personales y no hace llamadas de red. El operador lo sella fuera del servidor. Con el ancla, una reescritura completa de la cadena también es detectable: `audit-anchor --verificar` compara el registro de esa secuencia con el hash guardado. Procedimiento en `OPERATIONS_RUNBOOK.md` §11.

## 5c. Recuperación de emergencia de la cuenta GOD (FASE 10)

Detalle en `docs/BREAK_GLASS_RECOVERY.md`. Modelo de amenazas:

| Amenaza | Control |
|---|---|
| Uso por un usuario de servicio o sin privilegios | `conciliarh-run` exige root; las credenciales del propietario están en `cli.env` (0600 root); sin ellas el programa sale con 2 |
| Invocación desde la web | No hay ruta; `bin/` fuera del directorio servido; `proc_open` deshabilitado en PHP-FPM; la BD rechaza al rol web (`fn_usuario_god_protegido`, `fn_auditoria_actor`) |
| Abuso de `sudo` para ejecutar otro código | Lista cerrada de comandos; argumentos como arreglo (sin shell); `PATH` fijo; todo el código de la instalación (`bin`, `app`, `config`, `vendor`) debe ser de root y sin escritura para otros |
| Inyección de comandos o argumentos | Análisis estricto: una operación y opciones conocidas con formato validado; SQL parametrizado |
| Contraseña expuesta | Sólo por terminal, sin eco (si no se puede ocultar, no se pide); nunca como argumento: `conciliarh-run` rechaza argumentos desconocidos antes de lanzar el proceso, para que no lleguen al journal; no se registra ni la contraseña ni el hash; `ProtectProc=invisible` |
| Manipulación del entorno | `systemd-run` no hereda el entorno de quien invoca; en producción no hay modo no interactivo; todo `APP_ENV` distinto de `development` o `testing` cuenta como producción |
| `PATH` manipulado | `stty` por ruta absoluta; `conciliarh-run` fija su `PATH` |
| Operar sobre otro usuario o crear un segundo GOD | El servicio sólo selecciona cuentas con el rol GOD; `reactivate` se niega si hay otra activa; `repair` sólo devuelve el rol a la cuenta original |
| Operar sobre una cuenta distinta de la confirmada | Cada operación recibe el usuario confirmado y lo vuelve a exigir con los candados tomados |
| `repair` promueve a quien alteró la cuenta mientras estuvo sin el rol | Al devolver el rol se quitan los demás roles, se revocan las sesiones y la contraseña queda inutilizada hasta un `reset-password` |
| Uso sin rastro | Auditoría en la misma transacción (`emergencia.god_*`, `SYSTEM_BREAK_GLASS`), más el registro que hace la propia BD |
| Dos recuperaciones a la vez | Candado compartido con el alta de GOD y bloqueo de la fila |

Riesgo residual aceptado: quien tiene root en el servidor puede modificar la base de datos por otros medios. El comando no cambia ese hecho; lo encauza y lo registra.

## 6. Pendientes de seguridad (fases posteriores)

- **Resuelto en la FASE 8:** cifrado en reposo de reportes sensibles, aprobación por dos personas de fusiones graves y notificaciones de reportes (§2i).
- **La rotación HMAC** quedó implementada en la FASE 7 (§2h).
- **Resuelto en la FASE 9** (`deploy/`, `docs/DEPLOYMENT.md`), por verificar en el servidor institucional:
  - `MemoryMax`/`CPUQuota` del worker (2G / 200 %) y `MemoryMax` del scheduler;
  - usuarios del SO por servicio y grupo `conciliarh-data` para leer los originales 0440; verificación diaria de almacenamiento con `conciliarh-storage-check.timer` (en lugar de `cron`);
  - HSTS, `mod_remoteip` (sólo detrás de un proxy, con `RemoteIPInternalProxy`), `LimitRequest*`, endurecimiento de PHP-FPM, Apache y systemd;
  - límites por auditoría en acciones costosas y sensibles (§4i);
  - revisión OWASP completa con los módulos de negocio y pentest sobre una instalación real (§4i).
- **Resuelto en la FASE 10 (rc.2):** ancla externa de la auditoría (§5b), recuperación de emergencia de la cuenta GOD (§5c), orden determinista del procesamiento (la suite de pruebas), `log_error_verbosity = terse` en `DEPLOYMENT.md` §3.3.
- **FASE 10, por hacer en el servidor real:**
  - límite de peticiones en Apache o en el proxy inverso (riesgos L-6 y L-7, §4i);
  - función de mantenimiento de auditoría con doble registro (§5);
  - respaldo sin cifrar posible si falta `BACKUP_GPG_RECIPIENT` (§4i): considerar que `backup.sh` falle con `APP_ENV=production`.

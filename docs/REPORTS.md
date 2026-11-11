# Reportes y exportaciones (FASE 6)

Los reportes **sólo leen** los resultados persistidos por el motor de conciliación: PHP nunca recalcula dinero.
- Cada reporte corresponde a **un** cotejo (conciliación #id, n.º N) de una quincena.
- Se muestran en pantalla (`GET /reportes/ver`) y se exportan a XLSX, CSV o PDF (`POST /reportes/exportar`, con CSRF, permiso y auditoría).
- El lenguaje es objetivo: describe hechos y nunca califica conductas.

## La pantalla «Reportes»

`/reportes` (sección «Consulta» del menú) es **un solo formulario GET** hacia `/reportes/ver`:

1. **Cotejo:** una lista con el cotejo más reciente de cada quincena primero («quincena · cotejo n.º N · estado», y «provisional» si el cotejo todavía no es el oficial).
2. **Resumen de la quincena:** botón `tipo=resumen`.
3. **Lista de personas:** botón `tipo=lista` con una lista `lista` que elige cuáles personas: `empleados` (todas), `diferencias`, `no_pagados`, `pagados_mas`, `pagados_menos` y `pendientes`. `ReportController::params()` traduce `tipo=lista` al reporte elegido; **cualquier otro valor de `lista` responde 404** (por ejemplo `lista=resumen`).
4. **Más reportes:** botones `tipo=pensiones`, `grupos`, `bancos` e `incidencias`.

La pantalla sólo ofrece lo que la persona puede abrir:
- la opción «Pagos pendientes de identificar» (`pendientes`) aparece sólo con `asociaciones.ver`;
- «Incidencias» aparece en «Más reportes» sólo con `incidencias.ver`.

Ocultarlas no es la protección: abrir la dirección sin el permiso responde 403 igualmente.

En la pantalla de un reporte:
- el encabezado dice «quincena · cotejo n.º N · estado»;
- un cotejo que todavía no es el oficial muestra el aviso «Resultado provisional: el cotejo no es todavía el oficial de la quincena.»;
- el sello de las exportaciones y del PDF no cambia: «LOTE NO DECLARADO OFICIAL».

Las pantallas de reportes programados, reportes generados e incidencias de identidad están en la sección «Avanzado» del menú.

Pruebas: `ReportsHttpTest::testReportScreenListsAndOptionsFollowPermissions` (lista `no_pagados` → 200 con el título «Personas no pagadas»; `lista=resumen` y otros valores → 404; opciones ocultas sin permiso).

## Catálogo

| Clave | Título en pantalla | Contenido | Formatos |
|---|---|---|---|
| `resumen` | Resumen de la quincena | Cifras principales, % conciliado (con su definición), Policías y Bomberos, bancos, pensiones, personas por estado e incidencias | pantalla · XLSX · CSV · **PDF** |
| `empleados` | Lista de personas | Una fila por persona: neto RRHH, pagos por banco, total, diferencia, estado y alerta | pantalla · XLSX · CSV |
| `diferencias` | Personas con diferencias | Todo lo distinto de CORRECTO o NO APLICA | pantalla · XLSX · CSV |
| `no_pagados` / `pagados_mas` / `pagados_menos` | Personas no pagadas / pagadas de más / pagadas de menos | NO_PAGADO / PAGO_MAYOR + POSIBLE_PAGO_DUPLICADO / PAGO_MENOR | pantalla · XLSX · CSV |
| `pendientes` | Pagos pendientes de identificar | Pagos sin persona identificada (cuentan en el banco y nunca se asignan) y personas cuyo faltante explicaría uno de ellos; exige `asociaciones.ver` | pantalla · XLSX · CSV |
| `pensiones` | Pensiones alimenticias | Retenido, dispersado, identificado, pendiente, no dispersado y diferencia por grupo, más el detalle por persona | pantalla · XLSX · CSV · **PDF** |
| `bancos` | Por banco | Póliza de egreso (hoja relacionada), dispersión, identificado, pendiente y diferencia | pantalla · XLSX · CSV · **PDF** |
| `grupos` | Policías y Bomberos | RRHH contra Tesorería por grupo: totales, diferencia, correctos, pendientes, no pagados, menores y mayores | pantalla · XLSX · CSV · **PDF** |
| `incidencias` | Incidencias | Incidencias con su gestión; exige `incidencias.ver` | pantalla · XLSX · CSV · **PDF** |
| `comparativo` | Comparativo entre cotejos | Cambios entre dos cotejos de la misma quincena y su motivo según la trazabilidad. Se abre desde el cotejo, con «Comparar con» | pantalla · XLSX · CSV |

## XLSX (OpenSpout 4, MIT)

- Genera **una hoja por sección**. Cada hoja lleva:
  - un encabezado institucional: institución, ConciliaRH, reporte, periodo, lote (oficial o no), ejecución, estado, fecha, usuario, motor, tolerancia y huella;
  - los encabezados de la tabla con la paleta institucional y **congelados**;
  - anchos de columna razonables;
  - importes como **números** con formato `#,##0.00`;
  - una fila de totales resaltada.
- Sin macros.
- Se escribe **en streaming** a un archivo temporal, con memoria acotada.
- **Inyección de fórmulas:** `Cell::fromValue()` de OpenSpout convierte en **fórmula** cualquier texto que empiece con `=`. Por eso nunca se usa, ni tampoco `Row::fromValues`:
  - se crean siempre `StringCell` y `NumericCell` explícitas;
  - todo texto que empiece con `= + - @ TAB CR` se neutraliza con un apóstrofo (`Exporter::text`);
  - un importe negativo sigue siendo número.

## CSV

- **Codificación y formato:** UTF-8 con BOM (Excel lo abre bien), delimitador **coma**, comillas RFC 4180 con duplicación y **sin carácter de escape** (la diagonal permitía cerrar un campo y abrir otro), fin de línea **CRLF**.
- Lleva las mismas líneas de encabezado institucional, cada sección con su título y los totales al final.
- La misma neutralización de fórmulas que el XLSX.

## PDF (dompdf 3.1, LGPL-2.1)

Sirve para el resumen ejecutivo, las incidencias, bancos, grupos y pensiones. No se usa para miles de filas: muestra hasta 400 por sección y remite a XLSX/CSV.

**Contenido:**
- Formato carta horizontal con la paleta institucional (`#691C32`, `#BC955C`, `#ECD798`) y el logo institucional embebido.
- ConciliaRH, «Sistema Integral de Conciliación de Nómina y Pagos», periodo, lote, fecha y estado.
- **Sello:** «CONCILIACIÓN CERRADA», «EN REVISIÓN» o «LOTE NO DECLARADO OFICIAL». Un lote no oficial además lleva un aviso de que el resultado no es definitivo.
- **Auditable:** id de la conciliación, número de ejecución, versión del motor, fecha y hora, usuario generador, **huella SHA-256 del contenido** y huella de entradas de la conciliación, en cada página y al final. El mismo reporte de la misma ejecución produce la misma huella de contenido; la huella no revela secretos.

**Seguridad (sin SSRF ni LFI):**
- `isRemoteEnabled=false`: sin `http(s)://` ni `ftp://`.
- `allowedProtocols` sólo admite `data://`, así que ni `file://` ni red.
- `chroot` y caché de fuentes en un directorio de trabajo propio.
- Sin PHP ni JavaScript.
- Todo valor se escapa con `e()` en la plantilla.
- El logo va embebido como `data:` (JPEG institucional; no requiere GD).
- Una prueba abre un socket local e intenta `http`, `https`, `ftp`, `file`, `<link>` y `url()`: dompdf no conecta ni lee archivos. El `security-smoke` inyecta una URL con marcador y verifica en el log del servidor que nunca se solicitó.

### Selección de la librería

| Opción | Licencia | Evaluación |
|---|---|---|
| **dompdf 3.1** | LGPL-2.1 | **Elegida.** Renderiza HTML/CSS (reutiliza la plantilla y el escapado de las vistas), permite desactivar los recursos remotos y restringir protocolos y chroot, no requiere extensiones nuevas (DOM y mbstring ya estaban; JPEG sin GD) y se mantiene activamente. Los CVE históricos exigían `isRemoteEnabled` o `isPhpEnabled`, que aquí están apagados. |
| mPDF 8 | GPL-2.0 | Buen soporte tipográfico, pero la licencia GPL impone condiciones de distribución innecesarias y el paquete es más pesado (más de 30 MB con fuentes). |
| TCPDF | LGPL-3.0 | API de bajo nivel y heredada. El proyecto está en modo de mantenimiento a favor de `tc-lib-pdf`. Generaría más código propio de maquetación. |
| Servicio externo | — | **Descartado:** nunca se envía nómina a APIs ni SaaS. |

**Dependencias nuevas:**
- `dompdf/dompdf` 3.1.6, `dompdf/php-font-lib` 1.0.2, `dompdf/php-svg-lib` 1.0.2, `masterminds/html5` 2.11.0, `sabberworm/php-css-parser` 9.5.0.
- `openspout/openspout` 4.32.0.
- `composer audit` no encontró avisos de seguridad conocidos.

## Permisos y datos sensibles

| Permiso | Alcance |
|---|---|
| `reportes.ver` | Ver en pantalla. GOD, ADMIN y CONSULTA. |
| `reportes.exportar` | Exportar sin identificadores (RFC/CURP no se incluyen). GOD y ADMIN. **CONSULTA sólo ve** los reportes en pantalla (mínimo privilegio, igual que en la FASE 5); GOD puede asignarle el permiso si se decide. |
| `reportes.exportar_sensible` | Casilla «RFC/CURP» en los reportes de personas. Exige **también** `empleados.ver_sensible`. GOD y ADMIN. Un rol propio con `reportes.exportar` pero sin este permiso recibe 403 al pedir RFC/CURP (`ReportsHttpTest::testCase15_16PermissionsMaskingCsrfAndLimits`). |

Desde la migración 0026 sólo existen los roles GOD, ADMIN y CONSULTA: el antiguo rol ANALISTA se consolidó en ADMIN.

- Las cuentas y CLABE **nunca** se exportan completas: sólo `••••` y los últimos 4.
- Los reportes no amplían permisos:
  - todos exigen `conciliaciones.ver`;
  - el de incidencias (y la sección de incidencias del resumen) exige `incidencias.ver`;
  - el de pendientes exige `asociaciones.ver`.

## Auditoría, no persistencia y límites

- **Auditoría:** cada exportación registra `reportes.exportar` con reporte, conciliación, número, formato, filas, si fue sensible, huella y bytes. **Nunca el contenido.**
- **No persistencia:**
  - Los archivos se generan **bajo demanda** en `storage/trabajo/reportes` (fuera de `public/`), se transmiten con `Cache-Control: no-store` y se **borran** al terminar, también si el cliente se desconecta.
  - No se guardan PDFs: la huella permite regenerar y verificar.
- **Nombre del archivo:** lo produce el sistema (`conciliarh-<tipo>-c<id>-v<n>-<fecha>.<ext>`) sin ningún dato del usuario. No hay inyección de encabezados ni de rutas: el tipo y el formato son listas blancas.
- **Límites contra saturación:**
  - `reportes.max_por_minuto` (10): se responde 429.
  - Una generación a la vez por usuario, en **vista y exportación**, con candado consultivo: 429.
  - `reportes.max_filas` (50 000).
- **Temporales:**
  - Un archivo parcial se borra si el renderizado o la auditoría fallan (sin auditoría no hay descarga).
  - Cada exportación barre los temporales de más de una hora.

## Reportes programados (FASE 7)

Los mismos reportes pueden programarse (diario, semanal, quincenal, mensual o manual). Se generan en segundo plano con los permisos vigentes del dueño, se guardan fuera de `public/` con SHA-256 y retención configurable, y se descargan sólo por el controlador. Ver `docs/SCHEDULED_REPORTS.md`.

## Cifrado en reposo (FASE 8)

- **Reportes programados con datos de personas** (todos salvo `resumen`, `bancos` y `grupos`, o cualquiera sensible): se guardan cifrados (`*.enc`) y se descifran al transmitirse. Ver `docs/REPORT_ENCRYPTION.md`.
- **Exportación manual:** no se persiste. El temporal vive sólo mientras se transmite.

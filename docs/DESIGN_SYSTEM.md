# Sistema de diseño — ConciliaRH

**ConciliaRH, Sistema Integral de Conciliación de Nómina y Pagos.**

Este documento es la referencia obligatoria para **toda** pantalla nueva o modificada.
- **Prioridad:** funcionalidad y accesibilidad antes que decoración.

## 1. Principios visuales

1. **El dinero se lee primero.** Las cifras usan dígitos tabulares, van alineadas a la derecha y llevan signo explícito. Una diferencia nunca depende sólo del color.
2. **Una pieza protagonista por pantalla.** En el tablero es el **cuadre**: lo que RRHH autorizó frente a lo que Tesorería pagó, a la misma escala; el hueco entre ambas reglas es la diferencia. Todo lo demás es discreto.
3. **El vino es la institución, no el relleno.** Se reserva para la navegación, la acción primaria y el estado «Cerrado». El área de trabajo es neutra; el dorado y la arena se usan en filetes y en resaltes.
4. **La estructura es información.** Bordes, filetes y marcas dicen algo: fila con incidencia, lote provisional, decisión definitiva. No se decora.
5. **Sin rasgos de plantilla:**
   - no hay etiquetas en MAYÚSCULAS ESPACIADAS;
   - no hay iconos decorativos en la esquina de las tarjetas;
   - no hay degradados ni la misma sombra bajo cada caja;
   - no se añade «→» al texto de los botones.
6. **Los textos ayudan a actuar.** El error explica qué pasó y qué hacer. Un estado vacío invita a la siguiente acción. Los botones dicen exactamente lo que hacen («Cerrar conciliación», no «Enviar»).

## 2. Identidad

- **Nombre:** «ConciliaRH», con el subtítulo «Sistema Integral de Conciliación de Nómina y Pagos».
- **Logotipo:** el horizontal multicolor va en el panel del login (sobre vino). El isotipo vino y dorado va en la navegación, en la tarjeta del login y en el favicon.
- **Motivo propio:** las dos reglas del cuadre. Aparecen en el login (animación única, sólo al cargar) y en el tablero (con datos reales).
- **Tono:** institucional, sobrio y directo. Segunda persona formal («Use el usuario…»).

## 3. Paleta

| Rol | Token | Valor | Uso |
|---|---|---|---|
| Vino oficial | `--wine-700` | `#691C32` | Acción primaria, enlaces, «Cerrado», barra RRHH del cuadre |
| Vino navegación | `--color-nav-bg` (`--wine-800`) | `#521628` | Fondo de la navegación lateral y del panel del login |
| Dorado oficial | `--gold-500` | `#BC955C` | Filetes (`--color-rule`), foco, barra «Pagado». **Nunca texto** (2.77:1) |
| Dorado texto | `--gold-700` | `#83632F` | Migas de pan y acentos de texto (5.54:1) |
| Arena oficial | `--sand-500` | `#ECD798` | Selección, texto activo en la navegación |
| Arena claro | `--sand-50` | `#FDFBF3` | Fila con incidencia (`--color-row-attention`), explicaciones |
| Fondo | `--gray-50` | `#F7F6F5` | Fondo de la página |
| Superficie | `--white` | `#FFFFFF` | Tarjetas, tablas, formularios |
| Texto | `--gray-900` | `#1F1B1C` | Texto principal |
| Texto secundario | `--gray-600` | `#645C5E` | Ayudas y metadatos (6.49:1) |
| Éxito | `--success-700` / `-100` | `#17613F` / `#DCEFE4` | Correcto, cuadra |
| Advertencia | `--warning-800` / `-100` | `#7A4F00` / `#FFF1CF` | Pendiente, pagado de más |
| Error | `--danger-700` / `-100` | `#A3201A` / `#FBE3E1` | No pagado, pagado de menos, error |
| Información | `--info-700` / `-100` | `#1D5277` / `#DEEBF5` | En proceso, revisión manual |

Los semánticos cumplen ≥ 6.18:1 (texto 700 sobre fondo 100). Se usan **sólo** en estados y siempre con icono y texto.

## 4. Tipografía

- **Familia única:** Montserrat 400/500/600/700, local (`public/assets/vendor/montserrat/`, OFL). No se usa ningún servicio externo de fuentes; la CSP sólo permite `'self'`. Si falta, se usa la pila de sistema.
- **Cifras:** `font-variant-numeric: tabular-nums lining-nums` en `.num`, `.money`, `.diff`, `time`, KPI y cuadre.
- **Escala** (modular 1.2 sobre 15 px):

| Token | Tamaño | Uso |
|---|---|---|
| `--text-xs` | 12 px | Notas, encabezados de tabla, distintivos |
| `--text-sm` | 13 px | Celdas, etiquetas de campo, navegación |
| `--text-base` | 15 px | Cuerpo |
| `--text-md` | 17 px | Títulos de tarjeta y sección |
| `--text-lg` | 20 px | Títulos `h2`, importes del cuadre |
| `--text-xl` | 24 px | Título de página |
| `--text-2xl` | 30 px | Diferencia del cuadre |

- **Mayúsculas y línea:** sólo se usan en los datos que ya vienen así (nombres, periodos). Los textos de la interfaz van en oración. Las líneas de lectura miden ≤ 72ch.

## 5. Tokens

Todos están en `public/assets/css/tokens.css`. Los demás archivos **sólo** usan `var(--…)`.

| Grupo | Tokens |
|---|---|
| Color | Escalas `--wine-*`, `--gold-*`, `--sand-*`, `--gray-*` y semánticos. Roles `--color-bg`, `--color-surface`, `--color-surface-sunken`, `--color-surface-accent`, `--color-text`, `--color-text-muted`, `--color-border`, `--color-border-strong`, `--color-primary*`, `--color-rule`, `--color-focus`, `--color-nav-*`, `--color-row-attention`, `--color-money-under`, `--color-money-over`, `--color-money-even` |
| Tipografía | `--font-sans`, `--font-mono`, `--font-num`, `--text-*`, `--leading*`, `--weight-*` |
| Espaciado | `--space-1` … `--space-10` (base 4 px) |
| Bordes y radios | `--border-width`, `--rule-width` (3 px). Radios: `--radius-xs` 3 (marcas) < `--radius-sm` 6 (controles) < `--radius` 8 (contenedores) < `--radius-lg` 12 (diálogos) · `--radius-pill` (sólo distintivos) |
| Sombras | `--shadow-sm`, `--shadow` y `--shadow-lg`, **sólo en capas flotantes** (menús, toasts, modales, login). La página usa bordes |
| Foco | `--focus-ring` (dorado al 60 %) + contorno vino de 2 px |
| Alturas | `--control-h-sm` 32 · `--control-h` 40 · `--control-h-lg` 46 · `--row-h` 44 · `--header-height` 60 · `--sidebar-width` 256 |
| Movimiento | `--ease`, `--dur-fast` 120 ms, `--dur` 200 ms, `--dur-slow` 300 ms |
| Capas | `--z-sticky` 2 · `--z-topbar` 1020 · `--z-backdrop` 1025 · `--z-sidebar` 1030 · `--z-modal` 1055 · `--z-toast` 1090 · `--z-skip` 2000 |
| Breakpoints | Los de Bootstrap (576/768/992/1200/1400). En `@media` se escriben como literales |

**Estructura CSS** (orden de carga en `app/Views/partials/styles.php`):

```
vendor/bootstrap → tokens.css → base.css → layout.css → components.css → tables.css → forms.css → dashboard.css → responsive.css (→ login.css)
```

| Archivo | Contenido |
|---|---|
| `tokens.css` | `@font-face`, variables y el puente con Bootstrap (`--bs-*`) |
| `base.css` | Elementos HTML, escala de títulos, foco global, cifras tabulares, `prefers-reduced-motion` |
| `layout.css` | Navegación, barra superior, contenido, encabezado de página, `.section`, pie |
| `components.css` | Tarjetas, KPI, distintivos, botones, avisos, toasts, buscador, segmentado, vacíos, cargador, modal, permisos, zona de carga, utilidades |
| `tables.css` | Tablas, encabezado fijo, filas con incidencia, dinero y diferencias, tabla-tarjetas en móvil |
| `forms.css` | Campos, etiquetas, ayuda, error con icono, barra de filtros |
| `dashboard.css` | Cuadre, tira de cifras, línea de sistema, gráficas accesibles, bancos |
| `responsive.css` | Cambios por anchura e impresión |
| `login.css` | Sólo la pantalla de acceso |

**Bootstrap** sigue aportando la rejilla, las utilidades, los componentes base (dropdown, modal, list-group, pagination) y el comportamiento responsive. Encima va la capa visual propia.

## 6. Grid

- **Rejilla:** la de Bootstrap (`row`, `col-*`, `g-3`).
- **Contenido:** `max-width: 1680px`, alineado a la izquierda junto a la navegación. En 1920 px no se estira más.
- **Cuadre:** `grid` de 3 columnas (etiqueta, regla, importe). En móvil pasa a 2 columnas con la regla debajo.
- **Tira de cifras:** `grid auto-fit minmax(170px, 1fr)`. En tableta y móvil pasa a 2 columnas.

## 7. Espaciado

- **Base:** 4 px (`--space-*`).
- **Separaciones:**
  - entre secciones, `--space-8`;
  - del encabezado de página al contenido, `--space-6`;
  - interior de tarjeta y celda, `--space-4` / `--space-3`.
- **Alineación:** el texto va a la izquierda y los importes a la derecha. Sólo se centran los estados vacíos y los errores de página.

## 8. Botones

**Una sola familia: `.btn-action`** (reinterpretación de uiverse `cssbuttons-io/stupid-impala-51`). Al pasar el cursor **o al enfocar con el teclado**, el bloque del icono crece y el texto permanece en el DOM.

```html
<button class="btn-action btn-add" type="submit">
  <span class="btn-action__label">Crear periodo</span>
  <span class="btn-action__icon"><i class="bi bi-plus-lg" aria-hidden="true"></i></span>
</button>
```

| Variante | Uso |
|---|---|
| `btn-add` | Acción primaria (vino) |
| `btn-confirm` | Confirmar o cerrar (verde) |
| `btn-delete` | Destructiva: invalidar, anular (rojo; siempre con modal de confirmación) |
| `btn-edit` | Editar (dorado texto) |
| `btn-view` | Navegar a detalle (contorno vino) |
| `btn-cancel` | Cancelar o limpiar (contorno gris) |
| `btn-export` | Descargar o exportar (contorno dorado) |
| `btn-import` | Subir archivos |
| `btn-retry` | Reintentar un proceso |
| `btn-reconcile` | Ejecutar o declarar oficial (vino profundo) |

- `btn-action--sm` es la variante para filas de tabla.
- Los botones de Bootstrap (`.btn`) **sólo** quedan en dos controles de sistema: el botón de menú de la barra superior (`btn-icon`) y el de mostrar/ocultar contraseña dentro de un `input-group`. Toda acción de pantalla (filtrar, detectar, pausar, descargar, activar, separar, «Ir al inicio»…) usa `.btn-action`.
- «Filtrar», «Limpiar» y las acciones de fila usan `btn-action--sm`.
- Un botón que sólo tiene icono lleva `aria-label`.

## 9. Inputs

- La etiqueta es **siempre visible** (`.form-label`). La ayuda va debajo (`.form-text`, con `id` si el campo la referencia).
- Fondo **blanco** (`--color-surface`) en `.form-control` y `.form-select` (Bootstrap usaría el gris de la página).
- **Errores enlazados** con dos helpers de `app/Core/helpers.php` (escapan todo):

  ```php
  <input id="anio" name="anio" class="form-control<?= $err('anio') ? ' is-invalid' : '' ?>"<?= field_invalid('anio', $err('anio')) ?>>
  <?= field_error('anio', $err('anio')) ?>   <!-- <div class="invalid-feedback" id="anio-error">… -->
  ```

  `field_invalid()` añade `aria-invalid="true"` y `aria-describedby="{id}-error"`; `field_error()` imprime el mensaje con ese `id`. Si el campo ya tiene ayuda, `aria-describedby` lista ambos ids (ayuda y error). `.invalid-feedback` antepone un icono.
- Altura: `--control-h` (40 px), o `-sm` en filtros.
- Foco: borde vino + anillo dorado.
- Deshabilitados y de sólo lectura: fondo `--color-disabled-bg`.
- Los importes usan `inputmode="decimal"`, `pattern` y `placeholder="0.00"`. **El servidor valida siempre.**
- Los campos que no son de acceso llevan `autocomplete="off"` (evita al gestor de contraseñas); códigos y usuarios, `spellcheck="false"`.
- Login: `autocomplete="username"` / `current-password`, sin corrección ortográfica y con un botón accesible para mostrar la contraseña. El mensaje de error del servidor tiene `id` y ambos campos lo referencian con `aria-describedby`.

## 10. Buscadores y filtros

**Buscador** `.search` (reinterpretación de uiverse `alexruix/evil-parrot-25`):
- fondo hundido y subrayado vino al enfocar;
- anillo de foco en todo el grupo (`:focus-within`);
- botón para limpiar (`data-search-clear`) con `aria-label`;
- etiqueta `visually-hidden` que dice qué se busca; el `placeholder` termina en «…».

**Barra de filtros** `.filter-bar` — **un solo patrón** para todos los listados:
- controles `-sm` con etiqueta visible (`.form-label`, tamaño xs dentro de la barra);
- botón «Filtrar» `btn-action btn-view btn-action--sm` y, si aplica, «Limpiar» `btn-cancel` en `.filter-bar__actions`; `data-autosubmit` es un atajo, el botón siempre existe (sin JS también funciona);
- `.filter-bar__field--wide` para el buscador cuando comparte barra;
- en móvil cada control ocupa media fila y el buscador, el segmentado y las acciones, la fila completa.

**Control segmentado** `.segmented` (`fieldset` con `legend` oculta, radios y `.segmented__glider`):
- por omisión tiene 4 opciones; `.segmented--3` y `.segmented--5` ajustan la cuadrícula y el deslizador a 3 o 5 (el CSS cubre hasta la quinta opción);
- `.segmented__count` muestra junto a cada opción cuántos registros tiene, en peso regular, gris y dígitos tabulares (por ejemplo «Policías 1,234»);
- los resultados por persona usan dos: **Población** (Todos · Policías · Bomberos, `--3`) y **Resultado** (Todos · Correctos · Con diferencia · No pagados · Pendientes, `--5`), con `data-autosubmit`;
- en móvil (< 576 px) pasa a dos columnas sin deslizador (§18).

**Más filtros** `details.filter-more`: los filtros secundarios (resultado detallado, banco, área, rango de diferencia…) quedan plegados bajo «Más filtros». Se abre solo cuando alguno de ellos está activo, para que el filtro aplicado nunca quede oculto.

**Dónde va:** dentro de `.table-card__toolbar` (formulario GET sobre la tabla). Sólo cuando la tabla es un formulario POST (revisión masiva) o hay muchos filtros (resultados e incidencias de conciliación) va en una `.card` encima, con el mismo `.filter-bar` dentro.

El filtrado es del lado del servidor y queda en la URL (`?q=`, `?estado=`), así que se puede compartir y volver atrás.

## 11. Tablas

**Contenedor.** `.table-card` agrupa:
- barra superior `__toolbar` con el título `h2.table-card__title` (h3 si la sección ya tiene h2) y, opcional, `.table-card__hint`; o el buscador y los filtros;
- la tabla, dentro de `.table-responsive`;
- pie `__footer` (conteo y paginación).

**Encabezado fijo.** El `thead th` es `sticky`. En listas largas se añade `.table-scroll` al contenedor (alto máximo de 70vh). Sólo en listas largas: en listas cortas un redondeo subpíxel mostraría una barra inútil.

**Semántica (obligatoria, la verifica `UiContractHttpTest`).** Toda tabla tiene `<caption>` (puede ser `visually-hidden`) que dice qué contiene y, si aplica, dónde está el detalle o la fórmula; los `th` de `thead` llevan `scope="col"`; las filas de comparación usan `th scope="row"`; los totales van en `<tfoot>`. La columna de acciones es `th/td.cell-actions` con el encabezado en `visually-hidden`.

**Dinero.**
- Celdas `.num` (nunca `text-end`) alineadas a la derecha, sin salto de línea y con dígitos tabulares.
- El importe se imprime con `money($texto)`: signo menos tipográfico «−» y `$`. Nunca se convierte a float (bcmath).
- `money($v, 2, true)` añade «+» a los positivos (variaciones entre versiones).
- `money_plain($v)` devuelve el mismo texto **sin HTML** para atributos, `data-confirm`, `sprintf` y frases en negritas. No escapa: siempre se pasa por `e()`.
- Un cero sin movimiento se muestra como «—» en gris.

**Diferencias.** Se imprimen con `money_diff($diferencia, $tolerancia)`:

| Caso | Salida | Señales (no sólo color) |
|---|---|---|
| Pagado < esperado | `▼ −$3,500.00` | Signo −, flecha abajo, texto oculto «pagado de menos», `title` |
| Pagado > esperado | `▲ +$1,200.00` | Signo +, flecha arriba, texto «pagado de más» |
| Dentro de la tolerancia | `✓ $0.00` / `✓ ±$0.01` | Palomita, texto «sin diferencia» |

**Filas con incidencia.** `.row-attention` (todo estado distinto de «Correcto» o «No aplica») pone el fondo arena y **un filete dorado en el borde izquierdo** (forma, además de color).

**Estructura de fila.**
- Nombres largos: `.cell-name` (ancho mínimo y máximo, `overflow-wrap:anywhere`).
- Acciones: `.cell-actions` a la derecha.
- Columnas secundarias: `.col-hide-md` y `.col-hide-sm`.
- Primera columna fija: `.table-sticky-first`.

### 11.1 Estrategia responsive de cada tabla

No todo se convierte en tarjetas: cada tabla declara **una** estrategia según sus datos.

| Estrategia | Marcado | Cuándo | Móvil (< 576 px) |
|---|---|---|---|
| **A · Tarjeta móvil** | `table.table-stack` | Registros que se leen de uno en uno: personas, incidencias, usuarios, beneficiarios, cuentas, programaciones… | Cada fila es una tarjeta: etiqueta a la izquierda, valor a la derecha, acciones al pie. `col-hide-*` desaparecen (resumen) |
| **B · Columnas prioritarias + detalle** | `col-hide-md` / `col-hide-sm` + botón «Ver/Detalle» por fila | Listas de navegación cuyo detalle tiene su propia página: periodos, lotes, importaciones, empleados, asociaciones, auditoría, procesos | Quedan las columnas esenciales; lo demás está en el detalle |
| **C · Desplazamiento controlado** | `.table-responsive` + `.table-sticky-first` | Matrices que se comparan en horizontal: hojas y columnas del análisis, comparación de archivos, comparación de fusión, historial RRHH por quincena, reportes en pantalla | Región desplazable con foco de teclado, nombre (el `caption`) y sombras de borde; primera columna fija |
| **D · Compacta** | `table-sm` (o `chart-table`) | Pocas columnas cortas que caben en 390 px: conteos por versión, conceptos, archivos usados, auditoría relacionada, datos de gráficas | Sin cambios |

**Etiquetas de la estrategia A sin repetirlas a mano.** Las vistas **no** escriben `data-label`. `app.js` (`initResponsiveTables`) copia a cada `<td>` el texto de **su** `<th>` (respeta `colspan`), marca las celdas de acciones con etiqueta vacía y añade los roles ARIA de tabla (`table/rowgroup/row/columnheader/cell`) para que Safari/VoiceOver conserven la semántica cuando el CSS cambia `display`. Sólo entonces pone `data-stack-ready`, que activa el modo tarjetas: **sin JS la tabla queda como tabla con desplazamiento**. Un `data-label` explícito se respeta (caso excepcional: la matriz de la quincena, §29). La celda usa `display: flow-root` con la etiqueta flotante: el valor puede mezclar texto, `<small>` y distintivos sin partirse.

**Región desplazable (estrategia C).** `initScrollRegions` observa cada `.table-responsive` (ResizeObserver): si la tabla no cabe, el contenedor recibe `.is-scrollable`, `tabindex="0"`, `role="region"` y `aria-label` («{caption} (desplazable horizontalmente)»), y muestra sombras en los bordes (CSS con `background-attachment: local`, sin escuchar el scroll). Si cabe, se retira todo.

**Inventario (55 tablas en 38 vistas):**

| Estrategia | Tablas |
|---|---|
| A (30) | Resultados por persona · incidencias de conciliación · controles por nivel · personas que cambiaron (comparativo) · fuentes exactas (detalle de persona) · incidencias de identidad · candidatos, parecidos e historial de asociación · beneficiarios · trabajadores, cuentas y pagos del beneficiario · revisión masiva · registros de una acción masiva · posibles duplicados · fusiones · historial de identidad, Tesorería, cuentas e incidencias del empleado · controles del archivo · reportes generados · programaciones · usuarios · roles · catálogos (2) y perfiles · conceptos · configuración |
| B (9) | Periodos · lotes del periodo · archivos del lote · importaciones · empleados · asociaciones · conciliaciones · auditoría · procesos |
| C (6) | Hojas del libro · columnas detectadas · controles A/B (comparar archivos) · comparación de fusión · historial RRHH del empleado · secciones de reportes en pantalla (pensiones, detalle por empleado…) |
| D (8) | Gráficas de estado y de bancos (tablero) · personas por estado y montos (comparativo) · conceptos RRHH (detalle de persona) · archivos usados · auditoría relacionada · historial de análisis |
| — (2) | Plantilla PDF (`reports/pdf.php`): se imprime, no es responsive |

## 12. Cards

- **Tarjeta:** `.card` lleva borde de 1 px, radio de 8 px y **sin sombra**. Su encabezado `.card-header--accent` añade un filete dorado corto junto al título (no una banda en toda la tarjeta). **Todas** las tarjetas con título usan `card-header--accent` (sin variantes ni iconos decorativos en el título).
- **KPI:** `.kpi` lleva etiqueta, cifra y contexto, con el tono en un filete superior (`--wine`, `--success`, `--danger`, `--warning`). Sin iconos de esquina (se retiró también su marcado).
- **Tira de cifras:** `.figures` y `.figure` agrupan los indicadores secundarios en **una** superficie, separados por reglas. Una cifra en alerta (`figure--alert`) o correcta (`figure--ok`) lleva además icono `.figure__flag` y texto oculto: no depende del color.
- **Listas de definición:** `.dl-grid` para datos de ficha; `.dl-grid.dl-grid--num` para desgloses de importes (valor a la derecha, cifras tabulares, `dt.dl-grid__sub` para sub-conceptos; en móvil conserva dos columnas). No se usa `dl.row` de Bootstrap.
- **Vacíos:** `.empty-state` (con icono, en páginas y listas) y `.empty-state--sm` (dentro de una tarjeta, pestaña o gráfica). Nunca un texto suelto «Sin datos».

## 13. Badges

- **Distintivo:** `status_badge($estado, ?$texto)` en `app/Core/helpers.php` genera `.status status--<variante>` con icono, texto y `title` (todo escapado).
- **Alta de un estado:** el mapa `status_meta()` es la **única** fuente. Un estado o marca nueva se agrega ahí; no se escribe HTML de distintivos a mano (lo verifican `ViewHelpersTest` y `UiContractHttpTest`: todo `.status` lleva icono y `title`).
- **Marcas agregadas en la FASE 8** (antes escritas a mano en las vistas): `EXTRAORDINARIA`, `PENSION`, `MACROS`, `SENSIBLE`, `PROTEGIDO`, `SOLO_GOD`, `PASSWORD_TEMPORAL`, `ENTORNO`, `GRUPO`, `ORIGEN`, `ESTRUCTURA_INVALIDA`, `OCULTO`/`MUY_OCULTO`, `FILTRO_ACTIVO`, `FILAS_OCULTAS`, `COLUMNAS_OCULTAS`, `FORMULAS`/`FORMULAS_ND`/`FORMULAS_SIN_CACHE`, `COLUMNA_AUXILIAR`, `AVISO_REVISION`/`AVISO_ADVERTENCIA`/`AVISO_INFO`, `MAESTRO_CONFIABLE`/`MAESTRO_SOLO_ASOCIA`, `CUENTA_REGISTRADA`, `CLABE_NUEVA`, `FUERZA_FUERTE`/`MEDIA`/`DEBIL`, `SEV_BLOQUEO`, `SEV_GRAVE`. Con `$texto` se personaliza la leyenda («Sensible: incluye RFC/CURP», «3 filas ocultas»).
- **Roles:** `.role-chip` (GOD sólido vino, ADMIN dorado).

## 14. Estados

La forma del distintivo es parte del significado:

| Variante | Forma | Significado |
|---|---|---|
| `success` | Píldora rellena verde | Correcto, cuadra, activo |
| `warning` | Píldora rellena ámbar | Pendiente de identidad, advertencia |
| `danger` | Píldora rellena roja | No pagado, pago menor o mayor, error |
| `info` | Píldora rellena azul | En proceso |
| `review` | **Contorno** azul, fondo blanco | Requiere a una persona (revisión manual, en revisión) |
| `wine` | **Sólida vino**, texto blanco | Definitivo: cerrado |
| `gold` | Rellena dorada | Oficial, decisión manual |
| `unofficial` | **Contorno punteado** gris | Provisional: lote no oficial |
| `neutral` | Rellena gris | Informativo, no aplica, cancelado |

Estados de conciliación requeridos (leyendas en lenguaje llano; la fuente es `status_meta()` en `app/Core/helpers.php`):

| Estado | Distintivo |
|---|---|
| CORRECTO | ✓ «Pagado correctamente» (success) |
| NO_APLICA | «Sin pago que realizar» (neutral) |
| PAGO_MENOR | ▼ «Pago menor» (danger) |
| PAGO_MAYOR | ▲ «Pago mayor» (danger) |
| POSIBLE_PAGO_DUPLICADO | «Pago mayor: posible duplicado» (danger) |
| NO_PAGADO | ✕ «No pagado» (danger) |
| PAGO_SIN_RESPALDO_RRHH | «Pago sin registro en RH» (danger) |
| PENDIENTE_IDENTIDAD | Persona con «!» y «Pendiente de identificar» (warning) |
| CONFLICTO_IDENTIDAD | «Pendiente de identificar: conflicto» (warning) |
| ERROR_DATOS_RRHH | «Error en los datos de RH» (danger) |
| REVISION_MANUAL | Persona con palomita y «Revisión manual» (review) |
| ERROR | Octágono y «Error» (danger) |
| CERRADO / CERRADA | Candado, «Cerrado» / «Cerrada» (wine) |
| NO_OFICIAL | Sello con «?», «No oficial» (unofficial); su contraparte es OFICIAL (gold). En los resultados se muestra a todos como «No oficial: resultado provisional» |

Estados de pensión: CORRECTA «Dispersada correctamente» (success), NO_DISPERSADA «No dispersada», PENSION_MENOR «Pensión menor», PENSION_MAYOR «Pensión mayor» y SIN_RETENCION_RRHH «Dispersada sin retención en RH» (danger).

Estados de un archivo en la pantalla de la quincena (`periods/show.php`, leyenda propia sobre el estado real): «Leyendo» (PROCESANDO: recibido, verificando, analizando o aún sin normalizar), «Listo» (ANALIZADO), «Listo con avisos» (ANALIZADO_CON_ADVERTENCIAS), «Rechazado», «No se pudo leer» (ERROR) y «Requiere revisión» (REVISION_MANUAL).

## 15. Alerts

`.notice` (reinterpretación de uiverse `andrew-demchenk0/bad-squid-34`; `ilkhoeri/slippery-badger-23` no se encontró en el repositorio público). **Estructura única** (el contenedor es flex: el texto suelto se partiría en columnas):

```html
<div class="notice notice--warning" role="status">
  <i class="bi bi-exclamation-triangle-fill notice__icon" aria-hidden="true"></i>
  <div class="notice__body"><p class="notice__title">Título opcional</p>Mensaje con <a href="…">acción</a>.</div>
</div>
```

- **Variantes:** `error`, `warning`, `success`, `info` e `incident` (vino). `notice--compact` para listas de avisos (alertas del tablero).
- **Roles:** los errores usan `role="alert"`; las confirmaciones y el estado, `role="status"`; las notas permanentes, `role="note"`. Los toasts reutilizan `.notice` dentro de `.toast-stack` (`aria-live="polite"`).
- **Mensajes para personas** («El archivo no es un Excel válido. Súbalo de nuevo desde el original.»). El código técnico (`FIRMA_INVALIDA`, SQLSTATE) sólo aparece en `.notice__code`/`.upload-error__code` o en diagnóstico autorizado, nunca como mensaje principal. Nunca se muestran trazas, rutas, llaves ni datos personales.

## 16. Modales

- **Base:** el modal de Bootstrap, con radio de 12 px y `--shadow-lg`. Hay **un solo** modal: `partials/confirm-modal`.
- **Confirmación genérica:** activada con `data-confirm`, `data-confirm-title`, `data-confirm-variant="danger"` y `data-confirm-reason="campo"` (exige un motivo de 5+ caracteres y lo copia al formulario como texto, nunca como HTML).
- **Accesibilidad:** `aria-labelledby` (título) y `aria-describedby` (texto); foco atrapado; al abrir, el foco va al motivo o al botón principal; `Esc`, «Cancelar» o la X cierran y **el foco vuelve al botón que abrió la confirmación** (Bootstrap no lo hace al abrir por código; lo hace `app.js`). El motivo tiene ayuda y error enlazados (`aria-describedby`, `aria-invalid`); el error sólo se muestra cuando existe.
- **Encabezados:** `modal-header--danger` para acciones destructivas y `--wine` para decisiones institucionales.

## 17. Navegación

- **Lateral en vino sólido** con filete dorado. Las secciones van en oración, sin mayúsculas espaciadas.
- **Enlace activo:** fondo arena translúcido, marca dorada a la izquierda y `aria-current="page"`. Sólo se activa el de la ruta más específica.
- **Permisos:** se muestra lo que el rol puede usar. **Es sólo usabilidad:** cada ruta valida su permiso en el servidor.
- **Barra superior:** título de la página, entorno (fuera de producción) y menú de usuario.
- **Migas de pan:** separador «/».
- **Tableta y móvil (< 992 px):** la navegación es un panel deslizable con fondo oscuro. Oculto está fuera del orden de tabulación (`visibility:hidden`) y tiene `overscroll-behavior: contain`.
- Hay un enlace para saltar al contenido.

## 18. Responsive

Se valida en **1920×1080 · 1440×900 · 1366×768 · 1024×768 · 768×1024 · 390×844** .

| Anchura | Comportamiento |
|---|---|
| ≥ 1200 | Navegación fija, contenido hasta 1680 px, tabla completa |
| 992–1199 | Margen interior menor |
| < 992 | Navegación deslizable y botón de menú |
| < 768 | Cuadre en 2 columnas con la regla debajo, cifras en 2×2, ocultas las columnas `col-hide-md` |
| < 576 | Fuente base de 14 px; tablas A como tarjetas y tablas C desplazables con foco; acciones del encabezado a todo el ancho; filtros a media fila; segmentado en 2×2 sin deslizador; desgloses de importes en dos columnas; sin el nombre largo del usuario ni el distintivo de entorno |

**Impresión:** oculta la navegación y los controles.

## 19. Accesibilidad

- **Contraste:** WCAG 2.1 AA medido. El dorado oficial nunca es texto.
- **Foco visible:** contorno vino de 2 px con anillo dorado en **todo** lo interactivo, sin `!important`: `.btn` y `.btn-close` usan el mismo anillo por sus variables de Bootstrap. Nunca `outline:none` sin reemplazo (el buscador lo pasa al grupo con `:focus-within`).
- **Teclado:** todo se puede operar. «Saltar al contenido» es el primer foco y lleva a `<main>`. El menú móvil recibe el foco al abrir y `Esc` lo cierra devolviéndolo al botón. Las regiones desplazables (tablas C) reciben foco para usar las flechas.
- **Etiquetas y ARIA:**
  - toda entrada tiene `<label>` (visible o `visually-hidden`); los errores se enlazan con `field_error()`/`field_invalid()`;
  - iconos decorativos con `aria-hidden="true"`; un icono con significado lleva texto (distintivo) o `visually-hidden`;
  - botones de sólo icono y botones repetidos por fila con `aria-label` específico («Ver usuario dev.admin»);
  - tablas con `<caption>`, `scope` y `<tfoot>` para totales; sin ids duplicados.
- **Encabezados:** un `<h1>` por pantalla; secciones y tablas con `h2` (o `h3` bajo una sección `h2`); módulos de permisos con `h2`.
- **Estados sin depender del color:** icono, texto y forma (§14); diferencias con signo y flecha (§11); filas con filete; cifras del tablero con icono y texto oculto; pendiente rayado en las gráficas. **CORRECTO** (palomita, píldora verde), **ERROR** (octágono), **PENDIENTE** (reloj de arena) y **REVISIÓN** (persona con palomita, **contorno**) se distinguen en escala de grises.
- **Gráficas:** la tabla es la fuente de datos y las barras son `aria-hidden`; si no hay datos, un vacío explícito.
- **Movimiento:** se respeta `prefers-reduced-motion` de forma global. El único movimiento no provocado por la persona es el cuadre del login (1.1 s, una vez).
- **CSP estricta:** sin estilos ni scripts en línea ni manejadores `on*`. Los anchos de las barras llegan por `data-progress` y los aplica `app.js`.

## 20. Ejemplos

**Tablero:**
1. encabezado;
2. alertas operativas;
3. título de la conciliación con distintivos de oficial y estado;
4. **cuadre**;
5. tira de cifras (% conciliado, pagado identificado, pendiente de identidad, incidencias);
6. gráficas accesibles (estado de las personas, pagos por banco);
7. línea del sistema (motor, cola, usuarios);
8. periodos y actividad recientes.

No hay gráficas decorativas.

```php
<?= $this->insert('conciliations/_summary', ['c' => $conciliacion]) ?>
```

**Tabla de resultados** (`conciliations/employees.php`):

```php
<tr<?= $attn ? ' class="row-attention"' : '' ?>>   <!-- sin data-label: app.js lo toma del <th> -->
  <td class="num"><?= money($r['neto_esperado']) ?></td>
  <td class="num"><?= money_diff($r['diferencia'], $tol) ?></td>
  <td><?= status_badge($r['estado']) ?></td>
```

**Login** (`auth/login.php` + `login.css`): reinterpreta uiverse `Mr-Roy-alt/fat-kangaroo-51`.
- Panel vino con el logotipo, el nombre y el motivo del cuadre.
- Tarjeta blanca con sombra desplazada en arena.
- Campos Usuario y Contraseña, **sin correo ni registro público**.
- Botón sólido que se invierte al pasar el cursor.

**Capturas de referencia** (datos ficticios): `docs/screenshots/`.

## 21. Componentes que NO duplicar

| Necesidad | Usar | No crear |
|---|---|---|
| Botón | `.btn-action` + variante | Clases de botón nuevas o colores de botón por pantalla |
| Estado | `status_badge()` / `status_meta()` | `<span class="badge bg-…">` o distintivos escritos a mano |
| Oficial / no oficial | `status_badge('OFICIAL' \| 'NO_OFICIAL')` | Distintivos propios |
| Importe | `money()` | `'$' . fmt_decimal()` concatenado en la vista |
| Diferencia | `money_diff()` | Clases de color sobre el número |
| Aviso / toast | `.notice` | `.alert` de Bootstrap con otros estilos |
| Confirmación | `data-confirm` + `partials/confirm-modal` | `confirm()` del navegador o modales nuevos |
| Búsqueda | `.search` | Otro estilo de buscador |
| Filtro de estado | `.segmented` (con `--3`/`--5` y `.segmented__count`) | Pestañas nuevas |
| Filtros secundarios | `details.filter-more` («Más filtros») | Barras de filtros con todos los campos a la vista |
| Vacío | `.empty-state` | Textos sueltos «Sin datos» |
| Progreso | `.progress` + `data-progress` | `style="width:…"` (lo prohíbe la CSP) |
| Hojas de estilo | `partials/styles.php` | `<link>` sueltos en vistas o layouts |
| Colores y medidas | Tokens de `tokens.css` | Valores hex o px nuevos fuera de `tokens.css` |
| Etiqueta de celda en móvil | `table.table-stack` + `<th>` (etiqueta automática) | `data-label` escrito a mano en cada `<td>` |
| Error de campo | `field_error()` + `field_invalid()` | `<div class="invalid-feedback">` sin `id` ni enlace |
| Filtros | `.filter-bar` + «Filtrar» `btn-action--sm` | `row g-2` de columnas o botones `btn-outline-*` |
| Título de tabla | `h2.table-card__title` | `span` con estilo o `h2.h6` sueltos |
| Desglose de importes | `.dl-grid--num` | `dl.row` con `text-end` |
| Vacío pequeño | `.empty-state--sm` | `<p class="text-muted">Sin …</p>` |
| Importe en texto o atributo | `e(money_plain($v))` | `'$' . fmt_decimal($v)` |

## 22. Iconografía, dropdowns, paginación, loaders, skeletons y tooltips

- **Iconografía:** Bootstrap Icons 1.13 local, siempre con `aria-hidden="true"`. El significado lo da el texto: un distintivo, una etiqueta o un `visually-hidden`. Un mismo concepto usa siempre el mismo icono: `bi-people` fusiones, `bi-bell` notificaciones, `bi-heart-pulse` salud, `bi-person-heart` pensiones, `bi-lock` cifrado.
- **Dropdowns:** los de Bootstrap (menú de usuario y campana). Radio de 8 px, sombra `--shadow` y cierre con `Esc`. Ninguna acción destructiva vive sólo dentro de un dropdown.
- **Paginación:** del servidor (`partials/pagination`), con los filtros en la URL. Tamaño `--text-sm`; la página actual en vino.
- **Loader único:** `.loader` (círculo vino) en botones ocupados (`data-busy`) y en sondeos. El buscador gira su propio icono (`.search.is-loading`) y anuncia «Buscando…». No hay otros spinners.
- **Skeletons: no se usan.** Las páginas se sirven completas desde el servidor, así que un esqueleto sólo retrasaría la lectura. Mientras un proceso corre se muestra el estado real con barra `data-progress`, nunca un contenido simulado.
- **Tooltips:** sólo el `title` nativo como ayuda **complementaria**, por ejemplo en el riel de navegación o en los distintivos. Nunca contiene información que no esté también en el texto o en `aria-label`. No se usan tooltips de Bootstrap: requieren JS extra y no son accesibles en táctil.

## 23. Navegación

El menú (`app/Views/partials/sidebar.php`) sigue el proceso real: la **quincena** es la puerta de entrada. Sólo cambia lo que se ve en el menú; los permisos de cada ruta no cambiaron.

| Grupo | Opciones (según permisos) |
|---|---|
| **Principal** | Tablero, Quincenas |
| **Consulta** | Empleados, Beneficiarios de pensión, Reportes |
| **Avanzado** (GOD y quien puede cargar archivos, `archivos.cargar`: en la práctica ADMIN; CONSULTA no lo ve) | Archivos cargados, Cotejos, Revisión masiva, Revisión de pensiones, Asociaciones, Incidencias de identidad, Posibles duplicados, Solicitudes de fusión, Reportes programados |
| **Administración** (sólo GOD) | Usuarios, Roles y permisos, Catálogos, Conceptos de nómina, Auditoría, Configuración |
| **Sistema** (sólo GOD) | Procesos, Salud del sistema |

- En la interfaz «Periodos» se llama **«Quincenas»** (la ruta sigue siendo `/periodos`). Las pantallas del grupo «Avanzado» siguen accesibles por URL a quien tenga el permiso; sólo dejan de aparecer en el menú de quien no puede cargar archivos (CONSULTA). Desde la migración 0026 el grupo ya no es exclusivo de GOD.
- **Riel en escritorio (≥ 992 px):** el botón «Contraer la navegación» (`aria-pressed`) reduce la barra a iconos (`--sidebar-rail-width`). Las etiquetas quedan para lectores de pantalla y el `title` las muestra al pasar el cursor. La preferencia se guarda en el navegador (`localStorage` con `try/catch`). Sin preferencia, entre 992 y 1199 px empieza contraída.
- **Tableta vertical y móvil (< 992 px):** panel deslizable (§17).
- **Migas uniformes:** la primera miga es el **grupo** del menú. Lo que pertenece a una quincena sigue su jerarquía real: `Quincenas › {periodo}`, `Quincenas › {periodo} › Pendientes de identificar` y `Quincenas › {periodo} › Cotejo n.º N › Personas › {número}` (también `› Pensiones` e `› Incidencias`). El lote y la versión del motor no aparecen en las migas; GOD los ve en el encabezado del cotejo.
- **Encabezado:** título de la página, entorno (fuera de producción), **campana de notificaciones** y menú de cuenta (nombre, rol, cambiar contraseña, cerrar sesión).

## 24. Notificaciones

- **Campana:** botón con `aria-label` que dice cuántas hay sin leer («Notificaciones: 3 sin leer»). El contador es un número, nunca sólo un punto de color. Un anuncio discreto (`aria-live="polite"`) avisa cuando **aumentan**.
- **Panel:** muestra las 6 más recientes (las no leídas primero, con filete vino y «· nueva»). Abrir una es un POST con CSRF que la marca leída y lleva a su pantalla. Incluye un enlace «Ver todas».
- **Bandeja** (`/notificaciones`): filtros por estado y tipo, «Marcar todas como leídas», «Eliminar leídas» y un vacío que explica qué aparecerá ahí.
- **Sondeo:** cada 60 s y sólo con la pestaña visible (docs/NOTIFICATIONS.md).

## 25. Evidencia, línea de tiempo y barra de decisión

**Evidencia** (`partials/evidence`): tres marcas llenas o vacías + la palabra + la razón.
- FUERTE ●●● · MEDIA ●●○ · BAJA ●○○ · SIN ○○○.
- Se lee en escala de grises: el color es apoyo.
- Se usa en la revisión masiva y en la de pensiones. Debajo va la lista de señales en lenguaje llano (no códigos).

**Línea de tiempo** (`.timeline`): la historia de una CLABE por periodo.
- Cada punto muestra periodo, lote, importe, estado, trabajador y beneficiario confirmado.
- El caso actual se marca en vino.
- Es un `<ol>`: el orden es información.

**Barra de decisión** (`.decision-bar`): las acciones de un caso al pie, pegadas al desplazarse (en móvil, en el flujo normal).
- Casos de pensión: Confirmar · Rechazar · Dejar pendiente.
- Las solicitudes de fusión usan una tarjeta «Decisión» con Aprobar y aplicar · Rechazar (otra persona con permiso de aprobar: ADMIN o GOD) o Cancelar solicitud (solicitante; nunca ve Aprobar en su propia solicitud).
- Cada acción usa el modal de confirmación con motivo obligatorio.

## 26. Tablero: «Pendiente de mi atención»

- **Qué muestra:** una lista de tareas (`.attention`) que **este** usuario puede resolver según sus permisos. Cada tarea lleva conteo grande, texto y acción («Revisar pensiones»); las de prioridad alta, un filete rojo y un texto oculto «Prioridad alta».
- **Cifras:** todas se calculan en el momento (`AttentionService`); ninguna es fija.
- **Sin tareas:** una línea en verde con palomita: «Nada espera su decisión».
- **Posición:** va antes del cuadre. Las alertas que ya son tareas no se repiten.

## 27. Otros patrones de la FASE 8

- **Canal de una importación** (`.pipeline`): Recibido → Verificado → Analizado → Normalizado → Conciliado.
  - Cada paso lleva número o ✓, título y detalle («6 registros», «En 1 ejecución»).
  - El paso actual tiene `aria-current="step"`; un problema se marca con «!» y texto.
  - En móvil los pasos se apilan.
- **Filtros activos** (`partials/filter-status`): «N filtros activos · Limpiar filtros» bajo la barra de filtros, sólo cuando hay alguno. El orden no cuenta como filtro.
- **Matriz de permisos:**
  - buscador (sin acentos ni mayúsculas) y conteo «n de m» por módulo;
  - «Marcar todos / Quitar todos» por módulo, que **nunca** toca los permisos exclusivos de GOD (llegan deshabilitados y el servidor también los rechaza);
  - leyenda de «Sólo GOD» y «Sensible».
- **Auditoría legible:**
  - filtros por usuario, acción, módulo, entidad, origen, resultado (exitoso o fallido, deducido de la acción) y fechas;
  - el detalle muestra una tabla **Campo · Antes · Después** (tachado y resaltado, no sólo color) y el contexto como lista;
  - el JSON original queda en «Datos técnicos», plegado.
- **Revisión masiva:** columnas Selección · Nombre · Banco (con CLABE enmascarada) · Monto · Confianza (evidencia) · Evidencia (razón y señales) · Candidato · Estado.
  - La barra de selección («N seleccionados · $ total») queda pegada arriba al recorrer cientos de filas.
  - Conflictos y ambigüedades llevan filete de atención.
- **Usuarios:** Nombre · Usuario · Rol · Estado · Último acceso (con sesiones abiertas) · Acciones. Sin columnas irrelevantes.

## 28. Modales

Hay **un solo modal de confirmación** (`partials/confirm-modal`) que maneja `app.js`. Un formulario lo pide con `data-confirm="mensaje"` y lo ajusta con estos atributos:
- `data-confirm-title`;
- `data-confirm-variant`;
- `data-confirm-reason` (motivo obligatorio, mínimo 5 caracteres, que viaja en el campo indicado);
- `data-confirm-accept` (texto del botón);
- `data-confirm-note`.

| Variante | Uso | Filete · icono | Botón por omisión |
|---|---|---|---|
| `confirm` (omisión) | Operación normal | vino · ✓ | Confirmar |
| `danger` | Destructiva o que descarta trabajo: rechazar, anular, eliminar | rojo · octágono | Sí, continuar (rojo) |
| `warning` | Continúa con un riesgo conocido | ámbar · triángulo | Continuar |
| `info` | Sólo informa antes de seguir | azul · i | Entendido |
| `approval` | **Doble aprobación:** crea una solicitud que otra persona decide | doble dorado · personas | Enviar a aprobación, con la nota de cuatro ojos |

Reglas:
- La gravedad nunca depende sólo del color: cada variante tiene su icono y su verbo.
- El foco vuelve al botón que abrió el modal. `Esc` cancela.
- El botón que envió el formulario conserva su `name` y `value`.
- Sin `onclick` ni estilos en línea (CSP).

## 29. Quincena: matriz población × documento

La pantalla de la quincena (`app/Views/periods/show.php`, `GET /periodos/{id}`) es el centro del proceso. Los archivos se cargan en una matriz `table.table-stack.slot-grid`:

| | Nómina de RH | Pagos de nómina | Pensiones alimenticias |
|---|---|---|---|
| **Policías** | 1 archivo | varios (Santander, BBVA, interbancaria) | varios, opcional |
| **Bomberos** | 1 archivo | varios | varios, opcional |

- **Filas = población** (`th scope="row"`), **columnas = documento** (`th scope="col"` con la ayuda en `.slot-grid__help`). El lugar dice qué es el archivo: el tipo documental, el origen y la población se asignan solos; la persona no elige nada.
- **Celda** `td.slot`. Vacía lleva `.slot--empty` y un recuadro **con borde punteado** `.slot__missing`: «Falta este archivo» o, en pensiones, «Sin archivos (sólo si hubo pensiones)». La línea punteada es la señal de «falta» (forma, no sólo color).
- **Archivos de la celda** `ul.slot-files` > `li.slot-file`: nombre (`.slot-file__name`, enlace al archivo), estado (`.slot-file__state`, leyendas de §14), datos (`.slot-file__meta`: banco, registros y total con `money()`) y notas (`.slot-file__note` en ámbar; `.slot-file__note--error` en rojo para el motivo de rechazo o error).
- **Carga** `form.slot-upload` dentro de la celda (`data-busy`), con etiqueta oculta («Pagos de nómina de Policías») y botón «Cargar». En nómina sólo aparece mientras la celda está vacía; en pagos y pensiones siempre (`multiple`).
- **Archivos sin población:** aviso `.notice--warning` con `ul.slot-files--inline` y los botones «Es de Policías / Es de Bomberos».
- **Pólizas contables (opcional):** `details.slot-optional` con `.slot-optional__title` por póliza; plegado salvo que ya tenga archivos.
- **Cifras del cotejo:** `.cotejo-figures` (un `dl-grid--num` de ancho acotado).

**Excepción explícita al `data-label` automático (§11.1).** En esta tabla cada `td.slot` lleva `data-label` escrito en la vista con **sólo el título** de la columna. Motivo: el `<th>` contiene también la ayuda (`.slot-grid__help`), y la etiqueta automática de `app.js` copiaría el título y la ayuda completa delante de cada celda en móvil. Es la única vista con `data-label` a mano; no se usa como precedente para otras tablas.


## 30. Reportes: dos principales y «Más reportes»

La pantalla de reportes (`app/Views/reports/index.php`, `GET /reportes`) es un solo formulario `GET /reportes/ver`:

- **Cotejo** arriba (`.report-form__cotejo`): quincena, número de cotejo, estado y, si el lote no es el oficial, «provisional».
- **Dos tarjetas** en `.report-main` (rejilla que pasa a una columna en móvil): «Resumen de la quincena» y «Lista de personas». La lista lleva el selector «Personas» (`name="lista"`: Todas, Con diferencias, No pagadas, Pagadas de más, Pagadas de menos, Pagos pendientes de identificar). Cada tarjeta dice sus formatos en `.report-main__formats`.
- **«Más reportes»** plegado (`details.report-more`): pensiones, Policías y Bomberos, por banco e incidencias, uno por renglón (`.report-more__item`) con su botón «Ver».
- Cada botón es `type="submit" name="tipo"`; la lista envía `tipo=lista` y el controlador lo traduce al tipo elegido. Las opciones que el usuario no puede ver no aparecen (pendientes sin `asociaciones.ver`, incidencias sin `incidencias.ver`).
- Ningún tipo de reporte se eliminó: las programaciones y el historial siguen usando las mismas claves.

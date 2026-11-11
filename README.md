# ConciliaRH

**Sistema Integral de Conciliación de Nómina y Pagos** · PHP 8 + PostgreSQL

> **Versión de portafolio:** se removieron datos, configuraciones y recursos institucionales. El sistema original fue desarrollado para una dependencia municipal.
>
> Desarrollado por **Lucio** ([@LucioSalva](https://github.com/LucioSalva)) como Jefe del Departamento de TI del H. Ayuntamiento de Ecatepec de Morelos (México). En esta versión, la identidad visual, los textos institucionales, los logotipos, los manuales operativos y cualquier dato de personas fueron sustituidos o eliminados; los datos de prueba son ficticios y se generan por código.

---

## ¿Qué problema resuelve?

Cada quincena, Recursos Humanos autoriza una nómina (tabulados y pólizas contables) y Tesorería dispersa los pagos en varios bancos (Santander, BBVA, transferencias interbancarias), además de las pensiones alimenticias retenidas. Verificar a mano, en hojas de cálculo, que **lo autorizado coincide con lo pagado, persona por persona**, es lento y propenso a errores.

ConciliaRH responde de forma **verificable y auditable**:

- ¿El detalle de RH (tabulado) coincide con su póliza?
- ¿Coinciden las pólizas bancarias con los archivos que Tesorería dispersó?
- ¿Se pagó correctamente a **cada persona**, aunque el pago esté dividido entre varios bancos?
- ¿Hay pagos de más, de menos, faltantes, duplicados o sin respaldo de RH?
- ¿Las pensiones alimenticias retenidas se dispersaron realmente?

## Funcionalidades clave

- **Flujo por quincena:** una pantalla central con la matriz población × tipo de archivo (nómina de RH, pagos de Tesorería, pensiones). Se cargan los archivos, el sistema los lee en segundo plano y habilita «Cotejar» sólo cuando todo está listo.
- **Carga segura de Excel** (`.xls`, `.xlsx`, `.xlsm`): validación de extensión, firma, MIME real y contenedor (bomba ZIP, XXE, cifrado); las macros nunca se ejecutan; SHA-256, detección de duplicados y almacenamiento inmutable fuera de `public/`.
- **Análisis automático de libros:** inspección de todas las hojas (incluidas ocultas), detección de encabezados de uno o dos niveles, clasificación de filas (detalle/TOTAL/auxiliares), perfil por contenido (nunca por nombre de archivo) y **huella lógica de contenido** para detectar «mismo contenido, distinto binario» y posibles versiones.
- **Identidades:** empleado maestro e historial por campo; asociación **determinística** (número de empleado → RFC → CURP → CLABE confirmada). El nombre sólo produce sugerencias; las contradicciones generan conflictos para revisión manual.
- **Motor de conciliación por niveles** (tabulado ↔ póliza global ↔ pólizas de egreso ↔ dispersión ↔ pago por persona ↔ pensiones), con aritmética decimal exacta (`bcmath` / `NUMERIC`, nunca `float`), tolerancia configurable y resultados **reproducibles** (fuentes exactas, huella de entradas y versión del motor). Las ejecuciones son inmutables (v1, v2…) y existe un comparativo entre ellas.
- **Revisión masiva** con previsualización firmada, doble confirmación y ejecución todo-o-nada revalidada en el servidor.
- **Fusión no destructiva de duplicados** (por alias, sin mover ni borrar historial) y separación correctiva.
- **Reportes** en pantalla, XLSX, CSV y PDF (generado localmente, sin recursos remotos), con protección contra inyección de fórmulas; **reportes programados** con un rol de BD propio.
- **Notificaciones internas**, tablero «pendiente de mi atención» y pantalla de **salud del sistema** (workers, cola, scheduler, almacenamiento, migraciones).

## Decisiones de seguridad

Todo lo siguiente está implementado en el código y cubierto por pruebas:

| Control | Implementación |
|---|---|
| **RBAC** | Roles GOD / ADMIN / CONSULTA con permisos granulares (`perm:x` en rutas). Las reglas críticas también se validan **en la base de datos** (triggers que impiden que el rol web asigne GOD, regla de subconjunto de permisos). |
| **Bitácora de auditoría** | Tabla append-only **encadenada por hash** (`fn_auditoria_verificar()` detecta alteraciones), inmutable incluso para el propietario del esquema, con actor de sesión, IP y user-agent. Comando `bin/audit-anchor.php` para fijar un **ancla externa** de la cadena. |
| **Cifrado en reposo** | Cuentas/CLABE con **AES-256-GCM** (anillo de llaves con `key_id`) y búsqueda por **HMAC** sin descifrar; reportes con datos personales cifrados con **XChaCha20-Poly1305 secretstream** (libsodium), con llave distinta. Rotación de las tres llaves sólo por CLI (`bin/rotate-*.php`), reanudable y con corte atómico para HMAC. |
| **Doble aprobación (cuatro ojos)** | Las fusiones de identidades con conflictos graves requieren que **otra persona** apruebe la solicitud; quien solicita no puede aprobar. |
| **Mínimo privilegio en BD** | Roles separados: `conciliarh_owner` (sólo migraciones/CLI), `conciliarh_web`, `conciliarh_worker`, `conciliarh_scheduler`, con privilegios por columna y funciones `SECURITY DEFINER` acotadas. Row Level Security en notificaciones. |
| **Autenticación** | Contraseñas con **Argon2id**, bloqueo por usuario+IP, por usuario global y por IP, verificación ficticia de coste idéntico (sin enumeración de usuarios), cambio de contraseña forzado, sesiones en BD con ID hasheado, expiración por inactividad y absoluta, revocación. |
| **Web** | CSRF en todo POST, validación de `Origin`, CSP estricta (`default-src 'self'`), escape de salida centralizado (`e()`), router que sólo acepta IDs numéricos, errores sin detalles técnicos, logs JSON con redacción de datos sensibles. |
| **Datos sensibles en UI** | RFC/CURP y cuentas enmascarados (`••••1234`) para CONSULTA; «Revelar» requiere permiso específico y queda auditado. |
| **Arranque seguro** | La aplicación se niega a arrancar en producción con `APP_DEBUG=true`, llaves faltantes o inválidas, o llaves repetidas entre propósitos. |
| **Recuperación de emergencia** | Procedimiento *break-glass* para la cuenta GOD sólo por CLI y auditado (`bin/god-recovery.php`). |

Detalle en [`docs/SECURITY.md`](docs/SECURITY.md), [`docs/REPORT_ENCRYPTION.md`](docs/REPORT_ENCRYPTION.md), [`docs/HMAC_ROTATION.md`](docs/HMAC_ROTATION.md) y [`docs/CRITICAL_MERGE_APPROVAL.md`](docs/CRITICAL_MERGE_APPROVAL.md).

## Arquitectura

```
Navegador ──► public/index.php ──► Kernel ──► Sesión ──► Router ──► CSRF ──► Middleware (auth, perm:x)
                                                                              │
                                                                              ▼
                                                          Controlador ──► Servicio de dominio ──► PDO ──► PostgreSQL
                                                                                                            ▲
               bin/worker.php (procesador en segundo plano) ── cola `procesos` (FOR UPDATE SKIP LOCKED) ─────┘
```

- **Monolito modular** en PHP sin framework: MVC propio, contenedor de dependencias, router, vistas con escape automático.
- **La web nunca ejecuta comandos de shell:** encola trabajos en PostgreSQL (`procesos`) y emite `NOTIFY`.
- **Procesador en segundo plano (PHP CLI):** reclama trabajos con `FOR UPDATE SKIP LOCKED`, *lease* con token de *fencing*, latido por conexión lateral, recuperación de trabajos abandonados, timeout y reintentos acotados con *backoff*.
- **Lector OOXML propio en streaming** (XMLReader) para `.xlsx`/`.xlsm`; PhpSpreadsheet sólo para `.xls`.

Más detalle en [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md), [`docs/DATABASE.md`](docs/DATABASE.md) y [`docs/CONCILIATION_ENGINE.md`](docs/CONCILIATION_ENGINE.md).

## Stack

- **Backend:** PHP ≥ 8.3 (strict types, PSR-4 con Composer), sin framework.
- **Base de datos:** PostgreSQL ≥ 14 (desarrollo con 17): migraciones SQL versionadas con *checksum*, funciones PL/pgSQL, triggers, RLS.
- **Bibliotecas:** `dompdf/dompdf` (PDF local), `openspout/openspout` (XLSX en streaming), `phpoffice/phpspreadsheet` (lectura de `.xls`), PHPUnit 12.
- **Frontend:** Bootstrap 5, Bootstrap Icons y Montserrat servidos localmente (sin CDN); JavaScript con módulos ES, sin frameworks.
- **Infraestructura:** Docker Compose para desarrollo; para producción, plantillas de Apache, PHP-FPM, systemd (worker, scheduler, respaldos, verificación de almacenamiento) y logrotate en `deploy/`.

## Estructura de carpetas

```
app/
  Core/            Kernel, Router, Request/Response, sesión, vistas, Logger, Migrator, helpers
  Http/            Controladores y middleware (auth, permisos, CSRF)
  Domain/          Servicios de dominio: Auth, Users, Roles, Audit, Imports, Analysis, Identity,
                   Conciliation, Reports, Periods, Lots, Notifications, System…
  Engine/          Procesador en segundo plano: lectura de libros, análisis, normalización,
                   identidades y motor de conciliación
  Views/           Plantillas PHP (layout, parciales, pantallas, PDF)
bin/               CLI: migrate, worker, scheduler, create-god, rotaciones de llaves, preflight, mantenimiento
config/            Configuración (lee variables de entorno) y rutas
database/migrations/  Migraciones SQL up/down (sólo esquema y catálogos)
deploy/            Plantillas de despliegue sin Docker (Apache, PHP-FPM, systemd, PostgreSQL)
docs/              Documentación técnica de diseño
infra/docker/      Entorno de desarrollo (Compose, Dockerfile PHP/Apache, init de PostgreSQL)
public/            Único directorio publicado (index.php y assets)
scripts/           Inicialización de desarrollo, respaldo/restauración, empaquetado
tests/             PHPUnit (Unit, Integration, Engine, Http), fixtures ficticios y pruebas E2E
```

## Cómo levantarlo localmente

### Opción A: Docker (recomendada)

Requisitos: Docker con Compose v2, `make` y `bash`.

```bash
make init        # genera .env con secretos aleatorios, construye imágenes, instala Composer y migra (BD principal y de pruebas)
make god         # crea el usuario GOD (interactivo)
# o, sólo en desarrollo, cuentas de prueba dev.god / dev.admin / dev.consulta:
make seed-dev    # contraseñas aleatorias en storage/dev-credenciales.txt (ignorado por Git)
make demo-dev    # (opcional) datos de demostración ficticios
# Abrir http://localhost:8120
```

Cada contenedor recibe sólo las credenciales que necesita: `web` las del rol `conciliarh_web`, `worker` las de `conciliarh_worker`; las del propietario del esquema existen sólo en el servicio bajo demanda `tools`.

### Opción B: PHP + PostgreSQL locales

1. PHP ≥ 8.3 con `pdo_pgsql`, `bcmath`, `intl`, `mbstring`, `fileinfo`, `zip`, `openssl`, `sodium`, `iconv`, `gd`, `dom`, `xml`, `xmlreader`, `xmlwriter`, `simplexml`.
2. Crear los roles y la base (ver `deploy/postgres/01-roles-y-base.sql` o `infra/docker/postgres/init/01-roles.sh`).
3. Configurar el entorno:
   ```bash
   cp .env.example .env     # sustituya cada CHANGE_ME; llaves: openssl rand -hex 32 (todas distintas)
                            # en local: APP_ENV=development, APP_DEBUG=true, SESSION_SECURE=false, APP_URL=http://127.0.0.1:8120
   composer install
   php bin/migrate.php up
   php bin/create-god.php
   php -S 127.0.0.1:8120 -t public     # servidor de desarrollo
   php bin/worker.php worker           # en otra terminal: procesador en segundo plano
   ```
4. `php bin/preflight.php` verifica extensiones, configuración y conexión (sólo lectura).

## Pruebas

```bash
make test             # genera fixtures Excel ficticios + PHPUnit (Unit, Integration, Engine, Http)
make smoke            # pruebas HTTP de extremo a extremo (requiere make seed-dev)
make security-smoke   # pruebas ofensivas HTTP: SQLi, XSS, IDOR, CSRF, escalamiento de privilegios, fórmulas, SSRF por PDF
```

Sin Docker:

```bash
php -d memory_limit=1G tests/fixtures/generate.php   # fixtures ficticios (no se versionan)
vendor/bin/phpunit --testsuite Unit                   # no requiere base de datos
vendor/bin/phpunit                                    # todas (requiere DB_TEST_DATABASE y credenciales del propietario)
```

Las suites de integración y HTTP corren contra una base de pruebas real de PostgreSQL (privilegios por rol, triggers, cadena de auditoría, concurrencia de la cola). Todos los datos de prueba son ficticios (RFC genérico `XAXX010101000`, nombres «PERSONA FICTICIA…»).

## Documentación técnica

| Documento | Contenido |
|---|---|
| [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) | Diagramas y protocolo de la cola |
| [`docs/DATABASE.md`](docs/DATABASE.md) | Esquema, roles y migraciones |
| [`docs/SECURITY.md`](docs/SECURITY.md) | Modelo de amenazas, controles y revisiones de seguridad por fase |
| [`docs/IMPORTS.md`](docs/IMPORTS.md) | Carga segura, almacenamiento, SHA-256 vs huella de contenido |
| [`docs/FILE_ANALYSIS.md`](docs/FILE_ANALYSIS.md) | Motor de análisis de libros |
| [`docs/IDENTITY_MATCHING.md`](docs/IDENTITY_MATCHING.md) | Reglas de asociación de identidades y cuentas |
| [`docs/IDENTITY_MERGE.md`](docs/IDENTITY_MERGE.md) | Duplicados, fusión no destructiva y separación |
| [`docs/CONCILIATION_ENGINE.md`](docs/CONCILIATION_ENGINE.md) | Motor de conciliación por niveles |
| [`docs/REPORTS.md`](docs/REPORTS.md) · [`docs/SCHEDULED_REPORTS.md`](docs/SCHEDULED_REPORTS.md) | Reportes y reportes programados |
| [`docs/KEY_ROTATION.md`](docs/KEY_ROTATION.md) · [`docs/HMAC_ROTATION.md`](docs/HMAC_ROTATION.md) · [`docs/REPORT_ENCRYPTION.md`](docs/REPORT_ENCRYPTION.md) | Cifrado y rotación de llaves |
| [`docs/DEPLOYMENT.md`](docs/DEPLOYMENT.md) · [`docs/OPERATIONS_RUNBOOK.md`](docs/OPERATIONS_RUNBOOK.md) · [`docs/BACKUP_RESTORE.md`](docs/BACKUP_RESTORE.md) | Instalación sin Docker, operación, respaldo y restauración |
| [`docs/DESIGN_SYSTEM.md`](docs/DESIGN_SYSTEM.md) | Sistema de diseño y accesibilidad |

---

## English summary

**ConciliaRH** is a payroll-vs-payments reconciliation system built with **PHP 8 (no framework) and PostgreSQL**. It ingests the payroll authorized by HR and the bank disbursement files issued by the Treasury (Excel workbooks), analyzes them safely in a background worker, matches identities deterministically, and reconciles **person by person** with exact decimal arithmetic, producing reproducible, auditable results and reports.

Security highlights: role-based access control enforced both in the app and in the database, a **hash-chained append-only audit log** with external anchoring, **AES-256-GCM** encryption of bank accounts with HMAC lookup, **XChaCha20-Poly1305** encryption of sensitive reports, key rotation via CLI, **four-eyes approval** for critical identity merges, Argon2id passwords with anti-enumeration lockouts, strict CSP/CSRF, and least-privilege database roles per service.

Built by Lucio (@LucioSalva) as Head of the IT Department for a Mexican municipal government. *Portfolio version: data, configuration and institutional assets have been removed; the original system was built for a municipal agency.*

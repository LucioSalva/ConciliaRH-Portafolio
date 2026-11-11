# Cifrado en reposo de reportes generados (FASE 8)

Los reportes programados que contienen datos de personas se guardan **cifrados** en `storage/reportes/generados`. Complementa `docs/SCHEDULED_REPORTS.md`.

## 1. Por qué

Ya existían permisos al descargar, la huella SHA-256 y la retención. El cifrado agrega: **una copia del disco o del respaldo no expone a las personas** si no se tiene además la llave de reportes.

## 2. Clasificación

| Tipo | Contenido | En reposo |
|---|---|---|
| `resumen`, `bancos`, `grupos` | Sólo agregados (totales, conteos, por banco o grupo) | En claro, si no es sensible |
| `empleados`, `diferencias`, `no_pagados`, `pagados_mas`, `pagados_menos`, `pendientes`, `pensiones`, `incidencias` | Persona por persona (número, nombre, importes; incidencias con persona) | **Cifrado** |
| Cualquiera marcado `sensible` (RFC/CURP) | Identificadores | **Cifrado** |

- La regla vive en `ReportService::requiresEncryption()` y en la BD (`fn_reporte_requiere_cifrado`).
- El trigger de `reportes_generados` rechaza con `REPORTE_SIN_CIFRAR` que un reporte con personas pase a DISPONIBLE sin cifrar.
- **Exportación manual** (`/reportes/exportar`): no se persiste. Se genera en `storage/trabajo/reportes` (0660), se transmite y se borra al terminar; un barrido elimina restos de más de una hora.

## 3. Primitiva

Se usa **libsodium `crypto_secretstream_xchacha20poly1305`**, AEAD por segmentos de la extensión `sodium` estándar de PHP. **No se inventa criptografía.**

Se eligió sobre AES-256-GCM porque un reporte puede ocupar varios MB y debe cifrarse y descifrarse **en streaming**:
- **Nonces:** secretstream gestiona nonces aleatorios de 192 bits y su secuencia; no hay reutilización posible.
- **Integridad por segmento:** cada segmento lleva su etiqueta Poly1305.
- **Truncado:** el último segmento lleva `TAG_FINAL`, así que se detecta el truncado y la basura añadida.

Con AES-GCM habría que componer a mano un esquema por segmentos, lo que sí sería «inventar».

**Formato del archivo (`*.enc`):**

```
"CRHRPT1\n" | len(id) | id de llave | KCV (16 B) | encabezado secretstream (24 B) | { longitud (4 B BE) | segmento }*
```

- **Datos asociados de cada segmento:** `conciliarh:report:v1|<id de llave>|g<id del reporte>`.
  - Mover el archivo de un reporte a otro lo invalida.
  - Cambiar el id de llave del encabezado también lo invalida.
- **KCV** = `HMAC-SHA256(llave, "conciliarh:report-kcv:v1")[0..16]`. Distingue una **llave equivocada en el servidor** (503; el reporte NO se marca alterado) de un **archivo manipulado** (409; queda ALTERADO). No revela la llave.

## 4. Llaves

| Variable | Contenido |
|---|---|
| `REPORT_ENC_KEYS` | Anillo `r1:64hex[,r2:64hex]` (`openssl rand -hex 32` por llave) |
| `REPORT_ENC_KEY_ID` | Llave activa para cifrar |

- **Separación obligatoria.** `Config::assertSecure()` impide arrancar si alguna llave de reportes repite un secreto de `ACCOUNT_ENC_KEYS`, `ACCOUNT_HMAC_KEY(S)` o `APP_KEY`.
- **Dónde se configura:** la web (descarga) y el scheduler (generación). El procesador en segundo plano **no** la recibe.
- **Nunca se registran** la llave ni el contenido en logs, auditoría ni errores. Los errores son códigos: `AUTENTICACION_FALLIDA`, `TRUNCADO`, `LLAVE_INCORRECTA`…

## 5. Generación y descarga

**Generación** (`ReportJobRunner`):
1. Se renderiza a un temporal privado (openspout y dompdf necesitan un archivo).
2. Se cifra a `*.enc`.
3. Se **borra el claro de inmediato** (y en `finally` ante cualquier error).
4. Se mueve el cifrado a su ruta final.

La fila registra `cifrado`, `cifrado_llave`, `cifrado_at` y el SHA-256 del **archivo cifrado**.

**Descarga** (`ScheduledReportService::download`):
1. Se autoriza: dueño o administrador, más **todos** los permisos con los que se construyó.
2. Se copia el cifrado a un temporal y se verifica el SHA-256 **de la copia**.
3. Se **verifica todo el cifrado** (cada etiqueta, el final, la llave y el contexto) **sin entregar un solo byte**.
4. Sólo entonces se descifra **segmento a segmento directo a la respuesta** (`Response::withStreamWriter`). **En la descarga el claro nunca se escribe en disco.** En la generación sí existe un temporal en claro unos instantes (openspout y dompdf escriben a archivo): vive en `storage/trabajo/reportes`, se borra en cuanto existe el cifrado y en `finally`; no se sobrescribe con ceros (en discos SSD/COW no sería efectivo), por eso `STORAGE_PATH` debe estar en un volumen cifrado.
5. Se borra la copia cifrada, también si el cliente se desconecta.

**Si la verificación falla:**

| Caso | Respuesta | Estado del reporte | Auditoría |
|---|---|---|---|
| Huella del archivo distinta, etiqueta alterada, truncado, contexto ajeno | 409 | ALTERADO | `reportes.integridad_fallida` con el código |
| Llave ausente o distinta en este servidor | 503, sin marcar | Sin cambio | — |

**Límite:** 30 descargas por minuto por usuario.

## 6. Rotación

`bin/rotate-report-key.php`, sólo CLI y con el rol propietario:

```bash
php bin/rotate-report-key.php --status
php bin/rotate-report-key.php --dry-run --to=r2
php bin/rotate-report-key.php --to=r2 --confirm-backup
```

- Re-cifra cada reporte DISPONIBLE que use otra llave **sin claro en disco**: descifra y vuelve a cifrar en memoria, por segmentos. El archivo nuevo se asegura con `fsync` antes de registrarse; cada reporte se audita (`reportes.rotacion_llave_reporte`) y, si el archivo anterior no puede borrarse, se informa como error (nunca se da por procesado un claro que siguió en disco).
- Toma el candado exclusivo del reporte, así que espera a las descargas en curso.
- Actualiza ruta, SHA-256 y llave en una transacción. Sólo el propietario puede hacerlo (trigger). Después borra el archivo anterior.
- **Cifra también los reportes legados en claro** (anteriores a la FASE 8) que por su contenido deben cifrarse. La salud del sistema los cuenta mientras existan.
- **Orden recomendado:**
  1. respaldo;
  2. agregar la llave nueva al anillo en la web, el scheduler y la CLI;
  3. `REPORT_ENC_KEY_ID` = nueva;
  4. rotar;
  5. cuando `--status` ya no muestre la llave vieja, retirarla del anillo.

## 7. Qué NO protege

- A quien tiene a la vez acceso al servidor en ejecución y a sus variables de entorno.
- El reporte ya descargado por un usuario autorizado.

Para eso están los permisos, la auditoría de cada descarga y la retención.

## 8. Pruebas

- **`tests/Unit/ReportCipherTest.php`:**
  - ida y vuelta (vacío, pequeño, varios segmentos);
  - nonce distinto por cifrado;
  - nonce alterado, etiqueta alterada, cifrado alterado;
  - truncado sin final y a media;
  - basura final, magia alterada;
  - contexto de otro reporte;
  - llave equivocada, llave ausente, id de llave cambiado;
  - rotación sin claro en disco, re-cifrado de un archivo manipulado;
  - anillos inválidos.
- **`tests/Unit/CoreTest.php`:** anillo obligatorio y separado de las demás llaves.
- **`ScheduledReportsHttpTest`:**
  - en disco cifrado sin claro ni temporales;
  - descarga descifrada con `Content-Length` exacto;
  - llave equivocada da 503 sin marcar ALTERADO.
- **`make security-smoke`**, sección «FASE 8: cifrado en reposo»: generación real, archivo en disco, descarga, CONSULTA 403, byte alterado da 409 y ALTERADO.

<?php

declare(strict_types=1);

namespace ConciliaRH\Domain\Imports;

use RuntimeException;

final class ImportException extends RuntimeException
{
    public const MESSAGES = [
        'UPLOAD_SIN_ARCHIVO' => 'Seleccione un archivo.',
        'UPLOAD_DEMASIADO_GRANDE' => 'El archivo excede el tamaño máximo permitido.',
        'UPLOAD_INCOMPLETO' => 'La carga se interrumpió. Intente de nuevo.',
        'UPLOAD_ERROR_SERVIDOR' => 'El servidor no pudo recibir el archivo.',
        'ARCHIVO_VACIO' => 'El archivo está vacío.',
        'NOMBRE_INVALIDO' => 'El nombre del archivo no es válido.',
        'EXTENSION_NO_PERMITIDA' => 'Sólo se aceptan archivos .xls, .xlsx o .xlsm.',
        'FIRMA_INVALIDA' => 'El contenido del archivo no corresponde a su extensión.',
        'MIME_NO_PERMITIDO' => 'El tipo de archivo detectado no está permitido.',
        'EXTENSION_NO_COINCIDE' => 'El libro no corresponde a su extensión (por ejemplo, un .xlsx con macros o un .xlsm sin ellas).',
        'ARCHIVO_CORRUPTO' => 'El archivo está dañado o no es un libro de Excel válido.',
        'CONTENEDOR_PELIGROSO' => 'El archivo excede los límites de seguridad (tamaño descomprimido, número de partes o estructura no permitida).',
        'DUPLICADO_EXACTO_EN_LOTE' => 'Este mismo archivo (idéntico byte a byte) ya está cargado en esta quincena.',
        'DUPLICADO_EXACTO_REQUIERE_CONFIRMACION' => 'Este mismo archivo ya se cargó en otra quincena. Si es correcto, vuelva a cargarlo marcando la casilla de confirmación.',
        'LOTE_NO_ABIERTO' => 'La quincena no está abierta para recibir archivos.',
        'CLASIFICACION_INVALIDA' => 'Seleccione un tipo documental y un origen válidos.',
        'ALMACENAMIENTO_ERROR' => 'No fue posible almacenar el archivo. No se registró la carga.',
        'INTEGRIDAD_ERROR' => 'El archivo almacenado no coincide con su huella original. Se notificó al área de sistemas.',
        'ESTADO_INVALIDO' => 'La operación no está permitida en el estado actual del archivo.',
        'MACROS_REQUIERE_CONFIRMACION' => 'El libro contiene macros. ConciliaRH nunca las ejecuta, pero Excel podría hacerlo al abrirlo: confirme explícitamente la descarga.',
    ];

    /** @param array<string,mixed> $context datos no sensibles para el usuario/auditoría */
    public function __construct(private readonly string $errorCode, ?string $message = null, private readonly array $context = [])
    {
        parent::__construct($message ?? (self::MESSAGES[$errorCode] ?? 'No fue posible procesar el archivo.'));
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    /** @return array<string,mixed> */
    public function context(): array
    {
        return $this->context;
    }
}

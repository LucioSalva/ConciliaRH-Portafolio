<?php

declare(strict_types=1);

function e(mixed $value): string
{
    if ($value === null) {
        return '';
    }
    if (is_bool($value)) {
        $value = $value ? '1' : '0';
    }
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
}

function fmt_datetime(mixed $value, bool $withSeconds = false): string
{
    if (!is_string($value) || $value === '') {
        return '—';
    }
    try {
        $dt = new DateTimeImmutable($value);
    } catch (Exception) {
        return '—';
    }
    return $dt->setTimezone(new DateTimeZone(date_default_timezone_get()))->format($withSeconds ? 'd/m/Y H:i:s' : 'd/m/Y H:i');
}

function status_badge(string $state, ?string $label = null): string
{
    [$variant, $icon, $text] = status_meta($state);
    $text = $label ?? $text;
    return sprintf(
        '<span class="status status--%s" title="%s"><i class="bi %s" aria-hidden="true"></i>%s</span>',
        e($variant), e($text), e($icon), e($text)
    );
}

function status_label(string $state): string
{
    $text = status_meta($state)[2];
    return $text === $state ? ucfirst(strtolower(str_replace('_', ' ', $state))) : $text;
}

/** @return array{0:string,1:string,2:string} variante, icono, texto */
function status_meta(string $state): array
{
    static $map = [
        'PENDIENTE' => ['neutral', 'bi-hourglass-split', 'Pendiente'],
        'EN_EJECUCION' => ['info', 'bi-arrow-repeat', 'En ejecución'],
        'COMPLETADO' => ['success', 'bi-check-circle-fill', 'Completado'],
        'COMPLETADO_CON_INCIDENCIAS' => ['warning', 'bi-exclamation-triangle-fill', 'Completado con incidencias'],
        'ERROR' => ['danger', 'bi-x-octagon-fill', 'Error'],
        'CANCELADO' => ['neutral', 'bi-slash-circle', 'Cancelado'],
        'ACTIVO' => ['success', 'bi-check-circle-fill', 'Activo'],
        'INACTIVO' => ['warning', 'bi-pause-circle-fill', 'Inactivo'],
        'ELIMINADO' => ['danger', 'bi-trash3-fill', 'Eliminado'],
        'INTEGRA' => ['success', 'bi-shield-check', 'Íntegra'],
        'ABIERTO' => ['success', 'bi-unlock-fill', 'Abierto'],
        'CERRADO' => ['wine', 'bi-lock-fill', 'Cerrado'],
        'OFICIAL' => ['gold', 'bi-patch-check-fill', 'Oficial'],
        'NO_OFICIAL' => ['unofficial', 'bi-patch-question', 'No oficial'],
        'ANULADO' => ['neutral', 'bi-slash-circle', 'Anulado'],
        'SIN_ARCHIVOS' => ['neutral', 'bi-inbox', 'Sin archivos'],
        'PROCESANDO' => ['info', 'bi-arrow-repeat', 'Procesando'],
        'CON_ERRORES' => ['danger', 'bi-x-octagon-fill', 'Con errores'],
        'CON_ADVERTENCIAS' => ['warning', 'bi-exclamation-triangle-fill', 'Con advertencias'],
        'LISTO' => ['success', 'bi-check-circle-fill', 'Analizado'],
        'RECIBIDO' => ['neutral', 'bi-inbox-fill', 'Recibido'],
        'VERIFICANDO' => ['info', 'bi-shield-shaded', 'Verificando'],
        'VERIFICADO' => ['success', 'bi-shield-check', 'Verificado'],
        'RECHAZADO' => ['danger', 'bi-shield-x', 'Rechazado'],
        'ANALIZANDO' => ['info', 'bi-arrow-repeat', 'Analizando'],
        'ANALIZADO' => ['success', 'bi-check-circle-fill', 'Analizado'],
        'ANALIZADO_CON_ADVERTENCIAS' => ['warning', 'bi-exclamation-triangle-fill', 'Analizado con advertencias'],
        'NUEVO' => ['success', 'bi-stars', 'Nuevo'],
        'DUPLICADO_BINARIO' => ['warning', 'bi-files', 'Duplicado exacto'],
        'DUPLICADO_CONTENIDO' => ['warning', 'bi-intersect', 'Mismo contenido'],
        'POSIBLE_VERSION' => ['info', 'bi-layers', 'Posible versión'],
        'SIN_HUELLA' => ['neutral', 'bi-fingerprint', 'Sin huella'],
        'REVISION_MANUAL' => ['review', 'bi-person-check', 'Revisión manual'],
        'CONF_ALTA' => ['success', 'bi-bullseye', 'Confianza alta'],
        'CONF_MEDIA' => ['warning', 'bi-circle-half', 'Confianza media'],
        'CONF_BAJA' => ['danger', 'bi-question-circle', 'Confianza baja'],
        'CONF_MANUAL' => ['gold', 'bi-person', 'Elegido manualmente'],
        'CONF_NINGUNA' => ['neutral', 'bi-dash-circle', 'Sin perfil'],
        'CTL_CUADRA' => ['success', 'bi-check-circle-fill', 'Cuadra'],
        'CTL_NO_CUADRA' => ['danger', 'bi-x-octagon-fill', 'No cuadra'],
        'CTL_SIN_TOTAL' => ['neutral', 'bi-dash-circle', 'Sin total'],
        'CTL_MULTIPLES_TOTALES' => ['warning', 'bi-stack', 'Múltiples totales'],
        'CTL_TOTAL_INVALIDO' => ['danger', 'bi-exclamation-octagon', 'Total inválido'],
        'CTL_NO_APLICA' => ['neutral', 'bi-info-circle', 'Informativo'],
        'HOJA_SELECCIONADA' => ['success', 'bi-check2-circle', 'Seleccionada'],
        'HOJA_INCLUIDA' => ['info', 'bi-plus-circle', 'Incluida'],
        'HOJA_CANDIDATA' => ['warning', 'bi-question-circle', 'Candidata'],
        'HOJA_DESCARTADA' => ['neutral', 'bi-x-circle', 'Descartada'],
        'HOJA_VACIA' => ['neutral', 'bi-inbox', 'Vacía'],
        'HOJA_AUXILIAR' => ['neutral', 'bi-paperclip', 'Complementaria'],
        'ASOCIADO_AUTOMATICO' => ['success', 'bi-link-45deg', 'Asociado automático'],
        'ASOCIADO_MANUAL' => ['gold', 'bi-person-check', 'Asociado manual'],
        'SUGERENCIA' => ['info', 'bi-lightbulb', 'Sugerencia'],
        'AMBIGUO' => ['warning', 'bi-people', 'Ambiguo'],
        'CONFLICTO' => ['danger', 'bi-exclamation-octagon-fill', 'Conflicto'],
        'NO_IDENTIFICADO' => ['warning', 'bi-question-circle', 'No identificado'],
        'NO_IDENTIFICADO_CONFIRMADO' => ['neutral', 'bi-person-x', 'No identificado (confirmado)'],
        'INVALIDO' => ['danger', 'bi-x-octagon', 'Inválido'],
        'VIGENTE' => ['success', 'bi-check-circle-fill', 'Vigente'],
        'SUSTITUIDA' => ['neutral', 'bi-arrow-left-right', 'Sustituida'],
        'INVALIDADA' => ['neutral', 'bi-slash-circle', 'Invalidada'],
        'ACTIVA' => ['success', 'bi-check-circle-fill', 'Activa'],
        'INACTIVA' => ['neutral', 'bi-pause-circle', 'Inactiva'],
        'ABIERTA' => ['warning', 'bi-exclamation-diamond', 'Abierta'],
        'RESUELTA' => ['success', 'bi-check2-circle', 'Resuelta'],
        'DESCARTADA' => ['neutral', 'bi-x-circle', 'Descartada'],
        'ANULADA' => ['neutral', 'bi-slash-circle', 'Anulada'],
        'SEV_ALTA' => ['danger', 'bi-exclamation-octagon-fill', 'Alta'],
        'SEV_ERROR' => ['danger', 'bi-x-octagon-fill', 'Error'],
        'SEV_CRITICA' => ['wine', 'bi-exclamation-diamond-fill', 'Crítica'],
        'COMPLETADA' => ['success', 'bi-check-circle-fill', 'Completada'],
        'COMPLETADA_CON_INCIDENCIAS' => ['warning', 'bi-exclamation-triangle-fill', 'Completada con incidencias'],
        'EJECUTANDO' => ['info', 'bi-arrow-repeat', 'Ejecutando'],
        'CERRADA' => ['wine', 'bi-lock-fill', 'Cerrada'],
        'CUADRA' => ['success', 'bi-check-circle-fill', 'Cuadra'],
        'NO_CUADRA' => ['danger', 'bi-x-octagon-fill', 'No cuadra'],
        'NO_DETERMINABLE' => ['neutral', 'bi-dash-circle', 'No determinable'],
        'INFORMATIVO' => ['neutral', 'bi-info-circle', 'Informativo'],
        'CORRECTO' => ['success', 'bi-check-circle-fill', 'Pagado correctamente'],
        'PAGO_MENOR' => ['danger', 'bi-arrow-down-circle-fill', 'Pago menor'],
        'PAGO_MAYOR' => ['danger', 'bi-arrow-up-circle-fill', 'Pago mayor'],
        'NO_PAGADO' => ['danger', 'bi-x-circle-fill', 'No pagado'],
        'PAGO_SIN_RESPALDO_RRHH' => ['danger', 'bi-question-octagon-fill', 'Pago sin registro en RH'],
        'POSIBLE_PAGO_DUPLICADO' => ['danger', 'bi-files', 'Pago mayor: posible duplicado'],
        'PENDIENTE_IDENTIDAD' => ['warning', 'bi-person-exclamation', 'Pendiente de identificar'],
        'CONFLICTO_IDENTIDAD' => ['warning', 'bi-person-x', 'Pendiente de identificar: conflicto'],
        'ERROR_DATOS_RRHH' => ['danger', 'bi-bug', 'Error en los datos de RH'],
        'NO_APLICA' => ['neutral', 'bi-dash-circle', 'Sin pago que realizar'],
        'CORRECTA' => ['success', 'bi-check-circle-fill', 'Dispersada correctamente'],
        'NO_DISPERSADA' => ['danger', 'bi-x-circle-fill', 'No dispersada'],
        'PENSION_MENOR' => ['danger', 'bi-arrow-down-circle-fill', 'Pensión menor'],
        'PENSION_MAYOR' => ['danger', 'bi-arrow-up-circle-fill', 'Pensión mayor'],
        'SIN_RETENCION_RRHH' => ['danger', 'bi-question-octagon-fill', 'Dispersada sin retención en RH'],
        'EN_REVISION' => ['review', 'bi-search', 'En revisión'],
        'ACEPTADA_JUSTIFICADA' => ['gold', 'bi-patch-check', 'Aceptada (justificada)'],
        'SEV_ADVERTENCIA' => ['warning', 'bi-exclamation-triangle-fill', 'Advertencia'],
        'SEV_INFO' => ['info', 'bi-info-circle', 'Informativa'],
        'SEV_BAJA' => ['neutral', 'bi-dash-circle', 'Baja'],
        'DESCARTADO' => ['neutral', 'bi-x-circle', 'Descartado'],
        'FUSIONADO' => ['wine', 'bi-intersect', 'Fusionado'],
        'OBSOLETO' => ['neutral', 'bi-clock-history', 'Ya no se detecta'],
        'APLICADA' => ['wine', 'bi-intersect', 'Aplicada'],
        'SEPARADA' => ['neutral', 'bi-arrows-expand', 'Separada'],
        'GENERANDO' => ['info', 'bi-arrow-repeat', 'Generando'],
        'DISPONIBLE' => ['success', 'bi-check-circle-fill', 'Disponible'],
        'EXPIRADO' => ['neutral', 'bi-hourglass-bottom', 'Expirado'],
        'ALTERADO' => ['danger', 'bi-shield-exclamation', 'Archivo alterado'],
        'SIN_DATOS' => ['neutral', 'bi-inbox', 'Sin datos'],
        'PAUSADA' => ['warning', 'bi-pause-circle-fill', 'Pausada'],
        'CONF_BLOQUEADA' => ['danger', 'bi-slash-circle', 'Bloqueada'],
        'CONF_SIN_EVIDENCIA' => ['neutral', 'bi-dash-circle', 'Sin evidencia'],
        'CONF_FUERTE' => ['success', 'bi-shield-check', 'Fuerte'],
        'SOL_PENDIENTE_APROBACION' => ['review', 'bi-people', 'Pendiente de aprobación'],
        'SOL_EJECUTADA' => ['success', 'bi-check2-all', 'Aprobada y aplicada'],
        'SOL_RECHAZADA' => ['danger', 'bi-x-circle', 'Rechazada'],
        'SOL_CANCELADA' => ['neutral', 'bi-slash-circle', 'Cancelada'],
        'SOL_EXPIRADA' => ['neutral', 'bi-hourglass-bottom', 'Expirada'],
        'SALUD_OK' => ['success', 'bi-check-circle-fill', 'Correcto'],
        'SALUD_ADVERTENCIA' => ['warning', 'bi-exclamation-triangle-fill', 'Advertencia'],
        'SALUD_ERROR' => ['danger', 'bi-x-octagon-fill', 'Falla'],
        'SALUD_SIN_DATOS' => ['neutral', 'bi-dash-circle', 'Sin datos'],
        'CIFRADO' => ['info', 'bi-lock', 'Cifrado en reposo'],
        'EXTRAORDINARIA' => ['gold', 'bi-calendar-plus', 'Extraordinaria'],
        'PENSION' => ['gold', 'bi-people', 'Pensión'],
        'MACROS' => ['gold', 'bi-braces', 'Macros (no se ejecutan)'],
        'SENSIBLE' => ['warning', 'bi-shield-exclamation', 'Sensible'],
        'PROTEGIDO' => ['wine', 'bi-lock-fill', 'Protegido'],
        'SOLO_GOD' => ['wine', 'bi-lock-fill', 'Sólo GOD'],
        'PASSWORD_TEMPORAL' => ['warning', 'bi-key', 'Temporal'],
        'ENTORNO' => ['gold', 'bi-cone-striped', 'Entorno'],
        'GRUPO' => ['info', 'bi-people', 'Grupo'],
        'ORIGEN' => ['neutral', 'bi-hdd-network', 'Origen'],
        'ESTRUCTURA_INVALIDA' => ['warning', 'bi-exclamation-triangle', 'Inválido'],
        'OCULTO' => ['warning', 'bi-eye-slash', 'Oculta'],
        'MUY_OCULTO' => ['warning', 'bi-eye-slash-fill', 'Muy oculta'],
        'FILTRO_ACTIVO' => ['warning', 'bi-funnel', 'Filtro'],
        'FILAS_OCULTAS' => ['warning', 'bi-eye-slash', 'Filas ocultas'],
        'COLUMNAS_OCULTAS' => ['neutral', 'bi-layout-three-columns', 'Columnas ocultas'],
        'FORMULAS' => ['info', 'bi-calculator', 'Fórmulas'],
        'FORMULAS_ND' => ['neutral', 'bi-calculator', 'Fórmulas n/d'],
        'FORMULAS_SIN_CACHE' => ['danger', 'bi-exclamation-octagon', 'Sin caché'],
        'COLUMNA_AUXILIAR' => ['neutral', 'bi-paperclip', 'Auxiliar'],
        'AVISO_REVISION' => ['danger', 'bi-person-check', 'Revisión'],
        'AVISO_ADVERTENCIA' => ['warning', 'bi-exclamation-triangle-fill', 'Advertencia'],
        'AVISO_INFO' => ['info', 'bi-info-circle', 'Informativo'],
        'MAESTRO_CONFIABLE' => ['success', 'bi-check-circle-fill', 'Fuente confiable'],
        'MAESTRO_SOLO_ASOCIA' => ['neutral', 'bi-dash-circle', 'Sólo asocia'],
        'CUENTA_REGISTRADA' => ['success', 'bi-check2', 'Registrada'],
        'CLABE_NUEVA' => ['gold', 'bi-plus-circle', 'CLABE nueva'],
        'FUERZA_FUERTE' => ['success', 'bi-shield-check', 'Fuerte'],
        'FUERZA_MEDIA' => ['info', 'bi-circle-half', 'Media'],
        'FUERZA_DEBIL' => ['neutral', 'bi-dash-circle', 'Débil'],
        'SEV_BLOQUEO' => ['danger', 'bi-slash-circle', 'Bloqueo'],
        'SEV_GRAVE' => ['danger', 'bi-exclamation-octagon-fill', 'Grave'],
        'IMPORTE_PENDIENTE_IDENTIDAD' => ['neutral', 'bi-circle', 'Pagos de nómina pendientes de identificar'],
        'IMPORTE_PENSION_PENDIENTE_IDENTIDAD' => ['neutral', 'bi-circle', 'Pensiones pendientes de identificar'],
        'DISPERSION_TOTAL_NO_CUADRA' => ['neutral', 'bi-circle', 'La dispersión total no cuadra'],
        'GLOBAL_EGRESO_NO_CUADRA' => ['neutral', 'bi-circle', 'Póliza GLOBAL y póliza de egreso no cuadran'],
        'POBLACION_ARCHIVO_NO_COINCIDE' => ['neutral', 'bi-circle', 'La población del archivo no coincide'],
        'POLIZA_BANCO_NO_CUADRA' => ['neutral', 'bi-circle', 'La póliza de egreso del banco no cuadra'],
        'POLIZA_BANCO_RELACION_AMBIGUA' => ['neutral', 'bi-circle', 'Póliza de egreso con relación dudosa'],
        'POLIZA_BANCO_SIN_DISPERSION' => ['neutral', 'bi-circle', 'Póliza de egreso sin dispersión'],
        'POLIZA_BANCO_SIN_DISPERSION_IGUAL' => ['neutral', 'bi-circle', 'Póliza de egreso sin dispersión del mismo importe'],
        'POLIZA_GLOBAL_MULTIPLE' => ['neutral', 'bi-circle', 'Más de una póliza GLOBAL'],
        'POLIZA_HOJA_MONTO_INCONSISTENTE' => ['neutral', 'bi-circle', 'Importe de una hoja de la póliza inconsistente'],
        'RRHH_POLIZA_PERCEPCIONES_NO_CUADRA' => ['neutral', 'bi-circle', 'Percepciones de RH y póliza GLOBAL no cuadran'],
        'RRHH_POLIZA_DEDUCCIONES_NO_CUADRA' => ['neutral', 'bi-circle', 'Deducciones de RH y póliza GLOBAL no cuadran'],
        'RRHH_POLIZA_NETO_NO_CUADRA' => ['neutral', 'bi-circle', 'Neto de RH y póliza GLOBAL no cuadran'],
        'RRHH_POLIZA_EMPLEADOS_NO_CUADRA' => ['neutral', 'bi-circle', 'Personas de RH y póliza GLOBAL no cuadran'],
        'RRHH_POLIZA_NO_DETERMINABLE' => ['neutral', 'bi-circle', 'Falta la nómina de RH o la póliza GLOBAL para comparar'],
        'RRHH_SIN_EMPLEADO' => ['neutral', 'bi-circle', 'Registro de RH sin número de empleado'],
        'PENSION_NO_DISPERSADA' => ['neutral', 'bi-circle', 'Pensión no dispersada'],
        'PENSION_SIN_RETENCION_RRHH' => ['neutral', 'bi-circle', 'Pensión dispersada sin retención en RH'],
        'PENSION_PENDIENTE_IDENTIDAD' => ['neutral', 'bi-circle', 'Pensión pendiente de identificar'],
        'ACTUALIZACION_IDENTIFICADOR_DUPLICADO' => ['neutral', 'bi-circle', 'Actualización de identificador repetida'],
        'CLABE_CONFLICTO' => ['neutral', 'bi-circle', 'Cuenta en conflicto'],
        'CUENTA_NUEVA' => ['neutral', 'bi-circle', 'Cuenta nueva'],
        'DATOS_RRHH_CONTRADICTORIOS' => ['neutral', 'bi-circle', 'Datos de RH contradictorios'],
        'IMPORTE_INVALIDO' => ['neutral', 'bi-circle', 'Importe inválido'],
        'NUMERO_DUPLICADO_EN_ARCHIVO' => ['neutral', 'bi-circle', 'Número de empleado repetido en el archivo'],
        'RFC_DUPLICADO_EN_MAESTRO' => ['neutral', 'bi-circle', 'RFC repetido en el padrón de empleados'],
        'CURP_DUPLICADO_EN_MAESTRO' => ['neutral', 'bi-circle', 'CURP repetida en el padrón de empleados'],
        'ASOCIACIONES_PENDIENTES' => ['neutral', 'bi-circle', 'Pagos por identificar'],
        'CONCILIACION_ERROR' => ['neutral', 'bi-circle', 'Cotejo con error'],
        'CONCILIACION_TERMINADA' => ['neutral', 'bi-circle', 'Cotejo terminado'],
        'FUSION_DECIDIDA' => ['neutral', 'bi-circle', 'Fusión decidida'],
        'FUSION_PENDIENTE_APROBACION' => ['neutral', 'bi-circle', 'Fusión por aprobar'],
        'IMPORTACION_REVISION' => ['neutral', 'bi-circle', 'Archivo por revisar'],
        'REPORTE_FALLIDO' => ['neutral', 'bi-circle', 'Reporte programado con error'],
        'REPORTE_LISTO' => ['neutral', 'bi-circle', 'Reporte programado listo'],
        'ALTA_SIN_NOMBRE' => ['neutral', 'bi-circle', 'Alta sin nombre'],
        'CURP_DISCREPANTE' => ['neutral', 'bi-circle', 'CURP distinta a la registrada'],
        'CURP_INVALIDO' => ['neutral', 'bi-circle', 'CURP no válida'],
        'RFC_DISCREPANTE' => ['neutral', 'bi-circle', 'RFC distinto al registrado'],
        'RFC_INVALIDO' => ['neutral', 'bi-circle', 'RFC no válido'],
        'IDENTIDAD_INCOMPLETA' => ['neutral', 'bi-circle', 'Datos de identidad incompletos'],
        'NOMBRE_AMBIGUO' => ['neutral', 'bi-circle', 'Nombre igual al de varias personas'],
        'NOMBRE_DIFERENTE' => ['neutral', 'bi-circle', 'Nombre distinto al registrado'],
        'NOMBRE_SIN_COINCIDENCIA' => ['neutral', 'bi-circle', 'Nombre sin coincidencia'],
        'NUMERO_DESCONOCIDO_CON_OTRA_EVIDENCIA' => ['neutral', 'bi-circle', 'Número de empleado desconocido, con otra evidencia'],
        'NUMERO_EMPLEADO_DESCONOCIDO' => ['neutral', 'bi-circle', 'Número de empleado desconocido'],
        'NUMERO_EMPLEADO_INVALIDO' => ['neutral', 'bi-circle', 'Número de empleado no válido'],
        'NUMERO_NUEVO_IDENTIDAD_EXISTENTE' => ['neutral', 'bi-circle', 'Número nuevo para una persona ya registrada'],
        'IDENTIFICADORES_CONTRADICTORIOS' => ['neutral', 'bi-circle', 'Identificadores que no coinciden'],
        'IDENTIFICADOR_DUPLICADO_EN_MAESTRO' => ['neutral', 'bi-circle', 'Identificador repetido en el padrón de empleados'],
        'ARCHIVO_DUPLICADO_EXCLUIDO' => ['neutral', 'bi-circle', 'Archivo duplicado: no se usó'],
        'ARCHIVO_EXCLUIDO_NO_NORMALIZADO' => ['neutral', 'bi-circle', 'Archivo sin terminar de leer: no se usó'],
        'DUPLICADO_SIN_ORIGINAL_VIGENTE' => ['neutral', 'bi-circle', 'Duplicado sin original vigente'],
        'FUENTE_RRHH_AMBIGUA' => ['neutral', 'bi-circle', 'Dos nóminas de RH comparten personas'],
        'LOTE_SIN_DISPERSION' => ['neutral', 'bi-circle', 'Sin pagos de Tesorería'],
        'LOTE_SIN_FUENTE_RRHH' => ['neutral', 'bi-circle', 'Sin nómina de RH que se pueda usar'],
        'TABULADO_SUBCONJUNTO_EXCLUIDO' => ['neutral', 'bi-circle', 'Nómina de RH repetida: no se usó'],
        'VERSION_AMBIGUA_EN_LOTE' => ['neutral', 'bi-circle', 'Más de una versión del mismo archivo'],
        'TESORERIA_SANTANDER' => ['neutral', 'bi-circle', 'Tesorería · Santander'],
        'TESORERIA_BBVA' => ['neutral', 'bi-circle', 'Tesorería · BBVA'],
        'TESORERIA_INTERBANCARIA' => ['neutral', 'bi-circle', 'Tesorería · Interbancaria'],
        'TESORERIA_DISPERSION' => ['neutral', 'bi-circle', 'Tesorería · dispersión'],
        'TESORERIA_NOMINA' => ['neutral', 'bi-circle', 'Pagos de nómina'],
        'TESORERIA_PENSION' => ['neutral', 'bi-circle', 'Pensiones alimenticias'],
        'TESORERIA_PENSION_SANTANDER' => ['neutral', 'bi-circle', 'Pensiones · Santander'],
        'TESORERIA_PENSION_BBVA' => ['neutral', 'bi-circle', 'Pensiones · BBVA'],
        'TESORERIA_PENSION_INTERBANCARIA' => ['neutral', 'bi-circle', 'Pensiones · Interbancaria'],
        'RRHH_TABULADO' => ['neutral', 'bi-circle', 'Nómina de RH'],
        'RRHH_TABULADO_POLICIA' => ['neutral', 'bi-circle', 'Nómina de RH · Policías'],
        'RRHH_TABULADO_BOMBEROS' => ['neutral', 'bi-circle', 'Nómina de RH · Bomberos'],
        'RRHH_POLIZA_GLOBAL' => ['neutral', 'bi-circle', 'Póliza GLOBAL de RH'],
        'RRHH_POLIZA_GLOBAL_POLICIA' => ['neutral', 'bi-circle', 'Póliza GLOBAL de RH · Policías'],
        'RRHH_POLIZA_GLOBAL_BOMBEROS' => ['neutral', 'bi-circle', 'Póliza GLOBAL de RH · Bomberos'],
        'POLIZA_EGRESO_BANCARIA' => ['neutral', 'bi-circle', 'Póliza de egreso bancaria'],
        'AUTOMATICA' => ['neutral', 'bi-circle', 'Automática'],
        'REASOCIACION' => ['neutral', 'bi-circle', 'Reasociación'],
        'ASOCIAR' => ['neutral', 'bi-circle', 'Asociación manual'],
        'RECHAZAR_SUGERENCIA' => ['neutral', 'bi-circle', 'Propuesta rechazada'],
        'MARCAR_NO_IDENTIFICADO' => ['neutral', 'bi-circle', 'Marcado como no identificado'],
        'CONFIRMAR_CUENTA' => ['neutral', 'bi-circle', 'Cuenta confirmada'],
        'TRASLADO' => ['neutral', 'bi-circle', 'Traslado'],
        'CONFIRMAR_BENEFICIARIO' => ['neutral', 'bi-circle', 'Beneficiario confirmado'],
        'NOMBRE_EXACTO' => ['neutral', 'bi-circle', 'Nombre idéntico (propuesta)'],
        'NUMERO_EMPLEADO' => ['neutral', 'bi-circle', 'Número de empleado'],
        'ALTA_RRHH' => ['neutral', 'bi-circle', 'Alta desde RH'],
        'NOMINA' => ['neutral', 'bi-circle', 'Nómina'],
        'CLABE' => ['neutral', 'bi-circle', 'Cuenta CLABE'],
    ];
    return $map[$state] ?? ['neutral', 'bi-circle', $state];
}

function bank_label(mixed $code): string
{
    $c = strtoupper(trim((string) $code));
    return ['SANTANDER' => 'Santander', 'BBVA' => 'BBVA', 'INTERBANCARIA' => 'Interbancaria', 'OTRO' => 'Otro', 'OTROS' => 'Otros'][$c]
        ?? ($c === '' ? '—' : ucfirst(mb_strtolower($c)));
}

function fmt_bytes(mixed $bytes): string
{
    $b = (float) $bytes;
    foreach (['B', 'KB', 'MB', 'GB'] as $u) {
        if ($b < 1024 || $u === 'GB') {
            return ($u === 'B' ? (string) (int) $b : number_format($b, 1)) . ' ' . $u;
        }
        $b /= 1024;
    }
    return (string) $bytes;
}

function fmt_decimal(mixed $value, int $decimals = 2): string
{
    if ($value === null || $value === '') {
        return '—';
    }
    $v = trim((string) $value);
    if (!preg_match('/^-?\d+(\.\d+)?$/', $v)) {
        return $v;
    }
    $neg = str_starts_with($v, '-');
    $abs = ltrim($v, '-');
    $half = $decimals > 0 ? '0.' . str_repeat('0', $decimals) . '5' : '0.5';
    $rounded = bcadd($abs, $half, $decimals);
    [$int, $frac] = array_pad(explode('.', $rounded), 2, '');
    $int = strrev(implode(',', str_split(strrev($int), 3)));
    $out = $decimals > 0 ? $int . '.' . $frac : $int;
    return ($neg && trim($rounded, '0.') !== '' ? '-' : '') . $out;
}

function money_plain(mixed $value, int $decimals = 2, bool $signed = false): string
{
    if ($value === null || $value === '') {
        return '—';
    }
    $f = fmt_decimal($value, $decimals);
    if (!preg_match('/^-?[\d,]+(\.\d+)?$/', $f)) {
        return $f; // no numérico: se devuelve tal cual (el llamador escapa)
    }
    $neg = str_starts_with($f, '-');
    $zero = trim(ltrim($f, '-'), '0.,') === '';
    $sign = $neg ? '−' : ($signed && !$zero ? '+' : '');
    return $sign . '$' . ltrim($f, '-');
}

function money(mixed $value, int $decimals = 2, bool $signed = false): string
{
    if ($value === null || $value === '') {
        return '<span class="money diff--none">—</span>';
    }
    $text = money_plain($value, $decimals, $signed);
    return '<span class="money' . (str_starts_with($text, '−') ? ' money-neg' : '') . '">' . e($text) . '</span>';
}

function money_diff(mixed $value, string $tolerance = '0'): string
{
    if ($value === null || $value === '') {
        return '<span class="diff diff--none">—</span>';
    }
    $v = trim((string) $value);
    if (!preg_match('/^-?\d+(\.\d+)?$/', $v)) {
        return '<span class="diff diff--none">' . e($v) . '</span>';
    }
    $abs = ltrim($v, '-');
    $tol = preg_match('/^\d+(\.\d+)?$/', $tolerance) ? $tolerance : '0';
    $f = ltrim(fmt_decimal($abs), '-');
    if (bccomp($abs, $tol, 2) <= 0) {
        $zero = bccomp($abs, '0', 2) === 0;
        return '<span class="diff diff--even"><i class="bi bi-check2" aria-hidden="true"></i>'
            . ($zero ? '$0.00' : '±$' . e($f)) . '<span class="visually-hidden"> (sin diferencia)</span></span>';
    }
    return str_starts_with($v, '-')
        ? '<span class="diff diff--under" title="Pagado de menos"><i class="bi bi-caret-down-fill" aria-hidden="true"></i>−$' . e($f) . '<span class="visually-hidden"> (pagado de menos)</span></span>'
        : '<span class="diff diff--over" title="Pagado de más"><i class="bi bi-caret-up-fill" aria-hidden="true"></i>+$' . e($f) . '<span class="visually-hidden"> (pagado de más)</span></span>';
}

function field_error(string $id, ?string $message): string
{
    if ($message === null || $message === '') {
        return '';
    }
    return '<div class="invalid-feedback" id="' . e($id) . '-error">' . e($message) . '</div>';
}

function field_invalid(string $id, ?string $message): string
{
    if ($message === null || $message === '') {
        return '';
    }
    return ' aria-invalid="true" aria-describedby="' . e($id) . '-error"';
}

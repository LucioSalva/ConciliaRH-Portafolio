<?php

declare(strict_types=1);

namespace ConciliaRH\Domain\Dashboard;

use ConciliaRH\Core\Database;
use ConciliaRH\Domain\Auth\CurrentUser;
use ConciliaRH\Domain\Identity\BulkReviewService;
use ConciliaRH\Domain\Identity\PensionReviewService;

final class AttentionService
{
    public function __construct(private readonly Database $db, private readonly BulkReviewService $bulk, private readonly PensionReviewService $pensions)
    {
    }

    /** @return list<array{clave:string,prioridad:string,icono:string,cantidad:int,texto:string,accion:string,url:string}> */
    public function for(CurrentUser $u): array
    {
        $out = [];
        $add = static function (string $clave, string $prioridad, string $icono, int $n, string $texto, string $accion, string $url) use (&$out): void {
            if ($n > 0) {
                $out[] = ['clave' => $clave, 'prioridad' => $prioridad, 'icono' => $icono, 'cantidad' => $n, 'texto' => $texto, 'accion' => $accion, 'url' => $url];
            }
        };
        if ($u->can('empleados.fusionar_conflictos_aprobar') && $u->can('empleados.duplicados')) {
            $n = (int) $this->db->fetchValue("SELECT count(*) FROM solicitudes_fusion WHERE estado = 'PENDIENTE_APROBACION' AND expira_at > now()
                                               AND solicitado_por <> :u", ['u' => $u->id]);
            $add('fusiones', 'ALTA', 'bi-people', $n, 'solicitud(es) de fusión esperan su segunda firma', 'Revisar solicitudes',
                '/empleados/solicitudes-fusion?estado=PENDIENTE_APROBACION');
        }
        if ($u->can('analisis.seleccionar_perfil') && $u->can('archivos.ver')) {
            $n = (int) $this->db->fetchValue("SELECT count(*) FROM archivos_importados WHERE estado = 'REVISION_MANUAL'");
            $add('archivos', 'ALTA', 'bi-cloud-upload', $n, 'archivo(s) que el sistema no reconoció: indique qué hoja leer', 'Ver archivos', '/importaciones?estado=REVISION_MANUAL');
        }
        if ($u->can('asociaciones.resolver') && $u->can('asociaciones.ver')) {
            $c = $this->bulk->counts();
            $periodo = $this->db->fetchValue(
                "SELECT CASE WHEN count(DISTINCT r.periodo_id) = 1 THEN min(r.periodo_id) END FROM registros_tesoreria r
                   JOIN importaciones_normalizadas n ON n.id = r.importacion_id AND n.estado = 'VIGENTE'
                   JOIN periodos_nomina pe ON pe.id = r.periodo_id AND pe.estado = 'ABIERTO'
                  WHERE r.categoria = 'NOMINA' AND r.asociacion_estado IN ('SUGERENCIA', 'AMBIGUO', 'CONFLICTO', 'NO_IDENTIFICADO')");
            $add('transferencias', 'NORMAL', 'bi-bank', (int) ($c['nomina'] ?? 0), 'pago(s) de nómina sin número de empleado por identificar',
                'Identificar', $periodo !== null && $periodo !== false ? '/periodos/' . (int) $periodo . '/pendientes' : '/periodos');
            if ($u->can('asociaciones.resolver')) {
                $add('pensiones_media', 'NORMAL', 'bi-person-heart', $this->pensions->count(), 'pensión(es) con evidencia media por decidir',
                    'Revisar pensiones', '/revision-pensiones');
            }
        }
        if ($u->can('incidencias.resolver') && $u->can('conciliaciones.ver')) {
            $n = (int) $this->db->fetchValue(
                "SELECT count(*) FROM conciliacion_incidencias i JOIN v_conciliaciones_vigentes v ON v.id = i.conciliacion_id
                  WHERE i.estado IN ('ABIERTA', 'EN_REVISION') AND i.severidad IN ('CRITICA', 'ERROR')");
            $add('incidencias', 'ALTA', 'bi-flag', $n, 'diferencia(s) o error(es) por atender en los cotejos vigentes', 'Ver quincenas', '/periodos');
        }
        if ($u->can('conciliaciones.cerrar') && $u->can('conciliaciones.ver')) {
            $n = (int) $this->db->fetchValue(
                "SELECT count(*) FROM v_conciliaciones_vigentes v JOIN periodos_nomina p ON p.id = v.periodo_id
                  WHERE v.estado IN ('COMPLETADA', 'COMPLETADA_CON_INCIDENCIAS') AND p.estado = 'ABIERTO'");
            $add('cerrar', 'NORMAL', 'bi-lock', $n, 'cotejo(s) terminados listos para revisar y cerrar', 'Ver quincenas', '/periodos');
        }
        if ($u->can('reportes.programar')) {
            $n = (int) $this->db->fetchValue("SELECT count(*) FROM reportes_generados WHERE propietario_id = :u AND estado = 'ERROR'
                                               AND updated_at > now() - interval '7 days'", ['u' => $u->id]);
            $add('reportes', 'NORMAL', 'bi-file-earmark-x', $n, 'reporte(s) programados suyos fallaron esta semana', 'Ver reportes generados', '/reportes/generados?estado=ERROR');
        }
        if ($u->can('empleados.fusionar') && $u->can('empleados.duplicados')) {
            $n = (int) $this->db->fetchValue("SELECT count(*) FROM duplicados_empleados WHERE estado = 'PENDIENTE' AND confianza IN ('ALTA', 'MEDIA')");
            $add('duplicados', 'NORMAL', 'bi-intersect', $n, 'posible(s) duplicado(s) con evidencia alta o media', 'Revisar duplicados', '/empleados/duplicados');
        }
        if ($u->can('sistema.salud')) {
            $n = (int) $this->db->fetchValue("SELECT count(*) FROM procesos WHERE estado = 'EN_EJECUCION'
                AND (coalesce(heartbeat_at, bloqueado_at) < now() - interval '10 minutes' OR iniciado_at < now() - make_interval(secs => timeout_segundos))");
            $add('procesos', 'ALTA', 'bi-cpu', $n, 'trabajo(s) atascados en la cola', 'Ver salud del sistema', '/sistema/salud');
        }
        usort($out, static fn ($a, $b) => [$a['prioridad'] !== 'ALTA', -$a['cantidad']] <=> [$b['prioridad'] !== 'ALTA', -$b['cantidad']]);
        return $out;
    }
}

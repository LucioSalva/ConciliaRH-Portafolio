<?php

declare(strict_types=1);

namespace ConciliaRH\Http\Controllers;

use ConciliaRH\Core\Config;
use ConciliaRH\Core\Database;
use ConciliaRH\Core\Request;
use ConciliaRH\Core\Response;
use ConciliaRH\Domain\Audit\AuditRepository;
use ConciliaRH\Domain\Conciliation\ConciliationComparison;
use ConciliaRH\Domain\Conciliation\ConciliationService;
use ConciliaRH\Domain\Identity\BulkReviewService;
use ConciliaRH\Domain\Jobs\JobQueue;

final class DashboardController extends Controller
{
    public function __construct(
        private readonly Database $db,
        private readonly JobQueue $jobs,
        private readonly AuditRepository $audit,
        private readonly Config $config,
        private readonly ConciliationService $conciliations,
        private readonly ConciliationComparison $cmp,
        private readonly BulkReviewService $bulk,
        private readonly \ConciliaRH\Domain\Dashboard\AttentionService $attention,
    ) {
    }

    public function index(Request $request): Response
    {
        $user = $this->user($request);
        $data = ['title' => 'Tablero', 'breadcrumbs' => [['Tablero', null]]];
        $data['atencion'] = $this->attention->for($user);
        if ($user->can('conciliaciones.ver')) {
            $cid = $this->conciliations->dashboardConciliation();
            $data['conciliation'] = $cid !== null ? $this->conciliations->find($cid) : null;
            $data['alertas'] = $this->alerts($user, $data['conciliation']);
        }
        if ($user->can('usuarios.ver')) {
            $data['userStats'] = $this->db->fetchOne(
                "SELECT count(*) FILTER (WHERE activo AND deleted_at IS NULL) AS activos,
                        count(*) FILTER (WHERE NOT activo AND deleted_at IS NULL) AS inactivos,
                        (SELECT count(DISTINCT usuario_id) FROM sesiones WHERE revocada_at IS NULL
                           AND usuario_id IS NOT NULL AND ultima_actividad_at > now() - make_interval(mins => :idle)) AS en_linea
                   FROM usuarios",
                ['idle' => (int) $this->config->get('session.idle_minutes', 30)]
            );
        }
        if ($user->can('procesos.ver')) {
            $data['queue'] = $this->jobs->health((int) $this->config->get('worker.heartbeat_timeout', 120));
        }
        if ($user->can('periodos.ver')) {
            $data['recentPeriods'] = $this->db->fetchAll(
                "SELECT p.id, p.descripcion, p.estado,
                        (SELECT count(*) FROM lotes_importacion l WHERE l.periodo_id = p.id AND l.estado <> 'ANULADO') AS lotes,
                        (SELECT count(*) FROM archivos_importados a JOIN lotes_importacion l ON l.id = a.lote_id
                          WHERE l.periodo_id = p.id AND a.estado <> 'ANULADO') AS archivos
                   FROM periodos_nomina p WHERE p.estado <> 'ANULADO'
                  ORDER BY p.anio DESC, p.mes DESC, p.quincena DESC LIMIT 5"
            );
        }
        if ($user->can('archivos.ver')) {
            $data['fileStates'] = $this->db->fetchAll(
                "SELECT estado, count(*) AS n FROM archivos_importados WHERE estado <> 'ANULADO' GROUP BY estado ORDER BY 2 DESC"
            );
        }
        if ($user->can('auditoria.ver')) {
            $data['recentAudit'] = $this->audit->recent(6);
        }
        return $this->render('dashboard/index', $data);
    }

    /** @return list<array{nivel:string,texto:string,url:string}> */
    private function alerts(\ConciliaRH\Domain\Auth\CurrentUser $user, ?array $c): array
    {
        $out = [];
        if ($user->can('asociaciones.ver') && !$user->can('asociaciones.resolver')) {
            $n = $this->bulk->counts();
            if (($n['nomina'] ?? 0) > 0) {
                $out[] = ['nivel' => 'warning', 'texto' => number_format($n['nomina']) . ' pagos de nómina aún no tienen número de empleado identificado.', 'url' => '/periodos'];
            }
        }
        if ($c !== null && $c['totales'] !== []) {
            $pn = (int) $this->db->fetchValue("SELECT count(*) FROM conciliacion_pensiones WHERE conciliacion_id = :c AND estado = 'NO_DISPERSADA'", ['c' => $c['id']]);
            if ($pn > 0) {
                $out[] = ['nivel' => 'info', 'texto' => number_format($pn) . ' pensiones retenidas por RH no aparecen dispersadas.',
                          'url' => '/conciliaciones/' . $c['id'] . '/pensiones?estado=NO_DISPERSADA'];
            }
            $pi = (int) ($c['resumen']['pendientes_identidad'] ?? 0);
            if ($pi > 0) {
                $out[] = ['nivel' => 'info', 'texto' => number_format($pi) . ' personas están pendientes de identificar en el último cotejo.',
                          'url' => '/conciliaciones/' . $c['id'] . '/empleados?estado=PENDIENTES'];
            }
            if ($this->conciliations->isLatestFinished($c) && $this->cmp->identityChangesSince($c)['hay_cambios']) {
                $out[] = ['nivel' => 'info', 'texto' => 'Hubo identificaciones o cambios después del último cotejo: vuelva a cotejar la quincena para reflejarlos.',
                          'url' => '/periodos/' . (int) $c['periodo_id']];
            }
        }
        return $out;
    }
}

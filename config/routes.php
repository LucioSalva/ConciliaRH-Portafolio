<?php

declare(strict_types=1);

use ConciliaRH\Core\Router;
use ConciliaRH\Http\Controllers\AccountController;
use ConciliaRH\Http\Controllers\AnalysisController;
use ConciliaRH\Http\Controllers\AssociationController;
use ConciliaRH\Http\Controllers\AuditController;
use ConciliaRH\Http\Controllers\AuthController;
use ConciliaRH\Http\Controllers\CatalogController;
use ConciliaRH\Http\Controllers\BeneficiaryController;
use ConciliaRH\Http\Controllers\BulkReviewController;
use ConciliaRH\Http\Controllers\ConceptController;
use ConciliaRH\Http\Controllers\ReportController;
use ConciliaRH\Http\Controllers\ConciliationController;
use ConciliaRH\Http\Controllers\DashboardController;
use ConciliaRH\Http\Controllers\EmployeeController;
use ConciliaRH\Http\Controllers\HealthController;
use ConciliaRH\Http\Controllers\IdentityMergeController;
use ConciliaRH\Http\Controllers\ScheduledReportController;
use ConciliaRH\Http\Controllers\ImportController;
use ConciliaRH\Http\Controllers\LotController;
use ConciliaRH\Http\Controllers\NotificationController;
use ConciliaRH\Http\Controllers\PensionReviewController;
use ConciliaRH\Http\Controllers\PeriodController;
use ConciliaRH\Http\Controllers\ProcessController;
use ConciliaRH\Http\Controllers\QuincenaController;
use ConciliaRH\Http\Controllers\RoleController;
use ConciliaRH\Http\Controllers\SettingsController;
use ConciliaRH\Http\Controllers\SystemController;
use ConciliaRH\Http\Controllers\UserController;

return static function (Router $r): void {
    $r->get('/api/health', [HealthController::class, 'show'], ['stateless']);
    $r->group(['guest'], static function (Router $r): void {
        $r->get('/login', [AuthController::class, 'showLogin'], [], 'login');
        $r->post('/login', [AuthController::class, 'login']);
    });

    $r->group(['auth'], static function (Router $r): void {
        $r->post('/logout', [AuthController::class, 'logout'], [], 'logout');
        $r->get('/', [DashboardController::class, 'index'], ['perm:dashboard.ver'], 'dashboard');

        $r->get('/notificaciones', [NotificationController::class, 'index'], [], 'notificaciones');
        $r->post('/notificaciones/leer-todas', [NotificationController::class, 'readAll']);
        $r->post('/notificaciones/eliminar-leidas', [NotificationController::class, 'deleteRead']);
        $r->post('/notificaciones/{id}/abrir', [NotificationController::class, 'open']);
        $r->post('/notificaciones/{id}/eliminar', [NotificationController::class, 'delete']);
        $r->get('/api/notificaciones/resumen', [NotificationController::class, 'summary']);

        $r->get('/cuenta/password', [AccountController::class, 'editPassword'], [], 'cuenta.password');
        $r->post('/cuenta/password', [AccountController::class, 'updatePassword']);

        $r->get('/periodos', [PeriodController::class, 'index'], ['perm:periodos.ver'], 'periodos');
        $r->get('/periodos/nuevo', [PeriodController::class, 'create'], ['perm:periodos.crear']);
        $r->post('/periodos', [PeriodController::class, 'store'], ['perm:periodos.crear']);
        $r->get('/periodos/{id}', [QuincenaController::class, 'show'], ['perm:periodos.ver']);
        $r->post('/periodos/{id}/archivos', [QuincenaController::class, 'upload'], ['perm:archivos.cargar']);
        $r->post('/periodos/{id}/cotejar', [QuincenaController::class, 'conciliate'], ['perm:conciliaciones.ejecutar']);
        $r->get('/periodos/{id}/pendientes', [QuincenaController::class, 'pendingList'], ['perm:asociaciones.ver']);
        $r->post('/periodos/{id}/pendientes/confirmar', [QuincenaController::class, 'confirmBulk'], ['perm:asociaciones.masivo']);
        $r->post('/importaciones/{id}/poblacion', [QuincenaController::class, 'population'], ['perm:archivos.clasificar']);
        $r->post('/periodos/{id}', [PeriodController::class, 'update'], ['perm:periodos.editar']);
        $r->post('/periodos/{id}/cerrar', [PeriodController::class, 'close'], ['perm:periodos.cerrar']);
        $r->post('/periodos/{id}/reabrir', [PeriodController::class, 'reopen'], ['perm:periodos.reabrir']);
        $r->post('/periodos/{id}/anular', [PeriodController::class, 'annul'], ['perm:periodos.anular']);

        $r->post('/periodos/{id}/lotes', [LotController::class, 'store'], ['perm:lotes.crear']);
        $r->get('/lotes/{id}', [LotController::class, 'show'], ['perm:lotes.ver']);
        $r->post('/lotes/{id}', [LotController::class, 'update'], ['perm:lotes.editar']);
        $r->post('/lotes/{id}/cerrar', [LotController::class, 'close'], ['perm:lotes.cerrar']);
        $r->post('/lotes/{id}/reabrir', [LotController::class, 'reopen'], ['perm:lotes.reabrir']);
        $r->post('/lotes/{id}/anular', [LotController::class, 'annul'], ['perm:lotes.anular']);
        $r->post('/lotes/{id}/oficial', [LotController::class, 'declareOfficial'], ['perm:lotes.declarar_oficial']);
        $r->post('/lotes/{id}/relaciones', [LotController::class, 'relate'], ['perm:lotes.relacionar']);
        $r->post('/lotes/{id}/relaciones/{relacion}/anular', [LotController::class, 'annulRelation'], ['perm:lotes.relacionar']);

        $r->get('/importaciones', [ImportController::class, 'index'], ['perm:archivos.ver'], 'importaciones');
        $r->get('/importaciones/nueva', [ImportController::class, 'create'], ['perm:archivos.cargar']);
        $r->post('/importaciones', [ImportController::class, 'store'], ['perm:archivos.cargar']);
        $r->get('/importaciones/{id}', [ImportController::class, 'show'], ['perm:archivos.ver']);
        $r->post('/importaciones/{id}/clasificar', [ImportController::class, 'reclassify'], ['perm:archivos.clasificar']);
        $r->post('/importaciones/{id}/reprocesar', [ImportController::class, 'reprocess'], ['perm:archivos.reprocesar']);
        $r->post('/importaciones/{id}/anular', [ImportController::class, 'annul'], ['perm:archivos.anular']);
        $r->post('/importaciones/{id}/descargar', [ImportController::class, 'download'], ['perm:archivos.descargar_original']);
        $r->post('/importaciones/{id}/analisis/reprocesar', [AnalysisController::class, 'reanalyze'], ['perm:analisis.reprocesar']);
        $r->post('/importaciones/{id}/analisis/hoja', [AnalysisController::class, 'selectSheet'], ['perm:analisis.seleccionar_hoja']);
        $r->post('/importaciones/{id}/analisis/perfil', [AnalysisController::class, 'selectProfile'], ['perm:analisis.seleccionar_perfil']);
        $r->get('/importaciones/{id}/comparar', [AnalysisController::class, 'compare'], ['perm:analisis.ver_diagnostico']);
        $r->post('/importaciones/{id}/normalizar', [AssociationController::class, 'normalize'], ['perm:importacion.normalizar']);

        $r->get('/empleados/duplicados', [IdentityMergeController::class, 'duplicates'], ['perm:empleados.duplicados'], 'duplicados');
        $r->post('/empleados/duplicados/detectar', [IdentityMergeController::class, 'detect'], ['perm:empleados.duplicados']);
        $r->post('/empleados/duplicados/{id}/descartar', [IdentityMergeController::class, 'dismiss'], ['perm:empleados.duplicados']);
        $r->get('/empleados/fusion', [IdentityMergeController::class, 'preview'], ['perm:empleados.fusionar']);
        $r->post('/empleados/fusion', [IdentityMergeController::class, 'merge'], ['perm:empleados.fusionar']);
        $r->get('/empleados/fusiones', [IdentityMergeController::class, 'fusions'], ['perm:empleados.duplicados']);
        $r->get('/empleados/fusiones/{id}', [IdentityMergeController::class, 'fusion'], ['perm:empleados.duplicados']);
        $r->post('/empleados/fusiones/{id}/separar', [IdentityMergeController::class, 'separate'], ['perm:empleados.separar']);
        $r->get('/empleados/solicitudes-fusion', [IdentityMergeController::class, 'requests'], ['perm:empleados.duplicados'], 'solicitudes-fusion');
        $r->get('/empleados/solicitudes-fusion/{id}', [IdentityMergeController::class, 'showRequest'], ['perm:empleados.duplicados']);
        $r->post('/empleados/solicitudes-fusion/{id}/aprobar', [IdentityMergeController::class, 'approveRequest'], ['perm:empleados.fusionar_conflictos_aprobar']);
        $r->post('/empleados/solicitudes-fusion/{id}/rechazar', [IdentityMergeController::class, 'rejectRequest'], ['perm:empleados.fusionar_conflictos_aprobar']);
        $r->post('/empleados/solicitudes-fusion/{id}/cancelar', [IdentityMergeController::class, 'cancelRequest'], ['perm:empleados.fusionar_conflictos']);
        $r->get('/empleados', [EmployeeController::class, 'index'], ['perm:empleados.ver'], 'empleados');
        $r->get('/empleados/{id}', [EmployeeController::class, 'show'], ['perm:empleados.ver']);
        $r->post('/empleados/{id}', [EmployeeController::class, 'update'], ['perm:empleados.editar']);
        $r->post('/empleados/{id}/cuentas/{cuenta}/revelar', [EmployeeController::class, 'revealAccount'], ['perm:cuentas.ver_sensible']);
        $r->post('/empleados/{id}/cuentas/{cuenta}/desactivar', [EmployeeController::class, 'deactivateAccount'], ['perm:cuentas.confirmar']);

        $r->get('/asociaciones', [AssociationController::class, 'index'], ['perm:asociaciones.ver'], 'asociaciones');
        $r->get('/asociaciones/rrhh/{id}', [AssociationController::class, 'showRrhh'], ['perm:asociaciones.ver']);
        $r->get('/asociaciones/tesoreria/{id}', [AssociationController::class, 'showTesoreria'], ['perm:asociaciones.ver']);
        $r->post('/asociaciones/rrhh/{id}/asociar', [AssociationController::class, 'associateRrhh'], ['perm:asociaciones.resolver']);
        $r->post('/asociaciones/tesoreria/{id}/asociar', [AssociationController::class, 'associateTesoreria'], ['perm:asociaciones.resolver']);
        $r->post('/asociaciones/rrhh/{id}/rechazar', [AssociationController::class, 'rejectRrhh'], ['perm:asociaciones.resolver']);
        $r->post('/asociaciones/tesoreria/{id}/rechazar', [AssociationController::class, 'rejectTesoreria'], ['perm:asociaciones.resolver']);
        $r->post('/asociaciones/rrhh/{id}/no-identificado', [AssociationController::class, 'unidentifiedRrhh'], ['perm:asociaciones.resolver']);
        $r->post('/asociaciones/tesoreria/{id}/no-identificado', [AssociationController::class, 'unidentifiedTesoreria'], ['perm:asociaciones.resolver']);
        $r->get('/incidencias-identidad', [AssociationController::class, 'incidents'], ['perm:asociaciones.ver'], 'incidencias');
        $r->post('/incidencias-identidad/{id}/cerrar', [AssociationController::class, 'closeIncident'], ['perm:asociaciones.resolver']);

        $r->get('/revision-masiva', [BulkReviewController::class, 'index'], ['perm:asociaciones.ver'], 'revision-masiva');
        $r->post('/revision-masiva/previsualizar', [BulkReviewController::class, 'preview'], ['perm:asociaciones.masivo']);
        $r->post('/revision-masiva/ejecutar', [BulkReviewController::class, 'execute'], ['perm:asociaciones.masivo']);
        $r->get('/revision-masiva/acciones/{id}', [BulkReviewController::class, 'action'], ['perm:asociaciones.ver']);
        $r->get('/revision-pensiones', [PensionReviewController::class, 'index'], ['perm:asociaciones.ver'], 'revision-pensiones');
        $r->get('/revision-pensiones/{id}', [PensionReviewController::class, 'show'], ['perm:asociaciones.ver']);
        $r->post('/revision-pensiones/{id}/confirmar', [PensionReviewController::class, 'confirm'], ['perm:asociaciones.resolver']);
        $r->post('/revision-pensiones/{id}/rechazar', [PensionReviewController::class, 'reject'], ['perm:asociaciones.resolver']);
        $r->post('/revision-pensiones/{id}/pendiente', [PensionReviewController::class, 'pending'], ['perm:asociaciones.resolver']);
        $r->get('/beneficiarios', [BeneficiaryController::class, 'index'], ['perm:beneficiarios.ver'], 'beneficiarios');
        $r->get('/beneficiarios/{id}', [BeneficiaryController::class, 'show'], ['perm:beneficiarios.ver']);
        $r->post('/beneficiarios/{id}/observaciones', [BeneficiaryController::class, 'notes'], ['perm:beneficiarios.editar']);
        $r->post('/beneficiarios/{id}/trabajadores/{relacion}/desactivar', [BeneficiaryController::class, 'deactivateRelation'], ['perm:beneficiarios.editar']);
        $r->post('/beneficiarios/{id}/cuentas/{cuenta}/revelar', [BeneficiaryController::class, 'revealAccount'], ['perm:cuentas.ver_sensible']);

        $r->get('/reportes', [ReportController::class, 'index'], ['perm:reportes.ver'], 'reportes');
        $r->get('/reportes/ver', [ReportController::class, 'view'], ['perm:reportes.ver']);
        $r->post('/reportes/exportar', [ReportController::class, 'export'], ['perm:reportes.exportar']);
        $r->get('/reportes/programados', [ScheduledReportController::class, 'index'], ['perm:reportes.programar'], 'reportes-programados');
        $r->get('/reportes/programados/nuevo', [ScheduledReportController::class, 'create'], ['perm:reportes.programar']);
        $r->post('/reportes/programados', [ScheduledReportController::class, 'store'], ['perm:reportes.programar']);
        $r->get('/reportes/programados/{id}', [ScheduledReportController::class, 'edit'], ['perm:reportes.programar']);
        $r->post('/reportes/programados/{id}', [ScheduledReportController::class, 'update'], ['perm:reportes.programar']);
        $r->post('/reportes/programados/{id}/pausar', [ScheduledReportController::class, 'pause'], ['perm:reportes.programar']);
        $r->post('/reportes/programados/{id}/reactivar', [ScheduledReportController::class, 'activate'], ['perm:reportes.programar']);
        $r->post('/reportes/programados/{id}/generar', [ScheduledReportController::class, 'generate'], ['perm:reportes.programar']);
        $r->get('/reportes/generados', [ScheduledReportController::class, 'generated'], ['perm:reportes.programar'], 'reportes-generados');
        $r->post('/reportes/generados/{id}/descargar', [ScheduledReportController::class, 'download'], ['perm:reportes.exportar']);

        $r->get('/conciliaciones', [ConciliationController::class, 'index'], ['perm:conciliaciones.ver'], 'conciliaciones');
        $r->post('/conciliaciones', [ConciliationController::class, 'store'], ['perm:conciliaciones.ejecutar']);
        $r->get('/conciliaciones/{id}', [ConciliationController::class, 'show'], ['perm:conciliaciones.ver']);
        $r->get('/conciliaciones/{id}/empleados', [ConciliationController::class, 'employees'], ['perm:conciliaciones.ver']);
        $r->get('/conciliaciones/{id}/pensiones', [ConciliationController::class, 'pensions'], ['perm:conciliaciones.ver']);
        $r->get('/conciliaciones/{id}/comparar/{otra}', [ConciliationController::class, 'compare'], ['perm:conciliaciones.ver']);
        $r->get('/conciliaciones/{id}/empleados/{empleado}', [ConciliationController::class, 'employee'], ['perm:conciliaciones.ver']);
        $r->get('/conciliaciones/{id}/incidencias', [ConciliationController::class, 'incidents'], ['perm:incidencias.ver']);
        $r->post('/conciliaciones/{id}/incidencias/{incidencia}', [ConciliationController::class, 'updateIncident'], ['perm:incidencias.resolver']);
        $r->post('/conciliaciones/{id}/cerrar', [ConciliationController::class, 'close'], ['perm:conciliaciones.cerrar']);
        $r->post('/conciliaciones/{id}/invalidar', [ConciliationController::class, 'invalidate'], ['perm:conciliaciones.invalidar']);
        $r->post('/conciliaciones/{id}/exportar', [ConciliationController::class, 'export'], ['perm:conciliaciones.exportar']);
        $r->post('/lotes/{id}/relaciones-poliza', [ConciliationController::class, 'relatePolicy'], ['perm:conciliaciones.relacionar']);
        $r->post('/lotes/{id}/relaciones-poliza/{relacion}/anular', [ConciliationController::class, 'annulPolicyRelation'], ['perm:conciliaciones.relacionar']);

        $r->get('/conceptos', [ConceptController::class, 'index'], ['perm:conceptos.editar'], 'conceptos');
        $r->post('/conceptos/{id}', [ConceptController::class, 'update'], ['perm:conceptos.editar']);

        $r->get('/catalogos', [CatalogController::class, 'index'], ['perm:catalogos.administrar'], 'catalogos');
        $r->post('/catalogos', [CatalogController::class, 'store'], ['perm:catalogos.administrar']);
        $r->post('/catalogos/{id}/estado', [CatalogController::class, 'toggle'], ['perm:catalogos.administrar']);

        $r->get('/usuarios', [UserController::class, 'index'], ['perm:usuarios.ver'], 'usuarios');
        $r->get('/usuarios/nuevo', [UserController::class, 'create'], ['perm:usuarios.crear']);
        $r->post('/usuarios', [UserController::class, 'store'], ['perm:usuarios.crear']);
        $r->get('/usuarios/{id}', [UserController::class, 'edit'], ['perm:usuarios.ver']);
        $r->post('/usuarios/{id}', [UserController::class, 'update'], ['perm:usuarios.editar']);
        $r->post('/usuarios/{id}/estado', [UserController::class, 'toggleActive'], ['perm:usuarios.desactivar']);
        $r->post('/usuarios/{id}/eliminar', [UserController::class, 'destroy'], ['perm:usuarios.eliminar']);
        $r->post('/usuarios/{id}/password', [UserController::class, 'resetPassword'], ['perm:usuarios.restablecer_password']);
        $r->post('/usuarios/{id}/sesiones/cerrar', [UserController::class, 'revokeSessions'], ['perm:usuarios.cerrar_sesiones']);

        $r->get('/roles', [RoleController::class, 'index'], ['perm:roles.ver'], 'roles');
        $r->post('/roles', [RoleController::class, 'store'], ['perm:roles.administrar']);
        $r->get('/roles/{id}', [RoleController::class, 'show'], ['perm:roles.ver']);
        $r->post('/roles/{id}/permisos', [RoleController::class, 'updatePermissions'], ['perm:roles.administrar']);

        $r->get('/auditoria', [AuditController::class, 'index'], ['perm:auditoria.ver'], 'auditoria');
        $r->get('/auditoria/{id}', [AuditController::class, 'show'], ['perm:auditoria.ver']);
        $r->post('/auditoria/verificar', [AuditController::class, 'verify'], ['perm:auditoria.verificar']);

        $r->get('/configuracion', [SettingsController::class, 'index'], ['perm:configuracion.ver'], 'configuracion');
        $r->post('/configuracion', [SettingsController::class, 'update'], ['perm:configuracion.editar']);

        $r->get('/procesos', [ProcessController::class, 'index'], ['perm:procesos.diagnostico'], 'procesos');
        $r->get('/procesos/{id}', [ProcessController::class, 'show'], ['perm:procesos.ver']);
        $r->post('/procesos/diagnostico', [ProcessController::class, 'diagnostic'], ['perm:procesos.diagnostico']);
        $r->post('/procesos/{id}/cancelar', [ProcessController::class, 'cancel'], ['perm:procesos.cancelar']);

        $r->get('/sistema/salud', [SystemController::class, 'health'], ['perm:sistema.salud'], 'salud');
        $r->post('/sistema/salud/almacenamiento', [SystemController::class, 'storageCheck'], ['perm:sistema.mantenimiento']);
        $r->get('/api/sistema/salud', [SystemController::class, 'api'], ['perm:sistema.salud']);

        $r->get('/api/procesos/{id}', [ProcessController::class, 'status'], ['perm:procesos.ver']);
        $r->get('/api/sesion', [AccountController::class, 'sessionInfo']);
        $r->get('/api/importaciones/{id}', [ImportController::class, 'status'], ['perm:archivos.ver']);
        $r->get('/api/importaciones/{id}/analisis', [AnalysisController::class, 'api'], ['perm:analisis.ver']);
        $r->get('/api/importaciones/{id}/normalizacion', [AssociationController::class, 'api'], ['perm:asociaciones.ver']);
        $r->get('/api/conciliaciones/{id}', [ConciliationController::class, 'api'], ['perm:conciliaciones.ver']);
    });
};

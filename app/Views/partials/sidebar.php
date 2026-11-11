<?php
/** @var \ConciliaRH\Core\Template $this */
$god = isset($currentUser) && $currentUser instanceof \ConciliaRH\Domain\Auth\CurrentUser && $currentUser->isGod();
$operador = $god || (isset($currentUser) && $currentUser instanceof \ConciliaRH\Domain\Auth\CurrentUser && $currentUser->can('archivos.cargar'));
$sections = [
    'Principal' => [
        ['/', 'bi-speedometer2', 'Tablero', 'dashboard.ver'],
        ['/periodos', 'bi-calendar3', 'Quincenas', 'periodos.ver'],
    ],
    'Consulta' => [
        ['/empleados', 'bi-person-vcard', 'Empleados', 'empleados.ver'],
        ['/beneficiarios', 'bi-people', 'Beneficiarios de pensión', 'beneficiarios.ver'],
        ['/reportes', 'bi-file-earmark-bar-graph', 'Reportes', 'reportes.ver'],
    ],
    'Avanzado' => $operador ? [
        ['/importaciones', 'bi-cloud-upload', 'Archivos cargados', 'archivos.ver'],
        ['/conciliaciones', 'bi-clipboard2-check', 'Cotejos', 'conciliaciones.ver'],
        ['/revision-masiva', 'bi-ui-checks', 'Revisión masiva', 'asociaciones.ver'],
        ['/revision-pensiones', 'bi-person-heart', 'Revisión de pensiones', 'asociaciones.ver'],
        ['/asociaciones', 'bi-link-45deg', 'Asociaciones', 'asociaciones.ver'],
        ['/incidencias-identidad', 'bi-exclamation-diamond', 'Incidencias de identidad', 'asociaciones.ver'],
        ['/empleados/duplicados', 'bi-intersect', 'Posibles duplicados', 'empleados.duplicados'],
        ['/empleados/solicitudes-fusion', 'bi-people-fill', 'Solicitudes de fusión', 'empleados.duplicados'],
        ['/reportes/programados', 'bi-calendar-check', 'Reportes programados', 'reportes.programar'],
    ] : [],
    'Administración' => [
        ['/usuarios', 'bi-person-gear', 'Usuarios', 'usuarios.ver'],
        ['/roles', 'bi-shield-lock', 'Roles y permisos', 'roles.ver'],
        ['/catalogos', 'bi-tags', 'Catálogos', 'catalogos.administrar'],
        ['/conceptos', 'bi-list-columns', 'Conceptos de nómina', 'conceptos.editar'],
        ['/auditoria', 'bi-journal-check', 'Auditoría', 'auditoria.ver'],
        ['/configuracion', 'bi-sliders', 'Configuración', 'configuracion.ver'],
    ],
    'Sistema' => [
        ['/procesos', 'bi-cpu', 'Procesos', 'procesos.diagnostico'],
        ['/sistema/salud', 'bi-heart-pulse', 'Salud del sistema', 'sistema.salud'],
    ],
];
?>
<aside id="sidebar" class="sidebar" aria-label="Navegación principal">
    <a class="sidebar__brand" href="/">
        <img src="<?= e($this->asset('img/brand/isotipo.svg')) ?>" alt="" width="44" height="44">
        <span>
            <span class="sidebar__brand-name"><?= e($appName) ?></span>
            <span class="sidebar__brand-sub">Tesorería Municipal</span>
        </span>
    </a>
    <nav class="sidebar__nav">
        <?php
        $best = '';
        foreach ($sections as $items) {
            foreach ($items as $i) {
                if ($this->active($i[0]) !== '' && strlen($i[0]) > strlen($best)) {
                    $best = $i[0];
                }
            }
        }
        ?>
        <?php foreach ($sections as $label => $items):
            $visible = array_filter($items, fn ($i) => $this->can($i[3]));
            if ($visible === []) { continue; } ?>
            <div class="sidebar__section"><?= e($label) ?></div>
            <?php foreach ($visible as [$href, $icon, $text]): $active = $href === $best ? $this->active($href) : ''; ?>
                <a class="sidebar__link <?= e($active) ?>" href="<?= e($href) ?>" title="<?= e($text) ?>"<?= $active ? ' aria-current="page"' : '' ?>>
                    <i class="bi <?= e($icon) ?>" aria-hidden="true"></i><span class="sidebar__label"><?= e($text) ?></span>
                </a>
            <?php endforeach; ?>
        <?php endforeach; ?>
    </nav>
    <div class="sidebar__footer">
        <span class="sidebar__footer-text">Sistema Integral de Conciliación<br>de Nómina y Pagos</span>
        <button type="button" class="sidebar__collapse" data-sidebar-collapse aria-controls="sidebar" aria-pressed="false"
                aria-label="Contraer la navegación" title="Contraer la navegación">
            <i class="bi bi-chevron-double-left" aria-hidden="true"></i>
        </button>
    </div>
</aside>

<?php
/** @var \ConciliaRH\Core\Template $this */
/** @var \ConciliaRH\Domain\Auth\CurrentUser|null $currentUser */
?>
<header class="topbar">
    <button type="button" class="btn btn-outline-secondary btn-icon topbar__toggle" data-sidebar-toggle
            aria-controls="sidebar" aria-expanded="false" aria-label="Abrir menú de navegación">
        <i class="bi bi-list" aria-hidden="true"></i>
    </button>
    <p class="topbar__title d-none d-md-block"><?= e($title ?? '') ?></p>
    <div class="topbar__spacer"></div>
    <?php if (($appEnv ?? '') !== 'production'): ?>
        <span class="topbar__env"><?= status_badge('ENTORNO', 'Entorno de ' . $appEnv) ?></span>
    <?php endif; ?>
    <?php if ($currentUser !== null): ?>
        <div class="dropdown notif" data-notif data-notif-url="/api/notificaciones/resumen">
            <button class="notif__btn" type="button" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false"
                    aria-label="Notificaciones" data-notif-toggle>
                <i class="bi bi-bell" aria-hidden="true"></i><span class="notif__count" data-notif-count hidden></span>
            </button>
            <span class="visually-hidden" aria-live="polite" data-notif-live></span>
            <div class="dropdown-menu dropdown-menu-end notif__panel">
                <div class="notif__head">
                    <h2 class="notif__heading">Notificaciones</h2>
                    <a class="notif__all" href="/notificaciones">Ver todas</a>
                </div>
                <ul class="notif__list" data-notif-list></ul>
                <p class="notif__empty" data-notif-empty>Está al día: no hay notificaciones nuevas.</p>
                <template data-notif-item>
                    <li class="notif__item">
                        <form method="post" data-f="form"><input type="hidden" name="_token" value="">
                            <button type="submit" class="notif__open">
                                <i class="bi notif__icon" data-f="icono" aria-hidden="true"></i>
                                <span class="notif__body"><span class="notif__item-title" data-f="titulo"></span><span class="notif__text" data-f="mensaje"></span>
                                    <span class="notif__meta"><span data-f="area"></span> · <span data-f="fecha"></span></span></span>
                            </button>
                        </form>
                    </li>
                </template>
            </div>
        </div>
        <div class="dropdown">
            <button class="user-menu__btn" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Menú de usuario">
                <span class="avatar" aria-hidden="true"><?= e($currentUser->initials()) ?></span>
                <span class="user-menu__text">
                    <span class="user-menu__name d-block"><?= e($currentUser->nombre) ?></span>
                    <span class="user-menu__role d-block"><?= e(implode(' · ', $currentUser->roles)) ?></span>
                </span>
                <i class="bi bi-chevron-down small" aria-hidden="true"></i>
            </button>
            <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                <li><span class="dropdown-item-text small text-muted">Usuario: <strong><?= e($currentUser->username) ?></strong></span></li>
                <li><hr class="dropdown-divider"></li>
                <li><a class="dropdown-item" href="/cuenta/password"><i class="bi bi-key me-2" aria-hidden="true"></i>Cambiar mi contraseña</a></li>
                <li>
                    <form method="post" action="/logout">
                        <?= $this->csrf() ?>
                        <button type="submit" class="dropdown-item text-danger"><i class="bi bi-box-arrow-right me-2" aria-hidden="true"></i>Cerrar sesión</button>
                    </form>
                </li>
            </ul>
        </div>
    <?php endif; ?>
</header>

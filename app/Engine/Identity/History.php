<?php

declare(strict_types=1);

namespace ConciliaRH\Engine\Identity;

use ConciliaRH\Core\Database;
use ConciliaRH\Engine\Support\BatchInsert;

final class History
{
    /** @param list<list<mixed>> $rows */
    public static function insert(Database $db, array $rows): void
    {
        BatchInsert::run($db, 'asociaciones_historial', ['registro_tipo', 'registro_id', 'accion', 'estado_anterior', 'estado_nuevo',
            'empleado_anterior', 'empleado_nuevo', 'metodo', 'confianza', 'usuario_id', 'proceso_id', 'motivo'], $rows);
    }
}

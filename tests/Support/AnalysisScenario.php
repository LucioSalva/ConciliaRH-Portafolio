<?php

declare(strict_types=1);

namespace ConciliaRH\Tests\Support;

use ConciliaRH\Core\Database;

/**
 * Escenarios de ANÁLISIS confirmados por el propietario (el rol web no puede escribir resultados del
 * motor). Datos FICTICIOS. Todo lo creado se elimina en tearDown mediante committedOwner().
 */
trait AnalysisScenario
{
    /** @var array{u?:int,p?:int,l?:list<int>,a?:list<int>} */
    private array $scn = [];

    /**
     * @param array{estado?:string,huella?:?string,lote_estado?:string,staging?:list<string>,nombre?:string,lote?:int} $o
     * @return array{archivo:int,analisis:int,hojas:list<int>,lote:int,periodo:int}
     */
    protected function analyzedFile(array $o = []): array
    {
        $ids = &$this->scn;
        $db = $this->committedOwner(static function (Database $db) use (&$ids): void {
            if (($ids['a'] ?? []) === []) {
                return;
            }
            $list = implode(',', array_map('intval', $ids['a']));
            $db->execute("UPDATE archivos_importados SET analisis_vigente_id = NULL, proceso_id = NULL, archivo_relacionado_id = NULL WHERE id IN ($list)");
            foreach (['staging_registros', 'controles_archivo', 'archivo_hojas', 'analisis_archivo'] as $t) {
                $db->execute("DELETE FROM $t WHERE archivo_id IN ($list)");
            }
            $db->execute("DELETE FROM procesos WHERE parametros->>'archivo_id' IN (" . implode(',', array_map(static fn ($i) => "'" . (int) $i . "'", $ids['a'])) . ')');
            $db->execute("DELETE FROM archivos_importados WHERE id IN ($list)");
            $db->execute('DELETE FROM lotes_importacion WHERE periodo_id = :p', ['p' => $ids['p']]);
            $db->execute('DELETE FROM periodos_nomina WHERE id = :p', ['p' => $ids['p']]);
            $db->execute('DELETE FROM usuarios WHERE id = :u', ['u' => $ids['u']]);
            $ids = [];
        });
        if (!isset($this->scn['u'])) {
            $this->scn['u'] = (int) $db->fetchValue("INSERT INTO usuarios (username, nombre, password_hash) VALUES ('owner.analisis', 'Owner Análisis', repeat('x', 30)) RETURNING id");
            $this->scn['p'] = (int) $db->fetchValue("INSERT INTO periodos_nomina (anio, mes, quincena, fecha_inicio, fecha_fin, descripcion, creado_por)
                VALUES (2098, 11, 1, '2098-11-01', '2098-11-15', 'PERIODO ANALISIS PHP', :u) RETURNING id", ['u' => $this->scn['u']]);
            $this->scn['l'] = [];
            $this->scn['a'] = [];
            foreach ([1, 2] as $_) {
                $this->scn['l'][] = (int) $db->fetchValue("SELECT id FROM fn_crear_lote(:p, '', '', '', '', :u)", ['p' => $this->scn['p'], 'u' => $this->scn['u']]);
            }
        }
        $u = $this->scn['u'];
        $lote = $o['lote'] ?? $this->scn['l'][0];
        $n = count($this->scn['a']);
        $tipo = (int) $db->fetchValue("SELECT id FROM tipos_documento WHERE codigo = 'TESORERIA_DISPERSION'");
        $origen = (int) $db->fetchValue("SELECT id FROM origenes_administrativos WHERE codigo = 'SIN_DEFINIR'");
        $perfil = (int) $db->fetchValue("SELECT id FROM perfiles_importacion WHERE codigo = 'TESORERIA_BBVA'");
        $h = bin2hex(random_bytes(16));
        $uuid = sprintf('%s-%s-4%s-8%s-%s', substr($h, 0, 8), substr($h, 8, 4), substr($h, 13, 3), substr($h, 17, 3), substr($h, 20, 12));
        $aid = (int) $db->fetchValue(
            "INSERT INTO archivos_importados (uuid, lote_id, tipo_documento_id, origen_administrativo_id, nombre_original, extension, mime_detectado,
                firma, tamano_bytes, sha256, ruta_relativa, usuario_carga_id)
             VALUES (CAST(:uuid AS uuid), :l, :t, :o, :n, 'xlsx', 'application/zip', 'OOXML', 100, :sha, :ruta, :u) RETURNING id",
            ['uuid' => $uuid, 'l' => $lote, 't' => $tipo, 'o' => $origen, 'n' => $o['nombre'] ?? "BBVA FICTICIO $n.xlsx",
             'sha' => hash('sha256', "archivo-$n"), 'ruta' => "imports/{$this->scn['p']}/$lote/$uuid/original.xlsx", 'u' => $u]
        );
        $this->scn['a'][] = $aid;
        foreach (['VERIFICANDO', 'VERIFICADO', 'ANALIZANDO'] as $st) {
            $db->execute('UPDATE archivos_importados SET estado = :s WHERE id = :id', ['s' => $st, 'id' => $aid]);
        }
        // el trigger encoló ANALIZAR_ARCHIVO al quedar VERIFICADO: se da por completado
        $db->execute("UPDATE procesos SET estado = 'COMPLETADO', finalizado_at = now(), created_at = now() - interval '1 hour'
                      WHERE parametros->>'archivo_id' = :id", ['id' => (string) $aid]);
        $estado = $o['estado'] ?? 'ANALIZADO';
        $huella = array_key_exists('huella', $o) ? $o['huella'] : hash('sha256', 'contenido-comun');
        $revision = $estado === 'REVISION_MANUAL';
        $an = (int) $db->fetchValue(
            "INSERT INTO analisis_archivo (archivo_id, numero, vigente, resultado, motor_version, reglas_version, perfil_id, perfil_confianza,
                perfil_origen, perfil_puntaje, hoja_indice, hoja_origen, total_hojas, filas_detalle, huella_contenido, huella_version, advertencias, metricas)
             VALUES (:a, 1, true, :r, '1.0', 1, :p, :c, :po, 95, :h, :ho, 2, 3, :hu, :hv, CAST(:adv AS jsonb), '{\"total_ms\": 5}') RETURNING id",
            ['a' => $aid, 'r' => $estado, 'p' => $revision ? null : $perfil, 'c' => $revision ? 'NINGUNA' : 'ALTA',
             'po' => $revision ? 'NINGUNO' : 'AUTOMATICO', 'h' => $revision ? null : 0, 'ho' => $revision ? 'NINGUNA' : 'AUTOMATICO',
             'hu' => $revision ? null : $huella, 'hv' => $revision || $huella === null ? null : 1,
             'adv' => json_encode($revision ? [['codigo' => 'AMBIGUEDAD_HOJAS', 'severidad' => 'REVISION', 'mensaje' => 'Hojas equivalentes con contenido distinto.', 'hoja' => null]] : [])]
        );
        $hojas = [];
        foreach ([0 => 'Version A', 1 => 'Version B'] as $i => $name) {
            $sel = !$revision && $i === 0;
            $hojas[] = $hid = (int) $db->fetchValue(
                "INSERT INTO archivo_hojas (analisis_id, archivo_id, indice, nombre_original, visibilidad, filas_detectadas, columnas_detectadas,
                    rango_usado, encabezado_fila, encabezado_filas, perfil_sugerido_id, puntaje_candidata, seleccionada, estado, motivo_seleccion,
                    filas_detalle, filas_total, tiene_filtros, tiene_formulas, cantidad_formulas)
                 VALUES (:an, :a, :i, :n, 'VISIBLE', 5, 5, 'A1:E5', 2, 1, :p, 85, :sel, :st, 'Motivo de prueba', 3, 1, :filt, true, 1) RETURNING id",
                ['an' => $an, 'a' => $aid, 'i' => $i, 'n' => $name, 'p' => $perfil, 'sel' => $sel ? 'true' : 'false',
                 'st' => $sel ? 'SELECCIONADA' : ($revision ? 'CANDIDATA' : 'DESCARTADA'), 'filt' => $i === 1 ? 'true' : 'false']
            );
            foreach ([['NUM', 'Núm.', 'NUM_EMPLEADO'], ['NOMBRE', 'Nombre', 'NOMBRE'], ['IMPORTE', 'IMPORTE', 'IMPORTE']] as $c => [$norm, $orig, $canon]) {
                $db->execute("INSERT INTO archivo_columnas (hoja_id, indice, letra, encabezado_original, encabezado_normalizado, campo_canonico, tipo_inferido)
                              VALUES (:h, :i, :l, :o, :n, :c, 'TEXTO')", ['h' => $hid, 'i' => $c, 'l' => chr(65 + $c), 'o' => $orig, 'n' => $norm, 'c' => $canon]);
            }
        }
        if (!$revision) {
            $db->execute(
                "INSERT INTO controles_archivo (analisis_id, archivo_id, hoja_id, codigo, descripcion, tipo_valor, valor_declarado, valor_calculado,
                    diferencia, tolerancia, estado, mensaje)
                 VALUES (:an, :a, :h, 'TOTAL_IMPORTE', 'Total declarado vs. suma del importe', 'MONEDA', 3000.50, 3000.50, 0, 0.01, 'CUADRA', 'El total coincide.')",
                ['an' => $an, 'a' => $aid, 'h' => $hojas[0]]
            );
            foreach ($o['staging'] ?? ['r1', 'r2', 'r3'] as $k => $rec) {
                $db->execute(
                    "INSERT INTO staging_registros (analisis_id, archivo_id, hoja_id, fila_origen, importe, importe_estado, datos, hash_registro)
                     VALUES (:an, :a, :h, :f, 1000.00, 'OK', CAST(:d AS jsonb), :hash)",
                    ['an' => $an, 'a' => $aid, 'h' => $hojas[0], 'f' => 3 + $k, 'd' => json_encode(['NOMBRE' => 'PERSONA FICTICIA SECRETA']),
                     'hash' => hash('sha256', $rec)]
                );
            }
        }
        $db->execute(
            'UPDATE archivos_importados SET estado = :e, perfil_importacion_id = :p, huella_contenido = :h, huella_version = :hv,
                    clasificacion_duplicidad = :c, analisis_vigente_id = :an, procesado_at = now() WHERE id = :id',
            ['e' => $estado, 'p' => $revision ? null : $perfil, 'h' => $revision ? null : $huella, 'hv' => $revision || $huella === null ? null : 1,
             'c' => $revision ? 'REVISION_MANUAL' : 'NUEVO', 'an' => $an, 'id' => $aid]
        );
        if (($o['lote_estado'] ?? 'ABIERTO') === 'CERRADO') {
            $db->execute("UPDATE lotes_importacion SET estado = 'CERRADO', cerrado_por = :u, cerrado_at = now() WHERE id = :l", ['u' => $u, 'l' => $lote]);
        }
        return ['archivo' => $aid, 'analisis' => $an, 'hojas' => $hojas, 'lote' => $lote, 'periodo' => $this->scn['p']];
    }
}

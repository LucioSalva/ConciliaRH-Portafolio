<?php

declare(strict_types=1);

namespace ConciliaRH\Tests\Support;

use ConciliaRH\Core\Config;
use ConciliaRH\Core\Database;

/**
 * Escenario de IDENTIDADES (FASE 4) confirmado por el propietario: el rol web no puede crear empleados ni
 * registros (los crea el motor). Datos FICTICIOS; cuentas cifradas con el anillo de llaves de pruebas.
 * Limpieza: modo de mantenimiento explícito del propietario (tablas de sólo inserción).
 */
trait IdentityScenario
{
    use AnalysisScenario;

    public const CLABE = '002180000000012345';
    public const CUENTA_SANT = '60012345678';

    /** @var array<string,mixed> */
    private array $idn = [];

    public static function fakeRfc(int $i): string
    {
        return sprintf('FICT%06dAB%d', $i, $i % 10);
    }

    public static function fakeCurp(int $i): string
    {
        return sprintf('FAXT%06dHMCBCD%s%d', $i, chr(65 + $i % 26), $i % 10);
    }

    /** @return array{0:string,1:string,2:string} [hmac, cifrado (bytea hex), key_id] */
    public static function protect(string $digits): array
    {
        $env = DatabaseTestCase::env();
        $keyId = $env->string('ACCOUNT_ENC_KEY_ID', 'k1');
        $ring = Config::parseKeyRing($env->string('ACCOUNT_ENC_KEYS'));
        $nonce = random_bytes(12);
        $tag = '';
        $ct = openssl_encrypt($digits, 'aes-256-gcm', $ring[$keyId], OPENSSL_RAW_DATA, $nonce, $tag, 'conciliarh:cuenta:v1:' . $keyId);
        return [hash_hmac('sha256', 'cuenta:' . $digits, $env->string('ACCOUNT_HMAC_KEY')), '\\x' . bin2hex("\x01" . $nonce . $ct . $tag), $keyId];
    }

    /**
     * @return array{emp1:int,emp2:int,rrhh1:int,rrhh2:int,sug:int,conf:int,pen:int,auto:int,cuenta2:int,imp_t:int,imp_r:int,archivo_t:int,archivo_r:int,lote:int,lote2:int,periodo:int}
     */
    protected function identityScenario(): array
    {
        if ($this->idn !== []) {
            return $this->idn;
        }
        $fr = $this->analyzedFile(['nombre' => 'TABULADO FICTICIO.xlsx']);
        $ft = $this->analyzedFile(['nombre' => 'DISPERSION FICTICIA.xls']);
        $db = $this->committedOwner(static function (Database $db): void {
            $db->execute("SET conciliarh.identidad_mantenimiento = 'on'");
            $db->execute('TRUNCATE acciones_masivas, acciones_masivas_registros, beneficiarios_pension, beneficiario_trabajadores RESTART IDENTITY CASCADE');
            // Llave HMAC activa de las pruebas = «h1» (ACCOUNT_HMAC_KEY); una prueba de rotación no debe afectar a las demás
            $db->execute('TRUNCATE hmac_rotaciones RESTART IDENTITY');
            $db->execute("UPDATE hmac_llaves_estado SET key_id_activa = 'h1', kcv_activa = NULL WHERE key_id_activa <> 'h1' OR kcv_activa IS NOT NULL");
            $db->execute('TRUNCATE importaciones_normalizadas, empleados, empleado_historial, registros_rrhh, registros_rrhh_conceptos,
                          registros_tesoreria, cuentas_bancarias, polizas_globales, polizas_globales_lineas, polizas_egreso_lineas,
                          incidencias_identidad, asociaciones_historial, lotes_relaciones RESTART IDENTITY CASCADE');
            $db->execute("DELETE FROM procesos WHERE tipo = 'NORMALIZAR_REGISTROS'");
            $db->execute('RESET conciliarh.identidad_mantenimiento');
        });
        // Los triggers encolaron NORMALIZAR_REGISTROS al cerrar los análisis: en este escenario los datos los "escribe el motor"
        $db->execute("UPDATE procesos SET estado = 'COMPLETADO', finalizado_at = now() WHERE tipo = 'NORMALIZAR_REGISTROS'
                      AND parametros->>'archivo_id' IN (:a, :b)", ['a' => (string) $fr['archivo'], 'b' => (string) $ft['archivo']]);
        $pRrhh = (int) $db->fetchValue("SELECT id FROM perfiles_importacion WHERE codigo = 'RRHH_TABULADO_POLICIA'");
        $pInter = (int) $db->fetchValue("SELECT id FROM perfiles_importacion WHERE codigo = 'TESORERIA_INTERBANCARIA'");
        $u = $this->scn['u'];
        $s = ['archivo_r' => $fr['archivo'], 'archivo_t' => $ft['archivo'], 'lote' => $fr['lote'], 'lote2' => $this->scn['l'][1], 'periodo' => $fr['periodo']];
        $db->transaction(function (Database $db) use (&$s, $fr, $ft, $pRrhh, $pInter): void {
            $s['imp_r'] = (int) $db->fetchValue(
                "INSERT INTO importaciones_normalizadas (archivo_id, analisis_id, numero, tipo_registro, periodo_id, lote_id, perfil_id, grupo,
                    matching_version, puede_actualizar_maestro, registros, metricas)
                 VALUES (:a, :an, 1, 'RRHH', :p, :l, :pf, 'POLICIA', 1, true, 2, CAST(:m AS jsonb)) RETURNING id",
                ['a' => $fr['archivo'], 'an' => $fr['analisis'], 'p' => $fr['periodo'], 'l' => $fr['lote'], 'pf' => $pRrhh,
                 'm' => json_encode(['altas' => 2, 'por_estado' => ['ASOCIADO_AUTOMATICO' => 2], 'por_metodo' => ['ALTA_RRHH' => 2]])]);
            [$r1, $r2] = array_map('intval', array_column($db->fetchAll("SELECT nextval(pg_get_serial_sequence('registros_rrhh', 'id')) AS id FROM generate_series(1, 2)"), 'id'));
            [$e1, $e2] = array_map('intval', array_column($db->fetchAll("SELECT nextval(pg_get_serial_sequence('empleados', 'id')) AS id FROM generate_series(1, 2)"), 'id'));
            foreach ([[$e1, $r1, 1, 'PERSONA FICTICIA UNO'], [$e2, $r2, 2, 'PERSONA FICTICIA DOS']] as [$e, $r, $i, $name]) {
                $db->execute(
                    "INSERT INTO empleados (id, numero_empleado, nombre_completo, nombre_normalizado, rfc, curp, issemym, grupo, area, cargo,
                        origen_importacion_id, origen_registro_id, primer_periodo_id, ultimo_periodo_id, ultimo_periodo_fecha)
                     VALUES (:id, :n, :nom, :nom2, :rfc, :curp, :iss, 'POLICIA', 'AREA FICTICIA', 'POLICIA', :imp, :r, :p, :p2, '2098-11-15')",
                    ['id' => $e, 'n' => sprintf('9%04d', $i), 'nom' => $name, 'nom2' => $name, 'rfc' => self::fakeRfc($i), 'curp' => self::fakeCurp($i),
                     'iss' => '12345' . $i, 'imp' => $s['imp_r'], 'r' => $r, 'p' => $fr['periodo'], 'p2' => $fr['periodo']]);
                $db->execute(
                    "INSERT INTO registros_rrhh (id, importacion_id, archivo_id, analisis_id, periodo_id, lote_id, perfil_id, grupo, hoja_id, hoja_indice,
                        hoja_nombre, fila_origen, hash_registro, numero_original, numero_empleado, numero_valido, nombre_original, nombre_normalizado,
                        rfc_original, rfc, rfc_valido, curp_original, curp, curp_valida, area, cargo, percepciones, deducciones, neto,
                        empleado_id, asociacion_estado, asociacion_metodo, asociacion_confianza)
                     VALUES (:id, :imp, :a, :an, :p, :l, :pf, 'POLICIA', :h, 0, 'Tabla', :f, :hash, :n, :n2, true, :nom, :nom2, :rfc, :rfc2, true,
                        :curp, :curp2, true, 'AREA FICTICIA', 'POLICIA', 10000.00, 1500.00, 8500.00, :e, 'ASOCIADO_AUTOMATICO', 'ALTA_RRHH', 'ALTA')",
                    ['id' => $r, 'imp' => $s['imp_r'], 'a' => $fr['archivo'], 'an' => $fr['analisis'], 'p' => $fr['periodo'], 'l' => $fr['lote'],
                     'pf' => $pRrhh, 'h' => $fr['hojas'][0], 'f' => 3 + $i, 'hash' => hash('sha256', "rrhh-$i"), 'n' => sprintf('9%04d', $i),
                     'n2' => sprintf('9%04d', $i), 'nom' => $name, 'nom2' => $name, 'rfc' => self::fakeRfc($i), 'rfc2' => self::fakeRfc($i),
                     'curp' => self::fakeCurp($i), 'curp2' => self::fakeCurp($i), 'e' => $e]);
            }
            $s += ['emp1' => $e1, 'emp2' => $e2, 'rrhh1' => $r1, 'rrhh2' => $r2];
            $s['imp_t'] = (int) $db->fetchValue(
                "INSERT INTO importaciones_normalizadas (archivo_id, analisis_id, numero, tipo_registro, periodo_id, lote_id, perfil_id,
                    matching_version, registros, metricas)
                 VALUES (:a, :an, 1, 'TESORERIA', :p, :l, :pf, 1, 4, CAST(:m AS jsonb)) RETURNING id",
                ['a' => $ft['archivo'], 'an' => $ft['analisis'], 'p' => $ft['periodo'], 'l' => $ft['lote'], 'pf' => $pInter,
                 'm' => json_encode(['por_estado' => ['SUGERENCIA' => 1, 'CONFLICTO' => 1, 'NO_IDENTIFICADO' => 1, 'ASOCIADO_AUTOMATICO' => 1],
                                     'banco' => 'INTERBANCARIA', 'categoria' => 'NOMINA', 'cuentas_nuevas' => 1])]);
            [$hC, $cC, $kC] = self::protect(self::CLABE);
            [$hS, $cS, $kS] = self::protect(self::CUENTA_SANT);
            $rows = [
                'sug' => ['INTERBANCARIA', 'NOMINA', null, 'PERSONA FICTICIA UNO', 'CLABE', $hC, '2345', $cC, $kC, null, 'SUGERENCIA', 'NOMBRE_EXACTO', 'MEDIA',
                          ['fuertes' => [], 'candidatos' => [$e1], 'motivo' => 'NOMBRE_EXACTO_UNICO']],
                'conf' => ['SANTANDER', 'NOMINA', '90001', 'PERSONA FICTICIA UNO', 'CUENTA', null, null, null, null, null, 'CONFLICTO', null, 'NINGUNA',
                           ['fuertes' => [['NUMERO_EMPLEADO', $e1], ['RFC', $e2]], 'candidatos' => [$e1, $e2]]],
                'pen' => ['INTERBANCARIA', 'PENSION', null, 'BENEFICIARIA FICTICIA', 'CLABE', null, null, null, null, null, 'NO_IDENTIFICADO', null, 'NINGUNA',
                          ['fuertes' => [], 'motivo' => 'PENSION_SIN_TRABAJADOR']],
                'auto' => ['SANTANDER', 'NOMINA', '90002', 'PERSONA FICTICIA DOS', 'CUENTA', $hS, '5678', $cS, $kS, $e2, 'ASOCIADO_AUTOMATICO', 'NUMERO_EMPLEADO', 'ALTA',
                           ['fuertes' => [['NUMERO_EMPLEADO', $e2]]]],
            ];
            $f = 7;
            foreach ($rows as $k => [$banco, $cat, $num, $name, $tipo, $hmac, $l4, $ct, $kid, $emp, $st, $met, $conf, $ev]) {
                $s[$k] = (int) $db->fetchValue(
                    "INSERT INTO registros_tesoreria (importacion_id, archivo_id, analisis_id, periodo_id, lote_id, perfil_id, categoria, banco, hoja_id,
                        hoja_indice, hoja_nombre, fila_origen, hash_registro, numero_original, numero_empleado, numero_valido, nombre_original,
                        nombre_normalizado, tipo_cuenta, cuenta_hmac, cuenta_ultimos4, cuenta_cifrada, cuenta_key_id, cuenta_hmac_key_id, importe,
                        importe_estado, empleado_id, asociacion_estado, asociacion_metodo, asociacion_confianza, asociacion_evidencia)
                     VALUES (:imp, :a, :an, :p, :l, :pf, :cat, :b, :h, 0, 'Inter sin Compro Fiscal FA', :f, :hash, :n, :n2, :nv, :nom, :nom2, :tc,
                        :hm, :l4, CAST(:ct AS bytea), :kid, CASE WHEN CAST(:hm2 AS text) IS NULL THEN NULL ELSE fn_hmac_key_activa() END, 700.00, 'OK', :e, :st, :met, :conf, CAST(:ev AS jsonb)) RETURNING id",
                    ['imp' => $s['imp_t'], 'a' => $ft['archivo'], 'an' => $ft['analisis'], 'p' => $ft['periodo'], 'l' => $ft['lote'], 'pf' => $pInter,
                     'cat' => $cat, 'b' => $banco, 'h' => $ft['hojas'][0], 'f' => $f++, 'hash' => hash('sha256', "tes-$k"), 'n' => $num, 'n2' => $num,
                     'nv' => $num !== null, 'nom' => $name, 'nom2' => $name, 'tc' => $hmac ? $tipo : null, 'hm' => $hmac, 'hm2' => $hmac, 'l4' => $l4, 'ct' => $ct,
                     'kid' => $kid, 'e' => $emp, 'st' => $st, 'met' => $met, 'conf' => $conf, 'ev' => json_encode($ev)]);
            }
            $db->execute(
                "INSERT INTO incidencias_identidad (clave, tipo, severidad, importacion_id, registro_tipo, registro_id, detalle)
                 VALUES (:c, 'IDENTIFICADORES_CONTRADICTORIOS', 'ALTA', :i, 'TESORERIA', :r, CAST(:d AS jsonb))",
                ['c' => 'IDENTIFICADORES_CONTRADICTORIOS:TESORERIA:' . $s['conf'], 'i' => $s['imp_t'], 'r' => $s['conf'],
                 'd' => json_encode(['metodos' => ['NUMERO_EMPLEADO', 'RFC'], 'empleados' => [$e1, $e2]])]);
            $s['cuenta2'] = (int) $db->fetchValue(
                "INSERT INTO cuentas_bancarias (empleado_id, uso, tipo, cuenta_hmac, cuenta_ultimos4, cuenta_cifrada, key_id, banco, origen,
                    origen_registro_id, primer_periodo_id, ultimo_periodo_id)
                 VALUES (:e, 'NOMINA', 'CUENTA', :h, '5678', CAST(:c AS bytea), :k, 'SANTANDER', 'EVIDENCIA_FUERTE', :r, :p, :p2) RETURNING id",
                ['e' => $e2, 'h' => $hS, 'c' => $cS, 'k' => $kS, 'r' => $s['auto'], 'p' => $ft['periodo'], 'p2' => $ft['periodo']]);
        });
        return $this->idn = $s;
    }
}

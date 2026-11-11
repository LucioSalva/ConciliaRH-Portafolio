<?php

declare(strict_types=1);

namespace ConciliaRH\Engine\Analysis;

final class Profiles
{
    public const PENSION_WORDS = ['PENSION'];
    public const NOMINA_WORDS = ['NOMINA'];
    public const BOMBEROS_WORDS = ['BOMBER'];
    public const POLICIA_WORDS = ['POLICI', 'SEGURIDAD PUBLICA'];
    public const CONCEPT_HEADER = '/^(\d{3})[' . \ConciliaRH\Engine\Support\Py::WS . ']+\S/u';

    /** @var list<Profile>|null */
    private static ?array $all = null;
    /** @var array<string,true>|null */
    private static ?array $vocab = null;

    /** @return list<Profile> */
    public static function all(): array
    {
        if (self::$all !== null) {
            return self::$all;
        }
        $santander = [
            new Field('TIPO_REGISTRO', ['TIPO DE REGISTRO'], 'code', false),
            new Field('SECUENCIA', ['NUMERO DE SECUENCIA'], 'int', false),
            new Field('NUM_EMPLEADO', ['NUMERO EMPLEADO', 'NUMERO DE EMPLEADO', 'NUM EMPLEADO'], 'id'),
            new Field('APELLIDO_PATERNO', ['APELLIDO PATERNO DEL EMPLEADO', 'APELLIDO PATERNO']),
            new Field('APELLIDO_MATERNO', ['APELLIDO MATERNO DEL EMPLEADO', 'APELLIDO MATERNO']),
            new Field('NOMBRE', ['NOMBRE DEL EMPLEADO']),
            new Field('CUENTA', ['CUENTA'], 'account'),
            new Field('IMPORTE', ['IMPORTE'], 'money'),
            new Field('CONCEPTO', ['ID CONCEPTO', 'CONCEPTO'], 'code'),
        ];
        $interbancaria = [
            new Field('CUENTA_CARGO', ['CUENTA DE CARGO'], 'account'),
            new Field('CLABE', ['CUENTA DE ABONO', 'CLABE'], 'clabe'),
            new Field('BANCO_RECEPTOR', ['BANCO RECEPTOR'], 'code'),
            new Field('BENEFICIARIO', ['BENEFICIARIO']),
            new Field('SUCURSAL', ['SUCURSAL'], 'code'),
            new Field('IMPORTE', ['IMPORTE'], 'money'),
            new Field('PLAZA', ['PLAZA BANXICO'], 'code'),
            new Field('CONCEPTO', ['CONCEPTO']),
            new Field('REFERENCIA', ['REFERENCIA ORDENANTE'], 'code'),
            new Field('FORMA_APLICACION', ['FORMA DE APLICACION'], 'code'),
            new Field('FECHA_APLICACION', ['FECHA DE APLICACION'], 'date'),
        ];
        $bbva = [
            new Field('NUM_EMPLEADO', ['NUM', 'NUMERO', 'NO EMPLEADO', 'NUM EMPLEADO'], 'id'),
            new Field('NOMBRE', ['NOMBRE']),
            new Field('RFC', ['RFC'], 'rfc'),
            new Field('IMPORTE', ['IMPORTE'], 'money'),
            new Field('CUENTA', ['CTA BBVA', 'CUENTA BBVA', 'CUENTA'], 'account'),
        ];
        $bbvaPension = [
            new Field('NUM_EMPLEADO', ['NUM', 'NUMERO', 'NO EMPLEADO', 'NUM EMPLEADO'], 'id'),
            new Field('NOMBRE', ['NOMBRE']),
            new Field('IMPORTE', ['TOTAL', 'IMPORTE'], 'money'),
            new Field('CUENTA', ['CUENTA', 'CTA BBVA'], 'account'),
        ];
        $tabulado = [
            new Field('PLAZA', ['PZA', 'PLAZA'], 'code'),
            new Field('NUM_EMPLEADO', ['NUM', 'NUMERO', 'NUM EMPLEADO'], 'id'),
            new Field('NOMBRE', ['NOMBRE']),
            new Field('DEPTO', ['DEPTO', 'DEPARTAMENTO'], 'code'),
            new Field('AREA', ['AREA']),
            new Field('PUESTO', ['PUESTO'], 'code'),
            new Field('CARGO', ['CARGO']),
            new Field('RFC', ['RFC'], 'rfc'),
            new Field('CURP', ['CURP'], 'curp'),
            new Field('ISSEMYM', ['ISSEMYM'], 'code'),
            new Field('FOLIO', ['FOLIO'], 'code'),
            new Field('PERCEPCIONES', ['PERCEP', 'PERCEPCIONES'], 'money'),
            new Field('DEDUCCIONES', ['DEDUC', 'DEDUCCIONES'], 'money'),
            new Field('NETO', ['TOTAL', 'NETO'], 'money'),
            new Field('FECHA_INGRESO', ['FECHA ING', 'FECHA INGRESO'], 'date'),
            new Field('FECHA_BAJA', ['FECHA BAJA'], 'date'),
            new Field('NIVEL', ['NIVEL'], 'code'),
            new Field('HORAS', ['HORAS LABORADAS'], 'text'),
            new Field('FUENTE', ['FTE FINANCIAM', 'FUENTE FINANCIAMIENTO'], 'code'),
            new Field('BANCO', ['BANCO']),
            new Field('TIPO_PAGO', ['TIPO PAGO']),
            new Field('CLAVE_PROGRAMATICA', ['CLAVE PROGRAMATICA'], 'code'),
        ];
        $egreso = [
            new Field('CUENTA', ['CUENTA'], 'code'),
            new Field('SCTA', ['SCTA'], 'code'),
            new Field('SSCTA', ['SSCTA'], 'code'),
            new Field('SSSCTA', ['SSSCTA'], 'code'),
            new Field('SSSSCTA', ['SSSSCTA'], 'code'),
            new Field('REF', ['REF'], 'code'),
            new Field('CONCEPTO', ['CONCEPTO']),
            new Field('DEBE', ['DEBE', 'CARGO'], 'money'),
            new Field('HABER', ['HABER', 'ABONO'], 'money'),
        ];
        $santanderReq = ['NUM_EMPLEADO', 'APELLIDO_PATERNO', 'NOMBRE', 'CUENTA', 'IMPORTE'];
        $santanderIds = ['NUM_EMPLEADO', 'CUENTA', 'APELLIDO_PATERNO', 'NOMBRE'];
        $interReq = ['CUENTA_CARGO', 'CLABE', 'BANCO_RECEPTOR', 'BENEFICIARIO', 'IMPORTE'];
        $tabReq = ['NUM_EMPLEADO', 'NOMBRE', 'RFC', 'CURP', 'PERCEPCIONES', 'DEDUCCIONES', 'NETO'];
        $tabIds = ['NUM_EMPLEADO', 'NOMBRE', 'RFC', 'CURP'];
        return self::$all = [
            new Profile('TESORERIA_SANTANDER', 'TESORERIA_SANTANDER', 'TESORERIA_DISPERSION', 'tabular', $santander, $santanderReq, $santanderIds,
                'IMPORTE', variant: 'PAGO_CONCEPTO', variantValue: 'NOMINA', keyFields: ['NUM_EMPLEADO', 'CUENTA']),
            new Profile('TESORERIA_PENSION_SANTANDER', 'TESORERIA_SANTANDER', 'TESORERIA_PENSION', 'tabular', $santander, $santanderReq, $santanderIds,
                'IMPORTE', variant: 'PAGO_CONCEPTO', variantValue: 'PENSION', keyFields: ['NUM_EMPLEADO', 'CUENTA']),
            new Profile('TESORERIA_INTERBANCARIA', 'TESORERIA_INTERBANCARIA', 'TESORERIA_DISPERSION', 'tabular', $interbancaria, $interReq,
                ['CLABE', 'BENEFICIARIO'], 'IMPORTE', variant: 'PAGO_CONCEPTO', variantValue: 'NOMINA', keyFields: ['CLABE']),
            new Profile('TESORERIA_PENSION_INTERBANCARIA', 'TESORERIA_INTERBANCARIA', 'TESORERIA_PENSION', 'tabular', $interbancaria, $interReq,
                ['CLABE', 'BENEFICIARIO'], 'IMPORTE', variant: 'PAGO_CONCEPTO', variantValue: 'PENSION', keyFields: ['CLABE']),
            new Profile('TESORERIA_BBVA', 'TESORERIA_BBVA', 'TESORERIA_DISPERSION', 'tabular', $bbva, ['NUM_EMPLEADO', 'NOMBRE', 'RFC', 'IMPORTE', 'CUENTA'],
                ['NUM_EMPLEADO', 'NOMBRE', 'RFC', 'CUENTA'], 'IMPORTE', keyFields: ['NUM_EMPLEADO', 'CUENTA']),
            new Profile('TESORERIA_PENSION_BBVA', 'TESORERIA_PENSION_BBVA', 'TESORERIA_PENSION', 'tabular', $bbvaPension,
                ['NUM_EMPLEADO', 'NOMBRE', 'IMPORTE', 'CUENTA'], ['NUM_EMPLEADO', 'NOMBRE', 'CUENTA'], 'IMPORTE', keyFields: ['NUM_EMPLEADO', 'CUENTA']),
            new Profile('RRHH_TABULADO_POLICIA', 'RRHH_TABULADO', 'RRHH_TABULADO', 'tabular', $tabulado, $tabReq, $tabIds, 'NETO', conceptColumns: true,
                complementary: ['RRHH_POLIZA_GLOBAL'], variant: 'GRUPO', variantValue: 'POLICIA', keyFields: ['NUM_EMPLEADO']),
            new Profile('RRHH_TABULADO_BOMBEROS', 'RRHH_TABULADO', 'RRHH_TABULADO', 'tabular', $tabulado, $tabReq, $tabIds, 'NETO', conceptColumns: true,
                complementary: ['RRHH_POLIZA_GLOBAL'], variant: 'GRUPO', variantValue: 'BOMBEROS', keyFields: ['NUM_EMPLEADO']),
            new Profile('RRHH_POLIZA_GLOBAL_POLICIA', 'RRHH_POLIZA_GLOBAL', 'RRHH_POLIZA_GLOBAL', 'poliza_global',
                variant: 'GRUPO_TITULO', variantValue: 'POLICIA', keyFields: ['BLOQUE', 'CODIGO']),
            new Profile('RRHH_POLIZA_GLOBAL_BOMBEROS', 'RRHH_POLIZA_GLOBAL', 'RRHH_POLIZA_GLOBAL', 'poliza_global',
                variant: 'GRUPO_TITULO', variantValue: 'BOMBEROS', keyFields: ['BLOQUE', 'CODIGO']),
            new Profile('POLIZA_EGRESO_BANCARIA', 'POLIZA_EGRESO_BANCARIA', 'POLIZA_EGRESO_BANCARIA', 'tabular', $egreso,
                ['CUENTA', 'SCTA', 'CONCEPTO', 'DEBE', 'HABER'], ['CUENTA', 'SCTA', 'SSCTA', 'SSSCTA', 'SSSSCTA', 'CONCEPTO'], null,
                multiSheet: true, detailRequiresAmount: true, rowTotals: false),
        ];
    }

    public static function byCode(string $code): ?Profile
    {
        foreach (self::all() as $p) {
            if ($p->codigo === $code) {
                return $p;
            }
        }
        return null;
    }

    /** @return array<string,list<Profile>> */
    public static function families(): array
    {
        $out = [];
        foreach (self::all() as $p) {
            $out[$p->familia][] = $p;
        }
        return $out;
    }

    /** @return array<string,Profile> */
    public static function familyReps(): array
    {
        $out = [];
        foreach (self::families() as $fam => $profs) {
            $out[$fam] = $profs[0];
        }
        return $out;
    }

    public static function inVocab(string $header): bool
    {
        if (self::$vocab === null) {
            self::$vocab = ['PERCEPCIONES' => true, 'DEDUCCIONES' => true, 'NOMBRE DE INSTITUCION' => true, 'CLAVE' => true];
            foreach (self::all() as $p) {
                foreach ($p->fields as $f) {
                    foreach ($f->headers as $h) {
                        self::$vocab[$h] = true;
                    }
                }
            }
        }
        return isset(self::$vocab[$header]);
    }

    public static function profileFor(string $family, ?string $variant): ?Profile
    {
        foreach (self::families()[$family] ?? [] as $p) {
            if ($p->variantValue === $variant) {
                return $p;
            }
        }
        return null;
    }

    /**
     * @param list<string> $values
     * @param array<string,list<string>> $options
     * @return array{0:?string,1:array<string,int>}
     */
    public static function decideByWords(array $values, array $options, float $minShare = 0.9): array
    {
        $counts = array_fill_keys(array_keys($options), 0);
        foreach ($values as $v) {
            $hits = [];
            foreach ($options as $k => $words) {
                foreach ($words as $w) {
                    if (str_contains($v, $w)) {
                        $hits[] = $k;
                        break;
                    }
                }
            }
            if (count($hits) === 1) {
                $counts[$hits[0]]++;
            }
        }
        $signaled = array_sum($counts);
        if ($signaled === 0) {
            return [null, $counts];
        }
        $best = null;
        foreach ($counts as $k => $n) {
            if ($best === null || $n > $counts[$best]) {
                $best = $k;
            }
        }
        return $counts[$best] / $signaled >= $minShare ? [(string) $best, $counts] : [null, $counts];
    }
}

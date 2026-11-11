<?php

declare(strict_types=1);

use ConciliaRH\Tests\Fixtures\IdentityFixtures as FX;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

if (!isset($argv[1])) {
    fwrite(STDERR, "Uso: php tests/e2e/generar-lote-ficticio.php <directorio-salida>\n");
    exit(2);
}
$out = rtrim($argv[1], '/');
if (!is_dir("$out/xss")) {
    mkdir("$out/xss", 0775, true);
}

$ps = [FX::person(1), FX::person(2, ['pension' => '300.00']), FX::person(3), FX::person(4)];
$neto = '0.00';
$ded = '0.00';
foreach ($ps as $p) {
    $neto = bcadd($neto, bcsub(bcsub($p['sueldo'], $p['isr'], 2), $p['pension'], 2), 2);
    $ded = bcadd($ded, bcadd($p['isr'], $p['pension'], 2), 2);
}
$pago = static function (array $p, ?string $imp = null): array {
    [$a, $m, $n] = explode(' ', $p['nombre']);
    return [$p['num'], $a, $m, $n, sprintf('600%08d', (int) $p['num']), $imp ?? bcsub(bcsub($p['sueldo'], $p['isr'], 2), $p['pension'], 2)];
};
$sant = [$pago($ps[0]), $pago($ps[1]), $pago($ps[2], '8000.00')];
$inter = [['002180000000012345', 'OTRA PERSONA FICTICIA', '100.00']];
$totSant = array_reduce($sant, static fn (string $c, array $x) => bcadd($c, $x[5], 2), '0.00');
$totInter = array_reduce($inter, static fn (string $c, array $x) => bcadd($c, $x[2], 2), '0.00');
$hojas = [['SANTANDER $' . number_format((float) $totSant, 2), $totSant], ['SANTANDER $' . number_format((float) $totInter, 2) . ' I', $totInter]];

$archivos = [
    '01_TABULADO_RRHH_FICTICIO.xlsx' => [FX::tabulado($ps), 'RRHH_TABULADO', 'RRHH'],
    '02_POLIZA_GLOBAL_FICTICIA.xlsx' => [FX::polizaGlobal([['1', 'SUELDO BASE', (float) bcadd($neto, $ded, 2)]], [['70', 'ISR', (float) $ded]]),
                                         'RRHH_POLIZA_GLOBAL', 'RRHH'],
    '03_DISPERSION_SANTANDER_FICTICIA.xlsx' => [FX::santander($sant), 'TESORERIA_DISPERSION', 'TESORERIA'],
    '04_INTERBANCARIA_FICTICIA.xls' => [FX::interbancaria($inter), 'TESORERIA_DISPERSION', 'TESORERIA'],
    '05_PENSIONES_FICTICIAS.xlsx' => [FX::santander([[$ps[1]['num'], 'BENEFICIARIA', 'FICTICIA', 'UNO', '60099988877', '300.00']], true),
                                      'TESORERIA_PENSION', 'TESORERIA'],
    '06_POLIZA_EGRESO_FICTICIA.xlsx' => [FX::polizaEgresoBancos($hojas), 'POLIZA_EGRESO_BANCARIA', 'CONTABILIDAD'],
];
$manifiesto = [];
foreach ($archivos as $nombre => [$data, $tipo, $origen]) {
    file_put_contents("$out/$nombre", $data);
    $manifiesto[] = ['archivo' => $nombre, 'tipo_documento' => $tipo, 'origen' => $origen];
}
$esperado = ['90001' => 'CORRECTO', '90002' => 'CORRECTO', '90003' => 'PAGO_MENOR', '90004' => 'NO_PAGADO',
             'rrhh_neto_total' => $neto, 'tesoreria_nomina_total' => bcadd($totSant, $totInter, 2)];

$xss = '<svg/onload=alert("crh")>';
$hostiles = [FX::person(91, ['nombre' => "FICTICIO91 $xss NOMBRE91"]), FX::person(92, ['nombre' => "FICTICIO92 APELLIDO92 $xss"])];
file_put_contents("$out/xss/TABULADO_XSS_FICTICIO.xlsx", FX::tabulado($hostiles));
file_put_contents("$out/xss/SANTANDER_XSS_FICTICIO.xlsx", FX::santander([[$hostiles[0]['num'], 'FICTICIO91', $xss, 'NOMBRE91', '60000000091', '8500.00']]));
if (!is_dir("$out/quincena")) {
    mkdir("$out/quincena", 0775, true);
}
$bomb = ['area' => 'DIRECCION DE PROTECCION CIVIL Y BOMBEROS', 'cargo' => 'BOMBERO'];
$pb = [FX::person(5, $bomb), FX::person(6, $bomb)];
$quincena = [
    'POLICIA' => ['NOMINA' => ['01_TABULADO_RRHH_FICTICIO.xlsx'], 'PAGOS' => ['03_DISPERSION_SANTANDER_FICTICIA.xlsx', '04_INTERBANCARIA_FICTICIA.xls'],
                  'PENSIONES' => ['05_PENSIONES_FICTICIAS.xlsx']],
    'BOMBEROS' => ['NOMINA' => ['quincena/07_TABULADO_BOMBEROS_FICTICIO.xlsx'],
                   'PAGOS' => ['quincena/08_DISPERSION_BOMBEROS_FICTICIA.xlsx', 'quincena/09_INTERBANCARIA_BOMBEROS_FICTICIA.xls']],
];
file_put_contents("$out/quincena/07_TABULADO_BOMBEROS_FICTICIO.xlsx", FX::tabulado($pb));
file_put_contents("$out/quincena/08_DISPERSION_BOMBEROS_FICTICIA.xlsx", FX::santander([$pago($pb[0])]));
file_put_contents("$out/quincena/09_INTERBANCARIA_BOMBEROS_FICTICIA.xls",
    FX::interbancaria([['002180000000067890', $pb[1]['nombre'], bcsub(bcsub($pb[1]['sueldo'], $pb[1]['isr'], 2), $pb[1]['pension'], 2)]]));
if (!is_dir("$out/fusion")) {
    mkdir("$out/fusion", 0775, true);
}
$pf = [FX::person(97), FX::person(98)];
file_put_contents("$out/fusion/10_TABULADO_FUSION_FICTICIO.xlsx", FX::tabulado($pf));
foreach (['11_DISPERSION_FUSION_FICTICIA.xlsx' => $pf[0], '12_DISPERSION_FUSION_FICTICIA.xlsx' => $pf[1]] as $nombre => $p) {
    file_put_contents("$out/fusion/$nombre", FX::santander([[...array_slice($pago($p), 0, 4), '60000000097', $pago($p)[5]]]));
}
$esperadoQuincena = ['90001' => 'CORRECTO', '90002' => 'CORRECTO', '90003' => 'PAGO_MENOR', '90004' => 'NO_PAGADO', '90005' => 'CORRECTO', '90006' => 'CORRECTO',
                     'exactos' => '1'];

file_put_contents("$out/manifiesto.json", json_encode(['archivos' => $manifiesto, 'esperado' => $esperado,
    'quincena' => ['espacios' => $quincena, 'esperado' => $esperadoQuincena],
    'fusion' => ['archivos' => [['fusion/10_TABULADO_FUSION_FICTICIO.xlsx', 'RRHH_TABULADO', 'RRHH', 0],
                                ['fusion/11_DISPERSION_FUSION_FICTICIA.xlsx', 'TESORERIA_DISPERSION', 'TESORERIA', 0],
                                ['fusion/12_DISPERSION_FUSION_FICTICIA.xlsx', 'TESORERIA_DISPERSION', 'TESORERIA', 1]], 'origen' => '90098', 'destino' => '90097']], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");
echo (count($archivos) + 6) . " archivos ficticios en $out\n";

<?php

declare(strict_types=1);

use ConciliaRH\Tests\Fixtures\ExcelFixtures;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$dest = $argv[1] ?? ExcelFixtures::dir();
foreach (ExcelFixtures::generate($dest) as $name => $path) {
    printf("%-34s %10d B\n", $name, filesize($path));
}

<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

$caseFiles = glob(__DIR__ . '/Cases/*Test.php');

if ($caseFiles === false) {
    throw new RuntimeException('Could not discover test cases.');
}

sort($caseFiles);

foreach ($caseFiles as $caseFile) {
    require $caseFile;
}

echo "\nAll tests passed.\n";

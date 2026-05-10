<?php

$tests = [
    __DIR__ . '/unit_core.php',
    __DIR__ . '/integration_smoke.php',
];

$failures = [];

foreach ($tests as $testFile) {
    try {
        require $testFile;
        echo '[OK] ' . basename($testFile) . PHP_EOL;
    } catch (Throwable $e) {
        $failures[] = basename($testFile) . ': ' . $e->getMessage();
        echo '[FAIL] ' . basename($testFile) . PHP_EOL;
    }
}

if (!empty($failures)) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo 'All tests passed.' . PHP_EOL;

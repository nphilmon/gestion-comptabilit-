<?php

require_once __DIR__ . '/bootstrap.php';

$root = dirname(__DIR__);
$criticalFiles = [
    $root . '/login.php',
    $root . '/factures.php',
    $root . '/caisse.php',
    $root . '/paie.php',
];

foreach ($criticalFiles as $file) {
    assertTrue(is_file($file), 'Critical entry file is missing: ' . basename($file));
    exec('php -l ' . escapeshellarg($file), $output, $code);
    assertSame(0, $code, 'PHP lint failed for ' . basename($file) . ': ' . implode("\n", $output));
}

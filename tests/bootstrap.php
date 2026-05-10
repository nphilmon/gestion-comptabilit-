<?php

putenv('GESTION_COMPTA_DISABLE_ERROR_HANDLERS=1');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/../functions_commercial.php';
require_once __DIR__ . '/../functions_caisse.php';
require_once __DIR__ . '/../functions_paie.php';

function assertTrue(bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function assertSame(mixed $expected, mixed $actual, string $message): void {
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' Expected: ' . var_export($expected, true) . ', got: ' . var_export($actual, true));
    }
}

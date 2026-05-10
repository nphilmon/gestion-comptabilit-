<?php

require_once __DIR__ . '/bootstrap.php';

assertTrue(function_exists('attemptLogin'), 'Auth flow functions must load.');
assertTrue(function_exists('getFacturesList'), 'Billing flow functions must load.');
assertTrue(function_exists('getNotesCaisse'), 'Cash register flow functions must load.');
assertTrue(function_exists('calculatePaieBulletinTotals'), 'Payroll flow functions must load.');

$totals = calculatePaieBulletinTotals([
    'salaire_base_brut' => 2000,
    'heures_supplementaires' => 2,
    'taux_horaire_majore' => 25,
    'prime' => 100,
    'retenues' => 50,
    'cotisations_salariales' => 440,
    'cotisations_patronales' => 800,
]);

assertSame(2150.0, $totals['salaire_brut'], 'Payroll totals should be computed consistently.');
assertSame(1660.0, $totals['net_a_payer'], 'Payroll net amount should be computed consistently.');

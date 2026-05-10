<?php

require_once __DIR__ . '/bootstrap.php';

assertSame('clos', normalizeExerciceStatut('cloture'), 'Legacy closed status must be normalized.');
assertSame('clos', normalizeExerciceStatut('clos'), 'Closed status must remain closed.');
assertSame('ouvert', normalizeExerciceStatut('OUVERT'), 'Open status must normalize to ouvert.');
assertTrue(isExerciceClosed('cloture'), 'Legacy cloture status must be treated as closed.');
assertSame('abc', safeXmlValue("  abc  "), 'XML values must be trimmed.');

$sanitized = sanitizeLogContextValue([
    'message' => str_repeat('x', 600),
    'nested' => ['flag' => true],
]);

assertTrue(mb_strlen($sanitized['message']) <= 501, 'Long log values must be truncated.');
assertSame(true, $sanitized['nested']['flag'], 'Nested log context must be preserved.');

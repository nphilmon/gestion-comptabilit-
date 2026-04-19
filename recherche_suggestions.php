<?php
/**
 * Suggestions de recherche en direct
 */
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/functions_search.php';

requireLogin();

header('Content-Type: application/json; charset=utf-8');

$q = trim($_GET['q'] ?? '');
$payload = getSmartSearchResults($q, [
    'per_section_limit' => 4,
    'fetch_limit' => 12,
]);

$flat = array_slice($payload['flat_results'], 0, 8);
$suggestions = array_map(static function(array $item): array {
    return [
        'section' => $item['section'],
        'label' => $item['label'],
        'detail' => $item['detail'],
        'link' => $item['link'],
        'icon' => $item['icon'],
        'color' => $item['color'],
        'score' => $item['score'],
    ];
}, $flat);

$smart = array_map(static function(array $item): array {
    return [
        'label' => $item['label'],
        'text' => $item['text'],
        'link' => $item['link'],
        'button' => $item['button'],
    ];
}, array_slice($payload['smart_suggestions'], 0, 3));

echo json_encode([
    'query' => $q,
    'total' => $payload['total_results'],
    'suggestions' => $suggestions,
    'smart' => $smart,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

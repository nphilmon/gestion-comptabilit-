<?php
/**
 * Relais local pour la recherche d'entreprises (SIREN / SIRET)
 */
require_once __DIR__ . '/functions.php';

sendSecurityHeaders();
header('Content-Type: application/json; charset=utf-8');

$user = getCurrentUser();
if (!$user) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Authentification requise.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (!in_array($user['role'] ?? '', ['admin', 'comptable'], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Accès non autorisé.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (!checkRateLimit('api_entreprise', 30, 60, $user['email'] ?? getClientIp())) {
    http_response_code(429);
    echo json_encode(['success' => false, 'message' => 'Trop de requêtes. Réessayez dans une minute.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
recordRateLimitHit('api_entreprise', $user['email'] ?? getClientIp());

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

$q = trim((string) ($_GET['q'] ?? ''));
$perPage = max(1, min(8, (int) ($_GET['per_page'] ?? 5)));
$allowInsecureLocalFallback = in_array(getClientIp(), ['127.0.0.1', '::1'], true)
    || envValue('GESTION_COMPTA_ALLOW_INSECURE_SSL_FALLBACK', '0') === '1';

if ($q === '' || mb_strlen($q) < 2) {
    echo json_encode(['success' => true, 'results' => []], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (mb_strlen($q) > 120) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Recherche trop longue.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$url = 'https://recherche-entreprises.api.gouv.fr/search?q=' . urlencode($q) . '&per_page=' . $perPage . '&page=1';

try {
    $json = false;
    $lastError = '';

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_USERAGENT => 'GestionCompta/2.0',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $json = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $lastError = curl_error($ch);
        curl_close($ch);

        if ($json === false || $httpCode >= 400) {
            $json = false;
            $lastError = $lastError ?: ('HTTP ' . $httpCode);
        }
    }

    if ($json === false) {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 10,
                'header' => "Accept: application/json\r\nUser-Agent: GestionCompta/2.0\r\n"
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ]
        ]);

        $json = @file_get_contents($url, false, $context);
    }

    if ($json === false && $allowInsecureLocalFallback) {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 10,
                'header' => "Accept: application/json\r\nUser-Agent: GestionCompta/2.0\r\n"
            ],
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
            ]
        ]);

        $json = @file_get_contents($url, false, $context);
    }

    if ($json === false) {
        throw new RuntimeException($lastError ?: 'Impossible de joindre l\'API entreprise');
    }

    $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    $results = [];

    foreach (($data['results'] ?? []) as $entreprise) {
        $siege = $entreprise['siege'] ?? [];
        $adresse = trim(implode(' ', array_filter([
            $siege['numero_voie'] ?? '',
            $siege['type_voie'] ?? '',
            $siege['libelle_voie'] ?? ''
        ])));

        $results[] = [
            'nom' => $entreprise['nom_complet'] ?? '',
            'siren' => $entreprise['siren'] ?? '',
            'siret' => $siege['siret'] ?? '',
            'adresse' => $adresse,
            'code_postal' => $siege['code_postal'] ?? '',
            'ville' => $siege['libelle_commune'] ?? ($siege['commune'] ?? ''),
            'naf' => $entreprise['activite_principale'] ?? '',
        ];
    }

    echo json_encode([
        'success' => true,
        'results' => $results,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(502);
    echo json_encode([
        'success' => false,
        'message' => 'Service de recherche entreprise temporairement indisponible.',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

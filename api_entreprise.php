<?php
/**
 * Relais local pour la recherche d'entreprises (SIREN / SIRET)
 */
require_once __DIR__ . '/functions.php';

sendSecurityHeaders();
header('Content-Type: application/json; charset=utf-8');

const API_ENTREPRISE_MAX_RESULTS = 8;
const API_ENTREPRISE_TIMEOUT = 8;
const API_ENTREPRISE_CONNECT_TIMEOUT = 4;
const API_ENTREPRISE_USER_AGENT = 'GestionCompta/2.1';

function respondJson(array $payload, int $statusCode = 200): void {
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function cleanEntrepriseValue($value, int $maxLength = 255): string {
    $value = trim((string) $value);
    $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
    return mb_substr($value, 0, $maxLength);
}

function fetchEntrepriseJson(string $url, bool $allowInsecureLocalFallback): string {
    $lastError = '';

    if (function_exists('curl_init')) {
        foreach ([true, false] as $verifySsl) {
            if (!$verifySsl && !$allowInsecureLocalFallback) {
                continue;
            }

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => API_ENTREPRISE_TIMEOUT,
                CURLOPT_CONNECTTIMEOUT => API_ENTREPRISE_CONNECT_TIMEOUT,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTPHEADER => ['Accept: application/json'],
                CURLOPT_USERAGENT => API_ENTREPRISE_USER_AGENT,
                CURLOPT_SSL_VERIFYPEER => $verifySsl,
                CURLOPT_SSL_VERIFYHOST => $verifySsl ? 2 : 0,
            ]);

            $json = curl_exec($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($json !== false && $httpCode >= 200 && $httpCode < 300) {
                return $json;
            }

            $lastError = $error ?: ('HTTP ' . $httpCode);
        }
    }

    foreach ([true, false] as $verifySsl) {
        if (!$verifySsl && !$allowInsecureLocalFallback) {
            continue;
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => API_ENTREPRISE_TIMEOUT,
                'ignore_errors' => true,
                'header' => "Accept: application/json\r\nUser-Agent: " . API_ENTREPRISE_USER_AGENT . "\r\n"
            ],
            'ssl' => [
                'verify_peer' => $verifySsl,
                'verify_peer_name' => $verifySsl,
            ]
        ]);

        $json = @file_get_contents($url, false, $context);
        $statusLine = $http_response_header[0] ?? '';
        $httpCode = preg_match('/\s(\d{3})\s/', $statusLine, $matches) ? (int) $matches[1] : 0;

        if ($json !== false && $httpCode >= 200 && $httpCode < 300) {
            return $json;
        }

        $lastError = $statusLine ?: 'Erreur réseau';
    }

    throw new RuntimeException($lastError ?: 'Impossible de joindre l\'API entreprise');
}

function normalizeEntrepriseResult(array $entreprise): array {
    $siege = is_array($entreprise['siege'] ?? null) ? $entreprise['siege'] : [];
    $adresse = trim(implode(' ', array_filter([
        $siege['numero_voie'] ?? '',
        $siege['type_voie'] ?? '',
        $siege['libelle_voie'] ?? ''
    ])));

    return [
        'nom' => cleanEntrepriseValue($entreprise['nom_complet'] ?? ''),
        'siren' => cleanEntrepriseValue($entreprise['siren'] ?? '', 9),
        'siret' => cleanEntrepriseValue($siege['siret'] ?? '', 14),
        'adresse' => cleanEntrepriseValue($adresse),
        'code_postal' => cleanEntrepriseValue($siege['code_postal'] ?? '', 10),
        'ville' => cleanEntrepriseValue($siege['libelle_commune'] ?? ($siege['commune'] ?? ''), 120),
        'naf' => cleanEntrepriseValue($entreprise['activite_principale'] ?? '', 20),
    ];
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    respondJson(['success' => false, 'message' => 'Méthode non autorisée.'], 405);
}

$user = getCurrentUser();
if (!$user) {
    respondJson(['success' => false, 'message' => 'Authentification requise.'], 401);
}

if (!in_array($user['role'] ?? '', ['admin', 'comptable'], true)) {
    respondJson(['success' => false, 'message' => 'Accès non autorisé.'], 403);
}

if (!checkRateLimit('api_entreprise', 30, 60, $user['email'] ?? getClientIp())) {
    respondJson(['success' => false, 'message' => 'Trop de requêtes. Réessayez dans une minute.'], 429);
}
recordRateLimitHit('api_entreprise', $user['email'] ?? getClientIp());

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

$q = trim((string) ($_GET['q'] ?? ''));
$q = preg_replace('/\s+/u', ' ', $q) ?? $q;
$perPage = max(1, min(API_ENTREPRISE_MAX_RESULTS, (int) ($_GET['per_page'] ?? 5)));
$allowInsecureLocalFallback = in_array(getClientIp(), ['127.0.0.1', '::1'], true)
    || envValue('GESTION_COMPTA_ALLOW_INSECURE_SSL_FALLBACK', '0') === '1';

if ($q === '' || mb_strlen($q) < 2) {
    respondJson(['success' => true, 'results' => []]);
}

if (mb_strlen($q) > 120) {
    respondJson(['success' => false, 'message' => 'Recherche trop longue.'], 400);
}

$url = 'https://recherche-entreprises.api.gouv.fr/search?q=' . urlencode($q) . '&per_page=' . $perPage . '&page=1';

try {
    $json = fetchEntrepriseJson($url, $allowInsecureLocalFallback);
    $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    $results = [];

    foreach (($data['results'] ?? []) as $entreprise) {
        if (is_array($entreprise)) {
            $results[] = normalizeEntrepriseResult($entreprise);
        }
    }

    respondJson([
        'success' => true,
        'results' => $results,
    ]);
} catch (Throwable $e) {
    respondJson([
        'success' => false,
        'message' => 'Service de recherche entreprise temporairement indisponible.',
    ], 502);
}

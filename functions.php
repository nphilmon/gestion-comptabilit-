<?php
/**
 * Fonctions utilitaires
 */

require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_strict_mode', '1');
    $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443);

    // Sécurisation des cookies de session
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $isSecure,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
}

// --- En-têtes de sécurité HTTP ---
function sendSecurityHeaders(): void {
    static $sent = false;
    if ($sent || headers_sent()) return;
    $sent = true;
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('X-XSS-Protection: 1; mode=block');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), camera=(), microphone=()');
    header("Content-Security-Policy: default-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'; object-src 'none'; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; font-src 'self' https://cdn.jsdelivr.net data:; img-src 'self' data:; connect-src 'self' https://recherche-entreprises.api.gouv.fr;");
}

// --- Timeout de session (30 minutes d'inactivité) ---
function checkSessionTimeout(): void {
    $timeout = 1800; // 30 minutes
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $timeout) {
        logout();
        header('Location: ' . BASE_URL . 'login.php?expired=1');
        exit;
    }
    if (isLoggedIn()) {
        $_SESSION['last_activity'] = time();
    }
}

// --- Authentification ---
function isLoggedIn(): bool {
    return !empty($_SESSION['user_id']);
}

function getCurrentUser(): ?array {
    if (!isLoggedIn()) return null;
    static $user = null;
    if ($user === null) {
        $db = getDB();
        $stmt = $db->prepare('SELECT id, nom, email, role, actif FROM users WHERE id = ? AND actif = 1');
        $stmt->execute([$_SESSION['user_id']]);
        $user = $stmt->fetch() ?: null;
        if (!$user) {
            // Utilisateur supprimé ou désactivé
            session_destroy();
            return null;
        }
    }
    return $user;
}

function requireLogin(): void {
    checkSessionTimeout();
    if (!isLoggedIn() || !getCurrentUser()) {
        $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'];
        $_SESSION = [];
        session_destroy();
        header('Location: ' . BASE_URL . 'login.php');
        exit;
    }
}

function requireRole(string ...$roles): void {
    requireLogin();
    $user = getCurrentUser();
    if (!in_array($user['role'], $roles)) {
        setFlash('danger', 'Accès non autorisé.');
        header('Location: ' . BASE_URL);
        exit;
    }
}

// --- Protection anti-bruteforce / rate limiting ---
function getClientIp(): string {
    $candidates = [
        $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '',
        $_SERVER['HTTP_CLIENT_IP'] ?? '',
        $_SERVER['REMOTE_ADDR'] ?? '',
    ];

    foreach ($candidates as $candidate) {
        foreach (array_map('trim', explode(',', $candidate)) as $ip) {
            if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
    }

    return '0.0.0.0';
}

function getRateLimitKey(string $scope, ?string $identifier = null): string {
    return hash('sha256', $scope . '|' . getClientIp() . '|' . mb_strtolower(trim((string) $identifier)));
}

function checkRateLimit(string $scope, int $max, int $window, ?string $identifier = null): bool {
    try {
        $db = getDB();
        $since = date('Y-m-d H:i:s', time() - $window);
        $db->prepare('DELETE FROM rate_limits WHERE created_at < ?')->execute([$since]);

        $stmt = $db->prepare('SELECT COUNT(*) FROM rate_limits WHERE scope = ? AND key_hash = ? AND created_at >= ?');
        $stmt->execute([$scope, getRateLimitKey($scope, $identifier), $since]);
        return (int) $stmt->fetchColumn() < $max;
    } catch (Throwable $e) {
        return true;
    }
}

function recordRateLimitHit(string $scope, ?string $identifier = null): void {
    try {
        $db = getDB();
        $stmt = $db->prepare('INSERT INTO rate_limits (scope, key_hash, created_at) VALUES (?, ?, NOW())');
        $stmt->execute([$scope, getRateLimitKey($scope, $identifier)]);
    } catch (Throwable $e) {
        // Ne jamais bloquer l'application.
    }
}

function getRateLimitWaitTime(string $scope, int $window, ?string $identifier = null): int {
    try {
        $db = getDB();
        $since = date('Y-m-d H:i:s', time() - $window);
        $stmt = $db->prepare('SELECT MIN(created_at) FROM rate_limits WHERE scope = ? AND key_hash = ? AND created_at >= ?');
        $stmt->execute([$scope, getRateLimitKey($scope, $identifier), $since]);
        $firstHit = $stmt->fetchColumn();
        if (!$firstHit) {
            return 0;
        }
        return max(0, (strtotime($firstHit) + $window) - time());
    } catch (Throwable $e) {
        return 0;
    }
}

function clearRateLimitHits(string $scope, ?string $identifier = null): void {
    try {
        $db = getDB();
        $stmt = $db->prepare('DELETE FROM rate_limits WHERE scope = ? AND key_hash = ?');
        $stmt->execute([$scope, getRateLimitKey($scope, $identifier)]);
    } catch (Throwable $e) {
        // Ne jamais bloquer l'application.
    }
}

function checkLoginAttempts(?string $identifier = null): bool {
    return checkRateLimit('login', 5, 900, $identifier);
}

function recordLoginAttempt(?string $identifier = null): void {
    recordRateLimitHit('login', $identifier);
}

function getLoginWaitTime(?string $identifier = null): int {
    return getRateLimitWaitTime('login', 900, $identifier);
}

function clearLoginAttempts(?string $identifier = null): void {
    clearRateLimitHits('login', $identifier);
}

function attemptLogin(string $email, string $password): bool {
    $db = getDB();
    $stmt = $db->prepare('SELECT id, nom, email, password_hash, role, actif FROM users WHERE LOWER(email) = LOWER(?)');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user || !$user['actif'] || !password_verify($password, $user['password_hash'])) {
        recordLoginAttempt($email);
        return false;
    }

    // Regénérer l'ID de session pour prévenir la fixation de session
    session_regenerate_id(true);
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['user_nom'] = $user['nom'];
    $_SESSION['user_role'] = $user['role'];
    $_SESSION['last_activity'] = time();
    clearLoginAttempts($email);

    // Mettre à jour la dernière connexion
    $db->prepare('UPDATE users SET derniere_connexion = NOW() WHERE id = ?')->execute([$user['id']]);

    // Rehacher si nécessaire
    if (password_needs_rehash($user['password_hash'], PASSWORD_BCRYPT)) {
        $newHash = password_hash($password, PASSWORD_BCRYPT);
        $db->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([$newHash, $user['id']]);
    }

    // Log d'activité
    logActivity($user['id'], 'connexion', 'Connexion réussie');

    return true;
}

function logout(): void {
    if (isLoggedIn()) {
        logActivity($_SESSION['user_id'] ?? 0, 'deconnexion', 'Déconnexion');
    }
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

// --- Log d'activité ---
function logActivity(int $userId, string $action, string $details = '', ?string $cible = null): void {
    try {
        $db = getDB();
        $stmt = $db->prepare('INSERT INTO activity_log (user_id, action, details, cible, ip_address) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$userId, $action, $details, $cible, $_SERVER['REMOTE_ADDR'] ?? '']);
    } catch (\PDOException $e) {
        // Silencieux — le log ne doit pas bloquer l'application
    }
}

function getActivityLog(int $limit = 50, ?int $userId = null): array {
    $db = getDB();
    $sql = 'SELECT l.*, u.nom as user_nom FROM activity_log l LEFT JOIN users u ON l.user_id = u.id';
    $params = [];
    if ($userId) {
        $sql .= ' WHERE l.user_id = ?';
        $params[] = $userId;
    }
    $sql .= ' ORDER BY l.created_at DESC LIMIT ' . (int)$limit;
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

// --- Formatage monétaire ---
function formatMontant(float $montant): string {
    return number_format($montant, 2, ',', ' ') . ' €';
}

// --- Formatage date ---
function formatDate(string $date): string {
    return date('d/m/Y', strtotime($date));
}

function formatDateMois(string $date): string {
    $mois = [
        1 => 'Janvier', 2 => 'Février', 3 => 'Mars', 4 => 'Avril',
        5 => 'Mai', 6 => 'Juin', 7 => 'Juillet', 8 => 'Août',
        9 => 'Septembre', 10 => 'Octobre', 11 => 'Novembre', 12 => 'Décembre'
    ];
    $m = (int) date('n', strtotime($date));
    $y = date('Y', strtotime($date));
    return $mois[$m] . ' ' . $y;
}

// --- Sécurité ---
function e(string $str): string {
    return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
}

function csrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrfField(): string {
    return '<input type="hidden" name="csrf_token" value="' . csrfToken() . '">';
}

function verifyCsrf(): bool {
    if (!isset($_POST['csrf_token']) || !isset($_SESSION['csrf_token'])) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $_POST['csrf_token']);
}

function isRegistrationOpen(): bool {
    try {
        $db = getDB();
        $nbUsers = (int) $db->query('SELECT COUNT(*) FROM users')->fetchColumn();
        return $nbUsers === 0 || getParam('inscription_ouverte', '0') === '1';
    } catch (Throwable $e) {
        return false;
    }
}

// --- Paramètres ---
function getParam(string $cle, string $defaut = ''): string {
    $db = getDB();
    $stmt = $db->prepare('SELECT valeur FROM parametres WHERE cle = ?');
    $stmt->execute([$cle]);
    $row = $stmt->fetch();
    return $row ? $row['valeur'] : $defaut;
}

function setParam(string $cle, string $valeur): void {
    $db = getDB();
    $stmt = $db->prepare('INSERT INTO parametres (cle, valeur) VALUES (?, ?) ON DUPLICATE KEY UPDATE valeur = ?');
    $stmt->execute([$cle, $valeur, $valeur]);
}

// --- Catégories ---
function getCategories(string $type = null): array {
    $db = getDB();
    if ($type) {
        $stmt = $db->prepare('SELECT * FROM categories WHERE actif = 1 AND type = ? ORDER BY nom');
        $stmt->execute([$type]);
    } else {
        $stmt = $db->query('SELECT * FROM categories WHERE actif = 1 ORDER BY type, nom');
    }
    return $stmt->fetchAll();
}

function getCategorie(int $id): ?array {
    $db = getDB();
    $stmt = $db->prepare('SELECT * FROM categories WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

// --- Transactions ---
function getTransactions(array $filtres = []): array {
    $db = getDB();
    $where = ['1=1'];
    $params = [];

    if (!empty($filtres['type'])) {
        $where[] = 't.type = ?';
        $params[] = $filtres['type'];
    }
    if (!empty($filtres['categorie_id'])) {
        $where[] = 't.categorie_id = ?';
        $params[] = (int) $filtres['categorie_id'];
    }
    if (!empty($filtres['mois'])) {
        $where[] = 'DATE_FORMAT(t.date_transaction, "%Y-%m") = ?';
        $params[] = $filtres['mois'];
    }
    if (!empty($filtres['annee'])) {
        $where[] = 'YEAR(t.date_transaction) = ?';
        $params[] = (int) $filtres['annee'];
    }
    if (!empty($filtres['date_debut'])) {
        $where[] = 't.date_transaction >= ?';
        $params[] = $filtres['date_debut'];
    }
    if (!empty($filtres['date_fin'])) {
        $where[] = 't.date_transaction <= ?';
        $params[] = $filtres['date_fin'];
    }
    if (!empty($filtres['recherche'])) {
        $where[] = '(t.description LIKE ? OR t.client_fournisseur LIKE ? OR t.reference LIKE ?)';
        $like = '%' . $filtres['recherche'] . '%';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }

    $sql = "SELECT t.*, c.nom AS categorie_nom, c.couleur AS categorie_couleur
            FROM transactions t
            LEFT JOIN categories c ON t.categorie_id = c.id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY t.date_transaction DESC, t.id DESC";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function getTransaction(int $id): ?array {
    $db = getDB();
    $stmt = $db->prepare('SELECT t.*, c.nom AS categorie_nom FROM transactions t LEFT JOIN categories c ON t.categorie_id = c.id WHERE t.id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function ajouterTransaction(array $data): int {
    $db = getDB();
    $stmt = $db->prepare('INSERT INTO transactions (date_transaction, type, categorie_id, description, client_fournisseur, montant, mode_paiement, reference, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([
        $data['date_transaction'],
        $data['type'],
        $data['categorie_id'] ?: null,
        $data['description'],
        $data['client_fournisseur'] ?: null,
        $data['montant'],
        $data['mode_paiement'] ?? 'virement',
        $data['reference'] ?: null,
        $data['notes'] ?: null,
    ]);
    return (int) $db->lastInsertId();
}

function modifierTransaction(int $id, array $data): void {
    $db = getDB();
    $stmt = $db->prepare('UPDATE transactions SET date_transaction=?, type=?, categorie_id=?, description=?, client_fournisseur=?, montant=?, mode_paiement=?, reference=?, notes=? WHERE id=?');
    $stmt->execute([
        $data['date_transaction'],
        $data['type'],
        $data['categorie_id'] ?: null,
        $data['description'],
        $data['client_fournisseur'] ?: null,
        $data['montant'],
        $data['mode_paiement'] ?? 'virement',
        $data['reference'] ?: null,
        $data['notes'] ?: null,
        $id,
    ]);
}

function supprimerTransaction(int $id): void {
    $db = getDB();
    $stmt = $db->prepare('DELETE FROM transactions WHERE id = ?');
    $stmt->execute([$id]);
}

// --- Configuration des régimes ---
function getRegimeConfig(): array {
    $forme = getParam('forme_juridique', 'auto-entrepreneur');
    $regime = getParam('regime', 'micro-bnc');
    $imposition = getParam('regime_imposition', 'ir');

    return [
        'forme_juridique' => $forme,
        'regime' => $regime,
        'regime_imposition' => $imposition,
        'is_micro' => in_array($regime, ['micro-bnc', 'micro-bic-services', 'micro-bic-vente']),
        'is_societe' => in_array($forme, ['eurl', 'sarl', 'sas', 'sasu']),
        'is_auto' => $forme === 'auto-entrepreneur',
        'tva_applicable' => (bool) getParam('tva_applicable', '0'),
    ];
}

function getFormesJuridiques(): array {
    return [
        'auto-entrepreneur' => [
            'label' => 'Auto-entrepreneur / Micro-entrepreneur',
            'regimes' => [
                'micro-bnc' => 'Micro-BNC (Services, professions libérales)',
                'micro-bic-services' => 'Micro-BIC Services (Artisan, prestataire)',
                'micro-bic-vente' => 'Micro-BIC Vente (Commerce, e-commerce)',
            ],
            'imposition' => ['ir'],
        ],
        'ei' => [
            'label' => 'Entreprise Individuelle (EI)',
            'regimes' => [
                'micro-bnc' => 'Micro-BNC',
                'micro-bic-services' => 'Micro-BIC Services',
                'micro-bic-vente' => 'Micro-BIC Vente',
                'reel-simplifie' => 'Réel simplifié',
                'reel-normal' => 'Réel normal',
            ],
            'imposition' => ['ir'],
        ],
        'eurl' => [
            'label' => 'EURL (Entreprise Unipersonnelle)',
            'regimes' => [
                'reel-simplifie' => 'Réel simplifié',
                'reel-normal' => 'Réel normal',
            ],
            'imposition' => ['ir', 'is'],
        ],
        'sarl' => [
            'label' => 'SARL',
            'regimes' => [
                'reel-simplifie' => 'Réel simplifié',
                'reel-normal' => 'Réel normal',
            ],
            'imposition' => ['is', 'ir'],
        ],
        'sas' => [
            'label' => 'SAS',
            'regimes' => [
                'reel-simplifie' => 'Réel simplifié',
                'reel-normal' => 'Réel normal',
            ],
            'imposition' => ['is'],
        ],
        'sasu' => [
            'label' => 'SASU',
            'regimes' => [
                'reel-simplifie' => 'Réel simplifié',
                'reel-normal' => 'Réel normal',
            ],
            'imposition' => ['is', 'ir'],
        ],
    ];
}

function getRegimeDefaults(string $forme, string $regime): array {
    $defaults = [
        'micro-bnc' => [
            'plafond_ca' => '77700',
            'taux_abattement' => '34',
            'taux_cotisations_sociales' => '21.10',
            'taux_cfp' => '0.20',
            'taux_versement_liberatoire' => '2.20',
        ],
        'micro-bic-services' => [
            'plafond_ca' => '77700',
            'taux_abattement' => '50',
            'taux_cotisations_sociales' => '21.20',
            'taux_cfp' => '0.30',
            'taux_versement_liberatoire' => '1.70',
        ],
        'micro-bic-vente' => [
            'plafond_ca' => '188700',
            'taux_abattement' => '71',
            'taux_cotisations_sociales' => '12.30',
            'taux_cfp' => '0.10',
            'taux_versement_liberatoire' => '1.00',
        ],
        'reel-simplifie' => [
            'plafond_ca' => '0',
            'taux_abattement' => '0',
            'taux_cotisations_sociales' => '0',
            'taux_cfp' => '0',
            'taux_versement_liberatoire' => '0',
        ],
        'reel-normal' => [
            'plafond_ca' => '0',
            'taux_abattement' => '0',
            'taux_cotisations_sociales' => '0',
            'taux_cfp' => '0',
            'taux_versement_liberatoire' => '0',
        ],
    ];
    return $defaults[$regime] ?? $defaults['micro-bnc'];
}

function getRegimeLabel(): string {
    $conf = getRegimeConfig();
    $formes = getFormesJuridiques();
    $formeLabel = $formes[$conf['forme_juridique']]['label'] ?? $conf['forme_juridique'];
    $regimeLabels = $formes[$conf['forme_juridique']]['regimes'] ?? [];
    $regimeLabel = $regimeLabels[$conf['regime']] ?? $conf['regime'];
    return $formeLabel . ' — ' . $regimeLabel;
}

// --- Statistiques ---
function getStatsAnnee(int $annee): array {
    $db = getDB();
    $conf = getRegimeConfig();

    $stmt = $db->prepare("SELECT COALESCE(SUM(montant), 0) FROM transactions WHERE type = 'recette' AND YEAR(date_transaction) = ?");
    $stmt->execute([$annee]);
    $totalRecettes = (float) $stmt->fetchColumn();

    $stmt = $db->prepare("SELECT COALESCE(SUM(montant), 0) FROM transactions WHERE type = 'depense' AND YEAR(date_transaction) = ?");
    $stmt->execute([$annee]);
    $totalDepenses = (float) $stmt->fetchColumn();

    $params = [
        'plafond_ca' => (float) getParam('plafond_ca', '0'),
        'taux_abattement' => (float) getParam('taux_abattement', '0'),
        'taux_cotisations_sociales' => (float) getParam('taux_cotisations_sociales', '0'),
        'taux_cfp' => (float) getParam('taux_cfp', '0'),
        'versement_liberatoire_actif' => (bool) getParam('versement_liberatoire_actif', '0'),
        'taux_versement_liberatoire' => (float) getParam('taux_versement_liberatoire', '0'),
        'tva_applicable' => (bool) getParam('tva_applicable', '0'),
        'taux_tva' => (float) getParam('taux_tva', '20'),
        'taux_is' => (float) getParam('taux_is', '15'),
    ];

    return ['annee' => $annee] + calculerStatsRegime($conf, $totalRecettes, $totalDepenses, $params);
}

/**
 * Calcule le résultat fiscal (micro-entreprise ou régime réel) à partir des
 * totaux de l'année et des taux de paramétrage. Fonction pure, sans accès
 * base de données, pour permettre des tests unitaires sur les calculs
 * fiscaux/comptables.
 */
function calculerStatsRegime(array $conf, float $totalRecettes, float $totalDepenses, array $params): array {
    $plafondCA = $params['plafond_ca'] ?? 0.0;
    $tauxAbattement = $params['taux_abattement'] ?? 0.0;
    $tauxCotisations = $params['taux_cotisations_sociales'] ?? 0.0;
    $tauxCfp = $params['taux_cfp'] ?? 0.0;
    $vlActif = $params['versement_liberatoire_actif'] ?? false;
    $tauxVL = $params['taux_versement_liberatoire'] ?? 0.0;
    $tvaApplicable = $params['tva_applicable'] ?? false;
    $tauxTva = $params['taux_tva'] ?? 20.0;
    $tauxIS = $params['taux_is'] ?? 15.0;

    $result = [
        'total_recettes' => $totalRecettes,
        'total_depenses' => $totalDepenses,
        'regime' => $conf,
    ];

    if ($conf['is_micro']) {
        // --- MICRO-ENTREPRENEUR / AUTO-ENTREPRENEUR ---
        $beneficeAvantCharges = $totalRecettes - $totalDepenses;
        $abattement = $totalRecettes * ($tauxAbattement / 100);
        $revenuImposable = max(0, $totalRecettes - $abattement);
        $cotisationsSociales = $totalRecettes * ($tauxCotisations / 100);
        $cfp = $totalRecettes * ($tauxCfp / 100);
        $versementLiberatoire = $vlActif ? $totalRecettes * ($tauxVL / 100) : 0;
        $chargesObligatoires = $cotisationsSociales + $cfp + $versementLiberatoire;
        $beneficeNet = $totalRecettes - $totalDepenses - $chargesObligatoires;

        $result += [
            'benefice_avant_charges' => $beneficeAvantCharges,
            'abattement' => $abattement,
            'revenu_imposable' => $revenuImposable,
            'cotisations_sociales' => $cotisationsSociales,
            'cfp' => $cfp,
            'versement_liberatoire' => $versementLiberatoire,
            'charges_obligatoires' => $chargesObligatoires,
            'benefice_net' => $beneficeNet,
            'plafond_ca' => $plafondCA,
            'pct_plafond' => $plafondCA > 0 ? round($totalRecettes / $plafondCA * 100, 1) : 0,
            'tva_collectee' => 0,
            'tva_deductible' => 0,
            'tva_a_payer' => 0,
            'is' => 0,
        ];
    } else {
        // --- RÉGIME RÉEL (EI, EURL, SARL, SAS, SASU) ---
        $resultatBrut = $totalRecettes - $totalDepenses;

        // TVA
        $tvaCollectee = 0;
        $tvaDeductible = 0;
        if ($tvaApplicable) {
            $tvaCollectee = $totalRecettes * ($tauxTva / (100 + $tauxTva));
            $tvaDeductible = $totalDepenses * ($tauxTva / (100 + $tauxTva));
        }
        $tvaAPayer = max(0, $tvaCollectee - $tvaDeductible);

        // CA HT
        $caHT = $tvaApplicable ? $totalRecettes - $tvaCollectee : $totalRecettes;
        $depensesHT = $tvaApplicable ? $totalDepenses - $tvaDeductible : $totalDepenses;
        $resultatNet = $caHT - $depensesHT;

        // IS (si applicable)
        $is = 0;
        if ($conf['regime_imposition'] === 'is' && $resultatNet > 0) {
            if ($resultatNet <= 42500) {
                $is = $resultatNet * ($tauxIS / 100);
            } else {
                $is = 42500 * ($tauxIS / 100) + ($resultatNet - 42500) * 0.25;
            }
        }

        $beneficeNetApresIS = $resultatNet - $is;

        $result += [
            'benefice_avant_charges' => $resultatBrut,
            'ca_ht' => $caHT,
            'depenses_ht' => $depensesHT,
            'resultat_net' => $resultatNet,
            'abattement' => 0,
            'revenu_imposable' => $conf['regime_imposition'] === 'is' ? $beneficeNetApresIS : $resultatNet,
            'cotisations_sociales' => 0,
            'cfp' => 0,
            'versement_liberatoire' => 0,
            'charges_obligatoires' => $is,
            'benefice_net' => $beneficeNetApresIS,
            'plafond_ca' => $plafondCA,
            'pct_plafond' => 0,
            'tva_collectee' => $tvaCollectee,
            'tva_deductible' => $tvaDeductible,
            'tva_a_payer' => $tvaAPayer,
            'is' => $is,
        ];
    }

    return $result;
}

function getStatsMensuelles(int $annee): array {
    $db = getDB();
    $stmt = $db->prepare("
        SELECT 
            DATE_FORMAT(date_transaction, '%Y-%m') AS mois,
            type,
            COALESCE(SUM(montant), 0) AS total
        FROM transactions 
        WHERE YEAR(date_transaction) = ?
        GROUP BY mois, type
        ORDER BY mois
    ");
    $stmt->execute([$annee]);
    $rows = $stmt->fetchAll();

    $moisData = [];
    for ($m = 1; $m <= 12; $m++) {
        $key = sprintf('%d-%02d', $annee, $m);
        $moisData[$key] = ['recettes' => 0, 'depenses' => 0, 'solde' => 0];
    }
    foreach ($rows as $r) {
        if ($r['type'] === 'recette') {
            $moisData[$r['mois']]['recettes'] = (float) $r['total'];
        } else {
            $moisData[$r['mois']]['depenses'] = (float) $r['total'];
        }
    }
    foreach ($moisData as $key => &$d) {
        $d['solde'] = $d['recettes'] - $d['depenses'];
    }
    return $moisData;
}

function getStatsParCategorie(int $annee, string $type): array {
    $db = getDB();
    $stmt = $db->prepare("
        SELECT c.nom, c.couleur, COALESCE(SUM(t.montant), 0) AS total
        FROM transactions t
        LEFT JOIN categories c ON t.categorie_id = c.id
        WHERE t.type = ? AND YEAR(t.date_transaction) = ?
        GROUP BY c.id, c.nom, c.couleur
        ORDER BY total DESC
    ");
    $stmt->execute([$type, $annee]);
    return $stmt->fetchAll();
}

function getAnneesDisponibles(): array {
    $db = getDB();
    $stmt = $db->query('SELECT DISTINCT YEAR(date_transaction) AS annee FROM transactions ORDER BY annee DESC');
    $annees = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if (empty($annees)) {
        $annees = [(int) date('Y')];
    }
    return $annees;
}

// --- Exercices comptables ---
function getExercices(): array {
    $db = getDB();
    return $db->query('SELECT * FROM exercices ORDER BY date_debut DESC')->fetchAll();
}

function getExercice(int $id): ?array {
    $db = getDB();
    $stmt = $db->prepare('SELECT * FROM exercices WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function getExerciceEnCours(): ?array {
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM exercices WHERE statut = 'ouvert' AND date_debut <= CURDATE() AND date_fin >= CURDATE() LIMIT 1");
    $stmt->execute();
    $row = $stmt->fetch();
    return $row ?: null;
}

function ajouterExercice(array $data): int {
    $db = getDB();
    $stmt = $db->prepare('INSERT INTO exercices (nom, date_debut, date_fin, notes) VALUES (?, ?, ?, ?)');
    $stmt->execute([$data['nom'], $data['date_debut'], $data['date_fin'], $data['notes'] ?? null]);
    return (int) $db->lastInsertId();
}

function modifierExercice(int $id, array $data): void {
    $db = getDB();
    $stmt = $db->prepare('UPDATE exercices SET nom=?, date_debut=?, date_fin=?, notes=? WHERE id=?');
    $stmt->execute([$data['nom'], $data['date_debut'], $data['date_fin'], $data['notes'] ?? null, $id]);
}

function cloturerExercice(int $id): void {
    $db = getDB();
    $stmt = $db->prepare("UPDATE exercices SET statut = 'cloture' WHERE id = ?");
    $stmt->execute([$id]);
}

function rouvrirExercice(int $id): void {
    $db = getDB();
    $stmt = $db->prepare("UPDATE exercices SET statut = 'ouvert' WHERE id = ?");
    $stmt->execute([$id]);
}

function supprimerExercice(int $id): void {
    $db = getDB();
    $stmt = $db->prepare('DELETE FROM exercices WHERE id = ?');
    $stmt->execute([$id]);
}

function getStatsExercice(array $exercice): array {
    $db = getDB();
    $stmtR = $db->prepare("SELECT COALESCE(SUM(montant), 0) FROM transactions WHERE type = 'recette' AND date_transaction BETWEEN ? AND ?");
    $stmtR->execute([$exercice['date_debut'], $exercice['date_fin']]);
    $recettes = (float) $stmtR->fetchColumn();

    $stmtD = $db->prepare("SELECT COALESCE(SUM(montant), 0) FROM transactions WHERE type = 'depense' AND date_transaction BETWEEN ? AND ?");
    $stmtD->execute([$exercice['date_debut'], $exercice['date_fin']]);
    $depenses = (float) $stmtD->fetchColumn();

    $stmtN = $db->prepare("SELECT COUNT(*) FROM transactions WHERE date_transaction BETWEEN ? AND ?");
    $stmtN->execute([$exercice['date_debut'], $exercice['date_fin']]);
    $nbEcritures = (int) $stmtN->fetchColumn();

    return [
        'recettes' => $recettes,
        'depenses' => $depenses,
        'resultat' => $recettes - $depenses,
        'nb_ecritures' => $nbEcritures,
    ];
}

// --- Flash messages ---
function setFlash(string $type, string $message): void {
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function getFlash(): ?array {
    if (isset($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}

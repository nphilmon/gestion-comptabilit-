<?php
/**
 * Fonctions utilitaires
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/Totp.php';

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
    header("Content-Security-Policy: default-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'; object-src 'none'; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdn.tailwindcss.com; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://fonts.googleapis.com; font-src 'self' https://cdn.jsdelivr.net https://fonts.gstatic.com data:; img-src 'self' data:; connect-src 'self' https://recherche-entreprises.api.gouv.fr;");
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
        $stmt = $db->prepare('SELECT id, nom, email, role, actif, totp_enabled FROM users WHERE id = ? AND actif = 1');
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
    $user = isLoggedIn() ? getCurrentUser() : null;
    if (!$user) {
        $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'];
        $_SESSION = [];
        session_destroy();
        header('Location: ' . BASE_URL . 'login.php');
        exit;
    }
    // Filet de sécurité : la 2FA est obligatoire pour tous les comptes. Une
    // session déjà ouverte avant l'activation de cette exigence (ou pour un
    // compte dont la 2FA aurait été désactivée entre-temps) est redirigée
    // vers la configuration obligatoire plutôt que de continuer sans 2FA.
    if (empty($user['totp_enabled'])) {
        $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'];
        header('Location: ' . BASE_URL . 'configurer_2fa.php');
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

/**
 * Vérifie le mot de passe et prépare la connexion, sans jamais la finaliser
 * directement : la double authentification (TOTP) étant obligatoire pour
 * tous les comptes, la session complète n'est ouverte qu'après succès de
 * finalizeLogin(), une fois le code TOTP (ou un code de secours) vérifié
 * par verifier_2fa.php — ou juste après la configuration initiale
 * obligatoire par configurer_2fa.php pour un compte qui n'a pas encore
 * activé la 2FA.
 *
 * Retourne :
 *  - 'invalid'        : email/mot de passe incorrect ou compte inactif
 *  - '2fa_required'    : mot de passe correct, code TOTP à saisir
 *  - 'setup_required'  : mot de passe correct, 2FA pas encore activée
 */
function attemptLogin(string $email, string $password): string {
    $db = getDB();
    $stmt = $db->prepare('SELECT id, nom, email, password_hash, role, actif, totp_enabled FROM users WHERE LOWER(email) = LOWER(?)');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user || !$user['actif'] || !password_verify($password, $user['password_hash'])) {
        recordLoginAttempt($email);
        return 'invalid';
    }

    clearLoginAttempts($email);

    // Rehacher si nécessaire
    if (password_needs_rehash($user['password_hash'], PASSWORD_BCRYPT)) {
        $newHash = password_hash($password, PASSWORD_BCRYPT);
        $db->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([$newHash, $user['id']]);
    }

    // Regénérer l'ID de session pour prévenir la fixation de session, dès que
    // le mot de passe est validé (avant même l'étape de 2FA).
    session_regenerate_id(true);
    $_SESSION['pending_2fa_user_id'] = $user['id'];
    $_SESSION['pending_2fa_since'] = time();

    return $user['totp_enabled'] ? '2fa_required' : 'setup_required';
}

// Délai maximum pour finaliser une connexion en attente de 2FA (secondes).
const PENDING_2FA_TTL = 600;

/**
 * Récupère l'utilisateur dont la connexion est en attente de 2FA (mot de
 * passe déjà vérifié par attemptLogin), pour verifier_2fa.php et
 * configurer_2fa.php. Retourne null (et nettoie la session) si aucune
 * connexion n'est en attente, si elle a expiré, ou si le compte n'existe
 * plus / a été désactivé entre-temps.
 */
function getPending2faUser(): ?array {
    if (empty($_SESSION['pending_2fa_user_id'])) {
        return null;
    }
    if (time() - (int) ($_SESSION['pending_2fa_since'] ?? 0) > PENDING_2FA_TTL) {
        clearPending2fa();
        return null;
    }
    $db = getDB();
    $stmt = $db->prepare('SELECT id, nom, email, role, actif, totp_secret, totp_enabled FROM users WHERE id = ? AND actif = 1');
    $stmt->execute([$_SESSION['pending_2fa_user_id']]);
    $user = $stmt->fetch();
    if (!$user) {
        clearPending2fa();
        return null;
    }
    return $user;
}

function clearPending2fa(): void {
    unset($_SESSION['pending_2fa_user_id'], $_SESSION['pending_2fa_since']);
}

/**
 * Ouvre la session complète pour l'utilisateur dont la connexion était en
 * attente de 2FA, une fois le code TOTP (ou un code de secours) vérifié,
 * ou juste après l'activation initiale obligatoire de la 2FA.
 */
function finalizeLogin(int $userId): bool {
    $db = getDB();
    $stmt = $db->prepare('SELECT id, nom, email, role, actif FROM users WHERE id = ? AND actif = 1');
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    if (!$user) {
        return false;
    }

    clearPending2fa();
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['user_nom'] = $user['nom'];
    $_SESSION['user_role'] = $user['role'];
    $_SESSION['last_activity'] = time();

    $db->prepare('UPDATE users SET derniere_connexion = NOW() WHERE id = ?')->execute([$user['id']]);
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

// --- Double authentification (TOTP) obligatoire ---

function userNeedsTotpSetup(array $user): bool {
    return empty($user['totp_enabled']);
}

/**
 * Génère (ou réutilise) le secret TOTP en attente de confirmation pour un
 * utilisateur. Réutilise le secret existant tant qu'il n'a pas encore été
 * confirmé, pour qu'un simple rechargement de la page de configuration ne
 * rende pas invalide un QR code déjà scanné par l'application de
 * l'utilisateur.
 */
function generateTotpSecret(int $userId): string {
    $db = getDB();
    $stmt = $db->prepare('SELECT totp_secret, totp_enabled FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    if ($row && !empty($row['totp_secret']) && !$row['totp_enabled']) {
        return $row['totp_secret'];
    }

    $secret = Totp::generateSecret();
    $db->prepare('UPDATE users SET totp_secret = ?, totp_enabled = 0, totp_confirmed_at = NULL WHERE id = ?')
        ->execute([$secret, $userId]);
    return $secret;
}

/**
 * Active la 2FA pour un utilisateur après vérification du premier code TOTP
 * saisi lors de la configuration. Retourne false si aucun secret en attente
 * n'existe ou si le code est invalide.
 */
function enableTotpForUser(int $userId, string $code): bool {
    $db = getDB();
    $stmt = $db->prepare('SELECT totp_secret FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $secret = $stmt->fetchColumn();
    if (!$secret || !Totp::verify($secret, $code)) {
        return false;
    }

    $db->prepare('UPDATE users SET totp_enabled = 1, totp_confirmed_at = NOW() WHERE id = ?')->execute([$userId]);
    return true;
}

/**
 * Vérifie un code TOTP pour un utilisateur dont la 2FA est déjà active
 * (étape de connexion, dans verifier_2fa.php).
 */
function verifyTotpCode(int $userId, string $code): bool {
    $db = getDB();
    $stmt = $db->prepare('SELECT totp_secret FROM users WHERE id = ? AND totp_enabled = 1');
    $stmt->execute([$userId]);
    $secret = $stmt->fetchColumn();
    if (!$secret) {
        return false;
    }
    return Totp::verify($secret, $code);
}

/**
 * (Re)génère un jeu de 10 codes de secours à usage unique pour un
 * utilisateur, en remplaçant tout jeu précédent. Les codes en clair ne sont
 * jamais stockés : seul leur hash l'est, comme pour les mots de passe.
 * Les codes en clair sont retournés une seule fois, pour affichage immédiat.
 */
function generateBackupCodes(int $userId, int $count = 10): array {
    $db = getDB();
    $db->prepare('DELETE FROM user_backup_codes WHERE user_id = ?')->execute([$userId]);

    $insert = $db->prepare('INSERT INTO user_backup_codes (user_id, code_hash) VALUES (?, ?)');
    $codes = [];
    for ($i = 0; $i < $count; $i++) {
        $code = strtoupper(bin2hex(random_bytes(4))); // 8 caractères hexadécimaux
        $codes[] = $code;
        $insert->execute([$userId, password_hash($code, PASSWORD_BCRYPT)]);
    }
    return $codes;
}

/**
 * Vérifie un code de secours et le marque comme utilisé s'il est valide, en
 * secours au TOTP (appareil perdu). Chaque code n'est utilisable qu'une
 * seule fois.
 */
function verifyAndConsumeBackupCode(int $userId, string $code): bool {
    $code = strtoupper(trim($code));
    if ($code === '') {
        return false;
    }

    $db = getDB();
    $stmt = $db->prepare('SELECT id, code_hash FROM user_backup_codes WHERE user_id = ? AND used_at IS NULL');
    $stmt->execute([$userId]);
    foreach ($stmt->fetchAll() as $row) {
        if (password_verify($code, $row['code_hash'])) {
            $db->prepare('UPDATE user_backup_codes SET used_at = NOW() WHERE id = ?')->execute([$row['id']]);
            return true;
        }
    }
    return false;
}

// --- Réinitialisation de mot de passe ---
function checkPasswordResetRequests(?string $identifier = null): bool {
    return checkRateLimit('password_reset_request', 3, 900, $identifier);
}

function recordPasswordResetRequest(?string $identifier = null): void {
    recordRateLimitHit('password_reset_request', $identifier);
}

function getPasswordResetWaitTime(?string $identifier = null): int {
    return getRateLimitWaitTime('password_reset_request', 900, $identifier);
}

function getAbsoluteBaseUrl(): string {
    $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443);
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return ($isSecure ? 'https://' : 'http://') . $host . BASE_URL;
}

/**
 * Crée un jeton de réinitialisation pour l'utilisateur correspondant à
 * l'email donné. Retourne null si aucun compte actif ne correspond, sans
 * jamais lever d'erreur, pour ne pas permettre l'énumération des comptes
 * depuis l'appelant (mot_de_passe_oublie.php affiche le même message dans
 * les deux cas).
 */
function createPasswordResetToken(string $email): ?string {
    $db = getDB();
    $stmt = $db->prepare('SELECT id FROM users WHERE LOWER(email) = LOWER(?) AND actif = 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    if (!$user) {
        return null;
    }

    $token = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $token);
    $expiresAt = date('Y-m-d H:i:s', time() + 3600);

    $db->prepare('DELETE FROM password_resets WHERE user_id = ?')->execute([$user['id']]);
    $db->prepare('INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (?, ?, ?)')
        ->execute([$user['id'], $tokenHash, $expiresAt]);

    logActivity((int) $user['id'], 'mot_de_passe_oublie', 'Demande de réinitialisation de mot de passe');

    return $token;
}

function sendPasswordResetEmail(string $email, string $token): bool {
    $link = getAbsoluteBaseUrl() . 'reinitialiser_mot_de_passe.php?token=' . urlencode($token);

    $subject = '=?UTF-8?B?' . base64_encode('Réinitialisation de votre mot de passe - ' . APP_NAME) . '?=';
    $body = "Bonjour,\r\n\r\n"
        . "Une demande de réinitialisation de mot de passe a été effectuée pour votre compte " . APP_NAME . ".\r\n\r\n"
        . "Cliquez sur le lien ci-dessous pour choisir un nouveau mot de passe (valable 1 heure) :\r\n"
        . $link . "\r\n\r\n"
        . "Si vous n'êtes pas à l'origine de cette demande, ignorez simplement cet email.\r\n\r\n"
        . "-- \r\n" . APP_NAME;

    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $host = preg_replace('/[^a-zA-Z0-9.\-]/', '', explode(':', $host)[0]) ?: 'localhost';
    $fromEmail = envValue('GESTION_COMPTA_MAIL_FROM', 'no-reply@' . $host);

    $headers = "From: " . APP_NAME . " <" . $fromEmail . ">\r\n"
        . "Content-Type: text/plain; charset=UTF-8\r\n";

    try {
        return @mail($email, $subject, $body, $headers);
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Envoie un message du formulaire de contact public (presentation.php) à
 * l'adresse de contact configurée dans Paramètres. $email doit avoir été
 * validé (FILTER_VALIDATE_EMAIL) par l'appelant avant l'appel : une
 * adresse valide ne peut pas contenir de retour à la ligne, ce qui
 * protège l'en-tête Reply-To contre une injection d'en-têtes.
 */
function sendContactMessage(string $nom, string $email, string $message): bool {
    try {
        $to = getParam('email_entreprise', '');
        if ($to === '') {
            return false;
        }

        $subject = '=?UTF-8?B?' . base64_encode('Nouveau message de contact - ' . APP_NAME) . '?=';
        $body = "Nouveau message reçu depuis la page de présentation de " . APP_NAME . " :\r\n\r\n"
            . "Nom : " . $nom . "\r\n"
            . "Email : " . $email . "\r\n\r\n"
            . "Message :\r\n" . $message . "\r\n";

        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $host = preg_replace('/[^a-zA-Z0-9.\-]/', '', explode(':', $host)[0]) ?: 'localhost';
        $fromEmail = envValue('GESTION_COMPTA_MAIL_FROM', 'no-reply@' . $host);

        $headers = "From: " . APP_NAME . " <" . $fromEmail . ">\r\n"
            . "Reply-To: " . $email . "\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n";

        return @mail($to, $subject, $body, $headers);
    } catch (Throwable $e) {
        return false;
    }
}

function getUserByResetToken(string $token): ?array {
    if ($token === '') {
        return null;
    }
    $db = getDB();
    $tokenHash = hash('sha256', $token);
    $stmt = $db->prepare('SELECT u.id, u.nom, u.email FROM password_resets r
        INNER JOIN users u ON u.id = r.user_id
        WHERE r.token_hash = ? AND r.used_at IS NULL AND r.expires_at >= NOW() AND u.actif = 1');
    $stmt->execute([$tokenHash]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function resetPasswordWithToken(string $token, string $newPassword): bool {
    $user = getUserByResetToken($token);
    if (!$user) {
        return false;
    }

    $db = getDB();
    $hash = password_hash($newPassword, PASSWORD_BCRYPT);

    $db->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([$hash, $user['id']]);
    $db->prepare('DELETE FROM password_resets WHERE user_id = ?')->execute([$user['id']]);
    clearLoginAttempts($user['email']);

    logActivity((int) $user['id'], 'reinitialisation_mot_de_passe', 'Mot de passe réinitialisé via lien de récupération');

    return true;
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

// --- Pagination (listes de documents commerciaux, etc.) ---
function renderPagination(int $page, int $totalPages, array $queryParams = []): string {
    if ($totalPages <= 1) {
        return '';
    }
    $page = max(1, min($page, $totalPages));
    $buildUrl = function (int $p) use ($queryParams): string {
        $params = $queryParams;
        $params['page'] = $p;
        return '?' . http_build_query($params);
    };

    $html = '<nav aria-label="Pagination"><ul class="pagination pagination-sm justify-content-center mb-0">';

    $html .= '<li class="page-item' . ($page <= 1 ? ' disabled' : '') . '">'
        . '<a class="page-link" href="' . e($buildUrl(max(1, $page - 1))) . '">&laquo; Précédent</a></li>';

    $start = max(1, $page - 2);
    $end = min($totalPages, $page + 2);
    if ($start > 1) {
        $html .= '<li class="page-item"><a class="page-link" href="' . e($buildUrl(1)) . '">1</a></li>';
        if ($start > 2) {
            $html .= '<li class="page-item disabled"><span class="page-link">&hellip;</span></li>';
        }
    }
    for ($i = $start; $i <= $end; $i++) {
        $html .= '<li class="page-item' . ($i === $page ? ' active' : '') . '">'
            . '<a class="page-link" href="' . e($buildUrl($i)) . '">' . $i . '</a></li>';
    }
    if ($end < $totalPages) {
        if ($end < $totalPages - 1) {
            $html .= '<li class="page-item disabled"><span class="page-link">&hellip;</span></li>';
        }
        $html .= '<li class="page-item"><a class="page-link" href="' . e($buildUrl($totalPages)) . '">' . $totalPages . '</a></li>';
    }

    $html .= '<li class="page-item' . ($page >= $totalPages ? ' disabled' : '') . '">'
        . '<a class="page-link" href="' . e($buildUrl(min($totalPages, $page + 1))) . '">Suivant &raquo;</a></li>';

    $html .= '</ul></nav>';
    return $html;
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

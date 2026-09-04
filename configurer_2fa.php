<?php
/**
 * Configuration obligatoire de la double authentification (TOTP).
 * Accessible soit juste après le mot de passe (connexion en attente, avant
 * l'ouverture de session complète), soit pour un compte déjà connecté qui
 * n'a pas encore activé la 2FA (filet de sécurité pour une session
 * existante ouverte avant l'activation de cette fonctionnalité).
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';
sendSecurityHeaders();

$pendingUser = getPending2faUser();

if ($pendingUser) {
    if ($pendingUser['totp_enabled']) {
        // État incohérent (2FA déjà active) : direction la vérification.
        header('Location: ' . BASE_URL . 'verifier_2fa.php');
        exit;
    }
    $user = $pendingUser;
} elseif (isLoggedIn()) {
    $current = getCurrentUser();
    if (!$current) {
        header('Location: ' . BASE_URL . 'login.php');
        exit;
    }
    $db = getDB();
    $stmt = $db->prepare('SELECT id, nom, email, totp_enabled FROM users WHERE id = ?');
    $stmt->execute([$current['id']]);
    $user = $stmt->fetch();
    if (!$user || $user['totp_enabled']) {
        // Déjà activée : rien à configurer.
        header('Location: ' . BASE_URL);
        exit;
    }
} else {
    header('Location: ' . BASE_URL . 'login.php');
    exit;
}

$error = '';
$activated = false;
$backupCodes = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        $error = 'Jeton de sécurité invalide. Veuillez réessayer.';
    } elseif (!checkRateLimit('totp_setup', 8, 900, (string) $user['id'])) {
        $wait = max(1, ceil(getRateLimitWaitTime('totp_setup', 900, (string) $user['id']) / 60));
        $error = "Trop de tentatives. Réessayez dans $wait minute(s).";
    } else {
        $code = trim($_POST['code'] ?? '');
        if (enableTotpForUser((int) $user['id'], $code)) {
            clearRateLimitHits('totp_setup', (string) $user['id']);
            $backupCodes = generateBackupCodes((int) $user['id']);
            finalizeLogin((int) $user['id']);
            $activated = true;
            $continueUrl = $_SESSION['redirect_after_login'] ?? BASE_URL;
            unset($_SESSION['redirect_after_login']);
        } else {
            recordRateLimitHit('totp_setup', (string) $user['id']);
            $error = 'Code invalide. Vérifiez l\'heure de votre appareil et réessayez.';
        }
    }
}

$secret = null;
$qrDataUri = null;
$manualKey = null;

if (!$activated) {
    $secret = generateTotpSecret((int) $user['id']);
    $manualKey = Totp::formatSecretForDisplay($secret);
    $provisioningUri = Totp::provisioningUri($secret, $user['email'], APP_NAME);

    require_once __DIR__ . '/vendor/autoload.php';
    $renderer = new BaconQrCode\Renderer\ImageRenderer(
        new BaconQrCode\Renderer\RendererStyle\RendererStyle(220),
        new BaconQrCode\Renderer\Image\SvgImageBackEnd()
    );
    $svg = (new BaconQrCode\Writer($renderer))->writeString($provisioningUri);
    $qrDataUri = 'data:image/svg+xml;base64,' . base64_encode($svg);
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Double authentification obligatoire - <?= APP_NAME ?></title>
    <link rel="icon" type="image/svg+xml" href="<?= BASE_URL ?>assets/favicon.svg?v=<?= e(APP_VERSION) ?>">
    <link rel="shortcut icon" type="image/svg+xml" href="<?= BASE_URL ?>assets/favicon.svg?v=<?= e(APP_VERSION) ?>">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet" integrity="sha384-XGjxtQfXaH2tnPFa9x+ruJTuLE3Aa6LhHSWRr1XeTyhezb4abCG4ccI5AkVDxqC+" crossorigin="anonymous">
    <style>
        :root {
            --md-primary: #2563EB;
            --md-on-primary: #FFFFFF;
            --md-surface: #F0F2F5;
            --md-on-surface: #191C20;
            --md-on-surface-variant: #43474E;
            --md-outline: #73777F;
            --md-outline-variant: #C3C7CF;
            --md-surface-container-lowest: #FFFFFF;
            --md-surface-container: #ECEEF0;
            --md-error-container: #FFDAD6;
            --md-on-error-container: #410002;
            --md-success-container: #DFF5E1;
            --md-on-success-container: #0F5132;
            --md-warning-container: #FFE0B2;
            --md-elevation-3: 0 4px 8px 3px rgba(0,0,0,0.15), 0 1px 3px rgba(0,0,0,0.3);
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            background: var(--md-surface);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Inter', 'Roboto', system-ui, sans-serif;
            padding: 1.5rem;
        }
        .card-wrapper {
            background: var(--md-surface-container-lowest);
            border-radius: 28px;
            box-shadow: var(--md-elevation-3);
            max-width: 480px;
            width: 100%;
            overflow: hidden;
        }
        .card-header {
            background: var(--md-primary);
            color: var(--md-on-primary);
            padding: 2.25rem 2rem 1.75rem;
            text-align: center;
        }
        .card-header i { font-size: 2.5rem; margin-bottom: 0.5rem; display: inline-block; }
        .card-header h1 { font-size: 1.3rem; font-weight: 700; margin: 0; }
        .card-header p { margin-top: 0.4rem; opacity: 0.85; font-size: 0.875rem; font-weight: 300; }
        .card-body { padding: 2rem; }
        .form-label { font-weight: 500; font-size: 0.8125rem; color: var(--md-on-surface-variant); margin-bottom: 0.375rem; }
        .step-label {
            display: flex; align-items: center; gap: 0.5rem;
            font-weight: 700; font-size: 0.85rem; color: var(--md-on-surface);
            margin-bottom: 0.75rem;
        }
        .step-num {
            display: inline-flex; align-items: center; justify-content: center;
            width: 22px; height: 22px; border-radius: 50%;
            background: var(--md-primary); color: #fff; font-size: 0.75rem; flex-shrink: 0;
        }
        .qr-box {
            display: flex; justify-content: center; align-items: center;
            background: var(--md-surface-container);
            border-radius: 16px;
            padding: 1.25rem;
            margin-bottom: 1rem;
        }
        .qr-box img { width: 200px; height: 200px; }
        .manual-key {
            font-family: 'Courier New', monospace;
            font-size: 0.95rem;
            letter-spacing: 0.05em;
            background: var(--md-surface-container);
            border-radius: 10px;
            padding: 0.75rem 1rem;
            text-align: center;
            word-break: break-all;
            margin-bottom: 1.5rem;
            color: var(--md-on-surface);
        }
        .md-input-group {
            position: relative;
            display: flex;
            align-items: stretch;
            border: 1.5px solid var(--md-outline-variant);
            border-radius: 12px;
            background: var(--md-surface-container-lowest);
            transition: border-color 0.2s, box-shadow 0.2s;
            overflow: hidden;
        }
        .md-input-group:focus-within { border-color: var(--md-primary); box-shadow: 0 0 0 1px var(--md-primary); }
        .md-input-group .input-icon {
            display: flex; align-items: center; justify-content: center;
            width: 48px; flex-shrink: 0; color: var(--md-on-surface-variant); font-size: 1.1rem;
        }
        .md-input-group:focus-within .input-icon { color: var(--md-primary); }
        .md-input-group .form-control {
            border: none !important; box-shadow: none !important;
            padding: 0.75rem 0.75rem 0.75rem 0; font-size: 1.1rem; letter-spacing: 0.15em;
            background: transparent; flex: 1;
        }
        .btn-primary-pill {
            background: var(--md-primary);
            border: none;
            padding: 0.875rem;
            font-weight: 500;
            font-size: 0.9375rem;
            border-radius: 9999px;
            color: var(--md-on-primary);
            transition: box-shadow 0.2s, transform 0.15s;
        }
        .btn-primary-pill:hover { box-shadow: 0 2px 6px rgba(21,101,192,0.4); transform: translateY(-1px); color: var(--md-on-primary); }
        .md-alert { display: flex; align-items: center; gap: 0.625rem; padding: 0.75rem 1rem; border-radius: 12px; font-size: 0.85rem; margin-bottom: 1rem; border: none; }
        .md-alert-error { background: var(--md-error-container); color: var(--md-on-error-container); }
        .md-alert-warning { background: var(--md-warning-container); color: #BF360C; }
        .backup-codes {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0.5rem;
            background: var(--md-surface-container);
            border-radius: 14px;
            padding: 1.25rem;
            margin-bottom: 1.25rem;
        }
        .backup-codes span {
            font-family: 'Courier New', monospace;
            font-size: 0.95rem;
            text-align: center;
            padding: 0.4rem 0;
            color: var(--md-on-surface);
        }
        .card-footer-links { text-align: center; padding: 0 2rem 1.75rem; color: var(--md-on-surface-variant); font-size: 0.85rem; }
        .card-footer-links a { color: var(--md-primary); text-decoration: none; font-weight: 500; }
        .card-footer-links a:hover { text-decoration: underline; }
        .card-footer-links form { display: inline; }
        .card-footer-links button.link-btn {
            background: none; border: none; color: var(--md-primary); font-weight: 500;
            font-size: 0.85rem; cursor: pointer; padding: 0;
        }
        .card-footer-links button.link-btn:hover { text-decoration: underline; }
    </style>
</head>
<body>
    <main class="card-wrapper">
        <div class="card-header">
            <i class="bi bi-shield-lock-fill"></i>
            <h1>Double authentification obligatoire</h1>
            <p><?= e(APP_NAME) ?></p>
        </div>
        <div class="card-body">
            <?php if ($activated): ?>
                <div class="md-alert" style="background: var(--md-success-container); color: var(--md-on-success-container);">
                    <i class="bi bi-check-circle-fill"></i>
                    <div>La double authentification est activée sur votre compte.</div>
                </div>

                <div class="md-alert md-alert-warning">
                    <i class="bi bi-exclamation-triangle-fill"></i>
                    <div>Notez ces 10 codes de secours dans un endroit sûr. Ils ne seront plus jamais affichés et permettent de vous connecter si vous perdez l'accès à votre application d'authentification. Chaque code n'est utilisable qu'une seule fois.</div>
                </div>

                <div class="backup-codes">
                    <?php foreach ($backupCodes as $code): ?>
                        <span><?= e($code) ?></span>
                    <?php endforeach; ?>
                </div>

                <a href="<?= e($continueUrl) ?>" class="btn btn-primary-pill w-100 d-block text-center">
                    J'ai noté mes codes, continuer
                </a>
            <?php else: ?>
                <?php if ($error): ?>
                    <div class="md-alert md-alert-error">
                        <i class="bi bi-exclamation-triangle-fill"></i> <?= e($error) ?>
                    </div>
                <?php endif; ?>

                <p style="color: var(--md-on-surface-variant); font-size: 0.875rem; margin-bottom: 1.5rem;">
                    Pour protéger votre compte, la double authentification est obligatoire.
                    Configurez-la maintenant avec une application comme Google Authenticator,
                    Microsoft Authenticator, Authy ou 1Password.
                </p>

                <div class="step-label"><span class="step-num">1</span> Scannez ce QR code</div>
                <div class="qr-box">
                    <img src="<?= e($qrDataUri) ?>" alt="QR code de configuration de la double authentification" width="200" height="200">
                </div>

                <div class="step-label"><span class="step-num">2</span> Ou saisissez cette clé manuellement</div>
                <div class="manual-key"><?= e($manualKey) ?></div>

                <form method="POST" autocomplete="off">
                    <?= csrfField() ?>
                    <div class="step-label"><span class="step-num">3</span> Entrez le code à 6 chiffres</div>
                    <div class="mb-4">
                        <div class="md-input-group">
                            <span class="input-icon"><i class="bi bi-key"></i></span>
                            <input type="text" class="form-control" id="code" name="code"
                                   placeholder="123456" inputmode="numeric" pattern="[0-9]{6}"
                                   maxlength="6" autocomplete="one-time-code" required autofocus>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary-pill w-100">
                        Activer la double authentification
                    </button>
                </form>
            <?php endif; ?>
        </div>
        <?php if (!$activated): ?>
        <div class="card-footer-links">
            <form method="POST" action="<?= BASE_URL ?>logout.php">
                <?= csrfField() ?>
                <button type="submit" class="link-btn"><i class="bi bi-box-arrow-right"></i> Annuler et se déconnecter</button>
            </form>
        </div>
        <?php endif; ?>
    </main>

    <?php include __DIR__ . '/cookie_notice.php'; ?>
</body>
</html>

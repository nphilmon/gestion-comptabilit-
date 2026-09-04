<?php
/**
 * Étape de connexion : saisie du code de double authentification (TOTP),
 * ou d'un code de secours, une fois le mot de passe déjà vérifié par
 * attemptLogin() dans login.php.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';
sendSecurityHeaders();

if (isLoggedIn()) {
    header('Location: ' . BASE_URL);
    exit;
}

$user = getPending2faUser();
if (!$user) {
    header('Location: ' . BASE_URL . 'login.php');
    exit;
}
if (!$user['totp_enabled']) {
    header('Location: ' . BASE_URL . 'configurer_2fa.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        $error = 'Jeton de sécurité invalide. Veuillez réessayer.';
    } elseif (!checkRateLimit('totp_verify', 8, 900, (string) $user['id'])) {
        $wait = max(1, ceil(getRateLimitWaitTime('totp_verify', 900, (string) $user['id']) / 60));
        $error = "Trop de tentatives. Réessayez dans $wait minute(s).";
    } else {
        $code = trim($_POST['code'] ?? '');
        $valid = ($code !== '' && preg_match('/^\d{6}$/', $code) && verifyTotpCode((int) $user['id'], $code))
            || ($code !== '' && verifyAndConsumeBackupCode((int) $user['id'], $code));

        if ($valid) {
            clearRateLimitHits('totp_verify', (string) $user['id']);
            finalizeLogin((int) $user['id']);
            $redirect = $_SESSION['redirect_after_login'] ?? BASE_URL;
            unset($_SESSION['redirect_after_login']);
            header('Location: ' . $redirect);
            exit;
        }

        recordRateLimitHit('totp_verify', (string) $user['id']);
        $error = 'Code invalide.';
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vérification en deux étapes - <?= APP_NAME ?></title>
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
            --md-error-container: #FFDAD6;
            --md-on-error-container: #410002;
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
            max-width: 420px;
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
            padding: 0.75rem 0.75rem 0.75rem 0; font-size: 1.2rem; letter-spacing: 0.2em; text-align: center;
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
        .helper-text { font-size: 0.8rem; color: var(--md-on-surface-variant); margin-top: 0.5rem; text-align: center; }
        .card-footer-links { text-align: center; padding: 0 2rem 1.75rem; color: var(--md-on-surface-variant); font-size: 0.85rem; }
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
            <i class="bi bi-shield-lock"></i>
            <h1>Vérification en deux étapes</h1>
            <p><?= e(APP_NAME) ?></p>
        </div>
        <div class="card-body">
            <?php if ($error): ?>
                <div class="md-alert md-alert-error">
                    <i class="bi bi-exclamation-triangle-fill"></i> <?= e($error) ?>
                </div>
            <?php endif; ?>

            <form method="POST" autocomplete="off">
                <?= csrfField() ?>
                <div class="mb-3">
                    <label class="form-label" for="code">Code de votre application d'authentification</label>
                    <div class="md-input-group">
                        <input type="text" class="form-control" id="code" name="code"
                               placeholder="123456" inputmode="numeric"
                               maxlength="8" autocomplete="one-time-code" required autofocus>
                    </div>
                    <p class="helper-text">Ou saisissez l'un de vos codes de secours à 8 caractères.</p>
                </div>

                <button type="submit" class="btn btn-primary-pill w-100">
                    Vérifier
                </button>
            </form>
        </div>
        <div class="card-footer-links">
            <form method="POST" action="<?= BASE_URL ?>logout.php">
                <?= csrfField() ?>
                <button type="submit" class="link-btn"><i class="bi bi-box-arrow-right"></i> Annuler et se déconnecter</button>
            </form>
        </div>
    </main>

    <?php include __DIR__ . '/cookie_notice.php'; ?>
</body>
</html>

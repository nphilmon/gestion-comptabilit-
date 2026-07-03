<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';
sendSecurityHeaders();

// Déjà connecté ? Rediriger
if (isLoggedIn()) {
    header('Location: ' . BASE_URL);
    exit;
}

// Aucun utilisateur ? Rediriger vers setup
$nbUsers = (int) getDB()->query('SELECT COUNT(*) FROM users')->fetchColumn();
if ($nbUsers === 0) {
    header('Location: ' . BASE_URL . 'setup.php');
    exit;
}

$inscriptionOuverte = isRegistrationOpen();

$error = '';
$expired = isset($_GET['expired']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        $error = 'Jeton de sécurité invalide. Veuillez réessayer.';
    }

    $email = mb_strtolower(trim($_POST['email'] ?? ''));
    $password = $_POST['password'] ?? '';

    if ($error !== '') {
        // erreur CSRF déjà définie
    } elseif ($email === '' || $password === '') {
        $error = 'Veuillez remplir tous les champs.';
    } elseif (!checkLoginAttempts($email)) {
        $wait = max(1, ceil(getLoginWaitTime($email) / 60));
        $error = "Trop de tentatives. Réessayez dans $wait minute(s).";
    } elseif (attemptLogin($email, $password)) {
        $redirect = $_SESSION['redirect_after_login'] ?? BASE_URL;
        unset($_SESSION['redirect_after_login']);
        header('Location: ' . $redirect);
        exit;
    } else {
        $error = 'Email ou mot de passe incorrect.';
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Connexion - <?= APP_NAME ?></title>
    <link rel="icon" type="image/svg+xml" href="<?= BASE_URL ?>assets/favicon.svg?v=<?= e(APP_VERSION) ?>">
    <link rel="shortcut icon" type="image/svg+xml" href="<?= BASE_URL ?>assets/favicon.svg?v=<?= e(APP_VERSION) ?>">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        :root {
            --md-primary: #2563EB;
            --md-primary-dark: #1E3A5F;
            --md-on-primary: #FFFFFF;
            --md-primary-container: #DBEAFE;
            --md-on-primary-container: #1E3A5F;
            --md-surface: #F0F2F5;
            --md-on-surface: #191C20;
            --md-on-surface-variant: #43474E;
            --md-outline: #73777F;
            --md-outline-variant: #C3C7CF;
            --md-surface-container: #ECEEF0;
            --md-surface-container-lowest: #FFFFFF;
            --md-error-container: #FFDAD6;
            --md-on-error-container: #410002;
            --md-warning-container: #FFE0B2;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            background: var(--md-surface);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Inter', 'Roboto', system-ui, sans-serif;
            padding: 1rem;
        }

        .login-wrapper {
            width: 100%;
            max-width: 440px;
        }

        .login-card {
            background: var(--md-surface-container-lowest);
            border-radius: 28px;
            box-shadow: 0 2px 6px 2px rgba(0,0,0,0.08), 0 1px 2px rgba(0,0,0,0.12);
            overflow: hidden;
            transition: box-shadow 0.3s cubic-bezier(0.2, 0, 0, 1);
        }

        .login-card:hover {
            box-shadow: 0 6px 16px 4px rgba(0,0,0,0.1), 0 2px 4px rgba(0,0,0,0.15);
        }

        /* ── Header avec motif ── */
        .login-header {
            background: linear-gradient(135deg, var(--md-primary) 0%, var(--md-primary-dark) 100%);
            color: var(--md-on-primary);
            padding: 2.75rem 2rem 2.25rem;
            text-align: center;
            position: relative;
            overflow: hidden;
        }

        .login-header::before {
            content: '';
            position: absolute;
            top: -40%;
            right: -20%;
            width: 260px;
            height: 260px;
            background: radial-gradient(circle, rgba(255,255,255,0.07) 0%, transparent 70%);
            border-radius: 50%;
        }

        .login-header::after {
            content: '';
            position: absolute;
            bottom: -30%;
            left: -15%;
            width: 200px;
            height: 200px;
            background: radial-gradient(circle, rgba(255,255,255,0.05) 0%, transparent 70%);
            border-radius: 50%;
        }

        .login-icon {
            width: 72px;
            height: 72px;
            background: rgba(255,255,255,0.15);
            border-radius: 20px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 1rem;
            backdrop-filter: blur(4px);
            position: relative;
            z-index: 1;
        }

        .login-icon i {
            font-size: 2rem;
            color: var(--md-on-primary);
        }

        .login-header h1 {
            font-size: 1.5rem;
            font-weight: 600;
            margin: 0;
            letter-spacing: -0.01em;
            position: relative;
            z-index: 1;
        }

        .login-header p {
            margin: 0.5rem 0 0;
            opacity: 0.8;
            font-size: 0.875rem;
            font-weight: 300;
            letter-spacing: 0.02em;
            position: relative;
            z-index: 1;
        }

        /* ── Body ── */
        .login-body {
            padding: 2rem 2rem 1.5rem;
        }

        .form-label {
            font-weight: 500;
            font-size: 0.8125rem;
            color: var(--md-on-surface-variant);
            margin-bottom: 0.375rem;
        }

        .md-input-group {
            position: relative;
            display: flex;
            align-items: stretch;
            border: 1.5px solid var(--md-outline-variant);
            border-radius: 12px;
            background: var(--md-surface-container-lowest);
            transition: border-color 0.2s cubic-bezier(0.2,0,0,1), box-shadow 0.2s cubic-bezier(0.2,0,0,1);
            overflow: hidden;
        }

        .md-input-group:focus-within {
            border-color: var(--md-primary);
            box-shadow: 0 0 0 1px var(--md-primary);
        }

        .md-input-group .input-icon {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 48px;
            flex-shrink: 0;
            color: var(--md-on-surface-variant);
            font-size: 1.1rem;
            transition: color 0.2s;
        }

        .md-input-group:focus-within .input-icon {
            color: var(--md-primary);
        }

        .md-input-group .form-control {
            border: none !important;
            box-shadow: none !important;
            padding: 0.75rem 0.75rem 0.75rem 0;
            font-size: 0.9375rem;
            background: transparent;
            flex: 1;
        }

        .md-input-group .form-control::placeholder {
            color: var(--md-outline);
        }

        /* ── Button ── */
        .btn-login {
            background: var(--md-primary);
            border: none;
            padding: 0.875rem;
            font-weight: 500;
            font-size: 0.9375rem;
            border-radius: 9999px;
            color: var(--md-on-primary);
            letter-spacing: 0.02em;
            box-shadow: 0 1px 3px rgba(21,101,192,0.3), 0 2px 8px rgba(21,101,192,0.15);
            transition: box-shadow 0.25s cubic-bezier(0.2,0,0,1), transform 0.15s;
            position: relative;
            overflow: hidden;
        }

        .btn-login:hover {
            box-shadow: 0 2px 6px rgba(21,101,192,0.4), 0 4px 16px rgba(21,101,192,0.2);
            transform: translateY(-1px);
        }

        .btn-login:active {
            transform: translateY(0);
            box-shadow: 0 1px 2px rgba(21,101,192,0.3);
        }

        /* ── Footer ── */
        .login-footer {
            text-align: center;
            padding: 0 2rem 1.75rem;
            color: var(--md-on-surface-variant);
            font-size: 0.8rem;
        }

        .login-footer a {
            color: var(--md-primary);
            text-decoration: none;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            padding: 0.375rem 0.75rem;
            border-radius: 9999px;
            transition: background 0.2s;
        }

        .login-footer a:hover {
            background: var(--md-primary-container);
        }

        .login-version {
            margin-top: 0.75rem;
            font-size: 0.75rem;
            color: var(--md-outline);
        }

        /* ── Alert ── */
        .md-alert {
            display: flex;
            align-items: center;
            gap: 0.625rem;
            padding: 0.75rem 1rem;
            border-radius: 12px;
            font-size: 0.85rem;
            font-weight: 400;
            margin-bottom: 1rem;
            border: none;
        }

        .md-alert-error {
            background: var(--md-error-container);
            color: var(--md-on-error-container);
        }

        .md-alert-warning {
            background: var(--md-warning-container);
            color: #BF360C;
        }

        /* ── Divider ── */
        .login-divider {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin: 1.25rem 0;
            color: var(--md-outline);
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.06em;
        }

        .login-divider::before,
        .login-divider::after {
            content: '';
            flex: 1;
            height: 1px;
            background: var(--md-outline-variant);
        }
    </style>
</head>
<body>
    <div class="login-wrapper">
        <div class="login-card">
            <div class="login-header">
                <div class="login-icon">
                    <i class="bi bi-bar-chart-line-fill"></i>
                </div>
                <h1><?= e(APP_NAME) ?></h1>
                <p>Expert-comptable virtuel</p>
            </div>

            <div class="login-body">
                <?php if ($expired): ?>
                    <div class="md-alert md-alert-warning">
                        <i class="bi bi-clock-history"></i> Session expirée. Veuillez vous reconnecter.
                    </div>
                <?php endif; ?>

                <?php if ($error): ?>
                    <div class="md-alert md-alert-error">
                        <i class="bi bi-exclamation-triangle-fill"></i> <?= e($error) ?>
                    </div>
                <?php endif; ?>

                <form method="POST" autocomplete="on">
                    <?= csrfField() ?>

                    <div class="mb-3">
                        <label class="form-label" for="email">Adresse email</label>
                        <div class="md-input-group">
                            <span class="input-icon"><i class="bi bi-envelope"></i></span>
                            <input type="email" class="form-control" id="email" name="email"
                                   value="<?= e($_POST['email'] ?? '') ?>"
                                   placeholder="nom@exemple.com" required autofocus>
                        </div>
                    </div>

                    <div class="mb-4">
                        <label class="form-label" for="password">Mot de passe</label>
                        <div class="md-input-group">
                            <span class="input-icon"><i class="bi bi-lock"></i></span>
                            <input type="password" class="form-control" id="password" name="password"
                                   placeholder="••••••••" required>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-login w-100">
                        Se connecter
                    </button>
                </form>
            </div>

            <div class="login-footer">
                <?php if ($inscriptionOuverte): ?>
                    <a href="<?= BASE_URL ?>register.php"><i class="bi bi-person-plus"></i> Créer un compte</a>
                <?php endif; ?>
                <div class="login-version"><?= e(APP_NAME) ?> v<?= e(APP_VERSION) ?></div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

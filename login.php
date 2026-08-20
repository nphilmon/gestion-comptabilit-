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
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet" integrity="sha384-XGjxtQfXaH2tnPFa9x+ruJTuLE3Aa6LhHSWRr1XeTyhezb4abCG4ccI5AkVDxqC+" crossorigin="anonymous">
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
            font-family: 'Inter', 'Roboto', system-ui, sans-serif;
        }

        .login-page {
            display: flex;
            width: 100%;
            min-height: 100vh;
        }

        /* ── Panneau de marque (gauche, masqué en mobile) ── */
        .login-visual {
            flex: 1 1 50%;
            display: flex;
            flex-direction: column;
            justify-content: center;
            padding: 3rem 3.5rem;
            background: linear-gradient(135deg, var(--md-primary) 0%, var(--md-primary-dark) 100%);
            color: var(--md-on-primary);
            position: relative;
            overflow: hidden;
        }

        .login-visual::before {
            content: '';
            position: absolute;
            top: -30%;
            right: -15%;
            width: 480px;
            height: 480px;
            background: radial-gradient(circle, rgba(255,255,255,0.08) 0%, transparent 70%);
            border-radius: 50%;
        }

        .login-visual::after {
            content: '';
            position: absolute;
            bottom: -25%;
            left: -10%;
            width: 380px;
            height: 380px;
            background: radial-gradient(circle, rgba(255,255,255,0.06) 0%, transparent 70%);
            border-radius: 50%;
        }

        .login-visual-content {
            position: relative;
            z-index: 1;
            max-width: 460px;
        }

        .login-icon {
            width: 64px;
            height: 64px;
            background: rgba(255,255,255,0.15);
            border-radius: 18px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 1.75rem;
            backdrop-filter: blur(4px);
        }

        .login-icon i {
            font-size: 1.75rem;
            color: var(--md-on-primary);
        }

        .login-visual h1 {
            font-size: 2rem;
            font-weight: 700;
            letter-spacing: -0.02em;
            font-family: 'Inter', system-ui, sans-serif;
            margin-bottom: 0.75rem;
        }

        .login-visual p.lead {
            font-size: 1rem;
            font-weight: 300;
            opacity: 0.85;
            line-height: 1.6;
            margin-bottom: 2.5rem;
        }

        .login-features {
            list-style: none;
            display: flex;
            flex-direction: column;
            gap: 1.1rem;
        }

        .login-features li {
            display: flex;
            align-items: flex-start;
            gap: 0.75rem;
            font-size: 0.9rem;
            opacity: 0.95;
        }

        .login-features li i {
            flex-shrink: 0;
            width: 28px;
            height: 28px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: rgba(255,255,255,0.12);
            border-radius: 8px;
            font-size: 0.9rem;
            margin-top: 0.05rem;
        }

        /* ── Panneau formulaire (droite) ── */
        .login-form-side {
            flex: 1 1 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem;
            background: var(--md-surface-container-lowest);
        }

        .login-wrapper {
            width: 100%;
            max-width: 400px;
        }

        .login-form-top {
            text-align: center;
            margin-bottom: 1.75rem;
        }

        .login-form-top h2 {
            font-size: 1.375rem;
            font-weight: 700;
            color: var(--md-on-surface);
            letter-spacing: -0.01em;
        }

        .login-form-top p {
            margin-top: 0.375rem;
            font-size: 0.875rem;
            color: var(--md-on-surface-variant);
        }

        @media (max-width: 900px) {
            .login-visual { display: none; }
            .login-form-side { padding: 1.5rem; }
        }

        /* ── Body (contenu formulaire) ── */
        .login-body {
            padding: 0;
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
            padding-top: 1.5rem;
            margin-top: 1.5rem;
            border-top: 1px solid var(--md-outline-variant);
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
    <main class="login-page">
        <div class="login-visual">
            <div class="login-visual-content">
                <div class="login-icon">
                    <i class="bi bi-bar-chart-line-fill"></i>
                </div>
                <h1><?= e(APP_NAME) ?></h1>
                <p class="lead">Votre expert-comptable virtuel : suivi financier, paie et conformité légale réunis dans une seule application.</p>
                <ul class="login-features">
                    <li><i class="bi bi-shield-check"></i> Conforme au Code du travail et aux obligations comptables françaises</li>
                    <li><i class="bi bi-graph-up-arrow"></i> Suivi en temps réel de votre trésorerie et de vos recettes</li>
                    <li><i class="bi bi-file-earmark-richtext"></i> Devis, factures et bulletins de paie générés automatiquement</li>
                </ul>
            </div>
        </div>

        <div class="login-form-side">
            <div class="login-wrapper">
                <div class="login-form-top">
                    <h2>Connexion</h2>
                    <p>Accédez à votre espace de gestion</p>
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
                            <div class="d-flex justify-content-between align-items-center">
                                <label class="form-label mb-0" for="password">Mot de passe</label>
                                <a href="<?= BASE_URL ?>mot_de_passe_oublie.php" class="form-label mb-0 link-plain-primary">Mot de passe oublié ?</a>
                            </div>
                            <div class="md-input-group mt-1">
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
                    <div class="login-version">
                        <?= e(APP_NAME) ?> v<?= e(APP_VERSION) ?>
                        · <a href="<?= BASE_URL ?>mentions-legales.php" class="link-plain-primary">Mentions légales</a>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
</body>
</html>

<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';
sendSecurityHeaders();

// Déjà connecté ? Rediriger
if (isLoggedIn()) {
    header('Location: ' . BASE_URL);
    exit;
}

$token = trim($_POST['token'] ?? $_GET['token'] ?? '');
$user = getUserByResetToken($token);
$error = '';
$done = false;

if ($token === '' || !$user) {
    $error = 'Ce lien de réinitialisation est invalide ou a expiré.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        $error = 'Jeton de sécurité invalide. Veuillez réessayer.';
    } else {
        $password = $_POST['password'] ?? '';
        $password2 = $_POST['password_confirm'] ?? '';

        if (strlen($password) < 8) {
            $error = 'Le mot de passe doit contenir au moins 8 caractères.';
        } elseif ($password !== $password2) {
            $error = 'Les mots de passe ne correspondent pas.';
        } elseif (resetPasswordWithToken($token, $password)) {
            $done = true;
        } else {
            $error = 'Ce lien de réinitialisation est invalide ou a expiré.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Réinitialiser le mot de passe - <?= APP_NAME ?></title>
    <link rel="icon" type="image/svg+xml" href="<?= BASE_URL ?>assets/favicon.svg?v=<?= e(APP_VERSION) ?>">
    <link rel="shortcut icon" type="image/svg+xml" href="<?= BASE_URL ?>assets/favicon.svg?v=<?= e(APP_VERSION) ?>">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
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
            --md-success-container: #DFF5E1;
            --md-on-success-container: #0F5132;
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
        }
        .card-wrapper {
            background: var(--md-surface-container-lowest);
            border-radius: 28px;
            box-shadow: var(--md-elevation-3);
            max-width: 420px;
            width: 100%;
            overflow: hidden;
            margin: 1.5rem;
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
        .md-input-group .form-control { border: none !important; box-shadow: none !important; padding: 0.75rem 0.75rem 0.75rem 0; font-size: 0.9375rem; background: transparent; flex: 1; }
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
        .card-footer-links { text-align: center; padding: 0 2rem 1.75rem; color: var(--md-on-surface-variant); font-size: 0.85rem; }
        .card-footer-links a { color: var(--md-primary); text-decoration: none; font-weight: 500; }
        .card-footer-links a:hover { text-decoration: underline; }
        .md-alert { display: flex; align-items: center; gap: 0.625rem; padding: 0.75rem 1rem; border-radius: 12px; font-size: 0.85rem; margin-bottom: 1rem; border: none; }
        .md-alert-error { background: var(--md-error-container); color: var(--md-on-error-container); }
        .md-alert-success { background: var(--md-success-container); color: var(--md-on-success-container); }
        .password-strength { height: 4px; border-radius: 2px; margin-top: 4px; transition: all 0.3s; }
    </style>
</head>
<body>
    <div class="card-wrapper">
        <div class="card-header">
            <i class="bi bi-shield-lock"></i>
            <h1>Nouveau mot de passe</h1>
            <p><?= e(APP_NAME) ?></p>
        </div>
        <div class="card-body">
            <?php if ($done): ?>
                <div class="md-alert md-alert-success">
                    <i class="bi bi-check-circle-fill"></i>
                    <div>Votre mot de passe a été réinitialisé avec succès.</div>
                </div>
            <?php elseif (!$user): ?>
                <div class="md-alert md-alert-error">
                    <i class="bi bi-exclamation-triangle-fill"></i> <?= e($error) ?>
                </div>
            <?php else: ?>
                <?php if ($error): ?>
                    <div class="md-alert md-alert-error">
                        <i class="bi bi-exclamation-triangle-fill"></i> <?= e($error) ?>
                    </div>
                <?php endif; ?>

                <form method="POST" autocomplete="off">
                    <?= csrfField() ?>
                    <input type="hidden" name="token" value="<?= e($token) ?>">

                    <div class="mb-3">
                        <label class="form-label" for="password">Nouveau mot de passe</label>
                        <div class="md-input-group">
                            <span class="input-icon"><i class="bi bi-lock"></i></span>
                            <input type="password" class="form-control" id="password" name="password"
                                   placeholder="8 caractères minimum" required minlength="8" autofocus>
                        </div>
                        <div class="password-strength" id="pwStrength"></div>
                    </div>

                    <div class="mb-4">
                        <label class="form-label" for="password_confirm">Confirmer le mot de passe</label>
                        <div class="md-input-group">
                            <span class="input-icon"><i class="bi bi-lock-fill"></i></span>
                            <input type="password" class="form-control" id="password_confirm" name="password_confirm"
                                   placeholder="Retapez votre mot de passe" required minlength="8">
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary-pill w-100">
                        Réinitialiser le mot de passe
                    </button>
                </form>
            <?php endif; ?>
        </div>
        <div class="card-footer-links">
            <?php if ($done): ?>
                <a href="<?= BASE_URL ?>login.php"><i class="bi bi-box-arrow-in-right"></i> Se connecter</a>
            <?php else: ?>
                <a href="<?= BASE_URL ?>login.php"><i class="bi bi-arrow-left"></i> Retour à la connexion</a>
            <?php endif; ?>
        </div>
    </div>
    <script>
    const pwInput = document.getElementById('password');
    if (pwInput) {
        pwInput.addEventListener('input', function() {
            const bar = document.getElementById('pwStrength');
            const v = this.value;
            let score = 0;
            if (v.length >= 8) score++;
            if (v.length >= 12) score++;
            if (/[A-Z]/.test(v) && /[a-z]/.test(v)) score++;
            if (/\d/.test(v)) score++;
            if (/[^A-Za-z0-9]/.test(v)) score++;
            const colors = ['#e53e3e', '#e53e3e', '#ed8936', '#ecc94b', '#38a169', '#276749'];
            bar.style.width = Math.min(100, score * 20) + '%';
            bar.style.background = colors[score] || '#e53e3e';
        });
    }
    </script>
</body>
</html>

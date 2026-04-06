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
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        :root {
            --bleu-primary: #1e3a5f;
            --bleu-secondary: #2c5282;
            --bleu-accent: #3182ce;
            --bleu-light: #ebf4ff;
        }
        body {
            background: linear-gradient(135deg, var(--bleu-primary) 0%, var(--bleu-secondary) 50%, var(--bleu-accent) 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
        }
        .login-card {
            background: #fff;
            border-radius: 1rem;
            box-shadow: 0 15px 50px rgba(0,0,0,0.3);
            max-width: 420px;
            width: 100%;
            overflow: hidden;
        }
        .login-header {
            background: linear-gradient(135deg, var(--bleu-primary), var(--bleu-secondary));
            color: white;
            padding: 2rem;
            text-align: center;
        }
        .login-header i {
            font-size: 3rem;
            margin-bottom: 0.5rem;
        }
        
        .login-header h1 {
            font-size: 1.4rem;
            font-weight: 700;
            margin: 0;
        }
        .login-header p {
            margin: 0.3rem 0 0;
            opacity: 0.8;
            font-size: 0.9rem;
        }
        .login-body {
            padding: 2rem;
        }
        .form-control:focus {
            border-color: var(--bleu-accent);
            box-shadow: 0 0 0 0.2rem rgba(49, 130, 206, 0.25);
        }
        .btn-login {
            background: linear-gradient(135deg, var(--bleu-primary), var(--bleu-accent));
            border: none;
            padding: 0.7rem;
            font-weight: 600;
            font-size: 1rem;
            border-radius: 0.5rem;
            transition: opacity 0.2s;
        }
        .btn-login:hover {
            opacity: 0.9;
            background: linear-gradient(135deg, var(--bleu-primary), var(--bleu-accent));
        }
        .input-group-text {
            background: var(--bleu-light);
            border-right: none;
            color: var(--bleu-primary);
        }
        .input-group .form-control {
            border-left: none;
        }
        .login-footer {
            text-align: center;
            padding: 0 2rem 1.5rem;
            color: #718096;
            font-size: 0.8rem;
        }
        .login-footer a { color: var(--bleu-accent); text-decoration: none; font-weight: 600; }
        .login-footer a:hover { text-decoration: underline; }
    </style>
</head>
<body>
    <div class="login-card">
        <div class="login-header">
            <i class="bi bi-calculator-fill"></i>
            <h1><?= e(APP_NAME) ?></h1>
            <p>Expert-comptable virtuel</p>
        </div>

        <div class="login-body">
            <?php if ($expired): ?>
                <div class="alert alert-warning py-2">
                    <i class="bi bi-clock-history"></i> Session expirée. Veuillez vous reconnecter.
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="alert alert-danger py-2">
                    <i class="bi bi-exclamation-triangle-fill"></i> <?= e($error) ?>
                </div>
            <?php endif; ?>

            <form method="POST" autocomplete="on">
                <?= csrfField() ?>

                <div class="mb-3">
                    <label class="form-label fw-semibold" for="email">Adresse email</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-envelope"></i></span>
                        <input type="email" class="form-control" id="email" name="email"
                               value="<?= e($_POST['email'] ?? '') ?>"
                               placeholder="nom@exemple.com" required autofocus>
                    </div>
                </div>

                <div class="mb-4">
                    <label class="form-label fw-semibold" for="password">Mot de passe</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-lock"></i></span>
                        <input type="password" class="form-control" id="password" name="password"
                               placeholder="••••••••" required>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary btn-login w-100">
                    <i class="bi bi-box-arrow-in-right"></i> Se connecter
                </button>
            </form>
        </div>

        <div class="login-footer">
            <?php if ($inscriptionOuverte): ?>
                <div class="mb-2"><a href="<?= BASE_URL ?>register.php"><i class="bi bi-person-plus"></i> Créer un compte</a></div>
            <?php endif; ?>
            <?= e(APP_NAME) ?> v<?= e(APP_VERSION) ?>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

<?php
/**
 * Inscription - Créer un nouveau compte
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';
sendSecurityHeaders();

$registrationClosed = !isRegistrationOpen();

// Déjà connecté ?
if (isLoggedIn()) {
    header('Location: ' . BASE_URL);
    exit;
}

$errors = [];
$nom = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($registrationClosed) {
        $errors[] = 'Les inscriptions sont actuellement fermées.';
    } elseif (!verifyCsrf()) {
        $errors[] = 'Erreur de sécurité. Veuillez réessayer.';
    } else {
        $nom = trim($_POST['nom'] ?? '');
        $email = mb_strtolower(trim($_POST['email'] ?? ''));
        $password = $_POST['password'] ?? '';
        $password2 = $_POST['password_confirm'] ?? '';

        if ($nom === '') $errors[] = 'Le nom est obligatoire.';
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Adresse email invalide.';
        if (strlen($password) < 8) $errors[] = 'Le mot de passe doit contenir au moins 8 caractères.';
        if ($password !== $password2) $errors[] = 'Les mots de passe ne correspondent pas.';

        if (empty($errors)) {
            $db = getDB();
            $stmt = $db->prepare('SELECT id FROM users WHERE email = ?');
            $stmt->execute([$email]);
            if ($stmt->fetch()) {
                $errors[] = 'Cette adresse email est déjà utilisée.';
            }
        }

        if (empty($errors)) {
            $db = getDB();
            $hash = password_hash($password, PASSWORD_BCRYPT);
            $role = 'comptable'; // rôle par défaut pour les inscriptions
            $stmt = $db->prepare('INSERT INTO users (nom, email, password_hash, role, actif) VALUES (?, ?, ?, ?, 1)');
            $stmt->execute([$nom, $email, $hash, $role]);

            logActivity((int) $db->lastInsertId(), 'inscription', 'Nouveau compte créé');

            // Connexion automatique, puis configuration obligatoire de la 2FA
            attemptLogin($email, $password);

            header('Location: ' . BASE_URL . 'configurer_2fa.php');
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inscription - <?= APP_NAME ?></title>
    <link rel="icon" type="image/svg+xml" href="<?= BASE_URL ?>assets/favicon.svg?v=<?= e(APP_VERSION) ?>">
    <link rel="shortcut icon" type="image/svg+xml" href="<?= BASE_URL ?>assets/favicon.svg?v=<?= e(APP_VERSION) ?>">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet" integrity="sha384-XGjxtQfXaH2tnPFa9x+ruJTuLE3Aa6LhHSWRr1XeTyhezb4abCG4ccI5AkVDxqC+" crossorigin="anonymous">
    <style>
        :root {
            --md-primary: #2563EB;
            --md-on-primary: #FFFFFF;
            --md-primary-container: #DBEAFE;
            --md-on-primary-container: #1E3A5F;
            --md-surface: #FAFAFA;
            --md-on-surface: #191C20;
            --md-on-surface-variant: #43474E;
            --md-outline: #73777F;
            --md-surface-container: #ECEEF0;
            --md-surface-container-lowest: #FFFFFF;
            --md-error-container: #FFDAD6;
            --md-on-error-container: #410002;
            --md-warning-container: #FFE0B2;
            --md-elevation-2: 0 1px 2px rgba(0,0,0,0.3), 0 2px 6px 2px rgba(0,0,0,0.15);
            --md-elevation-3: 0 4px 8px 3px rgba(0,0,0,0.15), 0 1px 3px rgba(0,0,0,0.3);
        }
        body {
            background: var(--md-surface);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Inter', 'Roboto', system-ui, -apple-system, sans-serif;
        }
        .register-card {
            background: var(--md-surface-container-lowest);
            border-radius: 28px;
            box-shadow: var(--md-elevation-3);
            max-width: 460px;
            width: 100%;
            overflow: hidden;
        }
        .register-header {
            background: var(--md-primary);
            color: var(--md-on-primary);
            padding: 2.5rem 2rem 2rem;
            text-align: center;
        }
        .register-header .material-symbols-outlined {
            font-size: 48px;
            margin-bottom: 0.5rem;
            font-variation-settings: 'FILL' 1;
        }
        .register-header h1 { font-size: 1.4rem; font-weight: 600; margin: 0; }
        .register-header p { margin: 0.3rem 0 0; opacity: 0.85; font-size: 0.875rem; font-weight: 300; }
        .register-body { padding: 2rem; }
        .form-control {
            border: 1px solid var(--md-outline);
            border-radius: 4px;
            font-size: 0.875rem;
            padding: 0.625rem 0.875rem;
            transition: border-color 0.2s, box-shadow 0.2s;
        }
        .form-control:focus {
            border-color: var(--md-primary);
            box-shadow: 0 0 0 1px var(--md-primary);
        }
        .btn-register {
            background: var(--md-primary);
            border: none;
            padding: 0.75rem;
            font-weight: 500;
            font-size: 0.875rem;
            border-radius: 9999px;
            transition: box-shadow 0.2s;
            box-shadow: var(--md-elevation-2);
        }
        .btn-register:hover { box-shadow: var(--md-elevation-3); }
        .input-group-text {
            background: var(--md-surface-container);
            border: 1px solid var(--md-outline);
            color: var(--md-on-surface-variant);
        }
        .input-group .form-control { border-left: none; }
        .register-footer {
            text-align: center;
            padding: 0 2rem 1.5rem;
            color: var(--md-on-surface-variant);
            font-size: 0.85rem;
        }
        .register-footer a { color: var(--md-primary); text-decoration: none; font-weight: 500; }
        .register-footer a:hover { text-decoration: underline; }
        .password-strength { height: 4px; border-radius: 2px; margin-top: 4px; transition: all 0.3s; }
    </style>
</head>
<body>
    <main class="register-card">
        <div class="register-header">
            <span class="material-symbols-outlined">person_add</span>
            <h1>Créer un compte</h1>
            <p><?= e(APP_NAME) ?></p>
        </div>

        <div class="register-body">
            <?php if ($registrationClosed): ?>
                <div class="alert py-2 alert-warning-soft">
                    <i class="bi bi-shield-lock"></i> Les inscriptions publiques sont désactivées. Contactez l'administrateur.
                </div>
            <?php endif; ?>

            <?php if (!empty($errors)): ?>
                <div class="alert py-2 alert-error-soft">
                    <i class="bi bi-exclamation-triangle-fill"></i>
                    <?php foreach ($errors as $err): ?>
                        <div><?= e($err) ?></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <form method="POST" autocomplete="on" novalidate>
                <?= csrfField() ?>

                <div class="mb-3">
                    <label class="form-label fw-semibold" for="nom">Nom complet</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-person"></i></span>
                        <input type="text" class="form-control" id="nom" name="nom"
                               value="<?= e($nom) ?>" placeholder="Jean Dupont" required autofocus>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-semibold" for="email">Adresse email</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-envelope"></i></span>
                        <input type="email" class="form-control" id="email" name="email"
                               value="<?= e($email) ?>" placeholder="nom@exemple.com" required>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-semibold" for="password">Mot de passe</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-lock"></i></span>
                        <input type="password" class="form-control" id="password" name="password"
                               placeholder="8 caractères minimum" required minlength="8">
                    </div>
                    <div class="password-strength" id="pwStrength"></div>
                </div>

                <div class="mb-4">
                    <label class="form-label fw-semibold" for="password_confirm">Confirmer le mot de passe</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-lock-fill"></i></span>
                        <input type="password" class="form-control" id="password_confirm" name="password_confirm"
                               placeholder="Retapez votre mot de passe" required>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary btn-register w-100" <?= $registrationClosed ? 'disabled' : '' ?>>
                    <i class="bi bi-person-check"></i> Créer mon compte
                </button>
            </form>
        </div>

        <div class="register-footer">
            Déjà un compte ? <a href="<?= BASE_URL ?>login.php">Se connecter</a>
        </div>
    </main>

    <script>
    document.getElementById('password').addEventListener('input', function() {
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
    </script>

    <?php include __DIR__ . '/cookie_notice.php'; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
</body>
</html>

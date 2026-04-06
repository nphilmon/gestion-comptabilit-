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

            // Connexion automatique
            attemptLogin($email, $password);

            header('Location: ' . BASE_URL);
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
        .register-card {
            background: #fff;
            border-radius: 1rem;
            box-shadow: 0 15px 50px rgba(0,0,0,0.3);
            max-width: 460px;
            width: 100%;
            overflow: hidden;
        }
        .register-header {
            background: linear-gradient(135deg, var(--bleu-primary), var(--bleu-secondary));
            color: white;
            padding: 2rem;
            text-align: center;
        }
        .register-header i { font-size: 3rem; margin-bottom: 0.5rem; }
        .register-header h1 { font-size: 1.4rem; font-weight: 700; margin: 0; }
        .register-header p { margin: 0.3rem 0 0; opacity: 0.8; font-size: 0.9rem; }
        .register-body { padding: 2rem; }
        .form-control:focus {
            border-color: var(--bleu-accent);
            box-shadow: 0 0 0 0.2rem rgba(49, 130, 206, 0.25);
        }
        .btn-register {
            background: linear-gradient(135deg, var(--bleu-primary), var(--bleu-accent));
            border: none;
            padding: 0.7rem;
            font-weight: 600;
            font-size: 1rem;
            border-radius: 0.5rem;
            transition: opacity 0.2s;
        }
        .btn-register:hover { opacity: 0.9; }
        .input-group-text {
            background: var(--bleu-light);
            border-right: none;
            color: var(--bleu-primary);
        }
        .input-group .form-control { border-left: none; }
        .register-footer {
            text-align: center;
            padding: 0 2rem 1.5rem;
            color: #718096;
            font-size: 0.85rem;
        }
        .register-footer a { color: var(--bleu-accent); text-decoration: none; font-weight: 600; }
        .register-footer a:hover { text-decoration: underline; }
        .password-strength { height: 4px; border-radius: 2px; margin-top: 4px; transition: all 0.3s; }
    </style>
</head>
<body>
    <div class="register-card">
        <div class="register-header">
            <i class="bi bi-person-plus-fill"></i>
            <h1>Créer un compte</h1>
            <p><?= e(APP_NAME) ?></p>
        </div>

        <div class="register-body">
            <?php if ($registrationClosed): ?>
                <div class="alert alert-warning py-2">
                    <i class="bi bi-shield-lock"></i> Les inscriptions publiques sont désactivées. Contactez l'administrateur.
                </div>
            <?php endif; ?>

            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger py-2">
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
    </div>

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
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

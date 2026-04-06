<?php
/**
 * Setup initial — Assistant de configuration
 * S'exécute uniquement s'il n'y a aucun utilisateur en base
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';
sendSecurityHeaders();

$db = getDB();

// Vérifier si le setup est nécessaire
$nbUsers = (int) $db->query('SELECT COUNT(*) FROM users')->fetchColumn();
if ($nbUsers > 0 && !isLoggedIn()) {
    header('Location: ' . BASE_URL . 'login.php');
    exit;
}
if ($nbUsers > 0 && isLoggedIn()) {
    $user = getCurrentUser();
    if (!$user || $user['role'] !== 'admin') {
        header('Location: ' . BASE_URL);
        exit;
    }
}

$step = max(1, min(3, (int)($_GET['step'] ?? 1)));
$errors = [];
$success = false;

// Pré-remplir les paramètres existants
$params = [
    'nom_entreprise' => getParam('nom_entreprise', ''),
    'siret' => getParam('siret', ''),
    'adresse' => getParam('adresse', ''),
    'code_postal' => getParam('code_postal', ''),
    'ville' => getParam('ville', ''),
    'telephone' => getParam('telephone', ''),
    'email_entreprise' => getParam('email_entreprise', ''),
    'forme_juridique' => getParam('forme_juridique', 'auto-entrepreneur'),
    'regime' => getParam('regime', 'micro-bnc'),
    'regime_imposition' => getParam('regime_imposition', 'ir'),
    'tva_active' => getParam('tva_active', '0'),
    'inscription_ouverte' => getParam('inscription_ouverte', '0'),
];

// --- Traitement POST ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        $errors[] = 'Erreur de sécurité.';
    } else {
        $postStep = (int) ($_POST['step'] ?? 1);

        // Étape 1 : Créer le compte administrateur
        if ($postStep === 1 && $nbUsers === 0) {
            $nom = trim($_POST['nom'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $pw = $_POST['password'] ?? '';
            $pw2 = $_POST['password_confirm'] ?? '';

            if ($nom === '') $errors[] = 'Le nom est obligatoire.';
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Adresse email invalide.';
            if (strlen($pw) < 6) $errors[] = 'Le mot de passe doit contenir au moins 6 caractères.';
            if ($pw !== $pw2) $errors[] = 'Les mots de passe ne correspondent pas.';

            if (empty($errors)) {
                $hash = password_hash($pw, PASSWORD_BCRYPT);
                $stmt = $db->prepare('INSERT INTO users (nom, email, password_hash, role, actif) VALUES (?, ?, ?, ?, 1)');
                $stmt->execute([$nom, $email, $hash, 'admin']);
                logActivity((int) $db->lastInsertId(), 'setup', 'Compte administrateur créé');

                attemptLogin($email, $pw);
                header('Location: ' . BASE_URL . 'setup.php?step=2');
                exit;
            }
        }

        // Étape 2 : Informations de l'entreprise
        if ($postStep === 2) {
            $fields = [
                'nom_entreprise', 'siret', 'adresse', 'code_postal', 'ville',
                'telephone', 'email_entreprise'
            ];
            foreach ($fields as $f) {
                $val = trim($_POST[$f] ?? '');
                setParam($f, $val);
                $params[$f] = $val;
            }

            if (trim($_POST['nom_entreprise'] ?? '') === '') {
                $errors[] = "Le nom de l'entreprise est obligatoire.";
            }

            if (empty($errors)) {
                logActivity($_SESSION['user_id'] ?? 0, 'setup', 'Informations entreprise configurées');
                header('Location: ' . BASE_URL . 'setup.php?step=3');
                exit;
            }
        }

        // Étape 3 : Régime fiscal + options
        if ($postStep === 3) {
            $forme = $_POST['forme_juridique'] ?? 'auto-entrepreneur';
            $regime = $_POST['regime'] ?? 'micro-bnc';
            $imposition = $_POST['regime_imposition'] ?? 'ir';
            $tva = isset($_POST['tva_active']) ? '1' : '0';
            $inscription = isset($_POST['inscription_ouverte']) ? '1' : '0';

            setParam('forme_juridique', $forme);
            setParam('regime', $regime);
            setParam('regime_imposition', $imposition);
            setParam('tva_active', $tva);
            setParam('inscription_ouverte', $inscription);

            logActivity($_SESSION['user_id'] ?? 0, 'setup', 'Configuration fiscale terminée');
            $success = true;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Configuration initiale - <?= APP_NAME ?></title>
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
            background: #f0f4f8;
            min-height: 100vh;
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
        }
        .setup-container { max-width: 680px; margin: 2rem auto; padding: 0 1rem; }
        .setup-header {
            background: linear-gradient(135deg, var(--bleu-primary), var(--bleu-secondary));
            color: white;
            border-radius: 1rem 1rem 0 0;
            padding: 2rem;
            text-align: center;
        }
        .setup-header i { font-size: 2.5rem; }
        .setup-header h1 { font-size: 1.5rem; font-weight: 700; margin: 0.5rem 0 0; }
        .setup-card {
            background: #fff;
            border-radius: 0 0 1rem 1rem;
            box-shadow: 0 10px 40px rgba(0,0,0,0.1);
            padding: 2rem;
        }
        .step-indicator {
            display: flex;
            justify-content: center;
            gap: 0;
            margin-bottom: 2rem;
        }
        .step-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 1rem;
            font-size: 0.85rem;
            color: #a0aec0;
            position: relative;
        }
        .step-item.active { color: var(--bleu-primary); font-weight: 700; }
        .step-item.done { color: #38a169; }
        .step-num {
            width: 28px; height: 28px;
            border-radius: 50%;
            background: #e2e8f0;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 0.8rem;
        }
        .step-item.active .step-num { background: var(--bleu-primary); color: #fff; }
        .step-item.done .step-num { background: #38a169; color: #fff; }
        .step-connector { width: 40px; height: 2px; background: #e2e8f0; align-self: center; }
        .step-connector.done { background: #38a169; }
        .form-control:focus, .form-select:focus {
            border-color: var(--bleu-accent);
            box-shadow: 0 0 0 0.2rem rgba(49, 130, 206, 0.2);
        }
        .btn-setup {
            background: linear-gradient(135deg, var(--bleu-primary), var(--bleu-accent));
            border: none;
            font-weight: 600;
        }
        .btn-setup:hover { opacity: 0.9; }
        .success-screen { text-align: center; padding: 2rem 0; }
        .success-screen i { font-size: 4rem; color: #38a169; }
        .input-group-text { background: var(--bleu-light); color: var(--bleu-primary); }
    </style>
</head>
<body>
    <div class="setup-container">
        <div class="setup-header">
            <i class="bi bi-gear-wide-connected"></i>
            <h1><?= $success ? 'Configuration terminée !' : 'Assistant de configuration' ?></h1>
            <p class="mb-0 opacity-75"><?= e(APP_NAME) ?> v<?= e(APP_VERSION) ?></p>
        </div>

        <div class="setup-card">
            <?php if ($success): ?>
                <!-- Écran de fin -->
                <div class="success-screen">
                    <i class="bi bi-check-circle-fill"></i>
                    <h2 class="mt-3 mb-2">Tout est prêt !</h2>
                    <p class="text-muted mb-4">Votre application est configurée et opérationnelle.</p>
                    <div class="d-flex flex-column gap-2 mx-auto" style="max-width: 300px;">
                        <a href="<?= BASE_URL ?>" class="btn btn-primary btn-setup btn-lg">
                            <i class="bi bi-speedometer2"></i> Accéder au tableau de bord
                        </a>
                        <a href="<?= BASE_URL ?>parametres.php" class="btn btn-outline-secondary">
                            <i class="bi bi-gear"></i> Paramètres avancés
                        </a>
                    </div>
                </div>
            <?php else: ?>
                <!-- Indicateur d'étapes -->
                <div class="step-indicator">
                    <div class="step-item <?= $step === 1 ? 'active' : ($step > 1 ? 'done' : '') ?>">
                        <span class="step-num"><?= $step > 1 ? '<i class="bi bi-check"></i>' : '1' ?></span>
                        <span class="d-none d-sm-inline">Compte admin</span>
                    </div>
                    <div class="step-connector <?= $step > 1 ? 'done' : '' ?>"></div>
                    <div class="step-item <?= $step === 2 ? 'active' : ($step > 2 ? 'done' : '') ?>">
                        <span class="step-num"><?= $step > 2 ? '<i class="bi bi-check"></i>' : '2' ?></span>
                        <span class="d-none d-sm-inline">Entreprise</span>
                    </div>
                    <div class="step-connector <?= $step > 2 ? 'done' : '' ?>"></div>
                    <div class="step-item <?= $step === 3 ? 'active' : '' ?>">
                        <span class="step-num">3</span>
                        <span class="d-none d-sm-inline">Régime fiscal</span>
                    </div>
                </div>

                <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger py-2">
                        <?php foreach ($errors as $err): ?>
                            <div><i class="bi bi-exclamation-triangle-fill"></i> <?= e($err) ?></div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <?php if ($step === 1): ?>
                    <!-- ÉTAPE 1 : Compte administrateur -->
                    <h5 class="mb-3"><i class="bi bi-person-badge"></i> Créer le compte administrateur</h5>
                    <p class="text-muted small mb-4">Ce compte aura tous les droits sur l'application.</p>

                    <form method="POST">
                        <?= csrfField() ?>
                        <input type="hidden" name="step" value="1">

                        <div class="mb-3">
                            <label class="form-label fw-semibold">Nom complet *</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-person"></i></span>
                                <input type="text" class="form-control" name="nom" required
                                       value="<?= e($_POST['nom'] ?? '') ?>" placeholder="Jean Dupont">
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-semibold">Adresse email *</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-envelope"></i></span>
                                <input type="email" class="form-control" name="email" required
                                       value="<?= e($_POST['email'] ?? '') ?>" placeholder="admin@exemple.com">
                            </div>
                        </div>

                        <div class="row g-3 mb-4">
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Mot de passe *</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="bi bi-lock"></i></span>
                                    <input type="password" class="form-control" name="password" required minlength="6"
                                           placeholder="6 caractères min.">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Confirmation *</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="bi bi-lock-fill"></i></span>
                                    <input type="password" class="form-control" name="password_confirm" required
                                           placeholder="Retapez le mot de passe">
                                </div>
                            </div>
                        </div>

                        <button type="submit" class="btn btn-primary btn-setup w-100">
                            Créer le compte et continuer <i class="bi bi-arrow-right"></i>
                        </button>
                    </form>

                <?php elseif ($step === 2): ?>
                    <!-- ÉTAPE 2 : Informations entreprise -->
                    <h5 class="mb-3"><i class="bi bi-building"></i> Informations de votre entreprise</h5>
                    <p class="text-muted small mb-4">Ces informations apparaîtront sur vos documents (factures, devis...).</p>

                    <form method="POST">
                        <?= csrfField() ?>
                        <input type="hidden" name="step" value="2">

                        <div class="mb-3">
                            <label class="form-label fw-semibold">Nom de l'entreprise *</label>
                            <input type="text" class="form-control" name="nom_entreprise" required
                                   value="<?= e($params['nom_entreprise']) ?>" placeholder="Mon Entreprise SARL">
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-semibold">SIRET</label>
                            <input type="text" class="form-control" name="siret" maxlength="20"
                                   value="<?= e($params['siret']) ?>" placeholder="000 000 000 00000">
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-semibold">Adresse</label>
                            <input type="text" class="form-control" name="adresse"
                                   value="<?= e($params['adresse']) ?>" placeholder="123 rue de la Paix">
                        </div>

                        <div class="row g-3 mb-3">
                            <div class="col-md-4">
                                <label class="form-label fw-semibold">Code postal</label>
                                <input type="text" class="form-control" name="code_postal"
                                       value="<?= e($params['code_postal']) ?>" placeholder="75001">
                            </div>
                            <div class="col-md-8">
                                <label class="form-label fw-semibold">Ville</label>
                                <input type="text" class="form-control" name="ville"
                                       value="<?= e($params['ville']) ?>" placeholder="Paris">
                            </div>
                        </div>

                        <div class="row g-3 mb-4">
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Téléphone</label>
                                <input type="tel" class="form-control" name="telephone"
                                       value="<?= e($params['telephone']) ?>" placeholder="01 23 45 67 89">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Email professionnel</label>
                                <input type="email" class="form-control" name="email_entreprise"
                                       value="<?= e($params['email_entreprise']) ?>" placeholder="contact@monentreprise.fr">
                            </div>
                        </div>

                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-primary btn-setup flex-grow-1">
                                Enregistrer et continuer <i class="bi bi-arrow-right"></i>
                            </button>
                            <a href="<?= BASE_URL ?>setup.php?step=3" class="btn btn-outline-secondary">Passer</a>
                        </div>
                    </form>

                <?php elseif ($step === 3): ?>
                    <!-- ÉTAPE 3 : Régime fiscal -->
                    <h5 class="mb-3"><i class="bi bi-bank"></i> Régime fiscal et options</h5>
                    <p class="text-muted small mb-4">Configurez votre statut juridique et fiscal. Modifiable dans Paramètres.</p>

                    <form method="POST">
                        <?= csrfField() ?>
                        <input type="hidden" name="step" value="3">

                        <div class="mb-3">
                            <label class="form-label fw-semibold">Forme juridique</label>
                            <select class="form-select" name="forme_juridique">
                                <?php
                                $formes = [
                                    'auto-entrepreneur' => 'Auto-entrepreneur / Micro-entreprise',
                                    'eirl' => 'EIRL',
                                    'eurl' => 'EURL',
                                    'sarl' => 'SARL',
                                    'sas' => 'SAS',
                                    'sasu' => 'SASU',
                                    'sa' => 'SA',
                                    'sci' => 'SCI',
                                    'association' => 'Association',
                                    'autre' => 'Autre',
                                ];
                                foreach ($formes as $val => $label):
                                ?>
                                <option value="<?= $val ?>" <?= $params['forme_juridique'] === $val ? 'selected' : '' ?>><?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-semibold">Régime fiscal</label>
                            <select class="form-select" name="regime">
                                <?php
                                $regimes = [
                                    'micro-bnc' => 'Micro-BNC',
                                    'micro-bic' => 'Micro-BIC',
                                    'reel-simplifie' => 'Réel simplifié',
                                    'reel-normal' => 'Réel normal',
                                    'declaration-controlee' => 'Déclaration contrôlée',
                                ];
                                foreach ($regimes as $val => $label):
                                ?>
                                <option value="<?= $val ?>" <?= $params['regime'] === $val ? 'selected' : '' ?>><?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-semibold">Régime d'imposition</label>
                            <select class="form-select" name="regime_imposition">
                                <option value="ir" <?= $params['regime_imposition'] === 'ir' ? 'selected' : '' ?>>Impôt sur le revenu (IR)</option>
                                <option value="is" <?= $params['regime_imposition'] === 'is' ? 'selected' : '' ?>>Impôt sur les sociétés (IS)</option>
                            </select>
                        </div>

                        <hr>

                        <div class="mb-3">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="tva_active" id="tvaActive" value="1"
                                       <?= $params['tva_active'] === '1' ? 'checked' : '' ?>>
                                <label class="form-check-label fw-semibold" for="tvaActive">
                                    <i class="bi bi-percent"></i> Assujetti à la TVA
                                </label>
                            </div>
                            <small class="text-muted">Activez si vous collectez et reversez la TVA.</small>
                        </div>

                        <div class="mb-4">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="inscription_ouverte" id="inscriptionOuverte" value="1"
                                       <?= $params['inscription_ouverte'] === '1' ? 'checked' : '' ?>>
                                <label class="form-check-label fw-semibold" for="inscriptionOuverte">
                                    <i class="bi bi-person-plus"></i> Autoriser l'inscription libre
                                </label>
                            </div>
                            <small class="text-muted">Permet à d'autres personnes de créer un compte (rôle Comptable par défaut).</small>
                        </div>

                        <button type="submit" class="btn btn-primary btn-setup w-100 btn-lg">
                            <i class="bi bi-check-circle"></i> Terminer la configuration
                        </button>
                    </form>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <div class="text-center mt-3 text-muted" style="font-size: 0.8rem;">
            <?= e(APP_NAME) ?> v<?= e(APP_VERSION) ?>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

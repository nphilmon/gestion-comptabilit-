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

$requestedStep = max(1, min(3, (int)($_GET['step'] ?? 1)));
$step = $nbUsers === 0 ? 1 : max(2, $requestedStep);
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

        if ($postStep > 1 && $nbUsers === 0) {
            header('Location: ' . BASE_URL . 'setup.php');
            exit;
        }

        if ($postStep === 1 && $nbUsers > 0) {
            header('Location: ' . BASE_URL . 'setup.php?step=2');
            exit;
        }

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
    <title>Configuration initiale - <?= e(APP_NAME) ?></title>
    <link rel="icon" type="image/svg+xml" href="<?= BASE_URL ?>assets/favicon.svg?v=<?= e(APP_VERSION) ?>">
    <link rel="shortcut icon" type="image/svg+xml" href="<?= BASE_URL ?>assets/favicon.svg?v=<?= e(APP_VERSION) ?>">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        :root {
            --setup-primary: #2563EB;
            --setup-primary-dark: #1E3A5F;
            --setup-accent: #0F766E;
            --setup-accent-soft: #DDF7F2;
            --setup-surface: #F5F7FB;
            --setup-card: #FFFFFF;
            --setup-text: #172033;
            --setup-muted: #667085;
            --setup-border: #D7DDEA;
            --setup-danger-bg: #FFE7E3;
            --setup-danger-text: #8F1D13;
            --setup-shadow: 0 22px 55px rgba(23,32,51,0.14);
        }
        * { box-sizing: border-box; }
        body {
            background:
                linear-gradient(180deg, #EEF5FF 0%, var(--setup-surface) 42%, #FFFFFF 100%);
            min-height: 100vh;
            font-family: 'Inter', 'Roboto', system-ui, -apple-system, sans-serif;
            color: var(--setup-text);
            margin: 0;
        }
        .setup-container {
            max-width: 760px;
            margin: 2.5rem auto 1.5rem;
            padding: 0 1rem;
        }
        .setup-header {
            background: linear-gradient(135deg, var(--setup-primary) 0%, var(--setup-primary-dark) 100%);
            color: #FFFFFF;
            border-radius: 22px 22px 0 0;
            padding: 2rem;
            position: relative;
            overflow: hidden;
        }
        .setup-header::after {
            content: '';
            position: absolute;
            inset: auto 0 0;
            height: 4px;
            background: linear-gradient(90deg, var(--setup-accent), #7DD3FC, #FBBF24);
        }
        .setup-brand {
            display: flex;
            align-items: center;
            gap: 1rem;
            position: relative;
            z-index: 1;
        }
        .setup-brand-icon {
            width: 64px;
            height: 64px;
            border-radius: 18px;
            background: rgba(255,255,255,0.14);
            border: 1px solid rgba(255,255,255,0.24);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex: 0 0 auto;
        }
        .setup-brand-icon i {
            font-size: 1.9rem;
            line-height: 1;
        }
        .setup-kicker {
            margin: 0 0 0.2rem;
            font-size: 0.78rem;
            font-weight: 700;
            letter-spacing: 0;
            text-transform: uppercase;
            opacity: 0.78;
        }
        .setup-header h1 {
            font-size: clamp(1.55rem, 3.4vw, 2.35rem);
            line-height: 1.1;
            font-weight: 800;
            margin: 0;
        }
        .setup-subtitle {
            margin: 0.55rem 0 0;
            color: rgba(255,255,255,0.82);
            font-size: 0.95rem;
        }
        .setup-card {
            background: var(--setup-card);
            border: 1px solid rgba(215,221,234,0.8);
            border-radius: 0 0 22px 22px;
            box-shadow: var(--setup-shadow);
            padding: 2rem;
        }
        .step-indicator {
            display: flex;
            justify-content: center;
            gap: 0;
            margin-bottom: 2.1rem;
        }
        .step-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.45rem 0.85rem;
            font-size: 0.85rem;
            color: var(--setup-muted);
            position: relative;
        }
        .step-item.active { color: var(--setup-primary); font-weight: 800; }
        .step-item.done { color: var(--setup-accent); }
        .step-num {
            width: 30px; height: 30px;
            border-radius: 50%;
            background: #EEF2F7;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 0.8rem;
            border: 1px solid transparent;
        }
        .step-item.active .step-num {
            background: var(--setup-primary);
            color: #FFFFFF;
            box-shadow: 0 8px 16px rgba(25,87,166,0.22);
        }
        .step-item.done .step-num { background: var(--setup-accent); color: #fff; }
        .step-connector {
            width: 52px;
            height: 2px;
            background: var(--setup-border);
            align-self: center;
        }
        .step-connector.done { background: var(--setup-accent); }
        .section-title {
            display: flex;
            align-items: center;
            gap: 0.7rem;
            margin-bottom: 0.4rem;
        }
        .section-title i {
            width: 38px;
            height: 38px;
            border-radius: 12px;
            background: var(--setup-accent-soft);
            color: var(--setup-accent);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 1.1rem;
        }
        .section-title h2 {
            margin: 0;
            font-size: 1.2rem;
            font-weight: 800;
        }
        .section-intro {
            color: var(--setup-muted);
            font-size: 0.92rem;
            margin-bottom: 1.5rem;
        }
        .form-label {
            color: var(--setup-text);
            font-size: 0.88rem;
            margin-bottom: 0.45rem;
        }
        .form-control, .form-select {
            border: 1px solid var(--setup-border);
            border-radius: 12px;
            font-size: 0.93rem;
            padding: 0.72rem 0.9rem;
            color: var(--setup-text);
            background-color: #FFFFFF;
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
        }
        .form-control:focus, .form-select:focus {
            border-color: var(--setup-primary);
            box-shadow: 0 0 0 0.2rem rgba(25,87,166,0.12);
        }
        .form-control::placeholder { color: #8B95A7; }
        .input-group {
            border: 1px solid var(--setup-border);
            border-radius: 12px;
            overflow: hidden;
            background: #FFFFFF;
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
        }
        .input-group:focus-within {
            border-color: var(--setup-primary);
            box-shadow: 0 0 0 0.2rem rgba(25,87,166,0.12);
        }
        .input-group .form-control {
            border: 0;
            box-shadow: none;
            border-radius: 0;
        }
        .input-group-text {
            width: 44px;
            justify-content: center;
            background: #F7F9FC;
            color: var(--setup-muted);
            border: 0;
            border-right: 1px solid var(--setup-border);
        }
        .btn-setup {
            background: var(--setup-primary);
            border: none;
            font-weight: 700;
            border-radius: 9999px;
            padding: 0.82rem 1.25rem;
            box-shadow: 0 10px 22px rgba(25,87,166,0.22);
            transition: transform 0.16s ease, box-shadow 0.16s ease, background-color 0.16s ease;
        }
        .btn-setup:hover {
            background: var(--setup-primary-dark);
            transform: translateY(-1px);
            box-shadow: 0 14px 26px rgba(25,87,166,0.26);
        }
        .btn-outline-secondary {
            border-color: var(--setup-border);
            color: var(--setup-muted);
            border-radius: 9999px;
            padding-left: 1rem;
            padding-right: 1rem;
        }
        .alert-danger {
            background: var(--setup-danger-bg);
            color: var(--setup-danger-text);
            border: 0;
            border-radius: 14px;
        }
        hr {
            border-color: var(--setup-border);
            opacity: 1;
            margin: 1.4rem 0;
        }
        .form-check-input:checked {
            background-color: var(--setup-accent);
            border-color: var(--setup-accent);
        }
        .success-screen {
            text-align: center;
            padding: 2rem 0 1rem;
        }
        .success-icon {
            width: 76px;
            height: 76px;
            border-radius: 24px;
            background: var(--setup-accent-soft);
            color: var(--setup-accent);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 2.2rem;
            margin-bottom: 1rem;
        }
        .setup-version {
            color: var(--setup-muted);
            font-size: 0.8rem;
        }
        @media (max-width: 575.98px) {
            .setup-container {
                margin-top: 1rem;
                padding: 0 0.75rem;
            }
            .setup-header,
            .setup-card {
                border-radius: 18px;
            }
            .setup-card {
                margin-top: 0.75rem;
                padding: 1.25rem;
            }
            .setup-brand {
                align-items: flex-start;
            }
            .setup-brand-icon {
                width: 52px;
                height: 52px;
                border-radius: 16px;
            }
            .setup-brand-icon i {
                font-size: 1.55rem;
            }
            .step-item {
                padding-left: 0.35rem;
                padding-right: 0.35rem;
            }
            .step-connector {
                width: 28px;
            }
            .d-flex.gap-2 {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <main class="setup-container">
        <div class="setup-header">
            <div class="setup-brand">
                <div class="setup-brand-icon" aria-hidden="true">
                    <i class="bi bi-clipboard2-check-fill"></i>
                </div>
                <div>
                    <p class="setup-kicker">Assistant de démarrage</p>
                    <h1><?= $success ? 'Configuration terminée !' : 'Configurer ' . e(APP_NAME) ?></h1>
                    <p class="setup-subtitle"><?= e(APP_NAME) ?> v<?= e(APP_VERSION) ?></p>
                </div>
            </div>
        </div>

        <div class="setup-card">
            <?php if ($success): ?>
                <!-- Écran de fin -->
                <div class="success-screen">
                    <span class="success-icon" aria-hidden="true"><i class="bi bi-check2-circle"></i></span>
                    <h2 class="mt-3 mb-2">Tout est prêt !</h2>
                    <p class="text-muted mb-4">La configuration de <?= e(APP_NAME) ?> est terminée.</p>
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
                    <div class="section-title">
                        <i class="bi bi-person-badge"></i>
                        <h2>Créer le compte administrateur</h2>
                    </div>
                    <p class="section-intro">Ce compte aura tous les droits sur l'application.</p>

                    <form method="POST" action="<?= BASE_URL ?>setup.php">
                        <?= csrfField() ?>
                        <input type="hidden" name="step" value="1">

                        <div class="mb-3">
                            <label class="form-label fw-semibold">Nom complet *</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-person"></i></span>
                                <input type="text" class="form-control" name="nom" required
                                       value="<?= e($_POST['nom'] ?? '') ?>" placeholder="Jean Dupont" autocomplete="name">
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-semibold">Adresse email *</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-envelope"></i></span>
                                <input type="email" class="form-control" name="email" required
                                       value="<?= e($_POST['email'] ?? '') ?>" placeholder="admin@exemple.com" autocomplete="email">
                            </div>
                        </div>

                        <div class="row g-3 mb-4">
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Mot de passe *</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="bi bi-lock"></i></span>
                                    <input type="password" class="form-control" name="password" required minlength="6"
                                           placeholder="6 caractères min." autocomplete="new-password">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Confirmation *</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="bi bi-lock-fill"></i></span>
                                    <input type="password" class="form-control" name="password_confirm" required
                                           placeholder="Retapez le mot de passe" autocomplete="new-password">
                                </div>
                            </div>
                        </div>

                        <button type="submit" class="btn btn-primary btn-setup w-100">
                            Créer le compte et continuer <i class="bi bi-arrow-right"></i>
                        </button>
                    </form>

                <?php elseif ($step === 2): ?>
                    <!-- ÉTAPE 2 : Informations entreprise -->
                    <div class="section-title">
                        <i class="bi bi-building"></i>
                        <h2>Informations de votre entreprise</h2>
                    </div>
                    <p class="section-intro">Ces informations apparaîtront sur vos documents (factures, devis...).</p>

                    <form method="POST" action="<?= BASE_URL ?>setup.php?step=2">
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
                    <div class="section-title">
                        <i class="bi bi-bank"></i>
                        <h2>Régime fiscal et options</h2>
                    </div>
                    <p class="section-intro">Configurez votre statut juridique et fiscal. Modifiable dans Paramètres.</p>

                    <form method="POST" action="<?= BASE_URL ?>setup.php?step=3">
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

        <div class="text-center mt-3 setup-version">
            <?= e(APP_NAME) ?> v<?= e(APP_VERSION) ?>
        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

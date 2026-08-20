<?php
/**
 * Page de présentation publique de la solution — nouvel accueil pour les
 * visiteurs non connectés (voir le branchement dans index.php).
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';
sendSecurityHeaders();

// Déjà connecté ? Direction le tableau de bord.
if (isLoggedIn()) {
    header('Location: ' . BASE_URL);
    exit;
}

// Formulaire de contact — validation, honeypot et limitation de débit,
// puis retour à la page (PRG) avec un message flash pour éviter toute
// resoumission au rechargement.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['contact_submit'])) {
    if (!verifyCsrf()) {
        setFlash('contact_error', 'Jeton de sécurité invalide. Veuillez réessayer.');
    } elseif (!empty($_POST['website'])) {
        // Piège à robots rempli : on ignore silencieusement sans le signaler.
        setFlash('contact_success', '1');
    } elseif (!checkRateLimit('contact', 3, 3600)) {
        setFlash('contact_error', 'Trop de messages envoyés récemment. Merci de réessayer plus tard.');
    } else {
        $contactNom = trim($_POST['nom'] ?? '');
        $contactEmail = trim($_POST['email'] ?? '');
        $contactMessage = trim($_POST['message'] ?? '');
        if ($contactNom === '' || $contactMessage === '' || !filter_var($contactEmail, FILTER_VALIDATE_EMAIL)) {
            setFlash('contact_error', 'Merci de renseigner un nom, un email valide et un message.');
        } else {
            recordRateLimitHit('contact');
            if (sendContactMessage($contactNom, $contactEmail, $contactMessage)) {
                setFlash('contact_success', '1');
            } else {
                setFlash('contact_error', "Impossible d'envoyer le message pour le moment. Réessayez plus tard.");
            }
        }
    }
    header('Location: ' . BASE_URL . 'presentation.php#contact');
    exit;
}

$contactFlash = getFlash();
$contactSuccess = $contactFlash && $contactFlash['type'] === 'contact_success';
$contactError = ($contactFlash && $contactFlash['type'] === 'contact_error') ? $contactFlash['message'] : '';

$inscriptionOuverte = isRegistrationOpen();

$fonctionnalites = [
    [
        'icone' => 'bi-receipt',
        'titre' => 'Devis, factures & commandes',
        'texte' => 'Créez des devis et factures professionnels en quelques clics, suivez les paiements et convertissez un devis accepté en facture ou commande sans ressaisie.',
    ],
    [
        'icone' => 'bi-bank',
        'titre' => 'Comptabilité multi-régime',
        'texte' => 'Auto-entrepreneur, entreprise individuelle, EURL, SASU... Chaque régime a ses propres règles de calcul (plafonds, abattements, cotisations) automatiquement appliquées.',
    ],
    [
        'icone' => 'bi-file-earmark-check',
        'titre' => 'Facturation électronique 2026',
        'texte' => "Prêt pour la réforme : préparation et transmission des factures électroniques via une plateforme agréée (PDP), aux formats UBL et Factur-X.",
    ],
    [
        'icone' => 'bi-people',
        'titre' => 'Paie & congés payés',
        'texte' => "Bulletins de paie au format clarifié, calcul automatique de l'acquisition et du solde des congés payés selon vos règles.",
    ],
    [
        'icone' => 'bi-graph-up-arrow',
        'titre' => 'Trésorerie & rapports',
        'texte' => "Suivi des recettes et dépenses, journal général, grand livre, balance et projection de trésorerie — exportables en PDF ou CSV.",
    ],
    [
        'icone' => 'bi-shield-check',
        'titre' => 'Sécurité & conformité',
        'texte' => "Protection CSRF, limitation des tentatives de connexion, rôles et permissions, hébergement en France, mentions légales et RGPD à jour.",
    ],
];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e(APP_NAME) ?> — Gestion comptable simple pour indépendants et TPE</title>
    <meta name="description" content="Devis, factures, comptabilité multi-régime, paie et facturation électronique 2026 : une seule application pour piloter votre activité.">
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
            --md-outline-variant: #C3C7CF;
            --md-surface-container-lowest: #FFFFFF;
        }
        * { box-sizing: border-box; }
        body {
            font-family: 'Inter', 'Roboto', system-ui, sans-serif;
            color: var(--md-on-surface);
            background: var(--md-surface-container-lowest);
        }

        /* ── Barre de navigation ── */
        .pres-nav {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 1.1rem 1.5rem;
            max-width: 1140px;
            margin: 0 auto;
        }
        .pres-nav .brand {
            display: inline-flex;
            align-items: center;
            gap: 0.55rem;
            font-weight: 700;
            font-size: 1.1rem;
            color: var(--md-primary-dark);
            text-decoration: none;
        }
        .pres-nav .brand i { color: var(--md-primary); font-size: 1.3rem; }
        .pres-nav-actions { display: flex; align-items: center; gap: 1.25rem; }
        .pres-nav-link {
            color: var(--md-on-surface-variant);
            text-decoration: none;
            font-size: 0.9rem;
            font-weight: 500;
        }
        .pres-nav-link:hover { color: var(--md-primary); }
        .btn-nav-login {
            background: var(--md-primary);
            color: #fff;
            border: none;
            border-radius: 9999px;
            padding: 0.55rem 1.25rem;
            font-weight: 500;
            font-size: 0.9rem;
            text-decoration: none;
        }
        .btn-nav-login:hover { background: #1d4ed8; color: #fff; }

        /* ── Hero ── */
        .pres-hero {
            background: linear-gradient(135deg, var(--md-primary) 0%, var(--md-primary-dark) 100%);
            color: #fff;
            padding: 4rem 1.5rem 5rem;
            position: relative;
            overflow: hidden;
        }
        .pres-hero::before {
            content: '';
            position: absolute;
            top: -25%;
            right: -10%;
            width: 460px;
            height: 460px;
            background: radial-gradient(circle, rgba(255,255,255,0.08) 0%, transparent 70%);
            border-radius: 50%;
        }
        .pres-hero-inner {
            max-width: 760px;
            margin: 0 auto;
            text-align: center;
            position: relative;
            z-index: 1;
        }
        .pres-hero-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            background: rgba(255,255,255,0.14);
            border-radius: 9999px;
            padding: 0.35rem 0.9rem;
            font-size: 0.8rem;
            font-weight: 500;
            margin-bottom: 1.5rem;
        }
        .pres-hero h1 {
            font-size: 2.4rem;
            font-weight: 800;
            letter-spacing: -0.02em;
            line-height: 1.2;
            margin-bottom: 1rem;
        }
        .pres-hero p.lead {
            font-size: 1.1rem;
            font-weight: 300;
            opacity: 0.92;
            line-height: 1.65;
            max-width: 600px;
            margin: 0 auto 2.25rem;
        }
        .pres-hero-actions {
            display: flex;
            gap: 0.9rem;
            justify-content: center;
            flex-wrap: wrap;
        }
        .btn-hero-primary {
            background: #fff;
            color: var(--md-primary-dark);
            border: none;
            border-radius: 9999px;
            padding: 0.85rem 1.75rem;
            font-weight: 600;
            font-size: 0.95rem;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            box-shadow: 0 2px 10px rgba(0,0,0,0.15);
        }
        .btn-hero-primary:hover { color: var(--md-primary-dark); transform: translateY(-1px); }
        .btn-hero-secondary {
            background: rgba(255,255,255,0.12);
            color: #fff;
            border: 1.5px solid rgba(255,255,255,0.4);
            border-radius: 9999px;
            padding: 0.85rem 1.75rem;
            font-weight: 500;
            font-size: 0.95rem;
            text-decoration: none;
        }
        .btn-hero-secondary:hover { background: rgba(255,255,255,0.2); color: #fff; }

        /* ── Fonctionnalités ── */
        .pres-features {
            max-width: 1140px;
            margin: 0 auto;
            padding: 4rem 1.5rem;
        }
        .feature-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 1.5rem;
        }
        .pres-section-title {
            text-align: center;
            max-width: 560px;
            margin: 0 auto 2.75rem;
        }
        .pres-section-title h2 {
            font-size: 1.6rem;
            font-weight: 700;
            letter-spacing: -0.01em;
            margin-bottom: 0.6rem;
        }
        .pres-section-title p {
            color: var(--md-on-surface-variant);
            font-size: 0.95rem;
        }
        .feature-card {
            background: #fff;
            border: 1px solid var(--md-outline-variant);
            border-radius: 16px;
            padding: 1.6rem;
            height: 100%;
        }
        .feature-icon {
            width: 46px;
            height: 46px;
            border-radius: 12px;
            background: var(--md-primary-container);
            color: var(--md-primary);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 1.3rem;
            margin-bottom: 1rem;
        }
        .feature-card h3 {
            font-size: 1.02rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }
        .feature-card p {
            font-size: 0.87rem;
            color: var(--md-on-surface-variant);
            line-height: 1.6;
            margin-bottom: 0;
        }

        /* ── Bandeau CTA final ── */
        .pres-cta {
            background: var(--md-surface);
            padding: 3.5rem 1.5rem;
            text-align: center;
        }
        .pres-cta h2 {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 0.6rem;
        }
        .pres-cta p {
            color: var(--md-on-surface-variant);
            margin-bottom: 1.75rem;
        }
        .btn-cta-primary {
            background: var(--md-primary);
            color: #fff;
            border: none;
            border-radius: 9999px;
            padding: 0.75rem 1.75rem;
            font-weight: 600;
            font-size: 0.95rem;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }
        .btn-cta-primary:hover { background: #1d4ed8; color: #fff; }

        /* ── Footer ── */
        .pres-footer {
            text-align: center;
            padding: 2rem 1.5rem 2.5rem;
            font-size: 0.8rem;
            color: var(--md-on-surface-variant);
        }
        .pres-footer a { color: var(--md-primary); text-decoration: none; font-weight: 500; }
        .pres-footer a:hover { text-decoration: underline; }

        /* ── Contact ── */
        .pres-contact {
            padding: 4rem 1.5rem;
            border-top: 1px solid var(--md-outline-variant);
        }
        .pres-contact-inner {
            max-width: 560px;
            margin: 0 auto;
        }
        .contact-honeypot {
            position: absolute;
            left: -9999px;
            width: 1px;
            height: 1px;
            opacity: 0;
        }
        .contact-alert {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            border-radius: 10px;
            padding: 0.75rem 1rem;
            font-size: 0.88rem;
            margin-bottom: 1.25rem;
        }
        .contact-alert-success { background: #DCFCE7; color: #166534; }
        .contact-alert-error { background: #FEE2E2; color: #991B1B; }
        .contact-form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
        }
        .contact-field { margin-bottom: 1rem; }
        .contact-field label {
            display: block;
            font-size: 0.83rem;
            font-weight: 500;
            color: var(--md-on-surface-variant);
            margin-bottom: 0.35rem;
        }
        .contact-field input,
        .contact-field textarea {
            width: 100%;
            border: 1.5px solid var(--md-outline-variant);
            border-radius: 10px;
            padding: 0.65rem 0.8rem;
            font-size: 0.92rem;
            font-family: inherit;
            color: var(--md-on-surface);
        }
        .contact-field input:focus,
        .contact-field textarea:focus {
            outline: none;
            border-color: var(--md-primary);
            box-shadow: 0 0 0 1px var(--md-primary);
        }
        .contact-form button { border: none; cursor: pointer; }
    </style>
</head>
<body>

    <nav class="pres-nav">
        <a href="<?= BASE_URL ?>presentation.php" class="brand"><i class="bi bi-bar-chart-line-fill"></i> <?= e(APP_NAME) ?></a>
        <div class="pres-nav-actions">
            <a href="#contact" class="pres-nav-link">Contact</a>
            <a href="<?= BASE_URL ?>login.php" class="btn-nav-login">Se connecter</a>
        </div>
    </nav>

    <header class="pres-hero">
        <div class="pres-hero-inner">
            <span class="pres-hero-badge"><i class="bi bi-stars"></i> Conforme à la réforme de facturation électronique 2026</span>
            <h1>La gestion comptable de votre activité, réunie dans un seul outil</h1>
            <p class="lead">
                Devis, factures, comptabilité multi-régime, paie et suivi de trésorerie : <?= e(APP_NAME) ?>
                remplace vos tableurs et simplifie vos obligations, du premier devis à la déclaration.
            </p>
            <div class="pres-hero-actions">
                <a href="<?= BASE_URL ?>login.php" class="btn-hero-primary"><i class="bi bi-box-arrow-in-right"></i> Se connecter</a>
                <?php if ($inscriptionOuverte): ?>
                    <a href="<?= BASE_URL ?>register.php" class="btn-hero-secondary"><i class="bi bi-person-plus"></i> Créer un compte</a>
                <?php endif; ?>
            </div>
        </div>
    </header>

    <section class="pres-features">
        <div class="pres-section-title">
            <h2>Tout ce qu'il faut pour piloter votre activité</h2>
            <p>Chaque module est pensé pour les indépendants et petites entreprises françaises.</p>
        </div>
        <div class="feature-grid">
            <?php foreach ($fonctionnalites as $f): ?>
            <div class="feature-card">
                <div class="feature-icon"><i class="bi <?= e($f['icone']) ?>"></i></div>
                <h3><?= e($f['titre']) ?></h3>
                <p><?= e($f['texte']) ?></p>
            </div>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="pres-cta">
        <h2>Prêt à simplifier votre gestion ?</h2>
        <p>Connectez-vous pour accéder à votre espace.</p>
        <a href="<?= BASE_URL ?>login.php" class="btn-cta-primary"><i class="bi bi-box-arrow-in-right"></i> Se connecter</a>
    </section>

    <section class="pres-contact" id="contact">
        <div class="pres-contact-inner">
            <div class="pres-section-title">
                <h2>Une question ?</h2>
                <p>Écrivez-nous et nous vous répondrons directement par email.</p>
            </div>

            <?php if ($contactSuccess): ?>
                <div class="contact-alert contact-alert-success">
                    <i class="bi bi-check-circle"></i> Message envoyé, merci ! Nous vous répondrons rapidement.
                </div>
            <?php endif; ?>
            <?php if ($contactError !== ''): ?>
                <div class="contact-alert contact-alert-error">
                    <i class="bi bi-exclamation-triangle"></i> <?= e($contactError) ?>
                </div>
            <?php endif; ?>

            <form method="post" action="<?= BASE_URL ?>presentation.php#contact" class="contact-form">
                <?= csrfField() ?>
                <input type="text" name="website" class="contact-honeypot" tabindex="-1" autocomplete="off" aria-hidden="true">
                <div class="contact-form-row">
                    <div class="contact-field">
                        <label for="contact-nom">Nom</label>
                        <input type="text" id="contact-nom" name="nom" required>
                    </div>
                    <div class="contact-field">
                        <label for="contact-email">Email</label>
                        <input type="email" id="contact-email" name="email" required>
                    </div>
                </div>
                <div class="contact-field">
                    <label for="contact-message">Message</label>
                    <textarea id="contact-message" name="message" rows="4" required></textarea>
                </div>
                <button type="submit" name="contact_submit" value="1" class="btn-cta-primary">
                    <i class="bi bi-send"></i> Envoyer le message
                </button>
            </form>
        </div>
    </section>

    <footer class="pres-footer">
        <?= e(APP_NAME) ?> v<?= e(APP_VERSION) ?>
        · <a href="<?= BASE_URL ?>mentions-legales.php">Mentions légales</a>
    </footer>

    <?php include __DIR__ . '/cookie_notice.php'; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
</body>
</html>

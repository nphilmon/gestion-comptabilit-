<?php
/**
 * Mentions légales & protection des données personnelles.
 * Page publique (pas de requireLogin) : la LCEN impose que les mentions
 * légales soient accessibles à tout visiteur, connecté ou non.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';
sendSecurityHeaders();

$nomEntreprise = getParam('nom_entreprise', '');
$siret = getParam('siret', '');
$activite = getParam('activite', '');
$adresse = getParam('adresse_entreprise', '');
$codePostal = getParam('code_postal', '');
$ville = getParam('ville', '');
$emailContact = getParam('email_entreprise', '');
$telephone = getParam('telephone_entreprise', '');

$formes = getFormesJuridiques();
$formeCode = getParam('forme_juridique', '');
$formeLabel = $formes[$formeCode]['label'] ?? '';

$adresseComplete = trim(implode(', ', array_filter([
    trim(str_replace("\n", ', ', $adresse)),
    trim($codePostal . ' ' . $ville),
])));

$hebergeurNom = getParam('hebergeur_nom', '');
$hebergeurAdresse = getParam('hebergeur_adresse', '');
$hebergeurSite = getParam('hebergeur_site', '');
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mentions légales - <?= e(APP_NAME) ?></title>
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
            --md-surface-container-lowest: #FFFFFF;
            --md-warning-container: #FFE0B2;
        }
        * { box-sizing: border-box; }
        body {
            background: var(--md-surface);
            font-family: 'Inter', 'Roboto', system-ui, sans-serif;
            color: var(--md-on-surface);
        }
        .legal-topbar {
            background: linear-gradient(135deg, var(--md-primary) 0%, var(--md-primary-dark) 100%);
            color: var(--md-on-primary);
            padding: 2.5rem 1.5rem;
        }
        .legal-topbar-inner {
            max-width: 820px;
            margin: 0 auto;
        }
        .legal-topbar .brand {
            display: inline-flex;
            align-items: center;
            gap: 0.6rem;
            font-weight: 700;
            font-size: 1.05rem;
            text-decoration: none;
            color: var(--md-on-primary);
            opacity: 0.92;
        }
        .legal-topbar h1 {
            font-size: 1.75rem;
            font-weight: 700;
            letter-spacing: -0.01em;
            margin-top: 1rem;
        }
        .legal-topbar p {
            opacity: 0.9;
            margin-top: 0.4rem;
            margin-bottom: 0;
            font-size: 0.9rem;
        }
        .legal-content {
            max-width: 820px;
            margin: 0 auto;
            padding: 2.5rem 1.5rem 4rem;
        }
        .legal-card {
            background: var(--md-surface-container-lowest);
            border-radius: 16px;
            padding: 1.75rem 2rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 1px 2px rgba(0,0,0,0.04), 0 1px 8px rgba(0,0,0,0.04);
        }
        .legal-card h2 {
            font-size: 1.15rem;
            font-weight: 700;
            color: var(--md-primary-dark);
            margin-bottom: 0.9rem;
            display: flex;
            align-items: center;
            gap: 0.55rem;
        }
        .legal-card h2 i { color: var(--md-primary); }
        .legal-card h3 {
            font-size: 0.95rem;
            font-weight: 600;
            margin-top: 1.1rem;
            margin-bottom: 0.4rem;
        }
        .legal-card p, .legal-card li {
            font-size: 0.9rem;
            line-height: 1.65;
            color: var(--md-on-surface-variant);
        }
        .legal-card ul { padding-left: 1.2rem; margin-bottom: 0; }
        .legal-card dl { margin: 0; }
        .legal-card dt { font-weight: 600; color: var(--md-on-surface); font-size: 0.9rem; }
        .legal-card dd { color: var(--md-on-surface-variant); font-size: 0.9rem; margin-bottom: 0.6rem; }
        .legal-missing {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            background: var(--md-warning-container);
            color: #7A4100;
            border-radius: 8px;
            padding: 0.15rem 0.6rem;
            font-size: 0.82rem;
            font-weight: 500;
        }
        .legal-footer-nav {
            max-width: 820px;
            margin: 0 auto;
            padding: 0 1.5rem 3rem;
            text-align: center;
        }
        .legal-footer-nav a {
            color: var(--md-primary);
            text-decoration: none;
            font-weight: 500;
        }
        .legal-footer-nav a:hover { text-decoration: underline; }
        .legal-updated {
            font-size: 0.78rem;
            color: var(--md-outline);
            text-align: center;
            margin-top: 1rem;
        }
    </style>
</head>
<body>
    <div class="legal-topbar">
        <div class="legal-topbar-inner">
            <a href="<?= BASE_URL ?>login.php" class="brand"><i class="bi bi-bar-chart-line-fill"></i> <?= e(APP_NAME) ?></a>
            <h1>Mentions légales &amp; protection des données</h1>
            <p>Conformément à la loi n° 2004-575 du 21 juin 2004 pour la confiance dans l'économie numérique (LCEN)
               et au Règlement (UE) 2016/679 (RGPD).</p>
        </div>
    </div>

    <div class="legal-content">

        <div class="legal-card">
            <h2><i class="bi bi-person-badge"></i> 1. Éditeur du site</h2>
            <?php if ($nomEntreprise === ''): ?>
                <p><span class="legal-missing"><i class="bi bi-exclamation-triangle"></i> Informations à compléter</span>
                   dans Paramètres → Informations de l'entreprise.</p>
            <?php else: ?>
                <dl>
                    <dt>Éditeur</dt>
                    <dd>
                        <?= e($nomEntreprise) ?><?= $formeLabel ? ' — ' . e($formeLabel) : '' ?>
                        <?= $activite ? '<br>' . e($activite) : '' ?>
                    </dd>
                    <?php if ($adresseComplete !== ''): ?>
                    <dt>Adresse</dt>
                    <dd><?= e($adresseComplete) ?></dd>
                    <?php endif; ?>
                    <?php if ($siret !== ''): ?>
                    <dt>SIRET</dt>
                    <dd><?= e($siret) ?></dd>
                    <?php endif; ?>
                    <?php if ($emailContact !== '' || $telephone !== ''): ?>
                    <dt>Contact</dt>
                    <dd>
                        <?= $emailContact ? e($emailContact) : '' ?>
                        <?= $emailContact && $telephone ? ' — ' : '' ?>
                        <?= $telephone ? e($telephone) : '' ?>
                    </dd>
                    <?php endif; ?>
                    <dt>Directeur de la publication</dt>
                    <dd><?= e($nomEntreprise) ?></dd>
                </dl>
            <?php endif; ?>
        </div>

        <div class="legal-card">
            <h2><i class="bi bi-hdd-network"></i> 2. Hébergement</h2>
            <?php if ($hebergeurNom === ''): ?>
                <p><span class="legal-missing"><i class="bi bi-exclamation-triangle"></i> Informations à compléter</span>
                   dans Paramètres → Hébergement.</p>
            <?php else: ?>
                <dl>
                    <dt>Hébergeur</dt>
                    <dd><?= e($hebergeurNom) ?></dd>
                    <?php if ($hebergeurAdresse !== ''): ?>
                    <dt>Adresse</dt>
                    <dd><?= e($hebergeurAdresse) ?></dd>
                    <?php endif; ?>
                    <?php if ($hebergeurSite !== ''): ?>
                    <dt>Site web</dt>
                    <dd><?= e($hebergeurSite) ?></dd>
                    <?php endif; ?>
                </dl>
            <?php endif; ?>
        </div>

        <div class="legal-card">
            <h2><i class="bi bi-c-circle"></i> 3. Propriété intellectuelle</h2>
            <p>
                L'ensemble des éléments présents sur <?= e(APP_NAME) ?> (structure, textes, logo, code source)
                est protégé au titre du droit d'auteur et reste la propriété de son éditeur, sauf mention contraire.
                Toute reproduction, représentation ou diffusion, totale ou partielle, sans autorisation préalable
                est interdite.
            </p>
        </div>

        <div class="legal-card">
            <h2><i class="bi bi-shield-lock"></i> 4. Protection des données personnelles</h2>

            <h3>Responsable du traitement</h3>
            <p>
                Le responsable du traitement des données à caractère personnel collectées via <?= e(APP_NAME) ?>
                est l'éditeur identifié à l'article 1 ci-dessus.
            </p>

            <h3>Données collectées et finalités</h3>
            <ul>
                <li><strong>Comptes utilisateurs</strong> (email, mot de passe chiffré, rôle) : authentification et
                    gestion des accès à l'application.</li>
                <li><strong>Données clients</strong> (identité, coordonnées, SIRET) : établissement des devis, factures,
                    commandes et suivi comptable.</li>
                <li><strong>Données de paie des salariés</strong> (identité, numéro de sécurité sociale, IBAN,
                    rémunération) : établissement des bulletins de paie, uniquement si le module Paie est activé.</li>
            </ul>

            <h3>Base légale</h3>
            <p>
                Ces traitements reposent sur l'exécution d'un contrat (gestion de la relation client, contrat de travail)
                et sur le respect d'obligations légales (obligations comptables, fiscales et sociales imposées par le
                Code de commerce et le Code du travail).
            </p>

            <h3>Durée de conservation</h3>
            <ul>
                <li>Pièces comptables et factures : 10 ans, conformément à l'article L123-22 du Code de commerce.</li>
                <li>Bulletins de paie et documents sociaux : durées légales de conservation applicables en droit du travail.</li>
                <li>Comptes utilisateurs : conservés pendant la durée d'utilisation de l'application, puis supprimés ou
                    archivés selon les obligations légales en vigueur.</li>
            </ul>

            <h3>Destinataires des données</h3>
            <p>
                Les données ne sont accessibles qu'aux personnes autorisées de l'organisation (selon leur rôle) et ne
                sont transmises à des tiers que lorsque la loi l'exige (administration fiscale, organismes sociaux,
                plateforme de facturation électronique agréée).
            </p>

            <h3>Vos droits</h3>
            <p>
                Conformément au RGPD et à la loi Informatique et Libertés, vous disposez d'un droit d'accès, de
                rectification, d'effacement, de limitation, d'opposition et de portabilité de vos données. Pour exercer
                ces droits, contactez l'éditeur
                <?= $emailContact !== '' ? ' à l\'adresse ' . e($emailContact) : ' aux coordonnées indiquées à l\'article 1' ?>.
                Vous disposez également du droit d'introduire une réclamation auprès de la
                <a href="https://www.cnil.fr" target="_blank" rel="noopener">Commission Nationale de l'Informatique et
                des Libertés (CNIL)</a>.
            </p>

            <h3>Sécurité</h3>
            <p>
                Les mots de passe sont stockés sous forme chiffrée, les échanges sont protégés contre les attaques
                courantes (CSRF, limitation des tentatives de connexion), et l'accès aux données est restreint par un
                système de rôles.
            </p>
        </div>

        <div class="legal-card" id="cookies">
            <h2><i class="bi bi-cookie"></i> 5. Cookies</h2>
            <p>
                <?= e(APP_NAME) ?> utilise uniquement un cookie de session strictement nécessaire au fonctionnement de
                l'authentification (maintien de la connexion). Aucun cookie de mesure d'audience ou de publicité n'est
                déposé. Ce cookie technique ne nécessite pas de consentement préalable au titre de la réglementation
                applicable.
            </p>
            <p>
                Les polices de caractères sont chargées depuis Google Fonts et les bibliothèques d'interface depuis un
                réseau de diffusion de contenu (CDN) : ces services tiers peuvent recevoir votre adresse IP lors du
                chargement de la page.
            </p>
        </div>

        <div class="legal-card">
            <h2><i class="bi bi-globe-europe-africa"></i> 6. Droit applicable</h2>
            <p>
                Les présentes mentions légales sont soumises au droit français. En cas de litige, et à défaut de
                résolution amiable, les tribunaux français seront seuls compétents.
            </p>
        </div>

    </div>

    <div class="legal-footer-nav">
        <a href="<?= BASE_URL ?>login.php"><i class="bi bi-arrow-left"></i> Retour à la connexion</a>
        <div class="legal-updated">Dernière mise à jour : 20/08/2026 — <?= e(APP_NAME) ?> v<?= e(APP_VERSION) ?></div>
    </div>
</body>
</html>

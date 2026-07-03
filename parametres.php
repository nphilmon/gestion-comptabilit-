<?php
/**
 * Paramètres de l'application - Multi-régimes
 */
require_once __DIR__ . '/functions.php';
requireLogin();
requireRole('admin');
$titre = 'Paramètres';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        setFlash('danger', 'Jeton de sécurité invalide.');
        header('Location: parametres.php');
        exit;
    }

    // Appliquer les valeurs par défaut si changement de régime
    if (!empty($_POST['appliquer_defauts'])) {
        $forme = $_POST['forme_juridique'] ?? 'auto-entrepreneur';
        $regime = $_POST['regime'] ?? 'micro-bnc';
        $defaults = getRegimeDefaults($forme, $regime);
        foreach ($defaults as $k => $v) {
            setParam($k, $v);
        }
        setParam('forme_juridique', $forme);
        setParam('regime', $regime);
        setFlash('success', 'Valeurs par défaut du régime appliquées. Vérifiez et ajustez si nécessaire.');
        header('Location: parametres.php');
        exit;
    }

    $champs = [
        'nom_entreprise', 'siret', 'activite', 'adresse_entreprise',
        'forme_juridique', 'regime', 'regime_imposition',
        'plafond_ca', 'taux_abattement',
        'taux_cotisations_sociales', 'taux_cfp',
        'taux_versement_liberatoire', 'versement_liberatoire_actif',
        'tva_applicable', 'taux_tva', 'taux_is',
        'conditions_paiement', 'validite_devis',
        'acompte_commande_pct', 'garantie_sav_mois',
        'delai_livraison_jours', 'clause_chantier_livraison',
        'module_cp_actif', 'cp_mode_decompte', 'cp_reference_start_month',
        'cp_reference_start_day', 'cp_acquisition_rate', 'cp_annual_cap', 'cp_fractionnement_actif',
        'module_paie_actif', 'paie_jours_travail_mensuel',
        'paie_taux_charges_patronales', 'paie_taux_charges_salariales',
        'pdp_enabled', 'pdp_provider', 'pdp_auto_send', 'pdp_endpoint_url',
        'pdp_auth_type', 'pdp_api_key_env', 'pdp_custom_header_name', 'pdp_export_format',
        'logo_pdf_path', 'signature_pdf_path', 'cachet_pdf_path',
    ];

    foreach ($champs as $champ) {
        if (isset($_POST[$champ])) {
            setParam($champ, trim($_POST[$champ]));
        }
    }

    // Checkboxes
    if (!isset($_POST['versement_liberatoire_actif'])) {
        setParam('versement_liberatoire_actif', '0');
    }
    if (!isset($_POST['tva_applicable'])) {
        setParam('tva_applicable', '0');
    }
    if (!isset($_POST['module_cp_actif'])) {
        setParam('module_cp_actif', '0');
    }
    if (!isset($_POST['cp_fractionnement_actif'])) {
        setParam('cp_fractionnement_actif', '0');
    }
    if (!isset($_POST['module_paie_actif'])) {
        setParam('module_paie_actif', '0');
    }
    if (!isset($_POST['pdp_enabled'])) {
        setParam('pdp_enabled', '0');
    }
    if (!isset($_POST['pdp_auto_send'])) {
        setParam('pdp_auto_send', '0');
    }

    setFlash('success', 'Paramètres enregistrés avec succès.');
    header('Location: parametres.php');
    exit;
}

$formes = getFormesJuridiques();
$conf = getRegimeConfig();

include 'header.php';
?>

<div class="hero-banner mb-4">
    <div class="d-flex justify-content-between align-items-center">
        <div>
            <h2 class="mb-1"><i class="bi bi-gear"></i> Paramètres</h2>
            <p class="text-muted mb-0">Configuration de votre activité et régime fiscal</p>
        </div>
    </div>
</div>

<form method="POST">
    <?= csrfField() ?>

    <!-- Informations entreprise -->
    <div class="card border-0 mb-4">
        <div class="card-header"><i class="bi bi-building"></i> Informations de l'entreprise</div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">Nom de l'entreprise / activité</label>
                    <input type="text" name="nom_entreprise" class="form-control" 
                           value="<?= e(getParam('nom_entreprise')) ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">SIRET</label>
                    <input type="text" name="siret" class="form-control" maxlength="14"
                           value="<?= e(getParam('siret')) ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Nature de l'activité</label>
                    <input type="text" name="activite" class="form-control" 
                           value="<?= e(getParam('activite')) ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Adresse</label>
                    <textarea name="adresse_entreprise" class="form-control" rows="2"><?= e(getParam('adresse_entreprise')) ?></textarea>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Conditions de paiement</label>
                    <input type="text" name="conditions_paiement" class="form-control"
                           value="<?= e(getParam('conditions_paiement', 'Paiement à réception')) ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Validité devis (jours)</label>
                    <input type="number" name="validite_devis" class="form-control" min="1"
                           value="<?= e(getParam('validite_devis', '30')) ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Acompte automatique (%)</label>
                    <input type="number" name="acompte_commande_pct" class="form-control" min="0" max="100" step="1"
                           value="<?= e(getParam('acompte_commande_pct', '30')) ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Garantie / SAV (mois)</label>
                    <input type="number" name="garantie_sav_mois" class="form-control" min="0" max="60" step="1"
                           value="<?= e(getParam('garantie_sav_mois', '12')) ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Délai livraison indicatif (jours)</label>
                    <input type="number" name="delai_livraison_jours" class="form-control" min="0" max="365" step="1"
                           value="<?= e(getParam('delai_livraison_jours', '30')) ?>">
                </div>
                <div class="col-md-12">
                    <label class="form-label">Clause chantier / livraison personnalisée</label>
                    <textarea name="clause_chantier_livraison" class="form-control" rows="3"><?= e(getParam('clause_chantier_livraison')) ?></textarea>
                    <small class="text-muted">Si ce champ est rempli, il remplace la clause automatique de chantier/livraison dans les CGV.</small>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Logo PDF</label>
                    <input type="text" name="logo_pdf_path" class="form-control"
                           value="<?= e(getParam('logo_pdf_path')) ?>" placeholder="ex: uploads/logo.png">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Signature image PDF</label>
                    <input type="text" name="signature_pdf_path" class="form-control"
                           value="<?= e(getParam('signature_pdf_path')) ?>" placeholder="ex: uploads/signature.png">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Cachet image PDF</label>
                    <input type="text" name="cachet_pdf_path" class="form-control"
                           value="<?= e(getParam('cachet_pdf_path')) ?>" placeholder="ex: uploads/cachet.png">
                </div>
                <div class="col-md-12">
                    <small class="text-muted">Renseigne un chemin local relatif au projet, par exemple <code>uploads/logo.png</code> ou <code>assets/logo.jpg</code>.</small>
                </div>
            </div>
        </div>
    </div>

    <div class="card border-0 mb-4">
        <div class="card-header"><i class="bi bi-calendar2-check"></i> Modules métier - Congés payés (CP)</div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-4">
                    <div class="form-check form-switch mt-2">
                        <input type="hidden" name="module_cp_actif" value="0">
                        <input class="form-check-input" type="checkbox" name="module_cp_actif" value="1"
                               id="cpModuleSwitch" <?= getParam('module_cp_actif', '0') === '1' ? 'checked' : '' ?>>
                        <label class="form-check-label" for="cpModuleSwitch">
                            <strong>Activer la gestion des congés payés</strong>
                        </label>
                    </div>
                    <small class="text-muted">Ajoute le module RH CP dans la navigation et le tableau de bord.</small>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Décompte</label>
                    <select name="cp_mode_decompte" class="form-select">
                        <option value="ouvrables" <?= getParam('cp_mode_decompte', 'ouvrables') === 'ouvrables' ? 'selected' : '' ?>>Jours ouvrables</option>
                        <option value="ouvres" <?= getParam('cp_mode_decompte', 'ouvrables') === 'ouvres' ? 'selected' : '' ?>>Jours ouvrés</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Début période - mois</label>
                    <input type="number" name="cp_reference_start_month" class="form-control" min="1" max="12"
                           value="<?= e(getParam('cp_reference_start_month', '6')) ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Début période - jour</label>
                    <input type="number" name="cp_reference_start_day" class="form-control" min="1" max="28"
                           value="<?= e(getParam('cp_reference_start_day', '1')) ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Acquisition mensuelle</label>
                    <input type="number" name="cp_acquisition_rate" class="form-control" min="0" step="0.01"
                           value="<?= e(getParam('cp_acquisition_rate', '2.5')) ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Plafond annuel</label>
                    <input type="number" name="cp_annual_cap" class="form-control" min="0" step="0.01"
                           value="<?= e(getParam('cp_annual_cap', '30')) ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label d-block">Jours de fractionnement</label>
                    <div class="form-check form-switch">
                        <input type="hidden" name="cp_fractionnement_actif" value="0">
                        <input class="form-check-input" type="checkbox" name="cp_fractionnement_actif" value="1"
                               id="cpFractionnementSwitch" <?= getParam('cp_fractionnement_actif', '1') === '1' ? 'checked' : '' ?>>
                        <label class="form-check-label" for="cpFractionnementSwitch">Appliquer L3141-23</label>
                    </div>
                </div>
                <div class="col-md-7">
                    <div class="alert alert-light border-0 mb-0">
                        <i class="bi bi-info-circle text-primary"></i>
                        Réglage par défaut conforme au droit commun en France métropolitaine :
                        <strong>2,5 jours ouvrables</strong> acquis par mois de travail effectif, sur une période de référence du
                        <strong>1er juin au 31 mai</strong>. Les cas particuliers (convention collective, arrêt maladie, jours supplémentaires) restent ajustables dans le module CP.
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card border-0 mb-4">
        <div class="card-header"><i class="bi bi-diagram-3"></i> Facturation électronique - PDP</div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-4">
                    <div class="form-check form-switch mt-2">
                        <input type="hidden" name="pdp_enabled" value="0">
                        <input class="form-check-input" type="checkbox" name="pdp_enabled" value="1"
                               id="pdpEnabledSwitch" <?= getParam('pdp_enabled', '0') === '1' ? 'checked' : '' ?>>
                        <label class="form-check-label" for="pdpEnabledSwitch">
                            <strong>Activer le module PDP</strong>
                        </label>
                    </div>
                    <small class="text-muted">Permet l'export UBL et la transmission automatique à une plateforme choisie.</small>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Fournisseur</label>
                    <select name="pdp_provider" class="form-select">
                        <option value="generic_api" <?= getParam('pdp_provider', 'generic_api') === 'generic_api' ? 'selected' : '' ?>>API générique</option>
                        <option value="chorus_pro" <?= getParam('pdp_provider', 'generic_api') === 'chorus_pro' ? 'selected' : '' ?>>Chorus Pro / secteur public</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Format par défaut</label>
                    <select name="pdp_export_format" class="form-select">
                        <option value="ubl" <?= getParam('pdp_export_format', 'ubl') === 'ubl' ? 'selected' : '' ?>>UBL 2.1</option>
                        <option value="factur-x" <?= getParam('pdp_export_format', 'ubl') === 'factur-x' ? 'selected' : '' ?>>Factur-X</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <div class="form-check form-switch mt-4">
                        <input type="hidden" name="pdp_auto_send" value="0">
                        <input class="form-check-input" type="checkbox" name="pdp_auto_send" value="1"
                               id="pdpAutoSendSwitch" <?= getParam('pdp_auto_send', '0') === '1' ? 'checked' : '' ?>>
                        <label class="form-check-label" for="pdpAutoSendSwitch">
                            Envoi automatique
                        </label>
                    </div>
                </div>
                <div class="col-md-6">
                    <label class="form-label">URL endpoint PDP</label>
                    <input type="url" name="pdp_endpoint_url" class="form-control"
                           value="<?= e(getParam('pdp_endpoint_url')) ?>" placeholder="https://api.votre-pdp.fr/invoices">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Authentification</label>
                    <select name="pdp_auth_type" class="form-select">
                        <option value="bearer_env" <?= getParam('pdp_auth_type', 'bearer_env') === 'bearer_env' ? 'selected' : '' ?>>Bearer via variable env</option>
                        <option value="header_env" <?= getParam('pdp_auth_type', 'bearer_env') === 'header_env' ? 'selected' : '' ?>>En-tête custom via variable env</option>
                        <option value="none" <?= getParam('pdp_auth_type', 'bearer_env') === 'none' ? 'selected' : '' ?>>Aucune</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Variable d'environnement clé</label>
                    <input type="text" name="pdp_api_key_env" class="form-control"
                           value="<?= e(getParam('pdp_api_key_env', 'GESTION_COMPTA_PDP_API_KEY')) ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Nom en-tête custom</label>
                    <input type="text" name="pdp_custom_header_name" class="form-control"
                           value="<?= e(getParam('pdp_custom_header_name', 'X-API-Key')) ?>">
                </div>
                <div class="col-md-12">
                    <div class="alert alert-light border-0 mb-0">
                        <i class="bi bi-info-circle text-primary"></i>
                        Cette configuration prépare une intégration générique de type solution compatible / PDP.
                        Le format <strong>UBL 2.1</strong> peut être généré directement. Le format <strong>Factur-X</strong> nécessite un PDF/A-3 embarquant un XML normé, ce qui demandera une couche supplémentaire spécifique.
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card border-0 mb-4">
        <div class="card-header"><i class="bi bi-cash-coin"></i> Modules métier - Paie / Bulletins de paye</div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-4">
                    <div class="form-check form-switch mt-2">
                        <input type="hidden" name="module_paie_actif" value="0">
                        <input class="form-check-input" type="checkbox" name="module_paie_actif" value="1"
                               id="paieModuleSwitch" <?= getParam('module_paie_actif', '0') === '1' ? 'checked' : '' ?>>
                        <label class="form-check-label" for="paieModuleSwitch">
                            <strong>Activer le module paie</strong>
                        </label>
                    </div>
                    <small class="text-muted">Ajoute la gestion des salaires et bulletins dans la navigation.</small>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Base mensuelle d'heures</label>
                    <input type="number" name="paie_jours_travail_mensuel" class="form-control" min="0" step="0.01"
                           value="<?= e(getParam('paie_jours_travail_mensuel', '151.67')) ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Charges salariales (%)</label>
                    <input type="number" name="paie_taux_charges_salariales" class="form-control" min="0" step="0.01"
                           value="<?= e(getParam('paie_taux_charges_salariales', '22')) ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Charges patronales (%)</label>
                    <input type="number" name="paie_taux_charges_patronales" class="form-control" min="0" step="0.01"
                           value="<?= e(getParam('paie_taux_charges_patronales', '42')) ?>">
                </div>
                <div class="col-md-12">
                    <div class="alert alert-light border-0 mb-0">
                        <i class="bi bi-info-circle text-primary"></i>
                        Ce module fournit une base interne de gestion des bulletins de paye par collaborateur :
                        salaire brut, heures supplémentaires, primes, retenues, charges salariales, charges patronales et net à payer.
                        Les taux proposés servent de préremplissage et restent modifiables sur chaque bulletin.
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Forme juridique et régime -->
    <div class="card border-0 mb-4">
        <div class="card-header"><i class="bi bi-bank2"></i> Forme juridique & Régime fiscal</div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">Forme juridique *</label>
                    <select name="forme_juridique" id="formeJuridique" class="form-select">
                        <?php foreach ($formes as $code => $info): ?>
                            <option value="<?= $code ?>" <?= getParam('forme_juridique', 'auto-entrepreneur') === $code ? 'selected' : '' ?>>
                                <?= e($info['label']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Régime fiscal *</label>
                    <select name="regime" id="regimeFiscal" class="form-select">
                        <?php foreach ($formes as $code => $info): ?>
                            <?php foreach ($info['regimes'] as $rCode => $rLabel): ?>
                                <option value="<?= $rCode ?>" 
                                        data-forme="<?= $code ?>"
                                        <?= getParam('regime', 'micro-bnc') === $rCode && getParam('forme_juridique', 'auto-entrepreneur') === $code ? 'selected' : '' ?>>
                                    <?= e($rLabel) ?>
                                </option>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Imposition</label>
                    <select name="regime_imposition" id="regimeImposition" class="form-select">
                        <option value="ir" <?= getParam('regime_imposition', 'ir') === 'ir' ? 'selected' : '' ?>>IR</option>
                        <option value="is" <?= getParam('regime_imposition', 'ir') === 'is' ? 'selected' : '' ?>>IS</option>
                    </select>
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <button type="submit" name="appliquer_defauts" value="1" class="btn btn-outline-warning w-100">
                        <i class="bi bi-arrow-repeat"></i> Défauts
                    </button>
                </div>
            </div>
            <div class="alert alert-info mt-3 mb-0 border-0" id="regimeInfo">
                <i class="bi bi-info-circle"></i> <span id="regimeInfoText"></span>
            </div>
        </div>
    </div>

    <!-- TVA -->
    <div class="card border-0 mb-4" id="blocTva">
        <div class="card-header"><i class="bi bi-receipt"></i> TVA</div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-3">
                    <div class="form-check form-switch mt-2">
                        <input type="hidden" name="tva_applicable" value="0">
                        <input class="form-check-input" type="checkbox" name="tva_applicable" value="1"
                               id="tvaSwitch" <?= getParam('tva_applicable', '0') === '1' ? 'checked' : '' ?>>
                        <label class="form-check-label" for="tvaSwitch">
                            <strong>Assujetti à la TVA</strong>
                        </label>
                    </div>
                    <small class="text-muted" id="tvaHelpText">
                        Franchise en base si CA &lt; seuil
                    </small>
                </div>
                <div class="col-md-3" id="tauxTvaField">
                    <label class="form-label">Taux de TVA principal (%)</label>
                    <select name="taux_tva" class="form-select">
                        <option value="20" <?= getParam('taux_tva', '20') === '20' ? 'selected' : '' ?>>20% (taux normal)</option>
                        <option value="10" <?= getParam('taux_tva', '20') === '10' ? 'selected' : '' ?>>10% (intermédiaire)</option>
                        <option value="5.5" <?= getParam('taux_tva', '20') === '5.5' ? 'selected' : '' ?>>5,5% (réduit)</option>
                        <option value="2.1" <?= getParam('taux_tva', '20') === '2.1' ? 'selected' : '' ?>>2,1% (super-réduit)</option>
                    </select>
                </div>
            </div>
        </div>
    </div>

    <!-- Paramètres micro-entrepreneur -->
    <div class="card border-0 mb-4" id="blocMicro">
        <div class="card-header"><i class="bi bi-percent"></i> Paramètres micro-entrepreneur / auto-entrepreneur</div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">Plafond CA annuel (€)</label>
                    <input type="number" name="plafond_ca" class="form-control" step="100"
                           value="<?= e(getParam('plafond_ca', '77700')) ?>">
                    <small class="text-muted" id="plafondHelp">
                        77 700 € (BNC/BIC services) ou 188 700 € (BIC vente)
                    </small>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Taux d'abattement forfaitaire (%)</label>
                    <input type="number" name="taux_abattement" class="form-control" step="0.1" min="0" max="100"
                           value="<?= e(getParam('taux_abattement', '34')) ?>">
                    <small class="text-muted" id="abattementHelp">
                        34% BNC · 50% BIC services · 71% BIC vente
                    </small>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Taux cotisations URSSAF (%)</label>
                    <input type="number" name="taux_cotisations_sociales" class="form-control" step="0.01" min="0"
                           value="<?= e(getParam('taux_cotisations_sociales', '21.10')) ?>">
                    <small class="text-muted" id="urssafHelp">
                        21,1% BNC · 21,2% BIC services · 12,3% BIC vente
                    </small>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Contribution formation professionnelle (%)</label>
                    <input type="number" name="taux_cfp" class="form-control" step="0.01" min="0"
                           value="<?= e(getParam('taux_cfp', '0.20')) ?>">
                    <small class="text-muted">
                        0,1% commerce · 0,2% libéral · 0,3% artisan
                    </small>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Taux versement libératoire IR (%)</label>
                    <input type="number" name="taux_versement_liberatoire" class="form-control" step="0.01" min="0"
                           value="<?= e(getParam('taux_versement_liberatoire', '2.20')) ?>">
                    <small class="text-muted">
                        1,0% vente · 1,7% BIC services · 2,2% BNC
                    </small>
                </div>
                <div class="col-md-4 d-flex align-items-center">
                    <div class="form-check form-switch mt-3">
                        <input type="hidden" name="versement_liberatoire_actif" value="0">
                        <input class="form-check-input" type="checkbox" name="versement_liberatoire_actif" value="1"
                               id="vlSwitch" <?= getParam('versement_liberatoire_actif', '0') === '1' ? 'checked' : '' ?>>
                        <label class="form-check-label" for="vlSwitch">
                            Versement libératoire activé
                        </label>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Paramètres sociétés -->
    <div class="card border-0 mb-4" id="blocSociete">
        <div class="card-header"><i class="bi bi-building"></i> Paramètres société (régime réel)</div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">Taux IS réduit PME (%)</label>
                    <input type="number" name="taux_is" class="form-control" step="0.5" min="0"
                           value="<?= e(getParam('taux_is', '15')) ?>">
                    <small class="text-muted">15% sur les premiers 42 500 € de résultat, puis 25%</small>
                </div>
                <div class="col-md-8">
                    <div class="alert alert-light border-0 mb-0 mt-2">
                        <i class="bi bi-info-circle text-primary"></i>
                        En régime réel, les charges réelles sont déduites du chiffre d'affaires. 
                        Enregistrez toutes vos dépenses pour calculer le résultat net.
                        <br>Les charges sociales du dirigeant doivent être saisies comme dépenses (catégorie « Cotisations URSSAF » ou « Salaires & charges »).
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Informations selon le régime -->
    <div class="card border-0 mb-4" id="blocInfoMicro">
        <div class="card-header"><i class="bi bi-info-circle"></i> Rappels — Auto-entrepreneur</div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-6">
                    <h6>Obligations comptables simplifiées</h6>
                    <ul class="small">
                        <li><strong>Livre des recettes</strong> : enregistrement chronologique de toutes les recettes encaissées</li>
                        <li><strong>Registre des achats</strong> : obligatoire pour les activités de vente</li>
                        <li><strong>Pas de bilan</strong> ni de compte de résultat</li>
                        <li><strong>Déclaration 2042-C PRO</strong> annuelle (CA brut)</li>
                    </ul>
                </div>
                <div class="col-md-6">
                    <h6>Mentions obligatoires sur les factures</h6>
                    <ul class="small">
                        <li>Date et numéro de facture</li>
                        <li>Identité du prestataire (nom, SIRET)</li>
                        <li>Identité du client</li>
                        <li>Description de la prestation</li>
                        <li>Montant</li>
                        <li id="mentionTva"><em>« TVA non applicable, art. 293 B du CGI »</em></li>
                    </ul>
                </div>
            </div>
        </div>
    </div>

    <div class="card border-0 mb-4" id="blocInfoSociete" style="display:none;">
        <div class="card-header"><i class="bi bi-info-circle"></i> Rappels — Société</div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-6">
                    <h6>Obligations comptables</h6>
                    <ul class="small">
                        <li><strong>Comptabilité complète</strong> : journal, grand livre, balance</li>
                        <li><strong>Bilan</strong> et <strong>compte de résultat</strong> annuels</li>
                        <li><strong>Liasses fiscales</strong> (2065 pour IS, 2031/2035 pour IR)</li>
                        <li>Expert-comptable recommandé</li>
                    </ul>
                </div>
                <div class="col-md-6">
                    <h6>Déclarations</h6>
                    <ul class="small">
                        <li><strong>TVA</strong> : mensuelle ou trimestrielle (CA3/CA12)</li>
                        <li><strong>IS</strong> : acomptes trimestriels + solde</li>
                        <li><strong>CFE</strong> : cotisation foncière annuelle</li>
                        <li><strong>CVAE</strong> : si CA > 500 000 €</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>

    <div class="d-flex justify-content-end gap-2">
        <button type="submit" class="btn btn-primary btn-lg">
            <i class="bi bi-check-lg"></i> Enregistrer les paramètres
        </button>
    </div>
</form>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const formeSelect = document.getElementById('formeJuridique');
    const regimeSelect = document.getElementById('regimeFiscal');
    const impositionSelect = document.getElementById('regimeImposition');
    const blocMicro = document.getElementById('blocMicro');
    const blocSociete = document.getElementById('blocSociete');
    const blocInfoMicro = document.getElementById('blocInfoMicro');
    const blocInfoSociete = document.getElementById('blocInfoSociete');
    const regimeInfo = document.getElementById('regimeInfoText');

    const formesData = <?= json_encode($formes) ?>;

    function updateUI() {
        const forme = formeSelect.value;
        const formeData = formesData[forme];
        if (!formeData) return;

        // Filtrer les options de régime
        const options = regimeSelect.querySelectorAll('option');
        let hasSelected = false;
        options.forEach(opt => {
            const show = opt.dataset.forme === forme;
            opt.style.display = show ? '' : 'none';
            if (!show && opt.selected) opt.selected = false;
            if (show && !hasSelected) { opt.selected = true; hasSelected = true; }
        });

        // Filtrer les options d'imposition
        const impositions = formeData.imposition || ['ir'];
        impositionSelect.querySelectorAll('option').forEach(opt => {
            opt.style.display = impositions.includes(opt.value) ? '' : 'none';
            if (!impositions.includes(opt.value) && opt.selected) {
                opt.selected = false;
            }
        });
        if (!impositions.includes(impositionSelect.value)) {
            impositionSelect.value = impositions[0];
        }

        // Afficher/masquer les blocs
        const isMicro = ['micro-bnc', 'micro-bic-services', 'micro-bic-vente'].includes(regimeSelect.value);
        const isAutoOrEI = ['auto-entrepreneur', 'ei'].includes(forme);

        blocMicro.style.display = isMicro ? '' : 'none';
        blocSociete.style.display = !isMicro ? '' : 'none';
        blocInfoMicro.style.display = (isMicro && isAutoOrEI) ? '' : 'none';
        blocInfoSociete.style.display = !isAutoOrEI ? '' : 'none';

        // Info texte
        const infos = {
            'auto-entrepreneur': 'Régime simplifié avec cotisations proportionnelles au CA. Idéal pour démarrer.',
            'ei': 'Entreprise individuelle, patrimoine professionnel séparé depuis 2022. Choix micro ou réel.',
            'eurl': 'EURL : société unipersonnelle. Option IR possible (EURL à associé unique personne physique).',
            'sarl': 'SARL : société à responsabilité limitée. IS par défaut, option IR possible sous conditions.',
            'sas': 'SAS : grande liberté statutaire. Le dirigeant est assimilé salarié (charges ~80% du net).',
            'sasu': 'SASU : SAS à associé unique. Flexibilité entre rémunération et dividendes.',
        };
        regimeInfo.textContent = infos[forme] || '';
    }

    formeSelect.addEventListener('change', updateUI);
    regimeSelect.addEventListener('change', updateUI);
    updateUI();
});
</script>

<?php include 'footer.php'; ?>

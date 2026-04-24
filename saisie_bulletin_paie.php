<?php
/**
 * Interface de saisie du bulletin de paie — vue plein écran style logiciel RH
 * Gauche : formulaire de saisie structuré | Droite : aperçu feuille de paie en temps réel
 */
$titre = 'Saisie bulletin de paie';
require_once __DIR__ . '/functions_paie.php';
requireLogin();
requirePaieModuleEnabled();

$currentUser = getCurrentUser();
$canManage   = in_array($currentUser['role'] ?? '', ['admin', 'comptable'], true);
if (!$canManage) {
    setFlash('danger', 'Accès réservé.');
    header('Location: ' . BASE_URL . 'paie.php');
    exit;
}

$db = getDB();

// -----------------------------------------------------------------------
// POST — Enregistrement
// -----------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        setFlash('danger', 'Jeton de sécurité invalide.');
        header('Location: ' . BASE_URL . 'saisie_bulletin_paie.php');
        exit;
    }

    $postAction        = $_POST['post_action'] ?? '';
    $userId            = (int) ($_POST['user_id'] ?? 0);
    $periode           = trim($_POST['periode'] ?? '');
    $datePaiement      = trim($_POST['date_paiement'] ?? '');
    $statut            = $_POST['statut'] ?? 'brouillon';
    $modePaiement      = $_POST['mode_paiement'] ?? 'virement';
    $referencePaiement = trim($_POST['reference_paiement'] ?? '');
    $notes             = trim($_POST['notes'] ?? '');

    if ($userId <= 0 || !preg_match('/^\d{4}-\d{2}$/', $periode) || $datePaiement === '') {
        setFlash('danger', 'Collaborateur, période et date de paiement sont obligatoires.');
        header('Location: ' . BASE_URL . 'saisie_bulletin_paie.php?user_id=' . $userId . '&periode=' . urlencode($periode));
        exit;
    }

    if (!in_array($statut, ['brouillon', 'valide', 'paye'], true)) $statut = 'brouillon';
    if (!in_array($modePaiement, ['virement', 'cheque', 'especes', 'autre'], true)) $modePaiement = 'virement';

    $payload = [
        'salaire_base_brut'              => max(0.0, (float) ($_POST['salaire_base_brut'] ?? 0)),
        'heures_travaillees'             => max(0.0, (float) ($_POST['heures_travaillees'] ?? getPaieMonthlyHoursBase())),
        'heures_supplementaires'         => max(0.0, (float) ($_POST['heures_supplementaires'] ?? 0)),
        'taux_horaire_majore'            => max(0.0, (float) ($_POST['taux_horaire_majore'] ?? 0)),
        'montant_heures_supplementaires' => max(0.0, (float) ($_POST['montant_heures_supplementaires'] ?? 0)),
        'prime'                          => (float) ($_POST['prime'] ?? 0),
        'bonus'                          => (float) ($_POST['bonus'] ?? 0),
        'indemnites'                     => (float) ($_POST['indemnites'] ?? 0),
        'retenues'                       => max(0.0, (float) ($_POST['retenues'] ?? 0)),
        'cotisations_salariales'         => max(0.0, (float) ($_POST['cotisations_salariales'] ?? 0)),
        'cotisations_patronales'         => max(0.0, (float) ($_POST['cotisations_patronales'] ?? 0)),
    ];
    $totals  = calculatePaieBulletinTotals($payload);
    $payload = array_merge($payload, $totals);

    ensurePaieProfile($userId);

    if ($postAction === 'save_bulletin') {
        // Vérification doublon
        $chk = $db->prepare('SELECT id FROM bulletins_paie WHERE user_id = ? AND periode = ?');
        $chk->execute([$userId, $periode]);
        if ($chk->fetchColumn()) {
            setFlash('danger', 'Un bulletin existe déjà pour ce collaborateur sur cette période.');
            header('Location: ' . BASE_URL . 'saisie_bulletin_paie.php?user_id=' . $userId . '&periode=' . urlencode($periode));
            exit;
        }
        $stmt = $db->prepare('INSERT INTO bulletins_paie (
                user_id, periode, date_paiement, statut, salaire_base_brut, heures_travaillees,
                heures_supplementaires, taux_horaire_majore, montant_heures_supplementaires,
                prime, bonus, indemnites, retenues, cotisations_salariales, cotisations_patronales,
                salaire_brut, net_imposable, net_a_payer, cout_total_employeur,
                mode_paiement, reference_paiement, notes, created_by
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([
            $userId, $periode, $datePaiement, $statut,
            round($payload['salaire_base_brut'], 2), round($payload['heures_travaillees'], 2),
            round($payload['heures_supplementaires'], 2), round($payload['taux_horaire_majore'], 2),
            $payload['montant_heures_supplementaires'],
            round($payload['prime'], 2), round($payload['bonus'], 2),
            round($payload['indemnites'], 2), round($payload['retenues'], 2),
            round($payload['cotisations_salariales'], 2), round($payload['cotisations_patronales'], 2),
            $payload['salaire_brut'], $payload['net_imposable'],
            $payload['net_a_payer'], $payload['cout_total_employeur'],
            $modePaiement,
            $referencePaiement !== '' ? $referencePaiement : null,
            $notes !== '' ? $notes : null,
            (int) $currentUser['id'],
        ]);
        logActivity((int) $currentUser['id'], 'paie_bulletin', 'Création bulletin de paie', 'user:' . $userId . '|periode:' . $periode);
        setFlash('success', 'Bulletin créé avec succès.');
        header('Location: ' . BASE_URL . 'saisie_bulletin_paie.php?user_id=' . $userId . '&periode=' . urlencode($periode));
        exit;
    }

    if ($postAction === 'update_bulletin') {
        $bulletinId = (int) ($_POST['bulletin_id'] ?? 0);
        if ($bulletinId <= 0) {
            setFlash('danger', 'Bulletin invalide.');
            header('Location: ' . BASE_URL . 'saisie_bulletin_paie.php');
            exit;
        }
        $stmt = $db->prepare('UPDATE bulletins_paie SET
                date_paiement=?, statut=?, salaire_base_brut=?, heures_travaillees=?,
                heures_supplementaires=?, taux_horaire_majore=?, montant_heures_supplementaires=?,
                prime=?, bonus=?, indemnites=?, retenues=?, cotisations_salariales=?,
                cotisations_patronales=?, salaire_brut=?, net_imposable=?, net_a_payer=?,
                cout_total_employeur=?, mode_paiement=?, reference_paiement=?, notes=?
            WHERE id=?');
        $stmt->execute([
            $datePaiement, $statut,
            round($payload['salaire_base_brut'], 2), round($payload['heures_travaillees'], 2),
            round($payload['heures_supplementaires'], 2), round($payload['taux_horaire_majore'], 2),
            $payload['montant_heures_supplementaires'],
            round($payload['prime'], 2), round($payload['bonus'], 2),
            round($payload['indemnites'], 2), round($payload['retenues'], 2),
            round($payload['cotisations_salariales'], 2), round($payload['cotisations_patronales'], 2),
            $payload['salaire_brut'], $payload['net_imposable'],
            $payload['net_a_payer'], $payload['cout_total_employeur'],
            $modePaiement,
            $referencePaiement !== '' ? $referencePaiement : null,
            $notes !== '' ? $notes : null,
            $bulletinId,
        ]);
        logActivity((int) $currentUser['id'], 'paie_bulletin', 'Modification bulletin de paie', 'bulletin:' . $bulletinId);
        setFlash('success', 'Bulletin mis à jour.');
        header('Location: ' . BASE_URL . 'saisie_bulletin_paie.php?user_id=' . $userId . '&periode=' . urlencode($periode));
        exit;
    }
}

// -----------------------------------------------------------------------
// Données de page
// -----------------------------------------------------------------------
$selectedPeriod = (isset($_GET['periode']) && preg_match('/^\d{4}-\d{2}$/', (string) $_GET['periode']))
    ? $_GET['periode'] : date('Y-m');

$profiles       = array_values(array_filter(getPaieProfiles(true)));
$selectedUserId = (int) ($_GET['user_id'] ?? ($profiles[0]['user_id'] ?? 0));
$selectedProfile = $selectedUserId > 0 ? getPaieProfile($selectedUserId) : null;

// Bulletin existant ?
$existingBulletin = null;
if ($selectedUserId > 0) {
    $chk = $db->prepare('SELECT b.*, u.nom AS user_nom, u.email AS user_email, p.poste, p.matricule, p.iban, p.numero_securite_sociale
        FROM bulletins_paie b JOIN users u ON u.id=b.user_id LEFT JOIN paie_profils p ON p.user_id=b.user_id
        WHERE b.user_id=? AND b.periode=?');
    $chk->execute([$selectedUserId, $selectedPeriod]);
    $existingBulletin = $chk->fetch() ?: null;
}

$draft          = $selectedProfile
    ? buildPaieDraftFromProfile($selectedProfile, $selectedPeriod)
    : buildPaieDraftFromProfile(['salaire_base_brut' => 0, 'taux_horaire' => 0], $selectedPeriod);
$fd             = $existingBulletin ?: $draft;   // form data

// Périodes récentes pour navigation
$latestPeriods = getPaieLatestPeriods();
if (!in_array($selectedPeriod, $latestPeriods, true)) array_unshift($latestPeriods, $selectedPeriod);
$latestPeriods = array_slice(array_unique($latestPeriods), 0, 18);

// Infos entreprise
$ent = [
    'nom'       => getParam('nom_entreprise', 'Mon Entreprise'),
    'adresse'   => getParam('adresse', ''),
    'cp'        => getParam('code_postal', ''),
    'ville'     => getParam('ville', ''),
    'siret'     => getParam('siret', ''),
    'naf'       => getParam('code_naf', ''),
    'tel'       => getParam('telephone', ''),
    'email'     => getParam('email', ''),
    'urssaf'    => getParam('numero_urssaf', ''),
    'conv'      => getParam('convention_collective', ''),
];

include __DIR__ . '/header.php';
?>

<style>
/* =====================================================================
   Interface saisie bulletin de paie — plein écran
   ===================================================================== */
:root {
    --paie-bleu:        #003366;
    --paie-bleu-clair:  #dce4f0;
    --paie-bg:          #f0f2f5;
    --paie-card:        #fff;
    --paie-border:      #d0d7e3;
    --paie-section:     #e8eef7;
    --paie-green:       #1a7a4a;
    --paie-red:         #b91c1c;
}

/* Disposition principale */
.paie-workspace {
    display: grid;
    grid-template-columns: 440px 1fr;
    gap: 0;
    min-height: calc(100vh - 130px);
    background: var(--paie-bg);
}

/* ---- Panneau gauche : saisie ---- */
.paie-panel-left {
    background: var(--paie-card);
    border-right: 2px solid var(--paie-bleu);
    display: flex;
    flex-direction: column;
    overflow-y: auto;
    max-height: calc(100vh - 130px);
    position: sticky;
    top: 0;
}

.paie-panel-left::-webkit-scrollbar { width: 5px; }
.paie-panel-left::-webkit-scrollbar-track { background: #f0f2f5; }
.paie-panel-left::-webkit-scrollbar-thumb { background: var(--paie-bleu); border-radius: 3px; }

.paie-panel-header {
    background: var(--paie-bleu);
    color: #fff;
    padding: 14px 20px;
    flex-shrink: 0;
}
.paie-panel-header h2 { font-size: 1rem; margin: 0; font-weight: 700; letter-spacing: .5px; }
.paie-panel-header p  { font-size: 0.75rem; margin: 0; opacity: .8; }

/* Navigation période/collaborateur */
.paie-nav-bar {
    padding: 10px 16px;
    background: #f7f9fc;
    border-bottom: 1px solid var(--paie-border);
    display: flex;
    flex-direction: column;
    gap: 8px;
    flex-shrink: 0;
}
.paie-nav-bar label { font-size: 0.68rem; font-weight: 700; text-transform: uppercase; color: #888; margin-bottom: 2px; }
.paie-nav-bar select { font-size: 0.82rem; }

/* Carte collaborateur */
.paie-emp-card {
    margin: 12px 16px;
    background: var(--paie-section);
    border: 1px solid var(--paie-bleu-clair);
    border-radius: 8px;
    padding: 12px 14px;
    display: flex;
    align-items: center;
    gap: 12px;
    flex-shrink: 0;
}
.paie-emp-avatar {
    width: 42px; height: 42px; border-radius: 50%;
    background: var(--paie-bleu);
    color: #fff;
    display: flex; align-items: center; justify-content: center;
    font-weight: 800; font-size: 1.1rem;
    flex-shrink: 0;
}
.paie-emp-name  { font-weight: 700; font-size: 0.92rem; color: var(--paie-bleu); }
.paie-emp-meta  { font-size: 0.72rem; color: #666; line-height: 1.5; }

/* Sections formulaire */
.paie-form-section {
    border-bottom: 1px solid var(--paie-border);
    padding: 0;
}
.paie-form-section-title {
    background: var(--paie-bleu-clair);
    color: var(--paie-bleu);
    font-size: 0.68rem;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: .8px;
    padding: 6px 16px;
    display: flex;
    align-items: center;
    gap: 6px;
    cursor: pointer;
    user-select: none;
}
.paie-form-section-title .paie-toggle { margin-left: auto; transition: transform .2s; }
.paie-form-section-title.collapsed .paie-toggle { transform: rotate(-90deg); }
.paie-form-body { padding: 12px 16px; }

/* Champs de saisie */
.paie-field {
    margin-bottom: 10px;
}
.paie-field label {
    display: block;
    font-size: 0.72rem;
    font-weight: 600;
    color: #555;
    margin-bottom: 3px;
}
.paie-field label .paie-field-hint {
    font-weight: 400;
    color: #aaa;
    font-size: 0.68rem;
}
.paie-field input,
.paie-field select,
.paie-field textarea {
    width: 100%;
    border: 1px solid #ccd3e0;
    border-radius: 6px;
    padding: 7px 10px;
    font-size: 0.85rem;
    color: #222;
    background: #fff;
    transition: border-color .15s, box-shadow .15s;
    outline: none;
}
.paie-field input:focus,
.paie-field select:focus,
.paie-field textarea:focus {
    border-color: var(--paie-bleu);
    box-shadow: 0 0 0 3px rgba(0,51,102,.12);
}
.paie-field input[data-computed] {
    background: #f5f7fa;
    color: var(--paie-bleu);
    font-weight: 600;
}
.paie-field-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 10px;
}
.paie-field-row-3 {
    display: grid;
    grid-template-columns: 1fr 1fr 1fr;
    gap: 8px;
}

/* Totaux en temps réel (colonne droite du formulaire) */
.paie-live-summary {
    margin: 0 16px 16px;
    background: var(--paie-bleu);
    color: #fff;
    border-radius: 10px;
    padding: 14px 16px;
    flex-shrink: 0;
}
.paie-live-summary .ls-title {
    font-size: 0.68rem; font-weight: 700; text-transform: uppercase;
    opacity: .7; letter-spacing: .5px; margin-bottom: 10px;
}
.paie-live-row {
    display: flex; justify-content: space-between; align-items: center;
    padding: 3px 0;
    font-size: 0.8rem;
}
.paie-live-row .lbl { opacity: .8; }
.paie-live-row .val { font-weight: 600; font-family: 'JetBrains Mono', monospace; }
.paie-live-row.divider { border-top: 1px solid rgba(255,255,255,.2); margin-top: 4px; padding-top: 6px; }
.paie-live-row.net { font-size: 1rem; font-weight: 800; }
.paie-live-row.net .val { font-size: 1.1rem; }

/* Bouton d'action */
.paie-actions {
    padding: 14px 16px;
    border-top: 2px solid var(--paie-bleu);
    background: #f7f9fc;
    display: flex;
    gap: 10px;
    flex-shrink: 0;
}
.paie-btn-save {
    flex: 1;
    background: var(--paie-bleu);
    color: #fff;
    border: none;
    border-radius: 8px;
    padding: 11px 18px;
    font-size: 0.9rem;
    font-weight: 700;
    cursor: pointer;
    display: flex; align-items: center; justify-content: center; gap: 8px;
    transition: background .15s;
}
.paie-btn-save:hover { background: #002244; }
.paie-btn-save:disabled { opacity: .5; cursor: not-allowed; }

/* ---- Panneau droit : aperçu feuille de paie ---- */
.paie-panel-right {
    padding: 24px 28px;
    overflow-y: auto;
    max-height: calc(100vh - 130px);
    background: #e8edf5;
}

/* Barre d'outils aperçu */
.paie-preview-toolbar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 16px;
}
.paie-preview-toolbar h3 {
    font-size: 0.82rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .8px;
    color: var(--paie-bleu);
    margin: 0;
}

/* ===== FEUILLE DE PAIE HTML ===== */
.fiche-paie {
    font-family: 'Arial', Helvetica, sans-serif;
    font-size: 0.79rem;
    color: #222;
    background: #fff;
    border: 1px solid #bbb;
    border-radius: 3px;
    box-shadow: 0 4px 20px rgba(0,0,0,.15);
    max-width: 820px;
    margin: 0 auto;
}

.fiche-paie .fp-hdr {
    display: flex;
    justify-content: space-between;
    padding: 18px 22px 12px;
    border-bottom: 3px solid var(--paie-bleu);
    background: #f5f7fa;
    gap: 12px;
}
.fiche-paie .fp-ent-nom { font-size: 1rem; font-weight: 800; color: var(--paie-bleu); margin-bottom: 3px; }
.fiche-paie .fp-info    { color: #444; font-size: 0.75rem; line-height: 1.65; }
.fiche-paie .fp-muted   { color: #999; font-size: 0.7rem; }
.fiche-paie .fp-right   { text-align: right; min-width: 200px; }
.fiche-paie .fp-doc-title {
    font-size: 1.2rem; font-weight: 900;
    color: var(--paie-bleu); text-transform: uppercase; letter-spacing: 1px;
}
.fiche-paie .fp-periode { font-size: 0.88rem; font-weight: 700; color: #333; margin-top: 4px; }
.fiche-paie .fp-date    { font-size: 0.74rem; color: #777; }
.fiche-paie .fp-emp-title { font-size: 0.65rem; text-transform: uppercase; color: #999; font-weight: 700; margin-top: 10px; letter-spacing: 1px; }
.fiche-paie .fp-emp-name  { font-size: 0.95rem; font-weight: 800; color: #222; }
.fiche-paie .fp-emp-meta  { font-size: 0.72rem; color: #666; line-height: 1.55; }

.fiche-paie .fp-table { width: 100%; border-collapse: collapse; }
.fiche-paie .fp-table thead th {
    background: var(--paie-bleu);
    color: #fff; font-size: 0.7rem; font-weight: 700;
    padding: 5px 10px; text-align: right;
}
.fiche-paie .fp-table thead th:first-child { text-align: left; }
.fiche-paie .fp-table tbody td {
    padding: 4px 10px;
    border-bottom: 1px solid #e8ecf2;
    font-size: 0.76rem; vertical-align: middle;
}
.fiche-paie .fp-table tbody td:not(:first-child) { text-align: right; }
.fiche-paie .fp-table tbody tr:nth-child(even)   { background: #f9fafb; }

.fiche-paie .fp-section-lbl {
    background: #dce4f0; color: var(--paie-bleu);
    font-weight: 800; font-size: 0.67rem;
    text-transform: uppercase; letter-spacing: .5px;
    padding: 4px 10px;
    border-top: 1px solid #b5c8e2;
}
.fiche-paie .fp-section-lbl-light {
    background: #eef2f9; color: #777;
    font-weight: 700; font-size: 0.67rem;
    text-transform: uppercase; letter-spacing: .5px;
    padding: 4px 10px;
    border-top: 1px solid #dde3ef;
}

.fiche-paie .fp-subtotal td {
    background: var(--paie-section) !important;
    font-weight: 700; font-size: 0.8rem; color: var(--paie-bleu);
    border-top: 2px solid var(--paie-bleu) !important;
    padding: 6px 10px;
}
.fiche-paie .fp-net td {
    background: var(--paie-bleu) !important;
    color: #fff !important; font-weight: 900;
    font-size: 0.92rem; border: none;
    padding: 9px 12px;
}
.fiche-paie .fp-net td:not(:first-child) { text-align: right; }

.fiche-paie .fp-footer {
    display: grid; grid-template-columns: 1fr 1fr 1fr;
    border-top: 2px solid var(--paie-bleu);
    background: #f5f7fa;
}
.fiche-paie .fp-footer-col {
    padding: 12px 16px;
    border-right: 1px solid #dde3ef;
}
.fiche-paie .fp-footer-col:last-child { border-right: none; }
.fiche-paie .fp-footer-lbl {
    font-size: 0.65rem; font-weight: 700;
    text-transform: uppercase; color: #999;
    margin-bottom: 5px; letter-spacing: .5px;
}
.fiche-paie .fp-footer-val { font-size: 0.82rem; font-weight: 600; color: #222; }
.fiche-paie .fp-footer-val.big { font-size: 1.3rem; font-weight: 900; color: var(--paie-bleu); }
.fiche-paie .fp-recap-row {
    display: flex; justify-content: space-between; font-size: 0.74rem;
    padding: 2px 0; color: #555;
}
.fiche-paie .fp-recap-row.total { font-weight: 800; color: var(--paie-bleu); font-size: 0.8rem; border-top: 1px solid #ccd; padding-top: 4px; margin-top: 3px; }
.fiche-paie .fp-legal {
    padding: 7px 18px;
    font-size: 0.64rem; color: #bbb; font-style: italic;
    text-align: center;
    border-top: 1px solid #e5e8f0;
}

/* Responsive */
@media (max-width: 1100px) {
    .paie-workspace { grid-template-columns: 1fr; }
    .paie-panel-left { max-height: none; position: relative; border-right: none; border-bottom: 2px solid var(--paie-bleu); }
    .paie-panel-right { max-height: none; }
}
</style>

<?php
// Raccourcis pour le formulaire
$fv = static function(string $key, $default = '') use ($fd): string {
    return (string) ($fd[$key] ?? $default);
};
$fn = static function(string $key, float $default = 0.0, int $dec = 2) use ($fd): string {
    return number_format((float) ($fd[$key] ?? $default), $dec, '.', '');
};
$isEdit    = ($existingBulletin !== null);
$postAct   = $isEdit ? 'update_bulletin' : 'save_bulletin';
$bulletinId = $isEdit ? (int) $existingBulletin['id'] : 0;

// Initiales employé pour avatar
$initiales = '';
if ($selectedProfile) {
    $parts = explode(' ', trim((string) $selectedProfile['nom']));
    foreach ($parts as $p) $initiales .= strtoupper(mb_substr($p, 0, 1));
    $initiales = mb_substr($initiales, 0, 2);
}

$periodeLabel = formatPaiePeriod($selectedPeriod);
$modeLbl = match ((string) ($fd['mode_paiement'] ?? 'virement')) {
    'cheque' => 'Chèque', 'especes' => 'Espèces', 'autre' => 'Autre', default => 'Virement bancaire',
};
$ibanFmt = wordwrap((string) ($selectedProfile['iban'] ?? ''), 4, ' ', true);
?>

<!-- Barre de navigation breadcrumb -->
<div class="d-flex align-items-center justify-content-between mb-3 px-1">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0 small">
            <li class="breadcrumb-item"><a href="<?= BASE_URL ?>paie.php">Bulletins de paie</a></li>
            <li class="breadcrumb-item active">Saisie bulletin</li>
        </ol>
    </nav>
    <?php if ($isEdit): ?>
    <a href="<?= BASE_URL ?>bulletin_paie_pdf.php?id=<?= $bulletinId ?>" target="_blank" class="btn btn-sm btn-danger">
        <i class="bi bi-file-earmark-pdf"></i> Exporter PDF
    </a>
    <?php endif; ?>
</div>

<div class="paie-workspace">

    <!-- ================================================================
         PANNEAU GAUCHE — SAISIE
         ================================================================ -->
    <div class="paie-panel-left">

        <!-- Titre panneau -->
        <div class="paie-panel-header">
            <h2><i class="bi bi-pencil-square me-2"></i><?= $isEdit ? 'Modifier le bulletin' : 'Nouveau bulletin' ?></h2>
            <p><?= $isEdit ? 'Modification d\'un bulletin existant' : 'Création d\'un bulletin de paie mensuel' ?></p>
        </div>

        <!-- Navigation collaborateur / période -->
        <div class="paie-nav-bar">
            <div>
                <label>Collaborateur</label>
                <select id="nav_user" onchange="navigateTo()">
                    <?php foreach ($profiles as $p): ?>
                    <option value="<?= (int) $p['user_id'] ?>" <?= (int) $selectedUserId === (int) $p['user_id'] ? 'selected' : '' ?>>
                        <?= e((string) $p['nom']) ?> <?= !empty($p['poste']) ? '— ' . e((string) $p['poste']) : '' ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Période</label>
                <select id="nav_periode" onchange="navigateTo()">
                    <?php foreach ($latestPeriods as $per): ?>
                    <option value="<?= e($per) ?>" <?= $selectedPeriod === $per ? 'selected' : '' ?>><?= e(formatPaiePeriod($per)) ?></option>
                    <?php endforeach; ?>
                    <option value="__custom__" <?= !in_array($selectedPeriod, $latestPeriods, true) ? 'selected' : '' ?>>Autre période…</option>
                </select>
            </div>
            <div id="custom_periode_wrap" style="<?= !in_array($selectedPeriod, $latestPeriods, true) ? '' : 'display:none' ?>">
                <label>Période personnalisée</label>
                <input type="month" id="custom_periode" value="<?= e($selectedPeriod) ?>" onchange="navigateTo()" style="width:100%;padding:6px 10px;border:1px solid #ccd3e0;border-radius:6px;font-size:.85rem">
            </div>
        </div>

        <!-- Carte employé -->
        <?php if ($selectedProfile): ?>
        <div class="paie-emp-card">
            <div class="paie-emp-avatar"><?= e($initiales) ?></div>
            <div>
                <div class="paie-emp-name"><?= e((string) $selectedProfile['nom']) ?></div>
                <div class="paie-emp-meta">
                    <?php if (!empty($selectedProfile['poste'])): ?><span><?= e((string) $selectedProfile['poste']) ?></span> · <?php endif; ?>
                    <?php if (!empty($selectedProfile['matricule'])): ?>Mat. <?= e((string) $selectedProfile['matricule']) ?> · <?php endif; ?>
                    Brut référence : <strong><?= e(number_format((float) ($selectedProfile['salaire_base_brut'] ?? 0), 2, ',', ' ')) ?> €</strong>
                </div>
            </div>
            <?php if ($isEdit): ?>
            <span class="badge bg-<?= e(getPaieStatusBadgeClass((string) $existingBulletin['statut'])) ?> ms-auto">
                <?= e(getPaieStatusLabel((string) $existingBulletin['statut'])) ?>
            </span>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- ============================================================
             FORMULAIRE DE SAISIE
             ============================================================ -->
        <form method="post" id="bulletinForm">
            <?= csrfField() ?>
            <input type="hidden" name="post_action"  value="<?= $postAct ?>">
            <input type="hidden" name="user_id"      value="<?= (int) $selectedUserId ?>">
            <input type="hidden" name="periode"      value="<?= e($selectedPeriod) ?>">
            <?php if ($isEdit): ?>
            <input type="hidden" name="bulletin_id"  value="<?= $bulletinId ?>">
            <?php endif; ?>

            <!-- ---- Section 1 : Informations générales ---- -->
            <div class="paie-form-section">
                <div class="paie-form-section-title" onclick="toggleSection(this)">
                    <i class="bi bi-calendar3"></i> Informations générales
                    <i class="bi bi-chevron-down paie-toggle"></i>
                </div>
                <div class="paie-form-body">
                    <div class="paie-field-row">
                        <div class="paie-field">
                            <label>Date de paiement</label>
                            <input type="date" name="date_paiement" required value="<?= e($fv('date_paiement')) ?>">
                        </div>
                        <div class="paie-field">
                            <label>Statut</label>
                            <select name="statut">
                                <?php foreach (['brouillon' => 'Brouillon', 'valide' => 'Validé', 'paye' => 'Payé'] as $k => $v): ?>
                                <option value="<?= $k ?>" <?= $fv('statut', 'brouillon') === $k ? 'selected' : '' ?>><?= $v ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="paie-field-row">
                        <div class="paie-field">
                            <label>Mode de paiement</label>
                            <select name="mode_paiement" id="inp_mode_paiement">
                                <?php foreach (['virement' => 'Virement', 'cheque' => 'Chèque', 'especes' => 'Espèces', 'autre' => 'Autre'] as $k => $v): ?>
                                <option value="<?= $k ?>" <?= $fv('mode_paiement', 'virement') === $k ? 'selected' : '' ?>><?= $v ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="paie-field">
                            <label>Référence paiement <span class="paie-field-hint">(optionnel)</span></label>
                            <input type="text" name="reference_paiement" value="<?= e($fv('reference_paiement')) ?>">
                        </div>
                    </div>
                </div>
            </div>

            <!-- ---- Section 2 : Rémunération de base ---- -->
            <div class="paie-form-section">
                <div class="paie-form-section-title" onclick="toggleSection(this)">
                    <i class="bi bi-cash-stack"></i> Rémunération de base
                    <i class="bi bi-chevron-down paie-toggle"></i>
                </div>
                <div class="paie-form-body">
                    <div class="paie-field-row">
                        <div class="paie-field">
                            <label>Salaire brut mensuel <span class="paie-field-hint">€</span></label>
                            <input type="number" name="salaire_base_brut" id="inp_base" min="0" step="0.01"
                                   value="<?= $fn('salaire_base_brut') ?>" oninput="recalc()">
                        </div>
                        <div class="paie-field">
                            <label>Heures travaillées <span class="paie-field-hint">h</span></label>
                            <input type="number" name="heures_travaillees" id="inp_ht" min="0" step="0.01"
                                   value="<?= $fn('heures_travaillees', getPaieMonthlyHoursBase()) ?>" oninput="recalc()">
                        </div>
                    </div>
                    <div class="paie-field">
                        <label>Taux horaire calculé <span class="paie-field-hint">(automatique)</span></label>
                        <input type="text" id="disp_taux_hor" data-computed readonly placeholder="—">
                    </div>
                </div>
            </div>

            <!-- ---- Section 3 : Heures supplémentaires ---- -->
            <div class="paie-form-section">
                <div class="paie-form-section-title" onclick="toggleSection(this)">
                    <i class="bi bi-clock-history"></i> Heures supplémentaires
                    <i class="bi bi-chevron-down paie-toggle"></i>
                </div>
                <div class="paie-form-body">
                    <div class="paie-field-row">
                        <div class="paie-field">
                            <label>Nb heures supp. <span class="paie-field-hint">h</span></label>
                            <input type="number" name="heures_supplementaires" id="inp_hs" min="0" step="0.01"
                                   value="<?= $fn('heures_supplementaires') ?>" oninput="recalc()">
                        </div>
                        <div class="paie-field">
                            <label>Taux horaire majoré <span class="paie-field-hint">€/h</span></label>
                            <input type="number" name="taux_horaire_majore" id="inp_taux_maj" min="0" step="0.0001"
                                   value="<?= $fn('taux_horaire_majore', 0, 4) ?>" oninput="recalc()">
                        </div>
                    </div>
                    <div class="paie-field">
                        <label>Montant heures supp. <span class="paie-field-hint">€ — calculé auto si vide</span></label>
                        <input type="number" name="montant_heures_supplementaires" id="inp_mhs" min="0" step="0.01"
                               value="<?= $fn('montant_heures_supplementaires') ?>" oninput="recalc()">
                    </div>
                </div>
            </div>

            <!-- ---- Section 4 : Éléments variables ---- -->
            <div class="paie-form-section">
                <div class="paie-form-section-title" onclick="toggleSection(this)">
                    <i class="bi bi-plus-slash-minus"></i> Éléments variables
                    <i class="bi bi-chevron-down paie-toggle"></i>
                </div>
                <div class="paie-form-body">
                    <div class="paie-field-row-3">
                        <div class="paie-field">
                            <label>Prime <span class="paie-field-hint">€</span></label>
                            <input type="number" name="prime" id="inp_prime" step="0.01"
                                   value="<?= $fn('prime') ?>" oninput="recalc()">
                        </div>
                        <div class="paie-field">
                            <label>Bonus <span class="paie-field-hint">€</span></label>
                            <input type="number" name="bonus" id="inp_bonus" step="0.01"
                                   value="<?= $fn('bonus') ?>" oninput="recalc()">
                        </div>
                        <div class="paie-field">
                            <label>Indemnités <span class="paie-field-hint">€</span></label>
                            <input type="number" name="indemnites" id="inp_indem" step="0.01"
                                   value="<?= $fn('indemnites') ?>" oninput="recalc()">
                        </div>
                    </div>
                </div>
            </div>

            <!-- ---- Section 5 : Cotisations ---- -->
            <div class="paie-form-section">
                <div class="paie-form-section-title" onclick="toggleSection(this)">
                    <i class="bi bi-pie-chart"></i> Cotisations & retenues
                    <i class="bi bi-chevron-down paie-toggle"></i>
                </div>
                <div class="paie-form-body">
                    <div class="paie-field-row">
                        <div class="paie-field">
                            <label>Cotisations salariales <span class="paie-field-hint">€</span></label>
                            <input type="number" name="cotisations_salariales" id="inp_cotsal" min="0" step="0.01"
                                   value="<?= $fn('cotisations_salariales') ?>" oninput="recalc()">
                        </div>
                        <div class="paie-field">
                            <label>Cotisations patronales <span class="paie-field-hint">€</span></label>
                            <input type="number" name="cotisations_patronales" id="inp_cotpat" min="0" step="0.01"
                                   value="<?= $fn('cotisations_patronales') ?>" oninput="recalc()">
                        </div>
                    </div>
                    <div class="paie-field">
                        <label>Retenues diverses <span class="paie-field-hint">€ — ex : avances, absences</span></label>
                        <input type="number" name="retenues" id="inp_ret" min="0" step="0.01"
                               value="<?= $fn('retenues') ?>" oninput="recalc()">
                    </div>
                    <div class="paie-field-row">
                        <div class="paie-field">
                            <label>Taux salarial calculé <span class="paie-field-hint">%</span></label>
                            <input type="text" id="disp_taux_sal" data-computed readonly placeholder="—">
                        </div>
                        <div class="paie-field">
                            <label>Taux patronal calculé <span class="paie-field-hint">%</span></label>
                            <input type="text" id="disp_taux_pat" data-computed readonly placeholder="—">
                        </div>
                    </div>
                </div>
            </div>

            <!-- ---- Section 6 : Notes ---- -->
            <div class="paie-form-section">
                <div class="paie-form-section-title collapsed" onclick="toggleSection(this)">
                    <i class="bi bi-chat-left-text"></i> Notes internes
                    <i class="bi bi-chevron-down paie-toggle" style="transform:rotate(-90deg)"></i>
                </div>
                <div class="paie-form-body" style="display:none">
                    <div class="paie-field">
                        <textarea name="notes" rows="3" placeholder="Commentaires internes, informations complémentaires…"><?= e($fv('notes')) ?></textarea>
                    </div>
                </div>
            </div>

            <!-- Récapitulatif live -->
            <div class="paie-live-summary">
                <div class="ls-title"><i class="bi bi-calculator me-1"></i>Résumé en temps réel</div>
                <div class="paie-live-row"><span class="lbl">Salaire brut</span><span class="val" id="ls_brut">—</span></div>
                <div class="paie-live-row"><span class="lbl">Cotisations salariales</span><span class="val" id="ls_cotsal" style="color:#ffa0a0">—</span></div>
                <div class="paie-live-row divider"><span class="lbl">Net imposable</span><span class="val" id="ls_netimp">—</span></div>
                <div class="paie-live-row"><span class="lbl">Retenues</span><span class="val" id="ls_ret" style="color:#ffa0a0">—</span></div>
                <div class="paie-live-row divider net"><span class="lbl">Net à payer</span><span class="val" id="ls_netpay">—</span></div>
                <div class="paie-live-row" style="margin-top:8px;font-size:0.74rem;opacity:.6"><span class="lbl">Coût employeur</span><span class="val" id="ls_cout">—</span></div>
            </div>

            <!-- Boutons d'action -->
            <div class="paie-actions">
                <?php if ($isEdit): ?>
                <button type="submit" class="paie-btn-save">
                    <i class="bi bi-floppy2"></i> Enregistrer les modifications
                </button>
                <a href="<?= BASE_URL ?>paie.php?user_id=<?= (int) $selectedUserId ?>&periode=<?= urlencode($selectedPeriod) ?>" class="btn btn-outline-secondary btn-sm">
                    Retour
                </a>
                <?php else: ?>
                <button type="submit" class="paie-btn-save">
                    <i class="bi bi-plus-circle"></i> Créer le bulletin
                </button>
                <?php endif; ?>
            </div>

        </form>
    </div><!-- /paie-panel-left -->

    <!-- ================================================================
         PANNEAU DROIT — APERÇU FEUILLE DE PAIE (temps réel)
         ================================================================ -->
    <div class="paie-panel-right">
        <div class="paie-preview-toolbar">
            <h3><i class="bi bi-eye me-1"></i> Aperçu feuille de paie</h3>
            <small class="text-muted">Mis à jour en temps réel</small>
        </div>

        <div class="fiche-paie" id="fichePreview">

            <!-- EN-TÊTE -->
            <div class="fp-hdr">
                <div>
                    <div class="fp-ent-nom"><?= e($ent['nom']) ?></div>
                    <div class="fp-info">
                        <?php if ($ent['adresse']): ?><?= e($ent['adresse']) ?><br><?php endif; ?>
                        <?php if ($ent['cp'] || $ent['ville']): ?><?= e(trim($ent['cp'] . ' ' . $ent['ville'])) ?><br><?php endif; ?>
                        <?php if ($ent['tel']): ?>Tél. : <?= e($ent['tel']) ?><br><?php endif; ?>
                        <?php if ($ent['email']): ?><?= e($ent['email']) ?><br><?php endif; ?>
                    </div>
                    <?php if ($ent['siret']): ?><div class="fp-muted">SIRET : <?= e($ent['siret']) ?></div><?php endif; ?>
                    <?php if ($ent['naf']): ?><div class="fp-muted">Code APE/NAF : <?= e($ent['naf']) ?></div><?php endif; ?>
                    <?php if ($ent['urssaf']): ?><div class="fp-muted">N° URSSAF : <?= e($ent['urssaf']) ?></div><?php endif; ?>
                    <?php if ($ent['conv']): ?><div class="fp-muted" style="font-style:italic">Conv. coll. : <?= e($ent['conv']) ?></div><?php endif; ?>
                </div>
                <div class="fp-right">
                    <div class="fp-doc-title">Bulletin de Paie</div>
                    <div class="fp-periode" id="pv_periode"><?= e($periodeLabel) ?></div>
                    <div class="fp-date" id="pv_date_paie"><?= $fv('date_paiement') ? e(date('d/m/Y', strtotime($fv('date_paiement')))) : '—' ?></div>
                    <?php if ($selectedProfile): ?>
                    <div class="fp-emp-title">Employé</div>
                    <div class="fp-emp-name"><?= e((string) $selectedProfile['nom']) ?></div>
                    <div class="fp-emp-meta">
                        <?php if (!empty($selectedProfile['poste'])): ?>Poste : <?= e((string) $selectedProfile['poste']) ?><br><?php endif; ?>
                        <?php if (!empty($selectedProfile['matricule'])): ?>Matricule : <?= e((string) $selectedProfile['matricule']) ?><br><?php endif; ?>
                        <?php if (!empty($selectedProfile['numero_securite_sociale'])): ?><span style="color:#bbb;font-size:.68rem">N° SS : <?= e((string) $selectedProfile['numero_securite_sociale']) ?></span><?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- TABLEAU -->
            <table class="fp-table">
                <thead>
                    <tr>
                        <th style="width:48%;text-align:left">Désignation</th>
                        <th style="width:18%">Base</th>
                        <th style="width:14%">Taux</th>
                        <th style="width:20%">Montant</th>
                    </tr>
                </thead>
                <tbody id="pv_tbody">
                    <!-- Rempli par JS -->
                </tbody>
            </table>

            <!-- PIED DE FICHE -->
            <div class="fp-footer">
                <div class="fp-footer-col">
                    <div class="fp-footer-lbl">Mode de paiement</div>
                    <div class="fp-footer-val" id="pv_mode">—</div>
                    <?php if ($ibanFmt): ?>
                    <div class="fp-footer-lbl" style="margin-top:8px">IBAN</div>
                    <div class="fp-footer-val" style="font-size:0.72rem;word-break:break-all"><?= e($ibanFmt) ?></div>
                    <?php endif; ?>
                    <?php if ($fv('reference_paiement')): ?>
                    <div class="fp-footer-lbl" style="margin-top:8px">Référence</div>
                    <div class="fp-footer-val" id="pv_ref"><?= e($fv('reference_paiement')) ?></div>
                    <?php endif; ?>
                </div>
                <div class="fp-footer-col">
                    <div class="fp-footer-lbl">Récapitulatif</div>
                    <div id="pv_recap"></div>
                </div>
                <div class="fp-footer-col" style="text-align:center;display:flex;flex-direction:column;align-items:center;justify-content:center">
                    <div class="fp-footer-lbl">Net à payer</div>
                    <div class="fp-footer-val big" id="pv_net_big">—</div>
                    <?php if ($isEdit): ?>
                    <div style="margin-top:8px">
                        <a href="<?= BASE_URL ?>bulletin_paie_pdf.php?id=<?= $bulletinId ?>" target="_blank" class="btn btn-danger btn-sm">
                            <i class="bi bi-file-earmark-pdf"></i> PDF
                        </a>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="fp-legal">
                Ce bulletin de paie doit être conservé sans limitation de durée (art. L. 3243-4 du Code du travail). En cas de litige, il peut être produit en justice.
            </div>

        </div><!-- /fiche-paie -->
    </div><!-- /paie-panel-right -->

</div><!-- /paie-workspace -->

<script>
(function () {
    'use strict';

    // ---- Navigation collaborateur/période ----
    window.navigateTo = function () {
        var uid = document.getElementById('nav_user').value;
        var selPer = document.getElementById('nav_periode');
        var per = selPer.value;
        if (per === '__custom__') {
            document.getElementById('custom_periode_wrap').style.display = '';
            per = document.getElementById('custom_periode').value || per;
            if (per === '__custom__') return;
        } else {
            document.getElementById('custom_periode_wrap').style.display = 'none';
        }
        window.location.href = '<?= BASE_URL ?>saisie_bulletin_paie.php?user_id=' + uid + '&periode=' + encodeURIComponent(per);
    };

    // ---- Toggle section formulaire ----
    window.toggleSection = function (titleEl) {
        var body = titleEl.nextElementSibling;
        var icon = titleEl.querySelector('.paie-toggle');
        var collapsed = titleEl.classList.toggle('collapsed');
        body.style.display = collapsed ? 'none' : '';
        icon.style.transform = collapsed ? 'rotate(-90deg)' : '';
    };

    // ---- Formatage ----
    function fmt(n) {
        return n.toLocaleString('fr-FR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + '\u00a0\u20ac';
    }
    function fmtPct(n) {
        return n.toLocaleString('fr-FR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + '\u00a0%';
    }
    function fmtH(n) {
        return n.toLocaleString('fr-FR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + '\u00a0h';
    }
    function v(id) {
        var el = document.getElementById(id);
        return el ? (Math.max(0, parseFloat(el.value) || 0)) : 0;
    }

    // ---- Calcul principal ----
    function calcTotals() {
        var base    = v('inp_base');
        var ht      = v('inp_ht');
        var hs      = v('inp_hs');
        var tauxMaj = v('inp_taux_maj');
        var mhs     = v('inp_mhs');
        var prime   = parseFloat(document.getElementById('inp_prime').value) || 0;
        var bonus   = parseFloat(document.getElementById('inp_bonus').value) || 0;
        var indem   = parseFloat(document.getElementById('inp_indem').value) || 0;
        var cotsal  = v('inp_cotsal');
        var cotpat  = v('inp_cotpat');
        var ret     = v('inp_ret');

        if (mhs <= 0 && hs > 0 && tauxMaj > 0) mhs = hs * tauxMaj;

        var brut    = base + mhs + prime + bonus + indem;
        var netimp  = Math.max(0, brut - cotsal);
        var netpay  = Math.max(0, netimp - ret);
        var cout    = Math.max(0, brut + cotpat);
        var tauxH   = ht > 0 ? base / ht : 0;
        var tauxSal = brut > 0 ? (cotsal / brut * 100) : 0;
        var tauxPat = brut > 0 ? (cotpat / brut * 100) : 0;

        return { base, ht, hs, tauxMaj, mhs, prime, bonus, indem, cotsal, cotpat, ret, brut, netimp, netpay, cout, tauxH, tauxSal, tauxPat };
    }

    // ---- Mise à jour panneau résumé gauche ----
    function updateSummary(t) {
        setText('ls_brut',  fmt(t.brut));
        setText('ls_cotsal', '\u2212 ' + fmt(t.cotsal));
        setText('ls_netimp', fmt(t.netimp));
        setText('ls_ret',    t.ret > 0 ? '\u2212 ' + fmt(t.ret) : '—');
        setText('ls_netpay', fmt(t.netpay));
        setText('ls_cout',   fmt(t.cout));

        // Champs calculés
        setVal('disp_taux_hor', t.tauxH > 0 ? t.tauxH.toLocaleString('fr-FR', {minimumFractionDigits:4, maximumFractionDigits:4}) + ' €/h' : '—');
        setVal('disp_taux_sal', t.brut > 0 ? fmtPct(t.tauxSal) : '—');
        setVal('disp_taux_pat', t.brut > 0 ? fmtPct(t.tauxPat) : '—');
    }

    // ---- Mise à jour aperçu feuille ----
    function updatePreview(t) {
        // Grand net
        setText('pv_net_big', fmt(t.netpay));

        // Tableau
        var tbody = document.getElementById('pv_tbody');
        if (!tbody) return;
        var rows = '';

        rows += '<tr><td colspan="4" class="fp-section-lbl">Rémunération</td></tr>';

        rows += '<tr><td>Salaire de base</td><td>' + fmtH(t.ht) + '</td><td>' +
                (t.tauxH > 0 ? t.tauxH.toLocaleString('fr-FR',{minimumFractionDigits:4,maximumFractionDigits:4}) : '—') +
                '</td><td>' + fmt(t.base) + '</td></tr>';

        if (t.mhs > 0) {
            rows += '<tr><td>Heures suppl\u00e9mentaires</td><td>' + fmtH(t.hs) + '</td><td>' +
                    (t.tauxMaj > 0 ? t.tauxMaj.toLocaleString('fr-FR',{minimumFractionDigits:2,maximumFractionDigits:2}) : '—') +
                    '</td><td>' + fmt(t.mhs) + '</td></tr>';
        }
        if (t.prime !== 0) rows += '<tr><td>Prime</td><td></td><td></td><td>' + fmt(t.prime) + '</td></tr>';
        if (t.bonus !== 0) rows += '<tr><td>Bonus</td><td></td><td></td><td>' + fmt(t.bonus) + '</td></tr>';
        if (t.indem !== 0) rows += '<tr><td>Indemnit\u00e9s</td><td></td><td></td><td>' + fmt(t.indem) + '</td></tr>';

        rows += '<tr class="fp-subtotal"><td colspan="3">Salaire brut</td><td>' + fmt(t.brut) + '</td></tr>';

        rows += '<tr><td colspan="4" class="fp-section-lbl">Cotisations salariales (part employ\u00e9)</td></tr>';
        rows += '<tr><td>Cotisations sociales salariales</td><td>' + fmt(t.brut) + '</td><td>' +
                (t.brut > 0 ? fmtPct(t.tauxSal) : '—') +
                '</td><td style="color:#b91c1c">\u2212\u00a0' + fmt(t.cotsal) + '</td></tr>';
        rows += '<tr class="fp-subtotal"><td colspan="3">Net imposable</td><td>' + fmt(t.netimp) + '</td></tr>';

        if (t.ret > 0) {
            rows += '<tr><td colspan="4" class="fp-section-lbl">Retenues</td></tr>';
            rows += '<tr><td>Retenues diverses</td><td></td><td></td><td style="color:#b91c1c">\u2212\u00a0' + fmt(t.ret) + '</td></tr>';
        }

        rows += '<tr class="fp-net"><td colspan="3">\u2714\ufe0e Net \u00e0 payer en euros</td><td>' + fmt(t.netpay) + '</td></tr>';

        rows += '<tr><td colspan="4" class="fp-section-lbl-light">Cotisations patronales (informatif)</td></tr>';
        rows += '<tr><td>Charges patronales</td><td>' + fmt(t.brut) + '</td><td>' +
                (t.brut > 0 ? fmtPct(t.tauxPat) : '—') +
                '</td><td>' + fmt(t.cotpat) + '</td></tr>';
        rows += '<tr class="fp-subtotal"><td colspan="3">Co\u00fbt total employeur</td><td>' + fmt(t.cout) + '</td></tr>';

        tbody.innerHTML = rows;

        // Récap
        var recap = document.getElementById('pv_recap');
        if (recap) {
            recap.innerHTML =
                '<div class="fp-recap-row"><span>Salaire brut</span><span>' + fmt(t.brut) + '</span></div>' +
                '<div class="fp-recap-row"><span>Cotisations salariales</span><span style="color:#b91c1c">\u2212 ' + fmt(t.cotsal) + '</span></div>' +
                '<div class="fp-recap-row"><span>Net imposable</span><span>' + fmt(t.netimp) + '</span></div>' +
                (t.ret > 0 ? '<div class="fp-recap-row"><span>Retenues</span><span style="color:#b91c1c">\u2212 ' + fmt(t.ret) + '</span></div>' : '') +
                '<div class="fp-recap-row total"><span>Net \u00e0 payer</span><span>' + fmt(t.netpay) + '</span></div>';
        }

        // Mode paiement
        var modeEl = document.getElementById('pv_mode');
        if (modeEl) {
            var modeMap = { virement:'Virement bancaire', cheque:'Ch\u00e8que', especes:'Esp\u00e8ces', autre:'Autre' };
            var modeVal = (document.getElementById('inp_mode_paiement') || {}).value || 'virement';
            modeEl.textContent = modeMap[modeVal] || modeVal;
        }
    }

    function setText(id, txt) {
        var el = document.getElementById(id);
        if (el) el.textContent = txt;
    }
    function setVal(id, val) {
        var el = document.getElementById(id);
        if (el) el.value = val;
    }

    // ---- Recalcul global ----
    window.recalc = function () {
        var t = calcTotals();
        updateSummary(t);
        updatePreview(t);
    };

    // ---- Date paiement → aperçu ----
    var dpEl = document.querySelector('[name="date_paiement"]');
    if (dpEl) dpEl.addEventListener('change', function () {
        var pv = document.getElementById('pv_date_paie');
        if (pv && this.value) {
            var d = new Date(this.value + 'T00:00:00');
            pv.textContent = ('0'+d.getDate()).slice(-2) + '/' + ('0'+(d.getMonth()+1)).slice(-2) + '/' + d.getFullYear();
        }
    });

    // Mode paiement
    var mpEl = document.getElementById('inp_mode_paiement');
    if (mpEl) mpEl.addEventListener('change', recalc);

    // ---- Calcul initial ----
    recalc();
})();
</script>

<?php include __DIR__ . '/footer.php'; ?>

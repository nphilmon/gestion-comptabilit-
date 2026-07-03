<?php
/**
 * Module RH - Gestion des bulletins de paye
 */

$titre = 'Bulletins de paye';
require_once __DIR__ . '/functions_paie.php';

requireLogin();
requirePaieModuleEnabled();

$currentUser = getCurrentUser();
$canManagePaie = in_array($currentUser['role'] ?? '', ['admin', 'comptable'], true);
$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        setFlash('danger', 'Jeton de sécurité invalide.');
        header('Location: ' . BASE_URL . 'paie.php');
        exit;
    }

    $postAction = $_POST['post_action'] ?? '';

    if ($postAction === 'save_profile' && $canManagePaie) {
        $userId = (int) ($_POST['user_id'] ?? 0);
        $matricule = trim($_POST['matricule'] ?? '');
        $poste = trim($_POST['poste'] ?? '');
        $dateEntree = trim($_POST['date_entree'] ?? '');
        $salaireBaseBrut = max(0, (float) ($_POST['salaire_base_brut'] ?? 0));
        $tauxHoraire = max(0, (float) ($_POST['taux_horaire'] ?? 0));
        $iban = strtoupper(trim($_POST['iban'] ?? ''));
        $numeroSecu = preg_replace('/\s+/', '', trim($_POST['numero_securite_sociale'] ?? '')) ?? '';
        $actif = isset($_POST['actif']) ? 1 : 0;
        $notes = trim($_POST['notes'] ?? '');

        if ($userId <= 0) {
            setFlash('danger', 'Collaborateur invalide pour le profil de paie.');
            header('Location: ' . BASE_URL . 'paie.php');
            exit;
        }

        ensurePaieProfile($userId);
        $stmt = $db->prepare('UPDATE paie_profils
            SET matricule = ?, poste = ?, date_entree = ?, salaire_base_brut = ?, taux_horaire = ?,
                iban = ?, numero_securite_sociale = ?, actif = ?, notes = ?
            WHERE user_id = ?');
        $stmt->execute([
            $matricule !== '' ? $matricule : null,
            $poste !== '' ? $poste : null,
            $dateEntree !== '' ? $dateEntree : null,
            $salaireBaseBrut,
            $tauxHoraire,
            $iban !== '' ? $iban : null,
            $numeroSecu !== '' ? $numeroSecu : null,
            $actif,
            $notes !== '' ? $notes : null,
            $userId,
        ]);

        logActivity((int) $currentUser['id'], 'paie_profil', 'Mise à jour d\'un profil de paie', 'user:' . $userId);
        setFlash('success', 'Profil de paie enregistré.');
        header('Location: ' . BASE_URL . 'paie.php?user_id=' . $userId);
        exit;
    }

    if ($postAction === 'save_bulletin' && $canManagePaie) {
        $userId = (int) ($_POST['user_id'] ?? 0);
        $periode = trim($_POST['periode'] ?? '');
        $datePaiement = trim($_POST['date_paiement'] ?? '');
        $statut = $_POST['statut'] ?? 'brouillon';
        $notes = trim($_POST['notes'] ?? '');
        $modePaiement = $_POST['mode_paiement'] ?? 'virement';
        $referencePaiement = trim($_POST['reference_paiement'] ?? '');

        if ($userId <= 0 || !preg_match('/^\d{4}-\d{2}$/', $periode) || $datePaiement === '') {
            setFlash('danger', 'Collaborateur, période et date de paiement sont obligatoires.');
            header('Location: ' . BASE_URL . 'paie.php');
            exit;
        }

        if (!in_array($statut, ['brouillon', 'valide', 'paye'], true)) {
            $statut = 'brouillon';
        }

        if (!in_array($modePaiement, ['virement', 'cheque', 'especes', 'autre'], true)) {
            $modePaiement = 'virement';
        }

        $check = $db->prepare('SELECT id FROM bulletins_paie WHERE user_id = ? AND periode = ?');
        $check->execute([$userId, $periode]);
        if ($check->fetchColumn()) {
            setFlash('danger', 'Un bulletin existe déjà pour ce collaborateur sur cette période.');
            header('Location: ' . BASE_URL . 'paie.php?user_id=' . $userId . '&periode=' . urlencode($periode));
            exit;
        }

        $payload = [
            'salaire_base_brut' => (float) ($_POST['salaire_base_brut'] ?? 0),
            'heures_travaillees' => (float) ($_POST['heures_travaillees'] ?? getPaieMonthlyHoursBase()),
            'heures_supplementaires' => (float) ($_POST['heures_supplementaires'] ?? 0),
            'taux_horaire_majore' => (float) ($_POST['taux_horaire_majore'] ?? 0),
            'montant_heures_supplementaires' => (float) ($_POST['montant_heures_supplementaires'] ?? 0),
            'prime' => (float) ($_POST['prime'] ?? 0),
            'bonus' => (float) ($_POST['bonus'] ?? 0),
            'indemnites' => (float) ($_POST['indemnites'] ?? 0),
            'retenues' => (float) ($_POST['retenues'] ?? 0),
            'cotisations_salariales' => (float) ($_POST['cotisations_salariales'] ?? 0),
            'cotisations_patronales' => (float) ($_POST['cotisations_patronales'] ?? 0),
        ];
        $totals = calculatePaieBulletinTotals($payload);
        $payload = array_merge($payload, $totals);

        ensurePaieProfile($userId);
        $stmt = $db->prepare('INSERT INTO bulletins_paie (
                user_id, periode, date_paiement, statut, salaire_base_brut, heures_travaillees,
                heures_supplementaires, taux_horaire_majore, montant_heures_supplementaires, prime,
                bonus, indemnites, retenues, cotisations_salariales, cotisations_patronales,
                salaire_brut, net_imposable, net_a_payer, cout_total_employeur, mode_paiement,
                reference_paiement, notes, created_by
            ) VALUES (
                ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
            )');
        $stmt->execute([
            $userId,
            $periode,
            $datePaiement,
            $statut,
            round($payload['salaire_base_brut'], 2),
            round($payload['heures_travaillees'], 2),
            round($payload['heures_supplementaires'], 2),
            round($payload['taux_horaire_majore'], 2),
            $payload['montant_heures_supplementaires'],
            round($payload['prime'], 2),
            round($payload['bonus'], 2),
            round($payload['indemnites'], 2),
            round($payload['retenues'], 2),
            round($payload['cotisations_salariales'], 2),
            round($payload['cotisations_patronales'], 2),
            $payload['salaire_brut'],
            $payload['net_imposable'],
            $payload['net_a_payer'],
            $payload['cout_total_employeur'],
            $modePaiement,
            $referencePaiement !== '' ? $referencePaiement : null,
            $notes !== '' ? $notes : null,
            (int) $currentUser['id'],
        ]);

        logActivity((int) $currentUser['id'], 'paie_bulletin', 'Création d\'un bulletin de paye', 'user:' . $userId . '|periode:' . $periode);
        setFlash('success', 'Bulletin de paye créé avec succès.');
        header('Location: ' . BASE_URL . 'paie.php?user_id=' . $userId . '&periode=' . urlencode($periode));
        exit;
    }

    if ($postAction === 'update_status' && $canManagePaie) {
        $bulletinId = (int) ($_POST['bulletin_id'] ?? 0);
        $status = $_POST['status'] ?? 'brouillon';

        if (!in_array($status, ['brouillon', 'valide', 'paye'], true)) {
            setFlash('danger', 'Statut de bulletin invalide.');
            header('Location: ' . BASE_URL . 'paie.php');
            exit;
        }

        $stmt = $db->prepare('UPDATE bulletins_paie SET statut = ? WHERE id = ?');
        $stmt->execute([$status, $bulletinId]);

        setFlash('success', 'Statut du bulletin mis à jour.');
        header('Location: ' . BASE_URL . 'paie.php');
        exit;
    }

    if ($postAction === 'update_bulletin' && $canManagePaie) {
        $bulletinId   = (int) ($_POST['bulletin_id'] ?? 0);
        $datePaiement = trim($_POST['date_paiement'] ?? '');
        $statut       = $_POST['statut'] ?? 'brouillon';
        $notes        = trim($_POST['notes'] ?? '');
        $modePaiement = $_POST['mode_paiement'] ?? 'virement';
        $referencePaiement = trim($_POST['reference_paiement'] ?? '');

        if ($bulletinId <= 0 || $datePaiement === '') {
            setFlash('danger', 'Bulletin ou date de paiement invalide.');
            header('Location: ' . BASE_URL . 'paie.php');
            exit;
        }

        $existCheck = $db->prepare('SELECT user_id, periode FROM bulletins_paie WHERE id = ?');
        $existCheck->execute([$bulletinId]);
        $bRow = $existCheck->fetch();
        if (!$bRow) {
            setFlash('danger', 'Bulletin introuvable.');
            header('Location: ' . BASE_URL . 'paie.php');
            exit;
        }

        if (!in_array($statut, ['brouillon', 'valide', 'paye'], true)) {
            $statut = 'brouillon';
        }
        if (!in_array($modePaiement, ['virement', 'cheque', 'especes', 'autre'], true)) {
            $modePaiement = 'virement';
        }

        $payload = [
            'salaire_base_brut'              => (float) ($_POST['salaire_base_brut'] ?? 0),
            'heures_travaillees'             => (float) ($_POST['heures_travaillees'] ?? getPaieMonthlyHoursBase()),
            'heures_supplementaires'         => (float) ($_POST['heures_supplementaires'] ?? 0),
            'taux_horaire_majore'            => (float) ($_POST['taux_horaire_majore'] ?? 0),
            'montant_heures_supplementaires' => (float) ($_POST['montant_heures_supplementaires'] ?? 0),
            'prime'                          => (float) ($_POST['prime'] ?? 0),
            'bonus'                          => (float) ($_POST['bonus'] ?? 0),
            'indemnites'                     => (float) ($_POST['indemnites'] ?? 0),
            'retenues'                       => (float) ($_POST['retenues'] ?? 0),
            'cotisations_salariales'         => (float) ($_POST['cotisations_salariales'] ?? 0),
            'cotisations_patronales'         => (float) ($_POST['cotisations_patronales'] ?? 0),
        ];
        $totals  = calculatePaieBulletinTotals($payload);
        $payload = array_merge($payload, $totals);

        $stmt = $db->prepare('UPDATE bulletins_paie SET
                date_paiement = ?, statut = ?, salaire_base_brut = ?, heures_travaillees = ?,
                heures_supplementaires = ?, taux_horaire_majore = ?, montant_heures_supplementaires = ?,
                prime = ?, bonus = ?, indemnites = ?, retenues = ?, cotisations_salariales = ?,
                cotisations_patronales = ?, salaire_brut = ?, net_imposable = ?, net_a_payer = ?,
                cout_total_employeur = ?, mode_paiement = ?, reference_paiement = ?, notes = ?
            WHERE id = ?');
        $stmt->execute([
            $datePaiement,
            $statut,
            round($payload['salaire_base_brut'], 2),
            round($payload['heures_travaillees'], 2),
            round($payload['heures_supplementaires'], 2),
            round($payload['taux_horaire_majore'], 2),
            $payload['montant_heures_supplementaires'],
            round($payload['prime'], 2),
            round($payload['bonus'], 2),
            round($payload['indemnites'], 2),
            round($payload['retenues'], 2),
            round($payload['cotisations_salariales'], 2),
            round($payload['cotisations_patronales'], 2),
            $payload['salaire_brut'],
            $payload['net_imposable'],
            $payload['net_a_payer'],
            $payload['cout_total_employeur'],
            $modePaiement,
            $referencePaiement !== '' ? $referencePaiement : null,
            $notes !== '' ? $notes : null,
            $bulletinId,
        ]);

        logActivity((int) $currentUser['id'], 'paie_bulletin', 'Modification d\'un bulletin de paye', 'bulletin:' . $bulletinId);
        setFlash('success', 'Bulletin de paye mis à jour avec succès.');
        header('Location: ' . BASE_URL . 'paie.php?user_id=' . (int) $bRow['user_id'] . '&periode=' . urlencode((string) $bRow['periode']));
        exit;
    }

    if ($postAction === 'delete_bulletin' && $canManagePaie) {
        $bulletinId = (int) ($_POST['bulletin_id'] ?? 0);
        if ($bulletinId <= 0) {
            setFlash('danger', 'Bulletin invalide.');
            header('Location: ' . BASE_URL . 'paie.php');
            exit;
        }
        $check = $db->prepare('SELECT user_id, periode FROM bulletins_paie WHERE id = ?');
        $check->execute([$bulletinId]);
        $bRow = $check->fetch();
        if (!$bRow) {
            setFlash('danger', 'Bulletin introuvable.');
            header('Location: ' . BASE_URL . 'paie.php');
            exit;
        }
        $db->prepare('DELETE FROM bulletins_paie WHERE id = ?')->execute([$bulletinId]);
        logActivity((int) $currentUser['id'], 'paie_bulletin', 'Suppression d\'un bulletin de paye', 'bulletin:' . $bulletinId);
        setFlash('success', 'Bulletin supprimé avec succès.');
        header('Location: ' . BASE_URL . 'paie.php?user_id=' . (int) $bRow['user_id'] . '&periode=' . urlencode((string) $bRow['periode']));
        exit;
    }
}

$selectedPeriod = isset($_GET['periode']) && preg_match('/^\d{4}-\d{2}$/', (string) $_GET['periode'])
    ? $_GET['periode']
    : date('Y-m');

$profiles = $canManagePaie ? getPaieProfiles(true) : [getPaieProfile((int) $currentUser['id'])];
$profiles = array_values(array_filter($profiles));

$selectedUserId = $canManagePaie
    ? (int) ($_GET['user_id'] ?? ($profiles[0]['user_id'] ?? 0))
    : (int) $currentUser['id'];

$selectedProfile = $selectedUserId > 0 ? getPaieProfile($selectedUserId) : null;
if (!$selectedProfile && !$canManagePaie) {
    $selectedProfile = getPaieProfile((int) $currentUser['id']);
}

$stats = getPaieDashboardStats($selectedPeriod);
$latestPeriods = getPaieLatestPeriods();
if (!in_array($selectedPeriod, $latestPeriods, true)) {
    array_unshift($latestPeriods, $selectedPeriod);
}
$latestPeriods = array_slice(array_values(array_unique($latestPeriods)), 0, 12);

$filterStatut = isset($_GET['statut_filter']) && in_array($_GET['statut_filter'], ['brouillon', 'valide', 'paye'], true)
    ? $_GET['statut_filter'] : '';
$bulletinFilters = ['periode' => $selectedPeriod];
if ($filterStatut !== '') {
    $bulletinFilters['statut'] = $filterStatut;
}
if (!$canManagePaie) {
    $bulletinFilters['user_id'] = (int) $currentUser['id'];
} elseif ($selectedUserId > 0) {
    $bulletinFilters['user_id'] = $selectedUserId;
}
$bulletins = getPaieBulletins($bulletinFilters);

$existingBulletin = null;
foreach ($bulletins as $bulletin) {
    if ((int) $bulletin['user_id'] === (int) $selectedUserId && ($bulletin['periode'] ?? '') === $selectedPeriod) {
        $existingBulletin = $bulletin;
        break;
    }
}

$draft = $selectedProfile ? buildPaieDraftFromProfile($selectedProfile, $selectedPeriod) : buildPaieDraftFromProfile(['salaire_base_brut' => 0, 'taux_horaire' => 0], $selectedPeriod);
$bulletinFormData = $existingBulletin ?: $draft;

include __DIR__ . '/header.php';
?>

<?php
// ----- Données entreprise pour l'aperçu HTML -----
$entNom       = getParam('nom_entreprise', 'Mon Entreprise');
$entAdresse   = getParam('adresse', '');
$entCp        = getParam('code_postal', '');
$entVille     = getParam('ville', '');
$entSiret     = getParam('siret', '');
$entNaf       = getParam('code_naf', '');
$entTel       = getParam('telephone', '');
$entEmail     = getParam('email', '');
$entUrssaf    = getParam('numero_urssaf', '');
$entConv      = getParam('convention_collective', '');
?>
<style>
/* ===== Feuille de Paie CSS ===== */
.fiche-paie {
    font-family: 'Arial', sans-serif;
    font-size: 0.78rem;
    color: #222;
    background: #fff;
    border: 1px solid #bbb;
    border-radius: 4px;
    padding: 0;
    max-width: 900px;
    margin: 0 auto;
    box-shadow: 0 2px 12px rgba(0,0,0,.10);
}
.fiche-paie .fp-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    padding: 18px 24px 10px;
    border-bottom: 3px solid #003366;
    background: #f5f7fa;
}
.fiche-paie .fp-header .fp-emp-nom {
    font-size: 1.05rem;
    font-weight: 700;
    color: #003366;
    margin-bottom: 2px;
}
.fiche-paie .fp-header .fp-info-line {
    color: #444;
    line-height: 1.6;
}
.fiche-paie .fp-header .fp-muted {
    color: #888;
    font-size: 0.72rem;
}
.fiche-paie .fp-titre-doc {
    text-align: right;
}
.fiche-paie .fp-titre-doc .fp-doc-title {
    font-size: 1.3rem;
    font-weight: 800;
    color: #003366;
    letter-spacing: 1px;
    text-transform: uppercase;
}
.fiche-paie .fp-titre-doc .fp-periode-label {
    font-size: 0.92rem;
    font-weight: 600;
    color: #333;
}
.fiche-paie .fp-titre-doc .fp-date-paie {
    font-size: 0.78rem;
    color: #666;
}
.fiche-paie .fp-employe-bloc {
    text-align: right;
}
.fiche-paie .fp-employe-bloc .fp-emp-titre {
    font-size: 0.68rem;
    text-transform: uppercase;
    color: #888;
    font-weight: 700;
    letter-spacing: 1px;
}
.fiche-paie .fp-employe-bloc .fp-emp-name {
    font-size: 1rem;
    font-weight: 700;
    color: #222;
}
.fiche-paie .fp-employe-bloc .fp-emp-info {
    color: #555;
    font-size: 0.75rem;
    line-height: 1.6;
}
.fiche-paie .fp-section-title {
    background: #dce4f0;
    color: #003366;
    font-weight: 700;
    font-size: 0.7rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    padding: 4px 12px;
    border-top: 1px solid #b5c8e2;
}
.fiche-paie .fp-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.77rem;
}
.fiche-paie .fp-table thead th {
    background: #003366;
    color: #fff;
    font-weight: 700;
    padding: 5px 10px;
    text-align: right;
    border: none;
    font-size: 0.72rem;
    letter-spacing: 0.3px;
}
.fiche-paie .fp-table thead th:first-child {
    text-align: left;
}
.fiche-paie .fp-table tbody tr td {
    padding: 4px 10px;
    border-bottom: 1px solid #e8ecf2;
    vertical-align: middle;
}
.fiche-paie .fp-table tbody tr td:not(:first-child) {
    text-align: right;
}
.fiche-paie .fp-table tbody tr:nth-child(odd) {
    background: #f9fafb;
}
.fiche-paie .fp-table tbody tr:hover {
    background: #eef2f9;
}
.fiche-paie .fp-total-row td {
    font-weight: 700;
    font-size: 0.82rem;
    color: #003366;
    background: #e8eef7 !important;
    border-top: 2px solid #003366;
    padding: 6px 10px;
}
.fiche-paie .fp-net-row td {
    background: #003366 !important;
    color: #fff !important;
    font-weight: 800;
    font-size: 0.92rem;
    border: none;
    padding: 8px 12px;
}
.fiche-paie .fp-net-row td:not(:first-child) {
    text-align: right;
}
.fiche-paie .fp-footer-bloc {
    display: flex;
    gap: 0;
    border-top: 2px solid #003366;
    background: #f5f7fa;
}
.fiche-paie .fp-footer-bloc .fp-footer-col {
    flex: 1;
    padding: 12px 18px;
    border-right: 1px solid #dde3ef;
}
.fiche-paie .fp-footer-bloc .fp-footer-col:last-child {
    border-right: none;
}
.fiche-paie .fp-footer-bloc .fp-footer-label {
    font-size: 0.68rem;
    text-transform: uppercase;
    color: #888;
    font-weight: 700;
    margin-bottom: 5px;
}
.fiche-paie .fp-footer-bloc .fp-footer-value {
    font-size: 0.82rem;
    font-weight: 600;
    color: #222;
}
.fiche-paie .fp-footer-bloc .fp-footer-value.big {
    font-size: 1.1rem;
    color: #003366;
}
.fiche-paie .fp-footer-bloc .fp-recap-table {
    width: 100%;
    font-size: 0.75rem;
}
.fiche-paie .fp-footer-bloc .fp-recap-table td {
    padding: 1px 0;
}
.fiche-paie .fp-footer-bloc .fp-recap-table td:last-child {
    text-align: right;
    font-weight: 600;
}
.fiche-paie .fp-legal {
    padding: 8px 18px;
    font-size: 0.66rem;
    color: #aaa;
    font-style: italic;
    text-align: center;
    border-top: 1px solid #e0e4ee;
}
.fiche-paie .fp-statut-badge {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 10px;
    font-size: 0.7rem;
    font-weight: 700;
    text-transform: uppercase;
}
</style>

<div class="hero-banner mb-4">
    <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3">
        <div>
            <h1 class="mb-1"><i class="bi bi-cash-coin"></i> Bulletins de paye</h1>
            <p class="mb-0 text-muted">Gestion des salaires, profils collaborateurs et bulletins mensuels.</p>
        </div>
        <div class="d-flex align-items-center gap-2">
            <?php if ($canManagePaie): ?>
            <a href="<?= BASE_URL ?>saisie_bulletin_paie.php?user_id=<?= (int) $selectedUserId ?>&periode=<?= urlencode($selectedPeriod) ?>" class="btn btn-primary">
                <i class="bi bi-plus-circle"></i> Saisir un bulletin
            </a>
            <?php endif; ?>
            <div class="text-lg-end">
                <div class="small text-uppercase text-muted">Période suivie</div>
                <div class="fw-semibold"><?= e(formatPaiePeriod($selectedPeriod)) ?></div>
                <div class="small text-muted">Base mensuelle : <?= e(number_format(getPaieMonthlyHoursBase(), 2, ',', ' ')) ?> h</div>
                <div class="small text-muted">Taux indicatifs : <?= e(number_format(getPaieDefaultEmployeeChargeRate(), 2, ',', ' ')) ?>% salariales / <?= e(number_format(getPaieDefaultEmployerChargeRate(), 2, ',', ' ')) ?>% patronales</div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="card stat-card border-0 h-100">
            <div class="card-body">
                <div class="d-flex align-items-center mb-2">
                    <div class="stat-icon bg-primary-subtle text-primary"><i class="bi bi-people-fill"></i></div>
                    <small class="text-muted ms-2">Collaborateurs suivis</small>
                </div>
                <h3 class="mb-1"><?= (int) $stats['employees'] ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card border-0 h-100">
            <div class="card-body">
                <div class="d-flex align-items-center mb-2">
                    <div class="stat-icon bg-primary-subtle text-primary"><i class="bi bi-file-earmark-text-fill"></i></div>
                    <small class="text-muted ms-2">Bulletins sur la période</small>
                </div>
                <h3 class="text-primary mb-1"><?= (int) $stats['bulletins_count'] ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card border-0 h-100">
            <div class="card-body">
                <div class="d-flex align-items-center mb-2">
                    <div class="stat-icon bg-success-subtle text-success"><i class="bi bi-cash-stack"></i></div>
                    <small class="text-muted ms-2">Net à payer total</small>
                </div>
                <h3 class="text-success mb-1"><?= e(number_format((float) $stats['net_total'], 2, ',', ' ')) ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card border-0 h-100">
            <div class="card-body">
                <div class="d-flex align-items-center mb-2">
                    <div class="stat-icon bg-danger-subtle text-danger"><i class="bi bi-building"></i></div>
                    <small class="text-muted ms-2">Coût employeur total</small>
                </div>
                <h3 class="text-danger mb-1"><?= e(number_format((float) $stats['employer_total'], 2, ',', ' ')) ?></h3>
                <small class="text-muted"><?= (int) $stats['drafts_count'] ?> brouillon(s)</small>
            </div>
        </div>
    </div>
</div>

<?php if ($existingBulletin && $canManagePaie): ?>
<div class="alert alert-info border-0 mb-4 d-flex justify-content-between align-items-center">
    <span>
        <i class="bi bi-pencil-square"></i>
        Bulletin de <strong><?= e((string) ($selectedProfile['nom'] ?? 'ce collaborateur')) ?></strong> pour <?= e(formatPaiePeriod($selectedPeriod)) ?> — mode <strong>modification</strong>.
        Statut actuel&nbsp;: <span class="badge bg-<?= e(getPaieStatusBadgeClass((string) $existingBulletin['statut'])) ?>"><?= e(getPaieStatusLabel((string) $existingBulletin['statut'])) ?></span>
    </span>
    <a href="<?= BASE_URL ?>bulletin_paie_pdf.php?id=<?= (int) $existingBulletin['id'] ?>" target="_blank" class="btn btn-danger btn-sm ms-3">
        <i class="bi bi-file-earmark-pdf"></i> Télécharger PDF
    </a>
</div>
<?php endif; ?>

<?php
// ===== APERÇU FEUILLE DE PAIE =====
if ($existingBulletin):
    $bp = $existingBulletin;
    $bpBrut    = (float) $bp['salaire_brut'];
    $bpNetImp  = (float) $bp['net_imposable'];
    $bpNetPay  = (float) $bp['net_a_payer'];
    $bpCoutEmp = (float) $bp['cout_total_employeur'];
    $bpSalarBrut = (float) $bp['salaire_base_brut'];
    $bpHT      = (float) $bp['heures_travaillees'];
    $bpHS      = (float) $bp['heures_supplementaires'];
    $bpTauxMaj = (float) $bp['taux_horaire_majore'];
    $bpMontHS  = (float) $bp['montant_heures_supplementaires'];
    $bpPrime   = (float) $bp['prime'];
    $bpBonus   = (float) $bp['bonus'];
    $bpIndem   = (float) $bp['indemnites'];
    $bpCotSal  = (float) $bp['cotisations_salariales'];
    $bpCotPat  = (float) $bp['cotisations_patronales'];
    $bpRetenues = (float) $bp['retenues'];
    $bpTauxSal  = $bpBrut > 0 ? round($bpCotSal / $bpBrut * 100, 2) : 0;
    $bpTauxPat  = $bpBrut > 0 ? round($bpCotPat / $bpBrut * 100, 2) : 0;
    $bpTauxHor  = $bpHT > 0 ? round($bpSalarBrut / $bpHT, 4) : 0;
    $bpModeLbl  = match ((string) $bp['mode_paiement']) {
        'cheque'  => 'Chèque',
        'especes' => 'Espèces',
        'autre'   => 'Autre',
        default   => 'Virement bancaire',
    };
    $periodeLabel = formatPaiePeriod((string) $bp['periode']);
    $datePaie     = formatDate((string) $bp['date_paiement']);
    $ibanFormate  = $selectedProfile ? wordwrap((string) ($selectedProfile['iban'] ?? ''), 4, ' ', true) : '';
    $secuEmp      = $selectedProfile ? (string) ($selectedProfile['numero_securite_sociale'] ?? '') : '';
?>
<div class="card border-0 shadow mb-4">
    <div class="card-header d-flex justify-content-between align-items-center py-2">
        <span class="fw-semibold"><i class="bi bi-file-person"></i> Aperçu feuille de paie</span>
        <a href="<?= BASE_URL ?>bulletin_paie_pdf.php?id=<?= (int) $bp['id'] ?>" target="_blank" class="btn btn-danger btn-sm">
            <i class="bi bi-file-earmark-pdf"></i> Exporter PDF
        </a>
    </div>
    <div class="card-body p-3">
    <div class="fiche-paie">

        <!-- EN-TÊTE -->
        <div class="fp-header">
            <div>
                <div class="fp-emp-nom"><?= e($entNom) ?></div>
                <div class="fp-info-line">
                    <?php if ($entAdresse !== ''): ?><?= e($entAdresse) ?><br><?php endif; ?>
                    <?php if ($entCp !== '' || $entVille !== ''): ?><?= e(trim($entCp . ' ' . $entVille)) ?><br><?php endif; ?>
                    <?php if ($entTel !== ''): ?>Tél. : <?= e($entTel) ?><br><?php endif; ?>
                    <?php if ($entEmail !== ''): ?><?= e($entEmail) ?><br><?php endif; ?>
                </div>
                <?php if ($entSiret !== ''): ?><div class="fp-muted">SIRET : <?= e($entSiret) ?></div><?php endif; ?>
                <?php if ($entNaf !== ''): ?><div class="fp-muted">Code APE/NAF : <?= e($entNaf) ?></div><?php endif; ?>
                <?php if ($entUrssaf !== ''): ?><div class="fp-muted">N° URSSAF : <?= e($entUrssaf) ?></div><?php endif; ?>
                <?php if ($entConv !== ''): ?><div class="fp-muted" style="font-style:italic">Conv. coll. : <?= e($entConv) ?></div><?php endif; ?>
            </div>
            <div style="text-align:right; min-width:220px;">
                <div class="fp-titre-doc">
                    <div class="fp-doc-title">Bulletin de Paie</div>
                    <div class="fp-periode-label">Période : <?= e($periodeLabel) ?></div>
                    <div class="fp-date-paie">Date de paiement : <?= e($datePaie) ?></div>
                </div>
                <div class="fp-employe-bloc mt-2">
                    <div class="fp-emp-titre">Employé</div>
                    <div class="fp-emp-name"><?= e((string) ($bp['user_nom'] ?? '')) ?></div>
                    <div class="fp-emp-info">
                        <?php if (!empty($bp['poste'])): ?>Poste : <?= e((string) $bp['poste']) ?><br><?php endif; ?>
                        <?php if (!empty($bp['matricule'])): ?>Matricule : <?= e((string) $bp['matricule']) ?><br><?php endif; ?>
                        <?php if ($secuEmp !== ''): ?><span style="color:#aaa;font-size:0.7rem">N° SS : <?= e($secuEmp) ?></span><?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- TABLEAU PRINCIPAL -->
        <table class="fp-table">
            <thead>
                <tr>
                    <th style="width:50%;text-align:left">Désignation</th>
                    <th style="width:17%">Base</th>
                    <th style="width:13%">Taux</th>
                    <th style="width:20%">Montant</th>
                </tr>
            </thead>
            <tbody>
                <!-- Section rémunération -->
                <tr><td colspan="4" class="fp-section-title">Rémunération</td></tr>
                <tr>
                    <td>Salaire de base</td>
                    <td><?= e(number_format($bpHT, 2, ',', ' ')) ?> h</td>
                    <td><?= $bpTauxHor > 0 ? e(number_format($bpTauxHor, 4, ',', ' ')) : '—' ?></td>
                    <td><?= e(number_format($bpSalarBrut, 2, ',', ' ')) ?> €</td>
                </tr>
                <?php if ($bpHS > 0): ?>
                <tr>
                    <td>Heures supplémentaires</td>
                    <td><?= e(number_format($bpHS, 2, ',', ' ')) ?> h</td>
                    <td><?= $bpTauxMaj > 0 ? e(number_format($bpTauxMaj, 2, ',', ' ')) : '—' ?></td>
                    <td><?= e(number_format($bpMontHS, 2, ',', ' ')) ?> €</td>
                </tr>
                <?php endif; ?>
                <?php if ($bpPrime > 0): ?>
                <tr>
                    <td>Prime</td>
                    <td></td><td></td>
                    <td><?= e(number_format($bpPrime, 2, ',', ' ')) ?> €</td>
                </tr>
                <?php endif; ?>
                <?php if ($bpBonus > 0): ?>
                <tr>
                    <td>Bonus</td>
                    <td></td><td></td>
                    <td><?= e(number_format($bpBonus, 2, ',', ' ')) ?> €</td>
                </tr>
                <?php endif; ?>
                <?php if ($bpIndem > 0): ?>
                <tr>
                    <td>Indemnités</td>
                    <td></td><td></td>
                    <td><?= e(number_format($bpIndem, 2, ',', ' ')) ?> €</td>
                </tr>
                <?php endif; ?>
                <!-- SALAIRE BRUT -->
                <tr class="fp-total-row">
                    <td colspan="3">Salaire brut</td>
                    <td><?= e(number_format($bpBrut, 2, ',', ' ')) ?> €</td>
                </tr>

                <!-- Section cotisations salariales -->
                <tr><td colspan="4" class="fp-section-title">Cotisations salariales (part employé)</td></tr>
                <tr>
                    <td>Cotisations sociales salariales</td>
                    <td><?= e(number_format($bpBrut, 2, ',', ' ')) ?> €</td>
                    <td><?= $bpTauxSal > 0 ? e(number_format($bpTauxSal, 2, ',', ' ')) . ' %' : '—' ?></td>
                    <td style="color:#c0392b">- <?= e(number_format($bpCotSal, 2, ',', ' ')) ?> €</td>
                </tr>
                <!-- NET IMPOSABLE -->
                <tr class="fp-total-row">
                    <td colspan="3">Net imposable</td>
                    <td><?= e(number_format($bpNetImp, 2, ',', ' ')) ?> €</td>
                </tr>

                <?php if ($bpRetenues > 0): ?>
                <!-- Section retenues -->
                <tr><td colspan="4" class="fp-section-title">Retenues</td></tr>
                <tr>
                    <td>Retenues diverses</td>
                    <td></td><td></td>
                    <td style="color:#c0392b">- <?= e(number_format($bpRetenues, 2, ',', ' ')) ?> €</td>
                </tr>
                <?php endif; ?>

                <!-- NET À PAYER -->
                <tr class="fp-net-row">
                    <td colspan="3"><i class="bi bi-check-circle-fill me-1"></i> Net à payer en euros</td>
                    <td><?= e(number_format($bpNetPay, 2, ',', ' ')) ?> €</td>
                </tr>

                <!-- Section cotisations patronales -->
                <tr><td colspan="4" class="fp-section-title" style="background:#e8eef7;color:#555">Cotisations patronales (part employeur — informatif)</td></tr>
                <tr>
                    <td>Charges patronales</td>
                    <td><?= e(number_format($bpBrut, 2, ',', ' ')) ?> €</td>
                    <td><?= $bpTauxPat > 0 ? e(number_format($bpTauxPat, 2, ',', ' ')) . ' %' : '—' ?></td>
                    <td><?= e(number_format($bpCotPat, 2, ',', ' ')) ?> €</td>
                </tr>
                <tr class="fp-total-row">
                    <td colspan="3">Coût total employeur</td>
                    <td><?= e(number_format($bpCoutEmp, 2, ',', ' ')) ?> €</td>
                </tr>
            </tbody>
        </table>

        <?php if (!empty($bp['notes'])): ?>
        <div style="padding:8px 14px;background:#fffbea;font-size:0.75rem;color:#666;border-top:1px solid #e0d89a;font-style:italic">
            <i class="bi bi-info-circle"></i> Note : <?= e((string) $bp['notes']) ?>
        </div>
        <?php endif; ?>

        <!-- PIED RÉCAPITULATIF -->
        <div class="fp-footer-bloc">
            <div class="fp-footer-col">
                <div class="fp-footer-label">Mode de paiement</div>
                <div class="fp-footer-value"><?= e($bpModeLbl) ?></div>
                <?php if ($ibanFormate !== ''): ?>
                <div class="fp-footer-label mt-2">IBAN</div>
                <div class="fp-footer-value" style="font-size:0.72rem;word-break:break-all"><?= e($ibanFormate) ?></div>
                <?php endif; ?>
                <?php if (!empty($bp['reference_paiement'])): ?>
                <div class="fp-footer-label mt-2">Référence</div>
                <div class="fp-footer-value"><?= e((string) $bp['reference_paiement']) ?></div>
                <?php endif; ?>
            </div>
            <div class="fp-footer-col">
                <div class="fp-footer-label">Récapitulatif</div>
                <table class="fp-recap-table">
                    <tr><td class="text-muted">Salaire brut</td><td><?= e(number_format($bpBrut, 2, ',', ' ')) ?> €</td></tr>
                    <tr><td class="text-muted">Cotisations salariales</td><td style="color:#c0392b">- <?= e(number_format($bpCotSal, 2, ',', ' ')) ?> €</td></tr>
                    <tr><td class="text-muted">Net imposable</td><td><?= e(number_format($bpNetImp, 2, ',', ' ')) ?> €</td></tr>
                    <?php if ($bpRetenues > 0): ?>
                    <tr><td class="text-muted">Retenues</td><td style="color:#c0392b">- <?= e(number_format($bpRetenues, 2, ',', ' ')) ?> €</td></tr>
                    <?php endif; ?>
                    <tr style="border-top:1px solid #ccc;font-size:0.8rem"><td class="fw-bold" style="color:#003366">Net à payer</td><td class="fw-bold" style="color:#003366"><?= e(number_format($bpNetPay, 2, ',', ' ')) ?> €</td></tr>
                </table>
            </div>
            <div class="fp-footer-col" style="text-align:center;display:flex;flex-direction:column;justify-content:center;align-items:center">
                <div class="fp-footer-label">Net à payer</div>
                <div class="fp-footer-value big"><?= e(number_format($bpNetPay, 2, ',', ' ')) ?> €</div>
                <div class="mt-2">
                    <span class="fp-statut-badge bg-<?= e(getPaieStatusBadgeClass((string) $bp['statut'])) ?> text-white">
                        <?= e(getPaieStatusLabel((string) $bp['statut'])) ?>
                    </span>
                </div>
                <div class="mt-2">
                    <a href="<?= BASE_URL ?>bulletin_paie_pdf.php?id=<?= (int) $bp['id'] ?>" target="_blank" class="btn btn-danger btn-sm">
                        <i class="bi bi-file-earmark-pdf"></i> PDF
                    </a>
                </div>
            </div>
        </div>

        <div class="fp-legal">
            Ce bulletin de paie doit être conservé sans limitation de durée (art. L. 3243-4 du Code du travail).
            En cas de litige, il peut être produit en justice.
        </div>

    </div><!-- /fiche-paie -->
    </div>
</div>
<?php endif; // existingBulletin ?>

<div class="row g-4">
    <?php if ($canManagePaie): ?>
    <div class="col-xl-5">
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-person-vcard"></i> Profil collaborateur</span>
                <form method="get" class="d-flex gap-2">
                    <input type="hidden" name="periode" value="<?= e($selectedPeriod) ?>">
                    <select name="user_id" class="form-select form-select-sm" onchange="this.form.submit()">
                        <?php foreach ($profiles as $profile): ?>
                        <option value="<?= (int) $profile['user_id'] ?>" <?= (int) $selectedUserId === (int) $profile['user_id'] ? 'selected' : '' ?>>
                            <?= e((string) $profile['nom']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </form>
            </div>
            <div class="card-body">
                <?php if ($selectedProfile): ?>
                <form method="post" class="row g-3">
                    <?= csrfField() ?>
                    <input type="hidden" name="post_action" value="save_profile">
                    <input type="hidden" name="user_id" value="<?= (int) $selectedProfile['user_id'] ?>">

                    <div class="col-md-4">
                        <label class="form-label">Matricule</label>
                        <input type="text" name="matricule" class="form-control" value="<?= e((string) ($selectedProfile['matricule'] ?? '')) ?>">
                    </div>
                    <div class="col-md-8">
                        <label class="form-label">Poste</label>
                        <input type="text" name="poste" class="form-control" value="<?= e((string) ($selectedProfile['poste'] ?? '')) ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Date d'entrée</label>
                        <input type="date" name="date_entree" class="form-control" value="<?= e((string) ($selectedProfile['date_entree'] ?? '')) ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Salaire brut mensuel</label>
                        <input type="number" name="salaire_base_brut" class="form-control" min="0" step="0.01" value="<?= e(number_format((float) ($selectedProfile['salaire_base_brut'] ?? 0), 2, '.', '')) ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Taux horaire</label>
                        <input type="number" name="taux_horaire" class="form-control" min="0" step="0.01" value="<?= e(number_format((float) ($selectedProfile['taux_horaire'] ?? 0), 2, '.', '')) ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">IBAN</label>
                        <input type="text" name="iban" class="form-control" value="<?= e((string) ($selectedProfile['iban'] ?? '')) ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">N° sécurité sociale</label>
                        <input type="text" name="numero_securite_sociale" class="form-control" value="<?= e((string) ($selectedProfile['numero_securite_sociale'] ?? '')) ?>">
                    </div>
                    <div class="col-12">
                        <label class="form-label">Notes</label>
                        <textarea name="notes" class="form-control" rows="3"><?= e((string) ($selectedProfile['notes'] ?? '')) ?></textarea>
                    </div>
                    <div class="col-12 d-flex justify-content-between align-items-center">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="actif" id="actifProfileSwitch" <?= !empty($selectedProfile['actif']) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="actifProfileSwitch">Collaborateur actif dans le module paie</label>
                        </div>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-save"></i> Enregistrer le profil
                        </button>
                    </div>
                </form>
                <?php else: ?>
                <p class="text-muted mb-0">Aucun collaborateur disponible pour la paie.</p>
                <?php endif; ?>
            </div>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-header"><i class="bi bi-people"></i> Récapitulatif collaborateurs</div>
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Collaborateur</th>
                            <th>Poste</th>
                            <th>Brut mensuel</th>
                            <th>État</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($profiles as $profile): ?>
                        <tr>
                            <td>
                                <a href="<?= BASE_URL ?>paie.php?user_id=<?= (int) $profile['user_id'] ?>&periode=<?= urlencode($selectedPeriod) ?>" class="text-decoration-none">
                                    <?= e((string) $profile['nom']) ?>
                                </a>
                            </td>
                            <td><?= e((string) ($profile['poste'] ?? '')) ?></td>
                            <td><?= e(formatMontant((float) ($profile['salaire_base_brut'] ?? 0))) ?></td>
                            <td><span class="badge bg-<?= !empty($profile['actif']) ? 'success' : 'secondary' ?>"><?= !empty($profile['actif']) ? 'Actif' : 'Inactif' ?></span></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-xl-7">
        <div class="card border-0 shadow-sm">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-file-earmark-ruled"></i> <?= $existingBulletin ? 'Modifier le bulletin' : 'Nouveau bulletin' ?></span>
                <form method="get" class="d-flex gap-2 align-items-center">
                    <input type="hidden" name="user_id" value="<?= (int) $selectedUserId ?>">
                    <select name="periode" class="form-select form-select-sm" onchange="this.form.submit()">
                        <?php foreach ($latestPeriods as $period): ?>
                        <option value="<?= e($period) ?>" <?= $selectedPeriod === $period ? 'selected' : '' ?>><?= e(formatPaiePeriod($period)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </form>
            </div>
            <div class="card-body">
                <?php if ($selectedProfile): ?>
                <form method="post" class="row g-3" id="bulletinForm">
                    <?= csrfField() ?>
                    <input type="hidden" name="post_action" value="<?= $existingBulletin ? 'update_bulletin' : 'save_bulletin' ?>">
                    <input type="hidden" name="user_id" value="<?= (int) $selectedUserId ?>">
                    <?php if ($existingBulletin): ?><input type="hidden" name="bulletin_id" value="<?= (int) $existingBulletin['id'] ?>"><?php endif; ?>

                    <div class="col-md-4">
                        <label class="form-label">Période</label>
                        <input type="month" name="periode" class="form-control" value="<?= e((string) $bulletinFormData['periode']) ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Date de paiement</label>
                        <input type="date" name="date_paiement" class="form-control" value="<?= e((string) $bulletinFormData['date_paiement']) ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Statut</label>
                        <select name="statut" class="form-select">
                            <?php foreach (['brouillon' => 'Brouillon', 'valide' => 'Validé', 'paye' => 'Payé'] as $value => $label): ?>
                            <option value="<?= $value ?>" <?= ($bulletinFormData['statut'] ?? 'brouillon') === $value ? 'selected' : '' ?>><?= $label ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">Salaire brut mensuel</label>
                        <input type="number" name="salaire_base_brut" class="form-control" min="0" step="0.01" value="<?= e(number_format((float) ($bulletinFormData['salaire_base_brut'] ?? 0), 2, '.', '')) ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Heures travaillées</label>
                        <input type="number" name="heures_travaillees" class="form-control" min="0" step="0.01" value="<?= e(number_format((float) ($bulletinFormData['heures_travaillees'] ?? getPaieMonthlyHoursBase()), 2, '.', '')) ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Heures supplémentaires</label>
                        <input type="number" name="heures_supplementaires" class="form-control" min="0" step="0.01" value="<?= e(number_format((float) ($bulletinFormData['heures_supplementaires'] ?? 0), 2, '.', '')) ?>">
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">Taux horaire majoré</label>
                        <input type="number" name="taux_horaire_majore" class="form-control" min="0" step="0.01" value="<?= e(number_format((float) ($bulletinFormData['taux_horaire_majore'] ?? 0), 2, '.', '')) ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Montant heures supp.</label>
                        <input type="number" name="montant_heures_supplementaires" class="form-control" min="0" step="0.01" value="<?= e(number_format((float) ($bulletinFormData['montant_heures_supplementaires'] ?? 0), 2, '.', '')) ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Prime</label>
                        <input type="number" name="prime" class="form-control" step="0.01" value="<?= e(number_format((float) ($bulletinFormData['prime'] ?? 0), 2, '.', '')) ?>">
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">Bonus</label>
                        <input type="number" name="bonus" class="form-control" step="0.01" value="<?= e(number_format((float) ($bulletinFormData['bonus'] ?? 0), 2, '.', '')) ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Indemnités</label>
                        <input type="number" name="indemnites" class="form-control" step="0.01" value="<?= e(number_format((float) ($bulletinFormData['indemnites'] ?? 0), 2, '.', '')) ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Retenues</label>
                        <input type="number" name="retenues" class="form-control" min="0" step="0.01" value="<?= e(number_format((float) ($bulletinFormData['retenues'] ?? 0), 2, '.', '')) ?>">
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">Cotisations salariales</label>
                        <input type="number" name="cotisations_salariales" class="form-control" min="0" step="0.01" value="<?= e(number_format((float) ($bulletinFormData['cotisations_salariales'] ?? 0), 2, '.', '')) ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Cotisations patronales</label>
                        <input type="number" name="cotisations_patronales" class="form-control" min="0" step="0.01" value="<?= e(number_format((float) ($bulletinFormData['cotisations_patronales'] ?? 0), 2, '.', '')) ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Mode de paiement</label>
                        <select name="mode_paiement" class="form-select">
                            <?php foreach (['virement' => 'Virement', 'cheque' => 'Chèque', 'especes' => 'Espèces', 'autre' => 'Autre'] as $value => $label): ?>
                            <option value="<?= $value ?>" <?= ($bulletinFormData['mode_paiement'] ?? 'virement') === $value ? 'selected' : '' ?>><?= $label ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Référence paiement</label>
                        <input type="text" name="reference_paiement" class="form-control" value="<?= e((string) ($bulletinFormData['reference_paiement'] ?? '')) ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Notes</label>
                        <input type="text" name="notes" class="form-control" value="<?= e((string) ($bulletinFormData['notes'] ?? '')) ?>">
                    </div>

                    <?php $previewTotals = calculatePaieBulletinTotals($bulletinFormData); ?>
                    <div class="col-12">
                        <div class="row g-3">
                            <div class="col-md-3">
                                <div class="p-3 rounded bg-light border">
                                    <div class="small text-muted">Salaire brut</div>
                                    <div id="preview_brut" class="fw-semibold"><?= e(formatMontant((float) $previewTotals['salaire_brut'])) ?></div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="p-3 rounded bg-light border">
                                    <div class="small text-muted">Net imposable</div>
                                    <div id="preview_net_imp" class="fw-semibold"><?= e(formatMontant((float) $previewTotals['net_imposable'])) ?></div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="p-3 rounded bg-light border">
                                    <div class="small text-muted">Net à payer</div>
                                    <div id="preview_net_pay" class="fw-semibold text-success"><?= e(formatMontant((float) $previewTotals['net_a_payer'])) ?></div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="p-3 rounded bg-light border">
                                    <div class="small text-muted">Coût employeur</div>
                                    <div id="preview_cout" class="fw-semibold text-danger"><?= e(formatMontant((float) $previewTotals['cout_total_employeur'])) ?></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-12 d-flex justify-content-between align-items-center">
                        <?php if ($existingBulletin): ?>
                        <form method="post" class="mb-0" onsubmit="return confirm('Supprimer définitivement ce bulletin ?')">
                            <?= csrfField() ?>
                            <input type="hidden" name="post_action" value="delete_bulletin">
                            <input type="hidden" name="bulletin_id" value="<?= (int) $existingBulletin['id'] ?>">
                            <button type="submit" class="btn btn-outline-danger btn-sm">
                                <i class="bi bi-trash"></i> Supprimer
                            </button>
                        </form>
                        <?php else: ?>
                        <div></div>
                        <?php endif; ?>
                        <button type="submit" class="btn btn-primary">
                            <?php if ($existingBulletin): ?>
                            <i class="bi bi-pencil-square"></i> Enregistrer les modifications
                            <?php else: ?>
                            <i class="bi bi-plus-circle"></i> Générer le bulletin
                            <?php endif; ?>
                        </button>
                    </div>
                </form>
                <?php else: ?>
                <p class="text-muted mb-0">Sélectionnez un collaborateur pour générer un bulletin.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php else: ?>
    <div class="col-12">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-3">
                        <div class="small text-muted text-uppercase">Collaborateur</div>
                        <div class="fw-semibold"><?= e((string) ($selectedProfile['nom'] ?? $currentUser['nom'] ?? '')) ?></div>
                    </div>
                    <div class="col-md-3">
                        <div class="small text-muted text-uppercase">Poste</div>
                        <div class="fw-semibold"><?= e((string) ($selectedProfile['poste'] ?? '')) ?></div>
                    </div>
                    <div class="col-md-3">
                        <div class="small text-muted text-uppercase">Matricule</div>
                        <div class="fw-semibold"><?= e((string) ($selectedProfile['matricule'] ?? '')) ?></div>
                    </div>
                    <div class="col-md-3">
                        <div class="small text-muted text-uppercase">Salaire brut de référence</div>
                        <div class="fw-semibold"><?= e(formatMontant((float) ($selectedProfile['salaire_base_brut'] ?? 0))) ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="col-12">
        <div class="card border-0 shadow-sm">
            <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                <span><i class="bi bi-table"></i> Bulletins enregistrés</span>
                <div class="d-flex align-items-center gap-2">
                    <form method="get" class="d-flex gap-2 align-items-center mb-0">
                        <input type="hidden" name="user_id" value="<?= (int) $selectedUserId ?>">
                        <input type="hidden" name="periode" value="<?= e($selectedPeriod) ?>">
                        <select name="statut_filter" class="form-select form-select-sm" onchange="this.form.submit()">
                            <option value="" <?= $filterStatut === '' ? 'selected' : '' ?>>Tous les statuts</option>
                            <option value="brouillon" <?= $filterStatut === 'brouillon' ? 'selected' : '' ?>>Brouillon</option>
                            <option value="valide" <?= $filterStatut === 'valide' ? 'selected' : '' ?>>Validé</option>
                            <option value="paye" <?= $filterStatut === 'paye' ? 'selected' : '' ?>>Payé</option>
                        </select>
                    </form>
                    <div class="small text-muted"><?= count($bulletins) ?> bulletin(s)</div>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Collaborateur</th>
                            <th>Période</th>
                            <th>Brut</th>
                            <th>Net à payer</th>
                            <th>Coût employeur</th>
                            <th>Date paiement</th>
                            <th>Statut</th>
                            <?php if ($canManagePaie): ?>
                            <th class="text-end">Action</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($bulletins)): ?>
                        <tr>
                            <td colspan="<?= $canManagePaie ? 8 : 7 ?>" class="text-center text-muted py-4">Aucun bulletin pour cette sélection.</td>
                        </tr>
                        <?php else: ?>
                            <?php foreach ($bulletins as $bulletin): ?>
                            <tr>
                                <td>
                                    <div class="fw-semibold"><?= e((string) $bulletin['user_nom']) ?></div>
                                    <div class="small text-muted"><?= e((string) ($bulletin['poste'] ?? '')) ?></div>
                                </td>
                                <td><?= e(formatPaiePeriod((string) $bulletin['periode'])) ?></td>
                                <td><?= e(formatMontant((float) $bulletin['salaire_brut'])) ?></td>
                                <td class="text-success fw-semibold"><?= e(formatMontant((float) $bulletin['net_a_payer'])) ?></td>
                                <td><?= e(formatMontant((float) $bulletin['cout_total_employeur'])) ?></td>
                                <td><?= e(formatDate((string) $bulletin['date_paiement'])) ?></td>
                                <td>
                                    <span class="badge bg-<?= e(getPaieStatusBadgeClass((string) $bulletin['statut'])) ?>">
                                        <?= e(getPaieStatusLabel((string) $bulletin['statut'])) ?>
                                    </span>
                                </td>
                                <?php if ($canManagePaie): ?>
                                <td class="text-end">
                                    <div class="d-inline-flex flex-wrap gap-2 align-items-center justify-content-end">
                                        <a href="<?= BASE_URL ?>bulletin_paie_pdf.php?id=<?= (int) $bulletin['id'] ?>" target="_blank" class="btn btn-sm btn-outline-danger" title="Télécharger PDF">
                                            <i class="bi bi-file-earmark-pdf"></i>
                                        </a>
                                        <a href="<?= BASE_URL ?>saisie_bulletin_paie.php?user_id=<?= (int) $bulletin['user_id'] ?>&amp;periode=<?= urlencode((string) $bulletin['periode']) ?>" class="btn btn-sm btn-outline-secondary">
                                            <i class="bi bi-pencil"></i> Éditer
                                        </a>
                                        <form method="post" class="d-inline-flex gap-2">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="post_action" value="update_status">
                                            <input type="hidden" name="bulletin_id" value="<?= (int) $bulletin['id'] ?>">
                                            <select name="status" class="form-select form-select-sm">
                                                <?php foreach (['brouillon' => 'Brouillon', 'valide' => 'Validé', 'paye' => 'Payé'] as $value => $label): ?>
                                                <option value="<?= $value ?>" <?= $bulletin['statut'] === $value ? 'selected' : '' ?>><?= $label ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <button type="submit" class="btn btn-sm btn-outline-primary">Mettre à jour</button>
                                        </form>
                                    </div>
                                </td>
                                <?php endif; ?>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    'use strict';
    const form = document.getElementById('bulletinForm');
    if (!form) return;

    const fields = [
        'salaire_base_brut', 'heures_supplementaires', 'taux_horaire_majore',
        'montant_heures_supplementaires', 'prime', 'bonus', 'indemnites',
        'retenues', 'cotisations_salariales', 'cotisations_patronales'
    ];

    function v(name) {
        const el = form.querySelector('[name="' + name + '"]');
        return el ? Math.max(0, parseFloat(el.value) || 0) : 0;
    }

    function fmt(n) {
        return n.toLocaleString('fr-FR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' €';
    }

    function recalc() {
        const base = v('salaire_base_brut');
        let montantHS = v('montant_heures_supplementaires');
        if (montantHS === 0) {
            montantHS = v('heures_supplementaires') * v('taux_horaire_majore');
        }
        const brut    = base + montantHS + v('prime') + v('bonus') + v('indemnites');
        const netImp  = Math.max(0, brut - v('cotisations_salariales'));
        const netPay  = Math.max(0, netImp - v('retenues'));
        const cout    = Math.max(0, brut + v('cotisations_patronales'));

        const elBrut = document.getElementById('preview_brut');
        const elImp  = document.getElementById('preview_net_imp');
        const elPay  = document.getElementById('preview_net_pay');
        const elCout = document.getElementById('preview_cout');
        if (elBrut) elBrut.textContent = fmt(brut);
        if (elImp)  elImp.textContent  = fmt(netImp);
        if (elPay)  elPay.textContent  = fmt(netPay);
        if (elCout) elCout.textContent = fmt(cout);
    }

    fields.forEach(function (name) {
        const el = form.querySelector('[name="' + name + '"]');
        if (el) el.addEventListener('input', recalc);
    });
    recalc();
})();
</script>
<?php include __DIR__ . '/footer.php'; ?>

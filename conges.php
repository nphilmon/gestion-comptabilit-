<?php
/**
 * Module RH - Gestion des congés payés
 */

$titre = 'Congés payés';
require_once __DIR__ . '/functions_cp.php';

requireLogin();
requireCpModuleEnabled();

$currentUser = getCurrentUser();
$canManageCp = in_array($currentUser['role'] ?? '', ['admin', 'comptable'], true);
$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        setFlash('danger', 'Jeton de sécurité invalide.');
        header('Location: ' . BASE_URL . 'conges.php');
        exit;
    }

    $postAction = $_POST['post_action'] ?? '';

    if ($postAction === 'save_request') {
        $targetUserId = $canManageCp ? (int) ($_POST['user_id'] ?? 0) : (int) $currentUser['id'];
        $dateDebut = $_POST['date_debut'] ?? '';
        $dateFin = $_POST['date_fin'] ?? '';
        $motif = trim($_POST['motif'] ?? '');
        $commentaire = trim($_POST['commentaire'] ?? '');
        $mode = getCpCountingMode();

        if ($targetUserId <= 0 || $dateDebut === '' || $dateFin === '') {
            setFlash('danger', 'Utilisateur et période obligatoires pour la demande.');
            header('Location: ' . BASE_URL . 'conges.php');
            exit;
        }

        if (!$canManageCp && $targetUserId !== (int) $currentUser['id']) {
            setFlash('danger', 'Vous ne pouvez créer une demande que pour votre compte.');
            header('Location: ' . BASE_URL . 'conges.php');
            exit;
        }

        if ($dateFin < $dateDebut) {
            setFlash('danger', 'La date de fin doit être postérieure à la date de début.');
            header('Location: ' . BASE_URL . 'conges.php');
            exit;
        }

        if (hasCpRequestOverlap($targetUserId, $dateDebut, $dateFin)) {
            setFlash('danger', 'Une demande CP existe déjà sur cette période.');
            header('Location: ' . BASE_URL . 'conges.php');
            exit;
        }

        $joursDemandes = calculateCpLeaveDays($dateDebut, $dateFin, $mode);
        $balance = getCpBalanceForUser($targetUserId);
        if ($joursDemandes <= 0) {
            setFlash('danger', 'Aucun jour décomptable sur la période sélectionnée.');
            header('Location: ' . BASE_URL . 'conges.php');
            exit;
        }

        if ($balance && $joursDemandes > $balance['available']) {
            setFlash('danger', 'Le solde disponible est insuffisant pour cette demande.');
            header('Location: ' . BASE_URL . 'conges.php');
            exit;
        }

        ensureCpProfile($targetUserId);
        $stmt = $db->prepare('INSERT INTO cp_demandes (user_id, date_debut, date_fin, mode_decompte, jours_demandes, motif, commentaire)
                              VALUES (?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([
            $targetUserId,
            $dateDebut,
            $dateFin,
            $mode,
            $joursDemandes,
            $motif !== '' ? $motif : null,
            $commentaire !== '' ? $commentaire : null,
        ]);

        setFlash('success', 'Demande de congés enregistrée.');
        header('Location: ' . BASE_URL . 'conges.php');
        exit;
    }

    if ($postAction === 'update_status' && $canManageCp) {
        $requestId = (int) ($_POST['request_id'] ?? 0);
        $status = $_POST['status'] ?? '';

        if (!in_array($status, ['valide', 'refuse'], true)) {
            setFlash('danger', 'Statut de demande invalide.');
            header('Location: ' . BASE_URL . 'conges.php');
            exit;
        }

        $stmt = $db->prepare('SELECT * FROM cp_demandes WHERE id = ?');
        $stmt->execute([$requestId]);
        $request = $stmt->fetch();

        if (!$request) {
            setFlash('danger', 'Demande introuvable.');
            header('Location: ' . BASE_URL . 'conges.php');
            exit;
        }

        if ($status === 'valide') {
            $balance = getCpBalanceForUser((int) $request['user_id']);
            if ($balance && (float) $request['jours_demandes'] > $balance['available']) {
                setFlash('danger', 'Validation impossible : solde insuffisant.');
                header('Location: ' . BASE_URL . 'conges.php');
                exit;
            }
        }

        $stmt = $db->prepare('UPDATE cp_demandes SET statut = ?, traite_par = ?, traite_le = NOW() WHERE id = ?');
        $stmt->execute([$status, (int) $currentUser['id'], $requestId]);

        setFlash('success', 'Demande mise à jour.');
        header('Location: ' . BASE_URL . 'conges.php');
        exit;
    }

    if ($postAction === 'save_profile' && $canManageCp) {
        $userId = (int) ($_POST['user_id'] ?? 0);
        $dateEntree = trim($_POST['date_entree'] ?? '');
        $actif = isset($_POST['actif']) ? 1 : 0;
        $soldeInitial = (float) ($_POST['solde_initial'] ?? 0);
        $joursSupp = (float) ($_POST['jours_supplementaires_annuels'] ?? 0);
        $notes = trim($_POST['notes'] ?? '');

        if ($userId <= 0) {
            setFlash('danger', 'Utilisateur invalide pour le profil CP.');
            header('Location: ' . BASE_URL . 'conges.php');
            exit;
        }

        ensureCpProfile($userId);
        $stmt = $db->prepare('UPDATE cp_profils
            SET date_entree = ?, actif = ?, solde_initial = ?, jours_supplementaires_annuels = ?, notes = ?
            WHERE user_id = ?');
        $stmt->execute([
            $dateEntree !== '' ? $dateEntree : null,
            $actif,
            $soldeInitial,
            $joursSupp,
            $notes !== '' ? $notes : null,
            $userId,
        ]);

        setFlash('success', 'Profil CP mis à jour.');
        header('Location: ' . BASE_URL . 'conges.php');
        exit;
    }

    if ($postAction === 'add_adjustment' && $canManageCp) {
        $userId = (int) ($_POST['user_id'] ?? 0);
        $dateMouvement = $_POST['date_mouvement'] ?? date('Y-m-d');
        $jours = (float) ($_POST['jours'] ?? 0);
        $motif = trim($_POST['motif'] ?? '');

        if ($userId <= 0 || $motif === '' || abs($jours) < 0.01) {
            setFlash('danger', 'Ajustement invalide : utilisateur, motif et nombre de jours requis.');
            header('Location: ' . BASE_URL . 'conges.php');
            exit;
        }

        ensureCpProfile($userId);
        $stmt = $db->prepare('INSERT INTO cp_ajustements (user_id, date_mouvement, jours, motif, created_by) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$userId, $dateMouvement, $jours, $motif, (int) $currentUser['id']]);

        setFlash('success', 'Ajustement CP enregistré.');
        header('Location: ' . BASE_URL . 'conges.php');
        exit;
    }
}

$period = getCpReferencePeriod();
$stats = getCpDashboardStats();
$myBalance = getCpBalanceForUser((int) $currentUser['id']);
$balances = $canManageCp ? getCpEmployeeBalances() : ($myBalance ? [$myBalance] : []);
$requests = $canManageCp ? getCpRequests() : getCpRequests(['user_id' => (int) $currentUser['id']]);
$adjustments = $canManageCp ? getCpAdjustments() : [];
$profiles = getCpProfiles(true);
$cpFolders = ensureCpFoldersForAllProfiles();

$today = date('Y-m-d');
$stmt = $db->prepare('SELECT d.*, u.nom AS user_nom
    FROM cp_demandes d
    JOIN users u ON u.id = d.user_id
    WHERE d.statut = "valide" AND d.date_debut <= ? AND d.date_fin >= ?
    ORDER BY u.nom');
$stmt->execute([$today, $today]);
$todayLeaves = $stmt->fetchAll();

include __DIR__ . '/header.php';
?>

<div class="hero-banner mb-4">
    <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3">
        <div>
            <h1 class="mb-1"><i class="bi bi-calendar2-check"></i> Congés payés</h1>
            <p class="mb-0 text-muted">Gestion CP selon les paramètres légaux français configurés dans l'application.</p>
        </div>
        <div class="text-lg-end">
            <div class="small text-uppercase text-muted">Période de référence</div>
            <div class="fw-semibold"><?= e($period['label']) ?></div>
            <div class="small text-muted">Mode de décompte : <?= e(getCpCountingMode()) ?></div>
            <div class="small text-muted">Dossier racine : <code><?= e(cpStorageBaseRelativePath()) ?></code></div>
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
                    <div class="stat-icon bg-warning-subtle text-warning"><i class="bi bi-hourglass-split"></i></div>
                    <small class="text-muted ms-2">Demandes en attente</small>
                </div>
                <h3 class="text-warning mb-1"><?= (int) $stats['pending'] ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card border-0 h-100">
            <div class="card-body">
                <div class="d-flex align-items-center mb-2">
                    <div class="stat-icon bg-info-subtle text-info"><i class="bi bi-airplane-fill"></i></div>
                    <small class="text-muted ms-2">En congé aujourd'hui</small>
                </div>
                <h3 class="text-info mb-1"><?= (int) $stats['on_leave_today'] ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card border-0 h-100">
            <div class="card-body">
                <div class="d-flex align-items-center mb-2">
                    <div class="stat-icon bg-success-subtle text-success"><i class="bi bi-calendar2-check-fill"></i></div>
                    <small class="text-muted ms-2">Solde disponible total</small>
                </div>
                <h3 class="text-success mb-1"><?= e(number_format((float) $stats['available_total'], 2, ',', ' ')) ?></h3>
            </div>
        </div>
    </div>
</div>

<?php if (!empty($todayLeaves)): ?>
<div class="alert alert-info border-0 mb-4">
    <i class="bi bi-people"></i>
    En congé aujourd'hui :
    <?= e(implode(', ', array_map(static fn($item) => $item['user_nom'], $todayLeaves))) ?>
</div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-xl-4">
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header"><i class="bi bi-send-check"></i> Nouvelle demande de CP</div>
            <div class="card-body">
                <form method="POST">
                    <?= csrfField() ?>
                    <input type="hidden" name="post_action" value="save_request">

                    <?php if ($canManageCp): ?>
                    <div class="mb-3">
                        <label class="form-label">Collaborateur</label>
                        <select name="user_id" class="form-select" required>
                            <?php foreach ($profiles as $profile): ?>
                            <?php if ((int) $profile['user_actif'] !== 1) continue; ?>
                            <option value="<?= (int) $profile['user_id'] ?>" <?= (int) $profile['user_id'] === (int) $currentUser['id'] ? 'selected' : '' ?>>
                                <?= e($profile['nom']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Date début</label>
                            <input type="date" name="date_debut" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Date fin</label>
                            <input type="date" name="date_fin" class="form-control" required>
                        </div>
                    </div>

                    <div class="mt-3">
                        <label class="form-label">Motif</label>
                        <input type="text" name="motif" class="form-control" placeholder="Ex. congés été, fermeture, repos">
                    </div>

                    <div class="mt-3">
                        <label class="form-label">Commentaire</label>
                        <textarea name="commentaire" class="form-control" rows="3" placeholder="Précision éventuelle"></textarea>
                    </div>

                    <?php if ($myBalance): ?>
                    <div class="alert alert-light border-0 mt-3 mb-0">
                        <div class="fw-semibold">Votre solde actuel</div>
                        <div><?= e(number_format((float) $myBalance['available'], 2, ',', ' ')) ?> jour(s) disponibles</div>
                        <?php if ($myBalance['fractionnement'] > 0): ?>
                        <div class="small text-muted">dont <?= e(number_format((float) $myBalance['fractionnement'], 2, ',', ' ')) ?> jour(s) de fractionnement</div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <div class="mt-3 d-grid">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-calendar2-plus"></i> Enregistrer la demande
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <?php if ($canManageCp): ?>
        <div class="card border-0 shadow-sm">
            <div class="card-header"><i class="bi bi-sliders"></i> Ajustement de solde</div>
            <div class="card-body">
                <form method="POST">
                    <?= csrfField() ?>
                    <input type="hidden" name="post_action" value="add_adjustment">

                    <div class="mb-3">
                        <label class="form-label">Collaborateur</label>
                        <select name="user_id" class="form-select" required>
                            <?php foreach ($profiles as $profile): ?>
                            <option value="<?= (int) $profile['user_id'] ?>"><?= e($profile['nom']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Date</label>
                            <input type="date" name="date_mouvement" class="form-control" value="<?= e(date('Y-m-d')) ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Jours (+/-)</label>
                            <input type="number" name="jours" class="form-control" step="0.01" required>
                        </div>
                    </div>

                    <div class="mt-3">
                        <label class="form-label">Motif</label>
                        <input type="text" name="motif" class="form-control" placeholder="Ex. report N-1, régularisation, convention" required>
                    </div>

                    <div class="mt-3 d-grid">
                        <button type="submit" class="btn btn-outline-primary">
                            <i class="bi bi-plus-slash-minus"></i> Ajouter l'ajustement
                        </button>
                    </div>
                </form>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <div class="col-xl-8">
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header"><i class="bi bi-hourglass-split"></i> Demandes</div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Collaborateur</th>
                                <th>Période</th>
                                <th>Jours</th>
                                <th>Motif</th>
                                <th>Statut</th>
                                <?php if ($canManageCp): ?><th class="text-end">Action</th><?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($requests)): ?>
                            <tr><td colspan="<?= $canManageCp ? '6' : '5' ?>" class="text-center text-muted py-4">Aucune demande de congé enregistrée.</td></tr>
                            <?php endif; ?>
                            <?php foreach ($requests as $request): ?>
                            <tr>
                                <td class="fw-semibold"><?= e($request['user_nom']) ?></td>
                                <td><?= e(formatDate($request['date_debut'])) ?> au <?= e(formatDate($request['date_fin'])) ?></td>
                                <td><?= e(number_format((float) $request['jours_demandes'], 2, ',', ' ')) ?></td>
                                <td>
                                    <?= e($request['motif'] ?: 'Sans motif') ?>
                                    <?php if (!empty($request['commentaire'])): ?>
                                    <div class="small text-muted"><?= e($request['commentaire']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php
                                    $badge = match ($request['statut']) {
                                        'valide' => 'success',
                                        'refuse' => 'danger',
                                        default => 'warning text-dark',
                                    };
                                    ?>
                                    <span class="badge bg-<?= $badge ?>"><?= e(ucfirst($request['statut'])) ?></span>
                                </td>
                                <?php if ($canManageCp): ?>
                                <td class="text-end">
                                    <?php if ($request['statut'] === 'en_attente'): ?>
                                    <form method="POST" class="d-inline">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="post_action" value="update_status">
                                        <input type="hidden" name="request_id" value="<?= (int) $request['id'] ?>">
                                        <input type="hidden" name="status" value="valide">
                                        <button type="submit" class="btn btn-sm btn-outline-success"><i class="bi bi-check-lg"></i></button>
                                    </form>
                                    <form method="POST" class="d-inline">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="post_action" value="update_status">
                                        <input type="hidden" name="request_id" value="<?= (int) $request['id'] ?>">
                                        <input type="hidden" name="status" value="refuse">
                                        <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-x-lg"></i></button>
                                    </form>
                                    <?php else: ?>
                                    <span class="small text-muted"><?= e($request['traite_par_nom'] ?: 'Traité') ?></span>
                                    <?php endif; ?>
                                </td>
                                <?php endif; ?>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header"><i class="bi bi-people-fill"></i> Soldes et profils CP</div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0 align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Collaborateur</th>
                                <th>Entrée</th>
                                <th>Initial</th>
                                <th>Acquis</th>
                                <th>Ajustements</th>
                                <th>Fractionnement</th>
                                <th>Pris</th>
                                <th>Disponible</th>
                                <th>Dossier</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($balances as $balance): ?>
                            <?php $folder = $cpFolders[(int) $balance['profile']['user_id']] ?? getCpCollaboratorFolderInfo((int) $balance['profile']['user_id']); ?>
                            <tr>
                                <td>
                                    <div class="fw-semibold"><?= e($balance['profile']['nom']) ?></div>
                                    <div class="small text-muted"><?= e($balance['profile']['email']) ?></div>
                                </td>
                                <td><?= !empty($balance['profile']['date_entree']) ? e(formatDate($balance['profile']['date_entree'])) : '<span class="text-muted">-</span>' ?></td>
                                <td><?= e(number_format((float) $balance['initial'], 2, ',', ' ')) ?></td>
                                <td><?= e(number_format((float) $balance['acquired'], 2, ',', ' ')) ?></td>
                                <td><?= e(number_format((float) $balance['adjustments'], 2, ',', ' ')) ?></td>
                                <td><?= $balance['fractionnement'] > 0 ? e(number_format((float) $balance['fractionnement'], 2, ',', ' ')) : '<span class="text-muted">-</span>' ?></td>
                                <td><?= e(number_format((float) $balance['taken'], 2, ',', ' ')) ?></td>
                                <td class="fw-bold <?= $balance['available'] < 5 ? 'text-warning' : 'text-success' ?>">
                                    <?= e(number_format((float) $balance['available'], 2, ',', ' ')) ?>
                                </td>
                                <td>
                                    <?php if ($folder): ?>
                                    <code><?= e($folder['relative_path']) ?></code>
                                    <?php else: ?>
                                    <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <?php if ($canManageCp): ?>
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header"><i class="bi bi-person-gear"></i> Paramètres collaborateurs</div>
            <div class="card-body">
                <div class="row g-3">
                    <?php foreach ($profiles as $profile): ?>
                    <div class="col-lg-6">
                        <div class="border rounded-3 p-3 h-100">
                            <div class="fw-semibold mb-2"><?= e($profile['nom']) ?></div>
                            <form method="POST">
                                <?= csrfField() ?>
                                <input type="hidden" name="post_action" value="save_profile">
                                <input type="hidden" name="user_id" value="<?= (int) $profile['user_id'] ?>">

                                <div class="row g-2">
                                    <div class="col-md-6">
                                        <label class="form-label small">Date d'entrée</label>
                                        <input type="date" name="date_entree" class="form-control form-control-sm"
                                               value="<?= e($profile['date_entree'] ?? '') ?>">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label small">Solde initial</label>
                                        <input type="number" name="solde_initial" class="form-control form-control-sm" step="0.01"
                                               value="<?= e((string) ($profile['solde_initial'] ?? '0')) ?>">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label small">Jours sup. annuels</label>
                                        <input type="number" name="jours_supplementaires_annuels" class="form-control form-control-sm" step="0.01"
                                               value="<?= e((string) ($profile['jours_supplementaires_annuels'] ?? '0')) ?>">
                                    </div>
                                    <div class="col-md-6 d-flex align-items-end">
                                        <div class="form-check form-switch mb-2">
                                            <input class="form-check-input" type="checkbox" name="actif" value="1" id="cp-actif-<?= (int) $profile['user_id'] ?>" <?= (int) ($profile['actif'] ?? 1) === 1 ? 'checked' : '' ?>>
                                            <label class="form-check-label" for="cp-actif-<?= (int) $profile['user_id'] ?>">Suivi actif</label>
                                        </div>
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label small">Notes</label>
                                        <textarea name="notes" class="form-control form-control-sm" rows="2"><?= e($profile['notes'] ?? '') ?></textarea>
                                    </div>
                                    <div class="col-12">
                                        <div class="small text-muted mb-1">Dossier collaborateur</div>
                                        <div class="border rounded-2 px-2 py-1 bg-light">
                                            <code><?= e(($cpFolders[(int) $profile['user_id']]['relative_path'] ?? cpStorageBaseRelativePath())) ?></code>
                                        </div>
                                    </div>
                                </div>

                                <div class="mt-2 text-end">
                                    <button type="submit" class="btn btn-sm btn-outline-primary">
                                        <i class="bi bi-save"></i> Enregistrer
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-header"><i class="bi bi-clock-history"></i> Historique des ajustements</div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Date</th>
                                <th>Collaborateur</th>
                                <th>Jours</th>
                                <th>Motif</th>
                                <th>Saisi par</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($adjustments)): ?>
                            <tr><td colspan="5" class="text-center text-muted py-4">Aucun ajustement enregistré.</td></tr>
                            <?php endif; ?>
                            <?php foreach ($adjustments as $adjustment): ?>
                            <tr>
                                <td><?= e(formatDate($adjustment['date_mouvement'])) ?></td>
                                <td class="fw-semibold"><?= e($adjustment['user_nom']) ?></td>
                                <td class="<?= (float) $adjustment['jours'] >= 0 ? 'text-success' : 'text-danger' ?>">
                                    <?= e(number_format((float) $adjustment['jours'], 2, ',', ' ')) ?>
                                </td>
                                <td><?= e($adjustment['motif']) ?></td>
                                <td><?= e($adjustment['created_by_nom'] ?: '-') ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/footer.php'; ?>

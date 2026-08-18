<?php
/**
 * Gestion des exercices comptables
 */
require_once __DIR__ . '/functions.php';
requireLogin();
$titre = 'Exercices comptables';

$action = $_GET['action'] ?? 'liste';

// --- POST: traitement ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        setFlash('danger', 'Erreur de sécurité (CSRF).');
        header('Location: exercices.php');
        exit;
    }

    $postAction = $_POST['action'] ?? '';

    if ($postAction === 'ajouter' || $postAction === 'modifier') {
        $data = [
            'nom' => trim($_POST['nom'] ?? ''),
            'date_debut' => $_POST['date_debut'] ?? '',
            'date_fin' => $_POST['date_fin'] ?? '',
            'notes' => trim($_POST['notes'] ?? ''),
        ];

        if (empty($data['nom']) || empty($data['date_debut']) || empty($data['date_fin'])) {
            setFlash('danger', 'Tous les champs obligatoires doivent être remplis.');
            header('Location: exercices.php?action=' . ($postAction === 'modifier' ? 'modifier&id=' . (int)$_POST['id'] : 'nouveau'));
            exit;
        }

        if ($data['date_fin'] <= $data['date_debut']) {
            setFlash('danger', 'La date de fin doit être postérieure à la date de début.');
            header('Location: exercices.php?action=nouveau');
            exit;
        }

        if ($postAction === 'ajouter') {
            ajouterExercice($data);
            setFlash('success', 'Exercice créé avec succès.');
        } else {
            modifierExercice((int)$_POST['id'], $data);
            setFlash('success', 'Exercice modifié.');
        }
        header('Location: exercices.php');
        exit;
    }

    if ($postAction === 'cloturer') {
        cloturerExercice((int)$_POST['id']);
        setFlash('success', 'Exercice clôturé.');
        header('Location: exercices.php');
        exit;
    }

    if ($postAction === 'rouvrir') {
        rouvrirExercice((int)$_POST['id']);
        setFlash('success', 'Exercice réouvert.');
        header('Location: exercices.php');
        exit;
    }

    if ($postAction === 'supprimer') {
        supprimerExercice((int)$_POST['id']);
        setFlash('success', 'Exercice supprimé.');
        header('Location: exercices.php');
        exit;
    }
}

$exercices = getExercices();
$exerciceEnCours = getExerciceEnCours();

include 'header.php';
?>

<div class="hero-banner mb-4">
    <div class="d-flex justify-content-between align-items-center">
        <div>
            <h2 class="mb-1"><i class="bi bi-calendar-range"></i> Exercices comptables</h2>
            <p class="text-muted mb-0"><?= count($exercices) ?> exercice<?= count($exercices) > 1 ? 's' : '' ?></p>
        </div>
        <a href="?action=nouveau" class="btn btn-primary"><i class="bi bi-plus-lg"></i> Nouvel exercice</a>
    </div>
</div>

<?php if ($action === 'nouveau' || $action === 'modifier'): ?>
    <?php
    $exercice = null;
    if ($action === 'modifier' && isset($_GET['id'])) {
        $exercice = getExercice((int)$_GET['id']);
        if (!$exercice) { setFlash('danger', 'Exercice introuvable.'); header('Location: exercices.php'); exit; }
    }
    ?>
    <div class="card border-0 mb-4">
        <div class="card-header">
            <i class="bi bi-<?= $exercice ? 'pencil' : 'plus-circle' ?>"></i>
            <?= $exercice ? 'Modifier l\'exercice' : 'Nouvel exercice comptable' ?>
        </div>
        <div class="card-body">
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="<?= $exercice ? 'modifier' : 'ajouter' ?>">
                <?php if ($exercice): ?>
                    <input type="hidden" name="id" value="<?= $exercice['id'] ?>">
                <?php endif; ?>

                <div class="row g-3">
                    <div class="col-md-12">
                        <label class="form-label">Nom de l'exercice <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="nom" required
                               value="<?= e($exercice['nom'] ?? 'Exercice ' . date('Y')) ?>"
                               placeholder="Ex: Exercice 2025">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Date de début <span class="text-danger">*</span></label>
                        <input type="date" class="form-control" name="date_debut" required
                               value="<?= e($exercice['date_debut'] ?? date('Y') . '-01-01') ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Date de fin <span class="text-danger">*</span></label>
                        <input type="date" class="form-control" name="date_fin" required
                               value="<?= e($exercice['date_fin'] ?? date('Y') . '-12-31') ?>">
                    </div>
                    <div class="col-md-12">
                        <label class="form-label">Notes</label>
                        <textarea class="form-control" name="notes" rows="2"><?= e($exercice['notes'] ?? '') ?></textarea>
                    </div>
                </div>

                <div class="mt-3">
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> <?= $exercice ? 'Modifier' : 'Créer l\'exercice' ?></button>
                    <a href="exercices.php" class="btn btn-outline-secondary">Annuler</a>
                </div>
            </form>
        </div>
    </div>

<?php else: ?>
    <!-- Exercice en cours -->
    <?php if ($exerciceEnCours): ?>
        <?php $statsEC = getStatsExercice($exerciceEnCours); ?>
        <div class="card border-0 border-start border-primary border-4 mb-4">
            <div class="card-body">
                <div class="row align-items-center">
                    <div class="col-md-4">
                        <h5 class="mb-1"><i class="bi bi-play-circle text-primary"></i> <?= e($exerciceEnCours['nom']) ?></h5>
                        <small class="text-muted"><?= formatDate($exerciceEnCours['date_debut']) ?> → <?= formatDate($exerciceEnCours['date_fin']) ?></small>
                        <?php
                        $debut = new DateTime($exerciceEnCours['date_debut']);
                        $fin = new DateTime($exerciceEnCours['date_fin']);
                        $now = new DateTime();
                        $totalJours = $debut->diff($fin)->days;
                        $joursEcoules = $debut->diff($now)->days;
                        $pctAvancement = $totalJours > 0 ? min(round($joursEcoules / $totalJours * 100), 100) : 0;
                        ?>
                        <div class="progress mt-2 progress-h6">
                            <div class="progress-bar" style="width: <?= $pctAvancement ?>%"></div>
                        </div>
                        <small class="text-muted"><?= $pctAvancement ?>% écoulé — <?= max(0, $fin->diff($now)->days) ?> jours restants</small>
                    </div>
                    <div class="col-md-2 text-center">
                        <div class="text-success fw-bold fs-5"><?= formatMontant($statsEC['recettes']) ?></div>
                        <small class="text-muted">Recettes</small>
                    </div>
                    <div class="col-md-2 text-center">
                        <div class="text-danger fw-bold fs-5"><?= formatMontant($statsEC['depenses']) ?></div>
                        <small class="text-muted">Dépenses</small>
                    </div>
                    <div class="col-md-2 text-center">
                        <div class="<?= $statsEC['resultat'] >= 0 ? 'text-info' : 'text-danger' ?> fw-bold fs-5"><?= formatMontant($statsEC['resultat']) ?></div>
                        <small class="text-muted">Résultat</small>
                    </div>
                    <div class="col-md-2 text-center">
                        <div class="fw-bold fs-5"><?= $statsEC['nb_ecritures'] ?></div>
                        <small class="text-muted">Écritures</small>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Liste des exercices -->
    <div class="card border-0">
        <div class="card-body p-0">
            <?php if (empty($exercices)): ?>
                <div class="text-center py-5 text-muted">
                    <i class="bi bi-calendar-x fs-3rem"></i>
                    <p class="mt-2">Aucun exercice comptable défini.</p>
                    <a href="?action=nouveau" class="btn btn-primary"><i class="bi bi-plus-lg"></i> Créer le premier exercice</a>
                </div>
            <?php else: ?>
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Nom</th>
                            <th>Période</th>
                            <th>Statut</th>
                            <th class="text-end">Recettes</th>
                            <th class="text-end">Dépenses</th>
                            <th class="text-end">Résultat</th>
                            <th class="text-center">Écritures</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($exercices as $ex):
                            $statsEx = getStatsExercice($ex);
                        ?>
                            <tr class="<?= $exerciceEnCours && $ex['id'] == $exerciceEnCours['id'] ? 'table-primary' : '' ?>">
                                <td class="fw-semibold">
                                    <?= e($ex['nom']) ?>
                                    <?php if ($exerciceEnCours && $ex['id'] == $exerciceEnCours['id']): ?>
                                        <span class="badge bg-primary ms-1">En cours</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <small><?= formatDate($ex['date_debut']) ?> → <?= formatDate($ex['date_fin']) ?></small>
                                </td>
                                <td>
                                    <?php if ($ex['statut'] === 'ouvert'): ?>
                                        <span class="badge bg-success">Ouvert</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">Clôturé</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end text-success"><?= formatMontant($statsEx['recettes']) ?></td>
                                <td class="text-end text-danger"><?= formatMontant($statsEx['depenses']) ?></td>
                                <td class="text-end fw-bold <?= $statsEx['resultat'] >= 0 ? 'text-info' : 'text-danger' ?>">
                                    <?= formatMontant($statsEx['resultat']) ?>
                                </td>
                                <td class="text-center"><?= $statsEx['nb_ecritures'] ?></td>
                                <td class="text-end">
                                    <div class="btn-group btn-group-sm">
                                        <a href="?action=modifier&id=<?= $ex['id'] ?>" class="btn btn-outline-primary" title="Modifier">
                                            <i class="bi bi-pencil"></i>
                                        </a>
                                        <?php if ($ex['statut'] === 'ouvert'): ?>
                                            <form method="POST" class="d-inline" onsubmit="return confirm('Clôturer cet exercice ?')">
                                                <?= csrfField() ?>
                                                <input type="hidden" name="action" value="cloturer">
                                                <input type="hidden" name="id" value="<?= $ex['id'] ?>">
                                                <button class="btn btn-outline-warning" title="Clôturer"><i class="bi bi-lock"></i></button>
                                            </form>
                                        <?php else: ?>
                                            <form method="POST" class="d-inline">
                                                <?= csrfField() ?>
                                                <input type="hidden" name="action" value="rouvrir">
                                                <input type="hidden" name="id" value="<?= $ex['id'] ?>">
                                                <button class="btn btn-outline-success" title="Réouvrir"><i class="bi bi-unlock"></i></button>
                                            </form>
                                        <?php endif; ?>
                                        <?php if ($statsEx['nb_ecritures'] === 0): ?>
                                            <form method="POST" class="d-inline" onsubmit="return confirm('Supprimer cet exercice ?')">
                                                <?= csrfField() ?>
                                                <input type="hidden" name="action" value="supprimer">
                                                <input type="hidden" name="id" value="<?= $ex['id'] ?>">
                                                <button class="btn btn-outline-danger" title="Supprimer"><i class="bi bi-trash"></i></button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<?php include 'footer.php'; ?>

<?php
/**
 * Gestion des paiements
 */
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/functions_commercial.php';
requireLogin();
requireRole('admin', 'comptable');
$titre = 'Paiements';

$action = $_GET['action'] ?? 'liste';

// --- Traitement POST ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        setFlash('danger', 'Erreur de sécurité.');
        header('Location: paiements.php');
        exit;
    }
    
    $postAction = $_POST['post_action'] ?? '';
    
    if ($postAction === 'enregistrer') {
        $data = [
            'facture_id'    => (int)$_POST['facture_id'],
            'date_paiement' => $_POST['date_paiement'],
            'montant'       => (float)$_POST['montant'],
            'mode_paiement' => $_POST['mode_paiement'],
            'reference'     => trim($_POST['reference'] ?? ''),
            'notes'         => trim($_POST['notes'] ?? ''),
        ];

        if (empty($data['facture_id']) || $data['montant'] <= 0) {
            setFlash('danger', 'Veuillez sélectionner une facture et un montant valide.');
            header('Location: paiements.php?action=nouveau');
            exit;
        }

        ajouterPaiement($data);
        setFlash('success', 'Paiement enregistré.');
        header('Location: factures.php?action=voir&id=' . $data['facture_id']);
        exit;
    }

    if ($postAction === 'supprimer') {
        $paiementId = (int)$_POST['paiement_id'];
        $redirect = $_POST['redirect'] ?? 'paiements.php';
        supprimerPaiement($paiementId);
        setFlash('success', 'Paiement supprimé.');
        header('Location: ' . $redirect);
        exit;
    }
}

include 'header.php';
?>

<?php if ($action === 'liste'): ?>
<?php
$filtreMode = $_GET['mode'] ?? '';
$filtreRecherche = $_GET['recherche'] ?? '';
$paiementsList = getPaiementsList(['mode' => $filtreMode, 'recherche' => $filtreRecherche]);

$totalPaiements = 0;
foreach ($paiementsList as $p) { $totalPaiements += (float)$p['montant']; }

$db = getDB();
$enAttente = $db->query("SELECT COUNT(*) FROM factures WHERE statut IN ('envoyee','partielle','en_retard')")->fetchColumn();
?>

<div class="hero-banner mb-4">
    <div class="d-flex justify-content-between align-items-center">
        <div>
            <h2 class="mb-1"><i class="bi bi-credit-card"></i> Paiements</h2>
            <p class="text-muted mb-0"><?= count($paiementsList) ?> paiement<?= count($paiementsList) > 1 ? 's' : '' ?> enregistré<?= count($paiementsList) > 1 ? 's' : '' ?></p>
        </div>
        <a href="?action=nouveau" class="btn btn-primary"><i class="bi bi-plus-lg"></i> Nouveau paiement</a>
    </div>
</div>

<!-- Stat cards -->
<div class="row g-3 mb-4">
    <div class="col-md-4 col-6">
        <div class="card border-0 stat-card h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-muted small text-uppercase">Total encaissé</div>
                        <div class="fs-4 fw-bold text-success"><?= formatMontant($totalPaiements) ?></div>
                    </div>
                    <div class="stat-icon bg-success bg-opacity-10 text-success">
                        <i class="bi bi-cash-stack"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-4 col-6">
        <div class="card border-0 stat-card h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-muted small text-uppercase">Paiements</div>
                        <div class="fs-4 fw-bold text-primary"><?= count($paiementsList) ?></div>
                    </div>
                    <div class="stat-icon bg-primary bg-opacity-10 text-primary">
                        <i class="bi bi-credit-card"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-4 col-12">
        <div class="card border-0 stat-card h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-muted small text-uppercase">Factures en attente</div>
                        <div class="fs-4 fw-bold text-warning"><?= $enAttente ?></div>
                    </div>
                    <div class="stat-icon bg-warning bg-opacity-10 text-warning">
                        <i class="bi bi-hourglass-split"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Filtres -->
<div class="card border-0 mb-4">
    <div class="card-body py-3">
        <form method="get" class="row g-2 align-items-center">
            <div class="col-auto flex-grow-1">
                <input type="text" name="recherche" class="form-control form-control-sm" placeholder="N° facture, client..." value="<?= e($filtreRecherche) ?>">
            </div>
            <div class="col-auto">
                <select name="mode" class="form-select form-select-sm">
                    <option value="">Tous modes</option>
                    <?php foreach (['especes', 'carte_bancaire', 'virement', 'cheque', 'prelevement', 'paypal', 'autre'] as $m): ?>
                        <option value="<?= $m ?>" <?= $filtreMode === $m ? 'selected' : '' ?>><?= e(getModePaiementLabel($m)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-auto">
                <button class="btn btn-sm btn-primary"><i class="bi bi-search"></i> Filtrer</button>
                <?php if ($filtreMode || $filtreRecherche): ?>
                    <a href="paiements.php" class="btn btn-sm btn-outline-secondary">Effacer</a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<?php if (empty($paiementsList)): ?>
    <div class="card border-0">
        <div class="card-body text-center py-5 text-muted">
            <i class="bi bi-credit-card" style="font-size: 3rem;"></i>
            <p class="mt-2">Aucun paiement enregistré.</p>
            <a href="?action=nouveau" class="btn btn-primary"><i class="bi bi-plus-lg"></i> Enregistrer un paiement</a>
        </div>
    </div>
<?php else: ?>
    <div class="card border-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Date</th>
                        <th>Facture</th>
                        <th>Client</th>
                        <th>Mode</th>
                        <th>Référence</th>
                        <th class="text-end">Montant</th>
                        <th>Notes</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($paiementsList as $p): ?>
                    <tr>
                        <td><?= formatDate($p['date_paiement']) ?></td>
                        <td><a href="factures.php?action=voir&id=<?= $p['facture_id'] ?>" class="fw-bold"><?= e($p['facture_numero'] ?? '—') ?></a></td>
                        <td><?= e($p['client_entreprise'] ?: trim(($p['client_prenom'] ?? '') . ' ' . ($p['client_nom'] ?? ''))) ?></td>
                        <td><span class="badge bg-info"><?= e(getModePaiementLabel($p['mode_paiement'])) ?></span></td>
                        <td><?= e($p['reference'] ?: '—') ?></td>
                        <td class="text-end fw-bold text-success"><?= formatMontant($p['montant']) ?></td>
                        <td><small class="text-muted"><?= e($p['notes'] ?? '') ?></small></td>
                        <td class="text-end">
                            <form method="post" class="d-inline" onsubmit="return confirm('Supprimer ce paiement ?')">
                                <?= csrfField() ?>
                                <input type="hidden" name="post_action" value="supprimer">
                                <input type="hidden" name="paiement_id" value="<?= $p['id'] ?>">
                                <input type="hidden" name="redirect" value="paiements.php">
                                <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot class="table-dark">
                    <tr>
                        <td colspan="5" class="text-end fw-bold">Total</td>
                        <td class="text-end fw-bold"><?= formatMontant($totalPaiements) ?></td>
                        <td colspan="2"></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
<?php endif; ?>

<?php elseif ($action === 'nouveau'): ?>
<?php
$preselectedFacture = (int)($_GET['facture_id'] ?? 0);
$db = getDB();
$facturesOuvertes = $db->query("
    SELECT f.id, f.numero, f.objet, f.montant_ttc, f.montant_paye,
           c.nom AS client_nom, c.prenom AS client_prenom, c.entreprise AS client_entreprise
    FROM factures f
    LEFT JOIN clients c ON f.client_id = c.id
    WHERE f.statut NOT IN ('annulee', 'payee')
    ORDER BY f.date_facture DESC
")->fetchAll();
?>

<div class="hero-banner mb-4">
    <div class="d-flex justify-content-between align-items-center">
        <div>
            <h2 class="mb-1"><i class="bi bi-credit-card"></i> Enregistrer un paiement</h2>
            <p class="text-muted mb-0">Sélectionnez une facture et saisissez le montant reçu</p>
        </div>
        <a href="paiements.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Retour</a>
    </div>
</div>

<div class="card border-0">
    <div class="card-body">
        <form method="post">
            <?= csrfField() ?>
            <input type="hidden" name="post_action" value="enregistrer">

            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Facture *</label>
                    <select name="facture_id" class="form-select" required id="selectFacture" onchange="updateResteAPayer()">
                        <option value="">— Choisir une facture —</option>
                        <?php foreach ($facturesOuvertes as $fo): $reste = (float)$fo['montant_ttc'] - (float)$fo['montant_paye']; ?>
                            <option value="<?= $fo['id'] ?>"
                                    data-reste="<?= number_format($reste, 2, '.', '') ?>"
                                    <?= $preselectedFacture === $fo['id'] ? 'selected' : '' ?>>
                                <?= e($fo['numero']) ?> — <?= e($fo['client_entreprise'] ?: trim($fo['client_prenom'] . ' ' . $fo['client_nom'])) ?> — Reste : <?= formatMontant($reste) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Date *</label>
                    <input type="date" name="date_paiement" class="form-control" required value="<?= date('Y-m-d') ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Montant *</label>
                    <input type="number" name="montant" id="montantInput" class="form-control" step="0.01" min="0.01" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Mode de paiement *</label>
                    <select name="mode_paiement" class="form-select" required>
                        <option value="virement">Virement</option>
                        <option value="carte_bancaire">Carte bancaire</option>
                        <option value="especes">Espèces</option>
                        <option value="cheque">Chèque</option>
                        <option value="prelevement">Prélèvement</option>
                        <option value="paypal">PayPal</option>
                        <option value="autre">Autre</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Référence</label>
                    <input type="text" name="reference" class="form-control" placeholder="N° chèque, référence virement...">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Notes</label>
                    <input type="text" name="notes" class="form-control">
                </div>
            </div>

            <div class="d-flex gap-2 mt-4">
                <button type="submit" class="btn btn-success btn-lg"><i class="bi bi-check-lg"></i> Enregistrer le paiement</button>
                <a href="paiements.php" class="btn btn-outline-secondary btn-lg">Annuler</a>
            </div>
        </form>
    </div>
</div>

<script>
function updateResteAPayer() {
    const sel = document.getElementById('selectFacture');
    const opt = sel.options[sel.selectedIndex];
    const input = document.getElementById('montantInput');
    if (opt && opt.dataset.reste) {
        input.value = opt.dataset.reste;
        input.max = opt.dataset.reste;
    }
}
document.addEventListener('DOMContentLoaded', function() {
    if (document.getElementById('selectFacture').value) updateResteAPayer();
});
</script>

<?php endif; ?>

<?php include 'footer.php'; ?>

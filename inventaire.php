<?php
/**
 * Gestion des inventaires
 */
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/functions_caisse.php';
requireLogin();requireRole('admin', 'comptable');$titre = 'Inventaires';

$action = $_GET['action'] ?? 'liste';

// --- Actions POST ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        setFlash('danger', 'Jeton de sécurité invalide.');
        header('Location: ' . BASE_URL . 'inventaire.php');
        exit;
    }

    if ($action === 'nouveau') {
        $date = $_POST['date_inventaire'] ?? date('Y-m-d');
        $notes = trim($_POST['notes'] ?? '');
        $invId = creerInventaire($date, $notes);
        header('Location: ' . BASE_URL . 'inventaire.php?action=saisie&id=' . $invId);
        exit;
    }

    if ($action === 'saisie' && !empty($_GET['id'])) {
        $invId = (int)$_GET['id'];
        // Sauvegarder toutes les lignes
        if (!empty($_POST['produit_id']) && is_array($_POST['produit_id'])) {
            foreach ($_POST['produit_id'] as $i => $produitId) {
                $stockReel = (float)($_POST['stock_reel'][$i] ?? 0);
                ajouterLigneInventaire($invId, (int)$produitId, $stockReel);
            }
            setFlash('success', 'Inventaire sauvegardé.');
        }
        header('Location: ' . BASE_URL . 'inventaire.php?action=saisie&id=' . $invId);
        exit;
    }

    if ($action === 'valider' && !empty($_POST['id'])) {
        if (validerInventaire((int)$_POST['id'])) {
            setFlash('success', 'Inventaire validé. Le stock a été mis à jour.');
        } else {
            setFlash('danger', 'Impossible de valider cet inventaire.');
        }
        header('Location: ' . BASE_URL . 'inventaire.php');
        exit;
    }
}

include 'header.php';
?>

<?php if ($action === 'liste'): ?>
    <?php $inventaires = getInventaires(); ?>

    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2><i class="bi bi-clipboard-check"></i> Inventaires</h2>
        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalNouvelInventaire"><i class="bi bi-plus-lg"></i> Nouvel inventaire</button>
    </div>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>#</th>
                        <th>Date</th>
                        <th>Statut</th>
                        <th class="text-end">Nb produits</th>
                        <th>Notes</th>
                        <th class="text-center">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($inventaires)): ?>
                        <tr><td colspan="6" class="text-center text-muted py-4">Aucun inventaire</td></tr>
                    <?php else: foreach ($inventaires as $inv): ?>
                        <tr>
                            <td><?= $inv['id'] ?></td>
                            <td><?= formatDate($inv['date_inventaire']) ?></td>
                            <td>
                                <?php if ($inv['statut'] === 'en_cours'): ?>
                                    <span class="badge bg-warning">En cours</span>
                                <?php else: ?>
                                    <span class="badge bg-success">Validé</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end"><?= $inv['nb_lignes'] ?></td>
                            <td><small class="text-muted"><?= e($inv['notes'] ?? '') ?></small></td>
                            <td class="text-center">
                                <?php if ($inv['statut'] === 'en_cours'): ?>
                                    <a href="?action=saisie&id=<?= $inv['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil"></i> Saisir</a>
                                <?php else: ?>
                                    <a href="?action=voir&id=<?= $inv['id'] ?>" class="btn btn-sm btn-outline-info"><i class="bi bi-eye"></i> Voir</a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Modal nouvel inventaire -->
    <div class="modal fade" id="modalNouvelInventaire" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="post" action="?action=nouveau">
                    <?= csrfField() ?>
                    <div class="modal-header">
                        <h5 class="modal-title">Nouvel inventaire</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Date de l'inventaire</label>
                            <input type="date" name="date_inventaire" class="form-control" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Notes</label>
                            <textarea name="notes" class="form-control" rows="2"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                        <button type="submit" class="btn btn-primary"><i class="bi bi-plus-lg"></i> Créer</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

<?php elseif ($action === 'saisie' && !empty($_GET['id'])): ?>
    <?php
    $inv = getInventaire((int)$_GET['id']);
    if (!$inv || $inv['statut'] !== 'en_cours') { header('Location: ' . BASE_URL . 'inventaire.php'); exit; }
    $produits = getProduits(['actif' => 1]);
    $lignesExistantes = getLignesInventaire($inv['id']);
    $lignesMap = [];
    foreach ($lignesExistantes as $l) $lignesMap[$l['produit_id']] = $l;
    // Ne garder que les produits avec gestion de stock
    $produits = array_filter($produits, fn($p) => $p['gestion_stock']);
    ?>

    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2><i class="bi bi-clipboard-check"></i> Inventaire du <?= formatDate($inv['date_inventaire']) ?></h2>
        <a href="<?= BASE_URL ?>inventaire.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Retour</a>
    </div>

    <form method="post" action="?action=saisie&id=<?= $inv['id'] ?>">
        <?= csrfField() ?>
        <div class="card">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Produit</th>
                            <th class="text-end">Stock théorique</th>
                            <th class="text-end" style="width:150px">Stock réel</th>
                            <th class="text-end">Écart</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($produits as $p): ?>
                            <?php
                            $stockTheo = (float)$p['stock_actuel'];
                            $ligneExist = $lignesMap[$p['id']] ?? null;
                            $stockReel = $ligneExist ? (float)$ligneExist['stock_reel'] : $stockTheo;
                            $ecart = $stockReel - $stockTheo;
                            ?>
                            <tr>
                                <td>
                                    <input type="hidden" name="produit_id[]" value="<?= $p['id'] ?>">
                                    <?= e($p['nom']) ?>
                                    <?php if ($p['reference']): ?><br><small class="text-muted"><?= e($p['reference']) ?></small><?php endif; ?>
                                </td>
                                <td class="text-end"><?= number_format($stockTheo, 0, ',', ' ') ?> <?= e($p['unite']) ?></td>
                                <td class="text-end">
                                    <input type="number" step="0.01" name="stock_reel[]" class="form-control form-control-sm text-end inventaire-reel" data-theo="<?= $stockTheo ?>" value="<?= $stockReel ?>">
                                </td>
                                <td class="text-end ecart-cell <?= $ecart != 0 ? ($ecart > 0 ? 'text-success' : 'text-danger') : '' ?>">
                                    <?= ($ecart >= 0 ? '+' : '') . number_format($ecart, 0, ',', ' ') ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="mt-3 d-flex justify-content-between">
            <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Sauvegarder</button>
            <form method="post" action="?action=valider" onsubmit="return confirm('Valider l\'inventaire ? Le stock sera définitivement ajusté.')">
                <?= csrfField() ?>
                <input type="hidden" name="id" value="<?= $inv['id'] ?>">
                <button type="submit" class="btn btn-success"><i class="bi bi-check-circle"></i> Valider l'inventaire</button>
            </form>
        </div>
    </form>

    <script>
    document.querySelectorAll('.inventaire-reel').forEach(input => {
        input.addEventListener('input', function() {
            const theo = parseFloat(this.dataset.theo);
            const reel = parseFloat(this.value) || 0;
            const ecart = reel - theo;
            const cell = this.closest('tr').querySelector('.ecart-cell');
            cell.textContent = (ecart >= 0 ? '+' : '') + ecart.toFixed(0);
            cell.className = 'text-end ecart-cell ' + (ecart > 0 ? 'text-success' : ecart < 0 ? 'text-danger' : '');
        });
    });
    </script>

<?php elseif ($action === 'voir' && !empty($_GET['id'])): ?>
    <?php
    $inv = getInventaire((int)$_GET['id']);
    if (!$inv) { header('Location: ' . BASE_URL . 'inventaire.php'); exit; }
    $lignes = getLignesInventaire($inv['id']);
    ?>

    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2><i class="bi bi-clipboard-check"></i> Inventaire du <?= formatDate($inv['date_inventaire']) ?> <span class="badge bg-success">Validé</span></h2>
        <a href="<?= BASE_URL ?>inventaire.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Retour</a>
    </div>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Produit</th>
                        <th class="text-end">Stock théorique</th>
                        <th class="text-end">Stock réel</th>
                        <th class="text-end">Écart</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($lignes as $l): ?>
                        <tr>
                            <td>
                                <?= e($l['produit_nom']) ?>
                                <?php if ($l['produit_reference']): ?><br><small class="text-muted"><?= e($l['produit_reference']) ?></small><?php endif; ?>
                            </td>
                            <td class="text-end"><?= number_format((float)$l['stock_theorique'], 0, ',', ' ') ?></td>
                            <td class="text-end"><?= number_format((float)$l['stock_reel'], 0, ',', ' ') ?></td>
                            <td class="text-end <?= (float)$l['ecart'] != 0 ? ((float)$l['ecart'] > 0 ? 'text-success fw-bold' : 'text-danger fw-bold') : '' ?>">
                                <?= ((float)$l['ecart'] >= 0 ? '+' : '') . number_format((float)$l['ecart'], 0, ',', ' ') ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<?php include 'footer.php'; ?>

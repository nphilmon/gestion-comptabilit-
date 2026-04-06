<?php
/**
 * Clôture de caisse
 * Comptage des espèces, vérification des chèques, synchronisation comptabilité
 */
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/functions_caisse.php';
requireLogin();
requireRole('admin', 'comptable');
$titre = 'Clôture de caisse';

$action = $_GET['action'] ?? 'liste';

// --- Actions POST ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        setFlash('danger', 'Jeton de sécurité invalide.');
        header('Location: ' . BASE_URL . 'cloture_caisse.php');
        exit;
    }

    if ($action === 'cloturer') {
        $date = $_POST['date_cloture'] ?? date('Y-m-d');
        $especesReel = isset($_POST['montant_especes_reel']) && $_POST['montant_especes_reel'] !== '' ? (float)$_POST['montant_especes_reel'] : null;
        $nbChequesReel = isset($_POST['nb_cheques_reel']) && $_POST['nb_cheques_reel'] !== '' ? (int)$_POST['nb_cheques_reel'] : null;
        $notes = trim($_POST['notes'] ?? '');

        $clotureId = cloturerCaisse($date, $especesReel, $nbChequesReel, $notes);
        setFlash('success', 'Caisse clôturée avec succès.');
        header('Location: ' . BASE_URL . 'cloture_caisse.php?action=voir&id=' . $clotureId);
        exit;
    }

    if ($action === 'synchro' && !empty($_POST['id'])) {
        if (synchroComptaCloture((int)$_POST['id'])) {
            setFlash('success', 'Clôture synchronisée avec la comptabilité.');
        } else {
            setFlash('danger', 'Clôture déjà synchronisée ou introuvable.');
        }
        header('Location: ' . BASE_URL . 'cloture_caisse.php?action=voir&id=' . (int)$_POST['id']);
        exit;
    }
}

include 'header.php';
?>

<?php if ($action === 'liste'): ?>
    <?php $clotures = getCloturesCaisse(); ?>

    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2><i class="bi bi-lock"></i> Clôtures de caisse</h2>
        <a href="?action=nouvelle" class="btn btn-primary"><i class="bi bi-plus-lg"></i> Nouvelle clôture</a>
    </div>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Date</th>
                        <th class="text-end">Nb ventes</th>
                        <th class="text-end">Total</th>
                        <th class="text-end">Écart espèces</th>
                        <th class="text-end">Écart chèques</th>
                        <th class="text-center">Compta</th>
                        <th class="text-center">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($clotures)): ?>
                        <tr><td colspan="7" class="text-center text-muted py-4">Aucune clôture</td></tr>
                    <?php else: foreach ($clotures as $c): ?>
                        <tr>
                            <td><?= formatDate($c['date_cloture']) ?></td>
                            <td class="text-end"><?= $c['nb_notes'] ?></td>
                            <td class="text-end fw-bold"><?= formatMontant((float)$c['total_ventes']) ?></td>
                            <td class="text-end <?= (float)$c['ecart_especes'] != 0 ? 'text-danger fw-bold' : 'text-success' ?>">
                                <?= (float)$c['ecart_especes'] != 0 ? formatMontant((float)$c['ecart_especes']) : '✓' ?>
                            </td>
                            <td class="text-end <?= (int)$c['ecart_cheques'] != 0 ? 'text-danger fw-bold' : 'text-success' ?>">
                                <?= (int)$c['ecart_cheques'] != 0 ? ((int)$c['ecart_cheques'] > 0 ? '+' : '') . $c['ecart_cheques'] : '✓' ?>
                            </td>
                            <td class="text-center">
                                <?php if ($c['synchro_compta']): ?>
                                    <span class="badge bg-success"><i class="bi bi-check"></i> Sync</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary">Non</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <a href="?action=voir&id=<?= $c['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-eye"></i></a>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>

<?php elseif ($action === 'nouvelle'): ?>
    <?php
    $date = $_GET['date'] ?? date('Y-m-d');
    $stats = getStatsCaisseJour($date);
    ?>

    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2><i class="bi bi-lock"></i> Clôture de caisse</h2>
        <a href="<?= BASE_URL ?>cloture_caisse.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Retour</a>
    </div>

    <!-- Choix de date -->
    <div class="card mb-4">
        <div class="card-body py-2">
            <form method="get" class="row g-2 align-items-end">
                <input type="hidden" name="action" value="nouvelle">
                <div class="col-md-3">
                    <label class="form-label">Date à clôturer</label>
                    <input type="date" name="date" class="form-control form-control-sm" value="<?= e($date) ?>" onchange="this.form.submit()">
                </div>
                <div class="col-md-9">
                    <div class="row text-center">
                        <div class="col"><strong><?= $stats['nb_notes'] ?></strong><br><small class="text-muted">Ventes</small></div>
                        <div class="col"><strong class="text-success"><?= formatMontant($stats['total_ventes']) ?></strong><br><small class="text-muted">Total</small></div>
                        <div class="col"><strong><?= formatMontant($stats['especes']) ?></strong><br><small class="text-muted">Espèces</small></div>
                        <div class="col"><strong><?= formatMontant($stats['carte']) ?></strong><br><small class="text-muted">Carte</small></div>
                        <div class="col"><strong><?= formatMontant($stats['cheque']) ?></strong><br><small class="text-muted">Chèques (<?= $stats['nb_cheques'] ?>)</small></div>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <?php if ($stats['nb_notes'] === 0): ?>
        <div class="alert alert-warning"><i class="bi bi-info-circle"></i> Aucune vente terminée pour cette date.</div>
    <?php else: ?>

    <form method="post" action="?action=cloturer">
        <?= csrfField() ?>
        <input type="hidden" name="date_cloture" value="<?= e($date) ?>">

        <div class="row g-4">
            <!-- Comptage espèces -->
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header"><i class="bi bi-cash-stack"></i> Comptage des espèces</div>
                    <div class="card-body">
                        <p class="mb-2">Montant théorique : <strong class="text-primary"><?= formatMontant($stats['especes']) ?></strong></p>
                        <div class="mb-3">
                            <label class="form-label">Montant réel compté</label>
                            <input type="number" step="0.01" name="montant_especes_reel" class="form-control" placeholder="Comptez et saisissez le montant" id="especesReel">
                        </div>
                        <div id="ecartEspeces" class="alert alert-info d-none">
                            Écart : <strong id="ecartEspecesValeur"></strong>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Comptage chèques -->
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header"><i class="bi bi-file-earmark-text"></i> Vérification des chèques</div>
                    <div class="card-body">
                        <p class="mb-2">Nombre théorique : <strong class="text-primary"><?= $stats['nb_cheques'] ?></strong> chèque(s) pour <?= formatMontant($stats['cheque']) ?></p>
                        <div class="mb-3">
                            <label class="form-label">Nombre réel de chèques comptés</label>
                            <input type="number" name="nb_cheques_reel" class="form-control" placeholder="Comptez les chèques" id="chequesReel">
                        </div>
                        <div id="ecartCheques" class="alert alert-info d-none">
                            Écart : <strong id="ecartChequesValeur"></strong> chèque(s)
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-12">
                <div class="card">
                    <div class="card-body">
                        <label class="form-label">Notes / Observations</label>
                        <textarea name="notes" class="form-control" rows="2" placeholder="Commentaires sur la caisse du jour..."></textarea>
                    </div>
                </div>
            </div>
        </div>

        <div class="mt-3">
            <button type="submit" class="btn btn-primary btn-lg" onclick="return confirm('Clôturer la caisse pour le <?= formatDate($date) ?> ?')">
                <i class="bi bi-lock"></i> Clôturer la caisse
            </button>
        </div>
    </form>

    <script>
    document.getElementById('especesReel')?.addEventListener('input', function() {
        const theo = <?= $stats['especes'] ?>;
        const reel = parseFloat(this.value) || 0;
        const ecart = reel - theo;
        const el = document.getElementById('ecartEspeces');
        const val = document.getElementById('ecartEspecesValeur');
        el.classList.remove('d-none');
        val.textContent = (ecart >= 0 ? '+' : '') + ecart.toFixed(2) + ' €';
        el.className = 'alert ' + (Math.abs(ecart) < 0.01 ? 'alert-success' : 'alert-danger');
    });
    document.getElementById('chequesReel')?.addEventListener('input', function() {
        const theo = <?= $stats['nb_cheques'] ?>;
        const reel = parseInt(this.value) || 0;
        const ecart = reel - theo;
        const el = document.getElementById('ecartCheques');
        const val = document.getElementById('ecartChequesValeur');
        el.classList.remove('d-none');
        val.textContent = (ecart >= 0 ? '+' : '') + ecart;
        el.className = 'alert ' + (ecart === 0 ? 'alert-success' : 'alert-danger');
    });
    </script>
    <?php endif; ?>

<?php elseif ($action === 'voir' && !empty($_GET['id'])): ?>
    <?php
    $cloture = getClotureCaisse((int)$_GET['id']);
    if (!$cloture) { header('Location: ' . BASE_URL . 'cloture_caisse.php'); exit; }
    ?>

    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2><i class="bi bi-lock-fill"></i> Clôture du <?= formatDate($cloture['date_cloture']) ?></h2>
        <a href="<?= BASE_URL ?>cloture_caisse.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Retour</a>
    </div>

    <div class="row g-4">
        <div class="col-md-6">
            <div class="card">
                <div class="card-header"><i class="bi bi-receipt"></i> Résumé</div>
                <div class="card-body">
                    <table class="table table-sm mb-0">
                        <tr><th>Nombre de ventes</th><td class="text-end"><?= $cloture['nb_notes'] ?></td></tr>
                        <tr><th>Total des ventes</th><td class="text-end fw-bold text-success"><?= formatMontant((float)$cloture['total_ventes']) ?></td></tr>
                        <tr><td colspan="2"><hr class="my-1"></td></tr>
                        <tr><th>Espèces (théorique)</th><td class="text-end"><?= formatMontant((float)$cloture['montant_especes_theorique']) ?></td></tr>
                        <tr><th>Espèces (réel)</th><td class="text-end"><?= $cloture['montant_especes_reel'] !== null ? formatMontant((float)$cloture['montant_especes_reel']) : '—' ?></td></tr>
                        <tr>
                            <th>Écart espèces</th>
                            <td class="text-end <?= (float)$cloture['ecart_especes'] != 0 ? 'text-danger fw-bold' : 'text-success' ?>">
                                <?= formatMontant((float)$cloture['ecart_especes']) ?>
                            </td>
                        </tr>
                        <tr><td colspan="2"><hr class="my-1"></td></tr>
                        <tr><th>Carte bancaire</th><td class="text-end"><?= formatMontant((float)$cloture['montant_carte']) ?></td></tr>
                        <tr><th>Chèques (théorique)</th><td class="text-end"><?= $cloture['nb_cheques_theorique'] ?> chèque(s) — <?= formatMontant((float)$cloture['montant_cheque']) ?></td></tr>
                        <tr><th>Chèques (réel)</th><td class="text-end"><?= $cloture['nb_cheques_reel'] !== null ? $cloture['nb_cheques_reel'] . ' chèque(s)' : '—' ?></td></tr>
                        <tr>
                            <th>Écart chèques</th>
                            <td class="text-end <?= (int)$cloture['ecart_cheques'] != 0 ? 'text-danger fw-bold' : 'text-success' ?>">
                                <?= (int)$cloture['ecart_cheques'] != 0 ? ((int)$cloture['ecart_cheques'] > 0 ? '+' : '') . $cloture['ecart_cheques'] : '✓ OK' ?>
                            </td>
                        </tr>
                        <tr><th>Autres paiements</th><td class="text-end"><?= formatMontant((float)$cloture['montant_autre']) ?></td></tr>
                    </table>
                </div>
            </div>

            <?php if ($cloture['notes']): ?>
            <div class="card mt-3">
                <div class="card-body">
                    <strong>Notes :</strong> <?= e($cloture['notes']) ?>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <div class="col-md-6">
            <!-- Synchro comptabilité -->
            <div class="card">
                <div class="card-header"><i class="bi bi-arrow-repeat"></i> Synchronisation comptabilité</div>
                <div class="card-body">
                    <?php if ($cloture['synchro_compta']): ?>
                        <div class="alert alert-success mb-0">
                            <i class="bi bi-check-circle-fill"></i> Clôture importée en comptabilité.
                            <?php if ($cloture['transaction_id']): ?>
                                <br><small>Transaction #<?= $cloture['transaction_id'] ?></small>
                            <?php endif; ?>
                            <?php if ($cloture['transaction_ecart_id']): ?>
                                <br><small class="text-danger">Écriture erreur de caisse #<?= $cloture['transaction_ecart_id'] ?></small>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <p>En un clic, importez cette clôture dans la comptabilité.</p>
                        <form method="post" action="?action=synchro">
                            <?= csrfField() ?>
                            <input type="hidden" name="id" value="<?= $cloture['id'] ?>">
                            <button type="submit" class="btn btn-success" onclick="return confirm('Importer dans la comptabilité ?')">
                                <i class="bi bi-arrow-repeat"></i> Synchroniser maintenant
                            </button>
                        </form>
                        <small class="text-muted mt-2 d-block">
                            Cela créera une transaction de recette de <?= formatMontant((float)$cloture['total_ventes']) ?>.
                            <?php if ((float)$cloture['ecart_especes'] != 0): ?>
                                <br>Une écriture d'erreur de caisse de <?= formatMontant(abs((float)$cloture['ecart_especes'])) ?> sera aussi créée.
                            <?php endif; ?>
                        </small>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php include 'footer.php'; ?>

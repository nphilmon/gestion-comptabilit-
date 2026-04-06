<?php
/**
 * Caisse informatisée — Notes de caisse
 * Gestion des ventes en temps réel
 */
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/functions_commercial.php';
require_once __DIR__ . '/functions_caisse.php';
requireLogin();
requireRole('admin', 'comptable');
$titre = 'Caisse';

$action = $_GET['action'] ?? 'liste';

// --- Actions POST ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        setFlash('danger', 'Jeton de sécurité invalide.');
        header('Location: ' . BASE_URL . 'caisse.php');
        exit;
    }

    // Créer une nouvelle note
    if ($action === 'nouvelle') {
        $clientId = !empty($_POST['client_id']) ? (int)$_POST['client_id'] : null;
        $noteId = creerNoteCaisse($clientId);
        header('Location: ' . BASE_URL . 'caisse.php?action=note&id=' . $noteId);
        exit;
    }

    // Ajouter un produit à la note
    if ($action === 'ajouter_ligne' && !empty($_POST['note_id'])) {
        $noteId = (int)$_POST['note_id'];
        $produitId = !empty($_POST['produit_id']) ? (int)$_POST['produit_id'] : null;
        $data = [
            'produit_id' => $produitId,
            'designation' => trim($_POST['designation'] ?? ''),
            'quantite' => $_POST['quantite'] ?? 1,
            'prix_unitaire' => $_POST['prix_unitaire'] ?? 0,
            'taux_tva' => $_POST['taux_tva'] ?? 0,
        ];
        if (!empty($data['designation'])) {
            ajouterLigneNote($noteId, $data);
        }
        header('Location: ' . BASE_URL . 'caisse.php?action=note&id=' . $noteId);
        exit;
    }

    // Modifier quantité/prix d'une ligne
    if ($action === 'modifier_ligne' && !empty($_POST['ligne_id'])) {
        $ligneId = (int)$_POST['ligne_id'];
        $data = [
            'designation' => trim($_POST['designation'] ?? ''),
            'quantite' => $_POST['quantite'] ?? 1,
            'prix_unitaire' => $_POST['prix_unitaire'] ?? 0,
            'taux_tva' => $_POST['taux_tva'] ?? 0,
        ];
        modifierLigneNote($ligneId, $data);
        header('Location: ' . BASE_URL . 'caisse.php?action=note&id=' . (int)$_POST['note_id']);
        exit;
    }

    // Supprimer une ligne
    if ($action === 'supprimer_ligne' && !empty($_POST['ligne_id'])) {
        supprimerLigneNote((int)$_POST['ligne_id']);
        header('Location: ' . BASE_URL . 'caisse.php?action=note&id=' . (int)$_POST['note_id']);
        exit;
    }

    // Enregistrer un paiement
    if ($action === 'paiement' && !empty($_POST['note_id'])) {
        $noteId = (int)$_POST['note_id'];
        $moyenId = (int)$_POST['moyen_paiement_id'];
        $montant = (float)$_POST['montant_paiement'];
        $ref = trim($_POST['reference_paiement'] ?? '');
        if ($montant > 0 && $moyenId) {
            ajouterPaiementNote($noteId, $moyenId, $montant, $ref);
        }
        header('Location: ' . BASE_URL . 'caisse.php?action=note&id=' . $noteId);
        exit;
    }

    // Encaisser (terminer la note)
    if ($action === 'encaisser' && !empty($_POST['note_id'])) {
        $noteId = (int)$_POST['note_id'];
        if (encaisserNote($noteId)) {
            setFlash('success', 'Note encaissée avec succès !');
        } else {
            setFlash('danger', 'Impossible d\'encaisser : vérifiez que le montant payé couvre le total.');
        }
        header('Location: ' . BASE_URL . 'caisse.php?action=note&id=' . $noteId);
        exit;
    }

    // Annuler une note
    if ($action === 'annuler' && !empty($_POST['note_id'])) {
        annulerNote((int)$_POST['note_id']);
        setFlash('warning', 'Note annulée.');
        header('Location: ' . BASE_URL . 'caisse.php');
        exit;
    }
}

// --- API JSON rapide pour recherche produit (AJAX) ---
if ($action === 'api_produits') {
    header('Content-Type: application/json; charset=utf-8');
    $q = $_GET['q'] ?? '';
    $produits = getProduits(['recherche' => $q]);
    $result = [];
    foreach ($produits as $p) {
        $result[] = [
            'id' => $p['id'],
            'nom' => $p['nom'],
            'reference' => $p['reference'],
            'code_barres' => $p['code_barres'],
            'prix_vente' => (float)$p['prix_vente'],
            'taux_tva' => (float)$p['taux_tva'],
            'stock_actuel' => (float)$p['stock_actuel'],
            'gestion_stock' => (bool)$p['gestion_stock'],
        ];
    }
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    exit;
}

include 'header.php';
?>

<?php if ($action === 'liste'): ?>
    <?php
    $filtres = [];
    if (!empty($_GET['recherche'])) $filtres['recherche'] = $_GET['recherche'];
    if (!empty($_GET['statut'])) $filtres['statut'] = $_GET['statut'];
    if (!empty($_GET['date'])) $filtres['date'] = $_GET['date'];
    $notes = getNotesCaisse($filtres);

    // Notes en cours
    $notesEnCours = getNotesCaisse(['statut' => 'en_cours']);
    ?>

    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2><i class="bi bi-cart3"></i> Caisse</h2>
        <div>
            <a href="<?= BASE_URL ?>cloture_caisse.php" class="btn btn-outline-secondary"><i class="bi bi-lock"></i> Clôture</a>
            <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#modalNouvelleNote"><i class="bi bi-plus-lg"></i> Nouvelle note</button>
        </div>
    </div>

    <!-- Notes en cours (prioritaire) -->
    <?php if (!empty($notesEnCours)): ?>
    <div class="alert alert-info">
        <i class="bi bi-hourglass-split"></i> <strong><?= count($notesEnCours) ?> note(s) en cours :</strong>
        <?php foreach ($notesEnCours as $nc): ?>
            <a href="?action=note&id=<?= $nc['id'] ?>" class="btn btn-sm btn-outline-primary ms-2">
                <?= e($nc['numero']) ?> — <?= formatMontant((float)$nc['total_ttc']) ?>
            </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Filtres -->
    <div class="card mb-4">
        <div class="card-body py-2">
            <form method="get" class="row g-2 align-items-end">
                <div class="col-md-3">
                    <input type="text" name="recherche" class="form-control form-control-sm" placeholder="Rechercher..." value="<?= e($_GET['recherche'] ?? '') ?>">
                </div>
                <div class="col-md-2">
                    <select name="statut" class="form-select form-select-sm">
                        <option value="">Tous statuts</option>
                        <option value="en_cours" <?= ($_GET['statut'] ?? '') === 'en_cours' ? 'selected' : '' ?>>En cours</option>
                        <option value="terminee" <?= ($_GET['statut'] ?? '') === 'terminee' ? 'selected' : '' ?>>Terminée</option>
                        <option value="annulee" <?= ($_GET['statut'] ?? '') === 'annulee' ? 'selected' : '' ?>>Annulée</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <input type="date" name="date" class="form-control form-control-sm" value="<?= e($_GET['date'] ?? '') ?>">
                </div>
                <div class="col-md-5 text-end">
                    <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-search"></i></button>
                    <a href="?action=liste" class="btn btn-sm btn-outline-secondary">Effacer</a>
                </div>
            </form>
        </div>
    </div>

    <!-- Liste des notes -->
    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>N°</th>
                        <th>Date</th>
                        <th>Client</th>
                        <th>Statut</th>
                        <th class="text-end">Total TTC</th>
                        <th class="text-center">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($notes)): ?>
                        <tr><td colspan="6" class="text-center text-muted py-4">Aucune note de caisse</td></tr>
                    <?php else: foreach ($notes as $n): ?>
                        <tr>
                            <td><a href="?action=note&id=<?= $n['id'] ?>" class="fw-semibold"><?= e($n['numero']) ?></a></td>
                            <td><?= date('d/m/Y H:i', strtotime($n['date_note'])) ?></td>
                            <td>
                                <?php if ($n['client_nom']): ?>
                                    <?= e(($n['client_entreprise'] ? $n['client_entreprise'] . ' — ' : '') . $n['client_prenom'] . ' ' . $n['client_nom']) ?>
                                <?php else: ?>
                                    <span class="text-muted">Anonyme</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php
                                $statutBadge = ['en_cours' => 'warning', 'terminee' => 'success', 'annulee' => 'danger'];
                                $statutLabel = ['en_cours' => 'En cours', 'terminee' => 'Terminée', 'annulee' => 'Annulée'];
                                ?>
                                <span class="badge bg-<?= $statutBadge[$n['statut']] ?>"><?= $statutLabel[$n['statut']] ?></span>
                            </td>
                            <td class="text-end fw-bold"><?= formatMontant((float)$n['total_ttc']) ?></td>
                            <td class="text-center">
                                <a href="?action=note&id=<?= $n['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-eye"></i></a>
                                <?php if ($n['statut'] === 'terminee'): ?>
                                    <a href="<?= BASE_URL ?>ticket_caisse.php?id=<?= $n['id'] ?>" class="btn btn-sm btn-outline-info" title="Ticket PDF"><i class="bi bi-printer"></i></a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Modal nouvelle note -->
    <div class="modal fade" id="modalNouvelleNote" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="post" action="?action=nouvelle">
                    <?= csrfField() ?>
                    <div class="modal-header">
                        <h5 class="modal-title"><i class="bi bi-cart-plus"></i> Nouvelle note de caisse</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Client (optionnel)</label>
                            <select name="client_id" class="form-select">
                                <option value="">— Anonyme —</option>
                                <?php foreach (getClients() as $c): ?>
                                    <option value="<?= $c['id'] ?>"><?= e(clientNomComplet($c)) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-muted">Vérifiez si le membre est à jour de cotisation</small>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                        <button type="submit" class="btn btn-success"><i class="bi bi-plus-lg"></i> Créer la note</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

<?php elseif ($action === 'note' && !empty($_GET['id'])): ?>
    <?php
    $note = getNoteCaisse((int)$_GET['id']);
    if (!$note) { header('Location: ' . BASE_URL . 'caisse.php'); exit; }
    $lignes = getLignesNoteCaisse($note['id']);
    $paiements = getPaiementsNote($note['id']);
    $totalPaye = getTotalPayeNote($note['id']);
    $resteAPayer = max(0, (float)$note['total_ttc'] - $totalPaye);
    $moyens = getMoyensPaiementCaisse();
    $enCours = ($note['statut'] === 'en_cours');
    ?>

    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2>
            <i class="bi bi-receipt-cutoff"></i> Note <?= e($note['numero']) ?>
            <?php
            $statutBadge = ['en_cours' => 'warning', 'terminee' => 'success', 'annulee' => 'danger'];
            $statutLabel = ['en_cours' => 'En cours', 'terminee' => 'Terminée', 'annulee' => 'Annulée'];
            ?>
            <span class="badge bg-<?= $statutBadge[$note['statut']] ?> fs-6"><?= $statutLabel[$note['statut']] ?></span>
        </h2>
        <div>
            <?php if ($note['statut'] === 'terminee'): ?>
                <a href="<?= BASE_URL ?>ticket_caisse.php?id=<?= $note['id'] ?>" class="btn btn-outline-info"><i class="bi bi-printer"></i> Ticket PDF</a>
            <?php endif; ?>
            <a href="<?= BASE_URL ?>caisse.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Retour caisse</a>
        </div>
    </div>

    <div class="row mb-3">
        <div class="col-md-6">
            <small class="text-muted">
                <i class="bi bi-calendar"></i> <?= date('d/m/Y à H:i', strtotime($note['date_note'])) ?>
                <?php if ($note['client_nom']): ?>
                    | <i class="bi bi-person"></i> <?= e(($note['client_entreprise'] ? $note['client_entreprise'] . ' — ' : '') . $note['client_prenom'] . ' ' . $note['client_nom']) ?>
                <?php endif; ?>
            </small>
        </div>
    </div>

    <div class="row g-4">
        <!-- Colonne gauche : Lignes de la note -->
        <div class="col-lg-8">
            <!-- Ajout rapide de produit -->
            <?php if ($enCours): ?>
            <div class="card mb-3">
                <div class="card-header"><i class="bi bi-plus-circle"></i> Ajouter un article</div>
                <div class="card-body">
                    <form method="post" action="?action=ajouter_ligne" id="formAjoutProduit">
                        <?= csrfField() ?>
                        <input type="hidden" name="note_id" value="<?= $note['id'] ?>">
                        <input type="hidden" name="produit_id" id="produitIdHidden" value="">
                        <div class="row g-2">
                            <div class="col-md-5">
                                <input type="text" id="rechercheProduit" class="form-control form-control-sm" placeholder="Rechercher produit, scanner code-barres..." autocomplete="off">
                                <div id="resultsProduit" class="list-group position-absolute" style="z-index:1050; display:none;"></div>
                            </div>
                            <div class="col-md-3">
                                <input type="text" name="designation" id="designationProduit" class="form-control form-control-sm" placeholder="Désignation" required>
                            </div>
                            <div class="col-md-1">
                                <input type="number" name="quantite" class="form-control form-control-sm" value="1" min="0.01" step="0.01">
                            </div>
                            <div class="col-md-2">
                                <input type="number" step="0.01" name="prix_unitaire" id="prixProduit" class="form-control form-control-sm" placeholder="Prix" required>
                            </div>
                            <div class="col-md-1">
                                <input type="hidden" name="taux_tva" id="tvaProduit" value="0">
                                <button type="submit" class="btn btn-sm btn-success w-100"><i class="bi bi-plus"></i></button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
            <?php endif; ?>

            <!-- Tableau des lignes -->
            <div class="card">
                <div class="card-header"><i class="bi bi-list-ul"></i> Articles</div>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Désignation</th>
                                <th class="text-center">Qté</th>
                                <th class="text-end">P.U.</th>
                                <th class="text-end">Total TTC</th>
                                <?php if ($enCours): ?><th class="text-center" style="width:100px">Actions</th><?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($lignes)): ?>
                                <tr><td colspan="<?= $enCours ? 5 : 4 ?>" class="text-center text-muted py-4">Aucun article — ajoutez des produits ci-dessus</td></tr>
                            <?php else: foreach ($lignes as $l): ?>
                                <tr>
                                    <td>
                                        <?= e($l['designation']) ?>
                                        <?php if ($l['produit_reference']): ?><br><small class="text-muted"><?= e($l['produit_reference']) ?></small><?php endif; ?>
                                    </td>
                                    <td class="text-center"><?= number_format((float)$l['quantite'], 2, ',', '') ?></td>
                                    <td class="text-end"><?= formatMontant((float)$l['prix_unitaire']) ?></td>
                                    <td class="text-end fw-bold"><?= formatMontant((float)$l['montant_ttc']) ?></td>
                                    <?php if ($enCours): ?>
                                    <td class="text-center">
                                        <form method="post" action="?action=supprimer_ligne" class="d-inline">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="ligne_id" value="<?= $l['id'] ?>">
                                            <input type="hidden" name="note_id" value="<?= $note['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger" title="Supprimer"><i class="bi bi-trash"></i></button>
                                        </form>
                                    </td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                        <?php if (!empty($lignes)): ?>
                        <tfoot class="table-light">
                            <?php if ((float)$note['total_tva'] > 0): ?>
                            <tr>
                                <td colspan="<?= $enCours ? 3 : 2 ?>" class="text-end">Total HT</td>
                                <td class="text-end"><?= formatMontant((float)$note['total_ht']) ?></td>
                                <?php if ($enCours): ?><td></td><?php endif; ?>
                            </tr>
                            <tr>
                                <td colspan="<?= $enCours ? 3 : 2 ?>" class="text-end">TVA</td>
                                <td class="text-end"><?= formatMontant((float)$note['total_tva']) ?></td>
                                <?php if ($enCours): ?><td></td><?php endif; ?>
                            </tr>
                            <?php endif; ?>
                            <tr class="fw-bold fs-5">
                                <td colspan="<?= $enCours ? 3 : 2 ?>" class="text-end">Total TTC</td>
                                <td class="text-end text-primary"><?= formatMontant((float)$note['total_ttc']) ?></td>
                                <?php if ($enCours): ?><td></td><?php endif; ?>
                            </tr>
                        </tfoot>
                        <?php endif; ?>
                    </table>
                </div>
            </div>
        </div>

        <!-- Colonne droite : Paiements et actions -->
        <div class="col-lg-4">
            <!-- Résumé -->
            <div class="card mb-3 <?= $enCours ? 'border-warning' : ($note['statut'] === 'terminee' ? 'border-success' : 'border-danger') ?>">
                <div class="card-body text-center">
                    <h4 class="text-primary"><?= formatMontant((float)$note['total_ttc']) ?></h4>
                    <?php if ($totalPaye > 0): ?>
                        <p class="mb-1 text-success">Payé : <?= formatMontant($totalPaye) ?></p>
                    <?php endif; ?>
                    <?php if ($resteAPayer > 0 && $enCours): ?>
                        <p class="mb-0 text-danger fw-bold">Reste : <?= formatMontant($resteAPayer) ?></p>
                    <?php elseif ($note['statut'] === 'terminee'): ?>
                        <p class="mb-0 text-success"><i class="bi bi-check-circle-fill"></i> Encaissée</p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Paiements enregistrés -->
            <?php if (!empty($paiements)): ?>
            <div class="card mb-3">
                <div class="card-header"><i class="bi bi-credit-card"></i> Paiements</div>
                <ul class="list-group list-group-flush">
                    <?php foreach ($paiements as $p): ?>
                        <li class="list-group-item d-flex justify-content-between">
                            <span><?= e($p['moyen_nom']) ?></span>
                            <span class="fw-bold"><?= formatMontant((float)$p['montant']) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>

            <!-- Encaissement -->
            <?php if ($enCours && (float)$note['total_ttc'] > 0): ?>
            <div class="card mb-3">
                <div class="card-header"><i class="bi bi-wallet2"></i> Encaisser</div>
                <div class="card-body">
                    <form method="post" action="?action=paiement">
                        <?= csrfField() ?>
                        <input type="hidden" name="note_id" value="<?= $note['id'] ?>">
                        <div class="mb-2">
                            <select name="moyen_paiement_id" class="form-select form-select-sm" required>
                                <option value="">Moyen de paiement</option>
                                <?php foreach ($moyens as $m): ?>
                                    <option value="<?= $m['id'] ?>"><?= e($m['nom']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-2">
                            <input type="number" step="0.01" min="0.01" name="montant_paiement" class="form-control form-control-sm" placeholder="Montant" value="<?= number_format($resteAPayer, 2, '.', '') ?>" required>
                        </div>
                        <div class="mb-2">
                            <input type="text" name="reference_paiement" class="form-control form-control-sm" placeholder="Référence (optionnel)">
                        </div>
                        <button type="submit" class="btn btn-sm btn-primary w-100"><i class="bi bi-plus"></i> Ajouter paiement</button>
                    </form>

                    <?php if ($resteAPayer <= 0): ?>
                    <hr>
                    <form method="post" action="?action=encaisser">
                        <?= csrfField() ?>
                        <input type="hidden" name="note_id" value="<?= $note['id'] ?>">
                        <button type="submit" class="btn btn-success w-100 btn-lg"><i class="bi bi-check-circle"></i> Encaisser</button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Annuler -->
            <div class="card border-danger">
                <div class="card-body">
                    <form method="post" action="?action=annuler" onsubmit="return confirm('Annuler cette note ?')">
                        <?= csrfField() ?>
                        <input type="hidden" name="note_id" value="<?= $note['id'] ?>">
                        <button type="submit" class="btn btn-outline-danger btn-sm w-100"><i class="bi bi-x-circle"></i> Annuler la note</button>
                    </form>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($note['statut'] === 'terminee'): ?>
            <div class="card border-danger">
                <div class="card-body">
                    <form method="post" action="?action=annuler" onsubmit="return confirm('Annuler cette note terminée ? Le stock sera réajusté.')">
                        <?= csrfField() ?>
                        <input type="hidden" name="note_id" value="<?= $note['id'] ?>">
                        <button type="submit" class="btn btn-outline-danger btn-sm w-100"><i class="bi bi-x-circle"></i> Annuler la note</button>
                    </form>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

<?php elseif ($action === 'stats'): ?>
    <?php
    $annee = (int)($_GET['annee'] ?? date('Y'));
    $stats = getStatsCaisse($annee);
    ?>

    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2><i class="bi bi-bar-chart"></i> Statistiques Caisse <?= $annee ?></h2>
        <div>
            <a href="?action=stats&annee=<?= $annee - 1 ?>" class="btn btn-sm btn-outline-secondary">&laquo; <?= $annee - 1 ?></a>
            <a href="?action=stats&annee=<?= $annee + 1 ?>" class="btn btn-sm btn-outline-secondary"><?= $annee + 1 ?> &raquo;</a>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-md-6">
            <div class="card">
                <div class="card-header"><i class="bi bi-trophy"></i> Top 10 produits vendus</div>
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0">
                        <thead class="table-light">
                            <tr><th>Produit</th><th class="text-end">Qté</th><th class="text-end">CA</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($stats['top_produits'] as $tp): ?>
                                <tr>
                                    <td><?= e($tp['nom'] ?? 'Produit libre') ?></td>
                                    <td class="text-end"><?= number_format((float)$tp['total_qte'], 0, ',', ' ') ?></td>
                                    <td class="text-end fw-bold"><?= formatMontant((float)$tp['total_ca']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (empty($stats['top_produits'])): ?>
                                <tr><td colspan="3" class="text-center text-muted py-3">Aucune donnée</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="col-md-6">
            <div class="card mb-4">
                <div class="card-header"><i class="bi bi-credit-card"></i> Moyens de paiement</div>
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0">
                        <thead class="table-light">
                            <tr><th>Moyen</th><th class="text-end">Total</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($stats['top_moyens'] as $tm): ?>
                                <tr>
                                    <td><?= e($tm['nom']) ?></td>
                                    <td class="text-end fw-bold"><?= formatMontant((float)$tm['total']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (empty($stats['top_moyens'])): ?>
                                <tr><td colspan="2" class="text-center text-muted py-3">Aucune donnée</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="card">
                <div class="card-header"><i class="bi bi-graph-up"></i> CA mensuel caisse</div>
                <div class="card-body">
                    <canvas id="chartCaMensuel" height="200"></canvas>
                </div>
            </div>
        </div>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const data = <?= json_encode($stats['ca_mensuel']) ?>;
        const moisLabels = ['Jan','Fév','Mar','Avr','Mai','Juin','Juil','Aoû','Sep','Oct','Nov','Déc'];
        const labels = data.map(d => { const m = parseInt(d.mois.split('-')[1]); return moisLabels[m-1]; });
        const values = data.map(d => parseFloat(d.ca));

        if (typeof Chart !== 'undefined') {
            new Chart(document.getElementById('chartCaMensuel'), {
                type: 'bar',
                data: {
                    labels: labels,
                    datasets: [{ label: 'CA Caisse (€)', data: values, backgroundColor: '#3182ce' }]
                },
                options: { responsive: true, plugins: { legend: { display: false } } }
            });
        }
    });
    </script>
<?php endif; ?>

<?php include 'footer.php'; ?>

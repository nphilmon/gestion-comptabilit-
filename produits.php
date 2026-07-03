<?php
/**
 * Gestion des produits & stock
 */
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/functions_caisse.php';
requireLogin();
requireRole('admin', 'comptable');
$titre = 'Produits & Stock';

$action = $_GET['action'] ?? 'liste';

// --- Actions POST ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        setFlash('danger', 'Jeton de sécurité invalide.');
        header('Location: ' . BASE_URL . 'produits.php');
        exit;
    }
    if ($action === 'ajouter' || $action === 'modifier') {
        $data = [
            'reference' => trim($_POST['reference'] ?? ''),
            'code_barres' => trim($_POST['code_barres'] ?? ''),
            'nom' => trim($_POST['nom'] ?? ''),
            'description' => trim($_POST['description'] ?? ''),
            'categorie_produit_id' => $_POST['categorie_produit_id'] ?? null,
            'prix_achat' => $_POST['prix_achat'] ?? 0,
            'prix_vente' => $_POST['prix_vente'] ?? 0,
            'taux_tva' => $_POST['taux_tva'] ?? 0,
            'unite' => $_POST['unite'] ?? 'pièce',
            'stock_actuel' => $_POST['stock_actuel'] ?? 0,
            'stock_minimum' => $_POST['stock_minimum'] ?? 0,
        ];
        if (isset($_POST['gestion_stock'])) $data['gestion_stock'] = 1;

        if (empty($data['nom']) || (float)$data['prix_vente'] < 0) {
            setFlash('danger', 'Le nom et le prix de vente sont obligatoires.');
        } else {
            if ($action === 'ajouter') {
                ajouterProduit($data);
                setFlash('success', 'Produit ajouté avec succès.');
            } else {
                $id = (int)$_GET['id'];
                modifierProduit($id, $data);
                setFlash('success', 'Produit modifié avec succès.');
            }
            header('Location: ' . BASE_URL . 'produits.php');
            exit;
        }
    }

    if ($action === 'supprimer' && !empty($_POST['id'])) {
        supprimerProduit((int)$_POST['id']);
        setFlash('success', 'Produit désactivé.');
        header('Location: ' . BASE_URL . 'produits.php');
        exit;
    }

    if ($action === 'mouvement_stock' && !empty($_POST['produit_id'])) {
        $type = $_POST['type_mouvement'] ?? 'entree';
        $qte = (float)($_POST['quantite_mouvement'] ?? 0);
        $notes = trim($_POST['notes_mouvement'] ?? '');
        if ($qte > 0 && in_array($type, ['entree', 'sortie'])) {
            ajouterMouvementStock((int)$_POST['produit_id'], $type, $qte, $notes);
            setFlash('success', 'Mouvement de stock enregistré.');
        }
        header('Location: ' . BASE_URL . 'produits.php?action=voir&id=' . (int)$_POST['produit_id']);
        exit;
    }

    // Catégories produits
    if ($action === 'ajouter_categorie') {
        ajouterCategorieProduit($_POST);
        setFlash('success', 'Catégorie ajoutée.');
        header('Location: ' . BASE_URL . 'produits.php?action=categories');
        exit;
    }
    if ($action === 'modifier_categorie' && !empty($_GET['id'])) {
        modifierCategorieProduit((int)$_GET['id'], $_POST);
        setFlash('success', 'Catégorie modifiée.');
        header('Location: ' . BASE_URL . 'produits.php?action=categories');
        exit;
    }
}

include 'header.php';
?>

<?php if ($action === 'liste'): ?>
    <?php
    $filtres = [];
    if (!empty($_GET['recherche'])) $filtres['recherche'] = $_GET['recherche'];
    if (!empty($_GET['categorie_produit_id'])) $filtres['categorie_produit_id'] = $_GET['categorie_produit_id'];
    if (!empty($_GET['stock_bas'])) $filtres['stock_bas'] = true;
    $produits = getProduits($filtres);
    $categories = getCategoriesProduits();
    ?>

    <div class="hero-banner mb-4">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <h2 class="mb-1"><i class="bi bi-box-seam"></i> Produits & Stock</h2>
                <p class="text-muted mb-0">Catalogue produits et suivi des niveaux de stock</p>
            </div>
            <div class="d-flex gap-2">
                <a href="?action=categories" class="btn btn-outline-secondary document-action-btn"><i class="bi bi-tags"></i> Catégories</a>
                <a href="?action=ajouter" class="btn btn-primary document-action-btn"><i class="bi bi-plus-lg"></i> Nouveau produit</a>
            </div>
        </div>
    </div>

    <!-- Filtres -->
    <div class="card mb-4">
        <div class="card-body py-2">
            <form method="get" class="row g-2 align-items-end">
                <div class="col-md-4">
                    <input type="text" name="recherche" class="form-control form-control-sm" placeholder="Rechercher (nom, réf, code-barres)..." value="<?= e($_GET['recherche'] ?? '') ?>">
                </div>
                <div class="col-md-3">
                    <select name="categorie_produit_id" class="form-select form-select-sm">
                        <option value="">Toutes catégories</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?= $cat['id'] ?>" <?= ($_GET['categorie_produit_id'] ?? '') == $cat['id'] ? 'selected' : '' ?>><?= e($cat['nom']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="stock_bas" value="1" id="stockBas" <?= !empty($_GET['stock_bas']) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="stockBas">Stock bas</label>
                    </div>
                </div>
                <div class="col-md-3 text-end">
                    <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-search"></i> Filtrer</button>
                    <a href="?action=liste" class="btn btn-sm btn-outline-secondary">Effacer</a>
                </div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Réf.</th>
                        <th>Produit</th>
                        <th>Catégorie</th>
                        <th class="text-end">Prix vente</th>
                        <th class="text-end">Stock</th>
                        <th class="text-center">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($produits)): ?>
                        <tr><td colspan="6" class="text-center text-muted py-4">Aucun produit trouvé</td></tr>
                    <?php else: foreach ($produits as $p): ?>
                        <tr>
                            <td><small class="text-muted"><?= e($p['reference'] ?? '—') ?></small></td>
                            <td>
                                <a href="?action=voir&id=<?= $p['id'] ?>" class="text-decoration-none fw-semibold"><?= e($p['nom']) ?></a>
                                <?php if ($p['code_barres']): ?><br><small class="text-muted"><i class="bi bi-upc-scan"></i> <?= e($p['code_barres']) ?></small><?php endif; ?>
                            </td>
                            <td>
                                <?php if ($p['categorie_nom']): ?>
                                    <span class="badge" style="background-color: <?= e($p['categorie_couleur']) ?>"><?= e($p['categorie_nom']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end"><?= formatMontant((float)$p['prix_vente']) ?></td>
                            <td class="text-end">
                                <?php if ($p['gestion_stock']): ?>
                                    <?php
                                    $stockClass = '';
                                    if ((float)$p['stock_actuel'] <= 0) $stockClass = 'text-danger fw-bold';
                                    elseif ((float)$p['stock_actuel'] <= (float)$p['stock_minimum']) $stockClass = 'text-warning fw-bold';
                                    ?>
                                    <span class="<?= $stockClass ?>"><?= number_format((float)$p['stock_actuel'], 0, ',', ' ') ?></span>
                                    <small class="text-muted"><?= e($p['unite']) ?></small>
                                <?php else: ?>
                                    <small class="text-muted">—</small>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <a href="?action=modifier&id=<?= $p['id'] ?>" class="btn btn-sm btn-outline-primary" title="Modifier"><i class="bi bi-pencil"></i></a>
                                <a href="?action=voir&id=<?= $p['id'] ?>" class="btn btn-sm btn-outline-info" title="Détail"><i class="bi bi-eye"></i></a>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>

<?php elseif ($action === 'ajouter' || $action === 'modifier'): ?>
    <?php
    $produit = null;
    if ($action === 'modifier' && !empty($_GET['id'])) {
        $produit = getProduit((int)$_GET['id']);
        if (!$produit) { header('Location: ' . BASE_URL . 'produits.php'); exit; }
    }
    $categories = getCategoriesProduits();
    ?>

    <div class="hero-banner mb-4">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <h2 class="mb-1"><i class="bi bi-box-seam"></i> <?= $produit ? 'Modifier le produit' : 'Nouveau produit' ?></h2>
                <p class="text-muted mb-0">Renseignez les informations du produit</p>
            </div>
            <a href="<?= BASE_URL ?>produits.php" class="btn btn-outline-secondary document-action-btn"><i class="bi bi-arrow-left"></i> Retour</a>
        </div>
    </div>

    <div class="card">
        <div class="card-body">
            <form method="post" action="?action=<?= $action ?><?= $produit ? '&id=' . $produit['id'] : '' ?>">
                <?= csrfField() ?>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Nom du produit *</label>
                        <input type="text" name="nom" class="form-control" required value="<?= e($produit['nom'] ?? '') ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Référence</label>
                        <input type="text" name="reference" class="form-control" value="<?= e($produit['reference'] ?? '') ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Code-barres</label>
                        <input type="text" name="code_barres" class="form-control" value="<?= e($produit['code_barres'] ?? '') ?>">
                    </div>
                    <div class="col-md-12">
                        <label class="form-label">Description</label>
                        <textarea name="description" class="form-control" rows="2"><?= e($produit['description'] ?? '') ?></textarea>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Catégorie</label>
                        <select name="categorie_produit_id" class="form-select">
                            <option value="">— Aucune —</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?= $cat['id'] ?>" <?= ($produit['categorie_produit_id'] ?? '') == $cat['id'] ? 'selected' : '' ?>><?= e($cat['nom']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Prix d'achat (€)</label>
                        <input type="number" step="0.01" name="prix_achat" class="form-control" value="<?= $produit['prix_achat'] ?? '0.00' ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Prix de vente (€) *</label>
                        <input type="number" step="0.01" name="prix_vente" class="form-control" required value="<?= $produit['prix_vente'] ?? '0.00' ?>">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">TVA (%)</label>
                        <input type="number" step="0.01" name="taux_tva" class="form-control" value="<?= $produit['taux_tva'] ?? '0.00' ?>">
                    </div>

                    <div class="col-12"><hr></div>

                    <div class="col-md-2">
                        <div class="form-check mt-4">
                            <input class="form-check-input" type="checkbox" name="gestion_stock" id="gestionStock" <?= ($produit['gestion_stock'] ?? 1) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="gestionStock">Gérer le stock</label>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Unité</label>
                        <select name="unite" class="form-select">
                            <?php foreach (['pièce','kg','litre','mètre','lot','heure'] as $u): ?>
                                <option value="<?= $u ?>" <?= ($produit['unite'] ?? 'pièce') === $u ? 'selected' : '' ?>><?= ucfirst($u) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php if (!$produit): ?>
                    <div class="col-md-3">
                        <label class="form-label">Stock initial</label>
                        <input type="number" step="0.01" name="stock_actuel" class="form-control" value="0">
                    </div>
                    <?php endif; ?>
                    <div class="col-md-3">
                        <label class="form-label">Stock minimum (alerte)</label>
                        <input type="number" step="0.01" name="stock_minimum" class="form-control" value="<?= $produit['stock_minimum'] ?? '0' ?>">
                    </div>
                </div>

                <div class="mt-4">
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> <?= $produit ? 'Enregistrer' : 'Créer le produit' ?></button>
                    <a href="<?= BASE_URL ?>produits.php" class="btn btn-outline-secondary">Annuler</a>
                </div>
            </form>
        </div>
    </div>

<?php elseif ($action === 'voir' && !empty($_GET['id'])): ?>
    <?php
    $produit = getProduit((int)$_GET['id']);
    if (!$produit) { header('Location: ' . BASE_URL . 'produits.php'); exit; }
    $mouvements = getMouvementsStock($produit['id']);
    ?>

    <div class="hero-banner mb-4">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <h2 class="mb-1"><i class="bi bi-box-seam"></i> <?= e($produit['nom']) ?></h2>
            </div>
            <div class="d-flex gap-2">
                <a href="?action=modifier&id=<?= $produit['id'] ?>" class="btn btn-outline-primary document-action-btn"><i class="bi bi-pencil"></i> Modifier</a>
                <a href="<?= BASE_URL ?>produits.php" class="btn btn-outline-secondary document-action-btn"><i class="bi bi-arrow-left"></i> Retour</a>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-md-5">
            <div class="card">
                <div class="card-header"><i class="bi bi-info-circle"></i> Informations</div>
                <div class="card-body">
                    <table class="table table-sm mb-0">
                        <tr><th>Référence</th><td><?= e($produit['reference'] ?? '—') ?></td></tr>
                        <tr><th>Code-barres</th><td><?= e($produit['code_barres'] ?? '—') ?></td></tr>
                        <tr><th>Catégorie</th><td><?= e($produit['categorie_nom'] ?? '—') ?></td></tr>
                        <tr><th>Prix d'achat</th><td><?= formatMontant((float)$produit['prix_achat']) ?></td></tr>
                        <tr><th>Prix de vente</th><td class="fw-bold"><?= formatMontant((float)$produit['prix_vente']) ?></td></tr>
                        <tr><th>TVA</th><td><?= $produit['taux_tva'] ?>%</td></tr>
                        <?php if ($produit['gestion_stock']): ?>
                        <tr><th>Stock actuel</th><td class="fw-bold <?= (float)$produit['stock_actuel'] <= (float)$produit['stock_minimum'] ? 'text-danger' : 'text-success' ?>"><?= number_format((float)$produit['stock_actuel'], 0, ',', ' ') ?> <?= e($produit['unite']) ?></td></tr>
                        <tr><th>Stock minimum</th><td><?= number_format((float)$produit['stock_minimum'], 0, ',', ' ') ?> <?= e($produit['unite']) ?></td></tr>
                        <?php endif; ?>
                    </table>
                </div>
            </div>

            <?php if ($produit['gestion_stock']): ?>
            <div class="card mt-3">
                <div class="card-header"><i class="bi bi-arrow-left-right"></i> Mouvement de stock</div>
                <div class="card-body">
                    <form method="post" action="?action=mouvement_stock">
                        <?= csrfField() ?>
                        <input type="hidden" name="produit_id" value="<?= $produit['id'] ?>">
                        <div class="row g-2">
                            <div class="col-md-4">
                                <select name="type_mouvement" class="form-select form-select-sm">
                                    <option value="entree">+ Entrée</option>
                                    <option value="sortie">- Sortie</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <input type="number" step="0.01" min="0.01" name="quantite_mouvement" class="form-control form-control-sm" placeholder="Qté" required>
                            </div>
                            <div class="col-md-5">
                                <input type="text" name="notes_mouvement" class="form-control form-control-sm" placeholder="Motif...">
                            </div>
                        </div>
                        <button type="submit" class="btn btn-sm btn-primary mt-2"><i class="bi bi-check"></i> Enregistrer</button>
                    </form>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <div class="col-md-7">
            <div class="card">
                <div class="card-header"><i class="bi bi-clock-history"></i> Historique des mouvements</div>
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Date</th>
                                <th>Type</th>
                                <th class="text-end">Qté</th>
                                <th class="text-end">Stock</th>
                                <th>Notes</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($mouvements)): ?>
                                <tr><td colspan="5" class="text-center text-muted py-3">Aucun mouvement</td></tr>
                            <?php else: foreach ($mouvements as $m): ?>
                                <tr>
                                    <td><small><?= date('d/m/Y H:i', strtotime($m['created_at'])) ?></small></td>
                                    <td>
                                        <?php
                                        $labels = ['entree' => ['success', '+ Entrée'], 'sortie' => ['danger', '- Sortie'], 'vente' => ['info', 'Vente'], 'inventaire' => ['warning', 'Inventaire'], 'annulation_vente' => ['secondary', 'Annul.']];
                                        [$badge, $label] = $labels[$m['type']] ?? ['secondary', $m['type']];
                                        ?>
                                        <span class="badge bg-<?= $badge ?>"><?= $label ?></span>
                                    </td>
                                    <td class="text-end"><?= number_format((float)$m['quantite'], 2, ',', ' ') ?></td>
                                    <td class="text-end"><?= number_format((float)$m['stock_avant'], 0, ',', ' ') ?> → <?= number_format((float)$m['stock_apres'], 0, ',', ' ') ?></td>
                                    <td><small class="text-muted"><?= e($m['notes'] ?? '') ?></small></td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Suppression -->
            <div class="mt-3 text-end">
                <form method="post" action="?action=supprimer" onsubmit="return confirm('Désactiver ce produit ?')">
                    <?= csrfField() ?>
                    <input type="hidden" name="id" value="<?= $produit['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i> Désactiver le produit</button>
                </form>
            </div>
        </div>
    </div>

<?php elseif ($action === 'categories'): ?>
    <?php $categories = getCategoriesProduits(false); ?>

    <div class="hero-banner mb-4">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <h2 class="mb-1"><i class="bi bi-tags"></i> Catégories de produits</h2>
            </div>
            <a href="<?= BASE_URL ?>produits.php" class="btn btn-outline-secondary document-action-btn"><i class="bi bi-arrow-left"></i> Retour produits</a>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-md-5">
            <div class="card">
                <div class="card-header">Ajouter une catégorie</div>
                <div class="card-body">
                    <form method="post" action="?action=ajouter_categorie">
                        <?= csrfField() ?>
                        <div class="mb-2">
                            <input type="text" name="nom" class="form-control" placeholder="Nom" required>
                        </div>
                        <div class="mb-2">
                            <input type="text" name="description" class="form-control" placeholder="Description">
                        </div>
                        <div class="mb-2">
                            <input type="color" name="couleur" class="form-control form-control-color" value="#6c757d">
                        </div>
                        <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-plus"></i> Ajouter</button>
                    </form>
                </div>
            </div>
        </div>
        <div class="col-md-7">
            <div class="card">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr><th>Couleur</th><th>Nom</th><th>Description</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($categories as $cat): ?>
                                <tr>
                                    <td><span class="badge" style="background-color: <?= e($cat['couleur']) ?>">&nbsp;&nbsp;&nbsp;</span></td>
                                    <td><?= e($cat['nom']) ?></td>
                                    <td><small class="text-muted"><?= e($cat['description'] ?? '') ?></small></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php include 'footer.php'; ?>

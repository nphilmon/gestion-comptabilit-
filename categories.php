<?php
/**
 * Gestion des catégories
 */
require_once __DIR__ . '/functions.php';
requireLogin();
requireRole('admin', 'comptable');
$titre = 'Catégories';

$action = $_GET['action'] ?? 'liste';
$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

// --- Traitement du formulaire ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        setFlash('danger', 'Jeton de sécurité invalide.');
        header('Location: categories.php');
        exit;
    }

    $nom = trim($_POST['nom'] ?? '');
    $type = $_POST['type'] ?? 'recette';
    $description = trim($_POST['description'] ?? '');
    $couleur = $_POST['couleur'] ?? '#6c757d';

    $erreurs = [];
    if (empty($nom)) $erreurs[] = 'Le nom est requis.';
    if (!in_array($type, ['recette', 'depense'])) $erreurs[] = 'Type invalide.';
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $couleur)) $couleur = '#6c757d';

    if (empty($erreurs)) {
        $db = getDB();
        if ($action === 'modifier' && $id > 0) {
            $stmt = $db->prepare('UPDATE categories SET nom=?, type=?, description=?, couleur=? WHERE id=?');
            $stmt->execute([$nom, $type, $description, $couleur, $id]);
            setFlash('success', 'Catégorie modifiée.');
        } else {
            $stmt = $db->prepare('INSERT INTO categories (nom, type, description, couleur) VALUES (?, ?, ?, ?)');
            $stmt->execute([$nom, $type, $description, $couleur]);
            setFlash('success', 'Catégorie ajoutée.');
        }
        header('Location: categories.php');
        exit;
    }
}

// --- Suppression ---
if ($action === 'supprimer' && $id > 0) {
    if (isset($_GET['csrf']) && hash_equals(csrfToken(), $_GET['csrf'])) {
        $db = getDB();
        $stmt = $db->prepare('UPDATE categories SET actif = 0 WHERE id = ?');
        $stmt->execute([$id]);
        setFlash('success', 'Catégorie désactivée.');
    } else {
        setFlash('danger', 'Jeton invalide.');
    }
    header('Location: categories.php');
    exit;
}

// --- Chargement pour édition ---
$categorie = null;
if ($action === 'modifier' && $id > 0) {
    $categorie = getCategorie($id);
    if (!$categorie) {
        setFlash('danger', 'Catégorie introuvable.');
        header('Location: categories.php');
        exit;
    }
}

$categoriesRecette = getCategories('recette');
$categoriesDepense = getCategories('depense');

include 'header.php';
?>

<div class="hero-banner mb-4">
    <div class="d-flex justify-content-between align-items-center">
        <div>
            <h2 class="mb-1"><i class="bi bi-tags"></i> Catégories</h2>
            <p class="text-muted mb-0"><?= count($categoriesRecette) ?> recette<?= count($categoriesRecette) > 1 ? 's' : '' ?>, <?= count($categoriesDepense) ?> dépense<?= count($categoriesDepense) > 1 ? 's' : '' ?></p>
        </div>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalCategorie" onclick="resetForm()">
            <i class="bi bi-plus-lg"></i> Nouvelle catégorie
        </button>
    </div>
</div>

<!-- Stat cards -->
<div class="row g-3 mb-4">
    <div class="col-md-4 col-6">
        <div class="card border-0 stat-card h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-muted small text-uppercase">Total catégories</div>
                        <div class="fs-4 fw-bold"><?= count($categoriesRecette) + count($categoriesDepense) ?></div>
                    </div>
                    <div class="stat-icon bg-primary bg-opacity-10 text-primary">
                        <i class="bi bi-tags"></i>
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
                        <div class="text-muted small text-uppercase">Recettes</div>
                        <div class="fs-4 fw-bold text-success"><?= count($categoriesRecette) ?></div>
                    </div>
                    <div class="stat-icon bg-success bg-opacity-10 text-success">
                        <i class="bi bi-arrow-up-circle"></i>
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
                        <div class="text-muted small text-uppercase">Dépenses</div>
                        <div class="fs-4 fw-bold text-danger"><?= count($categoriesDepense) ?></div>
                    </div>
                    <div class="stat-icon bg-danger bg-opacity-10 text-danger">
                        <i class="bi bi-arrow-down-circle"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php if ($action === 'modifier' && $categorie): ?>
<!-- Formulaire d'édition -->
<div class="card border-0 mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-pencil"></i> Modifier : <?= e($categorie['nom']) ?></span>
        <a href="categories.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-x-lg"></i> Annuler</a>
    </div>
    <div class="card-body">
        <form method="POST" action="categories.php?action=modifier&id=<?= $categorie['id'] ?>">
            <?= csrfField() ?>
            <div class="row g-3 align-items-end">
                <div class="col-md-3">
                    <label class="form-label">Nom *</label>
                    <input type="text" name="nom" class="form-control" required value="<?= e($categorie['nom']) ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Type *</label>
                    <select name="type" class="form-select">
                        <option value="recette" <?= $categorie['type'] === 'recette' ? 'selected' : '' ?>>Recette</option>
                        <option value="depense" <?= $categorie['type'] === 'depense' ? 'selected' : '' ?>>Dépense</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Description</label>
                    <input type="text" name="description" class="form-control" value="<?= e($categorie['description'] ?? '') ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Couleur</label>
                    <input type="color" name="couleur" class="form-control form-control-color w-100" value="<?= e($categorie['couleur'] ?? '#6c757d') ?>">
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary w-100"><i class="bi bi-check-lg"></i> Enregistrer</button>
                </div>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<div class="row g-4">
    <!-- Recettes -->
    <div class="col-md-6">
        <div class="card border-0">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-arrow-up-circle text-success"></i> Catégories de recettes</span>
                <span class="badge bg-success"><?= count($categoriesRecette) ?></span>
            </div>
            <div class="list-group list-group-flush">
                <?php if (empty($categoriesRecette)): ?>
                    <div class="list-group-item text-muted text-center py-4">
                        <i class="bi bi-inbox"></i> Aucune catégorie de recette
                    </div>
                <?php else: ?>
                    <?php foreach ($categoriesRecette as $cat): ?>
                        <div class="list-group-item d-flex justify-content-between align-items-center">
                            <div class="d-flex align-items-center gap-2">
                                <span class="rounded-circle d-inline-block" style="width: 12px; height: 12px; background-color: <?= e($cat['couleur']) ?>"></span>
                                <div>
                                    <strong><?= e($cat['nom']) ?></strong>
                                    <?php if ($cat['description']): ?>
                                        <br><small class="text-muted"><?= e($cat['description']) ?></small>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="d-flex gap-1">
                                <a href="categories.php?action=modifier&id=<?= $cat['id'] ?>" class="btn btn-sm btn-outline-primary">
                                    <i class="bi bi-pencil"></i>
                                </a>
                                <a href="categories.php?action=supprimer&id=<?= $cat['id'] ?>&csrf=<?= csrfToken() ?>" 
                                   class="btn btn-sm btn-outline-danger"
                                   onclick="return confirm('Désactiver cette catégorie ?')">
                                    <i class="bi bi-trash"></i>
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Dépenses -->
    <div class="col-md-6">
        <div class="card border-0">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-arrow-down-circle text-danger"></i> Catégories de dépenses</span>
                <span class="badge bg-danger"><?= count($categoriesDepense) ?></span>
            </div>
            <div class="list-group list-group-flush">
                <?php if (empty($categoriesDepense)): ?>
                    <div class="list-group-item text-muted text-center py-4">
                        <i class="bi bi-inbox"></i> Aucune catégorie de dépense
                    </div>
                <?php else: ?>
                    <?php foreach ($categoriesDepense as $cat): ?>
                        <div class="list-group-item d-flex justify-content-between align-items-center">
                            <div class="d-flex align-items-center gap-2">
                                <span class="rounded-circle d-inline-block" style="width: 12px; height: 12px; background-color: <?= e($cat['couleur']) ?>"></span>
                                <div>
                                    <strong><?= e($cat['nom']) ?></strong>
                                    <?php if ($cat['description']): ?>
                                        <br><small class="text-muted"><?= e($cat['description']) ?></small>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="d-flex gap-1">
                                <a href="categories.php?action=modifier&id=<?= $cat['id'] ?>" class="btn btn-sm btn-outline-primary">
                                    <i class="bi bi-pencil"></i>
                                </a>
                                <a href="categories.php?action=supprimer&id=<?= $cat['id'] ?>&csrf=<?= csrfToken() ?>" 
                                   class="btn btn-sm btn-outline-danger"
                                   onclick="return confirm('Désactiver cette catégorie ?')">
                                    <i class="bi bi-trash"></i>
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Modal Ajout -->
<div class="modal fade" id="modalCategorie" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content border-0">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-plus-circle"></i> Nouvelle catégorie</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="categories.php?action=ajouter">
                <?= csrfField() ?>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-8">
                            <label class="form-label">Nom *</label>
                            <input type="text" name="nom" class="form-control" required>
                        </div>
                        <div class="col-4">
                            <label class="form-label">Couleur</label>
                            <input type="color" name="couleur" class="form-control form-control-color w-100" value="#3182ce">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Type *</label>
                            <select name="type" class="form-select">
                                <option value="recette">Recette</option>
                                <option value="depense">Dépense</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Description</label>
                            <input type="text" name="description" class="form-control" placeholder="Description optionnelle">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annuler</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Ajouter</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function resetForm() {
    const form = document.querySelector('#modalCategorie form');
    if (form) form.reset();
}
</script>

<?php include 'footer.php'; ?>

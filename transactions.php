<?php
/**
 * Gestion des transactions (CRUD)
 */
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/functions_intelligence.php';
requireLogin();
requireRole('admin', 'comptable');

// API auto-suggestion de catégorie
if (isset($_GET['api']) && $_GET['api'] === 'suggest_categorie') {
    requireLogin();
    apiSuggestionCategorie();
    exit;
}

$titre = 'Transactions';

$action = $_GET['action'] ?? 'liste';
$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

// --- Traitement des formulaires ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        setFlash('danger', 'Jeton de sécurité invalide.');
        header('Location: transactions.php');
        exit;
    }

    $postAction = $_POST['post_action'] ?? 'sauvegarder';
    if ($postAction === 'supprimer') {
        $deleteId = (int) ($_POST['id'] ?? 0);
        if ($deleteId > 0) {
            supprimerTransaction($deleteId);
            setFlash('success', 'Transaction supprimée.');
        }
        header('Location: transactions.php');
        exit;
    }

    $data = [
        'date_transaction'   => $_POST['date_transaction'] ?? '',
        'type'               => $_POST['type'] ?? 'recette',
        'categorie_id'       => !empty($_POST['categorie_id']) ? (int) $_POST['categorie_id'] : null,
        'description'        => trim($_POST['description'] ?? ''),
        'client_fournisseur' => trim($_POST['client_fournisseur'] ?? ''),
        'montant'            => (float) str_replace(',', '.', $_POST['montant'] ?? '0'),
        'mode_paiement'      => $_POST['mode_paiement'] ?? 'virement',
        'reference'          => trim($_POST['reference'] ?? ''),
        'notes'              => trim($_POST['notes'] ?? ''),
    ];

    // Validation
    $erreurs = [];
    if (empty($data['date_transaction'])) $erreurs[] = 'La date est requise.';
    if (empty($data['description'])) $erreurs[] = 'La description est requise.';
    if ($data['montant'] <= 0) $erreurs[] = 'Le montant doit être positif.';
    if (!in_array($data['type'], ['recette', 'depense'])) $erreurs[] = 'Type invalide.';
    if (!in_array($data['mode_paiement'], ['virement', 'cheque', 'especes', 'carte', 'paypal', 'autre'])) $erreurs[] = 'Mode de paiement invalide.';

    if (empty($erreurs)) {
        if ($action === 'modifier' && $id > 0) {
            modifierTransaction($id, $data);
            setFlash('success', 'Transaction modifiée avec succès.');
        } else {
            ajouterTransaction($data);
            setFlash('success', 'Transaction ajoutée avec succès.');
        }
        header('Location: transactions.php');
        exit;
    }
}

// --- Chargement pour édition ---
$transaction = null;
if (($action === 'modifier' || $action === 'voir') && $id > 0) {
    $transaction = getTransaction($id);
    if (!$transaction) {
        setFlash('danger', 'Transaction introuvable.');
        header('Location: transactions.php');
        exit;
    }
}

// --- Filtres pour la liste ---
$filtres = [];
if (!empty($_GET['type'])) $filtres['type'] = $_GET['type'];
if (!empty($_GET['mois'])) $filtres['mois'] = $_GET['mois'];
if (!empty($_GET['categorie_id'])) $filtres['categorie_id'] = (int) $_GET['categorie_id'];
if (!empty($_GET['recherche'])) $filtres['recherche'] = $_GET['recherche'];
if (!empty($_GET['annee'])) {
    $filtres['annee'] = (int) $_GET['annee'];
} else {
    $filtres['annee'] = (int) date('Y');
}

$transactions = getTransactions($filtres);
$categoriesRecette = getCategories('recette');
$categoriesDepense = getCategories('depense');
$toutesCategories = getCategories();

include 'header.php';
?>

<?php if ($action === 'ajouter' || $action === 'modifier'): ?>
    <!-- FORMULAIRE AJOUT / MODIFICATION -->
    <div class="hero-banner mb-4">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <h2 class="mb-1">
                    <i class="bi bi-<?= $action === 'ajouter' ? 'plus-circle' : 'pencil' ?>"></i>
                    <?= $action === 'ajouter' ? 'Nouvelle transaction' : 'Modifier la transaction' ?>
                </h2>
                <p class="text-muted mb-0">Renseignez les informations de la transaction</p>
            </div>
            <a href="transactions.php" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left"></i> Retour
            </a>
        </div>
    </div>

    <?php if (!empty($erreurs)): ?>
        <div class="alert alert-danger">
            <ul class="mb-0">
                <?php foreach ($erreurs as $err): ?>
                    <li><?= e($err) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <div class="card border-0">
        <div class="card-body">
            <form method="POST" action="transactions.php?action=<?= $action ?><?= $id ? '&id=' . $id : '' ?>">
                <?= csrfField() ?>
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">Date *</label>
                        <input type="date" name="date_transaction" class="form-control" required
                               value="<?= e($transaction['date_transaction'] ?? $data['date_transaction'] ?? date('Y-m-d')) ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Type *</label>
                        <select name="type" id="typeSelect" class="form-select" required>
                            <option value="recette" <?= ($transaction['type'] ?? $data['type'] ?? '') === 'recette' ? 'selected' : '' ?>>
                                Recette
                            </option>
                            <option value="depense" <?= ($transaction['type'] ?? $data['type'] ?? '') === 'depense' ? 'selected' : '' ?>>
                                Dépense
                            </option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Catégorie</label>
                        <select name="categorie_id" id="categorieSelect" class="form-select">
                            <option value="">-- Choisir --</option>
                            <?php foreach ($toutesCategories as $cat): ?>
                                <option value="<?= $cat['id'] ?>" 
                                        data-type="<?= $cat['type'] ?>"
                                        <?= (int)($transaction['categorie_id'] ?? $data['categorie_id'] ?? 0) === (int)$cat['id'] ? 'selected' : '' ?>>
                                    <?= e($cat['nom']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Montant (€) *</label>
                        <input type="number" name="montant" class="form-control" step="0.01" min="0.01" required
                               value="<?= e($transaction['montant'] ?? $data['montant'] ?? '') ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Description *</label>
                        <input type="text" name="description" id="descriptionInput" class="form-control" required maxlength="255"
                               value="<?= e($transaction['description'] ?? $data['description'] ?? '') ?>"
                               autocomplete="off">
                        <div id="suggestionCategorie" class="suggestion-categorie d-none mt-1">
                            <small class="d-flex align-items-center gap-1">
                                <i class="bi bi-magic text-primary"></i>
                                <span>Suggestion : <strong id="suggestionNom"></strong></span>
                                <span class="badge bg-primary-subtle text-primary" id="suggestionConfiance"></span>
                                <button type="button" class="btn btn-sm btn-outline-primary py-0 px-1" id="appliquerSuggestion">
                                    Appliquer
                                </button>
                            </small>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Client / Fournisseur</label>
                        <input type="text" name="client_fournisseur" class="form-control" maxlength="150"
                               value="<?= e($transaction['client_fournisseur'] ?? $data['client_fournisseur'] ?? '') ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Mode de paiement</label>
                        <select name="mode_paiement" class="form-select">
                            <?php
                            $modes = ['virement' => 'Virement', 'cheque' => 'Chèque', 'especes' => 'Espèces', 'carte' => 'Carte bancaire', 'paypal' => 'PayPal', 'autre' => 'Autre'];
                            foreach ($modes as $val => $label):
                            ?>
                                <option value="<?= $val ?>" <?= ($transaction['mode_paiement'] ?? $data['mode_paiement'] ?? 'virement') === $val ? 'selected' : '' ?>>
                                    <?= $label ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Référence</label>
                        <input type="text" name="reference" class="form-control" maxlength="100"
                               value="<?= e($transaction['reference'] ?? $data['reference'] ?? '') ?>">
                    </div>
                    <div class="col-md-9">
                        <label class="form-label">Notes</label>
                        <textarea name="notes" class="form-control" rows="2"><?= e($transaction['notes'] ?? $data['notes'] ?? '') ?></textarea>
                    </div>
                </div>
                <div class="mt-4">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-lg"></i> 
                        <?= $action === 'ajouter' ? 'Ajouter' : 'Enregistrer' ?>
                    </button>
                    <a href="transactions.php" class="btn btn-secondary ms-2">Annuler</a>
                </div>
            </form>
        </div>
    </div>

<?php else: ?>
    <!-- LISTE DES TRANSACTIONS -->
    <div class="hero-banner mb-4">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <h2 class="mb-1"><i class="bi bi-list-ul"></i> Transactions <?= $filtres['annee'] ?></h2>
                <p class="text-muted mb-0"><?= count($transactions) ?> transaction<?= count($transactions) > 1 ? 's' : '' ?> enregistrée<?= count($transactions) > 1 ? 's' : '' ?></p>
            </div>
            <a href="transactions.php?action=ajouter" class="btn btn-primary">
                <i class="bi bi-plus-lg"></i> Nouvelle transaction
            </a>
        </div>
    </div>

    <!-- Stat cards résumé -->
    <?php
    $totalR = array_sum(array_map(fn($t) => $t['type'] === 'recette' ? $t['montant'] : 0, $transactions));
    $totalD = array_sum(array_map(fn($t) => $t['type'] === 'depense' ? $t['montant'] : 0, $transactions));
    $solde = $totalR - $totalD;
    ?>
    <div class="row g-3 mb-4">
        <div class="col-md-3 col-6">
            <div class="card border-0 stat-card h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="text-muted small text-uppercase">Transactions</div>
                            <div class="fs-4 fw-bold"><?= count($transactions) ?></div>
                        </div>
                        <div class="stat-icon bg-secondary bg-opacity-10 text-secondary">
                            <i class="bi bi-arrow-left-right"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="card border-0 stat-card h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="text-muted small text-uppercase">Recettes</div>
                            <div class="fs-4 fw-bold text-success"><?= formatMontant($totalR) ?></div>
                        </div>
                        <div class="stat-icon bg-success bg-opacity-10 text-success">
                            <i class="bi bi-arrow-up-circle"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="card border-0 stat-card h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="text-muted small text-uppercase">Dépenses</div>
                            <div class="fs-4 fw-bold text-danger"><?= formatMontant($totalD) ?></div>
                        </div>
                        <div class="stat-icon bg-danger bg-opacity-10 text-danger">
                            <i class="bi bi-arrow-down-circle"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="card border-0 stat-card h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="text-muted small text-uppercase">Solde</div>
                            <div class="fs-4 fw-bold <?= $solde >= 0 ? 'text-primary' : 'text-warning' ?>"><?= formatMontant($solde) ?></div>
                        </div>
                        <div class="stat-icon <?= $solde >= 0 ? 'bg-primary' : 'bg-warning' ?> bg-opacity-10 <?= $solde >= 0 ? 'text-primary' : 'text-warning' ?>">
                            <i class="bi bi-wallet2"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Filtres -->
    <div class="card border-0 mb-4">
        <div class="card-body py-3">
            <form method="GET" class="row g-2 align-items-end">
                <div class="col-md-2 col-sm-6">
                    <label class="form-label form-label-sm">Année</label>
                    <select name="annee" class="form-select form-select-sm">
                        <?php foreach (getAnneesDisponibles() as $a): ?>
                            <option value="<?= $a ?>" <?= (int)($filtres['annee'] ?? date('Y')) === $a ? 'selected' : '' ?>><?= $a ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2 col-sm-6">
                    <label class="form-label form-label-sm">Type</label>
                    <select name="type" class="form-select form-select-sm">
                        <option value="">Tous</option>
                        <option value="recette" <?= ($filtres['type'] ?? '') === 'recette' ? 'selected' : '' ?>>Recettes</option>
                        <option value="depense" <?= ($filtres['type'] ?? '') === 'depense' ? 'selected' : '' ?>>Dépenses</option>
                    </select>
                </div>
                <div class="col-md-2 col-sm-6">
                    <label class="form-label form-label-sm">Mois</label>
                    <input type="month" name="mois" class="form-control form-control-sm" 
                           value="<?= e($filtres['mois'] ?? '') ?>">
                </div>
                <div class="col-md-2 col-sm-6">
                    <label class="form-label form-label-sm">Catégorie</label>
                    <select name="categorie_id" class="form-select form-select-sm">
                        <option value="">Toutes</option>
                        <?php foreach ($toutesCategories as $cat): ?>
                            <option value="<?= $cat['id'] ?>" <?= (int)($filtres['categorie_id'] ?? 0) === (int)$cat['id'] ? 'selected' : '' ?>>
                                <?= e($cat['nom']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2 col-sm-6">
                    <label class="form-label form-label-sm">Recherche</label>
                    <input type="text" name="recherche" class="form-control form-control-sm" 
                           placeholder="Description, client..." value="<?= e($filtres['recherche'] ?? '') ?>">
                </div>
                <div class="col-md-2 col-sm-6">
                    <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-search"></i> Filtrer</button>
                    <a href="transactions.php" class="btn btn-sm btn-outline-secondary">Réinit.</a>
                </div>
            </form>
        </div>
    </div>

    <!-- Tableau -->
    <div class="card border-0">
        <div class="card-body p-0">
            <?php if (empty($transactions)): ?>
                <div class="text-center empty-state">
                    <div class="empty-state-icon">
                        <i class="bi bi-inbox"></i>
                    </div>
                    <h3 class="empty-state-title">Aucune transaction trouvée</h3>
                    <p class="empty-state-text">
                        Commence par saisir une recette ou une dépense pour alimenter la comptabilité et les rapports.
                    </p>
                    <div class="empty-state-actions">
                        <a href="transactions.php?action=ajouter" class="btn btn-primary empty-state-btn"><i class="bi bi-plus-lg"></i> Ajouter une transaction</a>
                    </div>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Date</th>
                                <th>Type</th>
                                <th>Description</th>
                                <th>Catégorie</th>
                                <th>Client/Fournisseur</th>
                                <th>Paiement</th>
                                <th class="text-end">Montant</th>
                                <th class="text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($transactions as $t): ?>
                                <tr>
                                    <td class="text-nowrap"><?= formatDate($t['date_transaction']) ?></td>
                                    <td>
                                        <span class="badge <?= $t['type'] === 'recette' ? 'bg-success' : 'bg-danger' ?>">
                                            <?= $t['type'] === 'recette' ? 'Recette' : 'Dépense' ?>
                                        </span>
                                    </td>
                                    <td><?= e($t['description']) ?></td>
                                    <td>
                                        <?php if ($t['categorie_nom']): ?>
                                            <span class="badge" style="background-color: <?= e($t['categorie_couleur']) ?>">
                                                <?= e($t['categorie_nom']) ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= e($t['client_fournisseur'] ?? '') ?></td>
                                    <td><small class="text-muted"><?= e(ucfirst($t['mode_paiement'])) ?></small></td>
                                    <td class="text-end fw-bold <?= $t['type'] === 'recette' ? 'text-success' : 'text-danger' ?>">
                                        <?= $t['type'] === 'recette' ? '+' : '-' ?><?= formatMontant($t['montant']) ?>
                                    </td>
                                    <td class="text-center text-nowrap">
                                        <a href="transactions.php?action=modifier&id=<?= $t['id'] ?>" 
                                           class="btn btn-sm btn-outline-primary" title="Modifier">
                                            <i class="bi bi-pencil"></i>
                                        </a>
                                        <form method="post" action="transactions.php" class="d-inline" onsubmit="return confirm('Supprimer cette transaction ?')">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="post_action" value="supprimer">
                                            <input type="hidden" name="id" value="<?= $t['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger" title="Supprimer">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<?php include 'footer.php'; ?>

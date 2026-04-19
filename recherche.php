<?php
/**
 * Recherche globale
 */
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/functions_commercial.php';
require_once __DIR__ . '/functions_caisse.php';
require_once __DIR__ . '/functions_search.php';
requireLogin();
$titre = 'Recherche';

$q = trim($_GET['q'] ?? '');
$searchPayload = getSmartSearchResults($q);
$results = $searchPayload['results'];
$totalResults = $searchPayload['total_results'];
$smartSuggestions = $searchPayload['smart_suggestions'];
$searchContext = $searchPayload['search_context'];

include 'header.php';
?>

<div class="hero-banner mb-4">
    <h1 class="mb-1"><i class="bi bi-search"></i> Recherche</h1>
    <p class="mb-0">
        <?php if ($q): ?>
            <?= $totalResults ?> résultat(s) pour « <strong><?= e($q) ?></strong> »
        <?php else: ?>
            Rechercher des clients, factures, devis, transactions, produits...
        <?php endif; ?>
    </p>
</div>

<!-- Barre de recherche pleine page -->
<form method="GET" class="mb-4">
    <div class="input-group input-group-lg">
        <span class="input-group-text bg-white"><i class="bi bi-search text-muted"></i></span>
        <input type="text" class="form-control" name="q" value="<?= e($q) ?>" 
               placeholder="Rechercher un client, facture, devis, produit..." autofocus minlength="2">
        <button type="submit" class="btn btn-primary"><i class="bi bi-search"></i> Rechercher</button>
    </div>
</form>

<?php if (!empty($smartSuggestions)): ?>
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header d-flex align-items-center gap-2">
            <i class="bi bi-stars"></i>
            <span>Recherche intelligente</span>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <?php foreach (array_slice($smartSuggestions, 0, 4) as $suggestion): ?>
                <div class="col-lg-6">
                    <div class="priority-card priority-info h-100">
                        <span class="priority-icon"><i class="bi bi-lightbulb"></i></span>
                        <strong><?= e($suggestion['label']) ?></strong>
                        <span><?= e($suggestion['text']) ?></span>
                        <?php if (!empty($suggestion['link'])): ?>
                            <a href="<?= $suggestion['link'] ?>" class="priority-link text-decoration-none">
                                <?= e($suggestion['button'] ?? 'Ouvrir') ?> <i class="bi bi-arrow-right-short"></i>
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php if ($q && empty($results)): ?>
    <div class="text-center py-5">
        <i class="bi bi-search" style="font-size: 3rem; color: #cbd5e1;"></i>
        <p class="text-muted mt-3">Aucun résultat trouvé pour « <strong><?= e($q) ?></strong> »</p>
    </div>
<?php endif; ?>

<?php foreach ($results as $section => $items): ?>
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-header d-flex align-items-center gap-2">
            <i class="bi bi-<?= $items[0]['icon'] ?>"></i>
            <span><?= e($section) ?></span>
            <span class="badge bg-secondary ms-auto"><?= count($items) ?></span>
        </div>
        <div class="list-group list-group-flush">
            <?php foreach ($items as $item): ?>
            <a href="<?= $item['link'] ?>" class="list-group-item list-group-item-action d-flex align-items-center py-2">
                <div class="stat-icon bg-<?= $item['color'] ?>-subtle text-<?= $item['color'] ?> me-3" style="min-width:36px;">
                    <i class="bi bi-<?= $item['icon'] ?>"></i>
                </div>
                <div>
                    <div class="fw-semibold"><?= e($item['label']) ?></div>
                    <small class="text-muted"><?= e($item['detail']) ?></small>
                </div>
                <?php if (!empty($item['score'])): ?>
                    <span class="badge text-bg-light border ms-3 d-none d-md-inline">Score <?= (int) $item['score'] ?></span>
                <?php endif; ?>
                <i class="bi bi-chevron-right text-muted ms-auto"></i>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
<?php endforeach; ?>

<?php include 'footer.php'; ?>

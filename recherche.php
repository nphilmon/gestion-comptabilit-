<?php
/**
 * Recherche globale
 */
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/functions_commercial.php';
require_once __DIR__ . '/functions_caisse.php';
requireLogin();
$titre = 'Recherche';

$q = trim($_GET['q'] ?? '');

$results = [];
$totalResults = 0;

if (mb_strlen($q) >= 2) {
    $db = getDB();
    $like = '%' . $q . '%';

    // Clients
    $stmt = $db->prepare("SELECT id, COALESCE(entreprise, CONCAT(prenom, ' ', nom)) AS label, email, telephone FROM clients WHERE nom LIKE ? OR prenom LIKE ? OR entreprise LIKE ? OR email LIKE ? OR telephone LIKE ? LIMIT 10");
    $stmt->execute([$like, $like, $like, $like, $like]);
    $clients = $stmt->fetchAll();
    if ($clients) {
        $results['Clients'] = array_map(fn($r) => [
            'label' => $r['label'],
            'detail' => implode(' · ', array_filter([$r['email'], $r['telephone']])),
            'link' => BASE_URL . 'clients.php?action=voir&id=' . $r['id'],
            'icon' => 'person',
            'color' => 'primary'
        ], $clients);
    }

    // Factures
    $stmt = $db->prepare("SELECT f.id, f.numero, f.montant_ttc, f.statut, COALESCE(c.entreprise, CONCAT(c.prenom, ' ', c.nom)) AS client_nom FROM factures f LEFT JOIN clients c ON f.client_id = c.id WHERE f.numero LIKE ? OR c.nom LIKE ? OR c.entreprise LIKE ? LIMIT 10");
    $stmt->execute([$like, $like, $like]);
    $factures = $stmt->fetchAll();
    if ($factures) {
        $results['Factures'] = array_map(fn($r) => [
            'label' => $r['numero'] . ' — ' . ($r['client_nom'] ?? ''),
            'detail' => formatMontant((float)$r['montant_ttc']) . ' · ' . ucfirst($r['statut']),
            'link' => BASE_URL . 'factures.php?action=voir&id=' . $r['id'],
            'icon' => 'receipt',
            'color' => 'success'
        ], $factures);
    }

    // Devis
    $stmt = $db->prepare("SELECT d.id, d.numero, d.montant_ttc, d.statut, COALESCE(c.entreprise, CONCAT(c.prenom, ' ', c.nom)) AS client_nom FROM devis d LEFT JOIN clients c ON d.client_id = c.id WHERE d.numero LIKE ? OR c.nom LIKE ? OR c.entreprise LIKE ? LIMIT 10");
    $stmt->execute([$like, $like, $like]);
    $devis = $stmt->fetchAll();
    if ($devis) {
        $results['Devis'] = array_map(fn($r) => [
            'label' => $r['numero'] . ' — ' . ($r['client_nom'] ?? ''),
            'detail' => formatMontant((float)$r['montant_ttc']) . ' · ' . ucfirst($r['statut']),
            'link' => BASE_URL . 'devis.php?action=voir&id=' . $r['id'],
            'icon' => 'file-earmark-text',
            'color' => 'info'
        ], $devis);
    }

    // Transactions
    $stmt = $db->prepare("SELECT t.id, t.description, t.montant, t.type, t.date_transaction FROM transactions t WHERE t.description LIKE ? LIMIT 10");
    $stmt->execute([$like]);
    $transactions = $stmt->fetchAll();
    if ($transactions) {
        $results['Transactions'] = array_map(fn($r) => [
            'label' => $r['description'],
            'detail' => formatDate($r['date_transaction']) . ' · ' . formatMontant((float)$r['montant']),
            'link' => BASE_URL . 'transactions.php',
            'icon' => $r['type'] === 'recette' ? 'arrow-up-circle' : 'arrow-down-circle',
            'color' => $r['type'] === 'recette' ? 'success' : 'danger'
        ], $transactions);
    }

    // Produits
    $stmt = $db->prepare("SELECT id, nom, reference, prix_vente FROM produits WHERE nom LIKE ? OR reference LIKE ? LIMIT 10");
    $stmt->execute([$like, $like]);
    $produits = $stmt->fetchAll();
    if ($produits) {
        $results['Produits'] = array_map(fn($r) => [
            'label' => $r['nom'],
            'detail' => ($r['reference'] ? 'Réf: ' . $r['reference'] . ' · ' : '') . 'Prix: ' . formatMontant((float)$r['prix_vente']),
            'link' => BASE_URL . 'produits.php?action=modifier&id=' . $r['id'],
            'icon' => 'box-seam',
            'color' => 'warning'
        ], $produits);
    }

    foreach ($results as $items) {
        $totalResults += count($items);
    }
}

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
                <i class="bi bi-chevron-right text-muted ms-auto"></i>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
<?php endforeach; ?>

<?php include 'footer.php'; ?>

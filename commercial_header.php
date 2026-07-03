<?php
/**
 * Sous-navigation et bandeau commercial communs
 */
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/functions_commercial.php';

$currentCommercialPage = basename($_SERVER['SCRIPT_NAME'], '.php');
$currentCommercialAction = $action ?? 'liste';
$commercialDb = getDB();
$commercialStats = getStatsCommerciales();

$commercialCounts = [
    'clients' => (int) $commercialDb->query("SELECT COUNT(*) FROM clients WHERE actif = 1")->fetchColumn(),
    'devis' => (int) $commercialDb->query("SELECT COUNT(*) FROM devis")->fetchColumn(),
    'commandes' => (int) $commercialDb->query("SELECT COUNT(*) FROM commandes WHERE statut != 'annulee'")->fetchColumn(),
    'factures' => (int) $commercialDb->query("SELECT COUNT(*) FROM factures WHERE statut != 'annulee'")->fetchColumn(),
    'paiements' => (int) $commercialDb->query("SELECT COUNT(*) FROM paiements")->fetchColumn(),
];

$commandesOuvertes = (int) $commercialDb->query("SELECT COUNT(*) FROM commandes WHERE statut IN ('en_attente','confirmee','en_cours')")->fetchColumn();
$resteAEncaisser = (float) $commercialDb->query("SELECT COALESCE(SUM(montant_ttc - montant_paye), 0) FROM factures WHERE statut IN ('envoyee','partielle','en_retard')")->fetchColumn();

$commercialNav = [
    'clients' => ['label' => 'Clients', 'icon' => 'people', 'link' => 'clients.php'],
    'devis' => ['label' => 'Devis', 'icon' => 'file-earmark-text', 'link' => 'devis.php'],
    'commandes' => ['label' => 'Commandes', 'icon' => 'cart-check', 'link' => 'commandes.php'],
    'factures' => ['label' => 'Factures', 'icon' => 'receipt', 'link' => 'factures.php'],
    'paiements' => ['label' => 'Paiements', 'icon' => 'credit-card', 'link' => 'paiements.php'],
];

$commercialPageLabels = [
    'liste' => 'Vue de pilotage',
    'voir' => 'Fiche détaillée',
    'nouveau' => 'Création',
    'nouvelle' => 'Création',
    'ajouter' => 'Création',
    'modifier' => 'Modification',
];

$commercialTitles = [
    'clients' => 'Relation client',
    'devis' => 'Cycle de devis',
    'commandes' => 'Carnet de commandes',
    'factures' => 'Poste clients',
    'paiements' => 'Encaissements',
];
?>

<section class="commercial-shell mb-4">
    <div class="commercial-subnav">
        <?php foreach ($commercialNav as $key => $item): ?>
            <a href="<?= BASE_URL . $item['link'] ?>" class="commercial-subnav__link <?= $currentCommercialPage === $key ? 'is-active' : '' ?>">
                <i class="bi bi-<?= $item['icon'] ?>"></i>
                <span><?= e($item['label']) ?></span>
                <small><?= $commercialCounts[$key] ?></small>
            </a>
        <?php endforeach; ?>
    </div>

    <div class="commercial-overview">
        <div>
            <div class="commercial-overview__eyebrow"><?= e($commercialTitles[$currentCommercialPage] ?? 'Module commercial') ?></div>
            <div class="commercial-overview__title">
                <?= e($commercialNav[$currentCommercialPage]['label'] ?? 'Commercial') ?>
                <span><?= e($commercialPageLabels[$currentCommercialAction] ?? 'Espace de travail') ?></span>
            </div>
        </div>
        <div class="commercial-overview__metrics">
            <div class="commercial-indicator">
                <i class="bi bi-people-fill commercial-indicator__icon"></i>
                <div>
                    <small>Clients actifs</small>
                    <strong><?= $commercialCounts['clients'] ?></strong>
                </div>
            </div>
            <div class="commercial-indicator">
                <i class="bi bi-cash-coin commercial-indicator__icon"></i>
                <div>
                    <small>À encaisser</small>
                    <strong><?= formatMontant($resteAEncaisser) ?></strong>
                </div>
            </div>
            <div class="commercial-indicator">
                <i class="bi bi-cart-check-fill commercial-indicator__icon"></i>
                <div>
                    <small>Cmd ouvertes</small>
                    <strong><?= $commandesOuvertes ?></strong>
                </div>
            </div>
            <div class="commercial-indicator <?= $commercialStats['factures_en_retard'] > 0 ? 'commercial-indicator--alert' : '' ?>">
                <i class="bi bi-exclamation-triangle-fill commercial-indicator__icon"></i>
                <div>
                    <small>Factures en retard</small>
                    <strong><?= $commercialStats['factures_en_retard'] ?></strong>
                </div>
            </div>
        </div>
    </div>
</section>

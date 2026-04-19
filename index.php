<?php
/**
 * Tableau de bord principal - Vue d'ensemble
 */
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/functions_commercial.php';
$titre = 'Tableau de bord';

$annee = isset($_GET['annee']) ? (int) $_GET['annee'] : (int) date('Y');
$annees = getAnneesDisponibles();
if (!in_array($annee, $annees)) {
    $annees[] = $annee;
    sort($annees);
}

$stats = getStatsAnnee($annee);
$conf = getRegimeConfig();
$statsMensuelles = getStatsMensuelles($annee);
$recettesParCat = getStatsParCategorie($annee, 'recette');
$depensesParCat = getStatsParCategorie($annee, 'depense');
$statsCommerciales = getStatsCommerciales();

// Dernières transactions
$dernieres = array_slice(getTransactions(['annee' => $annee]), 0, 8);

// Derniers devis/factures
$derniersDevis = array_slice(getDevisList(), 0, 5);
$dernieresFactures = array_slice(getFacturesList(), 0, 5);

// Notifications / alertes
$notifications = [];
$actionsPrioritaires = [];
$insights = [];
$stockFaible = 0;
$tauxEncaissement = $statsCommerciales['ca_facture'] > 0
    ? round((($statsCommerciales['ca_facture'] - $statsCommerciales['en_attente_paiement']) / $statsCommerciales['ca_facture']) * 100)
    : null;

// Factures en retard
if ($statsCommerciales['factures_en_retard'] > 0) {
    $notifications[] = [
        'type' => 'danger',
        'icon' => 'exclamation-triangle-fill',
        'text' => $statsCommerciales['factures_en_retard'] . ' facture(s) en retard de paiement',
        'link' => BASE_URL . 'factures.php'
    ];
}

// Factures en attente
if ($statsCommerciales['en_attente_paiement'] > 0) {
    $notifications[] = [
        'type' => 'warning',
        'icon' => 'clock-fill',
        'text' => formatMontant($statsCommerciales['en_attente_paiement']) . ' en attente de paiement',
        'link' => BASE_URL . 'factures.php'
    ];
}

// Plafond CA micro
if ($stats['plafond_ca'] > 0 && $stats['pct_plafond'] > 80) {
    $notifications[] = [
        'type' => $stats['pct_plafond'] > 95 ? 'danger' : 'warning',
        'icon' => 'graph-up-arrow',
        'text' => 'CA à ' . $stats['pct_plafond'] . '% du plafond micro-entreprise',
        'link' => BASE_URL . 'rapports.php?annee=' . $annee
    ];
}

// Produits en stock faible
try {
    $db = getDB();
    $stockFaible = $db->query("SELECT COUNT(*) FROM produits WHERE gestion_stock = 1 AND actif = 1 AND stock_actuel <= seuil_alerte AND seuil_alerte > 0")->fetchColumn();
    if ($stockFaible > 0) {
        $notifications[] = [
            'type' => 'warning',
            'icon' => 'box-seam',
            'text' => $stockFaible . ' produit(s) en stock faible',
            'link' => BASE_URL . 'produits.php'
        ];
    }
} catch (\PDOException $e) {}

if (empty($dernieres)) {
    $actionsPrioritaires[] = [
        'icon' => 'journal-plus',
        'title' => 'Démarrer la saisie comptable',
        'text' => 'Aucune écriture trouvée pour ' . $annee . '. Commence par enregistrer tes premières recettes ou dépenses.',
        'link' => BASE_URL . 'transactions.php?action=ajouter&type=recette',
        'button' => 'Ajouter une écriture',
        'tone' => 'primary',
    ];
}

if (empty($derniersDevis) && empty($dernieresFactures)) {
    $actionsPrioritaires[] = [
        'icon' => 'file-earmark-plus',
        'title' => 'Lancer le cycle commercial',
        'text' => 'Le module devis et factures est prêt, mais aucun document récent n\'apparaît encore.',
        'link' => BASE_URL . 'devis.php?action=nouveau',
        'button' => 'Créer un devis',
        'tone' => 'info',
    ];
}

if ($statsCommerciales['factures_en_retard'] > 0) {
    $actionsPrioritaires[] = [
        'icon' => 'cash-stack',
        'title' => 'Relancer les impayés',
        'text' => $statsCommerciales['factures_en_retard'] . ' facture(s) en retard demandent un suivi pour sécuriser la trésorerie.',
        'link' => BASE_URL . 'factures.php?statut=en_retard',
        'button' => 'Voir les retards',
        'tone' => 'danger',
    ];
} elseif ($statsCommerciales['en_attente_paiement'] > 0) {
    $actionsPrioritaires[] = [
        'icon' => 'credit-card-2-front',
        'title' => 'Suivre les encaissements',
        'text' => formatMontant($statsCommerciales['en_attente_paiement']) . ' restent à encaisser sur les factures en cours.',
        'link' => BASE_URL . 'paiements.php',
        'button' => 'Suivre les paiements',
        'tone' => 'warning',
    ];
}

if ($stockFaible > 0) {
    $actionsPrioritaires[] = [
        'icon' => 'boxes',
        'title' => 'Surveiller le stock faible',
        'text' => $stockFaible . ' produit(s) approchent du seuil d\'alerte et méritent une vérification.',
        'link' => BASE_URL . 'produits.php',
        'button' => 'Contrôler le stock',
        'tone' => 'warning',
    ];
}

if (empty($actionsPrioritaires)) {
    $actionsPrioritaires[] = [
        'icon' => 'check2-circle',
        'title' => 'Tableau de bord sain',
        'text' => 'Aucune urgence détectée. C\'est le bon moment pour préparer un devis, rapprocher les écritures ou consulter les rapports.',
        'link' => BASE_URL . 'rapports.php?annee=' . $annee,
        'button' => 'Ouvrir les rapports',
        'tone' => 'success',
    ];
}

if ($tauxEncaissement !== null) {
    $insights[] = [
        'icon' => 'percent',
        'label' => 'Taux d\'encaissement',
        'value' => $tauxEncaissement . '%',
        'tone' => $tauxEncaissement >= 85 ? 'success' : ($tauxEncaissement >= 60 ? 'warning' : 'danger'),
    ];
}

if (!empty($dernieres)) {
    $insights[] = [
        'icon' => 'clock-history',
        'label' => 'Dernière écriture',
        'value' => formatDate($dernieres[0]['date_transaction']),
        'tone' => 'info',
    ];
}

if ($stats['benefice_net'] !== 0.0) {
    $insights[] = [
        'icon' => $stats['benefice_net'] >= 0 ? 'graph-up-arrow' : 'graph-down-arrow',
        'label' => $stats['benefice_net'] >= 0 ? 'Résultat positif' : 'Résultat à surveiller',
        'value' => formatMontant($stats['benefice_net']),
        'tone' => $stats['benefice_net'] >= 0 ? 'success' : 'danger',
    ];
}

if ($stats['plafond_ca'] > 0) {
    $insights[] = [
        'icon' => 'speedometer',
        'label' => 'Plafond micro',
        'value' => $stats['pct_plafond'] . '%',
        'tone' => $stats['pct_plafond'] > 95 ? 'danger' : ($stats['pct_plafond'] > 80 ? 'warning' : 'primary'),
    ];
}

include 'header.php';
?>

<!-- Hero / Welcome -->
<div class="hero-banner mb-4">
    <div class="row align-items-center">
        <div class="col-lg-7">
            <h2 class="mb-1"><i class="bi bi-speedometer2"></i> Tableau de bord <?= $annee ?></h2>
            <p class="text-muted mb-0">
                <?= e(getParam('nom_entreprise', 'Mon Activité')) ?> — <?= e(getRegimeLabel()) ?>
            </p>
            <div class="hero-metrics mt-3">
                <span class="hero-metric-pill">
                    <i class="bi bi-bell"></i>
                    <?= count($notifications) ?> alerte<?= count($notifications) > 1 ? 's' : '' ?>
                </span>
                <span class="hero-metric-pill">
                    <i class="bi bi-journal-text"></i>
                    <?= count($dernieres) ?> écriture<?= count($dernieres) > 1 ? 's' : '' ?> récente<?= count($dernieres) > 1 ? 's' : '' ?>
                </span>
                <span class="hero-metric-pill">
                    <i class="bi bi-receipt-cutoff"></i>
                    <?= $statsCommerciales['factures_en_retard'] ?> facture<?= $statsCommerciales['factures_en_retard'] > 1 ? 's' : '' ?> en retard
                </span>
            </div>
        </div>
        <div class="col-lg-5 text-lg-end mt-3 mt-lg-0">
            <div class="d-flex justify-content-lg-end gap-2 mb-2 flex-wrap">
                <div class="btn-group">
                    <?php foreach ($annees as $a): ?>
                        <a href="?annee=<?= $a ?>" class="btn btn-sm <?= $a === $annee ? 'btn-primary' : 'btn-outline-primary' ?>">
                            <?= $a ?>
                        </a>
                    <?php endforeach; ?>
                </div>
                <div class="dropdown">
                    <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown">
                        <i class="bi bi-download"></i> Export
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><a class="dropdown-item" href="<?= BASE_URL ?>export_csv.php?type=transactions&annee=<?= $annee ?>"><i class="bi bi-table"></i> Transactions <?= $annee ?></a></li>
                        <li><a class="dropdown-item" href="<?= BASE_URL ?>export_csv.php?type=factures"><i class="bi bi-receipt"></i> Factures</a></li>
                        <li><a class="dropdown-item" href="<?= BASE_URL ?>export_csv.php?type=clients"><i class="bi bi-people"></i> Clients</a></li>
                        <li><a class="dropdown-item" href="<?= BASE_URL ?>export_csv.php?type=produits"><i class="bi bi-box-seam"></i> Produits</a></li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-lg-8">
        <div class="card border-0 h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-stars"></i> Priorités du moment</span>
                <small class="text-muted">Ce qui mérite ton attention en premier</small>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <?php foreach (array_slice($actionsPrioritaires, 0, 3) as $action): ?>
                    <div class="col-md-6 col-xl-4">
                        <a href="<?= $action['link'] ?>" class="priority-card priority-<?= $action['tone'] ?>">
                            <span class="priority-icon"><i class="bi bi-<?= $action['icon'] ?>"></i></span>
                            <strong><?= e($action['title']) ?></strong>
                            <span><?= e($action['text']) ?></span>
                            <span class="priority-link"><?= e($action['button']) ?> <i class="bi bi-arrow-right-short"></i></span>
                        </a>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card border-0 h-100">
            <div class="card-header">
                <i class="bi bi-activity"></i> Vue rapide
            </div>
            <div class="card-body">
                <div class="insight-stack">
                    <?php foreach ($insights as $insight): ?>
                    <div class="insight-item insight-<?= $insight['tone'] ?>">
                        <div class="insight-icon"><i class="bi bi-<?= $insight['icon'] ?>"></i></div>
                        <div>
                            <small><?= e($insight['label']) ?></small>
                            <div class="fw-semibold"><?= e($insight['value']) ?></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php if (!empty($notifications)): ?>
<div class="mb-4">
    <?php foreach ($notifications as $n): ?>
    <a href="<?= $n['link'] ?>" class="alert alert-<?= $n['type'] ?> d-flex align-items-center py-2 mb-2 text-decoration-none">
        <i class="bi bi-<?= $n['icon'] ?> me-2"></i>
        <span><?= e($n['text']) ?></span>
        <i class="bi bi-chevron-right ms-auto"></i>
    </a>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Cartes résumé comptable -->
<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="card stat-card border-0 h-100">
            <div class="card-body">
                <div class="d-flex align-items-center mb-2">
                    <div class="stat-icon bg-success-subtle text-success"><i class="bi bi-arrow-up-circle-fill"></i></div>
                    <small class="text-muted ms-2">Chiffre d'affaires</small>
                </div>
                <h3 class="text-success mb-1"><?= formatMontant($stats['total_recettes']) ?></h3>
                <?php if ($stats['plafond_ca'] > 0): ?>
                <div class="progress mt-2" style="height: 5px;">
                    <div class="progress-bar bg-success" style="width: <?= min($stats['pct_plafond'], 100) ?>%"></div>
                </div>
                <small class="text-muted"><?= $stats['pct_plafond'] ?>% du plafond</small>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card border-0 h-100">
            <div class="card-body">
                <div class="d-flex align-items-center mb-2">
                    <div class="stat-icon bg-danger-subtle text-danger"><i class="bi bi-arrow-down-circle-fill"></i></div>
                    <small class="text-muted ms-2">Dépenses</small>
                </div>
                <h3 class="text-danger mb-1"><?= formatMontant($stats['total_depenses']) ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card border-0 h-100">
            <div class="card-body">
                <div class="d-flex align-items-center mb-2">
                    <div class="stat-icon bg-warning-subtle text-warning"><i class="bi bi-bank"></i></div>
                    <small class="text-muted ms-2">
                        <?= $conf['is_micro'] ? 'Charges obligatoires' : ($conf['tva_applicable'] ? 'TVA à reverser' : 'Résultat brut') ?>
                    </small>
                </div>
                <?php if ($conf['is_micro']): ?>
                    <h3 class="text-warning mb-1"><?= formatMontant($stats['charges_obligatoires']) ?></h3>
                    <small class="text-muted">URSSAF + CFP<?= $stats['versement_liberatoire'] > 0 ? ' + VL' : '' ?></small>
                <?php elseif ($conf['tva_applicable']): ?>
                    <h3 class="text-warning mb-1"><?= formatMontant($stats['tva_a_payer']) ?></h3>
                    <small class="text-muted">Collectée: <?= formatMontant($stats['tva_collectee']) ?></small>
                <?php else: ?>
                    <h3 class="text-warning mb-1"><?= formatMontant($stats['benefice_avant_charges']) ?></h3>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card border-0 h-100">
            <div class="card-body">
                <div class="d-flex align-items-center mb-2">
                    <div class="stat-icon bg-info-subtle text-info"><i class="bi bi-wallet2"></i></div>
                    <small class="text-muted ms-2"><?= $conf['is_micro'] ? 'Bénéfice net estimé' : 'Résultat net' ?></small>
                </div>
                <h3 class="<?= $stats['benefice_net'] >= 0 ? 'text-info' : 'text-danger' ?> mb-1">
                    <?= formatMontant($stats['benefice_net']) ?>
                </h3>
                <?php if ($conf['is_micro']): ?>
                <small class="text-muted">Imposable: <?= formatMontant($stats['revenu_imposable']) ?></small>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Section commerciale rapide -->
<div class="row g-3 mb-4">
    <div class="col-md-2">
        <div class="card border-0 text-center h-100">
            <div class="card-body py-3">
                <div class="fs-4 text-primary fw-bold"><?= $statsCommerciales['devis_total'] ?></div>
                <small class="text-muted">Devis</small>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card border-0 text-center h-100">
            <div class="card-body py-3">
                <div class="fs-4 text-success fw-bold"><?= $statsCommerciales['devis_acceptes'] ?></div>
                <small class="text-muted">Acceptés</small>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card border-0 text-center h-100">
            <div class="card-body py-3">
                <div class="fs-4 text-primary fw-bold"><?= formatMontant($statsCommerciales['ca_facture']) ?></div>
                <small class="text-muted">CA facturé</small>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card border-0 text-center h-100">
            <div class="card-body py-3">
                <div class="fs-4 text-warning fw-bold"><?= formatMontant($statsCommerciales['en_attente_paiement']) ?></div>
                <small class="text-muted">En attente</small>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card border-0 text-center h-100 <?= $statsCommerciales['factures_en_retard'] > 0 ? 'border-danger' : '' ?>">
            <div class="card-body py-3">
                <div class="fs-4 <?= $statsCommerciales['factures_en_retard'] > 0 ? 'text-danger' : 'text-muted' ?> fw-bold"><?= $statsCommerciales['factures_en_retard'] ?></div>
                <small class="text-muted">En retard</small>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card border-0 text-center h-100">
            <div class="card-body py-3">
                <div class="fs-4 text-info fw-bold"><?= $statsCommerciales['clients_actifs'] ?></div>
                <small class="text-muted">Clients actifs</small>
            </div>
        </div>
    </div>
</div>

<!-- Graphiques -->
<div class="row g-3 mb-4">
    <div class="col-md-8">
        <div class="card border-0">
            <div class="card-header">
                <i class="bi bi-graph-up"></i> Évolution mensuelle <?= $annee ?>
            </div>
            <div class="card-body">
                <canvas id="chartMensuel" height="280"></canvas>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card border-0 mb-3">
            <div class="card-header"><i class="bi bi-pie-chart"></i> Recettes par catégorie</div>
            <div class="card-body">
                <?php if (!empty($recettesParCat)): ?>
                    <canvas id="chartRecettes" height="180"></canvas>
                <?php else: ?>
                    <p class="text-muted text-center mb-0">Aucune recette</p>
                <?php endif; ?>
            </div>
        </div>
        <div class="card border-0">
            <div class="card-header"><i class="bi bi-pie-chart"></i> Dépenses par catégorie</div>
            <div class="card-body">
                <?php if (!empty($depensesParCat)): ?>
                    <canvas id="chartDepenses" height="180"></canvas>
                <?php else: ?>
                    <p class="text-muted text-center mb-0">Aucune dépense</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Activité récente : 3 colonnes -->
<div class="row g-3 mb-4">
    <!-- Dernières transactions -->
    <div class="col-md-6">
        <div class="card border-0">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-clock-history"></i> Dernières écritures</span>
                <a href="<?= BASE_URL ?>transactions.php" class="btn btn-sm btn-outline-primary">Voir tout</a>
            </div>
            <div class="card-body p-0">
                <?php if (empty($dernieres)): ?>
                    <div class="text-center py-4 text-muted">
                        <i class="bi bi-inbox" style="font-size: 2rem;"></i>
                        <p class="mt-2 mb-0">Aucune écriture pour <?= $annee ?>.</p>
                    </div>
                <?php else: ?>
                    <div class="list-group list-group-flush">
                        <?php foreach ($dernieres as $t): ?>
                            <div class="list-group-item d-flex justify-content-between align-items-center py-2">
                                <div>
                                    <div class="fw-semibold" style="font-size: 0.9rem;"><?= e($t['description']) ?></div>
                                    <small class="text-muted">
                                        <?= formatDate($t['date_transaction']) ?>
                                        <?php if ($t['categorie_nom']): ?>
                                            · <span class="badge" style="background-color: <?= e($t['categorie_couleur']) ?>; font-size: 0.65rem;"><?= e($t['categorie_nom']) ?></span>
                                        <?php endif; ?>
                                    </small>
                                </div>
                                <span class="fw-bold <?= $t['type'] === 'recette' ? 'text-success' : 'text-danger' ?>">
                                    <?= $t['type'] === 'recette' ? '+' : '-' ?><?= formatMontant($t['montant']) ?>
                                </span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Derniers devis + factures -->
    <div class="col-md-3">
        <div class="card border-0 mb-3">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-file-earmark-text"></i> Derniers devis</span>
                <a href="<?= BASE_URL ?>devis.php" class="btn btn-sm btn-outline-primary py-0"><i class="bi bi-arrow-right"></i></a>
            </div>
            <div class="list-group list-group-flush">
                <?php if (empty($derniersDevis)): ?>
                    <div class="list-group-item text-muted text-center py-3"><small>Aucun devis</small></div>
                <?php else: foreach ($derniersDevis as $d): $sd = getStatutDevisLabel($d['statut']); ?>
                    <a href="devis.php?action=voir&id=<?= $d['id'] ?>" class="list-group-item list-group-item-action py-2">
                        <div class="d-flex justify-content-between">
                            <small class="fw-semibold"><?= e($d['numero']) ?></small>
                            <span class="badge bg-<?= $sd['class'] ?>" style="font-size: 0.65rem;"><?= $sd['label'] ?></span>
                        </div>
                        <small class="text-muted"><?= e($d['client_entreprise'] ?: trim(($d['client_prenom'] ?? '') . ' ' . ($d['client_nom'] ?? ''))) ?></small>
                        <div class="text-end"><small class="fw-bold"><?= formatMontant($d['montant_ttc']) ?></small></div>
                    </a>
                <?php endforeach; endif; ?>
            </div>
        </div>
    </div>

    <div class="col-md-3">
        <div class="card border-0 mb-3">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-receipt"></i> Dernières factures</span>
                <a href="<?= BASE_URL ?>factures.php" class="btn btn-sm btn-outline-primary py-0"><i class="bi bi-arrow-right"></i></a>
            </div>
            <div class="list-group list-group-flush">
                <?php if (empty($dernieresFactures)): ?>
                    <div class="list-group-item text-muted text-center py-3"><small>Aucune facture</small></div>
                <?php else: foreach ($dernieresFactures as $f): $sf = getStatutFactureLabel($f['statut']); ?>
                    <a href="factures.php?action=voir&id=<?= $f['id'] ?>" class="list-group-item list-group-item-action py-2">
                        <div class="d-flex justify-content-between">
                            <small class="fw-semibold"><?= e($f['numero']) ?></small>
                            <span class="badge bg-<?= $sf['class'] ?>" style="font-size: 0.65rem;"><?= $sf['label'] ?></span>
                        </div>
                        <small class="text-muted"><?= e($f['client_entreprise'] ?: trim(($f['client_prenom'] ?? '') . ' ' . ($f['client_nom'] ?? ''))) ?></small>
                        <div class="text-end"><small class="fw-bold"><?= formatMontant($f['montant_ttc']) ?></small></div>
                    </a>
                <?php endforeach; endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Raccourcis action rapide -->
<div class="card border-0 mb-4">
    <div class="card-body">
        <div class="row g-2 text-center">
            <div class="col">
                <a href="transactions.php?action=ajouter&type=recette" class="btn btn-outline-success w-100">
                    <i class="bi bi-plus-circle"></i><br><small>Saisir une recette</small>
                </a>
            </div>
            <div class="col">
                <a href="transactions.php?action=ajouter&type=depense" class="btn btn-outline-danger w-100">
                    <i class="bi bi-dash-circle"></i><br><small>Saisir une dépense</small>
                </a>
            </div>
            <div class="col">
                <a href="devis.php?action=nouveau" class="btn btn-outline-primary w-100">
                    <i class="bi bi-file-earmark-text"></i><br><small>Créer un devis</small>
                </a>
            </div>
            <div class="col">
                <a href="factures.php?action=nouvelle" class="btn btn-outline-info w-100">
                    <i class="bi bi-receipt"></i><br><small>Créer une facture</small>
                </a>
            </div>
            <div class="col">
                <a href="rapports.php?annee=<?= $annee ?>" class="btn btn-outline-warning w-100">
                    <i class="bi bi-bar-chart-line"></i><br><small>Voir les rapports</small>
                </a>
            </div>
        </div>
    </div>
</div>

<!-- Données pour les graphiques -->
<script>
const dataMensuel = <?= json_encode(array_values($statsMensuelles)) ?>;
const labelsMensuel = <?= json_encode(array_map(function($k) { return formatDateMois($k . '-01'); }, array_keys($statsMensuelles))) ?>;

const dataRecettes = <?= json_encode(array_map(function($r) { return ['label' => $r['nom'] ?? 'Non classé', 'value' => (float)$r['total'], 'color' => $r['couleur'] ?? '#6c757d']; }, $recettesParCat)) ?>;

const dataDepenses = <?= json_encode(array_map(function($r) { return ['label' => $r['nom'] ?? 'Non classé', 'value' => (float)$r['total'], 'color' => $r['couleur'] ?? '#6c757d']; }, $depensesParCat)) ?>;
</script>

<?php include 'footer.php'; ?>

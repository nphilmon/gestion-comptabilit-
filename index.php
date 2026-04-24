<?php
/**
 * Tableau de bord principal - Vue d'ensemble
 */
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/functions_commercial.php';
require_once __DIR__ . '/functions_intelligence.php';
require_once __DIR__ . '/functions_cp.php';
require_once __DIR__ . '/functions_paie.php';
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

// Intelligence avancée
$projectionCA = getProjectionCA($annee);
$chargesTrimestrielles = getEstimationChargesTrimestrielles($annee);
$alertesProactives = getAlertesProactives($annee);
$analyseClients = getAnalyseClients();
$analyseProduits = getAnalyseProduits();
$kpisAvances = getKPIsAvances($annee);
$tresorerie = getTresoreriePrevisionnelle($annee);
$recommandationsIA = getRecommandationsIntelligentes($annee);
$cpModuleEnabled = isCpModuleEnabled();
$cpStats = $cpModuleEnabled ? getCpDashboardStats() : null;
$paieModuleEnabled = isPaieModuleEnabled();
$paieStats = $paieModuleEnabled ? getPaieDashboardStats(date('Y-m')) : null;

// Notifications / alertes
$notifications = [];

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
        </div>
        <div class="col-md-4 text-end">
            <div class="d-flex justify-content-end gap-2 mb-2">
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

<div class="card border-0 mb-4 intelligent-card intelligent-<?= $assistantTone ?>">
    <div class="card-body">
        <div class="row g-4 align-items-start">
            <div class="col-xl-4">
                <div class="intelligent-score">
                    <div class="intelligent-score__ring">
                        <strong><?= $assistantScore ?></strong>
                        <small>/100</small>
                    </div>
                    <div class="intelligent-score__content">
                        <span class="intelligent-eyebrow"><i class="bi bi-stars"></i> Copilote de gestion</span>
                        <h3><?= e($assistantHeadline) ?></h3>
                        <p><?= e($assistantSummary) ?></p>
                        <div class="intelligent-trend trend-<?= $assistantTrendTone ?>">
                            <i class="bi bi-activity"></i>
                            <span><?= e($assistantTrendLabel) ?></span>
                            <strong><?= e($assistantTrendValue) ?></strong>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-xl-4">
                <div class="intelligent-panel">
                    <div class="intelligent-panel__title">Diagnostic automatique</div>
                    <div class="intelligent-factor-list">
                        <?php foreach (array_slice($assistantFactors, 0, 4) as $factor): ?>
                        <div class="intelligent-factor factor-<?= e($factor['tone']) ?>">
                            <span><?= e($factor['label']) ?></span>
                            <strong><?= e($factor['value']) ?></strong>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <div class="col-xl-4">
                <div class="intelligent-panel">
                    <div class="intelligent-panel__title">Actions suggérées</div>
                    <div class="intelligent-reco-list">
                        <?php foreach (array_slice($assistantRecommendations, 0, 3) as $recommendation): ?>
                        <a class="intelligent-reco" href="<?= $recommendation['link'] ?>">
                            <span><?= e($recommendation['text']) ?></span>
                            <strong><?= e($recommendation['button']) ?> <i class="bi bi-arrow-right-short"></i></strong>
                        </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

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

<!-- ═══════════════════════════════════════════════════════ -->
<!-- INTELLIGENCE AVANCÉE                                  -->
<!-- ═══════════════════════════════════════════════════════ -->

<!-- Alertes proactives avancées -->
<?php if (!empty($alertesProactives)): ?>
<div class="card border-0 mb-4 intelligence-section">
    <div class="card-header bg-gradient-intelligence">
        <i class="bi bi-lightning-charge-fill"></i> Alertes proactives
        <span class="badge bg-white text-dark ms-2"><?= count($alertesProactives) ?></span>
    </div>
    <div class="card-body p-0">
        <div class="list-group list-group-flush">
            <?php foreach (array_slice($alertesProactives, 0, 5) as $alerte): ?>
            <a href="<?= BASE_URL . e($alerte['link']) ?>" class="list-group-item list-group-item-action d-flex align-items-center py-3 alerte-proactive-item">
                <div class="alerte-icon alerte-icon-<?= e($alerte['type']) ?>">
                    <i class="bi bi-<?= e($alerte['icon']) ?>"></i>
                </div>
                <div class="ms-3 flex-grow-1">
                    <div class="fw-semibold"><?= e($alerte['titre']) ?></div>
                    <small class="text-muted"><?= e($alerte['texte']) ?></small>
                </div>
                <i class="bi bi-chevron-right text-muted"></i>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- KPIs avancés temps réel -->
<div class="card border-0 mb-4 intelligence-section">
    <div class="card-header bg-gradient-intelligence">
        <i class="bi bi-speedometer"></i> KPIs avancés
    </div>
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-3 col-6">
                <div class="kpi-card kpi-marge">
                    <div class="kpi-value"><?= $kpisAvances['taux_marge_brute'] ?>%</div>
                    <div class="kpi-label">Marge brute</div>
                    <div class="kpi-detail"><?= formatMontant($kpisAvances['marge_brute']) ?></div>
                </div>
            </div>
            <div class="col-md-3 col-6">
                <div class="kpi-card kpi-dso">
                    <div class="kpi-value"><?= $kpisAvances['dso'] ?> j</div>
                    <div class="kpi-label">Délai paiement (DSO)</div>
                    <div class="kpi-detail"><?= $kpisAvances['dso'] <= 30 ? 'Excellent' : ($kpisAvances['dso'] <= 60 ? 'Correct' : 'À améliorer') ?></div>
                </div>
            </div>
            <div class="col-md-3 col-6">
                <div class="kpi-card kpi-conversion">
                    <div class="kpi-value"><?= $kpisAvances['taux_conversion_devis'] ?>%</div>
                    <div class="kpi-label">Conversion devis→facture</div>
                    <div class="kpi-detail"><?= $kpisAvances['devis_convertis'] ?? 0 ?>/<?= $kpisAvances['devis_total'] ?? 0 ?> devis</div>
                </div>
            </div>
            <div class="col-md-3 col-6">
                <div class="kpi-card kpi-recouvrement">
                    <div class="kpi-value"><?= $kpisAvances['taux_recouvrement'] ?>%</div>
                    <div class="kpi-label">Taux recouvrement</div>
                    <div class="kpi-detail">CA moyen client: <?= formatMontant($kpisAvances['ca_moyen_client']) ?></div>
                </div>
            </div>
        </div>
        <?php if (!empty($kpisAvances['meilleur_mois'])): ?>
        <div class="row g-3 mt-1">
            <div class="col-md-6">
                <div class="kpi-mini">
                    <i class="bi bi-trophy-fill text-warning"></i>
                    <span>Meilleur mois : <strong><?= e($kpisAvances['meilleur_mois']) ?></strong> (<?= formatMontant($kpisAvances['meilleur_mois_ca']) ?>)</span>
                </div>
            </div>
            <div class="col-md-6">
                <div class="kpi-mini">
                    <i class="bi bi-bar-chart text-muted"></i>
                    <span>Dépenses moy./mois : <strong><?= formatMontant($kpisAvances['depenses_moy_mensuelles']) ?></strong></span>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Dashboard prédictif : Projection CA + Trésorerie -->
<div class="row g-3 mb-4">
    <!-- Projection CA -->
    <div class="col-lg-6">
        <div class="card border-0 h-100 projection-card">
            <div class="card-body p-4">
                <div class="d-flex align-items-center justify-content-between mb-3">
                    <div class="d-flex align-items-center gap-2">
                        <div class="projection-icon projection-icon--ca">
                            <i class="bi bi-graph-up-arrow"></i>
                        </div>
                        <div>
                            <h6 class="mb-0 fw-bold">Projection CA <?= $annee ?></h6>
                            <small class="text-muted"><?= $projectionCA['mois_courant'] ?> mois écoulés — <?= $projectionCA['mois_restants'] ?> restants</small>
                        </div>
                    </div>
                    <span class="projection-trend-badge projection-trend-badge--<?= $projectionCA['tendance'] ?>">
                        <i class="bi bi-<?= $projectionCA['tendance'] === 'hausse' ? 'trending-up' : ($projectionCA['tendance'] === 'baisse' ? 'trending-down' : 'dash-lg') ?>"></i>
                        <?= ucfirst($projectionCA['tendance']) ?>
                    </span>
                </div>

                <div class="row g-2 mb-3">
                    <div class="col-6">
                        <div class="projection-kpi">
                            <span class="projection-kpi__label">CA réalisé</span>
                            <span class="projection-kpi__value text-success"><?= formatMontant($projectionCA['total_actuel']) ?></span>
                            <span class="projection-kpi__sub">Moy. <?= formatMontant($projectionCA['moyenne_mensuelle']) ?>/mois</span>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="projection-kpi">
                            <span class="projection-kpi__label">Projection annuelle</span>
                            <span class="projection-kpi__value text-primary"><?= formatMontant($projectionCA['projection_lineaire']) ?></span>
                            <?php if ($projectionCA['ca_n1'] > 0): ?>
                            <span class="projection-kpi__sub <?= $projectionCA['variation_n1'] >= 0 ? 'text-success' : 'text-danger' ?>">
                                <i class="bi bi-<?= $projectionCA['variation_n1'] >= 0 ? 'arrow-up-short' : 'arrow-down-short' ?>"></i>
                                <?= ($projectionCA['variation_n1'] >= 0 ? '+' : '') . $projectionCA['variation_n1'] ?>% vs <?= $annee - 1 ?>
                            </span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <?php if ($projectionCA['plafond_ca'] > 0): ?>
                <div class="projection-plafond mb-3">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <small class="text-muted"><i class="bi bi-speedometer"></i> Plafond micro-entreprise</small>
                        <strong class="<?= $projectionCA['pct_projection_plafond'] > 90 ? 'text-danger' : ($projectionCA['pct_projection_plafond'] > 70 ? 'text-warning' : 'text-success') ?>"><?= $projectionCA['pct_projection_plafond'] ?>%</strong>
                    </div>
                    <div class="progress projection-progress">
                        <div class="progress-bar bg-<?= $projectionCA['pct_projection_plafond'] > 90 ? 'danger' : ($projectionCA['pct_projection_plafond'] > 70 ? 'warning' : 'success') ?>"
                             style="width: <?= min(100, $projectionCA['pct_projection_plafond']) ?>%"></div>
                    </div>
                    <div class="d-flex justify-content-between mt-1">
                        <small class="text-muted"><?= formatMontant($projectionCA['projection_lineaire']) ?></small>
                        <small class="text-muted"><?= formatMontant($projectionCA['plafond_ca']) ?></small>
                    </div>
                </div>
                <?php endif; ?>

                <div class="projection-chart-wrapper">
                    <canvas id="chartProjection" height="180"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- Trésorerie prévisionnelle -->
    <div class="col-lg-6">
        <div class="card border-0 h-100 projection-card">
            <div class="card-body p-4">
                <div class="d-flex align-items-center justify-content-between mb-3">
                    <div class="d-flex align-items-center gap-2">
                        <div class="projection-icon projection-icon--treso">
                            <i class="bi bi-safe"></i>
                        </div>
                        <div>
                            <h6 class="mb-0 fw-bold">Trésorerie prévisionnelle</h6>
                            <small class="text-muted">Projection sur 6 mois glissants</small>
                        </div>
                    </div>
                    <?php if ($tresorerie['runway_mois'] !== null): ?>
                    <span class="projection-trend-badge projection-trend-badge--<?= $tresorerie['runway_mois'] > 6 ? 'hausse' : ($tresorerie['runway_mois'] > 3 ? 'stable' : 'baisse') ?>">
                        <i class="bi bi-fuel-pump"></i>
                        <?= $tresorerie['runway_mois'] ?> mois
                    </span>
                    <?php endif; ?>
                </div>

                <div class="row g-2 mb-3">
                    <div class="col-4">
                        <div class="projection-kpi">
                            <span class="projection-kpi__label">Solde actuel</span>
                            <span class="projection-kpi__value <?= $tresorerie['solde_actuel'] >= 0 ? 'text-success' : 'text-danger' ?>"><?= formatMontant($tresorerie['solde_actuel']) ?></span>
                        </div>
                    </div>
                    <div class="col-4">
                        <div class="projection-kpi">
                            <span class="projection-kpi__label">Entrées prévues</span>
                            <span class="projection-kpi__value text-info"><?= formatMontant($tresorerie['entrees_prevues']) ?></span>
                        </div>
                    </div>
                    <div class="col-4">
                        <div class="projection-kpi">
                            <span class="projection-kpi__label">Flux net/mois</span>
                            <span class="projection-kpi__value <?= $tresorerie['flux_net_moyen'] >= 0 ? 'text-success' : 'text-danger' ?>"><?= formatMontant($tresorerie['flux_net_moyen']) ?></span>
                        </div>
                    </div>
                </div>

                <?php if ($tresorerie['runway_mois'] !== null): ?>
                <div class="projection-plafond mb-3">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <small class="text-muted"><i class="bi bi-fuel-pump"></i> Runway estimé</small>
                        <strong class="<?= $tresorerie['runway_mois'] > 6 ? 'text-success' : ($tresorerie['runway_mois'] > 3 ? 'text-warning' : 'text-danger') ?>">
                            <?= $tresorerie['runway_mois'] ?> mois
                        </strong>
                    </div>
                    <div class="progress projection-progress">
                        <div class="progress-bar bg-<?= $tresorerie['runway_mois'] > 6 ? 'success' : ($tresorerie['runway_mois'] > 3 ? 'warning' : 'danger') ?>"
                             style="width: <?= min(100, ($tresorerie['runway_mois'] / 12) * 100) ?>%"></div>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($tresorerie['date_rupture']): ?>
                <div class="projection-alert mb-3">
                    <i class="bi bi-exclamation-triangle-fill"></i>
                    Rupture de trésorerie estimée dans <strong><?= $tresorerie['mois_avant_rupture'] ?> mois</strong>
                    <small>(~<?= date('M Y', strtotime($tresorerie['date_rupture'])) ?>)</small>
                </div>
                <?php endif; ?>

                <div class="projection-chart-wrapper">
                    <canvas id="chartTresorerie" height="160"></canvas>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Charges URSSAF trimestrielles (micro uniquement) -->
<?php if (!empty($chargesTrimestrielles)): ?>
<div class="card border-0 mb-4 intelligence-section">
    <div class="card-header bg-gradient-intelligence">
        <i class="bi bi-bank2"></i> Estimation charges URSSAF <?= $annee ?>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Trimestre</th>
                        <th class="text-end">CA</th>
                        <th class="text-end">URSSAF</th>
                        <th class="text-end">CFP</th>
                        <?php if ((bool) getParam('versement_liberatoire_actif', '0')): ?><th class="text-end">VL</th><?php endif; ?>
                        <th class="text-end">Total</th>
                        <th>Échéance</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($chargesTrimestrielles as $t): ?>
                    <tr class="<?= $t['est_passe'] ? 'text-muted' : '' ?>">
                        <td><strong><?= e($t['label']) ?></strong></td>
                        <td class="text-end"><?= formatMontant($t['ca']) ?></td>
                        <td class="text-end"><?= formatMontant($t['urssaf']) ?></td>
                        <td class="text-end"><?= formatMontant($t['cfp']) ?></td>
                        <?php if ((bool) getParam('versement_liberatoire_actif', '0')): ?>
                            <td class="text-end"><?= formatMontant($t['versement_liberatoire']) ?></td>
                        <?php endif; ?>
                        <td class="text-end fw-bold"><?= formatMontant($t['total_charges']) ?></td>
                        <td><small><?= formatDate($t['echeance']) ?></small></td>
                        <td>
                            <?php if ($t['est_passe']): ?>
                                <span class="badge bg-success-subtle text-success">Passé</span>
                            <?php else: ?>
                                <span class="badge bg-warning-subtle text-warning">À venir</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Analyse clients + Produits -->
<div class="row g-3 mb-4">
    <!-- Top clients -->
    <div class="col-lg-6">
        <div class="card border-0 h-100 intelligence-section">
            <div class="card-header bg-gradient-intelligence d-flex justify-content-between align-items-center">
                <span><i class="bi bi-people-fill"></i> Analyse clients</span>
                <div class="d-flex gap-2">
                    <span class="badge bg-white text-dark"><?= $analyseClients['taux_fidelisation'] ?>% fidélisés</span>
                    <span class="badge bg-white text-dark">Panier moy: <?= formatMontant($analyseClients['panier_moyen']) ?></span>
                </div>
            </div>
            <div class="card-body p-0">
                <?php if (!empty($analyseClients['top_clients'])): ?>
                <div class="table-responsive">
                    <table class="table table-hover mb-0 align-middle table-sm">
                        <thead class="table-light">
                            <tr>
                                <th>Client</th>
                                <th class="text-center">Score</th>
                                <th class="text-end">CA total</th>
                                <th class="text-center">Factures</th>
                                <th>Dernière</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach (array_slice($analyseClients['top_clients'], 0, 5) as $client): ?>
                            <tr>
                                <td>
                                    <div class="fw-semibold"><?= e($client['entreprise'] ?: trim(($client['prenom'] ?? '') . ' ' . $client['nom'])) ?></div>
                                </td>
                                <td class="text-center">
                                    <span class="badge bg-<?= $client['niveau'] === 'premium' ? 'success' : ($client['niveau'] === 'regulier' ? 'primary' : 'secondary') ?>">
                                        <?= $client['score'] ?>
                                    </span>
                                </td>
                                <td class="text-end fw-bold"><?= formatMontant($client['ca_total']) ?></td>
                                <td class="text-center"><?= $client['nb_factures'] ?></td>
                                <td><small class="text-muted"><?= $client['derniere_facture'] ? formatDate($client['derniere_facture']) : '-' ?></small></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <div class="text-center py-4 text-muted">
                    <i class="bi bi-people" style="font-size: 2rem;"></i>
                    <p class="mt-2 mb-0">Pas encore assez de données clients.</p>
                </div>
                <?php endif; ?>

                <?php if (!empty($analyseClients['clients_dormants'])): ?>
                <div class="border-top px-3 py-2">
                    <small class="text-danger fw-semibold"><i class="bi bi-moon-stars"></i> Clients dormants (>6 mois) :</small>
                    <?php foreach (array_slice($analyseClients['clients_dormants'], 0, 3) as $d): ?>
                    <span class="badge bg-danger-subtle text-danger ms-1">
                        <?= e($d['entreprise'] ?: trim(($d['prenom'] ?? '') . ' ' . $d['nom'])) ?>
                        <small>(<?= $d['jours_inactif'] ?>j)</small>
                    </span>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Analyse produits -->
    <div class="col-lg-6">
        <div class="card border-0 h-100 intelligence-section">
            <div class="card-header bg-gradient-intelligence d-flex justify-content-between align-items-center">
                <span><i class="bi bi-box-seam-fill"></i> Analyse produits</span>
                <?php if (!empty($analyseProduits['reappro_urgente'])): ?>
                <span class="badge bg-danger"><?= count($analyseProduits['reappro_urgente']) ?> réappro urgente(s)</span>
                <?php endif; ?>
            </div>
            <div class="card-body p-0">
                <?php if (!empty($analyseProduits['best_sellers'])): ?>
                <div class="table-responsive">
                    <table class="table table-hover mb-0 align-middle table-sm">
                        <thead class="table-light">
                            <tr>
                                <th>Produit</th>
                                <th class="text-center">Vendus</th>
                                <th class="text-end">CA généré</th>
                                <th class="text-end">Marge</th>
                                <th class="text-center">Stock</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach (array_slice($analyseProduits['best_sellers'], 0, 5) as $p): ?>
                            <tr>
                                <td>
                                    <div class="fw-semibold"><?= e($p['nom']) ?></div>
                                    <small class="text-muted"><?= e($p['reference'] ?? '') ?></small>
                                </td>
                                <td class="text-center"><?= (int) $p['total_vendu'] ?></td>
                                <td class="text-end"><?= formatMontant($p['ca_genere']) ?></td>
                                <td class="text-end">
                                    <span class="<?= $p['marge_pct'] > 30 ? 'text-success' : ($p['marge_pct'] > 10 ? 'text-warning' : 'text-danger') ?>">
                                        <?= $p['marge_pct'] ?>%
                                    </span>
                                </td>
                                <td class="text-center">
                                    <?php if ($p['gestion_stock']): ?>
                                        <span class="badge bg-<?= (float)$p['stock_actuel'] <= (float)$p['seuil_alerte'] ? 'danger' : 'success' ?>-subtle text-<?= (float)$p['stock_actuel'] <= (float)$p['seuil_alerte'] ? 'danger' : 'success' ?>">
                                            <?= (int) $p['stock_actuel'] ?>
                                        </span>
                                    <?php else: ?>
                                        <small class="text-muted">-</small>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <div class="text-center py-4 text-muted">
                    <i class="bi bi-box-seam" style="font-size: 2rem;"></i>
                    <p class="mt-2 mb-0">Pas encore de données de vente produits.</p>
                </div>
                <?php endif; ?>

                <?php if (!empty($analyseProduits['stock_mort'])): ?>
                <div class="border-top px-3 py-2">
                    <small class="text-warning fw-semibold"><i class="bi bi-archive"></i> Stock mort (>90j sans mouvement) :</small>
                    <?php foreach (array_slice($analyseProduits['stock_mort'], 0, 3) as $sm): ?>
                    <span class="badge bg-warning-subtle text-warning ms-1">
                        <?= e($sm['nom']) ?>
                        <small>(<?= (int) $sm['stock_actuel'] ?> unités)</small>
                    </span>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Suggestions intelligentes IA -->
<?php if (!empty($recommandationsIA)):
    // Grouper par catégorie
    $catLabels = [
        'commercial' => ['label' => 'Commercial', 'icon' => 'briefcase', 'color' => 'primary'],
        'tresorerie' => ['label' => 'Trésorerie', 'icon' => 'wallet2', 'color' => 'info'],
        'comptabilite' => ['label' => 'Comptabilité', 'icon' => 'journal-text', 'color' => 'dark'],
        'fiscal' => ['label' => 'Fiscal', 'icon' => 'bank', 'color' => 'secondary'],
        'stock' => ['label' => 'Stock', 'icon' => 'box-seam', 'color' => 'warning'],
        'prevision' => ['label' => 'Prévisions', 'icon' => 'binoculars', 'color' => 'success'],
        'organisation' => ['label' => 'Organisation', 'icon' => 'gear', 'color' => 'secondary'],
        'general' => ['label' => 'Général', 'icon' => 'info-circle', 'color' => 'primary'],
    ];
    $grouped = [];
    foreach ($recommandationsIA as $r) {
        $cat = $r['categorie'] ?? 'general';
        $grouped[$cat][] = $r;
    }
    $nbCritiques = count(array_filter($recommandationsIA, fn($r) => in_array($r['type'], ['danger', 'warning'])));
    $nbTotal = count($recommandationsIA);
?>
<div class="card border-0 mb-4 intelligence-section">
    <div class="card-header bg-gradient-intelligence d-flex justify-content-between align-items-center">
        <span>
            <i class="bi bi-stars"></i> Suggestions intelligentes
            <small class="text-white-50 ms-2"><?= $nbTotal ?> suggestion<?= $nbTotal > 1 ? 's' : '' ?> basée<?= $nbTotal > 1 ? 's' : '' ?> sur vos données</small>
        </span>
        <?php if ($nbCritiques > 0): ?>
        <span class="badge bg-danger"><?= $nbCritiques ?> action<?= $nbCritiques > 1 ? 's' : '' ?> recommandée<?= $nbCritiques > 1 ? 's' : '' ?></span>
        <?php endif; ?>
    </div>
    <div class="card-body">
        <!-- Filtres par catégorie -->
        <div class="d-flex flex-wrap gap-2 mb-3" id="ia-filters">
            <button class="btn btn-sm btn-primary active" data-ia-filter="all">
                <i class="bi bi-grid-3x3-gap"></i> Toutes <span class="badge bg-white text-primary ms-1"><?= $nbTotal ?></span>
            </button>
            <?php foreach ($grouped as $cat => $items):
                $meta = $catLabels[$cat] ?? $catLabels['general'];
            ?>
            <button class="btn btn-sm btn-outline-<?= $meta['color'] ?>" data-ia-filter="<?= $cat ?>">
                <i class="bi bi-<?= $meta['icon'] ?>"></i> <?= $meta['label'] ?>
                <span class="badge bg-<?= $meta['color'] ?> bg-opacity-10 text-<?= $meta['color'] ?> ms-1"><?= count($items) ?></span>
            </button>
            <?php endforeach; ?>
        </div>

        <!-- Critiques en premier (danger) -->
        <?php
        $critiques = array_filter($recommandationsIA, fn($r) => $r['type'] === 'danger');
        if (!empty($critiques)):
        ?>
        <div class="alert alert-danger d-flex align-items-start gap-2 py-2 mb-3" role="alert">
            <i class="bi bi-exclamation-triangle-fill fs-5 mt-1"></i>
            <div>
                <strong><?= count($critiques) ?> alerte<?= count($critiques) > 1 ? 's' : '' ?> critique<?= count($critiques) > 1 ? 's' : '' ?></strong> —
                <?php foreach ($critiques as $c): ?>
                    <?= e($c['titre']) ?><?= !empty($c['lien']) ? ' <a href="' . e($c['lien']) . '" class="alert-link">Agir →</a>' : '' ?>.
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Cartes suggestions -->
        <div class="row g-3">
            <?php foreach ($recommandationsIA as $i => $reco):
                $typeColors = [
                    'danger'  => ['bg' => 'rgba(220,53,69,0.08)', 'border' => '#dc3545', 'icon_bg' => '#dc3545'],
                    'warning' => ['bg' => 'rgba(255,193,7,0.08)', 'border' => '#ffc107', 'icon_bg' => '#e6a800'],
                    'info'    => ['bg' => 'rgba(13,202,240,0.06)', 'border' => '#0dcaf0', 'icon_bg' => '#0bb4d0'],
                    'success' => ['bg' => 'rgba(25,135,84,0.06)', 'border' => '#198754', 'icon_bg' => '#198754'],
                ];
                $tc = $typeColors[$reco['type']] ?? $typeColors['info'];
                $cat = $reco['categorie'] ?? 'general';
                $catMeta = $catLabels[$cat] ?? $catLabels['general'];
            ?>
            <div class="col-md-6 col-xl-4 ia-card" data-ia-cat="<?= $cat ?>" <?= $i >= 6 ? 'style="display:none"' : '' ?>>
                <div class="d-flex gap-3 p-3 rounded-3 h-100" style="background:<?= $tc['bg'] ?>;border-left:3px solid <?= $tc['border'] ?>">
                    <div class="flex-shrink-0">
                        <div class="d-flex align-items-center justify-content-center rounded-circle text-white" style="width:38px;height:38px;background:<?= $tc['icon_bg'] ?>">
                            <i class="bi bi-<?= e($reco['icon']) ?>"></i>
                        </div>
                    </div>
                    <div class="flex-grow-1 min-w-0">
                        <div class="d-flex justify-content-between align-items-start mb-1">
                            <div class="fw-semibold lh-sm" style="font-size:.88rem"><?= e($reco['titre']) ?></div>
                            <span class="badge bg-<?= $catMeta['color'] ?>-subtle text-<?= $catMeta['color'] ?> ms-2 flex-shrink-0" style="font-size:.65rem"><?= $catMeta['label'] ?></span>
                        </div>
                        <div class="text-muted mb-2" style="font-size:.8rem;line-height:1.35"><?= e($reco['texte']) ?></div>
                        <?php if (!empty($reco['lien'])): ?>
                        <a href="<?= e($reco['lien']) ?>" class="btn btn-sm btn-outline-<?= e($reco['type']) ?> py-0 px-2" style="font-size:.75rem">
                            <i class="bi bi-arrow-right"></i> Agir
                        </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <?php if ($nbTotal > 6): ?>
        <div class="text-center mt-3">
            <button class="btn btn-sm btn-outline-primary" id="ia-show-all">
                <i class="bi bi-chevron-down"></i> Voir les <?= $nbTotal - 6 ?> autres suggestion<?= ($nbTotal - 6) > 1 ? 's' : '' ?>
            </button>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

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
            <?php if ($cpModuleEnabled): ?>
            <div class="col">
                <a href="conges.php" class="btn btn-outline-secondary w-100">
                    <i class="bi bi-calendar2-check"></i><br><small>Gérer les CP</small>
                </a>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Données pour les graphiques -->
<script>
const dataMensuel = <?= json_encode(array_values($statsMensuelles)) ?>;
const labelsMensuel = <?= json_encode(array_map(function($k) { return formatDateMois($k . '-01'); }, array_keys($statsMensuelles))) ?>;

const dataRecettes = <?= json_encode(array_map(function($r) { return ['label' => $r['nom'] ?? 'Non classé', 'value' => (float)$r['total'], 'color' => $r['couleur'] ?? '#6c757d']; }, $recettesParCat)) ?>;

const dataDepenses = <?= json_encode(array_map(function($r) { return ['label' => $r['nom'] ?? 'Non classé', 'value' => (float)$r['total'], 'color' => $r['couleur'] ?? '#6c757d']; }, $depensesParCat)) ?>;

const dataProjection = <?= json_encode($projectionCA['mensuel']) ?>;
const projectionMoyenne = <?= json_encode($projectionCA['moyenne_mensuelle']) ?>;
const projectionMoisCourant = <?= json_encode($projectionCA['mois_courant']) ?>;

const dataTresorerie = <?= json_encode($tresorerie['projection_mensuelle']) ?>;

// ─── Suggestions intelligentes : filtres & show more ───
document.addEventListener('DOMContentLoaded', function() {
    // Filtres par catégorie
    document.querySelectorAll('[data-ia-filter]').forEach(btn => {
        btn.addEventListener('click', function() {
            const filter = this.dataset.iaFilter;
            document.querySelectorAll('[data-ia-filter]').forEach(b => b.classList.remove('active'));
            this.classList.add('active');
            // Remove outline class and add solid class for active
            document.querySelectorAll('[data-ia-filter]').forEach(b => {
                if (b !== this && !b.className.includes('btn-outline-')) {
                    b.className = b.className.replace(/btn-(\w+)/, 'btn-outline-$1');
                }
            });

            document.querySelectorAll('.ia-card').forEach(card => {
                if (filter === 'all' || card.dataset.iaCat === filter) {
                    card.style.display = '';
                } else {
                    card.style.display = 'none';
                }
            });
            // Hide "show all" when filtering
            const showAllBtn = document.getElementById('ia-show-all');
            if (showAllBtn) showAllBtn.style.display = filter === 'all' ? '' : 'none';
        });
    });

    // Voir plus
    const showAllBtn = document.getElementById('ia-show-all');
    if (showAllBtn) {
        showAllBtn.addEventListener('click', function() {
            document.querySelectorAll('.ia-card[style*="display:none"], .ia-card[style*="display: none"]').forEach(c => {
                if (!c.dataset.iaCat || document.querySelector('[data-ia-filter].active')?.dataset.iaFilter === 'all') {
                    c.style.display = '';
                }
            });
            this.style.display = 'none';
        });
    }
});
</script>

<?php include 'footer.php'; ?>

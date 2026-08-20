<?php
/**
 * Tableau de bord principal - Vue d'ensemble
 */
require_once __DIR__ . '/functions.php';

// Visiteur non connecté : page de présentation publique plutôt que le
// tableau de bord (qui redirigerait de toute façon vers la connexion,
// après avoir inutilement interrogé la base).
if (!isLoggedIn()) {
    require __DIR__ . '/presentation.php';
    exit;
}

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
$stockFaible = 0;
try {
    $db = getDB();
    $stockFaible = (int) $db->query("SELECT COUNT(*) FROM produits WHERE gestion_stock = 1 AND actif = 1 AND stock_actuel <= seuil_alerte AND seuil_alerte > 0")->fetchColumn();
    if ($stockFaible > 0) {
        $notifications[] = [
            'type' => 'warning',
            'icon' => 'box-seam',
            'text' => $stockFaible . ' produit(s) en stock faible',
            'link' => BASE_URL . 'produits.php'
        ];
    }
} catch (\PDOException $e) {}

$actionsPrioritaires = [];

if ($statsCommerciales['factures_en_retard'] > 0) {
    $actionsPrioritaires[] = [
        'tone' => 'danger',
        'icon' => 'exclamation-triangle-fill',
        'title' => 'Relancer les factures en retard',
        'text' => $statsCommerciales['factures_en_retard'] . ' facture(s) nécessitent une relance.',
        'button' => 'Voir les factures',
        'link' => BASE_URL . 'factures.php',
    ];
}

if ($statsCommerciales['en_attente_paiement'] > 0) {
    $actionsPrioritaires[] = [
        'tone' => 'warning',
        'icon' => 'clock-history',
        'title' => 'Suivre les paiements',
        'text' => formatMontant($statsCommerciales['en_attente_paiement']) . ' restent à encaisser.',
        'button' => 'Ouvrir les paiements',
        'link' => BASE_URL . 'paiements.php',
    ];
}

if ($stats['plafond_ca'] > 0 && $stats['pct_plafond'] > 80) {
    $actionsPrioritaires[] = [
        'tone' => $stats['pct_plafond'] > 95 ? 'danger' : 'warning',
        'icon' => 'speedometer2',
        'title' => 'Surveiller le plafond',
        'text' => 'Le chiffre d\'affaires atteint ' . $stats['pct_plafond'] . '% du plafond.',
        'button' => 'Voir le rapport',
        'link' => BASE_URL . 'rapports.php?annee=' . $annee,
    ];
}

if ($stockFaible > 0) {
    $actionsPrioritaires[] = [
        'tone' => 'warning',
        'icon' => 'box-seam',
        'title' => 'Réapprovisionner le stock',
        'text' => $stockFaible . ' produit(s) sont sous le seuil d\'alerte.',
        'button' => 'Voir les produits',
        'link' => BASE_URL . 'produits.php',
    ];
}

if (empty($dernieres)) {
    $actionsPrioritaires[] = [
        'tone' => 'success',
        'icon' => 'plus-circle',
        'title' => 'Ajouter la première transaction',
        'text' => 'Commencez par enregistrer une recette ou une dépense.',
        'button' => 'Ajouter',
        'link' => BASE_URL . 'transactions.php?action=ajouter',
    ];
}

if ($statsCommerciales['clients_actifs'] === 0) {
    $actionsPrioritaires[] = [
        'tone' => 'info',
        'icon' => 'person-plus',
        'title' => 'Créer un client',
        'text' => 'Ajoutez vos contacts pour préparer devis et factures.',
        'button' => 'Nouveau client',
        'link' => BASE_URL . 'clients.php?action=nouveau',
    ];
}

if (empty($derniersDevis)) {
    $actionsPrioritaires[] = [
        'tone' => 'primary',
        'icon' => 'file-earmark-text',
        'title' => 'Préparer un devis',
        'text' => 'Créez une proposition commerciale prête à envoyer.',
        'button' => 'Nouveau devis',
        'link' => BASE_URL . 'devis.php?action=nouveau',
    ];
}

if (count($actionsPrioritaires) < 3) {
    $actionsPrioritaires[] = [
        'tone' => 'secondary',
        'icon' => 'gear',
        'title' => 'Compléter les paramètres',
        'text' => 'Vérifiez les informations de l\'entreprise et les options fiscales.',
        'button' => 'Paramètres',
        'link' => BASE_URL . 'parametres.php',
    ];
}

$insights = [
    [
        'tone' => ($tresorerie['solde_actuel'] ?? 0) >= 0 ? 'success' : 'danger',
        'icon' => 'wallet2',
        'label' => 'Solde estimé',
        'value' => formatMontant((float)($tresorerie['solde_actuel'] ?? 0)),
    ],
    [
        'tone' => $statsCommerciales['en_attente_paiement'] > 0 ? 'warning' : 'success',
        'icon' => 'receipt',
        'label' => 'À encaisser',
        'value' => formatMontant($statsCommerciales['en_attente_paiement']),
    ],
    [
        'tone' => ($projectionCA['projection_lineaire'] ?? 0) > 0 ? 'info' : 'secondary',
        'icon' => 'graph-up-arrow',
        'label' => 'Projection CA',
        'value' => formatMontant((float)($projectionCA['projection_lineaire'] ?? 0)),
    ],
];

$assistantScore = 100;
$assistantScore -= min(25, count($notifications) * 8);
if ($statsCommerciales['factures_en_retard'] > 0) $assistantScore -= 20;
if ($statsCommerciales['en_attente_paiement'] > 0) $assistantScore -= 10;
if ($stats['plafond_ca'] > 0 && $stats['pct_plafond'] > 90) $assistantScore -= 15;
if (($tresorerie['solde_actuel'] ?? 0) < 0) $assistantScore -= 20;
$assistantScore = max(0, min(100, $assistantScore));

$assistantTone = $assistantScore >= 75 ? 'success' : ($assistantScore >= 50 ? 'warning' : 'danger');
$assistantHeadline = $assistantScore >= 75
    ? 'Situation sous contrôle'
    : ($assistantScore >= 50 ? 'Quelques points à suivre' : 'Priorités à traiter rapidement');
$assistantSummary = empty($notifications)
    ? 'Aucune alerte critique détectée pour le moment.'
    : count($notifications) . ' point(s) demandent votre attention.';

$assistantTrendTone = ($projectionCA['tendance'] ?? 'stable') === 'hausse'
    ? 'success'
    : ((($projectionCA['tendance'] ?? 'stable') === 'baisse') ? 'warning' : 'info');
$assistantTrendLabel = 'Tendance CA';
$assistantTrendValue = ucfirst((string)($projectionCA['tendance'] ?? 'stable'));

$assistantFactors = [
    ['tone' => 'success', 'label' => 'CA encaissé', 'value' => formatMontant($stats['total_recettes'])],
    ['tone' => 'info', 'label' => 'CA projeté', 'value' => formatMontant((float)($projectionCA['projection_lineaire'] ?? 0))],
    ['tone' => $statsCommerciales['en_attente_paiement'] > 0 ? 'warning' : 'success', 'label' => 'Paiements ouverts', 'value' => formatMontant($statsCommerciales['en_attente_paiement'])],
    ['tone' => ($stats['pct_plafond'] ?? 0) > 80 ? 'warning' : 'info', 'label' => 'Plafond utilisé', 'value' => ($stats['pct_plafond'] ?? 0) . '%'],
];

$assistantRecommendations = array_map(static function (array $action): array {
    return [
        'text' => $action['title'],
        'button' => $action['button'],
        'link' => $action['link'],
    ];
}, array_slice($actionsPrioritaires, 0, 3));

if (empty($assistantRecommendations)) {
    $assistantRecommendations[] = [
        'text' => 'Continuer la saisie comptable',
        'button' => 'Ajouter',
        'link' => BASE_URL . 'transactions.php?action=ajouter',
    ];
}

// Palette Tailwind par "tone" métier (success/warning/danger/info/primary/secondary)
$toneClasses = [
    'success'   => ['bg' => 'bg-emerald-50', 'text' => 'text-emerald-700', 'ring' => 'ring-emerald-100', 'dot' => 'bg-emerald-500', 'solid' => 'bg-emerald-600'],
    'warning'   => ['bg' => 'bg-amber-50', 'text' => 'text-amber-700', 'ring' => 'ring-amber-100', 'dot' => 'bg-amber-500', 'solid' => 'bg-amber-500'],
    'danger'    => ['bg' => 'bg-rose-50', 'text' => 'text-rose-700', 'ring' => 'ring-rose-100', 'dot' => 'bg-rose-500', 'solid' => 'bg-rose-600'],
    'info'      => ['bg' => 'bg-sky-50', 'text' => 'text-sky-700', 'ring' => 'ring-sky-100', 'dot' => 'bg-sky-500', 'solid' => 'bg-sky-600'],
    'primary'   => ['bg' => 'bg-brand-50', 'text' => 'text-brand-700', 'ring' => 'ring-brand-100', 'dot' => 'bg-brand-600', 'solid' => 'bg-brand-600'],
    'secondary' => ['bg' => 'bg-gray-100', 'text' => 'text-gray-600', 'ring' => 'ring-gray-200', 'dot' => 'bg-gray-400', 'solid' => 'bg-gray-500'],
];
function tw_tone(array $map, ?string $tone, string $key): string {
    return $map[$tone ?? 'secondary'][$key] ?? $map['secondary'][$key];
}

include 'header.php';
?>

<!-- ═══════════ En-tête de page ═══════════ -->
<div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-6">
    <div>
        <h1 class="text-xl sm:text-2xl font-bold text-gray-900 flex items-center gap-2">
            <i class="bi bi-speedometer2 text-brand-600"></i> Tableau de bord <?= $annee ?>
        </h1>
        <p class="text-sm text-gray-500 mt-0.5">
            <?= e(getParam('nom_entreprise', 'Mon Activité')) ?> — <?= e(getRegimeLabel()) ?>
        </p>
    </div>
    <div class="flex items-center gap-2">
        <div class="inline-flex rounded-lg border border-gray-200 bg-white p-0.5">
            <?php foreach ($annees as $a): ?>
                <a href="?annee=<?= $a ?>"
                   class="px-3 py-1.5 text-sm font-medium rounded-md transition-colors <?= $a === $annee ? 'bg-brand-600 text-white shadow-sm' : 'text-gray-500 hover:text-gray-800' ?>">
                    <?= $a ?>
                </a>
            <?php endforeach; ?>
        </div>
        <div class="dropdown">
            <button class="inline-flex items-center gap-1.5 rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm font-medium text-gray-600 hover:bg-gray-50 transition" type="button" data-bs-toggle="dropdown">
                <i class="bi bi-download"></i> <span class="hidden sm:inline">Export</span>
            </button>
            <ul class="dropdown-menu dropdown-menu-end shadow-lg border border-gray-100 rounded-xl p-1.5 mt-2">
                <li><a class="dropdown-item rounded-lg" href="<?= BASE_URL ?>export_csv.php?type=transactions&annee=<?= $annee ?>"><i class="bi bi-table"></i> Transactions <?= $annee ?></a></li>
                <li><a class="dropdown-item rounded-lg" href="<?= BASE_URL ?>export_csv.php?type=factures"><i class="bi bi-receipt"></i> Factures</a></li>
                <li><a class="dropdown-item rounded-lg" href="<?= BASE_URL ?>export_csv.php?type=clients"><i class="bi bi-people"></i> Clients</a></li>
                <li><a class="dropdown-item rounded-lg" href="<?= BASE_URL ?>export_csv.php?type=produits"><i class="bi bi-box-seam"></i> Produits</a></li>
            </ul>
        </div>
    </div>
</div>

<!-- ═══════════ Notifications ═══════════ -->
<?php if (!empty($notifications)): ?>
<div class="mb-6 space-y-2">
    <?php foreach ($notifications as $n): $nt = $toneClasses[$n['type']] ?? $toneClasses['info']; ?>
    <a href="<?= $n['link'] ?>" class="flex items-center gap-3 rounded-xl border border-gray-100 <?= $nt['bg'] ?> px-4 py-3 text-sm <?= $nt['text'] ?> hover:brightness-95 transition">
        <i class="bi bi-<?= $n['icon'] ?>"></i>
        <span class="font-medium flex-1"><?= e($n['text']) ?></span>
        <i class="bi bi-chevron-right"></i>
    </a>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- ═══════════ Priorités + Vue rapide ═══════════ -->
<div class="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-6">
    <div class="lg:col-span-2 bg-white rounded-2xl border border-gray-100 shadow-card">
        <div class="flex items-center justify-between px-5 py-4 border-b border-gray-50">
            <span class="text-sm font-semibold text-gray-800"><i class="bi bi-stars text-brand-600"></i> Priorités du moment</span>
            <span class="text-xs text-gray-400 hidden sm:inline">Ce qui mérite votre attention en premier</span>
        </div>
        <div class="p-5 grid grid-cols-1 md:grid-cols-3 gap-3">
            <?php foreach (array_slice($actionsPrioritaires, 0, 3) as $action): $pt = $toneClasses[$action['tone']] ?? $toneClasses['info']; ?>
            <a href="<?= $action['link'] ?>" class="group flex flex-col gap-2 rounded-xl border border-gray-100 <?= $pt['bg'] ?> p-4 hover:shadow-card-hover transition">
                <span class="flex h-9 w-9 items-center justify-center rounded-lg <?= $pt['solid'] ?> text-white">
                    <i class="bi bi-<?= $action['icon'] ?>"></i>
                </span>
                <strong class="text-sm text-gray-800"><?= e($action['title']) ?></strong>
                <span class="text-xs text-gray-500"><?= e($action['text']) ?></span>
                <span class="mt-auto inline-flex items-center gap-1 text-xs font-semibold <?= $pt['text'] ?>">
                    <?= e($action['button']) ?> <i class="bi bi-arrow-right-short group-hover:translate-x-0.5 transition-transform"></i>
                </span>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
    <div class="bg-white rounded-2xl border border-gray-100 shadow-card">
        <div class="px-5 py-4 border-b border-gray-50 text-sm font-semibold text-gray-800">
            <i class="bi bi-activity text-brand-600"></i> Vue rapide
        </div>
        <div class="p-5 space-y-3">
            <?php foreach ($insights as $insight): $it = $toneClasses[$insight['tone']] ?? $toneClasses['info']; ?>
            <div class="flex items-center gap-3">
                <span class="flex h-9 w-9 items-center justify-center rounded-lg <?= $it['bg'] ?> <?= $it['text'] ?> shrink-0">
                    <i class="bi bi-<?= $insight['icon'] ?>"></i>
                </span>
                <div class="min-w-0">
                    <div class="text-xs text-gray-400"><?= e($insight['label']) ?></div>
                    <div class="text-sm font-semibold text-gray-800 truncate"><?= e($insight['value']) ?></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- ═══════════ Copilote de gestion ═══════════ -->
<?php $at = $toneClasses[$assistantTone]; ?>
<div class="bg-white rounded-2xl border border-gray-100 shadow-card mb-6">
    <div class="p-5 sm:p-6 grid grid-cols-1 xl:grid-cols-3 gap-6">
        <div class="flex items-center gap-4">
            <div class="relative flex h-20 w-20 shrink-0 items-center justify-center rounded-full <?= $at['bg'] ?> ring-8 <?= $at['ring'] ?>">
                <strong class="text-2xl <?= $at['text'] ?>"><?= $assistantScore ?></strong>
                <small class="absolute bottom-2 text-[10px] text-gray-400">/100</small>
            </div>
            <div class="min-w-0">
                <span class="inline-flex items-center gap-1 text-[11px] font-semibold uppercase tracking-wide text-brand-600"><i class="bi bi-stars"></i> Copilote de gestion</span>
                <h3 class="text-base font-bold text-gray-900 mt-0.5"><?= e($assistantHeadline) ?></h3>
                <p class="text-sm text-gray-500 mt-0.5"><?= e($assistantSummary) ?></p>
                <div class="inline-flex items-center gap-1.5 mt-2 rounded-full <?= tw_tone($toneClasses, $assistantTrendTone, 'bg') ?> <?= tw_tone($toneClasses, $assistantTrendTone, 'text') ?> px-2.5 py-1 text-xs font-medium">
                    <i class="bi bi-activity"></i> <?= e($assistantTrendLabel) ?> : <strong><?= e($assistantTrendValue) ?></strong>
                </div>
            </div>
        </div>
        <div>
            <div class="text-xs font-semibold uppercase tracking-wide text-gray-400 mb-3">Diagnostic automatique</div>
            <div class="space-y-2">
                <?php foreach (array_slice($assistantFactors, 0, 4) as $factor): ?>
                <div class="flex items-center justify-between rounded-lg <?= tw_tone($toneClasses, $factor['tone'], 'bg') ?> px-3 py-2 text-sm">
                    <span class="text-gray-500"><?= e($factor['label']) ?></span>
                    <strong class="<?= tw_tone($toneClasses, $factor['tone'], 'text') ?>"><?= e($factor['value']) ?></strong>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <div>
            <div class="text-xs font-semibold uppercase tracking-wide text-gray-400 mb-3">Actions suggérées</div>
            <div class="space-y-2">
                <?php foreach (array_slice($assistantRecommendations, 0, 3) as $recommendation): ?>
                <a class="flex items-center justify-between rounded-lg border border-gray-100 px-3 py-2 text-sm hover:bg-gray-50 transition" href="<?= $recommendation['link'] ?>">
                    <span class="text-gray-600"><?= e($recommendation['text']) ?></span>
                    <strong class="text-brand-600 inline-flex items-center gap-0.5"><?= e($recommendation['button']) ?> <i class="bi bi-arrow-right-short"></i></strong>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<!-- ═══════════ Cartes résumé comptable ═══════════ -->
<div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4 mb-6">
    <div class="bg-white rounded-2xl border border-gray-100 shadow-card p-5">
        <div class="flex items-center gap-2 mb-2">
            <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-emerald-50 text-emerald-600"><i class="bi bi-arrow-up-circle-fill"></i></span>
            <span class="text-xs text-gray-400">Chiffre d'affaires</span>
        </div>
        <h3 class="text-xl font-bold text-emerald-600 mb-1"><?= formatMontant($stats['total_recettes']) ?></h3>
        <?php if ($stats['plafond_ca'] > 0): ?>
        <div class="h-1.5 rounded-full bg-gray-100 overflow-hidden mt-2">
            <div class="h-full rounded-full bg-emerald-500" style="width: <?= min($stats['pct_plafond'], 100) ?>%"></div>
        </div>
        <small class="text-xs text-gray-400"><?= $stats['pct_plafond'] ?>% du plafond</small>
        <?php endif; ?>
    </div>
    <div class="bg-white rounded-2xl border border-gray-100 shadow-card p-5">
        <div class="flex items-center gap-2 mb-2">
            <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-rose-50 text-rose-600"><i class="bi bi-arrow-down-circle-fill"></i></span>
            <span class="text-xs text-gray-400">Dépenses</span>
        </div>
        <h3 class="text-xl font-bold text-rose-600 mb-1"><?= formatMontant($stats['total_depenses']) ?></h3>
    </div>
    <div class="bg-white rounded-2xl border border-gray-100 shadow-card p-5">
        <div class="flex items-center gap-2 mb-2">
            <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-amber-50 text-amber-600"><i class="bi bi-bank"></i></span>
            <span class="text-xs text-gray-400"><?= $conf['is_micro'] ? 'Charges obligatoires' : ($conf['tva_applicable'] ? 'TVA à reverser' : 'Résultat brut') ?></span>
        </div>
        <?php if ($conf['is_micro']): ?>
            <h3 class="text-xl font-bold text-amber-600 mb-1"><?= formatMontant($stats['charges_obligatoires']) ?></h3>
            <small class="text-xs text-gray-400">URSSAF + CFP<?= $stats['versement_liberatoire'] > 0 ? ' + VL' : '' ?></small>
        <?php elseif ($conf['tva_applicable']): ?>
            <h3 class="text-xl font-bold text-amber-600 mb-1"><?= formatMontant($stats['tva_a_payer']) ?></h3>
            <small class="text-xs text-gray-400">Collectée : <?= formatMontant($stats['tva_collectee']) ?></small>
        <?php else: ?>
            <h3 class="text-xl font-bold text-amber-600 mb-1"><?= formatMontant($stats['benefice_avant_charges']) ?></h3>
        <?php endif; ?>
    </div>
    <div class="bg-white rounded-2xl border border-gray-100 shadow-card p-5">
        <div class="flex items-center gap-2 mb-2">
            <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-sky-50 text-sky-600"><i class="bi bi-wallet2"></i></span>
            <span class="text-xs text-gray-400"><?= $conf['is_micro'] ? 'Bénéfice net estimé' : 'Résultat net' ?></span>
        </div>
        <h3 class="text-xl font-bold mb-1 <?= $stats['benefice_net'] >= 0 ? 'text-sky-600' : 'text-rose-600' ?>">
            <?= formatMontant($stats['benefice_net']) ?>
        </h3>
        <?php if ($conf['is_micro']): ?>
        <small class="text-xs text-gray-400">Imposable : <?= formatMontant($stats['revenu_imposable']) ?></small>
        <?php endif; ?>
    </div>
</div>

<!-- ═══════════ Section commerciale rapide ═══════════ -->
<div class="grid grid-cols-2 sm:grid-cols-3 xl:grid-cols-6 gap-3 mb-6">
    <div class="bg-white rounded-xl border border-gray-100 shadow-card text-center py-4">
        <div class="text-xl font-bold text-brand-600"><?= $statsCommerciales['devis_total'] ?></div>
        <small class="text-xs text-gray-400">Devis</small>
    </div>
    <div class="bg-white rounded-xl border border-gray-100 shadow-card text-center py-4">
        <div class="text-xl font-bold text-emerald-600"><?= $statsCommerciales['devis_acceptes'] ?></div>
        <small class="text-xs text-gray-400">Acceptés</small>
    </div>
    <div class="bg-white rounded-xl border border-gray-100 shadow-card text-center py-4">
        <div class="text-xl font-bold text-brand-600"><?= formatMontant($statsCommerciales['ca_facture']) ?></div>
        <small class="text-xs text-gray-400">CA facturé</small>
    </div>
    <div class="bg-white rounded-xl border border-gray-100 shadow-card text-center py-4">
        <div class="text-xl font-bold text-amber-600"><?= formatMontant($statsCommerciales['en_attente_paiement']) ?></div>
        <small class="text-xs text-gray-400">En attente</small>
    </div>
    <div class="bg-white rounded-xl border <?= $statsCommerciales['factures_en_retard'] > 0 ? 'border-rose-200' : 'border-gray-100' ?> shadow-card text-center py-4">
        <div class="text-xl font-bold <?= $statsCommerciales['factures_en_retard'] > 0 ? 'text-rose-600' : 'text-gray-400' ?>"><?= $statsCommerciales['factures_en_retard'] ?></div>
        <small class="text-xs text-gray-400">En retard</small>
    </div>
    <div class="bg-white rounded-xl border border-gray-100 shadow-card text-center py-4">
        <div class="text-xl font-bold text-sky-600"><?= $statsCommerciales['clients_actifs'] ?></div>
        <small class="text-xs text-gray-400">Clients actifs</small>
    </div>
</div>

<!-- ═══════════ Graphiques ═══════════ -->
<div class="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-6">
    <div class="lg:col-span-2 bg-white rounded-2xl border border-gray-100 shadow-card">
        <div class="px-5 py-4 border-b border-gray-50 text-sm font-semibold text-gray-800">
            <i class="bi bi-graph-up text-brand-600"></i> Évolution mensuelle <?= $annee ?>
        </div>
        <div class="p-5">
            <canvas id="chartMensuel" height="280"></canvas>
        </div>
    </div>
    <div class="space-y-4">
        <div class="bg-white rounded-2xl border border-gray-100 shadow-card">
            <div class="px-5 py-4 border-b border-gray-50 text-sm font-semibold text-gray-800"><i class="bi bi-pie-chart text-brand-600"></i> Recettes par catégorie</div>
            <div class="p-5">
                <?php if (!empty($recettesParCat)): ?>
                    <canvas id="chartRecettes" height="180"></canvas>
                <?php else: ?>
                    <p class="text-sm text-gray-400 text-center mb-0">Aucune recette</p>
                <?php endif; ?>
            </div>
        </div>
        <div class="bg-white rounded-2xl border border-gray-100 shadow-card">
            <div class="px-5 py-4 border-b border-gray-50 text-sm font-semibold text-gray-800"><i class="bi bi-pie-chart text-brand-600"></i> Dépenses par catégorie</div>
            <div class="p-5">
                <?php if (!empty($depensesParCat)): ?>
                    <canvas id="chartDepenses" height="180"></canvas>
                <?php else: ?>
                    <p class="text-sm text-gray-400 text-center mb-0">Aucune dépense</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- ═══════════ Activité récente ═══════════ -->
<div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4 mb-6">
    <div class="xl:col-span-2 bg-white rounded-2xl border border-gray-100 shadow-card">
        <div class="flex items-center justify-between px-5 py-4 border-b border-gray-50">
            <span class="text-sm font-semibold text-gray-800"><i class="bi bi-clock-history text-brand-600"></i> Dernières écritures</span>
            <a href="<?= BASE_URL ?>transactions.php" class="text-xs font-semibold text-brand-600 hover:text-brand-700">Voir tout</a>
        </div>
        <?php if (empty($dernieres)): ?>
            <div class="text-center py-10 text-gray-400">
                <i class="bi bi-inbox text-3xl"></i>
                <p class="mt-2 mb-0 text-sm">Aucune écriture pour <?= $annee ?>.</p>
            </div>
        <?php else: ?>
            <div class="divide-y divide-gray-50">
                <?php foreach ($dernieres as $t): ?>
                    <div class="flex items-center justify-between px-5 py-3">
                        <div class="min-w-0">
                            <div class="text-sm font-medium text-gray-800 truncate"><?= e($t['description']) ?></div>
                            <div class="text-xs text-gray-400 mt-0.5 flex items-center gap-1.5">
                                <?= formatDate($t['date_transaction']) ?>
                                <?php if ($t['categorie_nom']): ?>
                                    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-medium text-white" style="background-color: <?= e($t['categorie_couleur']) ?>;"><?= e($t['categorie_nom']) ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <span class="text-sm font-bold shrink-0 ml-3 <?= $t['type'] === 'recette' ? 'text-emerald-600' : 'text-rose-600' ?>">
                            <?= $t['type'] === 'recette' ? '+' : '-' ?><?= formatMontant($t['montant']) ?>
                        </span>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="bg-white rounded-2xl border border-gray-100 shadow-card">
        <div class="flex items-center justify-between px-5 py-4 border-b border-gray-50">
            <span class="text-sm font-semibold text-gray-800"><i class="bi bi-file-earmark-text text-brand-600"></i> Derniers devis</span>
            <a href="<?= BASE_URL ?>devis.php" class="text-brand-600 hover:text-brand-700"><i class="bi bi-arrow-right"></i></a>
        </div>
        <?php if (empty($derniersDevis)): ?>
            <div class="text-center py-6 text-sm text-gray-400">Aucun devis</div>
        <?php else: foreach ($derniersDevis as $d): $sd = getStatutDevisLabel($d['statut']); ?>
            <a href="devis.php?action=voir&id=<?= $d['id'] ?>" class="block px-5 py-3 hover:bg-gray-50 transition border-b border-gray-50 last:border-0">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-semibold text-gray-700"><?= e($d['numero']) ?></span>
                    <span class="badge bg-<?= $sd['class'] ?> fs-065"><?= $sd['label'] ?></span>
                </div>
                <div class="text-xs text-gray-400 mt-0.5 truncate"><?= e($d['client_entreprise'] ?: trim(($d['client_prenom'] ?? '') . ' ' . ($d['client_nom'] ?? ''))) ?></div>
                <div class="text-right text-xs font-bold text-gray-700 mt-0.5"><?= formatMontant($d['montant_ttc']) ?></div>
            </a>
        <?php endforeach; endif; ?>
    </div>

    <div class="bg-white rounded-2xl border border-gray-100 shadow-card">
        <div class="flex items-center justify-between px-5 py-4 border-b border-gray-50">
            <span class="text-sm font-semibold text-gray-800"><i class="bi bi-receipt text-brand-600"></i> Dernières factures</span>
            <a href="<?= BASE_URL ?>factures.php" class="text-brand-600 hover:text-brand-700"><i class="bi bi-arrow-right"></i></a>
        </div>
        <?php if (empty($dernieresFactures)): ?>
            <div class="text-center py-6 text-sm text-gray-400">Aucune facture</div>
        <?php else: foreach ($dernieresFactures as $f): $sf = getStatutFactureLabel($f['statut']); ?>
            <a href="factures.php?action=voir&id=<?= $f['id'] ?>" class="block px-5 py-3 hover:bg-gray-50 transition border-b border-gray-50 last:border-0">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-semibold text-gray-700"><?= e($f['numero']) ?></span>
                    <span class="badge bg-<?= $sf['class'] ?> fs-065"><?= $sf['label'] ?></span>
                </div>
                <div class="text-xs text-gray-400 mt-0.5 truncate"><?= e($f['client_entreprise'] ?: trim(($f['client_prenom'] ?? '') . ' ' . ($f['client_nom'] ?? ''))) ?></div>
                <div class="text-right text-xs font-bold text-gray-700 mt-0.5"><?= formatMontant($f['montant_ttc']) ?></div>
            </a>
        <?php endforeach; endif; ?>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════ -->
<!-- INTELLIGENCE AVANCÉE                                     -->
<!-- ═══════════════════════════════════════════════════════ -->

<!-- Alertes proactives avancées -->
<?php if (!empty($alertesProactives)): ?>
<div class="bg-white rounded-2xl border border-gray-100 shadow-card mb-6 overflow-hidden">
    <div class="px-5 py-4 bg-gradient-to-r from-brand-600 to-brand-700 text-white text-sm font-semibold flex items-center gap-2">
        <i class="bi bi-lightning-charge-fill"></i> Alertes proactives
        <span class="inline-flex items-center rounded-full bg-white/20 px-2 py-0.5 text-xs">​<?= count($alertesProactives) ?></span>
    </div>
    <div class="divide-y divide-gray-50">
        <?php foreach (array_slice($alertesProactives, 0, 5) as $alerte): ?>
        <a href="<?= BASE_URL . e($alerte['link']) ?>" class="flex items-center gap-3 px-5 py-3 hover:bg-gray-50 transition">
            <span class="flex h-9 w-9 items-center justify-center rounded-lg shrink-0 <?= tw_tone($toneClasses, $alerte['type'], 'bg') ?> <?= tw_tone($toneClasses, $alerte['type'], 'text') ?>">
                <i class="bi bi-<?= e($alerte['icon']) ?>"></i>
            </span>
            <div class="flex-1 min-w-0">
                <div class="text-sm font-medium text-gray-800 truncate"><?= e($alerte['titre']) ?></div>
                <div class="text-xs text-gray-400 truncate"><?= e($alerte['texte']) ?></div>
            </div>
            <i class="bi bi-chevron-right text-gray-300"></i>
        </a>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- KPIs avancés temps réel -->
<div class="bg-white rounded-2xl border border-gray-100 shadow-card mb-6 overflow-hidden">
    <div class="px-5 py-4 bg-gradient-to-r from-brand-600 to-brand-700 text-white text-sm font-semibold">
        <i class="bi bi-speedometer"></i> KPIs avancés
    </div>
    <div class="p-5">
        <div class="grid grid-cols-2 xl:grid-cols-4 gap-3">
            <div class="rounded-xl bg-gray-50 p-4">
                <div class="text-xl font-bold text-gray-900"><?= $kpisAvances['taux_marge_brute'] ?>%</div>
                <div class="text-xs font-medium text-gray-500 mt-0.5">Marge brute</div>
                <div class="text-xs text-gray-400"><?= formatMontant($kpisAvances['marge_brute']) ?></div>
            </div>
            <div class="rounded-xl bg-gray-50 p-4">
                <div class="text-xl font-bold text-gray-900"><?= $kpisAvances['dso'] ?> j</div>
                <div class="text-xs font-medium text-gray-500 mt-0.5">Délai paiement (DSO)</div>
                <div class="text-xs text-gray-400"><?= $kpisAvances['dso'] <= 30 ? 'Excellent' : ($kpisAvances['dso'] <= 60 ? 'Correct' : 'À améliorer') ?></div>
            </div>
            <div class="rounded-xl bg-gray-50 p-4">
                <div class="text-xl font-bold text-gray-900"><?= $kpisAvances['taux_conversion_devis'] ?>%</div>
                <div class="text-xs font-medium text-gray-500 mt-0.5">Conversion devis→facture</div>
                <div class="text-xs text-gray-400"><?= $kpisAvances['devis_convertis'] ?? 0 ?>/<?= $kpisAvances['devis_total'] ?? 0 ?> devis</div>
            </div>
            <div class="rounded-xl bg-gray-50 p-4">
                <div class="text-xl font-bold text-gray-900"><?= $kpisAvances['taux_recouvrement'] ?>%</div>
                <div class="text-xs font-medium text-gray-500 mt-0.5">Taux recouvrement</div>
                <div class="text-xs text-gray-400">CA moyen client : <?= formatMontant($kpisAvances['ca_moyen_client']) ?></div>
            </div>
        </div>
        <?php if (!empty($kpisAvances['meilleur_mois'])): ?>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mt-3">
            <div class="flex items-center gap-2 text-sm text-gray-600">
                <i class="bi bi-trophy-fill text-amber-500"></i>
                <span>Meilleur mois : <strong class="text-gray-800"><?= e($kpisAvances['meilleur_mois']) ?></strong> (<?= formatMontant($kpisAvances['meilleur_mois_ca']) ?>)</span>
            </div>
            <div class="flex items-center gap-2 text-sm text-gray-600">
                <i class="bi bi-bar-chart text-gray-400"></i>
                <span>Dépenses moy./mois : <strong class="text-gray-800"><?= formatMontant($kpisAvances['depenses_moy_mensuelles']) ?></strong></span>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Dashboard prédictif : Projection CA + Trésorerie -->
<div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-6">
    <!-- Projection CA -->
    <div class="bg-white rounded-2xl border border-gray-100 shadow-card p-5 sm:p-6">
        <div class="flex items-center justify-between mb-4">
            <div class="flex items-center gap-2.5">
                <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-brand-50 text-brand-600"><i class="bi bi-graph-up-arrow"></i></span>
                <div>
                    <h6 class="text-sm font-bold text-gray-900 mb-0">Projection CA <?= $annee ?></h6>
                    <small class="text-xs text-gray-400"><?= $projectionCA['mois_courant'] ?> mois écoulés — <?= $projectionCA['mois_restants'] ?> restants</small>
                </div>
            </div>
            <span class="inline-flex items-center gap-1 rounded-full <?= tw_tone($toneClasses, $assistantTrendTone, 'bg') ?> <?= tw_tone($toneClasses, $assistantTrendTone, 'text') ?> px-2.5 py-1 text-xs font-medium">
                <i class="bi bi-<?= $projectionCA['tendance'] === 'hausse' ? 'trending-up' : ($projectionCA['tendance'] === 'baisse' ? 'trending-down' : 'dash-lg') ?>"></i>
                <?= ucfirst($projectionCA['tendance']) ?>
            </span>
        </div>

        <div class="grid grid-cols-2 gap-3 mb-4">
            <div class="rounded-xl bg-gray-50 p-3">
                <span class="block text-xs text-gray-400">CA réalisé</span>
                <span class="block text-lg font-bold text-emerald-600"><?= formatMontant($projectionCA['total_actuel']) ?></span>
                <span class="block text-xs text-gray-400">Moy. <?= formatMontant($projectionCA['moyenne_mensuelle']) ?>/mois</span>
            </div>
            <div class="rounded-xl bg-gray-50 p-3">
                <span class="block text-xs text-gray-400">Projection annuelle</span>
                <span class="block text-lg font-bold text-brand-600"><?= formatMontant($projectionCA['projection_lineaire']) ?></span>
                <?php if ($projectionCA['ca_n1'] > 0): ?>
                <span class="block text-xs <?= $projectionCA['variation_n1'] >= 0 ? 'text-emerald-600' : 'text-rose-600' ?>">
                    <i class="bi bi-<?= $projectionCA['variation_n1'] >= 0 ? 'arrow-up-short' : 'arrow-down-short' ?>"></i>
                    <?= ($projectionCA['variation_n1'] >= 0 ? '+' : '') . $projectionCA['variation_n1'] ?>% vs <?= $annee - 1 ?>
                </span>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($projectionCA['plafond_ca'] > 0): ?>
        <div class="mb-4">
            <div class="flex items-center justify-between mb-1 text-xs">
                <span class="text-gray-400"><i class="bi bi-speedometer"></i> Plafond micro-entreprise</span>
                <strong class="<?= $projectionCA['pct_projection_plafond'] > 90 ? 'text-rose-600' : ($projectionCA['pct_projection_plafond'] > 70 ? 'text-amber-600' : 'text-emerald-600') ?>"><?= $projectionCA['pct_projection_plafond'] ?>%</strong>
            </div>
            <div class="h-1.5 rounded-full bg-gray-100 overflow-hidden">
                <div class="h-full rounded-full <?= $projectionCA['pct_projection_plafond'] > 90 ? 'bg-rose-500' : ($projectionCA['pct_projection_plafond'] > 70 ? 'bg-amber-500' : 'bg-emerald-500') ?>"
                     style="width: <?= min(100, $projectionCA['pct_projection_plafond']) ?>%"></div>
            </div>
            <div class="flex items-center justify-between mt-1 text-xs text-gray-400">
                <span><?= formatMontant($projectionCA['projection_lineaire']) ?></span>
                <span><?= formatMontant($projectionCA['plafond_ca']) ?></span>
            </div>
        </div>
        <?php endif; ?>

        <canvas id="chartProjection" height="180"></canvas>
    </div>

    <!-- Trésorerie prévisionnelle -->
    <div class="bg-white rounded-2xl border border-gray-100 shadow-card p-5 sm:p-6">
        <div class="flex items-center justify-between mb-4">
            <div class="flex items-center gap-2.5">
                <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-sky-50 text-sky-600"><i class="bi bi-safe"></i></span>
                <div>
                    <h6 class="text-sm font-bold text-gray-900 mb-0">Trésorerie prévisionnelle</h6>
                    <small class="text-xs text-gray-400">Projection sur 6 mois glissants</small>
                </div>
            </div>
            <?php if ($tresorerie['runway_mois'] !== null): ?>
            <?php $runwayTone = $tresorerie['runway_mois'] > 6 ? 'success' : ($tresorerie['runway_mois'] > 3 ? 'warning' : 'danger'); ?>
            <span class="inline-flex items-center gap-1 rounded-full <?= tw_tone($toneClasses, $runwayTone, 'bg') ?> <?= tw_tone($toneClasses, $runwayTone, 'text') ?> px-2.5 py-1 text-xs font-medium">
                <i class="bi bi-fuel-pump"></i> <?= $tresorerie['runway_mois'] ?> mois
            </span>
            <?php endif; ?>
        </div>

        <div class="grid grid-cols-3 gap-2 mb-4">
            <div class="rounded-xl bg-gray-50 p-3">
                <span class="block text-xs text-gray-400">Solde actuel</span>
                <span class="block text-sm font-bold <?= $tresorerie['solde_actuel'] >= 0 ? 'text-emerald-600' : 'text-rose-600' ?>"><?= formatMontant($tresorerie['solde_actuel']) ?></span>
            </div>
            <div class="rounded-xl bg-gray-50 p-3">
                <span class="block text-xs text-gray-400">Entrées prévues</span>
                <span class="block text-sm font-bold text-sky-600"><?= formatMontant($tresorerie['entrees_prevues']) ?></span>
            </div>
            <div class="rounded-xl bg-gray-50 p-3">
                <span class="block text-xs text-gray-400">Flux net/mois</span>
                <span class="block text-sm font-bold <?= $tresorerie['flux_net_moyen'] >= 0 ? 'text-emerald-600' : 'text-rose-600' ?>"><?= formatMontant($tresorerie['flux_net_moyen']) ?></span>
            </div>
        </div>

        <?php if ($tresorerie['runway_mois'] !== null): ?>
        <div class="mb-4">
            <div class="flex items-center justify-between mb-1 text-xs">
                <span class="text-gray-400"><i class="bi bi-fuel-pump"></i> Runway estimé</span>
                <strong class="<?= $tresorerie['runway_mois'] > 6 ? 'text-emerald-600' : ($tresorerie['runway_mois'] > 3 ? 'text-amber-600' : 'text-rose-600') ?>">
                    <?= $tresorerie['runway_mois'] ?> mois
                </strong>
            </div>
            <div class="h-1.5 rounded-full bg-gray-100 overflow-hidden">
                <div class="h-full rounded-full <?= $tresorerie['runway_mois'] > 6 ? 'bg-emerald-500' : ($tresorerie['runway_mois'] > 3 ? 'bg-amber-500' : 'bg-rose-500') ?>"
                     style="width: <?= min(100, ($tresorerie['runway_mois'] / 12) * 100) ?>%"></div>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($tresorerie['date_rupture']): ?>
        <div class="flex items-start gap-2 rounded-xl bg-rose-50 text-rose-700 px-3 py-2.5 text-sm mb-4">
            <i class="bi bi-exclamation-triangle-fill mt-0.5"></i>
            <span>Rupture de trésorerie estimée dans <strong><?= $tresorerie['mois_avant_rupture'] ?> mois</strong>
            <span class="text-xs text-rose-500">(~<?= date('M Y', strtotime($tresorerie['date_rupture'])) ?>)</span></span>
        </div>
        <?php endif; ?>

        <canvas id="chartTresorerie" height="160"></canvas>
    </div>
</div>

<!-- Charges URSSAF trimestrielles (micro uniquement) -->
<?php if (!empty($chargesTrimestrielles)): ?>
<div class="bg-white rounded-2xl border border-gray-100 shadow-card mb-6 overflow-hidden">
    <div class="px-5 py-4 bg-gradient-to-r from-brand-600 to-brand-700 text-white text-sm font-semibold">
        <i class="bi bi-bank2"></i> Estimation charges URSSAF <?= $annee ?>
    </div>
    <div class="overflow-x-auto">
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
<?php endif; ?>

<!-- Analyse clients + Produits -->
<div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-6">
    <!-- Top clients -->
    <div class="bg-white rounded-2xl border border-gray-100 shadow-card overflow-hidden">
        <div class="px-5 py-4 bg-gradient-to-r from-brand-600 to-brand-700 text-white text-sm font-semibold flex items-center justify-between flex-wrap gap-2">
            <span><i class="bi bi-people-fill"></i> Analyse clients</span>
            <div class="flex gap-2">
                <span class="inline-flex items-center rounded-full bg-white/90 text-gray-700 px-2.5 py-1 text-xs font-medium"><?= $analyseClients['taux_fidelisation'] ?>% fidélisés</span>
                <span class="inline-flex items-center rounded-full bg-white/90 text-gray-700 px-2.5 py-1 text-xs font-medium">Panier moy : <?= formatMontant($analyseClients['panier_moyen']) ?></span>
            </div>
        </div>
        <?php if (!empty($analyseClients['top_clients'])): ?>
        <div class="overflow-x-auto">
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
        <div class="text-center py-10 text-gray-400">
            <i class="bi bi-people text-3xl"></i>
            <p class="mt-2 mb-0 text-sm">Pas encore assez de données clients.</p>
        </div>
        <?php endif; ?>

        <?php if (!empty($analyseClients['clients_dormants'])): ?>
        <div class="border-t border-gray-100 px-5 py-3">
            <small class="text-rose-600 font-semibold"><i class="bi bi-moon-stars"></i> Clients dormants (&gt;6 mois) :</small>
            <?php foreach (array_slice($analyseClients['clients_dormants'], 0, 3) as $d): ?>
            <span class="badge bg-danger-subtle text-danger ms-1">
                <?= e($d['entreprise'] ?: trim(($d['prenom'] ?? '') . ' ' . $d['nom'])) ?>
                <small>(<?= $d['jours_inactif'] ?>j)</small>
            </span>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- Analyse produits -->
    <div class="bg-white rounded-2xl border border-gray-100 shadow-card overflow-hidden">
        <div class="px-5 py-4 bg-gradient-to-r from-brand-600 to-brand-700 text-white text-sm font-semibold flex items-center justify-between flex-wrap gap-2">
            <span><i class="bi bi-box-seam-fill"></i> Analyse produits</span>
            <?php if (!empty($analyseProduits['reappro_urgente'])): ?>
            <span class="inline-flex items-center rounded-full bg-rose-500 px-2.5 py-1 text-xs font-medium"><?= count($analyseProduits['reappro_urgente']) ?> réappro urgente(s)</span>
            <?php endif; ?>
        </div>
        <?php if (!empty($analyseProduits['best_sellers'])): ?>
        <div class="overflow-x-auto">
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
        <div class="text-center py-10 text-gray-400">
            <i class="bi bi-box-seam text-3xl"></i>
            <p class="mt-2 mb-0 text-sm">Pas encore de données de vente produits.</p>
        </div>
        <?php endif; ?>

        <?php if (!empty($analyseProduits['stock_mort'])): ?>
        <div class="border-t border-gray-100 px-5 py-3">
            <small class="text-amber-600 font-semibold"><i class="bi bi-archive"></i> Stock mort (&gt;90j sans mouvement) :</small>
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
<div class="bg-white rounded-2xl border border-gray-100 shadow-card mb-6 overflow-hidden">
    <div class="px-5 py-4 bg-gradient-to-r from-brand-600 to-brand-700 text-white text-sm font-semibold flex items-center justify-between flex-wrap gap-2">
        <span>
            <i class="bi bi-stars"></i> Suggestions intelligentes
            <span class="text-white/70 font-normal ms-2 hidden sm:inline"><?= $nbTotal ?> suggestion<?= $nbTotal > 1 ? 's' : '' ?> basée<?= $nbTotal > 1 ? 's' : '' ?> sur vos données</span>
        </span>
        <?php if ($nbCritiques > 0): ?>
        <span class="inline-flex items-center rounded-full bg-rose-500 px-2.5 py-1 text-xs font-medium"><?= $nbCritiques ?> action<?= $nbCritiques > 1 ? 's' : '' ?> recommandée<?= $nbCritiques > 1 ? 's' : '' ?></span>
        <?php endif; ?>
    </div>
    <div class="p-5">
        <!-- Filtres par catégorie -->
        <div class="flex flex-wrap gap-2 mb-4" id="ia-filters">
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
        <div class="flex items-start gap-2 rounded-xl bg-rose-50 text-rose-700 px-4 py-3 text-sm mb-4">
            <i class="bi bi-exclamation-triangle-fill mt-0.5"></i>
            <div>
                <strong><?= count($critiques) ?> alerte<?= count($critiques) > 1 ? 's' : '' ?> critique<?= count($critiques) > 1 ? 's' : '' ?></strong> —
                <?php foreach ($critiques as $c): ?>
                    <?= e($c['titre']) ?><?= !empty($c['lien']) ? ' <a href="' . e($c['lien']) . '" class="text-rose-800 font-semibold underline">Agir →</a>' : '' ?>.
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Cartes suggestions -->
        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-3">
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
            <div class="ia-card" data-ia-cat="<?= $cat ?>" <?= $i >= 6 ? 'style="display:none"' : '' ?>>
                <div class="flex gap-3 rounded-xl p-3.5 h-100" style="background:<?= $tc['bg'] ?>;border-left:3px solid <?= $tc['border'] ?>">
                    <div class="flex-shrink-0">
                        <div class="d-flex align-items-center justify-content-center rounded-circle text-white icon-circle-38" style="background:<?= $tc['icon_bg'] ?>">
                            <i class="bi bi-<?= e($reco['icon']) ?>"></i>
                        </div>
                    </div>
                    <div class="flex-grow-1 min-w-0">
                        <div class="d-flex justify-content-between align-items-start mb-1">
                            <div class="fw-semibold lh-sm fs-088"><?= e($reco['titre']) ?></div>
                            <span class="badge bg-<?= $catMeta['color'] ?>-subtle text-<?= $catMeta['color'] ?> ms-2 flex-shrink-0 fs-065"><?= $catMeta['label'] ?></span>
                        </div>
                        <div class="text-muted mb-2 fs-08-lh135"><?= e($reco['texte']) ?></div>
                        <?php if (!empty($reco['lien'])): ?>
                        <a href="<?= e($reco['lien']) ?>" class="btn btn-sm btn-outline-<?= e($reco['type']) ?> py-0 px-2 fs-075">
                            <i class="bi bi-arrow-right"></i> Agir
                        </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <?php if ($nbTotal > 6): ?>
        <div class="text-center mt-4">
            <button class="btn btn-sm btn-outline-primary" id="ia-show-all">
                <i class="bi bi-chevron-down"></i> Voir les <?= $nbTotal - 6 ?> autres suggestion<?= ($nbTotal - 6) > 1 ? 's' : '' ?>
            </button>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- Raccourcis action rapide -->
<div class="bg-white rounded-2xl border border-gray-100 shadow-card p-5 mb-2">
    <div class="grid grid-cols-2 sm:grid-cols-3 <?= $cpModuleEnabled ? 'xl:grid-cols-6' : 'xl:grid-cols-5' ?> gap-3 text-center">
        <a href="transactions.php?action=ajouter&type=recette" class="flex flex-col items-center gap-1.5 rounded-xl border border-gray-100 py-4 text-emerald-600 hover:bg-emerald-50 transition">
            <i class="bi bi-plus-circle text-lg"></i><small class="text-xs font-medium">Saisir une recette</small>
        </a>
        <a href="transactions.php?action=ajouter&type=depense" class="flex flex-col items-center gap-1.5 rounded-xl border border-gray-100 py-4 text-rose-600 hover:bg-rose-50 transition">
            <i class="bi bi-dash-circle text-lg"></i><small class="text-xs font-medium">Saisir une dépense</small>
        </a>
        <a href="devis.php?action=nouveau" class="flex flex-col items-center gap-1.5 rounded-xl border border-gray-100 py-4 text-brand-600 hover:bg-brand-50 transition">
            <i class="bi bi-file-earmark-text text-lg"></i><small class="text-xs font-medium">Créer un devis</small>
        </a>
        <a href="factures.php?action=nouvelle" class="flex flex-col items-center gap-1.5 rounded-xl border border-gray-100 py-4 text-sky-600 hover:bg-sky-50 transition">
            <i class="bi bi-receipt text-lg"></i><small class="text-xs font-medium">Créer une facture</small>
        </a>
        <a href="rapports.php?annee=<?= $annee ?>" class="flex flex-col items-center gap-1.5 rounded-xl border border-gray-100 py-4 text-amber-600 hover:bg-amber-50 transition">
            <i class="bi bi-bar-chart-line text-lg"></i><small class="text-xs font-medium">Voir les rapports</small>
        </a>
        <?php if ($cpModuleEnabled): ?>
        <a href="conges.php" class="flex flex-col items-center gap-1.5 rounded-xl border border-gray-100 py-4 text-gray-600 hover:bg-gray-50 transition">
            <i class="bi bi-calendar2-check text-lg"></i><small class="text-xs font-medium">Gérer les CP</small>
        </a>
        <?php endif; ?>
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

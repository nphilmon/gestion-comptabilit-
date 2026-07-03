<?php
/**
 * Section Comptabilité — inspirée de Paheko
 * - Livre des recettes (obligatoire micro-entrepreneur)
 * - Registre des achats
 * - Journal général
 * - Grand livre
 * - Balance générale
 * - Trésorerie
 * - Export CSV / FEC
 */
require_once __DIR__ . '/functions.php';
requireLogin();
requireRole('admin', 'comptable');
$titre = 'Comptabilité';

$annee = isset($_GET['annee']) ? (int) $_GET['annee'] : (int) date('Y');
$onglet = $_GET['onglet'] ?? 'recettes';
$annees = getAnneesDisponibles();

// --- Export CSV ---
if (isset($_GET['export'])) {
    $type = $_GET['export'];
    if (!in_array($type, ['recettes', 'achats', 'journal', 'fec'])) {
        setFlash('danger', 'Type d\'export invalide.');
        header('Location: comptabilite.php');
        exit;
    }

    if ($type === 'fec') {
        // Export FEC (Fichier des Écritures Comptables)
        $all = getTransactions(['annee' => $annee]);
        $allChrono = array_reverse($all);
        $siren = getParam('siren', '000000000');
        $nomFichier = $siren . 'FEC' . $annee . '1231.txt';

        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $nomFichier . '"');
        $out = fopen('php://output', 'w');

        // En-tête FEC obligatoire
        fputcsv($out, [
            'JournalCode', 'JournalLib', 'EcritureNum', 'EcritureDate',
            'CompteNum', 'CompteLib', 'CompAuxNum', 'CompAuxLib',
            'PieceRef', 'PieceDate', 'EcritureLib', 'Debit',
            'Credit', 'EcritureLet', 'DateLet', 'ValidDate',
            'Montantdevise', 'Idevise'
        ], "\t");

        foreach ($allChrono as $i => $t) {
            $dateFormatted = date('Ymd', strtotime($t['date_transaction']));
            $compteNum = $t['type'] === 'recette' ? '706000' : '607000';
            $compteLib = $t['type'] === 'recette' ? 'Prestations de services' : 'Achats de marchandises';
            $journalCode = $t['type'] === 'recette' ? 'VE' : 'AC';
            $journalLib = $t['type'] === 'recette' ? 'Journal des ventes' : 'Journal des achats';
            $ecritureNum = sprintf('%s-%06d', $journalCode, $i + 1);

            // Ligne écriture
            fputcsv($out, [
                $journalCode, $journalLib, $ecritureNum, $dateFormatted,
                $compteNum, $compteLib, '', $t['client_fournisseur'] ?? '',
                $t['reference'] ?? '', $dateFormatted, $t['description'],
                $t['type'] === 'depense' ? number_format($t['montant'], 2, ',', '') : '0,00',
                $t['type'] === 'recette' ? number_format($t['montant'], 2, ',', '') : '0,00',
                '', '', $dateFormatted, '', ''
            ], "\t");
        }
        fclose($out);
        exit;
    }

    $filtreType = $type === 'recettes' ? 'recette' : ($type === 'achats' ? 'depense' : '');
    if ($type === 'journal') {
        $data = getTransactions(['annee' => $annee]);
    } else {
        $data = getTransactions(['type' => $filtreType, 'annee' => $annee]);
    }

    $nomsFichier = [
        'recettes' => 'livre_recettes_' . $annee . '.csv',
        'achats' => 'registre_achats_' . $annee . '.csv',
        'journal' => 'journal_general_' . $annee . '.csv',
    ];

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . ($nomsFichier[$type] ?? 'export.csv') . '"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");

    if ($type === 'recettes') {
        fputcsv($out, ['N°', 'Date d\'encaissement', 'Référence facture', 'Client', 'Nature de la prestation', 'Montant (€)', 'Mode de règlement'], ';');
        $num = 0;
        $totalAnnuel = 0;
        foreach ($data as $t) {
            $num++;
            $totalAnnuel += $t['montant'];
            fputcsv($out, [$num, date('d/m/Y', strtotime($t['date_transaction'])), $t['reference'] ?? '', $t['client_fournisseur'] ?? '', $t['description'], number_format($t['montant'], 2, ',', ''), ucfirst($t['mode_paiement'])], ';');
        }
        fputcsv($out, ['', '', '', '', 'TOTAL ANNUEL', number_format($totalAnnuel, 2, ',', ''), ''], ';');
    } elseif ($type === 'achats') {
        fputcsv($out, ['N°', 'Date d\'achat', 'Référence', 'Fournisseur', 'Nature de l\'achat', 'Catégorie', 'Montant (€)', 'Mode de règlement'], ';');
        $num = 0;
        $totalAnnuel = 0;
        foreach ($data as $t) {
            $num++;
            $totalAnnuel += $t['montant'];
            fputcsv($out, [$num, date('d/m/Y', strtotime($t['date_transaction'])), $t['reference'] ?? '', $t['client_fournisseur'] ?? '', $t['description'], $t['categorie_nom'] ?? 'Non classé', number_format($t['montant'], 2, ',', ''), ucfirst($t['mode_paiement'])], ';');
        }
        fputcsv($out, ['', '', '', '', '', 'TOTAL ANNUEL', number_format($totalAnnuel, 2, ',', ''), ''], ';');
    } elseif ($type === 'journal') {
        fputcsv($out, ['N°', 'Date', 'Type', 'Description', 'Catégorie', 'Tiers', 'Débit (€)', 'Crédit (€)', 'Référence', 'Mode'], ';');
        $num = 0;
        foreach (array_reverse($data) as $t) {
            $num++;
            fputcsv($out, [
                $num,
                date('d/m/Y', strtotime($t['date_transaction'])),
                $t['type'] === 'recette' ? 'Recette' : 'Dépense',
                $t['description'],
                $t['categorie_nom'] ?? 'Non classé',
                $t['client_fournisseur'] ?? '',
                $t['type'] === 'depense' ? number_format($t['montant'], 2, ',', '') : '',
                $t['type'] === 'recette' ? number_format($t['montant'], 2, ',', '') : '',
                $t['reference'] ?? '',
                ucfirst($t['mode_paiement']),
            ], ';');
        }
    }

    fclose($out);
    exit;
}

// --- Données ---
$conf = getRegimeConfig();
$recettes = getTransactions(['type' => 'recette', 'annee' => $annee]);
$achats = getTransactions(['type' => 'depense', 'annee' => $annee]);
$toutesTransactions = getTransactions(['annee' => $annee]);

$totalRecettes = array_sum(array_column($recettes, 'montant'));
$totalAchats = array_sum(array_column($achats, 'montant'));

// Cumul par trimestre
$trimestres = [1 => 0, 2 => 0, 3 => 0, 4 => 0];
foreach ($recettes as $r) {
    $m = (int) date('n', strtotime($r['date_transaction']));
    $q = (int) ceil($m / 3);
    $trimestres[$q] += $r['montant'];
}

// Numérotation chronologique
$recettesChronologiques = array_reverse($recettes);
$achatsChronologiques = array_reverse($achats);
$journalChronologique = array_reverse($toutesTransactions);

// Grand livre : grouper par catégorie
$grandLivre = [];
foreach ($journalChronologique as $t) {
    $catKey = $t['categorie_nom'] ?? 'Non classé';
    if (!isset($grandLivre[$catKey])) {
        $grandLivre[$catKey] = [
            'couleur' => $t['categorie_couleur'] ?? '#6c757d',
            'total_debit' => 0,
            'total_credit' => 0,
            'ecritures' => [],
        ];
    }
    $grandLivre[$catKey]['ecritures'][] = $t;
    if ($t['type'] === 'depense') {
        $grandLivre[$catKey]['total_debit'] += $t['montant'];
    } else {
        $grandLivre[$catKey]['total_credit'] += $t['montant'];
    }
}
ksort($grandLivre);

// Balance générale
$balance = [];
foreach ($grandLivre as $catNom => $catData) {
    $solde = $catData['total_credit'] - $catData['total_debit'];
    $balance[] = [
        'compte' => $catNom,
        'couleur' => $catData['couleur'],
        'nb_ecritures' => count($catData['ecritures']),
        'total_debit' => $catData['total_debit'],
        'total_credit' => $catData['total_credit'],
        'solde' => $solde,
    ];
}

include 'header.php';
?>

<div class="hero-banner mb-4">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
            <h2 class="mb-1"><i class="bi bi-journal-bookmark-fill"></i> Comptabilité <?= $annee ?></h2>
            <p class="text-muted mb-0">Livre des recettes, registre des achats et grand livre de l'exercice</p>
        </div>
        <div class="d-flex gap-2">
            <div class="btn-group">
                <?php foreach ($annees as $a): ?>
                    <a href="?annee=<?= $a ?>&onglet=<?= e($onglet) ?>"
                       class="btn btn-sm <?= $a === $annee ? 'btn-primary' : 'btn-outline-primary' ?>">
                        <?= $a ?>
                    </a>
                <?php endforeach; ?>
            </div>
            <div class="dropdown">
                <button class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown">
                    <i class="bi bi-download"></i> Exporter
                </button>
                <ul class="dropdown-menu dropdown-menu-end">
                    <li><a class="dropdown-item" href="?annee=<?= $annee ?>&export=recettes"><i class="bi bi-filetype-csv text-success"></i> Livre des recettes (CSV)</a></li>
                    <li><a class="dropdown-item" href="?annee=<?= $annee ?>&export=achats"><i class="bi bi-filetype-csv text-danger"></i> Registre des achats (CSV)</a></li>
                    <li><a class="dropdown-item" href="?annee=<?= $annee ?>&export=journal"><i class="bi bi-filetype-csv text-primary"></i> Journal général (CSV)</a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item" href="?annee=<?= $annee ?>&export=fec"><i class="bi bi-file-earmark-code text-dark"></i> Export FEC (contrôle fiscal)</a></li>
                </ul>
            </div>
        </div>
    </div>
</div>

<!-- Résumé comptable -->
<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="card stat-card border-0 h-100">
            <div class="card-body">
                <div class="d-flex align-items-center mb-2">
                    <div class="stat-icon bg-success-subtle text-success"><i class="bi bi-arrow-up-circle-fill"></i></div>
                    <small class="text-muted ms-2">Total recettes</small>
                </div>
                <h3 class="text-success mb-1"><?= formatMontant($totalRecettes) ?></h3>
                <small class="text-muted"><?= count($recettes) ?> opérations</small>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card border-0 h-100">
            <div class="card-body">
                <div class="d-flex align-items-center mb-2">
                    <div class="stat-icon bg-danger-subtle text-danger"><i class="bi bi-arrow-down-circle-fill"></i></div>
                    <small class="text-muted ms-2">Total achats / dépenses</small>
                </div>
                <h3 class="text-danger mb-1"><?= formatMontant($totalAchats) ?></h3>
                <small class="text-muted"><?= count($achats) ?> opérations</small>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card border-0 h-100">
            <div class="card-body">
                <div class="d-flex align-items-center mb-2">
                    <div class="stat-icon bg-info-subtle text-info"><i class="bi bi-wallet2"></i></div>
                    <small class="text-muted ms-2">Résultat</small>
                </div>
                <h3 class="<?= ($totalRecettes - $totalAchats) >= 0 ? 'text-info' : 'text-danger' ?> mb-1"><?= formatMontant($totalRecettes - $totalAchats) ?></h3>
                <small class="text-muted"><?= count($toutesTransactions) ?> écritures totales</small>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card border-0 h-100">
            <div class="card-body">
                <div class="d-flex align-items-center mb-2">
                    <div class="stat-icon bg-primary-subtle text-primary"><i class="bi bi-bank"></i></div>
                    <small class="text-muted ms-2">
                        CA trimestriel <?= $conf['is_micro'] ? '(URSSAF)' : '' ?>
                    </small>
                </div>
                <div class="row text-center">
                    <?php foreach ($trimestres as $q => $montant): ?>
                        <div class="col-6 mb-1">
                            <div class="fw-bold <?= $montant > 0 ? 'text-primary' : 'text-muted' ?>" style="font-size: 0.85rem;">
                                <?= formatMontant($montant) ?>
                            </div>
                            <small class="text-muted" style="font-size: 0.7rem;">T<?= $q ?></small>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Onglets -->
<ul class="nav nav-tabs nav-fill mb-0" role="tablist">
    <li class="nav-item">
        <a class="nav-link <?= $onglet === 'recettes' ? 'active' : '' ?>" href="?annee=<?= $annee ?>&onglet=recettes">
            <i class="bi bi-book text-success"></i> Recettes <span class="badge bg-success"><?= count($recettes) ?></span>
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $onglet === 'achats' ? 'active' : '' ?>" href="?annee=<?= $annee ?>&onglet=achats">
            <i class="bi bi-cart text-danger"></i> Achats <span class="badge bg-danger"><?= count($achats) ?></span>
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $onglet === 'journal' ? 'active' : '' ?>" href="?annee=<?= $annee ?>&onglet=journal">
            <i class="bi bi-journal-text text-primary"></i> Journal
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $onglet === 'grandlivre' ? 'active' : '' ?>" href="?annee=<?= $annee ?>&onglet=grandlivre">
            <i class="bi bi-book-half text-info"></i> Grand livre
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $onglet === 'balance' ? 'active' : '' ?>" href="?annee=<?= $annee ?>&onglet=balance">
            <i class="bi bi-clipboard-data text-warning"></i> Balance
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $onglet === 'tresorerie' ? 'active' : '' ?>" href="?annee=<?= $annee ?>&onglet=tresorerie">
            <i class="bi bi-cash-stack text-secondary"></i> Trésorerie
        </a>
    </li>
</ul>

<?php if ($onglet === 'recettes'): ?>
<!-- ============================== -->
<!-- LIVRE DES RECETTES             -->
<!-- ============================== -->
<div class="card border-top-0 rounded-top-0">
    <div class="card-header d-flex justify-content-between align-items-center">
        <div>
            <strong><i class="bi bi-book"></i> Livre des recettes <?= $annee ?></strong>
            <small class="text-muted ms-2">(Art. L123-28 du Code de Commerce)</small>
        </div>
        <div class="d-flex gap-2">
            <a href="transactions.php?action=ajouter&type=recette" class="btn btn-sm btn-primary">
                <i class="bi bi-plus-lg"></i> Nouvelle recette
            </a>
            <a href="livre_pdf.php?onglet=recettes&annee=<?= $annee ?>" target="_blank" class="btn btn-sm btn-outline-secondary" title="Export PDF"><i class="bi bi-file-earmark-pdf"></i></a>
        </div>
    </div>
    <div class="card-body p-0">
        <?php if (empty($recettesChronologiques)): ?>
            <div class="text-center empty-state">
                <div class="empty-state-icon">
                    <i class="bi bi-journal"></i>
                </div>
                <h3 class="empty-state-title">Aucune recette pour <?= $annee ?></h3>
                <p class="empty-state-text">
                    Enregistre une première recette pour alimenter le livre des recettes et suivre ton chiffre d’affaires annuel.
                </p>
                <div class="empty-state-actions">
                    <a href="transactions.php?action=ajouter&type=recette" class="btn btn-primary empty-state-btn"><i class="bi bi-plus-lg"></i> Enregistrer une recette</a>
                </div>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm table-hover table-striped mb-0">
                    <thead class="table-success">
                        <tr>
                            <th class="text-center" style="width: 50px;">N°</th>
                            <th style="width: 105px;">Date</th>
                            <th>Réf.</th>
                            <th>Client</th>
                            <th>Nature de la prestation</th>
                            <th class="text-end" style="width: 115px;">Montant</th>
                            <th style="width: 100px;">Mode</th>
                            <th class="text-end" style="width: 115px;">Cumul</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $cumul = 0; foreach ($recettesChronologiques as $i => $t): $cumul += $t['montant']; ?>
                            <tr>
                                <td class="text-center text-muted"><?= $i + 1 ?></td>
                                <td><?= formatDate($t['date_transaction']) ?></td>
                                <td><small><?= e($t['reference'] ?? '—') ?></small></td>
                                <td><?= e($t['client_fournisseur'] ?? '—') ?></td>
                                <td>
                                    <?= e($t['description']) ?>
                                    <?php if ($t['categorie_nom']): ?>
                                        <br><span class="badge" style="background-color: <?= e($t['categorie_couleur']) ?>; font-size: 0.65rem;"><?= e($t['categorie_nom']) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end fw-bold text-success"><?= formatMontant($t['montant']) ?></td>
                                <td><small><?= e(ucfirst($t['mode_paiement'])) ?></small></td>
                                <td class="text-end text-primary"><?= formatMontant($cumul) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot class="table-dark">
                        <tr>
                            <td colspan="5" class="text-end"><strong>TOTAL <?= $annee ?></strong></td>
                            <td class="text-end"><strong><?= formatMontant($totalRecettes) ?></strong></td>
                            <td></td>
                            <td class="text-end"><strong><?= formatMontant($cumul) ?></strong></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
<div class="alert alert-light border mt-3">
    <i class="bi bi-info-circle text-primary"></i>
    <strong>Rappel légal :</strong> Le livre des recettes doit mentionner chronologiquement le montant et l'origine des recettes encaissées.
    <br><small class="text-muted">Art. L123-28 du Code de Commerce<?= !$conf['tva_applicable'] ? ' — « TVA non applicable, art. 293 B du CGI »' : '' ?></small>
</div>

<?php elseif ($onglet === 'achats'): ?>
<!-- ============================== -->
<!-- REGISTRE DES ACHATS            -->
<!-- ============================== -->
<div class="card border-top-0 rounded-top-0">
    <div class="card-header d-flex justify-content-between align-items-center">
        <div>
            <strong><i class="bi bi-cart"></i> Registre des achats <?= $annee ?></strong>
        </div>
        <div class="d-flex gap-2">
            <a href="transactions.php?action=ajouter&type=depense" class="btn btn-sm btn-primary"><i class="bi bi-plus-lg"></i> Nouvel achat</a>
            <a href="livre_pdf.php?onglet=achats&annee=<?= $annee ?>" target="_blank" class="btn btn-sm btn-outline-secondary" title="Export PDF"><i class="bi bi-file-earmark-pdf"></i></a>
        </div>
    </div>
    <div class="card-body p-0">
        <?php if (empty($achatsChronologiques)): ?>
            <div class="text-center py-5 text-muted">
                <i class="bi bi-cart-x" style="font-size: 3rem;"></i>
                <p class="mt-2">Aucun achat pour <?= $annee ?>.</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm table-hover table-striped mb-0">
                    <thead class="table-danger">
                        <tr>
                            <th class="text-center" style="width: 50px;">N°</th>
                            <th style="width: 105px;">Date</th>
                            <th>Réf.</th>
                            <th>Fournisseur</th>
                            <th>Nature</th>
                            <th>Catégorie</th>
                            <th class="text-end" style="width: 115px;">Montant</th>
                            <th style="width: 100px;">Mode</th>
                            <th class="text-end" style="width: 115px;">Cumul</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $cumul = 0; foreach ($achatsChronologiques as $i => $t): $cumul += $t['montant']; ?>
                            <tr>
                                <td class="text-center text-muted"><?= $i + 1 ?></td>
                                <td><?= formatDate($t['date_transaction']) ?></td>
                                <td><small><?= e($t['reference'] ?? '—') ?></small></td>
                                <td><?= e($t['client_fournisseur'] ?? '—') ?></td>
                                <td><?= e($t['description']) ?></td>
                                <td>
                                    <?php if ($t['categorie_nom']): ?>
                                        <span class="badge" style="background-color: <?= e($t['categorie_couleur']) ?>; font-size: 0.65rem;"><?= e($t['categorie_nom']) ?></span>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end fw-bold text-danger"><?= formatMontant($t['montant']) ?></td>
                                <td><small><?= e(ucfirst($t['mode_paiement'])) ?></small></td>
                                <td class="text-end text-primary"><?= formatMontant($cumul) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot class="table-dark">
                        <tr>
                            <td colspan="6" class="text-end"><strong>TOTAL <?= $annee ?></strong></td>
                            <td class="text-end"><strong><?= formatMontant($totalAchats) ?></strong></td>
                            <td></td>
                            <td class="text-end"><strong><?= formatMontant($cumul) ?></strong></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php
$depensesParCat = getStatsParCategorie($annee, 'depense');
if (!empty($depensesParCat)):
?>
<div class="card mt-3 border-0">
    <div class="card-header"><i class="bi bi-pie-chart"></i> Répartition par catégorie</div>
    <div class="card-body p-0">
        <table class="table table-sm mb-0">
            <thead class="table-light">
                <tr><th>Catégorie</th><th class="text-end">Montant</th><th style="width: 40%;">Part</th></tr>
            </thead>
            <tbody>
                <?php foreach ($depensesParCat as $cat):
                    $pct = $totalAchats > 0 ? round((float)$cat['total'] / $totalAchats * 100, 1) : 0;
                ?>
                    <tr>
                        <td><span class="badge" style="background-color: <?= e($cat['couleur'] ?? '#6c757d') ?>">&nbsp;</span> <?= e($cat['nom'] ?? 'Non classé') ?></td>
                        <td class="text-end fw-bold"><?= formatMontant((float)$cat['total']) ?></td>
                        <td>
                            <div class="d-flex align-items-center gap-2">
                                <div class="progress flex-grow-1" style="height: 8px;">
                                    <div class="progress-bar" style="width: <?= $pct ?>%; background-color: <?= e($cat['couleur'] ?? '#6c757d') ?>"></div>
                                </div>
                                <small class="text-muted" style="width: 40px;"><?= $pct ?>%</small>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php elseif ($onglet === 'journal'): ?>
<!-- ============================== -->
<!-- JOURNAL GÉNÉRAL                -->
<!-- ============================== -->
<div class="card border-top-0 rounded-top-0">
    <div class="card-header d-flex justify-content-between align-items-center">
        <div>
            <strong><i class="bi bi-journal-text"></i> Journal général <?= $annee ?></strong>
            <small class="text-muted ms-2">(Toutes écritures — débit / crédit)</small>
        </div>
        <div class="d-flex gap-2">
            <a href="transactions.php?action=ajouter" class="btn btn-sm btn-primary"><i class="bi bi-plus-lg"></i> Nouvelle écriture</a>
            <a href="livre_pdf.php?onglet=journal&annee=<?= $annee ?>" target="_blank" class="btn btn-sm btn-outline-secondary" title="Export PDF"><i class="bi bi-file-earmark-pdf"></i></a>
        </div>
    </div>
    <div class="card-body p-0">
        <?php if (empty($journalChronologique)): ?>
            <div class="text-center py-5 text-muted">
                <i class="bi bi-journal" style="font-size: 3rem;"></i>
                <p class="mt-2">Aucune écriture pour <?= $annee ?>.</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm table-hover table-striped mb-0">
                    <thead class="table-primary">
                        <tr>
                            <th class="text-center" style="width: 50px;">N°</th>
                            <th style="width: 105px;">Date</th>
                            <th style="width: 80px;">Journal</th>
                            <th>Libellé</th>
                            <th>Compte (Catégorie)</th>
                            <th>Tiers</th>
                            <th>Réf.</th>
                            <th class="text-end" style="width: 115px;">Débit</th>
                            <th class="text-end" style="width: 115px;">Crédit</th>
                            <th class="text-end" style="width: 115px;">Solde cumulé</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $cumulDebit = 0;
                        $cumulCredit = 0;
                        foreach ($journalChronologique as $i => $t):
                            $isDebit = $t['type'] === 'depense';
                            if ($isDebit) $cumulDebit += $t['montant'];
                            else $cumulCredit += $t['montant'];
                            $soldeCumul = $cumulCredit - $cumulDebit;
                        ?>
                            <tr>
                                <td class="text-center text-muted"><?= $i + 1 ?></td>
                                <td><?= formatDate($t['date_transaction']) ?></td>
                                <td>
                                    <span class="badge bg-<?= $isDebit ? 'danger' : 'success' ?>-subtle text-<?= $isDebit ? 'danger' : 'success' ?>" style="font-size: 0.7rem;">
                                        <?= $isDebit ? 'ACH' : 'VTE' ?>
                                    </span>
                                </td>
                                <td><?= e($t['description']) ?></td>
                                <td>
                                    <?php if ($t['categorie_nom']): ?>
                                        <span class="badge" style="background-color: <?= e($t['categorie_couleur']) ?>; font-size: 0.65rem;"><?= e($t['categorie_nom']) ?></span>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td><small><?= e($t['client_fournisseur'] ?? '—') ?></small></td>
                                <td><small><?= e($t['reference'] ?? '') ?></small></td>
                                <td class="text-end <?= $isDebit ? 'fw-bold text-danger' : '' ?>">
                                    <?= $isDebit ? formatMontant($t['montant']) : '' ?>
                                </td>
                                <td class="text-end <?= !$isDebit ? 'fw-bold text-success' : '' ?>">
                                    <?= !$isDebit ? formatMontant($t['montant']) : '' ?>
                                </td>
                                <td class="text-end <?= $soldeCumul >= 0 ? 'text-info' : 'text-danger' ?>">
                                    <?= formatMontant($soldeCumul) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot class="table-dark">
                        <tr>
                            <td colspan="7" class="text-end"><strong>TOTAUX <?= $annee ?></strong></td>
                            <td class="text-end"><strong><?= formatMontant($cumulDebit) ?></strong></td>
                            <td class="text-end"><strong><?= formatMontant($cumulCredit) ?></strong></td>
                            <td class="text-end"><strong><?= formatMontant($soldeCumul) ?></strong></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php elseif ($onglet === 'grandlivre'): ?>
<!-- ============================== -->
<!-- GRAND LIVRE                    -->
<!-- ============================== -->
<div class="card border-top-0 rounded-top-0">
    <div class="card-header d-flex justify-content-between align-items-center">
        <div>
            <strong><i class="bi bi-book-half"></i> Grand livre <?= $annee ?></strong>
            <small class="text-muted ms-2">(Écritures groupées par compte / catégorie)</small>
        </div>
        <a href="livre_pdf.php?onglet=grandlivre&annee=<?= $annee ?>" target="_blank" class="btn btn-sm btn-outline-secondary" title="Export PDF"><i class="bi bi-file-earmark-pdf"></i></a>
    </div>
    <div class="card-body p-0">
        <?php if (empty($grandLivre)): ?>
            <div class="text-center py-5 text-muted">
                <i class="bi bi-book" style="font-size: 3rem;"></i>
                <p class="mt-2">Aucune écriture pour <?= $annee ?>.</p>
            </div>
        <?php else: ?>
            <?php foreach ($grandLivre as $catNom => $catData): ?>
                <div class="border-bottom">
                    <div class="p-2 bg-light d-flex justify-content-between align-items-center">
                        <div>
                            <span class="badge" style="background-color: <?= e($catData['couleur']) ?>">&nbsp;</span>
                            <strong><?= e($catNom) ?></strong>
                            <small class="text-muted ms-2">(<?= count($catData['ecritures']) ?> écritures)</small>
                        </div>
                        <div>
                            <?php if ($catData['total_debit'] > 0): ?>
                                <span class="text-danger me-3">Débit: <?= formatMontant($catData['total_debit']) ?></span>
                            <?php endif; ?>
                            <?php if ($catData['total_credit'] > 0): ?>
                                <span class="text-success me-3">Crédit: <?= formatMontant($catData['total_credit']) ?></span>
                            <?php endif; ?>
                            <?php $solde = $catData['total_credit'] - $catData['total_debit']; ?>
                            <strong class="<?= $solde >= 0 ? 'text-info' : 'text-danger' ?>">Solde: <?= formatMontant($solde) ?></strong>
                        </div>
                    </div>
                    <table class="table table-sm mb-0">
                        <tbody>
                            <?php foreach ($catData['ecritures'] as $t): ?>
                                <tr>
                                    <td style="width: 105px;"><?= formatDate($t['date_transaction']) ?></td>
                                    <td><?= e($t['description']) ?></td>
                                    <td style="width: 150px;"><small><?= e($t['client_fournisseur'] ?? '') ?></small></td>
                                    <td style="width: 80px;"><small><?= e($t['reference'] ?? '') ?></small></td>
                                    <td class="text-end" style="width: 110px;">
                                        <?php if ($t['type'] === 'depense'): ?>
                                            <span class="text-danger"><?= formatMontant($t['montant']) ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end" style="width: 110px;">
                                        <?php if ($t['type'] === 'recette'): ?>
                                            <span class="text-success"><?= formatMontant($t['montant']) ?></span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<?php elseif ($onglet === 'balance'): ?>
<!-- ============================== -->
<!-- BALANCE GÉNÉRALE               -->
<!-- ============================== -->
<div class="card border-top-0 rounded-top-0">
    <div class="card-header d-flex justify-content-between align-items-center">
        <div>
            <strong><i class="bi bi-clipboard-data"></i> Balance générale <?= $annee ?></strong>
            <small class="text-muted ms-2">(Synthèse débit / crédit par compte)</small>
        </div>
        <a href="livre_pdf.php?onglet=balance&annee=<?= $annee ?>" target="_blank" class="btn btn-sm btn-outline-secondary" title="Export PDF"><i class="bi bi-file-earmark-pdf"></i></a>
    </div>
    <div class="card-body p-0">
        <?php if (empty($balance)): ?>
            <div class="text-center py-5 text-muted">
                <i class="bi bi-clipboard" style="font-size: 3rem;"></i>
                <p class="mt-2">Aucune donnée pour <?= $annee ?>.</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm table-hover table-striped mb-0">
                    <thead class="table-warning">
                        <tr>
                            <th>Compte (Catégorie)</th>
                            <th class="text-center">Écritures</th>
                            <th class="text-end">Total Débit</th>
                            <th class="text-end">Total Crédit</th>
                            <th class="text-end">Solde Débiteur</th>
                            <th class="text-end">Solde Créditeur</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $sommeDebit = 0;
                        $sommeCredit = 0;
                        $sommeSoldeD = 0;
                        $sommeSoldeC = 0;
                        foreach ($balance as $b):
                            $sommeDebit += $b['total_debit'];
                            $sommeCredit += $b['total_credit'];
                            $soldeDebiteur = $b['solde'] < 0 ? abs($b['solde']) : 0;
                            $soldeCrediteur = $b['solde'] > 0 ? $b['solde'] : 0;
                            $sommeSoldeD += $soldeDebiteur;
                            $sommeSoldeC += $soldeCrediteur;
                        ?>
                            <tr>
                                <td>
                                    <span class="badge" style="background-color: <?= e($b['couleur']) ?>">&nbsp;</span>
                                    <?= e($b['compte']) ?>
                                </td>
                                <td class="text-center"><?= $b['nb_ecritures'] ?></td>
                                <td class="text-end text-danger"><?= $b['total_debit'] > 0 ? formatMontant($b['total_debit']) : '—' ?></td>
                                <td class="text-end text-success"><?= $b['total_credit'] > 0 ? formatMontant($b['total_credit']) : '—' ?></td>
                                <td class="text-end"><?= $soldeDebiteur > 0 ? '<span class="text-danger fw-bold">' . formatMontant($soldeDebiteur) . '</span>' : '—' ?></td>
                                <td class="text-end"><?= $soldeCrediteur > 0 ? '<span class="text-success fw-bold">' . formatMontant($soldeCrediteur) . '</span>' : '—' ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot class="table-dark">
                        <tr>
                            <td><strong>TOTAUX</strong></td>
                            <td class="text-center"><strong><?= count($toutesTransactions) ?></strong></td>
                            <td class="text-end"><strong><?= formatMontant($sommeDebit) ?></strong></td>
                            <td class="text-end"><strong><?= formatMontant($sommeCredit) ?></strong></td>
                            <td class="text-end"><strong><?= formatMontant($sommeSoldeD) ?></strong></td>
                            <td class="text-end"><strong><?= formatMontant($sommeSoldeC) ?></strong></td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <!-- Vérification d'équilibre -->
            <div class="alert <?= abs($sommeSoldeD - $sommeSoldeC) < 0.01 ? 'alert-success' : 'alert-warning' ?> m-3">
                <i class="bi bi-<?= abs($sommeSoldeD - $sommeSoldeC) < 0.01 ? 'check-circle' : 'exclamation-triangle' ?>"></i>
                <?php if (abs($sommeSoldeD - $sommeSoldeC) < 0.01): ?>
                    <strong>Balance équilibrée.</strong> Les totaux débiteurs et créditeurs sont égaux.
                <?php else: ?>
                    <strong>Attention :</strong> Écart de <?= formatMontant(abs($sommeSoldeD - $sommeSoldeC)) ?> entre les soldes.
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php elseif ($onglet === 'tresorerie'): ?>
<!-- ============================== -->
<!-- VUE TRÉSORERIE                 -->
<!-- ============================== -->
<?php
$statsMensuelles = getStatsMensuelles($annee);
$tauxCotisations = (float) getParam('taux_cotisations_sociales', '21.10');
$tauxCfp = (float) getParam('taux_cfp', '0.20');
$vlActif = (bool) getParam('versement_liberatoire_actif', '0');
$tauxVL = (float) getParam('taux_versement_liberatoire', '2.20');
$tauxTva = (float) getParam('taux_tva', '20');
?>
<div class="card border-top-0 rounded-top-0">
    <div class="card-header d-flex justify-content-between align-items-center">
        <div>
            <strong><i class="bi bi-cash-stack"></i> Suivi de trésorerie <?= $annee ?></strong>
            <small class="text-muted ms-2">(<?= e(getRegimeLabel()) ?>)</small>
        </div>
        <a href="livre_pdf.php?onglet=tresorerie&annee=<?= $annee ?>" target="_blank" class="btn btn-sm btn-outline-secondary" title="Export PDF"><i class="bi bi-file-earmark-pdf"></i></a>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead class="table-info">
                    <tr>
                        <th>Mois</th>
                        <th class="text-end">Recettes</th>
                        <th class="text-end">Dépenses</th>
                        <th class="text-end">Solde brut</th>
                        <?php if ($conf['is_micro']): ?>
                        <th class="text-end">URSSAF</th>
                        <th class="text-end">CFP<?= $vlActif ? '+VL' : '' ?></th>
                        <?php elseif ($conf['tva_applicable']): ?>
                        <th class="text-end">TVA coll.</th>
                        <th class="text-end">TVA déd.</th>
                        <th class="text-end">TVA nette</th>
                        <?php endif; ?>
                        <th class="text-end fw-bold">Tréso. nette</th>
                        <th class="text-end">Cumul</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $cumulNet = 0;
                    $totalChargesSociales = 0;
                    $totalAutresCharges = 0;
                    $totalTvaCollectee = 0;
                    $totalTvaDeductible = 0;
                    foreach ($statsMensuelles as $mois => $d):
                        if ($conf['is_micro']) {
                            $urssaf = $d['recettes'] * ($tauxCotisations / 100);
                            $cfpVl = $d['recettes'] * ($tauxCfp / 100);
                            if ($vlActif) $cfpVl += $d['recettes'] * ($tauxVL / 100);
                            $tresoNette = $d['recettes'] - $d['depenses'] - $urssaf - $cfpVl;
                            $totalChargesSociales += $urssaf;
                            $totalAutresCharges += $cfpVl;
                        } else {
                            $tvaC = $d['recettes'] * ($tauxTva / (100 + $tauxTva));
                            $tvaD = $d['depenses'] * ($tauxTva / (100 + $tauxTva));
                            $tvaNette = $tvaC - $tvaD;
                            $totalTvaCollectee += $tvaC;
                            $totalTvaDeductible += $tvaD;
                            $tresoNette = $d['recettes'] - $d['depenses'];
                            if ($conf['tva_applicable']) $tresoNette -= $tvaNette;
                        }
                        $cumulNet += $tresoNette;
                    ?>
                        <tr class="<?= $d['recettes'] == 0 && $d['depenses'] == 0 ? 'text-muted' : '' ?>">
                            <td><?= formatDateMois($mois . '-01') ?></td>
                            <td class="text-end text-success"><?= $d['recettes'] > 0 ? formatMontant($d['recettes']) : '—' ?></td>
                            <td class="text-end text-danger"><?= $d['depenses'] > 0 ? formatMontant($d['depenses']) : '—' ?></td>
                            <td class="text-end <?= $d['solde'] >= 0 ? 'text-success' : 'text-danger' ?>"><?= formatMontant($d['solde']) ?></td>
                            <?php if ($conf['is_micro']): ?>
                            <td class="text-end text-warning"><?= $urssaf > 0 ? formatMontant($urssaf) : '—' ?></td>
                            <td class="text-end text-warning"><?= $cfpVl > 0 ? formatMontant($cfpVl) : '—' ?></td>
                            <?php elseif ($conf['tva_applicable']): ?>
                            <td class="text-end text-info"><?= $tvaC > 0 ? formatMontant($tvaC) : '—' ?></td>
                            <td class="text-end text-success"><?= $tvaD > 0 ? formatMontant($tvaD) : '—' ?></td>
                            <td class="text-end text-warning"><?= formatMontant($tvaNette) ?></td>
                            <?php endif; ?>
                            <td class="text-end fw-bold <?= $tresoNette >= 0 ? 'text-info' : 'text-danger' ?>"><?= formatMontant($tresoNette) ?></td>
                            <td class="text-end <?= $cumulNet >= 0 ? 'text-primary' : 'text-danger' ?>"><?= formatMontant($cumulNet) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot class="table-dark">
                    <tr>
                        <th>TOTAL <?= $annee ?></th>
                        <th class="text-end"><?= formatMontant($totalRecettes) ?></th>
                        <th class="text-end"><?= formatMontant($totalAchats) ?></th>
                        <th class="text-end"><?= formatMontant($totalRecettes - $totalAchats) ?></th>
                        <?php if ($conf['is_micro']): ?>
                        <th class="text-end"><?= formatMontant($totalChargesSociales) ?></th>
                        <th class="text-end"><?= formatMontant($totalAutresCharges) ?></th>
                        <?php elseif ($conf['tva_applicable']): ?>
                        <th class="text-end"><?= formatMontant($totalTvaCollectee) ?></th>
                        <th class="text-end"><?= formatMontant($totalTvaDeductible) ?></th>
                        <th class="text-end"><?= formatMontant($totalTvaCollectee - $totalTvaDeductible) ?></th>
                        <?php endif; ?>
                        <th class="text-end"><?= formatMontant($cumulNet) ?></th>
                        <th class="text-end"><?= formatMontant($cumulNet) ?></th>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>

<!-- Indicateurs -->
<div class="row g-3 mt-2">
    <div class="col-md-4">
        <div class="card border-0">
            <div class="card-body text-center">
                <small class="text-muted">Marge nette</small>
                <?php $margeNette = $totalRecettes > 0 ? round($cumulNet / $totalRecettes * 100, 1) : 0; ?>
                <h3 class="<?= $margeNette >= 0 ? 'text-success' : 'text-danger' ?>"><?= $margeNette ?>%</h3>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card border-0">
            <div class="card-body text-center">
                <small class="text-muted"><?= $conf['is_micro'] ? 'Pression fiscale' : ($conf['tva_applicable'] ? 'Impact TVA' : 'Ratio dépenses') ?></small>
                <?php
                if ($conf['is_micro']) {
                    $pression = $totalRecettes > 0 ? round(($totalChargesSociales + $totalAutresCharges) / $totalRecettes * 100, 1) : 0;
                } elseif ($conf['tva_applicable']) {
                    $pression = $totalRecettes > 0 ? round(($totalTvaCollectee - $totalTvaDeductible) / $totalRecettes * 100, 1) : 0;
                } else {
                    $pression = $totalRecettes > 0 ? round($totalAchats / $totalRecettes * 100, 1) : 0;
                }
                ?>
                <h3 class="text-warning"><?= $pression ?>%</h3>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card border-0">
            <div class="card-body text-center">
                <small class="text-muted">Revenu mensuel moyen</small>
                <?php
                $moisEcoule = ($annee == date('Y')) ? max((int)date('n'), 1) : 12;
                $revenuMoyenMensuel = $moisEcoule > 0 ? $cumulNet / $moisEcoule : 0;
                ?>
                <h3 class="<?= $revenuMoyenMensuel >= 0 ? 'text-info' : 'text-danger' ?>"><?= formatMontant($revenuMoyenMensuel) ?></h3>
                <small class="text-muted">Sur <?= $moisEcoule ?> mois</small>
            </div>
        </div>
    </div>
</div>

<?php endif; ?>

<?php include 'footer.php'; ?>

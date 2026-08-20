<?php
/**
 * Rapports avancés - Inspiré Paheko
 * - Bilan annuel synthétique
 * - Compte de résultat
 * - Comparaison N / N-1
 * - Détail mensuel
 * - Répartition par catégorie
 * - Conseils & alertes
 * - Export PDF
 */
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/functions_commercial.php';
requireLogin();
requireRole('admin', 'comptable');
$titre = 'Rapports';

$annee = isset($_GET['annee']) ? (int) $_GET['annee'] : (int) date('Y');
$onglet = $_GET['onglet'] ?? 'bilan';
$annees = getAnneesDisponibles();
$ongletLabels = [
    'bilan' => 'Bilan annuel',
    'resultat' => 'Compte de résultat',
    'comparaison' => 'Comparaison N-1',
    'mensuel' => 'Détail mensuel',
    'categories' => 'Répartition par catégories',
    'conseils' => 'Conseils & alertes',
];
$ongletLabel = $ongletLabels[$onglet] ?? 'Bilan annuel';

$stats = getStatsAnnee($annee);
$conf = getRegimeConfig();
$statsMensuelles = getStatsMensuelles($annee);
$recettesParCat = getStatsParCategorie($annee, 'recette');
$depensesParCat = getStatsParCategorie($annee, 'depense');
$statsCommerciales = getStatsCommerciales();

// N-1
$statsN1 = getStatsAnnee($annee - 1);
$statsMensuellesN1 = getStatsMensuelles($annee - 1);

// Cumul progressif
$cumul = 0;
$cumulData = [];
foreach ($statsMensuelles as $mois => $d) {
    $cumul += $d['solde'];
    $cumulData[$mois] = $cumul;
}

// Export PDF rapport
if (isset($_GET['export_pdf'])) {
    require_once __DIR__ . '/lib/fpdf.php';

    function u8d($s) { return mb_convert_encoding($s, 'Windows-1252', 'UTF-8'); }

    class RapportPDF extends FPDF {
        function Header() {
            $anneeRapport = (string) ($_GET['annee'] ?? date('Y'));
            $entreprise = getParam('nom_entreprise', 'Mon Activité');
            $regime = getRegimeLabel();

            $this->SetFillColor(30, 58, 95);
            $this->Rect(0, 0, 210, 34, 'F');
            $this->SetY(5);
            $this->SetTextColor(255, 255, 255);

            $this->SetFont('Arial', 'B', 15);
            $this->Cell(0, 8, u8d('Rapport ' . $anneeRapport), 0, 1, 'C');

            $this->SetFont('Arial', '', 9);
            $this->MultiCell(0, 4, u8d($regime), 0, 'C');

            $this->SetFont('Arial', 'I', 8);
            $this->Cell(0, 5, u8d($entreprise), 0, 1, 'C');

            $this->Ln(4);
            $this->SetTextColor(0, 0, 0);
        }
        function Footer() {
            $this->SetY(-15);
            $this->SetFont('Arial', 'I', 8);
            $this->SetTextColor(128, 128, 128);
            $this->Cell(0, 10, 'Page ' . $this->PageNo() . '/{nb} — ' . u8d('Généré le ') . date('d/m/Y'), 0, 0, 'C');
        }
        function SectionTitle($title) {
            $this->Ln(3);
            $this->SetFillColor(235, 244, 255);
            $this->SetFont('Arial', 'B', 11);
            $this->Cell(0, 8, u8d($title), 0, 1, 'L', true);
            $this->Ln(2);
        }
        function TableRow($label, $value, $bold = false, $color = null) {
            $this->SetFont('Arial', $bold ? 'B' : '', 9);
            $this->Cell(130, 6, u8d($label), 0, 0, 'L');
            if ($color === 'green') $this->SetTextColor(0, 128, 0);
            elseif ($color === 'red') $this->SetTextColor(200, 0, 0);
            elseif ($color === 'blue') $this->SetTextColor(30, 58, 95);
            $this->Cell(50, 6, u8d($value), 0, 1, 'R');
            $this->SetTextColor(0, 0, 0);
        }
    }

    $pdf = new RapportPDF();
    $pdf->AliasNbPages();
    $pdf->AddPage();

    // Bilan
    $pdf->SectionTitle('Bilan annuel ' . $annee);
    $pdf->TableRow('Chiffre d\'affaires (recettes)', formatMontant($stats['total_recettes']), true, 'green');
    if ($conf['tva_applicable'] && !$conf['is_micro']) {
        $pdf->TableRow('   dont TVA collectée', formatMontant($stats['tva_collectee']));
        $pdf->TableRow('   CA HT', formatMontant($stats['ca_ht']), true);
    }
    $pdf->TableRow('Dépenses professionnelles', formatMontant($stats['total_depenses']), true, 'red');
    if ($conf['is_micro']) {
        $pdf->TableRow('Cotisations URSSAF', formatMontant($stats['cotisations_sociales']));
        $pdf->TableRow('CFP', formatMontant($stats['cfp']));
        if ($stats['versement_liberatoire'] > 0) {
            $pdf->TableRow('Versement libératoire IR', formatMontant($stats['versement_liberatoire']));
        }
        $pdf->TableRow('Total charges obligatoires', formatMontant($stats['charges_obligatoires']), true, 'red');
    }
    $pdf->TableRow('Bénéfice net', formatMontant($stats['benefice_net']), true, $stats['benefice_net'] >= 0 ? 'green' : 'red');
    $pdf->TableRow('Revenu imposable', formatMontant($stats['revenu_imposable']), false, 'blue');

    // Comparaison N-1
    $pdf->SectionTitle('Comparaison N / N-1');
    $pdf->SetFont('Arial', 'B', 8);
    $pdf->Cell(70, 6, '', 0, 0);
    $pdf->Cell(40, 6, u8d((string)$annee), 0, 0, 'R');
    $pdf->Cell(40, 6, u8d((string)($annee - 1)), 0, 0, 'R');
    $pdf->Cell(30, 6, u8d('Évolution'), 0, 1, 'R');
    $pdf->SetFont('Arial', '', 8);

    $comparaisons = [
        ['Recettes', $stats['total_recettes'], $statsN1['total_recettes']],
        ['Dépenses', $stats['total_depenses'], $statsN1['total_depenses']],
        ['Résultat net', $stats['benefice_net'], $statsN1['benefice_net']],
    ];
    foreach ($comparaisons as $c) {
        $evol = $c[2] > 0 ? round(($c[1] - $c[2]) / $c[2] * 100, 1) : 0;
        $pdf->Cell(70, 5, u8d($c[0]), 0, 0);
        $pdf->Cell(40, 5, u8d(formatMontant($c[1])), 0, 0, 'R');
        $pdf->Cell(40, 5, u8d(formatMontant($c[2])), 0, 0, 'R');
        $pdf->Cell(30, 5, ($evol >= 0 ? '+' : '') . $evol . '%', 0, 1, 'R');
    }

    // Détail mensuel
    $pdf->SectionTitle('Détail mensuel ' . $annee);
    $pdf->SetFont('Arial', 'B', 8);
    $pdf->Cell(40, 6, 'Mois', 1, 0, 'L');
    $pdf->Cell(35, 6, 'Recettes', 1, 0, 'R');
    $pdf->Cell(35, 6, u8d('Dépenses'), 1, 0, 'R');
    $pdf->Cell(35, 6, 'Solde', 1, 0, 'R');
    $pdf->Cell(35, 6, 'Cumul', 1, 1, 'R');
    $pdf->SetFont('Arial', '', 8);
    $cumulPdf = 0;
    foreach ($statsMensuelles as $mois => $d) {
        $cumulPdf += $d['solde'];
        $pdf->Cell(40, 5, u8d(formatDateMois($mois . '-01')), 1, 0, 'L');
        $pdf->Cell(35, 5, u8d(formatMontant($d['recettes'])), 1, 0, 'R');
        $pdf->Cell(35, 5, u8d(formatMontant($d['depenses'])), 1, 0, 'R');
        $pdf->Cell(35, 5, u8d(formatMontant($d['solde'])), 1, 0, 'R');
        $pdf->Cell(35, 5, u8d(formatMontant($cumulPdf)), 1, 1, 'R');
    }

    $pdf->Output('D', 'rapport_annuel_' . $annee . '.pdf');
    exit;
}

include 'header.php';
?>

<div class="hero-banner reports-hero mb-4">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
        <div>
            <div class="small text-uppercase fw-semibold text-primary mb-1">Rapport annuel</div>
            <h2 class="mb-1"><i class="bi bi-bar-chart-line"></i> Rapport <?= $annee ?></h2>
            <p class="text-muted mb-0"><?= e(getRegimeLabel()) ?> — <?= e($ongletLabel) ?></p>
        </div>
        <div class="d-flex gap-2 flex-wrap reports-actions">
            <div class="btn-group">
                <?php foreach ($annees as $a): ?>
                    <a href="?annee=<?= $a ?>&onglet=<?= e($onglet) ?>" class="btn btn-sm <?= $a === $annee ? 'btn-primary' : 'btn-outline-primary' ?>"><?= $a ?></a>
                <?php endforeach; ?>
            </div>
            <a href="?annee=<?= $annee ?>&export_pdf=1" class="btn btn-sm btn-outline-danger">
                <i class="bi bi-file-earmark-pdf"></i> Export PDF
            </a>
            <button onclick="window.print()" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-printer"></i> Imprimer
            </button>
        </div>
    </div>
</div>

<div class="card border-0 mb-4 reports-tabs-card">
    <div class="card-body py-2">
        <ul class="nav nav-pills reports-tabs gap-2" role="tablist">
            <li class="nav-item">
                <a class="nav-link <?= $onglet === 'bilan' ? 'active' : '' ?>" href="?annee=<?= $annee ?>&onglet=bilan">
                    <i class="bi bi-file-earmark-bar-graph"></i> Bilan
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $onglet === 'resultat' ? 'active' : '' ?>" href="?annee=<?= $annee ?>&onglet=resultat">
                    <i class="bi bi-graph-up-arrow"></i> Compte de résultat
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $onglet === 'comparaison' ? 'active' : '' ?>" href="?annee=<?= $annee ?>&onglet=comparaison">
                    <i class="bi bi-arrow-left-right"></i> Comparaison N-1
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $onglet === 'mensuel' ? 'active' : '' ?>" href="?annee=<?= $annee ?>&onglet=mensuel">
                    <i class="bi bi-calendar3"></i> Mensuel
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $onglet === 'categories' ? 'active' : '' ?>" href="?annee=<?= $annee ?>&onglet=categories">
                    <i class="bi bi-pie-chart"></i> Catégories
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $onglet === 'conseils' ? 'active' : '' ?>" href="?annee=<?= $annee ?>&onglet=conseils">
                    <i class="bi bi-lightbulb"></i> Conseils
                </a>
            </li>
        </ul>
    </div>
</div>
<?php if ($onglet === 'bilan'): ?>
<!-- ============= BILAN ANNUEL ============= -->
<div class="card border-0 mb-4 reports-panel">
    <div class="card-header bg-primary text-white">
        <i class="bi bi-file-earmark-bar-graph"></i> Bilan annuel <?= $annee ?> — <?= e(getRegimeLabel()) ?>
    </div>
    <div class="card-body p-0">
        <table class="table table-bordered mb-0">
            <tbody>
                <tr class="table-success">
                    <td><strong><i class="bi bi-arrow-up-circle"></i> Chiffre d'affaires</strong></td>
                    <td class="text-end fs-5"><strong><?= formatMontant($stats['total_recettes']) ?></strong></td>
                </tr>
                <?php if ($conf['tva_applicable'] && !$conf['is_micro']): ?>
                <tr>
                    <td class="ps-4">dont TVA collectée</td>
                    <td class="text-end"><?= formatMontant($stats['tva_collectee']) ?></td>
                </tr>
                <tr>
                    <td class="ps-4"><strong>CA HT</strong></td>
                    <td class="text-end"><strong><?= formatMontant($stats['ca_ht']) ?></strong></td>
                </tr>
                <?php endif; ?>
                <tr class="table-danger">
                    <td><strong><i class="bi bi-arrow-down-circle"></i> Dépenses professionnelles</strong></td>
                    <td class="text-end fs-5"><strong><?= formatMontant($stats['total_depenses']) ?></strong></td>
                </tr>
                <?php if ($conf['tva_applicable'] && !$conf['is_micro']): ?>
                <tr>
                    <td class="ps-4">dont TVA déductible</td>
                    <td class="text-end"><?= formatMontant($stats['tva_deductible']) ?></td>
                </tr>
                <tr>
                    <td class="ps-4"><strong>Dépenses HT</strong></td>
                    <td class="text-end"><strong><?= formatMontant($stats['depenses_ht']) ?></strong></td>
                </tr>
                <tr class="table-light">
                    <td><strong><i class="bi bi-receipt"></i> TVA à reverser</strong></td>
                    <td class="text-end"><strong><?= formatMontant($stats['tva_a_payer']) ?></strong></td>
                </tr>
                <?php endif; ?>
                <tr>
                    <td><i class="bi bi-calculator"></i> Résultat avant charges</td>
                    <td class="text-end"><?= formatMontant($stats['benefice_avant_charges']) ?></td>
                </tr>
                <?php if ($conf['is_micro']): ?>
                <tr class="table-light"><th colspan="2">Charges sociales obligatoires</th></tr>
                <tr>
                    <td class="ps-4">Cotisations URSSAF (<?= getParam('taux_cotisations_sociales') ?>%)</td>
                    <td class="text-end"><?= formatMontant($stats['cotisations_sociales']) ?></td>
                </tr>
                <tr>
                    <td class="ps-4">Contribution formation (<?= getParam('taux_cfp') ?>%)</td>
                    <td class="text-end"><?= formatMontant($stats['cfp']) ?></td>
                </tr>
                <?php if ($stats['versement_liberatoire'] > 0): ?>
                <tr>
                    <td class="ps-4">Versement libératoire IR (<?= getParam('taux_versement_liberatoire') ?>%)</td>
                    <td class="text-end"><?= formatMontant($stats['versement_liberatoire']) ?></td>
                </tr>
                <?php endif; ?>
                <tr class="table-warning">
                    <td><strong>Total charges obligatoires</strong></td>
                    <td class="text-end"><strong><?= formatMontant($stats['charges_obligatoires']) ?></strong></td>
                </tr>
                <?php elseif ($conf['regime_imposition'] === 'is' && $stats['is'] > 0): ?>
                <tr class="table-warning">
                    <td><strong>Impôt sur les Sociétés (IS)</strong></td>
                    <td class="text-end"><strong><?= formatMontant($stats['is']) ?></strong></td>
                </tr>
                <?php endif; ?>
                <tr class="table-info">
                    <td><strong><i class="bi bi-wallet2"></i> <?= $conf['is_micro'] ? 'Bénéfice net estimé' : 'Résultat net' ?></strong></td>
                    <td class="text-end fs-5"><strong><?= formatMontant($stats['benefice_net']) ?></strong></td>
                </tr>
                <tr class="table-light"><th colspan="2">Informations fiscales</th></tr>
                <?php if ($conf['is_micro']): ?>
                <tr>
                    <td>Abattement forfaitaire (<?= getParam('taux_abattement') ?>%)</td>
                    <td class="text-end"><?= formatMontant($stats['abattement']) ?></td>
                </tr>
                <tr>
                    <td><strong>Revenu imposable</strong></td>
                    <td class="text-end"><strong><?= formatMontant($stats['revenu_imposable']) ?></strong></td>
                </tr>
                <?php else: ?>
                <tr>
                    <td><strong><?= $conf['regime_imposition'] === 'is' ? 'Résultat distribuable' : 'Revenu imposable (IR)' ?></strong></td>
                    <td class="text-end"><strong><?= formatMontant($stats['revenu_imposable']) ?></strong></td>
                </tr>
                <?php endif; ?>
                <?php if ($stats['plafond_ca'] > 0): ?>
                <tr>
                    <td>Utilisation du plafond CA</td>
                    <td class="text-end">
                        <div class="progress progress-h20">
                            <div class="progress-bar <?= $stats['pct_plafond'] > 90 ? 'bg-danger' : ($stats['pct_plafond'] > 70 ? 'bg-warning' : 'bg-success') ?>"
                                 style="width: <?= min($stats['pct_plafond'], 100) ?>%"><?= $stats['pct_plafond'] ?>%</div>
                        </div>
                        <small class="text-muted"><?= formatMontant($stats['total_recettes']) ?> / <?= formatMontant($stats['plafond_ca']) ?></small>
                    </td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php elseif ($onglet === 'resultat'): ?>
<!-- ============= COMPTE DE RÉSULTAT ============= -->
<div class="card border-0 mb-4 reports-panel">
    <div class="card-header">
        <i class="bi bi-graph-up-arrow"></i> Compte de résultat <?= $annee ?> — <?= e(getRegimeLabel()) ?>
    </div>
    <div class="card-body p-0">
        <table class="table table-bordered mb-0">
            <!-- Produits -->
            <thead class="table-success">
                <tr><th colspan="2"><i class="bi bi-arrow-up-circle"></i> PRODUITS D'EXPLOITATION</th></tr>
            </thead>
            <tbody>
                <?php
                $totalProduits = 0;
                if (!empty($recettesParCat)):
                    foreach ($recettesParCat as $cat):
                        $totalProduits += (float)$cat['total'];
                ?>
                    <tr>
                        <td class="ps-4">
                            <span class="badge" style="background-color: <?= e($cat['couleur'] ?? '#6c757d') ?>">&nbsp;</span>
                            <?= e($cat['nom'] ?? 'Non classé') ?>
                        </td>
                        <td class="text-end"><?= formatMontant((float)$cat['total']) ?></td>
                    </tr>
                <?php endforeach; endif; ?>
                <tr class="table-light">
                    <td class="fw-bold">Total produits d'exploitation</td>
                    <td class="text-end fw-bold text-success"><?= formatMontant($totalProduits) ?></td>
                </tr>
            </tbody>

            <!-- Charges -->
            <thead class="table-danger">
                <tr><th colspan="2"><i class="bi bi-arrow-down-circle"></i> CHARGES D'EXPLOITATION</th></tr>
            </thead>
            <tbody>
                <?php
                $totalCharges = 0;
                if (!empty($depensesParCat)):
                    foreach ($depensesParCat as $cat):
                        $totalCharges += (float)$cat['total'];
                ?>
                    <tr>
                        <td class="ps-4">
                            <span class="badge" style="background-color: <?= e($cat['couleur'] ?? '#6c757d') ?>">&nbsp;</span>
                            <?= e($cat['nom'] ?? 'Non classé') ?>
                        </td>
                        <td class="text-end"><?= formatMontant((float)$cat['total']) ?></td>
                    </tr>
                <?php endforeach; endif; ?>

                <?php if ($conf['is_micro']): ?>
                <tr class="table-light"><td colspan="2"><em>Charges sociales estimées</em></td></tr>
                <tr>
                    <td class="ps-4">Cotisations URSSAF</td>
                    <td class="text-end"><?= formatMontant($stats['cotisations_sociales']) ?></td>
                </tr>
                <tr>
                    <td class="ps-4">CFP<?= $stats['versement_liberatoire'] > 0 ? ' + Versement libératoire' : '' ?></td>
                    <td class="text-end"><?= formatMontant($stats['cfp'] + $stats['versement_liberatoire']) ?></td>
                </tr>
                <?php $totalCharges += $stats['charges_obligatoires']; ?>
                <?php endif; ?>

                <tr class="table-light">
                    <td class="fw-bold">Total charges d'exploitation</td>
                    <td class="text-end fw-bold text-danger"><?= formatMontant($totalCharges) ?></td>
                </tr>
            </tbody>

            <!-- Résultat -->
            <tfoot>
                <?php $resultatExploitation = $totalProduits - $totalCharges; ?>
                <tr class="table-primary">
                    <td><strong class="fs-5"><i class="bi bi-trophy"></i> RÉSULTAT D'EXPLOITATION</strong></td>
                    <td class="text-end fs-5"><strong class="<?= $resultatExploitation >= 0 ? 'text-success' : 'text-danger' ?>"><?= formatMontant($resultatExploitation) ?></strong></td>
                </tr>
                <?php if (!$conf['is_micro'] && $conf['tva_applicable']): ?>
                <tr>
                    <td>TVA nette à reverser</td>
                    <td class="text-end"><?= formatMontant($stats['tva_a_payer']) ?></td>
                </tr>
                <?php endif; ?>
                <?php if (!$conf['is_micro'] && $conf['regime_imposition'] === 'is' && $stats['is'] > 0): ?>
                <tr>
                    <td>Impôt sur les sociétés</td>
                    <td class="text-end"><?= formatMontant($stats['is']) ?></td>
                </tr>
                <?php endif; ?>
                <tr class="table-info">
                    <td><strong class="fs-5"><i class="bi bi-wallet2"></i> RÉSULTAT NET</strong></td>
                    <td class="text-end fs-5"><strong class="<?= $stats['benefice_net'] >= 0 ? 'text-success' : 'text-danger' ?>"><?= formatMontant($stats['benefice_net']) ?></strong></td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>

<!-- Graphique résultat -->
<div class="card border-0 mb-4">
    <div class="card-body">
        <?php if ($totalProduits > 0 || $totalCharges > 0): ?>
            <canvas id="chartResultat" height="250"></canvas>
        <?php else: ?>
            <p class="text-muted text-center py-4 mb-0">Pas encore de produits ni de charges enregistrés en <?= $annee ?> — le graphique s'affichera dès votre première écriture.</p>
        <?php endif; ?>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const ctx = document.getElementById('chartResultat');
    if (ctx && typeof Chart !== 'undefined') {
        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: ['Produits', 'Charges', 'Résultat net'],
                datasets: [{
                    data: [<?= $totalProduits ?>, <?= $totalCharges ?>, <?= $stats['benefice_net'] ?>],
                    backgroundColor: ['rgba(40,167,69,0.7)', 'rgba(220,53,69,0.7)', '<?= $stats['benefice_net'] >= 0 ? 'rgba(13,202,240,0.7)' : 'rgba(220,53,69,0.5)' ?>'],
                    borderColor: ['#28a745', '#dc3545', '<?= $stats['benefice_net'] >= 0 ? '#0dcaf0' : '#dc3545' ?>'],
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                plugins: { legend: { display: false } },
                scales: { y: { beginAtZero: true } }
            }
        });
    }
});
</script>

<?php elseif ($onglet === 'comparaison'): ?>
<!-- ============= COMPARAISON N / N-1 ============= -->
<div class="card border-0 mb-4 reports-panel">
    <div class="card-header">
        <i class="bi bi-arrow-left-right"></i> Comparaison <?= $annee ?> / <?= $annee - 1 ?>
    </div>
    <div class="card-body p-0">
        <table class="table table-bordered table-hover mb-0">
            <thead class="table-light">
                <tr>
                    <th>Indicateur</th>
                    <th class="text-end"><?= $annee ?></th>
                    <th class="text-end"><?= $annee - 1 ?></th>
                    <th class="text-end">Variation</th>
                    <th class="text-end">Évolution</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $indicateurs = [
                    ['Chiffre d\'affaires', $stats['total_recettes'], $statsN1['total_recettes'], 'success'],
                    ['Dépenses', $stats['total_depenses'], $statsN1['total_depenses'], 'danger'],
                    ['Résultat brut', $stats['benefice_avant_charges'], $statsN1['benefice_avant_charges'], 'primary'],
                ];
                if ($conf['is_micro']) {
                    $indicateurs[] = ['Charges obligatoires', $stats['charges_obligatoires'], $statsN1['charges_obligatoires'], 'warning'];
                }
                $indicateurs[] = ['Résultat net', $stats['benefice_net'], $statsN1['benefice_net'], 'info'];
                $indicateurs[] = ['Revenu imposable', $stats['revenu_imposable'], $statsN1['revenu_imposable'], 'secondary'];

                foreach ($indicateurs as $ind):
                    $variation = $ind[1] - $ind[2];
                    $evolution = $ind[2] != 0 ? round($variation / abs($ind[2]) * 100, 1) : ($ind[1] > 0 ? 100 : 0);
                ?>
                    <tr>
                        <td class="fw-semibold"><span class="badge bg-<?= $ind[3] ?>">&nbsp;</span> <?= $ind[0] ?></td>
                        <td class="text-end fw-bold"><?= formatMontant($ind[1]) ?></td>
                        <td class="text-end text-muted"><?= formatMontant($ind[2]) ?></td>
                        <td class="text-end <?= $variation >= 0 ? 'text-success' : 'text-danger' ?>">
                            <?= $variation >= 0 ? '+' : '' ?><?= formatMontant($variation) ?>
                        </td>
                        <td class="text-end">
                            <?php if ($evolution != 0): ?>
                                <span class="badge bg-<?= $evolution > 0 ? 'success' : 'danger' ?>">
                                    <i class="bi bi-arrow-<?= $evolution > 0 ? 'up' : 'down' ?>"></i> <?= abs($evolution) ?>%
                                </span>
                            <?php else: ?>
                                <span class="badge bg-secondary">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Graphique comparaison mensuelle -->
<?php $aDesDonneesComparaison = array_sum(array_column($statsMensuelles, 'recettes')) > 0 || array_sum(array_column($statsMensuellesN1, 'recettes')) > 0; ?>
<div class="card border-0 mb-4">
    <div class="card-header"><i class="bi bi-graph-up"></i> Évolution mensuelle comparée</div>
    <div class="card-body">
        <?php if ($aDesDonneesComparaison): ?>
            <canvas id="chartComparaison" height="280"></canvas>
        <?php else: ?>
            <p class="text-muted text-center py-4 mb-0">Pas encore de recettes enregistrées sur <?= $annee ?> ou <?= $annee - 1 ?> — le graphique s'affichera dès vos premières recettes.</p>
        <?php endif; ?>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const ctx = document.getElementById('chartComparaison');
    if (ctx && typeof Chart !== 'undefined') {
        const mois = ['Jan', 'Fév', 'Mar', 'Avr', 'Mai', 'Jun', 'Jul', 'Aoû', 'Sep', 'Oct', 'Nov', 'Déc'];
        const dataN = <?= json_encode(array_values(array_map(function($d) { return $d['recettes']; }, $statsMensuelles))) ?>;
        const dataN1 = <?= json_encode(array_values(array_map(function($d) { return $d['recettes']; }, $statsMensuellesN1))) ?>;

        new Chart(ctx, {
            type: 'line',
            data: {
                labels: mois,
                datasets: [
                    { label: '<?= $annee ?>', data: dataN, borderColor: '#3182ce', backgroundColor: 'rgba(49,130,206,0.1)', fill: true, tension: 0.3 },
                    { label: '<?= $annee - 1 ?>', data: dataN1, borderColor: '#a0aec0', backgroundColor: 'rgba(160,174,192,0.1)', fill: true, tension: 0.3, borderDash: [5, 5] }
                ]
            },
            options: { responsive: true, scales: { y: { beginAtZero: true } } }
        });
    }
});
</script>

<?php elseif ($onglet === 'mensuel'): ?>
<!-- ============= DÉTAIL MENSUEL ============= -->
<div class="card border-0 mb-4 reports-panel">
    <div class="card-header"><i class="bi bi-calendar3"></i> Détail mensuel <?= $annee ?></div>
    <div class="card-body p-0">
        <table class="table table-striped table-hover mb-0">
            <thead class="table-light">
                <tr>
                    <th>Mois</th>
                    <th class="text-end">Recettes</th>
                    <th class="text-end">Dépenses</th>
                    <th class="text-end">Solde</th>
                    <th class="text-end">Cumul</th>
                    <th class="text-center col-w-200">Progression</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $maxRecettes = max(array_column($statsMensuelles, 'recettes'));
                foreach ($statsMensuelles as $mois => $d):
                    $pctBar = $maxRecettes > 0 ? round($d['recettes'] / $maxRecettes * 100) : 0;
                ?>
                    <tr>
                        <td><?= formatDateMois($mois . '-01') ?></td>
                        <td class="text-end text-success"><?= formatMontant($d['recettes']) ?></td>
                        <td class="text-end text-danger"><?= formatMontant($d['depenses']) ?></td>
                        <td class="text-end fw-bold <?= $d['solde'] >= 0 ? 'text-success' : 'text-danger' ?>"><?= formatMontant($d['solde']) ?></td>
                        <td class="text-end <?= $cumulData[$mois] >= 0 ? 'text-info' : 'text-danger' ?>"><?= formatMontant($cumulData[$mois]) ?></td>
                        <td>
                            <div class="progress progress-h12">
                                <div class="progress-bar bg-success" style="width: <?= $pctBar ?>%"></div>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot class="table-dark">
                <tr>
                    <th>TOTAL <?= $annee ?></th>
                    <th class="text-end"><?= formatMontant($stats['total_recettes']) ?></th>
                    <th class="text-end"><?= formatMontant($stats['total_depenses']) ?></th>
                    <th class="text-end"><?= formatMontant($stats['total_recettes'] - $stats['total_depenses']) ?></th>
                    <th class="text-end"><?= formatMontant(end($cumulData) ?: 0) ?></th>
                    <th></th>
                </tr>
            </tfoot>
        </table>
    </div>
</div>

<?php elseif ($onglet === 'categories'): ?>
<!-- ============= RÉPARTITION CATÉGORIES ============= -->
<div class="card border-0 mb-4 reports-panel">
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-6">
                <h5 class="text-success"><i class="bi bi-pie-chart"></i> Recettes par catégorie</h5>
                <?php if (empty($recettesParCat)): ?>
                    <p class="text-muted text-center py-3">Aucune recette</p>
                <?php else: ?>
                    <canvas id="chartRecCat" height="200" class="mb-3"></canvas>
                    <table class="table table-sm">
                        <tbody>
                            <?php foreach ($recettesParCat as $cat): ?>
                                <tr>
                                    <td><span class="badge" style="background-color: <?= e($cat['couleur'] ?? '#6c757d') ?>">&nbsp;</span> <?= e($cat['nom'] ?? 'Non classé') ?></td>
                                    <td class="text-end fw-bold"><?= formatMontant((float) $cat['total']) ?></td>
                                    <td class="text-end text-muted"><?= $stats['total_recettes'] > 0 ? round((float)$cat['total'] / $stats['total_recettes'] * 100, 1) : 0 ?>%</td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
            <div class="col-md-6">
                <h5 class="text-danger"><i class="bi bi-pie-chart"></i> Dépenses par catégorie</h5>
                <?php if (empty($depensesParCat)): ?>
                    <p class="text-muted text-center py-3">Aucune dépense</p>
                <?php else: ?>
                    <canvas id="chartDepCat" height="200" class="mb-3"></canvas>
                    <table class="table table-sm">
                        <tbody>
                            <?php foreach ($depensesParCat as $cat): ?>
                                <tr>
                                    <td><span class="badge" style="background-color: <?= e($cat['couleur'] ?? '#6c757d') ?>">&nbsp;</span> <?= e($cat['nom'] ?? 'Non classé') ?></td>
                                    <td class="text-end fw-bold"><?= formatMontant((float) $cat['total']) ?></td>
                                    <td class="text-end text-muted"><?= $stats['total_depenses'] > 0 ? round((float)$cat['total'] / $stats['total_depenses'] * 100, 1) : 0 ?>%</td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const recData = <?= json_encode(array_map(function($r) { return ['label' => $r['nom'] ?? 'Non classé', 'value' => (float)$r['total'], 'color' => $r['couleur'] ?? '#6c757d']; }, $recettesParCat)) ?>;
    const depData = <?= json_encode(array_map(function($r) { return ['label' => $r['nom'] ?? 'Non classé', 'value' => (float)$r['total'], 'color' => $r['couleur'] ?? '#6c757d']; }, $depensesParCat)) ?>;

    function makePie(id, data) {
        const el = document.getElementById(id);
        if (!el || !data.length || typeof Chart === 'undefined') return;
        new Chart(el, {
            type: 'doughnut',
            data: {
                labels: data.map(d => d.label),
                datasets: [{
                    data: data.map(d => d.value),
                    backgroundColor: data.map(d => d.color)
                }]
            },
            options: { responsive: true, plugins: { legend: { position: 'bottom', labels: { font: { size: 11 } } } } }
        });
    }
    makePie('chartRecCat', recData);
    makePie('chartDepCat', depData);
});
</script>

<?php elseif ($onglet === 'conseils'): ?>
<!-- ============= CONSEILS & ALERTES ============= -->
<div class="card border-0 mb-4 reports-panel">
    <div class="card-header"><i class="bi bi-lightbulb"></i> Conseils & Alertes — <?= $annee ?></div>
    <div class="card-body">
        <ul class="list-group list-group-flush">
            <?php if ($conf['is_micro'] && $stats['pct_plafond'] > 90): ?>
                <li class="list-group-item list-group-item-danger">
                    <i class="bi bi-exclamation-triangle"></i> <strong>Alerte plafond !</strong> Vous êtes à <?= $stats['pct_plafond'] ?>% du plafond de CA.
                    Un dépassement 2 ans consécutifs entraîne le passage au régime réel.
                </li>
            <?php elseif ($conf['is_micro'] && $stats['pct_plafond'] > 70): ?>
                <li class="list-group-item list-group-item-warning">
                    <i class="bi bi-exclamation-circle"></i> <?= $stats['pct_plafond'] ?>% du plafond atteint. Surveillez votre CA.
                </li>
            <?php endif; ?>

            <?php if ($conf['is_micro']): ?>
                <?php $tauxAbatt = (float)getParam('taux_abattement', '34'); ?>
                <?php if ($stats['total_depenses'] > $stats['total_recettes'] * ($tauxAbatt / 100)): ?>
                    <li class="list-group-item list-group-item-info">
                        <i class="bi bi-info-circle"></i> Vos dépenses réelles (<?= formatMontant($stats['total_depenses']) ?>) dépassent l'abattement forfaitaire de <?= $tauxAbatt ?>% (<?= formatMontant($stats['abattement']) ?>). Le régime réel pourrait être plus avantageux.
                    </li>
                <?php endif; ?>
                <?php if ($stats['total_recettes'] > 0 && !getParam('versement_liberatoire_actif', '0')): ?>
                    <li class="list-group-item">
                        <i class="bi bi-lightbulb text-warning"></i> <strong>Astuce :</strong> Évaluez le versement libératoire de l'IR — avantageux si votre taux marginal > 11%.
                    </li>
                <?php endif; ?>
                <li class="list-group-item">
                    <i class="bi bi-calendar-check text-primary"></i> <strong>Rappel :</strong> Déclarez votre CA à l'URSSAF (mensuellement ou trimestriellement).
                </li>
            <?php else: ?>
                <?php if ($conf['tva_applicable'] && $stats['tva_a_payer'] > 0): ?>
                    <li class="list-group-item list-group-item-warning">
                        <i class="bi bi-receipt"></i> <strong>TVA :</strong> <?= formatMontant($stats['tva_a_payer']) ?> à reverser. N'oubliez pas vos déclarations.
                    </li>
                <?php endif; ?>
                <?php if ($conf['regime_imposition'] === 'is' && $stats['is'] > 0): ?>
                    <li class="list-group-item list-group-item-info">
                        <i class="bi bi-bank"></i> <strong>IS estimé :</strong> <?= formatMontant($stats['is']) ?>. Acomptes : 15/03, 15/06, 15/09, 15/12.
                    </li>
                <?php endif; ?>
                <?php if ($conf['is_societe']): ?>
                    <li class="list-group-item">
                        <i class="bi bi-person-badge text-primary"></i> Enregistrez les charges sociales du dirigeant comme dépenses.
                    </li>
                <?php endif; ?>
                <li class="list-group-item">
                    <i class="bi bi-calendar-check text-primary"></i> <strong>Rappel :</strong> Clôture des comptes et dépôt au greffe dans les 6 mois.
                </li>
            <?php endif; ?>

            <!-- Indicateurs commerciaux -->
            <?php if ($statsCommerciales['factures_en_retard'] > 0): ?>
                <li class="list-group-item list-group-item-danger">
                    <i class="bi bi-clock-history"></i> <strong><?= $statsCommerciales['factures_en_retard'] ?> facture(s) en retard de paiement.</strong>
                    <a href="factures.php" class="ms-2">Voir les factures →</a>
                </li>
            <?php endif; ?>
            <?php if ($statsCommerciales['en_attente_paiement'] > 0): ?>
                <li class="list-group-item list-group-item-warning">
                    <i class="bi bi-hourglass-split"></i> <?= formatMontant($statsCommerciales['en_attente_paiement']) ?> en attente de paiement.
                </li>
            <?php endif; ?>

            <!-- Comparaison N-1 -->
            <?php
            $evolCA = $statsN1['total_recettes'] > 0 ? round(($stats['total_recettes'] - $statsN1['total_recettes']) / $statsN1['total_recettes'] * 100, 1) : 0;
            if ($evolCA != 0):
            ?>
                <li class="list-group-item">
                    <i class="bi bi-graph-<?= $evolCA > 0 ? 'up text-success' : 'down text-danger' ?>"></i>
                    CA <?= $evolCA > 0 ? 'en hausse' : 'en baisse' ?> de <strong><?= abs($evolCA) ?>%</strong> par rapport à <?= $annee - 1 ?>.
                </li>
            <?php endif; ?>
        </ul>
    </div>
</div>

<?php endif; ?>

<?php include 'footer.php'; ?>

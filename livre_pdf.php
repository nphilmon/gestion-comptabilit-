<?php
/**
 * Export PDF des livres comptables (recettes, achats, journal, grand livre, balance, trésorerie)
 * Remplace l'impression navigateur (window.print()), peu fiable selon les configurations.
 */
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/lib/fpdf.php';
requireLogin();
requireRole('admin', 'comptable');

$onglet = $_GET['onglet'] ?? 'recettes';
$annee  = isset($_GET['annee']) ? (int) $_GET['annee'] : (int) date('Y');
if (!in_array($onglet, ['recettes', 'achats', 'journal', 'grandlivre', 'balance', 'tresorerie'], true)) {
    die('Onglet invalide.');
}

$conf = getRegimeConfig();
$recettes = getTransactions(['type' => 'recette', 'annee' => $annee]);
$achats = getTransactions(['type' => 'depense', 'annee' => $annee]);
$toutesTransactions = getTransactions(['annee' => $annee]);
$totalRecettes = array_sum(array_column($recettes, 'montant'));
$totalAchats = array_sum(array_column($achats, 'montant'));

$recettesChronologiques = array_reverse($recettes);
$achatsChronologiques = array_reverse($achats);
$journalChronologique = array_reverse($toutesTransactions);

$grandLivre = [];
foreach ($journalChronologique as $t) {
    $catKey = $t['categorie_nom'] ?? 'Non classé';
    if (!isset($grandLivre[$catKey])) {
        $grandLivre[$catKey] = ['total_debit' => 0, 'total_credit' => 0, 'ecritures' => []];
    }
    $grandLivre[$catKey]['ecritures'][] = $t;
    if ($t['type'] === 'depense') {
        $grandLivre[$catKey]['total_debit'] += $t['montant'];
    } else {
        $grandLivre[$catKey]['total_credit'] += $t['montant'];
    }
}
ksort($grandLivre);

$balance = [];
foreach ($grandLivre as $catNom => $catData) {
    $balance[] = [
        'compte' => $catNom,
        'nb_ecritures' => count($catData['ecritures']),
        'total_debit' => $catData['total_debit'],
        'total_credit' => $catData['total_credit'],
        'solde' => $catData['total_credit'] - $catData['total_debit'],
    ];
}

$statsMensuelles = $onglet === 'tresorerie' ? getStatsMensuelles($annee) : [];

$titres = [
    'recettes'   => ['Livre des recettes', 'Art. L123-28 du Code de Commerce'],
    'achats'     => ['Registre des achats', ''],
    'journal'    => ['Journal général', 'Toutes écritures — débit / crédit'],
    'grandlivre' => ['Grand livre', 'Écritures groupées par compte / catégorie'],
    'balance'    => ['Balance générale', 'Synthèse débit / crédit par compte'],
    'tresorerie' => ['Suivi de trésorerie', ''],
];
[$titrePrincipal, $sousTitre] = $titres[$onglet];

// =====================================================================
// Classe PDF — charte MD3 (mêmes tons que assets/style.css)
// =====================================================================
class LivrePDF extends FPDF
{
    public $titre = '';
    public $sousTitre = '';
    public $annee = 0;

    private $colPrimary   = [37, 99, 235];
    private $colPrimaryDk = [30, 58, 95];
    private $colTint      = [219, 234, 254];
    private $colDark      = [15, 23, 42];
    private $colMuted     = [71, 85, 105];
    private $colBorder    = [226, 232, 240];
    private $colZebra     = [248, 250, 252];
    private $colSuccess   = [22, 163, 74];
    private $colError     = [220, 38, 38];

    function conv($str)
    {
        return mb_convert_encoding((string) $str, 'Windows-1252', 'UTF-8');
    }

    function eur(float $value): string
    {
        return number_format($value, 2, ',', ' ') . " \xE2\x82\xAC";
    }

    function Header()
    {
        $this->SetFillColor(...$this->colPrimary);
        $this->Rect(0, 0, $this->w, 22, 'F');
        $this->SetTextColor(255, 255, 255);
        $this->SetFont('Helvetica', 'B', 15);
        $this->SetXY(10, 6);
        $this->Cell(0, 8, $this->conv($this->titre . ' — ' . $this->annee), 0, 1);
        if ($this->sousTitre !== '') {
            $this->SetFont('Helvetica', '', 9);
            $this->SetXY(10, 14);
            $this->Cell(0, 5, $this->conv($this->sousTitre), 0, 1);
        }
        $this->SetY(28);
        $this->SetTextColor(...$this->colDark);
    }

    function Footer()
    {
        $this->SetY(-15);
        $this->SetFont('Helvetica', '', 8);
        $this->SetTextColor(...$this->colMuted);
        $this->Cell(0, 10, $this->conv(APP_NAME . ' — Généré le ' . date('d/m/Y à H:i')), 0, 0, 'L');
        $this->Cell(0, 10, 'Page ' . $this->PageNo() . '/{nb}', 0, 0, 'R');
    }

    function tableHeader(array $headers, array $widths, array $aligns): void
    {
        $this->SetFont('Helvetica', 'B', 8);
        $this->SetFillColor(...$this->colTint);
        $this->SetTextColor(...$this->colPrimaryDk);
        $this->SetDrawColor(...$this->colBorder);
        foreach ($headers as $i => $h) {
            $this->Cell($widths[$i], 7, $this->conv($h), 0, 0, $aligns[$i], true);
        }
        $this->Ln();
        $this->SetTextColor(...$this->colDark);
    }

    function tableRow(array $data, array $widths, array $aligns, bool $fill, array $colors = []): void
    {
        $this->SetFont('Helvetica', '', 8);
        $this->SetFillColor(...$this->colZebra);
        foreach ($data as $i => $val) {
            if (isset($colors[$i])) {
                $this->SetTextColor(...$colors[$i]);
            } else {
                $this->SetTextColor(...$this->colDark);
            }
            $this->Cell($widths[$i], 6, $this->conv($this->fitText((string) $val, $widths[$i])), 0, 0, $aligns[$i] ?? 'L', $fill);
        }
        $this->Ln();
        $this->SetTextColor(...$this->colDark);
    }

    // Tronque le texte avec "..." s'il dépasse la largeur de la cellule (évite le chevauchement de colonnes)
    function fitText(string $txt, float $width): string
    {
        $maxW = $width - 2;
        if ($this->GetStringWidth($this->conv($txt)) <= $maxW) {
            return $txt;
        }
        while ($txt !== '' && $this->GetStringWidth($this->conv($txt . '...')) > $maxW) {
            $txt = mb_substr($txt, 0, -1);
        }
        return $txt . '...';
    }

    function totalRow(array $data, array $widths, array $aligns): void
    {
        $this->SetFont('Helvetica', 'B', 8.5);
        $this->SetFillColor(...$this->colPrimaryDk);
        $this->SetTextColor(255, 255, 255);
        foreach ($data as $i => $val) {
            $this->Cell($widths[$i], 7, $this->conv((string) $val), 0, 0, $aligns[$i] ?? 'L', true);
        }
        $this->Ln();
        $this->SetTextColor(...$this->colDark);
    }

    function checkPageBreak(float $needed = 8): void
    {
        if ($this->GetY() + $needed > $this->h - 18) {
            $this->AddPage();
        }
    }
}

$pdf = new LivrePDF('L', 'mm', 'A4');
$pdf->titre = $titrePrincipal;
$pdf->sousTitre = $sousTitre;
$pdf->annee = $annee;
$pdf->AliasNbPages();
$pdf->AddPage();
$pdf->SetAutoPageBreak(false);

switch ($onglet) {
    case 'recettes':
        $headers = ['N°', 'Date', 'Réf.', 'Client', 'Nature de la prestation', 'Montant', 'Mode', 'Cumul'];
        $widths  = [12, 22, 25, 45, 90, 30, 25, 30];
        $aligns  = ['C', 'L', 'L', 'L', 'L', 'R', 'L', 'R'];
        $pdf->tableHeader($headers, $widths, $aligns);
        $cumul = 0;
        foreach ($recettesChronologiques as $i => $t) {
            $pdf->checkPageBreak();
            $cumul += $t['montant'];
            $pdf->tableRow([
                $i + 1, formatDate($t['date_transaction']), $t['reference'] ?? '—',
                $t['client_fournisseur'] ?? '—', $t['description'],
                $pdf->eur($t['montant']), ucfirst($t['mode_paiement']), $pdf->eur($cumul),
            ], $widths, $aligns, $i % 2 === 1);
        }
        $pdf->checkPageBreak(9);
        $pdf->totalRow(['', '', '', '', 'TOTAL ' . $annee, $pdf->eur($totalRecettes), '', $pdf->eur($cumul)], $widths, $aligns);
        break;

    case 'achats':
        $headers = ['N°', 'Date', 'Réf.', 'Fournisseur', 'Nature de l\'achat', 'Montant', 'Mode', 'Cumul'];
        $widths  = [12, 22, 25, 45, 90, 30, 25, 30];
        $aligns  = ['C', 'L', 'L', 'L', 'L', 'R', 'L', 'R'];
        $pdf->tableHeader($headers, $widths, $aligns);
        $cumul = 0;
        foreach ($achatsChronologiques as $i => $t) {
            $pdf->checkPageBreak();
            $cumul += $t['montant'];
            $pdf->tableRow([
                $i + 1, formatDate($t['date_transaction']), $t['reference'] ?? '—',
                $t['client_fournisseur'] ?? '—', $t['description'],
                $pdf->eur($t['montant']), ucfirst($t['mode_paiement']), $pdf->eur($cumul),
            ], $widths, $aligns, $i % 2 === 1);
        }
        $pdf->checkPageBreak(9);
        $pdf->totalRow(['', '', '', '', 'TOTAL ' . $annee, $pdf->eur($totalAchats), '', $pdf->eur($cumul)], $widths, $aligns);
        break;

    case 'journal':
        $headers = ['N°', 'Date', 'Jrn', 'Libellé', 'Compte (Catégorie)', 'Tiers', 'Réf.', 'Débit', 'Crédit', 'Solde cumulé'];
        $widths  = [10, 20, 14, 55, 45, 35, 22, 26, 26, 30];
        $aligns  = ['C', 'L', 'C', 'L', 'L', 'L', 'L', 'R', 'R', 'R'];
        $pdf->tableHeader($headers, $widths, $aligns);
        $cumulDebit = 0; $cumulCredit = 0;
        foreach ($journalChronologique as $i => $t) {
            $pdf->checkPageBreak();
            $isDebit = $t['type'] === 'depense';
            if ($isDebit) $cumulDebit += $t['montant']; else $cumulCredit += $t['montant'];
            $solde = $cumulCredit - $cumulDebit;
            $pdf->tableRow([
                $i + 1, formatDate($t['date_transaction']), $isDebit ? 'ACH' : 'VTE',
                $t['description'], $t['categorie_nom'] ?? '—', $t['client_fournisseur'] ?? '—',
                $t['reference'] ?? '', $isDebit ? $pdf->eur($t['montant']) : '', !$isDebit ? $pdf->eur($t['montant']) : '',
                $pdf->eur($solde),
            ], $widths, $aligns, $i % 2 === 1, [7 => $isDebit ? [220, 38, 38] : null, 8 => !$isDebit ? [22, 163, 74] : null]);
        }
        $pdf->checkPageBreak(9);
        $pdf->totalRow(['', '', '', '', '', '', 'TOTAUX', $pdf->eur($cumulDebit), $pdf->eur($cumulCredit), $pdf->eur($cumulCredit - $cumulDebit)], $widths, $aligns);
        break;

    case 'grandlivre':
        $widths = [25, 90, 40, 25, 30, 30];
        $aligns = ['L', 'L', 'L', 'L', 'R', 'R'];
        foreach ($grandLivre as $catNom => $catData) {
            $pdf->checkPageBreak(14);
            $pdf->SetFont('Helvetica', 'B', 9);
            $pdf->SetFillColor(241, 245, 249);
            $pdf->Cell(0, 7, $pdf->conv($catNom . '  (' . count($catData['ecritures']) . ' écritures — Débit ' . $pdf->eur($catData['total_debit']) . ' / Crédit ' . $pdf->eur($catData['total_credit']) . ')'), 0, 1, 'L', true);
            $pdf->tableHeader(['Date', 'Libellé', 'Tiers', 'Réf.', 'Débit', 'Crédit'], $widths, $aligns);
            foreach ($catData['ecritures'] as $i => $t) {
                $pdf->checkPageBreak();
                $pdf->tableRow([
                    formatDate($t['date_transaction']), $t['description'], $t['client_fournisseur'] ?? '',
                    $t['reference'] ?? '', $t['type'] === 'depense' ? $pdf->eur($t['montant']) : '',
                    $t['type'] === 'recette' ? $pdf->eur($t['montant']) : '',
                ], $widths, $aligns, $i % 2 === 1, [4 => [220, 38, 38], 5 => [22, 163, 74]]);
            }
            $pdf->Ln(3);
        }
        break;

    case 'balance':
        $headers = ['Compte (Catégorie)', 'Écritures', 'Total Débit', 'Total Crédit', 'Solde Débiteur', 'Solde Créditeur'];
        $widths  = [80, 30, 40, 40, 40, 40];
        $aligns  = ['L', 'C', 'R', 'R', 'R', 'R'];
        $pdf->tableHeader($headers, $widths, $aligns);
        $sD = 0; $sC = 0; $sSD = 0; $sSC = 0;
        foreach ($balance as $i => $b) {
            $pdf->checkPageBreak();
            $sD += $b['total_debit']; $sC += $b['total_credit'];
            $soldeD = $b['solde'] < 0 ? abs($b['solde']) : 0;
            $soldeC = $b['solde'] > 0 ? $b['solde'] : 0;
            $sSD += $soldeD; $sSC += $soldeC;
            $pdf->tableRow([
                $b['compte'], $b['nb_ecritures'],
                $b['total_debit'] > 0 ? $pdf->eur($b['total_debit']) : '—',
                $b['total_credit'] > 0 ? $pdf->eur($b['total_credit']) : '—',
                $soldeD > 0 ? $pdf->eur($soldeD) : '—', $soldeC > 0 ? $pdf->eur($soldeC) : '—',
            ], $widths, $aligns, $i % 2 === 1, [2 => [220, 38, 38], 3 => [22, 163, 74]]);
        }
        $pdf->checkPageBreak(9);
        $pdf->totalRow(['TOTAUX', count($toutesTransactions), $pdf->eur($sD), $pdf->eur($sC), $pdf->eur($sSD), $pdf->eur($sSC)], $widths, $aligns);
        break;

    case 'tresorerie':
        $tauxCotisations = (float) getParam('taux_cotisations_sociales', '21.10');
        $tauxCfp = (float) getParam('taux_cfp', '0.20');
        $vlActif = (bool) getParam('versement_liberatoire_actif', '0');
        $tauxVL = (float) getParam('taux_versement_liberatoire', '2.20');
        $tauxTva = (float) getParam('taux_tva', '20');

        if ($conf['is_micro']) {
            $headers = ['Mois', 'Recettes', 'Dépenses', 'Solde brut', 'URSSAF', 'CFP' . ($vlActif ? '+VL' : ''), 'Tréso. nette', 'Cumul'];
            $widths  = [30, 34, 34, 34, 34, 34, 34, 33];
        } elseif ($conf['tva_applicable']) {
            $headers = ['Mois', 'Recettes', 'Dépenses', 'Solde brut', 'TVA coll.', 'TVA déd.', 'TVA nette', 'Tréso. nette', 'Cumul'];
            $widths  = [26, 30, 30, 30, 30, 30, 30, 30, 31];
        } else {
            $headers = ['Mois', 'Recettes', 'Dépenses', 'Solde brut', 'Tréso. nette', 'Cumul'];
            $widths  = [40, 45, 45, 45, 45, 47];
        }
        $aligns = array_fill(1, count($headers) - 1, 'R'); $aligns[0] = 'L'; ksort($aligns);
        $pdf->tableHeader($headers, $widths, $aligns);

        $cumulNet = 0;
        foreach ($statsMensuelles as $mois => $d) {
            $pdf->checkPageBreak();
            $row = [formatDateMois($mois . '-01'), $d['recettes'] > 0 ? $pdf->eur($d['recettes']) : '—', $d['depenses'] > 0 ? $pdf->eur($d['depenses']) : '—', $pdf->eur($d['solde'])];
            if ($conf['is_micro']) {
                $urssaf = $d['recettes'] * ($tauxCotisations / 100);
                $cfpVl = $d['recettes'] * ($tauxCfp / 100) + ($vlActif ? $d['recettes'] * ($tauxVL / 100) : 0);
                $tresoNette = $d['recettes'] - $d['depenses'] - $urssaf - $cfpVl;
                $row[] = $urssaf > 0 ? $pdf->eur($urssaf) : '—';
                $row[] = $cfpVl > 0 ? $pdf->eur($cfpVl) : '—';
            } elseif ($conf['tva_applicable']) {
                $tvaC = $d['recettes'] * ($tauxTva / (100 + $tauxTva));
                $tvaD = $d['depenses'] * ($tauxTva / (100 + $tauxTva));
                $tresoNette = $d['recettes'] - $d['depenses'] - ($tvaC - $tvaD);
                $row[] = $tvaC > 0 ? $pdf->eur($tvaC) : '—';
                $row[] = $tvaD > 0 ? $pdf->eur($tvaD) : '—';
                $row[] = $pdf->eur($tvaC - $tvaD);
            } else {
                $tresoNette = $d['recettes'] - $d['depenses'];
            }
            $cumulNet += $tresoNette;
            $row[] = $pdf->eur($tresoNette);
            $row[] = $pdf->eur($cumulNet);
            $pdf->tableRow($row, $widths, $aligns, false);
        }
        $pdf->checkPageBreak(9);
        $totalRow = ['TOTAL ' . $annee, $pdf->eur($totalRecettes), $pdf->eur($totalAchats), $pdf->eur($totalRecettes - $totalAchats)];
        $nbExtra = count($headers) - 6;
        for ($i = 0; $i < $nbExtra; $i++) $totalRow[] = '';
        $totalRow[] = $pdf->eur($cumulNet);
        $totalRow[] = $pdf->eur($cumulNet);
        $pdf->totalRow($totalRow, $widths, $aligns);
        break;
}

$nomFichier = str_replace(' ', '_', $titrePrincipal) . '_' . $annee . '.pdf';
$pdf->Output('I', $nomFichier);

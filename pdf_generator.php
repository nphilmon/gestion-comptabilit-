<?php
/**
 * Générateur de PDF professionnel style EBP / Ciel / SAP
 * Utilise FPDF v1.85
 */
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/functions_commercial.php';
requireLogin();
require_once __DIR__ . '/lib/fpdf.php';

$type = $_GET['type'] ?? '';
$id   = (int)($_GET['id'] ?? 0);

if (!in_array($type, ['devis', 'facture', 'commande']) || !$id) {
    die('Paramètres invalides.');
}

$conf = getRegimeConfig();

// =====================================================================
// Classe PDF — Style EBP / Ciel / SAP
// =====================================================================
class DocumentPDF extends FPDF
{
    public $entreprise   = [];
    public $docType      = '';
    public $docNumero    = '';
    public $legalLines   = [];
    public $logoPath     = null;
    public $isCGVPage    = false;
    public $cgvDocRef    = '';

    // Couleurs
    private $colPrimary   = [0, 51, 102];
    private $colDark      = [33, 33, 33];
    private $colMuted     = [100, 100, 100];
    private $colLight     = [128, 128, 128];
    private $colBorder    = [180, 180, 180];
    private $colBorderL   = [210, 210, 210];
    private $colTableHead = [240, 240, 240];
    private $colBg        = [250, 250, 250];

    // --- Conversion UTF-8 ---
    function conv($str)
    {
        return mb_convert_encoding((string)$str, 'Windows-1252', 'UTF-8');
    }

    function eur(float $value): string
    {
        return number_format($value, 2, ',', ' ') . " \xE2\x82\xAC";
    }

    // =================================================================
    // EN-TÊTE
    // =================================================================
    function Header()
    {
        if ($this->isCGVPage) {
            $this->headerCGV();
            return;
        }
        $this->headerDocument();
    }

    function headerDocument()
    {
        $leftX = 15;
        $y = 10;

        // Logo + Nom entreprise (haut gauche)
        $textX = $leftX;
        if (!empty($this->logoPath) && is_file($this->logoPath)) {
            $this->Image($this->logoPath, $leftX, $y, 18, 18);
            $textX = $leftX + 22;
        }

        $this->SetFont('Helvetica', 'B', 14);
        $this->SetTextColor(...$this->colPrimary);
        $this->SetXY($textX, $y);
        $this->Cell(80, 6, $this->conv($this->entreprise['nom'] ?? ''), 0, 1);

        if (!empty($this->entreprise['activite'])) {
            $this->SetFont('Helvetica', '', 8);
            $this->SetTextColor(...$this->colMuted);
            $this->SetXY($textX, $y + 7);
            $this->Cell(80, 4, $this->conv($this->entreprise['activite']), 0, 1);
        }

        $infoY = $y + 12;
        $this->SetFont('Helvetica', '', 7.5);
        $this->SetTextColor(...$this->colDark);
        if (!empty($this->entreprise['adresse'])) {
            $this->SetXY($textX, $infoY);
            $this->Cell(80, 3.5, $this->conv($this->entreprise['adresse']), 0, 1);
            $infoY += 3.5;
        }
        if (!empty($this->entreprise['telephone'])) {
            $this->SetXY($textX, $infoY);
            $this->Cell(80, 3.5, $this->conv('Tel. : ' . $this->entreprise['telephone']), 0, 1);
            $infoY += 3.5;
        }
        if (!empty($this->entreprise['email'])) {
            $this->SetXY($textX, $infoY);
            $this->Cell(80, 3.5, $this->conv($this->entreprise['email']), 0, 1);
            $infoY += 3.5;
        }
        if (!empty($this->entreprise['siret'])) {
            $this->SetXY($textX, $infoY);
            $this->SetFont('Helvetica', '', 7);
            $this->SetTextColor(...$this->colLight);
            $this->Cell(80, 3.5, 'SIRET : ' . $this->entreprise['siret'], 0, 1);
        }

        // Type de document (haut droit)
        $this->SetFont('Helvetica', 'B', 24);
        $this->SetTextColor(...$this->colPrimary);
        $this->SetXY(120, $y);
        $this->Cell(75, 10, $this->conv(mb_strtoupper($this->docType)), 0, 1, 'R');

        $this->SetFont('Helvetica', '', 10);
        $this->SetTextColor(...$this->colDark);
        $this->SetXY(120, $y + 11);
        $this->Cell(75, 5, $this->conv("N\xC2\xB0 " . $this->docNumero), 0, 1, 'R');

        // Trait de séparation
        $sepY = max($infoY + 4, $y + 25);
        $this->SetDrawColor(...$this->colPrimary);
        $this->SetLineWidth(0.6);
        $this->Line(15, $sepY, 195, $sepY);
        $this->SetLineWidth(0.2);
        $this->SetY($sepY + 4);
        $this->SetTextColor(...$this->colDark);
    }

    function headerCGV()
    {
        $this->SetFont('Helvetica', '', 7.5);
        $this->SetTextColor(...$this->colLight);
        $this->SetXY(15, 10);
        $this->Cell(90, 4, $this->conv($this->entreprise['nom'] ?? ''), 0, 0, 'L');
        $this->Cell(90, 4, $this->conv($this->cgvDocRef), 0, 1, 'R');
        $this->SetDrawColor(...$this->colBorderL);
        $this->Line(15, 16, 195, 16);
        $this->SetY(20);
        $this->SetTextColor(...$this->colDark);
    }

    // =================================================================
    // PIED DE PAGE
    // =================================================================
    function Footer()
    {
        if ($this->isCGVPage) {
            $this->footerCGV();
            return;
        }
        $this->footerDocument();
    }

    function footerDocument()
    {
        $lineCount = count($this->legalLines);
        $footerH = 12 + ($lineCount * 3);
        $this->SetY(-$footerH);
        $this->SetDrawColor(...$this->colBorder);
        $this->SetLineWidth(0.2);
        $this->Line(15, $this->GetY(), 195, $this->GetY());
        $this->Ln(2);
        $this->SetFont('Helvetica', '', 6);
        $this->SetTextColor(...$this->colLight);
        foreach ($this->legalLines as $line) {
            $this->Cell(0, 3, $this->conv($line), 0, 1, 'C');
        }
        $this->Ln(0.5);
        $this->SetFont('Helvetica', '', 6.5);
        $this->SetTextColor(...$this->colMuted);
        $this->Cell(0, 3.5, 'Page ' . $this->PageNo() . '/{nb}', 0, 0, 'R');
    }

    function footerCGV()
    {
        $this->SetY(-12);
        $this->SetDrawColor(...$this->colBorderL);
        $this->Line(15, $this->GetY(), 195, $this->GetY());
        $this->Ln(2);
        $this->SetFont('Helvetica', '', 6.5);
        $this->SetTextColor(...$this->colLight);
        $this->Cell(90, 3.5, $this->conv('Conditions Generales de Vente'), 0, 0, 'L');
        $this->Cell(90, 3.5, 'Page ' . $this->PageNo() . '/{nb}', 0, 0, 'R');
    }

    // =================================================================
    // OUTILS DE MISE EN PAGE
    // =================================================================

    function infoLine(float $x, float $y, string $label, string $value, float $labelW = 35, float $valueW = 50): float
    {
        $this->SetXY($x, $y);
        $this->SetFont('Helvetica', '', 7.5);
        $this->SetTextColor(...$this->colMuted);
        $this->Cell($labelW, 4, $this->conv($label), 0, 0, 'L');
        $this->SetFont('Helvetica', 'B', 8);
        $this->SetTextColor(...$this->colDark);
        $this->Cell($valueW, 4, $this->conv($value), 0, 1, 'L');
        return $y + 4.5;
    }

    function addressBlock(float $x, float $y, float $w, string $title, array $lines): float
    {
        $padding = 4;
        $lineH = 4.2;
        $titleH = 5;
        $contentH = $titleH + 3 + (max(1, count($lines)) * $lineH) + $padding;

        $this->SetDrawColor(...$this->colBorder);
        $this->SetLineWidth(0.3);
        $this->Rect($x, $y, $w, $contentH);
        $this->SetLineWidth(0.2);

        $this->SetFillColor(...$this->colTableHead);
        $this->Rect($x, $y, $w, $titleH + 2, 'F');
        $this->SetDrawColor(...$this->colBorder);
        $this->Line($x, $y + $titleH + 2, $x + $w, $y + $titleH + 2);

        $this->SetFont('Helvetica', 'B', 7);
        $this->SetTextColor(...$this->colMuted);
        $this->SetXY($x + $padding, $y + 1.5);
        $this->Cell($w - $padding * 2, $titleH, $this->conv(mb_strtoupper($title)), 0, 1);

        $cursorY = $y + $titleH + 4;
        foreach ($lines as $i => $line) {
            $this->SetXY($x + $padding, $cursorY);
            if ($i === 0) {
                $this->SetFont('Helvetica', 'B', 9);
            } else {
                $this->SetFont('Helvetica', '', 8);
            }
            $this->SetTextColor(...$this->colDark);
            $this->MultiCell($w - $padding * 2, $lineH, $this->conv($line), 0, 'L');
            $cursorY = $this->GetY();
        }
        return $y + $contentH;
    }

    function nbLines(float $w, string $txt): int
    {
        $cw = &$this->CurrentFont['cw'];
        if ($w == 0) $w = $this->w - $this->rMargin - $this->x;
        $wmax = ($w - 2 * $this->cMargin) * 1000 / $this->FontSize;
        $s = str_replace("\r", '', $txt);
        $nb = strlen($s);
        if ($nb > 0 && $s[$nb - 1] === "\n") $nb--;
        $sep = -1; $i = 0; $j = 0; $l = 0; $nl = 1;
        while ($i < $nb) {
            $c = $s[$i];
            if ($c === "\n") { $i++; $sep = -1; $j = $i; $l = 0; $nl++; continue; }
            if ($c === ' ') $sep = $i;
            $l += $cw[$c] ?? 600;
            if ($l > $wmax) {
                if ($sep === -1) { if ($i === $j) $i++; } else { $i = $sep + 1; }
                $sep = -1; $j = $i; $l = 0; $nl++;
            } else { $i++; }
        }
        return $nl;
    }

    function tableHeader(array $headers, array $widths, array $aligns): void
    {
        $this->SetFillColor(...$this->colTableHead);
        $this->SetDrawColor(...$this->colBorder);
        $this->SetTextColor(...$this->colDark);
        $this->SetFont('Helvetica', 'B', 7.5);
        $this->SetLineWidth(0.3);

        $x = 15;
        $y = $this->GetY();
        $h = 7;
        $totalW = array_sum(array_filter($widths));

        $this->Line($x, $y, $x + $totalW, $y);
        foreach ($headers as $i => $header) {
            if (($widths[$i] ?? 0) <= 0) continue;
            $this->SetXY($x, $y);
            $this->Cell($widths[$i], $h, $this->conv($header), 0, 0, $aligns[$i] ?? 'L', true);
            $this->Line($x, $y, $x, $y + $h);
            $x += $widths[$i];
        }
        $this->Line($x, $y, $x, $y + $h);
        $this->Line(15, $y + $h, 15 + $totalW, $y + $h);
        $this->SetLineWidth(0.2);
        $this->SetY($y + $h);
    }

    function tableRow(array $data, array $widths, array $aligns, bool $fill = false): void
    {
        $lineCounts = [];
        foreach ($data as $i => $txt) {
            if (($widths[$i] ?? 0) <= 0) continue;
            $lineCounts[] = $this->nbLines($widths[$i] - 3, $this->conv($txt));
        }
        $rowHeight = max(6.5, max($lineCounts) * 4.2 + 2);

        if ($this->GetY() + $rowHeight > $this->h - $this->bMargin - 5) {
            $this->AddPage();
        }

        $totalW = array_sum(array_filter($widths));
        $x = 15;
        $y = $this->GetY();

        if ($fill) {
            $this->SetFillColor(248, 248, 252);
            $this->Rect($x, $y, $totalW, $rowHeight, 'F');
        }

        $this->SetDrawColor(...$this->colBorderL);
        $this->SetFont('Helvetica', '', 8);
        $this->SetTextColor(...$this->colDark);

        for ($i = 0; $i < count($data); $i++) {
            if (($widths[$i] ?? 0) <= 0) continue;
            $w = $widths[$i];
            $a = $aligns[$i] ?? 'L';
            $this->Line($x, $y, $x, $y + $rowHeight);
            $this->SetXY($x + 1.5, $y + 1);
            $this->MultiCell($w - 3, 4.2, $this->conv($data[$i]), 0, $a);
            $x += $w;
        }
        $this->Line($x, $y, $x, $y + $rowHeight);
        $this->Line(15, $y + $rowHeight, 15 + $totalW, $y + $rowHeight);
        $this->SetY($y + $rowHeight);
    }

    function summaryLine(string $label, string $value, bool $bold = false, array $labelColor = null, array $valColor = null): void
    {
        $x = 120;
        $labelW = 40;
        $valW = 35;
        $h = $bold ? 7.5 : 6;
        $this->SetFont('Helvetica', $bold ? 'B' : '', $bold ? 10 : 8.5);
        $this->SetTextColor(...($labelColor ?? $this->colDark));
        $this->SetX($x);
        $this->Cell($labelW, $h, $this->conv($label), 0, 0, 'R');
        $this->SetTextColor(...($valColor ?? $this->colDark));
        $this->Cell($valW, $h, $this->conv($value), 0, 1, 'R');
    }

    function summaryTotalTTC(string $label, string $value): void
    {
        $x = 120;
        $totalW = 75;
        $h = 9;
        $y = $this->GetY();
        $this->SetFillColor(...$this->colPrimary);
        $this->Rect($x, $y, $totalW, $h, 'F');
        $this->SetTextColor(255, 255, 255);
        $this->SetFont('Helvetica', 'B', 11);
        $this->SetXY($x, $y);
        $this->Cell(40, $h, $this->conv($label), 0, 0, 'R');
        $this->Cell(35, $h, $this->conv($value), 0, 1, 'R');
        $this->SetTextColor(...$this->colDark);
    }

    function sectionLabel(string $label): void
    {
        if ($this->GetY() + 10 > $this->h - $this->bMargin) {
            $this->AddPage();
        }
        $this->SetFillColor(...$this->colTableHead);
        $this->SetDrawColor(...$this->colBorder);
        $this->SetTextColor(...$this->colDark);
        $this->SetFont('Helvetica', 'B', 8);
        $y = $this->GetY();
        $this->Rect(15, $y, 180, 6, 'DF');
        $this->SetXY(18, $y);
        $this->Cell(174, 6, $this->conv(mb_strtoupper($label)), 0, 1, 'L');
        $this->Ln(1);
    }
}

// =====================================================================
// Fonctions utilitaires
// =====================================================================

function resolvePdfImagePath(string $value): ?string
{
    $value = trim($value);
    if ($value === '') return null;

    $candidate = $value;
    if (!preg_match('/^[A-Za-z]:[\\\\\\/]/', $candidate) && !str_starts_with($candidate, DIRECTORY_SEPARATOR)) {
        $candidate = __DIR__ . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $candidate);
    }
    $real = realpath($candidate);
    return ($real !== false && is_file($real)) ? $real : null;
}

function getLegalFooterLines(array $entreprise, array $conf, string $type, array $doc): array
{
    $lines = [];
    $identity = array_filter([
        $entreprise['adresse'] ?? '',
        !empty($entreprise['siret']) ? 'SIRET : ' . $entreprise['siret'] : '',
        $entreprise['email'] ?? '',
        $entreprise['telephone'] ?? '',
    ]);
    if ($identity) {
        $lines[] = implode('  -  ', $identity);
    }

    if (!$conf['tva_applicable']) {
        $lines[] = 'TVA non applicable, art. 293 B du CGI';
    } elseif (!empty($doc['client_tva'])) {
        $lines[] = "TVA applicable selon le regime en vigueur - N\xC2\xB0 TVA client : " . $doc['client_tva'];
    }

    $conditionsPaiement = trim((string) getParam('conditions_paiement', "Paiement a reception"));
    $lines[] = 'Conditions : ' . $conditionsPaiement . ' - Penalites de retard : taux BCE + 10 pts';

    $clientPro = ($doc['client_type'] ?? null) === 'professionnel' || !empty($doc['client_entreprise']);
    if ($clientPro) {
        $lines[] = "Indemnite forfaitaire de recouvrement (professionnels) : 40 \xE2\x82\xAC";
    }

    return $lines;
}

// =====================================================================
// Récupération des données
// =====================================================================

if ($type === 'devis') {
    $doc = getDevis($id);
    if (!$doc) die('Devis introuvable.');
    $lignes     = getLignesDevis($id);
    $docLabel   = 'Devis';
    $dateValue  = $doc['date_devis'];
} elseif ($type === 'facture') {
    $doc = getFacture($id);
    if (!$doc) die('Facture introuvable.');
    $lignes     = getLignesFacture($id);
    $docLabel   = 'Facture';
    $dateValue  = $doc['date_facture'];
} else {
    $doc = getCommande($id);
    if (!$doc) die('Commande introuvable.');
    $lignes     = getLignesCommande($id);
    $docLabel   = 'Commande';
    $dateValue  = $doc['date_commande'];
}

$statusLabel = match ($type) {
    'devis'    => getStatutDevisLabel($doc['statut'])['label'],
    'commande' => getStatutCommandeLabel($doc['statut'])['label'],
    default    => getStatutFactureLabel($doc['statut'])['label'],
};

// =====================================================================
// CONSTRUCTION DU PDF — PAGES DOCUMENT
// =====================================================================
$pdf = new DocumentPDF('P', 'mm', 'A4');
$pdf->AliasNbPages();

$pdf->entreprise = [
    'nom'       => getParam('nom_entreprise', 'Mon Entreprise'),
    'adresse'   => getParam('adresse_entreprise', ''),
    'activite'  => getParam('activite', ''),
    'email'     => getParam('email_entreprise', ''),
    'telephone' => getParam('telephone_entreprise', ''),
    'siret'     => getParam('siret', ''),
];
$pdf->docType    = $docLabel;
$pdf->docNumero  = $doc['numero'];
$pdf->legalLines = getLegalFooterLines($pdf->entreprise, $conf, $type, $doc);
$pdf->logoPath   = resolvePdfImagePath(getParam('logo_pdf_path', ''));
$pdf->cgvDocRef  = $docLabel . " N\xC2\xB0 " . $doc['numero'];

$footerLineCount = count($pdf->legalLines);
$pdf->SetAutoPageBreak(true, 14 + $footerLineCount * 3);
$pdf->AddPage();

// =============================================================
// BLOC INFORMATIONS (dates, réf, statut)
// =============================================================
$infoY = $pdf->GetY();
$y = $infoY;
$y = $pdf->infoLine(15, $y, 'Date :', formatDate($dateValue));
$y = $pdf->infoLine(15, $y, 'Statut :', $statusLabel);

if ($type === 'devis') {
    $y = $pdf->infoLine(15, $y, 'Validite :', formatDate($doc['date_validite']));
} elseif ($type === 'facture') {
    $y = $pdf->infoLine(15, $y, 'Echeance :', formatDate($doc['date_echeance']));
}

if ($type === 'facture' && !empty($doc['devis_id'])) {
    $devisRef = getDevis((int)$doc['devis_id']);
    if ($devisRef) {
        $y = $pdf->infoLine(15, $y, 'Ref. devis :', $devisRef['numero']);
    }
}

$pdf->Ln(2);

// =============================================================
// BLOCS ADRESSES
// =============================================================
$blockY = max($y + 2, $pdf->GetY());

$companyLines = array_values(array_filter([
    $pdf->entreprise['nom'],
    $pdf->entreprise['adresse'] ?? '',
    trim(($pdf->entreprise['email'] ?? '') . (!empty($pdf->entreprise['telephone']) ? '  -  ' . $pdf->entreprise['telephone'] : '')),
    !empty($pdf->entreprise['siret']) ? 'SIRET : ' . $pdf->entreprise['siret'] : '',
]));

$clientNom = $doc['client_entreprise'] ?: trim(($doc['client_prenom'] ?? '') . ' ' . $doc['client_nom']);
$clientLines = array_values(array_filter([
    $clientNom,
    $doc['client_adresse'] ?? '',
    trim(($doc['client_cp'] ?? '') . ' ' . ($doc['client_ville'] ?? '')),
    $doc['client_email'] ?? '',
    !empty($doc['client_telephone']) ? 'Tel. : ' . $doc['client_telephone'] : '',
    !empty($doc['client_siret']) ? 'SIRET : ' . $doc['client_siret'] : '',
]));

$bottomL = $pdf->addressBlock(15, $blockY, 85, 'Emetteur', $companyLines);
$bottomR = $pdf->addressBlock(110, $blockY, 85, 'Destinataire', $clientLines);
$pdf->SetY(max($bottomL, $bottomR) + 5);

// =============================================================
// OBJET
// =============================================================
if (!empty($doc['objet'])) {
    $pdf->sectionLabel('Objet');
    $pdf->SetFont('Helvetica', '', 9);
    $pdf->SetTextColor(33, 33, 33);
    $pdf->SetX(15);
    $pdf->MultiCell(180, 4.5, $pdf->conv($doc['objet']));
    $pdf->Ln(3);
}

// =============================================================
// TABLEAU DES LIGNES
// =============================================================
if ($conf['tva_applicable']) {
    $colWidths    = [75, 14, 18, 24, 16, 33];
    $headers      = ['Designation', 'Qte', 'Unite', 'P.U. HT', 'TVA %', 'Total HT'];
    $headerAligns = ['L', 'C', 'C', 'R', 'R', 'R'];
} else {
    $colWidths    = [90, 14, 18, 26, 0, 32];
    $headers      = ['Designation', 'Qte', 'Unite', 'Prix unit.', '', 'Total'];
    $headerAligns = ['L', 'C', 'C', 'R', 'R', 'R'];
}

$pdf->tableHeader($headers, $colWidths, $headerAligns);

$fill = false;
foreach ($lignes as $l) {
    $rowData = [
        $l['description'],
        rtrim(rtrim(number_format((float)$l['quantite'], 3, ',', ' '), '0'), ','),
        $l['unite'],
        number_format((float)$l['prix_unitaire_ht'], 2, ',', ' '),
        $conf['tva_applicable'] ? number_format((float)$l['taux_tva'], 1, ',', '') . '%' : '',
        number_format((float)$l['montant_ht'], 2, ',', ' '),
    ];
    $rowAligns = ['L', 'C', 'C', 'R', 'R', 'R'];
    $pdf->tableRow($rowData, $colWidths, $rowAligns, $fill);
    $fill = !$fill;
}

$pdf->Ln(5);

// =============================================================
// TOTAUX
// =============================================================
$totalsBlockH = $conf['tva_applicable'] ? 30 : 20;
if ($pdf->GetY() + $totalsBlockH > $pdf->GetPageHeight() - 25) {
    $pdf->AddPage();
}

$totalsY = $pdf->GetY();
$boxW = 75;
$boxX = 120;
$boxH = $conf['tva_applicable'] ? 24 : 16;
$pdf->SetFillColor(250, 250, 250);
$pdf->SetDrawColor(180, 180, 180);
$pdf->Rect($boxX, $totalsY, $boxW, $boxH, 'DF');

$pdf->summaryLine('Total HT :', $pdf->eur((float)$doc['montant_ht']));

if ($conf['tva_applicable']) {
    $pdf->summaryLine('Total TVA :', $pdf->eur((float)$doc['montant_tva']));
}

$pdf->SetDrawColor(180, 180, 180);
$pdf->Line($boxX + 2, $pdf->GetY(), $boxX + $boxW - 2, $pdf->GetY());
$pdf->Ln(1);

$pdf->summaryTotalTTC('TOTAL TTC :', $pdf->eur((float)$doc['montant_ttc']));

// =============================================================
// ACOMPTE / SOLDE
// =============================================================
$acomptePct = (float) getParam('acompte_commande_pct', '30');
if (in_array($type, ['devis', 'commande'], true) && $acomptePct > 0) {
    $acompte = round((float)$doc['montant_ttc'] * ($acomptePct / 100), 2);
    $solde   = max(0, (float)$doc['montant_ttc'] - $acompte);
    $pdf->Ln(2);
    $pdf->summaryLine('Acompte (' . number_format($acomptePct, 0) . '%) :', $pdf->eur($acompte), false, [0, 80, 170], [0, 80, 170]);
    $pdf->summaryLine('Solde restant :', $pdf->eur($solde), true);
}

// =============================================================
// PAIEMENTS (factures)
// =============================================================
if ($type === 'facture' && (float)$doc['montant_paye'] > 0) {
    $pdf->Ln(2);
    $pdf->summaryLine('Deja paye :', $pdf->eur((float)$doc['montant_paye']), false, [0, 120, 60], [0, 120, 60]);
    $reste = (float)$doc['montant_ttc'] - (float)$doc['montant_paye'];
    if ($reste > 0.01) {
        $pdf->summaryLine('Reste a payer :', $pdf->eur($reste), true, [200, 30, 30], [200, 30, 30]);
    }
}

$pdf->Ln(6);

// =============================================================
// NOTES
// =============================================================
if (!empty($doc['notes'])) {
    $pdf->sectionLabel('Notes');
    $pdf->SetFont('Helvetica', '', 8.5);
    $pdf->SetTextColor(80, 80, 80);
    $pdf->SetX(15);
    $pdf->MultiCell(180, 4.5, $pdf->conv($doc['notes']));
    $pdf->SetTextColor(33, 33, 33);
    $pdf->Ln(3);
}

// =============================================================
// BON POUR ACCORD (devis)
// =============================================================
if ($type === 'devis') {
    if ($pdf->GetY() + 38 > $pdf->GetPageHeight() - 25) {
        $pdf->AddPage();
    }
    $pdf->Ln(3);
    $startY = $pdf->GetY();

    $pdf->SetDrawColor(180, 180, 180);
    $pdf->SetFillColor(250, 250, 252);
    $pdf->SetLineWidth(0.3);
    $pdf->Rect(15, $startY, 180, 34, 'DF');
    $pdf->SetLineWidth(0.2);

    $pdf->SetXY(20, $startY + 3);
    $pdf->SetFont('Helvetica', 'B', 9);
    $pdf->SetTextColor(0, 51, 102);
    $pdf->Cell(80, 5, $pdf->conv('BON POUR ACCORD'), 0, 1);

    $pdf->SetX(20);
    $pdf->SetFont('Helvetica', '', 7.5);
    $pdf->SetTextColor(100, 100, 100);
    $pdf->MultiCell(95, 3.5, $pdf->conv("Signature precedee de la mention \"Bon pour accord\",\ncachet eventuel, date et nom du signataire."));

    $pdf->SetFont('Helvetica', '', 8);
    $pdf->SetTextColor(33, 33, 33);
    $pdf->SetXY(130, $startY + 4);
    $pdf->Cell(55, 4, $pdf->conv('Date : ........ / ........ / ............'), 0, 1);
    $pdf->SetXY(130, $startY + 13);
    $pdf->Cell(55, 4, $pdf->conv('Signature :'), 0, 1);
    $pdf->SetDrawColor(180, 180, 180);
    $pdf->Line(130, $startY + 29, 188, $startY + 29);

    $signaturePath = resolvePdfImagePath(getParam('signature_pdf_path', ''));
    $cachetPath    = resolvePdfImagePath(getParam('cachet_pdf_path', ''));
    if ($signaturePath) {
        $pdf->Image($signaturePath, 140, $startY + 17, 30, 10);
    }
    if ($cachetPath) {
        $pdf->Image($cachetPath, 88, $startY + 6, 24, 24);
    }
}

// =============================================================
// PAGES CGV — SÉPARÉES, SANS EN-TÊTE DOCUMENT
// =============================================================
$hasCGV = !empty($doc['conditions']) || in_array($type, ['devis', 'facture', 'commande'], true);

if ($hasCGV) {
    // Basculer en mode CGV (en-tête/pied minimalistes)
    $pdf->isCGVPage = true;
    $pdf->AddPage();

    // Titre
    $pdf->SetFont('Helvetica', 'B', 14);
    $pdf->SetTextColor(0, 51, 102);
    $pdf->Cell(0, 8, $pdf->conv('CONDITIONS GENERALES DE VENTE'), 0, 1, 'C');

    $pdf->SetDrawColor(0, 51, 102);
    $pdf->SetLineWidth(0.4);
    $pdf->Line(60, $pdf->GetY(), 150, $pdf->GetY());
    $pdf->SetLineWidth(0.2);
    $pdf->Ln(5);

    // Référence du document
    $pdf->SetFont('Helvetica', '', 8);
    $pdf->SetTextColor(128, 128, 128);
    $pdf->Cell(0, 4, $pdf->conv("Applicables au " . $docLabel . " N\xC2\xB0 " . $doc['numero'] . ' du ' . formatDate($dateValue)), 0, 1, 'C');
    $pdf->Ln(4);

    // Résumé des conditions
    $conditionsText = getConditionsDocumentVente($type === 'facture' ? 'facture' : $type, $doc);
    if (!empty($conditionsText)) {
        $pdf->SetFont('Helvetica', 'B', 9);
        $pdf->SetTextColor(0, 51, 102);
        $pdf->SetX(15);
        $pdf->Cell(180, 5, $pdf->conv('Resume'), 0, 1, 'L');
        $pdf->Ln(1);
        $pdf->SetFont('Helvetica', '', 8);
        $pdf->SetTextColor(60, 60, 60);
        $pdf->SetX(15);
        $pdf->MultiCell(180, 4, $pdf->conv($conditionsText));
        $pdf->Ln(4);
    }

    // Conditions détaillées complètes
    $fullCGV = genererConditionsDocumentVenteCompletes($type === 'facture' ? 'facture' : $type, $doc);
    if (!empty($fullCGV)) {
        $pdf->SetFont('Helvetica', 'B', 9);
        $pdf->SetTextColor(0, 51, 102);
        $pdf->SetX(15);
        $pdf->Cell(180, 5, $pdf->conv('Conditions detaillees'), 0, 1, 'L');
        $pdf->SetDrawColor(210, 210, 210);
        $pdf->Line(15, $pdf->GetY(), 195, $pdf->GetY());
        $pdf->Ln(2);
        $pdf->SetFont('Helvetica', '', 7.5);
        $pdf->SetTextColor(50, 50, 50);
        $pdf->SetX(15);
        $pdf->MultiCell(180, 3.8, $pdf->conv($fullCGV));
    }

    // Mention d'acceptation
    $pdf->Ln(6);
    $pdf->SetDrawColor(180, 180, 180);
    $pdf->Line(15, $pdf->GetY(), 195, $pdf->GetY());
    $pdf->Ln(3);
    $pdf->SetFont('Helvetica', 'I', 7);
    $pdf->SetTextColor(128, 128, 128);
    $pdf->SetX(15);
    $pdf->MultiCell(180, 3.5, $pdf->conv(
        'Le client reconnait avoir pris connaissance des presentes conditions generales de vente et les accepte sans reserve. '
        . 'Toute commande implique l\'adhesion pleine et entiere aux presentes conditions.'
    ));
}

// =============================================================
// SORTIE
// =============================================================
$filename = $doc['numero'] . '.pdf';
$pdf->Output('I', $filename);

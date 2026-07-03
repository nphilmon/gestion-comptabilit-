<?php
/**
 * Générateur de PDF professionnel — charte visuelle alignée sur le thème
 * web (Material Design 3). Utilise FPDF v1.85
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
// Classe PDF — charte MD3 (mêmes tons que assets/style.css)
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

    // Couleurs — alignées sur les jetons MD3 du thème web
    private $colPrimary       = [37, 99, 235];    // --md-primary
    private $colPrimaryDark   = [30, 58, 95];     // --md-on-primary-container
    private $colPrimaryTint   = [219, 234, 254];  // --md-primary-container
    private $colDark          = [15, 23, 42];     // --md-on-surface
    private $colMuted         = [71, 85, 105];    // --md-on-surface-variant
    private $colLight         = [100, 116, 139];  // slate-500
    private $colBorder        = [203, 213, 225];  // --md-outline
    private $colBorderL       = [226, 232, 240];  // --md-outline-variant
    private $colTableHead     = [219, 234, 254];  // --md-primary-container
    private $colBg            = [248, 250, 252];  // --md-surface
    private $colZebra         = [241, 245, 249];  // subtle row tint
    private $colSuccess       = [22, 163, 74];    // --md-success
    private $colError         = [220, 38, 38];    // --md-error

    // --- Conversion UTF-8 ---
    function conv($str)
    {
        return mb_convert_encoding((string)$str, 'Windows-1252', 'UTF-8');
    }

    function eur(float $value): string
    {
        return number_format($value, 2, ',', ' ') . " \xE2\x82\xAC";
    }

    // --- Rectangle à coins arrondis (mêmes rayons que le web) ---
    function roundedRect(float $x, float $y, float $w, float $h, float $r, string $style = ''): void
    {
        $k = $this->k;
        $hp = $this->h;
        $op = $style === 'F' ? 'f' : (($style === 'FD' || $style === 'DF') ? 'B' : 'S');
        $myArc = 4 / 3 * (sqrt(2) - 1);

        $this->_out(sprintf('%.2F %.2F m', ($x + $r) * $k, ($hp - $y) * $k));
        $xc = $x + $w - $r; $yc = $y + $r;
        $this->_out(sprintf('%.2F %.2F l', $xc * $k, ($hp - $y) * $k));
        $this->_arc($xc + $r * $myArc, $yc - $r, $xc + $r, $yc - $r * $myArc, $xc + $r, $yc);
        $xc = $x + $w - $r; $yc = $y + $h - $r;
        $this->_out(sprintf('%.2F %.2F l', ($x + $w) * $k, ($hp - $yc) * $k));
        $this->_arc($xc + $r, $yc + $r * $myArc, $xc + $r * $myArc, $yc + $r, $xc, $yc + $r);
        $xc = $x + $r; $yc = $y + $h - $r;
        $this->_out(sprintf('%.2F %.2F l', $xc * $k, ($hp - ($y + $h)) * $k));
        $this->_arc($xc - $r * $myArc, $yc + $r, $xc - $r, $yc + $r * $myArc, $xc - $r, $yc);
        $xc = $x + $r; $yc = $y + $r;
        $this->_out(sprintf('%.2F %.2F l', $x * $k, ($hp - $yc) * $k));
        $this->_arc($xc - $r, $yc - $r * $myArc, $xc - $r * $myArc, $yc - $r, $xc, $yc - $r);
        $this->_out($op);
    }

    private function _arc(float $x1, float $y1, float $x2, float $y2, float $x3, float $y3): void
    {
        $h = $this->h;
        $this->_out(sprintf(
            '%.2F %.2F %.2F %.2F %.2F %.2F c',
            $x1 * $this->k, ($h - $y1) * $this->k,
            $x2 * $this->k, ($h - $y2) * $this->k,
            $x3 * $this->k, ($h - $y3) * $this->k
        ));
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
        $this->SetTextColor(...$this->colPrimaryDark);
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

        // Type de document (haut droit) — pastille de couleur primaire
        $this->SetFillColor(...$this->colPrimary);
        $this->roundedRect(118, $y - 1, 77, 11, 2, 'F');
        $this->SetFont('Helvetica', 'B', 15);
        $this->SetTextColor(255, 255, 255);
        $this->SetXY(118, $y + 0.7);
        $this->Cell(73, 8, $this->conv(mb_strtoupper($this->docType)), 0, 1, 'C');

        $this->SetFont('Helvetica', '', 10);
        $this->SetTextColor(...$this->colDark);
        $this->SetXY(120, $y + 12.5);
        $this->Cell(75, 5, $this->conv("N\xC2\xB0 " . $this->docNumero), 0, 1, 'R');

        // Trait de séparation — fin, couleur primaire
        $sepY = max($infoY + 4, $y + 25);
        $this->SetDrawColor(...$this->colPrimary);
        $this->SetLineWidth(0.5);
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
        $this->SetDrawColor(...$this->colBorderL);
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
        $this->roundedRect($x, $y, $w, $contentH, 2.5);
        $this->SetLineWidth(0.2);

        $this->SetFillColor(...$this->colPrimaryTint);
        $this->roundedRect($x, $y, $w, $titleH + 2, 2.5, 'F');
        $this->SetFillColor(...$this->colPrimaryTint);
        $this->Rect($x, $y + 2.5, $w, $titleH - 0.5, 'F');

        $this->SetFont('Helvetica', 'B', 7);
        $this->SetTextColor(...$this->colPrimaryDark);
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
        $this->SetTextColor(...$this->colPrimaryDark);
        $this->SetFont('Helvetica', 'B', 7.5);
        $this->SetLineWidth(0.3);

        $x = 15;
        $y = $this->GetY();
        $h = 7.5;
        $totalW = array_sum(array_filter($widths));

        $this->Line($x, $y, $x + $totalW, $y);
        foreach ($headers as $i => $header) {
            if (($widths[$i] ?? 0) <= 0) continue;
            $this->SetXY($x, $y);
            $this->Cell($widths[$i], $h, $this->conv($header), 0, 0, $aligns[$i] ?? 'L', true);
            $x += $widths[$i];
        }
        $this->SetDrawColor(...$this->colPrimary);
        $this->SetLineWidth(0.4);
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
            $this->SetFillColor(...$this->colZebra);
            $this->Rect($x, $y, $totalW, $rowHeight, 'F');
        }

        $this->SetDrawColor(...$this->colBorderL);
        $this->SetFont('Helvetica', '', 8);
        $this->SetTextColor(...$this->colDark);

        for ($i = 0; $i < count($data); $i++) {
            if (($widths[$i] ?? 0) <= 0) continue;
            $w = $widths[$i];
            $a = $aligns[$i] ?? 'L';
            $this->SetXY($x + 1.5, $y + 1);
            $this->MultiCell($w - 3, 4.2, $this->conv($data[$i]), 0, $a);
            $x += $w;
        }
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
        $this->SetTextColor(...($labelColor ?? $this->colMuted));
        $this->SetX($x);
        $this->Cell($labelW, $h, $this->conv($label), 0, 0, 'R');
        $this->SetTextColor(...($valColor ?? $this->colDark));
        $this->Cell($valW, $h, $this->conv($value), 0, 1, 'R');
    }

    function summaryTotalTTC(string $label, string $value): void
    {
        $x = 120;
        $totalW = 75;
        $h = 10;
        $y = $this->GetY();
        $this->SetFillColor(...$this->colPrimary);
        $this->roundedRect($x, $y, $totalW, $h, 2, 'F');
        $this->SetTextColor(255, 255, 255);
        $this->SetFont('Helvetica', 'B', 11.5);
        $this->SetXY($x, $y + 0.6);
        $this->Cell(40, $h - 1, $this->conv($label), 0, 0, 'R');
        $this->Cell(35, $h - 1, $this->conv($value), 0, 1, 'R');
        $this->SetTextColor(...$this->colDark);
    }

    function sectionLabel(string $label): void
    {
        if ($this->GetY() + 10 > $this->h - $this->bMargin) {
            $this->AddPage();
        }
        $this->SetFillColor(...$this->colPrimaryTint);
        $this->SetTextColor(...$this->colPrimaryDark);
        $this->SetFont('Helvetica', 'B', 8);
        $y = $this->GetY();
        $this->roundedRect(15, $y, 180, 6.5, 1.5, 'F');
        $this->SetXY(18, $y);
        $this->Cell(174, 6.5, $this->conv(mb_strtoupper($label)), 0, 1, 'L');
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
        $lines[] = "TVA applicable selon le régime en vigueur - N\xC2\xB0 TVA client : " . $doc['client_tva'];
    }

    $conditionsPaiement = trim((string) getParam('conditions_paiement', "Paiement à réception"));
    $lines[] = 'Conditions : ' . $conditionsPaiement . ' - Pénalités de retard : taux BCE + 10 pts';

    $clientPro = ($doc['client_type'] ?? null) === 'professionnel' || !empty($doc['client_entreprise']);
    if ($clientPro) {
        $lines[] = "Indemnité forfaitaire de recouvrement (professionnels) : 40 \xE2\x82\xAC";
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
    $y = $pdf->infoLine(15, $y, 'Validité :', formatDate($doc['date_validite']));
} elseif ($type === 'facture') {
    $y = $pdf->infoLine(15, $y, 'Échéance :', formatDate($doc['date_echeance']));
}

if ($type === 'facture' && !empty($doc['devis_id'])) {
    $devisRef = getDevis((int)$doc['devis_id']);
    if ($devisRef) {
        $y = $pdf->infoLine(15, $y, 'Réf. devis :', $devisRef['numero']);
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

$bottomL = $pdf->addressBlock(15, $blockY, 85, 'Émetteur', $companyLines);
$bottomR = $pdf->addressBlock(110, $blockY, 85, 'Destinataire', $clientLines);
$pdf->SetY(max($bottomL, $bottomR) + 5);

// =============================================================
// OBJET
// =============================================================
if (!empty($doc['objet'])) {
    $pdf->sectionLabel('Objet');
    $pdf->SetFont('Helvetica', '', 9);
    $pdf->SetTextColor(15, 23, 42);
    $pdf->SetX(15);
    $pdf->MultiCell(180, 4.5, $pdf->conv($doc['objet']));
    $pdf->Ln(3);
}

// =============================================================
// TABLEAU DES LIGNES
// =============================================================
if ($conf['tva_applicable']) {
    $colWidths    = [75, 14, 18, 24, 16, 33];
    $headers      = ['Désignation', 'Qté', 'Unité', 'P.U. HT', 'TVA %', 'Total HT'];
    $headerAligns = ['L', 'C', 'C', 'R', 'R', 'R'];
} else {
    $colWidths    = [90, 14, 18, 26, 0, 32];
    $headers      = ['Désignation', 'Qté', 'Unité', 'Prix unit.', '', 'Total'];
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
$pdf->SetFillColor(248, 250, 252);
$pdf->SetDrawColor(203, 213, 225);
$pdf->roundedRect($boxX, $totalsY, $boxW, $boxH, 2, 'DF');

$pdf->summaryLine('Total HT :', $pdf->eur((float)$doc['montant_ht']));

if ($conf['tva_applicable']) {
    $pdf->summaryLine('Total TVA :', $pdf->eur((float)$doc['montant_tva']));
}

$pdf->SetDrawColor(203, 213, 225);
$pdf->Line($boxX + 3, $pdf->GetY(), $boxX + $boxW - 3, $pdf->GetY());
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
    $pdf->summaryLine('Acompte (' . number_format($acomptePct, 0) . '%) :', $pdf->eur($acompte), false, [37, 99, 235], [37, 99, 235]);
    $pdf->summaryLine('Solde restant :', $pdf->eur($solde), true);
}

// =============================================================
// PAIEMENTS (factures)
// =============================================================
if ($type === 'facture' && (float)$doc['montant_paye'] > 0) {
    $pdf->Ln(2);
    $pdf->summaryLine('Déjà payé :', $pdf->eur((float)$doc['montant_paye']), false, [22, 163, 74], [22, 163, 74]);
    $reste = (float)$doc['montant_ttc'] - (float)$doc['montant_paye'];
    if ($reste > 0.01) {
        $pdf->summaryLine('Reste à payer :', $pdf->eur($reste), true, [220, 38, 38], [220, 38, 38]);
    }
}

$pdf->Ln(6);

// =============================================================
// NOTES
// =============================================================
if (!empty($doc['notes'])) {
    $pdf->sectionLabel('Notes');
    $pdf->SetFont('Helvetica', '', 8.5);
    $pdf->SetTextColor(71, 85, 105);
    $pdf->SetX(15);
    $pdf->MultiCell(180, 4.5, $pdf->conv($doc['notes']));
    $pdf->SetTextColor(15, 23, 42);
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

    $pdf->SetDrawColor(203, 213, 225);
    $pdf->SetFillColor(248, 250, 252);
    $pdf->SetLineWidth(0.3);
    $pdf->roundedRect(15, $startY, 180, 34, 3, 'DF');
    $pdf->SetLineWidth(0.2);

    $pdf->SetXY(20, $startY + 3);
    $pdf->SetFont('Helvetica', 'B', 9);
    $pdf->SetTextColor(30, 58, 95);
    $pdf->Cell(80, 5, $pdf->conv('BON POUR ACCORD'), 0, 1);

    $pdf->SetX(20);
    $pdf->SetFont('Helvetica', '', 7.5);
    $pdf->SetTextColor(71, 85, 105);
    $pdf->MultiCell(95, 3.5, $pdf->conv("Signature précédée de la mention «\u00a0Bon pour accord\u00a0»,\ncachet éventuel, date et nom du signataire."));

    $pdf->SetFont('Helvetica', '', 8);
    $pdf->SetTextColor(15, 23, 42);
    $pdf->SetXY(130, $startY + 4);
    $pdf->Cell(55, 4, $pdf->conv('Date : ........ / ........ / ............'), 0, 1);
    $pdf->SetXY(130, $startY + 13);
    $pdf->Cell(55, 4, $pdf->conv('Signature :'), 0, 1);
    $pdf->SetDrawColor(203, 213, 225);
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
    $pdf->SetTextColor(30, 58, 95);
    $pdf->Cell(0, 8, $pdf->conv('CONDITIONS GÉNÉRALES DE VENTE'), 0, 1, 'C');

    $pdf->SetDrawColor(37, 99, 235);
    $pdf->SetLineWidth(0.5);
    $pdf->Line(60, $pdf->GetY(), 150, $pdf->GetY());
    $pdf->SetLineWidth(0.2);
    $pdf->Ln(5);

    // Référence du document
    $pdf->SetFont('Helvetica', '', 8);
    $pdf->SetTextColor(100, 116, 139);
    $pdf->Cell(0, 4, $pdf->conv("Applicables au " . $docLabel . " N\xC2\xB0 " . $doc['numero'] . ' du ' . formatDate($dateValue)), 0, 1, 'C');
    $pdf->Ln(4);

    // Résumé des conditions
    $conditionsText = getConditionsDocumentVente($type === 'facture' ? 'facture' : $type, $doc);
    if (!empty($conditionsText)) {
        $pdf->SetFont('Helvetica', 'B', 9);
        $pdf->SetTextColor(30, 58, 95);
        $pdf->SetX(15);
        $pdf->Cell(180, 5, $pdf->conv('Résumé'), 0, 1, 'L');
        $pdf->Ln(1);
        $pdf->SetFont('Helvetica', '', 8);
        $pdf->SetTextColor(51, 65, 85);
        $pdf->SetX(15);
        $pdf->MultiCell(180, 4, $pdf->conv($conditionsText));
        $pdf->Ln(4);
    }

    // Conditions détaillées complètes
    $fullCGV = genererConditionsDocumentVenteCompletes($type === 'facture' ? 'facture' : $type, $doc);
    if (!empty($fullCGV)) {
        $pdf->SetFont('Helvetica', 'B', 9);
        $pdf->SetTextColor(30, 58, 95);
        $pdf->SetX(15);
        $pdf->Cell(180, 5, $pdf->conv('Conditions détaillées'), 0, 1, 'L');
        $pdf->SetDrawColor(226, 232, 240);
        $pdf->Line(15, $pdf->GetY(), 195, $pdf->GetY());
        $pdf->Ln(2);
        $pdf->SetFont('Helvetica', '', 7.5);
        $pdf->SetTextColor(71, 85, 105);
        $pdf->SetX(15);
        $pdf->MultiCell(180, 3.8, $pdf->conv($fullCGV));
    }

    // Mention d'acceptation
    $pdf->Ln(6);
    $pdf->SetDrawColor(203, 213, 225);
    $pdf->Line(15, $pdf->GetY(), 195, $pdf->GetY());
    $pdf->Ln(3);
    $pdf->SetFont('Helvetica', 'I', 7);
    $pdf->SetTextColor(100, 116, 139);
    $pdf->SetX(15);
    $pdf->MultiCell(180, 3.5, $pdf->conv(
        'Le client reconnaît avoir pris connaissance des présentes conditions générales de vente et les accepte sans réserve. '
        . 'Toute commande implique l\'adhésion pleine et entière aux présentes conditions.'
    ));
}

// =============================================================
// SORTIE
// =============================================================
$filename = $doc['numero'] . '.pdf';
$pdf->Output('I', $filename);

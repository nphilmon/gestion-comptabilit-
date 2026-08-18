<?php
/**
 * Composant PDF réutilisable — devis / factures / commandes.
 * Charte MD3 (mêmes tons que assets/style.css). Moteur : FPDF v1.85.
 *
 * Extrait de pdf_generator.php pour être réutilisable et testable
 * indépendamment (aucune dépendance à la base de données ici).
 */

require_once __DIR__ . '/fpdf.php';

class DocumentPDF extends FPDF
{
    public $entreprise   = [];
    public $docType      = '';
    public $docNumero    = '';
    public $legalLines   = [];
    public $logoPath     = null;
    public $cgvDocRef    = '';

    // Numéro de la première page en mode CGV (0 = pas de section CGV).
    // NB : on ne peut pas piloter cela avec un simple booléen togglé avant
    // AddPage() — FPDF appelle Footer() de la page sortante AVANT
    // d'incrémenter $this->page et d'appeler Header() de la nouvelle page,
    // donc un flag externe change "trop tôt" et fait perdre le pied de
    // page légal de la dernière page du document. On compare plutôt au
    // numéro de page courant, qui a la bonne valeur à chaque hook.
    public $cgvFirstPage = 0;

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

    // Répétition de l'en-tête de tableau sur les pages suivantes
    private $continuingTable  = false;
    private $tableHeadersState = [];
    private $tableWidthsState  = [];
    private $tableAlignsState  = [];

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
    function isOnCgvPage(): bool
    {
        return $this->cgvFirstPage > 0 && $this->page >= $this->cgvFirstPage;
    }

    function Header()
    {
        if ($this->isOnCgvPage()) {
            $this->headerCGV();
            return;
        }
        $this->headerDocument();
        if ($this->continuingTable) {
            $this->tableHeader($this->tableHeadersState, $this->tableWidthsState, $this->tableAlignsState);
        }
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
        if ($this->isOnCgvPage()) {
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

        // Nombre réel de lignes après retour à la ligne (une entrée longue,
        // ex. une raison sociale, peut occuper plusieurs lignes) : on
        // mesure avec la même police que celle utilisée lors du dessin.
        $wrappedLines = 0;
        foreach ($lines as $i => $line) {
            $this->SetFont('Helvetica', $i === 0 ? 'B' : '', $i === 0 ? 9 : 8);
            $wrappedLines += $this->nbLines($w - $padding * 2, $this->conv($line));
        }
        $contentH = $titleH + 3 + (max(1, $wrappedLines) * $lineH) + $padding;

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
        $this->tableHeadersState = $headers;
        $this->tableWidthsState  = $widths;
        $this->tableAlignsState  = $aligns;
        $this->continuingTable   = true;

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

    // À appeler une fois le tableau des lignes terminé, pour que les
    // sauts de page suivants (totaux, notes, CGV...) ne réaffichent plus
    // l'en-tête de colonnes.
    function endTable(): void
    {
        $this->continuingTable = false;
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

    function summaryLine(string $label, string $value, bool $bold = false, ?array $labelColor = null, ?array $valColor = null): void
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
        $candidate = __DIR__ . '/..' . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $candidate);
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

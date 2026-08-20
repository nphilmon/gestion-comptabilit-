<?php
/**
 * Composant PDF réutilisable — livres comptables (recettes, achats,
 * journal, grand livre, balance, trésorerie). Charte MD3, mêmes jetons de
 * couleur que lib/DocumentPdf.php. Moteur : FPDF v1.85.
 *
 * Extrait de livre_pdf.php pour être réutilisable et testable
 * indépendamment (aucune dépendance à la base de données ici).
 */

require_once __DIR__ . '/fpdf.php';

class LivrePDF extends FPDF
{
    public $titre = '';
    public $sousTitre = '';
    public $annee = 0;

    // Couleurs — mêmes jetons MD3 que DocumentPDF (lib/DocumentPdf.php)
    private $colPrimary     = [37, 99, 235];    // --md-primary
    private $colPrimaryDark = [30, 58, 95];     // --md-on-primary-container
    private $colPrimaryTint = [219, 234, 254];  // --md-primary-container
    private $colDark        = [15, 23, 42];     // --md-on-surface
    private $colMuted       = [71, 85, 105];    // --md-on-surface-variant
    private $colBorder      = [203, 213, 225];  // --md-outline
    private $colZebra       = [241, 245, 249];  // subtle row tint
    private $colSuccess     = [22, 163, 74];    // --md-success
    private $colError       = [220, 38, 38];    // --md-error

    // Répétition de l'en-tête de tableau sur les pages suivantes — voir
    // le même mécanisme et la même justification dans DocumentPdf.php.
    private $continuingTable   = false;
    private $tableHeadersState = [];
    private $tableWidthsState  = [];
    private $tableAlignsState  = [];

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

        if ($this->continuingTable) {
            $this->tableHeader($this->tableHeadersState, $this->tableWidthsState, $this->tableAlignsState);
        }
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
        $this->tableHeadersState = $headers;
        $this->tableWidthsState  = $widths;
        $this->tableAlignsState  = $aligns;
        $this->continuingTable   = true;

        $this->SetFont('Helvetica', 'B', 8);
        $this->SetFillColor(...$this->colPrimaryTint);
        $this->SetTextColor(...$this->colPrimaryDark);
        $this->SetDrawColor(...$this->colBorder);
        foreach ($headers as $i => $h) {
            $this->Cell($widths[$i], 7, $this->conv($h), 0, 0, $aligns[$i], true);
        }
        $this->Ln();
        $this->SetTextColor(...$this->colDark);
    }

    // À appeler une fois un tableau terminé, pour que les sauts de page
    // suivants (rapport suivant, section suivante...) ne réaffichent plus
    // cet en-tête de colonnes.
    function endTable(): void
    {
        $this->continuingTable = false;
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
        $this->SetFillColor(...$this->colPrimaryDark);
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

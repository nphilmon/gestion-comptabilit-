<?php
/**
 * Composant PDF réutilisable — Bulletin de paye.
 * Gabarit inspiré de la maquette légale du bulletin de paie clarifié,
 * avec les accents de couleur de la charte MD3 (mêmes jetons que
 * lib/DocumentPdf.php / lib/LivrePdf.php) utilisés avec la même
 * retenue que sur le reste de l'application : bandeau de titre,
 * bandes de section, en-tête de tableau et les deux montants clés
 * (net à payer, net payé) — le reste du document (grille, blocs
 * identité) reste neutre pour préserver la lisibilité et le rendu à
 * l'impression N&B de ce document légal dense.
 *
 * Extrait de bulletin_paie_pdf.php pour être réutilisable et testable
 * indépendamment (aucune dépendance à la base de données ici).
 */

require_once __DIR__ . '/fpdf.php';

class BulletinMaquettePDF extends FPDF
{
    // Couleurs — mêmes jetons MD3 que DocumentPDF / LivrePDF
    private array $colPrimary     = [37, 99, 235];    // --md-primary
    private array $colPrimaryDark = [30, 58, 95];     // --md-on-primary-container
    private array $colPrimaryTint = [219, 234, 254];  // --md-primary-container
    private array $colDark        = [15, 23, 42];     // --md-on-surface
    private array $colZebra       = [241, 245, 249];  // subtle row tint

    function conv(string $text): string {
        return mb_convert_encoding($text, 'Windows-1252', 'UTF-8');
    }

    function money(float $value): string {
        return number_format($value, 2, ',', ' ');
    }

    function Header() {}

    function Footer() {}

    function roundedRect(float $x, float $y, float $w, float $h, float $r, string $style = ''): void {
        $k = $this->k;
        $hp = $this->h;
        $op = $style === 'F' ? 'f' : (($style === 'FD' || $style === 'DF') ? 'B' : 'S');
        $myArc = 4 / 3 * (sqrt(2) - 1);

        $this->_out(sprintf('%.2F %.2F m', ($x + $r) * $k, ($hp - $y) * $k));
        $xc = $x + $w - $r; $yc = $y + $r;
        $this->_out(sprintf('%.2F %.2F l', $xc * $k, ($hp - $y) * $k));
        $this->arc($xc + $r * $myArc, $yc - $r, $xc + $r, $yc - $r * $myArc, $xc + $r, $yc);
        $xc = $x + $w - $r; $yc = $y + $h - $r;
        $this->_out(sprintf('%.2F %.2F l', ($x + $w) * $k, ($hp - $yc) * $k));
        $this->arc($xc + $r, $yc + $r * $myArc, $xc + $r * $myArc, $yc + $r, $xc, $yc + $r);
        $xc = $x + $r; $yc = $y + $h - $r;
        $this->_out(sprintf('%.2F %.2F l', $xc * $k, ($hp - ($y + $h)) * $k));
        $this->arc($xc - $r * $myArc, $yc + $r, $xc - $r, $yc + $r * $myArc, $xc - $r, $yc);
        $xc = $x + $r; $yc = $y + $r;
        $this->_out(sprintf('%.2F %.2F l', $x * $k, ($hp - $yc) * $k));
        $this->arc($xc - $r, $yc - $r * $myArc, $xc - $r * $myArc, $yc - $r, $xc, $yc - $r);
        $this->_out($op);
    }

    private function arc(float $x1, float $y1, float $x2, float $y2, float $x3, float $y3): void {
        $h = $this->h;
        $this->_out(sprintf(
            '%.2F %.2F %.2F %.2F %.2F %.2F c',
            $x1 * $this->k,
            ($h - $y1) * $this->k,
            $x2 * $this->k,
            ($h - $y2) * $this->k,
            $x3 * $this->k,
            ($h - $y3) * $this->k
        ));
    }

    function labelValue(float $x, float $y, string $label, string $value, float $labelW = 28): void {
        $this->SetXY($x, $y);
        $this->SetFont('Helvetica', '', 6.8);
        $this->Cell($labelW, 3.5, $this->conv($label . ' :'), 0, 0);
        $this->SetFont('Helvetica', 'B', 6.8);
        $this->Cell(0, 3.5, $this->conv($value), 0, 0);
    }

    // Bandeau de titre — accent bleu de marque (même traitement que le
    // badge de type de document sur les factures).
    function drawTopBar(string $period): void {
        $this->SetFillColor(...$this->colPrimary);
        $this->roundedRect(5, 6, 200, 7, 1.5, 'F');
        $this->SetTextColor(255, 255, 255);
        $this->SetFont('Helvetica', 'B', 7.2);
        $this->SetXY(8, 8);
        $this->Cell(55, 3, $this->conv('BULLETIN DE PAYE'), 0, 0);
        $this->SetFont('Helvetica', '', 6.8);
        $this->SetX(78);
        $this->Cell(30, 3, $this->conv('Période de paye'), 0, 0);
        $this->SetFont('Helvetica', 'B', 7);
        $this->Cell(55, 3, $this->conv($period), 0, 0);
        $this->SetTextColor(...$this->colDark);
        $this->SetDrawColor(0, 0, 0);
    }

    function drawIdentityBlocks(array $ent, array $emp, array $b): void {
        $this->SetDrawColor(0, 0, 0);

        $this->roundedRect(5, 16, 88, 30, 3);
        $this->SetFont('Helvetica', 'B', 8);
        $this->SetXY(8, 19);
        $this->MultiCell(80, 4, $this->conv($ent['nom']), 0, 'L');
        $this->SetFont('Helvetica', '', 6.8);
        $y = $this->GetY() + 1;
        foreach (array_filter([
            $ent['adresse'],
            trim($ent['cp'] . ' ' . $ent['ville']),
            $ent['siret'] ? 'SIRET : ' . $ent['siret'] : '',
            $ent['naf'] ? 'APE/NAF : ' . $ent['naf'] : '',
            $ent['urssaf'] ? 'URSSAF : ' . $ent['urssaf'] : '',
        ]) as $line) {
            $this->SetXY(8, $y);
            $this->Cell(80, 3.4, $this->conv($line), 0, 1);
            $y += 3.4;
        }

        $this->roundedRect(112, 32, 64, 28, 2);
        $this->SetFont('Helvetica', 'B', 8);
        $this->SetXY(115, 36);
        $this->MultiCell(58, 4, $this->conv($emp['nom']), 0, 'L');
        $this->SetFont('Helvetica', '', 6.8);
        $this->SetXY(115, 46);
        $this->Cell(58, 3.5, $this->conv($emp['poste']), 0, 1);
        $this->SetXY(115, 50);
        $this->Cell(58, 3.5, $this->conv($emp['email']), 0, 1);

        $this->roundedRect(5, 49, 88, 31, 3);
        $this->labelValue(8, 52, 'Date d\'entrée', paieDateLabel($emp['date_entree']));
        $this->labelValue(8, 56, 'Date ancienneté', paieDateLabel($emp['date_entree']));
        $this->labelValue(8, 60, 'Nature d\'emploi', $emp['poste']);
        $this->labelValue(8, 64, 'Statut catégorie', getParam('statut_categorie_paie', ''));
        $this->labelValue(8, 71, 'N° S.S.', $emp['secu']);
        $this->labelValue(8, 75, 'Service', getParam('service_paie', ''));
        $this->labelValue(66, 64, 'Position', getParam('position_paie', ''), 19);
        $this->labelValue(66, 68, 'Niveau', getParam('niveau_paie', ''), 19);
        $this->labelValue(66, 72, 'Échelon', getParam('echelon_paie', ''), 19);
        $this->labelValue(66, 76, 'Coefficient', getParam('coefficient_paie', ''), 19);

        $this->SetXY(5, 83);
        $this->SetFont('Helvetica', '', 6.7);
        $this->Cell(200, 3.5, $this->conv('CCN : ' . ($ent['convention'] ?: '-') . '    Durée CP : ' . getParam('cp_mode_decompte', 'ouvrables')), 0, 1);
    }

    // En-tête du tableau des cotisations — teinté comme les en-têtes de
    // colonnes des autres documents PDF de l'application.
    function drawTableHeader(float $y): void {
        $this->SetXY(5, $y);
        $this->SetFont('Helvetica', '', 6.6);
        $this->SetDrawColor(0, 0, 0);
        $this->SetFillColor(...$this->colPrimaryTint);
        $this->SetTextColor(...$this->colPrimaryDark);
        $this->Cell(130, 8, $this->conv('Libellé'), 1, 0, 'C', true);
        $this->Cell(19, 8, 'Base', 1, 0, 'C', true);
        $this->Cell(13, 8, 'Taux', 1, 0, 'C', true);
        $x = $this->GetX();
        $this->Cell(19, 8, '', 1, 0, 'C', true);
        $this->SetXY($x, $y + 1);
        $this->Cell(19, 3, 'Part', 0, 0, 'C');
        $this->SetXY($x, $y + 4);
        $this->Cell(19, 3, $this->conv('Salarié'), 0, 0, 'C');
        $x += 19;
        $this->SetXY($x, $y);
        $this->Cell(19, 8, '', 1, 0, 'C', true);
        $this->SetXY($x, $y + 1);
        $this->Cell(19, 3, 'Part', 0, 0, 'C');
        $this->SetXY($x, $y + 4);
        $this->Cell(19, 3, 'Employeur', 0, 1, 'C');
        $this->SetY($y + 8);
        $this->SetTextColor(...$this->colDark);
    }

    function row(string $label, string $base = '', string $rate = '', string $employee = '', string $employer = '', bool $bold = false, bool $fill = false): void {
        $h = 5;
        if ($this->GetY() + $h > 210) {
            return;
        }
        $this->SetFillColor(...$this->colZebra);
        $this->SetFont('Helvetica', $bold ? 'B' : '', 6.7);
        $this->Cell(130, $h, $this->conv($label), 'LR', 0, 'L', $fill);
        $this->Cell(19, $h, $this->conv($base), 'LR', 0, 'R', $fill);
        $this->Cell(13, $h, $this->conv($rate), 'LR', 0, 'R', $fill);
        $this->Cell(19, $h, $this->conv($employee), 'LR', 0, 'R', $fill);
        $this->Cell(19, $h, $this->conv($employer), 'LR', 1, 'R', $fill);
    }

    // Bande de section — accent bleu de marque, même traitement que
    // sectionLabel() sur les factures (fond teinté, texte bleu foncé).
    function section(string $label): void {
        $this->SetFont('Helvetica', 'B', 6.8);
        $this->SetFillColor(...$this->colPrimaryTint);
        $this->SetTextColor(...$this->colPrimaryDark);
        $this->Cell(200, 5, $this->conv($label), 'LR', 1, 'L', true);
        $this->SetTextColor(...$this->colDark);
    }

    function closeTable(float $startY, float $endY): void {
        $this->Line(5, $endY, 205, $endY);
        foreach ([135, 154, 167, 186, 205] as $x) {
            $this->Line($x, $startY, $x, $endY);
        }
        // Trait vertical épais côté gauche (maquette)
        $this->SetLineWidth(1.2);
        $this->Line(5, $startY, 5, $endY);
        $this->SetLineWidth(0.2);
    }

    // Montant clé n°1 — net à payer avant impôt : accent bleu de marque
    // sur la ligne principale uniquement (les lignes secondaires restent
    // neutres).
    function drawNetBeforeTax(float $y, array $b): void {
        $this->SetY($y);
        $this->SetFillColor(...$this->colPrimaryTint);
        $this->SetTextColor(...$this->colPrimaryDark);
        $this->SetFont('Helvetica', 'BI', 8.2);
        $this->Cell(186, 6, $this->conv('Net à payer avant impôt sur le revenu'), 1, 0, 'L', true);
        $this->SetFont('Helvetica', 'B', 8.4);
        $this->Cell(14, 6, $this->conv($this->money($b['net_a_payer'])), 1, 1, 'R', true);
        $this->SetTextColor(...$this->colDark);

        $this->SetFont('Helvetica', '', 6);
        $this->Cell(200, 4, $this->conv('Dont évolution de la rémunération liée à la suppression des cotisations salariales chômage et maladie'), 'LR', 1, 'L');

        $this->SetFont('Helvetica', 'B', 6.5);
        $this->Cell(186, 4.5, $this->conv('Montant net social'), 'LRB', 0, 'L');
        $this->Cell(14, 4.5, $this->conv($this->money($b['montant_net_social'])), 'LRB', 1, 'R');
    }

    function drawIncomeTax(float $y, array $b): void {
        $this->SetY($y);
        $this->SetFont('Helvetica', 'BI', 6.7);
        $this->Cell(80, 5, $this->conv('Impôt sur le revenu'), 1, 0, 'L');
        $this->Cell(21, 5, 'Base', 1, 0, 'C');
        $this->Cell(26, 5, 'Taux', 1, 0, 'C');
        $this->Cell(34, 5, 'Montant', 1, 0, 'C');
        $this->Cell(39, 5, $this->conv('Montant cumulé'), 1, 1, 'C');

        $this->SetFont('Helvetica', '', 6.5);
        $this->Cell(80, 5, $this->conv('Impôt sur le revenu prélevé à la source'), 1, 0, 'L');
        $this->Cell(21, 5, $this->money($b['net_imposable']), 1, 0, 'R');
        $this->Cell(26, 5, '0,00 %', 1, 0, 'R');
        $this->Cell(34, 5, '0,00', 1, 0, 'R');
        $this->Cell(39, 5, '0,00', 1, 1, 'R');

        $this->Cell(80, 5, $this->conv('Net imposable'), 1, 0, 'L');
        $this->Cell(120, 5, $this->money($b['net_imposable']), 1, 1, 'R');
        $this->Cell(80, 5, $this->conv('Montant net des heures Comp/Supp exonérées'), 1, 0, 'L');
        $this->Cell(120, 5, $this->money($b['montant_hs']), 1, 1, 'R');
    }

    function drawBottom(float $y, array $emp, array $b): void {
        $boxH  = 18;
        $rowH  = 5;
        $cols  = [24, 27, 31, 28, 32, 28, 30];
        $heads = ['Mois', 'Plafond S.S.', 'Heures trav.', 'Jours trav.', 'Brut S.S.', 'Allég. cotis.', 'Coût employeur'];

        // ── Boîtes congés / dates (neutres) ────────────────────────────
        $this->roundedRect(5, $y, 40, $boxH, 3);
        $this->SetFont('Helvetica', 'B', 6.3);
        $this->SetXY(7, $y + 2);
        $this->Cell(36, 4, $this->conv('Congés payés'), 0, 1, 'L');
        $this->SetFont('Helvetica', '', 6.3);
        foreach (['Acquis N-1 :', 'Acquis en cours N :', 'Pris N-1 :', 'Reste N-1 :'] as $lbl) {
            $this->SetX(7);
            $this->Cell(36, 3.2, $this->conv($lbl), 0, 1, 'L');
        }

        $this->roundedRect(49, $y, 46, $boxH, 3);
        $this->SetFont('Helvetica', '', 6.3);
        $this->SetXY(51, $y + 2);
        $this->Cell(42, 4, $this->conv('Dates de congés payés'), 0, 1, 'C');

        // ── Boîte net payé — montant clé n°2, accent bleu de marque ────
        $this->SetFillColor(...$this->colPrimary);
        $this->roundedRect(144, $y, 61, $boxH, 3, 'F');
        $this->SetTextColor(255, 255, 255);
        $this->SetXY(146, $y + 2);
        $this->SetFont('Helvetica', 'B', 7.5);
        $this->Cell(57, 4.5, $this->conv('Net payé : ' . $this->money($b['net_a_payer'])), 0, 1, 'R');
        $this->SetFont('Helvetica', '', 6.5);
        $this->SetXY(146, $y + 9);
        $this->Cell(18, 4, 'Banque :', 0, 0);
        $this->Cell(39, 4, $this->conv($b['mode_paiement']), 0, 1, 'R');
        $this->SetXY(146, $y + 13.5);
        $this->Cell(18, 4, 'IBAN :', 0, 0);
        $this->Cell(39, 4, $this->conv($emp['iban']), 0, 1, 'R');
        $this->SetTextColor(...$this->colDark);

        // ── Tableau récapitulatif ──────────────────────────────────────
        $tableY = $y + $boxH + 1;
        $this->SetY($tableY);
        $this->SetFont('Helvetica', 'B', 6.2);
        foreach ($heads as $i => $head) {
            $this->Cell($cols[$i], $rowH, $this->conv($head), 1, 0, 'C');
        }
        $this->Ln();

        $this->SetFont('Helvetica', '', 6.2);
        // Ligne Mois
        $this->Cell($cols[0], $rowH, $this->conv('Mois'), 1, 0, 'C');
        $this->Cell($cols[1], $rowH, '', 1, 0, 'R');
        $this->Cell($cols[2], $rowH, $this->money($b['heures_travaillees']), 1, 0, 'R');
        $this->Cell($cols[3], $rowH, '', 1, 0, 'R');
        $this->Cell($cols[4], $rowH, $this->money($b['salaire_brut']), 1, 0, 'R');
        $this->Cell($cols[5], $rowH, '', 1, 0, 'R');
        $this->Cell($cols[6], $rowH, $this->money($b['cout_employeur']), 1, 1, 'R');
        // Ligne Cumul
        $this->Cell($cols[0], $rowH, $this->conv('Cumul'), 1, 0, 'C');
        $this->Cell($cols[1], $rowH, '', 1, 0, 'R');
        $this->Cell($cols[2], $rowH, $this->money($b['heures_travaillees']), 1, 0, 'R');
        $this->Cell($cols[3], $rowH, '', 1, 0, 'R');
        $this->Cell($cols[4], $rowH, $this->money($b['salaire_brut']), 1, 0, 'R');
        $this->Cell($cols[5], $rowH, '', 1, 0, 'R');
        $this->Cell($cols[6], $rowH, $this->money($b['cout_employeur']), 1, 1, 'R');

        // ── Pied de page légal ─────────────────────────────────────────
        $this->SetY($tableY + $rowH * 3 + 2);
        $this->SetFont('Helvetica', '', 6);
        $this->Cell(0, 3.5, $this->conv('Pour faire valoir vos droits, conservez ce bulletin sans limitation de durée.'), 0, 1, 'C');
        $this->Cell(0, 3.5, $this->conv("Pour plus d'informations sur le bulletin clarifié, voir la rubrique dédiée sur www.service-public.fr"), 0, 0, 'C');
    }
}

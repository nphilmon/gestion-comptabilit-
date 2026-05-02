<?php
/**
 * Générateur PDF — Bulletin de paye
 * Gabarit noir/blanc inspiré de la maquette fournie.
 */
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/functions_paie.php';
requireLogin();
require_once __DIR__ . '/lib/fpdf.php';

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    die('Paramètre invalide.');
}

$currentUser = getCurrentUser();
$canView = in_array($currentUser['role'] ?? '', ['admin', 'comptable'], true);

$bulletin = getPaieBulletin($id);
if (!$bulletin) {
    die('Bulletin introuvable.');
}

if (!$canView && (int) $bulletin['user_id'] !== (int) $currentUser['id']) {
    die('Accès refusé.');
}

$ent = [
    'nom' => getParam('nom_entreprise', 'Mon Entreprise'),
    'adresse' => getParam('adresse', ''),
    'cp' => getParam('code_postal', ''),
    'ville' => getParam('ville', ''),
    'siret' => getParam('siret', ''),
    'naf' => getParam('code_naf', ''),
    'urssaf' => getParam('numero_urssaf', ''),
    'convention' => getParam('convention_collective', ''),
];

$emp = [
    'nom' => (string) ($bulletin['user_nom'] ?? ''),
    'email' => (string) ($bulletin['user_email'] ?? ''),
    'matricule' => (string) ($bulletin['matricule'] ?? ''),
    'poste' => (string) ($bulletin['poste'] ?? ''),
    'secu' => (string) ($bulletin['numero_securite_sociale'] ?? ''),
    'iban' => (string) ($bulletin['iban'] ?? ''),
    'date_entree' => (string) ($bulletin['date_entree'] ?? ''),
];

$b = [
    'periode' => (string) $bulletin['periode'],
    'date_paiement' => (string) $bulletin['date_paiement'],
    'mode_paiement' => (string) $bulletin['mode_paiement'],
    'reference_paiement' => (string) ($bulletin['reference_paiement'] ?? ''),
    'statut' => (string) $bulletin['statut'],
    'salaire_base_brut' => (float) $bulletin['salaire_base_brut'],
    'heures_travaillees' => (float) $bulletin['heures_travaillees'],
    'heures_supp' => (float) $bulletin['heures_supplementaires'],
    'taux_maj' => (float) $bulletin['taux_horaire_majore'],
    'montant_hs' => (float) $bulletin['montant_heures_supplementaires'],
    'prime' => (float) $bulletin['prime'],
    'bonus' => (float) $bulletin['bonus'],
    'indemnites' => (float) $bulletin['indemnites'],
    'indemnite_sante' => (float) ($bulletin['indemnite_sante'] ?? 0),
    'ancv_ce' => (float) ($bulletin['ancv_ce'] ?? 0),
    'cotis_salar' => (float) $bulletin['cotisations_salariales'],
    'cotis_patron' => (float) $bulletin['cotisations_patronales'],
    'retenues' => (float) $bulletin['retenues'],
    'salaire_brut' => (float) $bulletin['salaire_brut'],
    'net_imposable' => (float) $bulletin['net_imposable'],
    'net_a_payer' => (float) $bulletin['net_a_payer'],
    'cout_employeur' => (float) $bulletin['cout_total_employeur'],
    'notes' => (string) ($bulletin['notes'] ?? ''),
];

function paieMoney(float $value): string {
    return number_format($value, 2, ',', ' ');
}

function paieRate(float $value): string {
    return number_format($value, 2, ',', ' ') . ' %';
}

function paiePeriodLabel(string $period): string {
    return preg_match('/^\d{4}-\d{2}$/', $period) ? formatDateMois($period . '-01') : $period;
}

function paieDateLabel(?string $date): string {
    return $date ? date('d/m/Y', strtotime($date)) : '';
}

class BulletinMaquettePDF extends FPDF
{
    private array $black = [0, 0, 0];
    private array $grey = [90, 90, 90];
    private array $light = [245, 245, 245];

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

    function drawTopBar(string $period): void {
        $this->SetDrawColor(0, 0, 0);
        $this->SetLineWidth(0.25);
        $this->roundedRect(5, 6, 200, 7, 1.5);
        $this->SetFont('Helvetica', 'B', 7.2);
        $this->SetXY(8, 8);
        $this->Cell(55, 3, $this->conv('BULLETIN DE PAYE'), 0, 0);
        $this->SetFont('Helvetica', '', 6.8);
        $this->SetX(78);
        $this->Cell(30, 3, $this->conv('Période de paye'), 0, 0);
        $this->SetFont('Helvetica', 'B', 7);
        $this->Cell(55, 3, $this->conv($period), 0, 0);
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
        $this->labelValue(66, 72, 'Echelon', getParam('echelon_paie', ''), 19);
        $this->labelValue(66, 76, 'Coefficient', getParam('coefficient_paie', ''), 19);

        $this->SetXY(5, 83);
        $this->SetFont('Helvetica', '', 6.7);
        $this->Cell(200, 3.5, $this->conv('CCN : ' . ($ent['convention'] ?: '-') . '    Durée CP : ' . getParam('cp_mode_decompte', 'ouvrables')), 0, 1);
    }

    function drawTableHeader(float $y): void {
        $this->SetXY(5, $y);
        $this->SetFont('Helvetica', '', 6.6);
        $this->SetDrawColor(0, 0, 0);
        $this->Cell(130, 8, $this->conv('Libellé'), 1, 0, 'C');
        $this->Cell(19, 8, 'Base', 1, 0, 'C');
        $this->Cell(13, 8, 'Taux', 1, 0, 'C');
        $x = $this->GetX();
        $this->Cell(19, 8, '', 1, 0, 'C');
        $this->SetXY($x, $y + 1);
        $this->Cell(19, 3, 'Part', 0, 0, 'C');
        $this->SetXY($x, $y + 4);
        $this->Cell(19, 3, $this->conv('Salarié'), 0, 0, 'C');
        $x += 19;
        $this->SetXY($x, $y);
        $this->Cell(19, 8, '', 1, 0, 'C');
        $this->SetXY($x, $y + 1);
        $this->Cell(19, 3, 'Part', 0, 0, 'C');
        $this->SetXY($x, $y + 4);
        $this->Cell(19, 3, 'Employeur', 0, 1, 'C');
        $this->SetY($y + 8);
    }

    function row(string $label, string $base = '', string $rate = '', string $employee = '', string $employer = '', bool $bold = false, bool $fill = false): void {
        $h = 5;
        if ($this->GetY() + $h > 210) {
            return;
        }
        $this->SetFillColor(...$this->light);
        $this->SetFont('Helvetica', $bold ? 'B' : '', 6.7);
        $this->Cell(130, $h, $this->conv($label), 'LR', 0, 'L', $fill);
        $this->Cell(19, $h, $this->conv($base), 'LR', 0, 'R', $fill);
        $this->Cell(13, $h, $this->conv($rate), 'LR', 0, 'R', $fill);
        $this->Cell(19, $h, $this->conv($employee), 'LR', 0, 'R', $fill);
        $this->Cell(19, $h, $this->conv($employer), 'LR', 1, 'R', $fill);
    }

    function section(string $label): void {
        $this->SetFont('Helvetica', 'B', 6.8);
        $this->SetFillColor(235, 235, 235);
        $this->Cell(200, 5, $this->conv($label), 'LR', 1, 'L', true);
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

    function drawNetBeforeTax(float $y, array $b): void {
        $this->SetY($y);
        $this->SetFont('Helvetica', 'BI', 8.2);
        $this->Cell(186, 6, $this->conv('Net à payer avant impôt sur le revenu'), 1, 0, 'L');
        $this->SetFont('Helvetica', 'B', 8.4);
        $this->Cell(14, 6, $this->conv($this->money($b['net_a_payer'])), 1, 1, 'R');

        $this->SetFont('Helvetica', '', 6);
        $this->Cell(200, 4, $this->conv('Dont évolution de la rémunération liée à la suppression des cotisations salariales chômage et maladie'), 'LR', 1, 'L');
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

        // ── Boîtes congés / dates / net payé ──────────────────────────
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

        $this->roundedRect(144, $y, 61, $boxH, 3);
        $this->SetXY(146, $y + 2);
        $this->SetFont('Helvetica', 'B', 6.7);
        $this->Cell(57, 4.5, $this->conv('Net payé : ' . $this->money($b['net_a_payer'])), 0, 1, 'R');
        $this->SetFont('Helvetica', '', 6.5);
        $this->SetXY(146, $y + 9);
        $this->Cell(18, 4, 'Banque :', 0, 0);
        $this->Cell(39, 4, $this->conv($b['mode_paiement']), 0, 1, 'R');
        $this->SetXY(146, $y + 13.5);
        $this->Cell(18, 4, 'IBAN :', 0, 0);
        $this->Cell(39, 4, $this->conv($emp['iban']), 0, 1, 'R');

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

$pdf = new BulletinMaquettePDF('P', 'mm', 'A4');
$pdf->AliasNbPages();
$pdf->SetMargins(5, 6, 5);
$pdf->SetAutoPageBreak(false);
$pdf->AddPage();
$pdf->SetTextColor(0, 0, 0);
$pdf->SetDrawColor(0, 0, 0);
$pdf->SetLineWidth(0.2);

$pdf->drawTopBar(paiePeriodLabel($b['periode']));
$pdf->drawIdentityBlocks($ent, $emp, $b);
$tableStart = 88;
$pdf->drawTableHeader($tableStart);

$tauxHoraire = $b['heures_travaillees'] > 0 ? $b['salaire_base_brut'] / $b['heures_travaillees'] : 0;
$tauxSalar = $b['salaire_brut'] > 0 ? $b['cotis_salar'] / $b['salaire_brut'] * 100 : 0;
$tauxPatron = $b['salaire_brut'] > 0 ? $b['cotis_patron'] / $b['salaire_brut'] * 100 : 0;

$pdf->section('Rémunération brute');
$pdf->row('Salaire de base', number_format($b['heures_travaillees'], 2, ',', ' '), $tauxHoraire > 0 ? paieMoney($tauxHoraire) : '', paieMoney($b['salaire_base_brut']));
if ($b['heures_supp'] > 0) {
    $pdf->row('Heures supplémentaires', number_format($b['heures_supp'], 2, ',', ' '), paieMoney($b['taux_maj']), paieMoney($b['montant_hs']), '', false, true);
}
if ($b['prime'] > 0) $pdf->row('Prime', '', '', paieMoney($b['prime']));
if ($b['bonus'] > 0) $pdf->row('Bonus', '', '', paieMoney($b['bonus']), '', false, true);
if ($b['indemnites'] > 0) $pdf->row('Indemnités', '', '', paieMoney($b['indemnites']));
if ($b['indemnite_sante'] > 0) $pdf->row('Indem. compl. santé', '', '', paieMoney($b['indemnite_sante']), '', false, true);
if ($b['ancv_ce'] > 0) $pdf->row("ANCV / Comité d'entreprise", '', '', paieMoney($b['ancv_ce']));
$pdf->row('Salaire brut', '', '', paieMoney($b['salaire_brut']), '', true, true);

$pdf->section('Santé');
$pdf->row('Sécurité sociale - Maladie maternité invalidité décès', paieMoney($b['salaire_brut']), paieRate($tauxSalar), '-' . paieMoney($b['cotis_salar']), '', false);
$pdf->row('Complémentaire santé / prévoyance', '', '', '', '', false, true);

$pdf->section('Accidents du travail - Maladies professionnelles');
$pdf->row('Accidents du travail', paieMoney($b['salaire_brut']), '', '', paieMoney(0));

$pdf->section('Retraite');
$pdf->row('Retraite plafonnée / déplafonnée', paieMoney($b['salaire_brut']), '', '', '', false, true);
$pdf->row('Retraite complémentaire', paieMoney($b['salaire_brut']), '', '', '');

$pdf->section('Famille - Assurance chômage - Autres contributions');
$pdf->row('Cotisations patronales', paieMoney($b['salaire_brut']), paieRate($tauxPatron), '', paieMoney($b['cotis_patron']), false, true);

if ($b['retenues'] > 0) {
    $pdf->section('Retenues diverses');
    $pdf->row('Retenues', '', '', '-' . paieMoney($b['retenues']), '');
}

$pdf->row('Total des cotisations et contributions', '', '', '-' . paieMoney($b['cotis_salar']), paieMoney($b['cotis_patron']), true, true);
$pdf->row('Net imposable', '', '', paieMoney($b['net_imposable']), '', true);
$tableEnd = max($pdf->GetY(), 212);
$pdf->closeTable($tableStart + 8, $tableEnd);

$netY = max($tableEnd + 2, 214);
$taxY = $netY + 11;
$botY = $taxY + 22;

$pdf->drawNetBeforeTax($netY, $b);
$pdf->drawIncomeTax($taxY, $b);

if ($b['notes'] !== '') {
    $noteY = $taxY + 21;
    $pdf->SetXY(5, $noteY);
    $pdf->SetFont('Helvetica', 'I', 6);
    $pdf->MultiCell(200, 3, $pdf->conv('Note : ' . $b['notes']), 0, 'L');
}

$pdf->drawBottom($botY, $emp, $b);

$periode = preg_replace('/[^0-9\-]/', '', $b['periode']);
$nomFichier = 'bulletin-paie-' . $periode . '-' . preg_replace('/[^a-z0-9]/i', '-', $emp['nom']) . '.pdf';
$pdf->Output('I', $nomFichier);

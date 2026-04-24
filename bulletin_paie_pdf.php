<?php
/**
 * Générateur PDF — Bulletin de Paie (fiche de salaire officielle)
 * Style conforme au modèle réglementaire français
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
$canView     = in_array($currentUser['role'] ?? '', ['admin', 'comptable'], true);

$bulletin = getPaieBulletin($id);
if (!$bulletin) {
    die('Bulletin introuvable.');
}

// Un employé ne peut voir que son propre bulletin
if (!$canView && (int) $bulletin['user_id'] !== (int) $currentUser['id']) {
    die('Accès refusé.');
}

// =====================================================================
// Données entreprise & employé
// =====================================================================
$ent = [
    'nom'         => getParam('nom_entreprise', 'Mon Entreprise'),
    'adresse'     => getParam('adresse', ''),
    'cp'          => getParam('code_postal', ''),
    'ville'       => getParam('ville', ''),
    'siret'       => getParam('siret', ''),
    'naf'         => getParam('code_naf', ''),
    'tel'         => getParam('telephone', ''),
    'email'       => getParam('email', ''),
    'urssaf'      => getParam('numero_urssaf', ''),
    'convention'  => getParam('convention_collective', ''),
];

$emp = [
    'nom'         => $bulletin['user_nom'] ?? '',
    'email'       => $bulletin['user_email'] ?? '',
    'matricule'   => $bulletin['matricule'] ?? '',
    'poste'       => $bulletin['poste'] ?? '',
    'secu'        => $bulletin['numero_securite_sociale'] ?? '',
    'iban'        => $bulletin['iban'] ?? '',
];

// Calculs depuis le bulletin
$b = [
    'periode'              => (string) $bulletin['periode'],
    'date_paiement'        => (string) $bulletin['date_paiement'],
    'mode_paiement'        => (string) $bulletin['mode_paiement'],
    'reference_paiement'   => (string) ($bulletin['reference_paiement'] ?? ''),
    'statut'               => (string) $bulletin['statut'],
    'salaire_base_brut'    => (float)  $bulletin['salaire_base_brut'],
    'heures_travaillees'   => (float)  $bulletin['heures_travaillees'],
    'heures_supp'          => (float)  $bulletin['heures_supplementaires'],
    'taux_maj'             => (float)  $bulletin['taux_horaire_majore'],
    'montant_hs'           => (float)  $bulletin['montant_heures_supplementaires'],
    'prime'                => (float)  $bulletin['prime'],
    'bonus'                => (float)  $bulletin['bonus'],
    'indemnites'           => (float)  $bulletin['indemnites'],
    'cotis_salar'          => (float)  $bulletin['cotisations_salariales'],
    'cotis_patron'         => (float)  $bulletin['cotisations_patronales'],
    'retenues'             => (float)  $bulletin['retenues'],
    'salaire_brut'         => (float)  $bulletin['salaire_brut'],
    'net_imposable'        => (float)  $bulletin['net_imposable'],
    'net_a_payer'          => (float)  $bulletin['net_a_payer'],
    'cout_employeur'       => (float)  $bulletin['cout_total_employeur'],
    'notes'                => (string) ($bulletin['notes'] ?? ''),
];

$taux_salar  = $b['salaire_brut'] > 0 ? round($b['cotis_salar']  / $b['salaire_brut'] * 100, 2) : 0;
$taux_patron = $b['salaire_brut'] > 0 ? round($b['cotis_patron'] / $b['salaire_brut'] * 100, 2) : 0;

function eur(float $v): string {
    return number_format($v, 2, ',', ' ') . ' EUR';
}
function pct(float $v): string {
    return number_format($v, 2, ',', ' ') . ' %';
}
function h(float $v): string {
    return number_format($v, 2, ',', ' ') . ' h';
}

// =====================================================================
// Classe PDF Bulletin de Paie
// =====================================================================
class BulletinPDF extends FPDF
{
    private array $colBleu   = [0,   51,  102];
    private array $colDark   = [33,  33,  33];
    private array $colGrey   = [100, 100, 100];
    private array $colLight  = [150, 150, 150];
    private array $colBorder = [180, 180, 180];
    private array $colBg     = [245, 247, 250];
    private array $colGreen  = [0,   100, 50];
    private array $colBleuBg = [0,   51,  102];

    function conv(string $s): string {
        return mb_convert_encoding($s, 'Windows-1252', 'UTF-8');
    }

    function eur(float $v): string {
        return number_format($v, 2, ',', ' ') . ' EUR';
    }

    function Header() {}
    function Footer() {
        $this->SetY(-12);
        $this->SetDrawColor(...$this->colBorder);
        $this->SetLineWidth(0.2);
        $this->Line(10, $this->GetY(), 200, $this->GetY());
        $this->Ln(2);
        $this->SetFont('Helvetica', '', 6.5);
        $this->SetTextColor(...$this->colLight);
        $this->Cell(0, 4, $this->conv('Bulletin de paie confidentiel — à conserver sans limitation de durée'), 0, 0, 'C');
    }

    // ---- En-tête du document ------------------------------------------
    function drawHeader(array $ent, array $emp, string $periode, string $datePaiement): void
    {
        $this->SetY(10);

        // ---- Bloc EMPLOYEUR (gauche) -----------------------------------
        $this->SetFont('Helvetica', 'B', 11);
        $this->SetTextColor(...$this->colBleu);
        $this->SetX(10);
        $this->Cell(90, 6, $this->conv($ent['nom']), 0, 1);

        $this->SetFont('Helvetica', '', 8);
        $this->SetTextColor(...$this->colDark);
        $lines = [];
        if ($ent['adresse'] !== '') $lines[] = $ent['adresse'];
        if ($ent['cp'] !== '' || $ent['ville'] !== '') $lines[] = trim($ent['cp'] . ' ' . $ent['ville']);
        if ($ent['tel'] !== '') $lines[] = 'Tél. : ' . $ent['tel'];
        if ($ent['email'] !== '') $lines[] = $ent['email'];
        foreach ($lines as $l) {
            $this->SetX(10);
            $this->Cell(90, 4, $this->conv($l), 0, 1);
        }

        $infoY = $this->GetY() + 1;
        if ($ent['siret'] !== '') {
            $this->SetFont('Helvetica', '', 7.5);
            $this->SetTextColor(...$this->colGrey);
            $this->SetXY(10, $infoY);
            $this->Cell(90, 3.5, 'SIRET : ' . $ent['siret'], 0, 1);
            $infoY += 3.5;
        }
        if ($ent['naf'] !== '') {
            $this->SetXY(10, $infoY);
            $this->Cell(90, 3.5, 'Code APE/NAF : ' . $ent['naf'], 0, 1);
            $infoY += 3.5;
        }
        if ($ent['urssaf'] !== '') {
            $this->SetXY(10, $infoY);
            $this->Cell(90, 3.5, 'N° URSSAF : ' . $ent['urssaf'], 0, 1);
            $infoY += 3.5;
        }
        if ($ent['convention'] !== '') {
            $this->SetXY(10, $infoY);
            $this->SetFont('Helvetica', 'I', 7);
            $this->Cell(90, 3.5, 'Conv. coll. : ' . $ent['convention'], 0, 1);
        }

        // ---- Bloc BULLETIN DE PAIE (droite titre) ----------------------
        $this->SetFont('Helvetica', 'B', 16);
        $this->SetTextColor(...$this->colBleu);
        $this->SetXY(110, 10);
        $this->Cell(90, 8, $this->conv('BULLETIN DE PAIE'), 0, 1, 'R');

        // Période et date de paiement
        $periodeLabel = '';
        if (preg_match('/^(\d{4})-(\d{2})$/', $periode, $m)) {
            $moisFr = [
                '01' => 'Janvier', '02' => 'Février', '03' => 'Mars', '04' => 'Avril',
                '05' => 'Mai', '06' => 'Juin', '07' => 'Juillet', '08' => 'Août',
                '09' => 'Septembre', '10' => 'Octobre', '11' => 'Novembre', '12' => 'Décembre',
            ];
            $periodeLabel = ($moisFr[$m[2]] ?? $m[2]) . ' ' . $m[1];
        }
        $this->SetFont('Helvetica', 'B', 10);
        $this->SetTextColor(...$this->colDark);
        $this->SetXY(110, 20);
        $this->Cell(90, 5, $this->conv('Période : ' . $periodeLabel), 0, 1, 'R');

        $this->SetFont('Helvetica', '', 8.5);
        $this->SetXY(110, 26);
        $this->Cell(90, 4.5, $this->conv('Date de paiement : ' . date('d/m/Y', strtotime($datePaiement))), 0, 1, 'R');

        // ---- Bloc EMPLOYÉ (bas droite) ---------------------------------
        $empY = max($this->GetY() + 2, 38);
        $this->SetXY(110, $empY);
        $this->SetFont('Helvetica', 'B', 7);
        $this->SetTextColor(...$this->colGrey);
        $this->Cell(90, 4, $this->conv('EMPLOYÉ'), 0, 1, 'L');

        $this->SetFont('Helvetica', 'B', 9.5);
        $this->SetTextColor(...$this->colDark);
        $this->SetXY(110, $empY + 4);
        $this->Cell(90, 5, $this->conv($emp['nom']), 0, 1);

        $this->SetFont('Helvetica', '', 8);
        $curY = $this->GetY();
        if ($emp['poste'] !== '') {
            $this->SetXY(110, $curY);
            $this->Cell(90, 4, $this->conv('Poste : ' . $emp['poste']), 0, 1);
            $curY = $this->GetY();
        }
        if ($emp['matricule'] !== '') {
            $this->SetXY(110, $curY);
            $this->Cell(90, 4, $this->conv('Matricule : ' . $emp['matricule']), 0, 1);
            $curY = $this->GetY();
        }
        if ($emp['secu'] !== '') {
            $this->SetFont('Helvetica', '', 7.5);
            $this->SetTextColor(...$this->colGrey);
            $this->SetXY(110, $curY);
            $this->Cell(90, 3.5, $this->conv('N° Sécurité sociale : ' . $emp['secu']), 0, 1);
        }

        // ---- Séparateur ------------------------------------------------
        $sepY = max($this->GetY() + 3, 58);
        $this->SetDrawColor(...$this->colBleu);
        $this->SetLineWidth(0.8);
        $this->Line(10, $sepY, 200, $sepY);
        $this->SetLineWidth(0.2);
        $this->SetY($sepY + 4);
        $this->SetTextColor(...$this->colDark);
    }

    // ---- En-tête du tableau de cotisations ----------------------------
    function tableHead(): void
    {
        $y = $this->GetY();
        $this->SetFillColor(...$this->colBleu);
        $this->SetTextColor(255, 255, 255);
        $this->SetFont('Helvetica', 'B', 7.5);
        $this->SetDrawColor(...$this->colBorder);
        $this->SetLineWidth(0.2);

        $cols = [['Désignation', 100, 'L'], ['Base', 22, 'R'], ['Taux', 22, 'R'], ['Montant', 36, 'R']];
        $x = 10;
        foreach ($cols as [$lbl, $w, $a]) {
            $this->SetXY($x, $y);
            $this->Cell($w, 7, $this->conv($lbl), 1, 0, $a, true);
            $x += $w;
        }
        $this->Ln(7);
    }

    // ---- Ligne de tableau normale -------------------------------------
    function tableLine(string $label, string $base, string $taux, string $montant, bool $fill = false): void
    {
        if ($this->GetY() + 6 > $this->h - $this->bMargin - 15) {
            $this->AddPage();
            $this->tableHead();
        }
        $y = $this->GetY();
        if ($fill) {
            $this->SetFillColor(...$this->colBg);
        }
        $this->SetFont('Helvetica', '', 8);
        $this->SetTextColor(...$this->colDark);
        $x = 10;
        $data = [[$label, 100, 'L'], [$base, 22, 'R'], [$taux, 22, 'R'], [$montant, 36, 'R']];
        foreach ($data as [$txt, $w, $a]) {
            $this->SetXY($x, $y);
            $this->Cell($w, 5.5, $this->conv($txt), 'LR', 0, $a, $fill);
            $x += $w;
        }
        $this->SetXY(10, $y + 5.5);
        $this->SetDrawColor(...$this->colBorder);
        $this->Line(10, $y + 5.5, 190, $y + 5.5);
        $this->SetY($y + 5.5);
    }

    // ---- Ligne de section (sous-titre fond coloré) -------------------
    function sectionLine(string $label, bool $isTotal = false): void
    {
        if ($this->GetY() + 8 > $this->h - $this->bMargin - 15) {
            $this->AddPage();
        }
        $y = $this->GetY();
        if ($isTotal) {
            $this->SetFillColor(...$this->colBleuBg);
            $this->SetTextColor(255, 255, 255);
        } else {
            $this->SetFillColor(220, 228, 240);
            $this->SetTextColor(...$this->colBleu);
        }
        $this->SetFont('Helvetica', 'B', $isTotal ? 8.5 : 7.5);
        $this->Rect(10, $y, 180, 6.5, 'F');
        $this->SetXY(12, $y + 0.5);
        $this->Cell(178, 5.5, $this->conv(mb_strtoupper($label)), 0, 1, 'L');
        $this->SetTextColor(...$this->colDark);
        $this->SetY($y + 6.5);
    }

    // ---- Ligne total avec montant souligné ---------------------------
    function totalLine(string $label, string $montant, bool $highlight = false): void
    {
        $y = $this->GetY() + 1;
        if ($highlight) {
            $this->SetFillColor(...$this->colBleuBg);
            $this->Rect(10, $y, 180, 9, 'F');
            $this->SetFont('Helvetica', 'B', 10.5);
            $this->SetTextColor(255, 255, 255);
        } else {
            $this->SetFont('Helvetica', 'B', 9);
            $this->SetTextColor(...$this->colDark);
        }
        $this->SetXY(10, $y);
        $this->Cell(144, 9, $this->conv($label), 0, 0, 'L');
        $this->Cell(36, 9, $this->conv($montant), 0, 1, 'R');
        $this->SetY($y + 10);
        $this->SetTextColor(...$this->colDark);
    }

    // ---- Séparateur léger --------------------------------------------
    function thinLine(): void
    {
        $this->SetDrawColor(...$this->colBorder);
        $this->SetLineWidth(0.2);
        $this->Line(10, $this->GetY(), 190, $this->GetY());
        $this->Ln(0.5);
    }

    // ---- Bloc récapitulatif bas de page ------------------------------
    function drawFooterBlock(array $b, array $emp): void
    {
        $this->Ln(4);
        $y = $this->GetY();

        // Fond gris clair
        $this->SetFillColor(...$this->colBg);
        $this->SetDrawColor(...$this->colBorder);
        $this->SetLineWidth(0.3);
        $this->Rect(10, $y, 180, 36, 'DF');

        // Colonne gauche : infos paiement
        $this->SetFont('Helvetica', 'B', 7.5);
        $this->SetTextColor(...$this->colGrey);
        $this->SetXY(13, $y + 3);
        $this->Cell(80, 4, $this->conv('INFORMATIONS DE PAIEMENT'), 0, 1);

        $modeLbl = match ($b['mode_paiement']) {
            'cheque'  => 'Chèque',
            'especes' => 'Espèces',
            'autre'   => 'Autre',
            default   => 'Virement bancaire',
        };
        $this->SetFont('Helvetica', '', 8);
        $this->SetTextColor(...$this->colDark);
        $curY = $y + 8;
        $rows = [
            ['Mode de paiement', $modeLbl],
        ];
        if ($emp['iban'] !== '') $rows[] = ['IBAN', wordwrap($emp['iban'], 4, ' ', true)];
        if ($b['reference_paiement'] !== '') $rows[] = ['Référence', $b['reference_paiement']];

        foreach ($rows as [$lbl, $val]) {
            $this->SetXY(13, $curY);
            $this->SetFont('Helvetica', '', 7.5);
            $this->SetTextColor(...$this->colGrey);
            $this->Cell(40, 4.5, $this->conv($lbl . ' :'), 0, 0);
            $this->SetFont('Helvetica', 'B', 7.5);
            $this->SetTextColor(...$this->colDark);
            $this->Cell(50, 4.5, $this->conv($val), 0, 1);
            $curY += 4.5;
        }

        // Colonne droite : récapitulatif chiffres
        $this->SetFont('Helvetica', 'B', 7.5);
        $this->SetTextColor(...$this->colGrey);
        $this->SetXY(100, $y + 3);
        $this->Cell(88, 4, $this->conv('RÉCAPITULATIF'), 0, 1, 'R');

        $recapRows = [
            ['Salaire brut',          $this->eur($b['salaire_brut'])],
            ['Cotisations salariales', '- ' . $this->eur($b['cotis_salar'])],
            ['Net imposable',          $this->eur($b['net_imposable'])],
            ['Retenues',               '- ' . $this->eur($b['retenues'])],
            ['Coût total employeur',   $this->eur($b['cout_employeur'])],
        ];
        $rY = $y + 8;
        foreach ($recapRows as [$lbl, $val]) {
            $this->SetXY(100, $rY);
            $this->SetFont('Helvetica', '', 7.5);
            $this->SetTextColor(...$this->colGrey);
            $this->Cell(60, 4.5, $this->conv($lbl . ' :'), 0, 0, 'R');
            $this->SetFont('Helvetica', 'B', 7.5);
            $this->SetTextColor(...$this->colDark);
            $this->Cell(28, 4.5, $this->conv($val), 0, 1, 'R');
            $rY += 4.5;
        }
    }

    // ---- Mention légale bas ------------------------------------------
    function drawLegalMention(): void
    {
        $this->Ln(4);
        $this->SetFont('Helvetica', 'I', 6.5);
        $this->SetTextColor(...$this->colLight);
        $this->MultiCell(180, 3.5, $this->conv(
            'Ce bulletin de paie doit être conservé sans limitation de durée (art. L. 3243-4 du Code du travail). ' .
            'En cas de litige, il peut être produit en justice. ' .
            'Toute modification non autorisée est passible de sanctions pénales.'
        ), 0, 'C');
    }
}

// =====================================================================
// Construction du PDF
// =====================================================================
$pdf = new BulletinPDF('P', 'mm', 'A4');
$pdf->AliasNbPages();
$pdf->SetMargins(10, 10, 10);
$pdf->SetAutoPageBreak(true, 25);
$pdf->AddPage();

// En-tête
$pdf->drawHeader($ent, $emp, $b['periode'], $b['date_paiement']);

// ---- Tableau principal -----------------------------------------------
$pdf->tableHead();

// --- Rémunération de base ---
$pdf->sectionLine('Rémunération');

$tauxHoraire = $b['heures_travaillees'] > 0 ? round($b['salaire_base_brut'] / $b['heures_travaillees'], 4) : 0;
$pdf->tableLine(
    'Salaire de base',
    h($b['heures_travaillees']),
    $tauxHoraire > 0 ? number_format($tauxHoraire, 4, ',', ' ') : '',
    eur($b['salaire_base_brut']),
    false
);

if ($b['heures_supp'] > 0) {
    $pdf->tableLine(
        'Heures supplémentaires',
        h($b['heures_supp']),
        $b['taux_maj'] > 0 ? number_format($b['taux_maj'], 2, ',', ' ') : '',
        eur($b['montant_hs']),
        true
    );
}
if ($b['prime'] > 0) {
    $pdf->tableLine('Prime', '', '', eur($b['prime']), false);
}
if ($b['bonus'] > 0) {
    $pdf->tableLine('Bonus', '', '', eur($b['bonus']), true);
}
if ($b['indemnites'] > 0) {
    $pdf->tableLine('Indemnités', '', '', eur($b['indemnites']), false);
}

// --- Total Brut ---
$pdf->thinLine();
$pdf->totalLine('SALAIRE BRUT', eur($b['salaire_brut']));

// --- Cotisations salariales ---
$pdf->sectionLine('Cotisations salariales (part employé)');
$pdf->tableLine(
    'Cotisations sociales salariales',
    eur($b['salaire_brut']),
    $taux_salar > 0 ? pct($taux_salar) : '',
    '- ' . eur($b['cotis_salar']),
    false
);
$pdf->thinLine();
$pdf->totalLine('NET IMPOSABLE', eur($b['net_imposable']));

// --- Retenues diverses ---
if ($b['retenues'] > 0) {
    $pdf->sectionLine('Retenues');
    $pdf->tableLine('Retenues diverses', '', '', '- ' . eur($b['retenues']), true);
    $pdf->thinLine();
}

// --- Net à payer ---
$pdf->totalLine('NET À PAYER EN EUROS', eur($b['net_a_payer']), true);

// --- Cotisations patronales ---
$pdf->Ln(3);
$pdf->sectionLine('Cotisations patronales (part employeur — à titre informatif)');
$pdf->tableLine(
    'Charges patronales',
    eur($b['salaire_brut']),
    $taux_patron > 0 ? pct($taux_patron) : '',
    eur($b['cotis_patron']),
    false
);
$pdf->thinLine();
$pdf->totalLine('COÛT TOTAL EMPLOYEUR', eur($b['cout_employeur']));

// --- Notes ---
if ($b['notes'] !== '') {
    $pdf->Ln(3);
    $pdf->SetFont('Helvetica', 'I', 7.5);
    $pdf->SetTextColor(80, 80, 80);
    $pdf->MultiCell(180, 4, $pdf->conv('Note : ' . $b['notes']), 0, 'L');
    $pdf->SetTextColor(33, 33, 33);
}

// --- Bloc récap paiement ---
$pdf->drawFooterBlock($b, $emp);

// --- Mention légale ---
$pdf->drawLegalMention();

// =====================================================================
// Sortie
// =====================================================================
$periode = preg_replace('/[^0-9\-]/', '', $b['periode']);
$nomFichier = 'bulletin-paie-' . $periode . '-' . preg_replace('/[^a-z0-9]/i', '-', $emp['nom']) . '.pdf';
$pdf->Output('I', $nomFichier);

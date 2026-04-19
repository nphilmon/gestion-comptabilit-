<?php
/**
 * Générateur de ticket de caisse PDF professionnel (reçu de vente)
 */
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/functions_caisse.php';
requireLogin();
require_once __DIR__ . '/lib/fpdf.php';

$id = (int)($_GET['id'] ?? 0);
if (!$id) die('ID manquant.');

$note = getNoteCaisse($id);
if (!$note) die('Note introuvable.');

$lignes = getLignesNoteCaisse($id);
$paiements = getPaiementsNote($id);

class TicketPDF extends FPDF
{
    public $entreprise = [];
    private $lineLeft = 4;
    private $lineRight = 76;

    function conv($s) {
        return mb_convert_encoding((string)$s, 'Windows-1252', 'UTF-8');
    }

    function eur(float $v): string {
        return number_format($v, 2, ',', ' ') . chr(128);
    }

    function dottedLine(): void {
        $this->SetFont('Courier', '', 6);
        $y = $this->GetY() + 1;
        $this->SetXY($this->lineLeft, $y);
        $dots = str_repeat('- ', 24);
        $this->Cell($this->lineRight - $this->lineLeft, 2, $dots, 0, 1, 'C');
        $this->Ln(1);
    }

    function solidLine(): void {
        $this->SetDrawColor(60, 60, 60);
        $this->SetLineWidth(0.3);
        $this->Line($this->lineLeft, $this->GetY() + 0.5, $this->lineRight, $this->GetY() + 0.5);
        $this->SetLineWidth(0.2);
        $this->Ln(2);
    }

    function doubleLine(): void {
        $y = $this->GetY() + 0.5;
        $this->SetDrawColor(40, 40, 40);
        $this->SetLineWidth(0.3);
        $this->Line($this->lineLeft, $y, $this->lineRight, $y);
        $this->Line($this->lineLeft, $y + 1, $this->lineRight, $y + 1);
        $this->SetLineWidth(0.2);
        $this->Ln(3);
    }

    function Header() {
        $this->Ln(2);

        // Nom entreprise en gras
        $this->SetFont('Helvetica', 'B', 11);
        $this->Cell(0, 5, $this->conv($this->entreprise['nom'] ?? 'Caisse'), 0, 1, 'C');

        // Adresse
        if (!empty($this->entreprise['adresse'])) {
            $this->SetFont('Helvetica', '', 7);
            $this->Cell(0, 3.5, $this->conv($this->entreprise['adresse']), 0, 1, 'C');
        }

        // Téléphone
        if (!empty($this->entreprise['telephone'])) {
            $this->SetFont('Helvetica', '', 7);
            $this->Cell(0, 3.5, $this->conv('Tel : ' . $this->entreprise['telephone']), 0, 1, 'C');
        }

        // SIRET
        if (!empty($this->entreprise['siret'])) {
            $this->SetFont('Helvetica', '', 6.5);
            $this->Cell(0, 3.5, 'SIRET : ' . $this->entreprise['siret'], 0, 1, 'C');
        }

        $this->doubleLine();
    }

    function Footer() {
        $this->SetY(-18);
        $this->dottedLine();
        $this->SetFont('Helvetica', 'B', 7);
        $this->Cell(0, 3.5, 'Merci de votre visite !', 0, 1, 'C');
        $this->SetFont('Helvetica', '', 6);
        $this->Cell(0, 3.5, $this->conv('Conservez ce reçu pour tout échange ou retour'), 0, 1, 'C');
    }
}

// Calculer la hauteur nécessaire
$baseHeight = 110; // en-tête + totaux + pied
$lineHeight = count($lignes) * 9;
$paymentHeight = count($paiements) * 4.5;
$totalHeight = max(200, $baseHeight + $lineHeight + $paymentHeight + 30);

// Format ticket : 80mm de large
$pdf = new TicketPDF('P', 'mm', [80, $totalHeight]);
$pdf->entreprise = [
    'nom'       => getParam('nom_entreprise', 'Mon Entreprise'),
    'adresse'   => getParam('adresse_entreprise', ''),
    'telephone' => getParam('telephone_entreprise', ''),
    'siret'     => getParam('siret', ''),
];
$pdf->SetMargins(4, 4, 4);
$pdf->SetAutoPageBreak(true, 18);
$pdf->AliasNbPages();
$pdf->AddPage();

// === Titre du reçu ===
$pdf->SetFont('Helvetica', 'B', 9);
$pdf->Cell(0, 5, $pdf->conv('RECU DE VENTE'), 0, 1, 'C');
$pdf->Ln(1);

// === Informations de la note ===
$pdf->SetFont('Helvetica', '', 7.5);
$pdf->Cell(36, 4, $pdf->conv('N' . chr(176) . ' :'), 0, 0, 'L');
$pdf->Cell(36, 4, $note['numero'], 0, 1, 'R');
$pdf->Cell(36, 4, 'Date :', 0, 0, 'L');
$pdf->Cell(36, 4, date('d/m/Y H:i', strtotime($note['date_note'])), 0, 1, 'R');

if (!empty($note['client_nom'])) {
    $clientLabel = !empty($note['client_entreprise'])
        ? $note['client_entreprise']
        : trim(($note['client_prenom'] ?? '') . ' ' . $note['client_nom']);
    $pdf->Cell(36, 4, 'Client :', 0, 0, 'L');
    $pdf->Cell(36, 4, $pdf->conv($clientLabel), 0, 1, 'R');
}

$pdf->solidLine();

// === En-tête du tableau d'articles ===
$colArt = 34;
$colQte = 8;
$colPU  = 14;
$colTot = 16;

$pdf->SetFont('Helvetica', 'B', 7);
$pdf->Cell($colArt, 4, 'Article', 0, 0, 'L');
$pdf->Cell($colQte, 4, 'Qte', 0, 0, 'C');
$pdf->Cell($colPU, 4, 'P.U.', 0, 0, 'R');
$pdf->Cell($colTot, 4, 'Total', 0, 1, 'R');
$pdf->SetDrawColor(100, 100, 100);
$pdf->Line(4, $pdf->GetY(), 76, $pdf->GetY());
$pdf->Ln(1);

// === Lignes d'articles ===
$pdf->SetFont('Helvetica', '', 7);
foreach ($lignes as $l) {
    $designation = $l['designation'];
    $qte = number_format((float)$l['quantite'], 0, ',', '');
    $pu  = number_format((float)$l['prix_unitaire'], 2, ',', '');
    $tot = number_format((float)$l['montant_ttc'], 2, ',', '');

    // Nom de l'article (peut être long → sur sa propre ligne si nécessaire)
    if (mb_strlen($designation) > 22) {
        // Désignation sur une ligne, puis chiffres sur la suivante
        $pdf->SetFont('Helvetica', '', 7);
        $pdf->Cell(72, 3.5, $pdf->conv($designation), 0, 1, 'L');
        $pdf->Cell($colArt, 3.5, '', 0, 0, 'L');
        $pdf->Cell($colQte, 3.5, $qte, 0, 0, 'C');
        $pdf->Cell($colPU, 3.5, $pu, 0, 0, 'R');
        $pdf->SetFont('Helvetica', 'B', 7);
        $pdf->Cell($colTot, 3.5, $tot, 0, 1, 'R');
    } else {
        $pdf->SetFont('Helvetica', '', 7);
        $pdf->Cell($colArt, 3.8, $pdf->conv($designation), 0, 0, 'L');
        $pdf->Cell($colQte, 3.8, $qte, 0, 0, 'C');
        $pdf->Cell($colPU, 3.8, $pu, 0, 0, 'R');
        $pdf->SetFont('Helvetica', 'B', 7);
        $pdf->Cell($colTot, 3.8, $tot, 0, 1, 'R');
    }
    $pdf->SetFont('Helvetica', '', 7);
    $pdf->Ln(0.5);
}

$pdf->doubleLine();

// === Totaux ===
$labelW = 44;
$valW   = 28;

if ((float)$note['total_tva'] > 0) {
    $pdf->SetFont('Helvetica', '', 7.5);
    $pdf->Cell($labelW, 4, 'Total HT', 0, 0, 'L');
    $pdf->Cell($valW, 4, $pdf->eur((float)$note['total_ht']), 0, 1, 'R');
    $pdf->Cell($labelW, 4, 'TVA', 0, 0, 'L');
    $pdf->Cell($valW, 4, $pdf->eur((float)$note['total_tva']), 0, 1, 'R');
    $pdf->Ln(1);
}

// Total TTC mis en avant
$pdf->SetFont('Helvetica', 'B', 11);
$pdf->Cell($labelW, 7, 'TOTAL TTC', 0, 0, 'L');
$pdf->Cell($valW, 7, $pdf->eur((float)$note['total_ttc']), 0, 1, 'R');

$pdf->solidLine();

// === Paiements ===
if (!empty($paiements)) {
    $pdf->SetFont('Helvetica', 'B', 7.5);
    $pdf->Cell(0, 4, 'PAIEMENT(S)', 0, 1, 'L');
    $pdf->Ln(0.5);
    $pdf->SetFont('Helvetica', '', 7.5);
    foreach ($paiements as $p) {
        $pdf->Cell($labelW, 4, $pdf->conv($p['moyen_nom']), 0, 0, 'L');
        $pdf->Cell($valW, 4, $pdf->eur((float)$p['montant']), 0, 1, 'R');
    }
    $pdf->Ln(2);
}

// === TVA non applicable ===
$tvaApplicable = (bool) getParam('tva_applicable', '0');
if (!$tvaApplicable) {
    $pdf->SetFont('Helvetica', '', 5.5);
    $pdf->Cell(0, 3, 'TVA non applicable, art. 293 B du CGI', 0, 1, 'C');
    $pdf->Ln(1);
}

$pdf->Output('D', 'ticket_' . $note['numero'] . '.pdf');

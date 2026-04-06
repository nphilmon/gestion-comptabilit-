<?php
/**
 * Générateur de ticket de caisse PDF (reçu de vente)
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

    function conv($s) {
        return mb_convert_encoding($s, 'Windows-1252', 'UTF-8');
    }

    function Header() {
        // Nom entreprise
        $this->SetFont('Courier', 'B', 12);
        $this->Cell(0, 5, $this->conv($this->entreprise['nom'] ?? 'Caisse'), 0, 1, 'C');

        if (!empty($this->entreprise['adresse'])) {
            $this->SetFont('Courier', '', 8);
            $this->Cell(0, 4, $this->conv($this->entreprise['adresse']), 0, 1, 'C');
        }
        if (!empty($this->entreprise['siret'])) {
            $this->SetFont('Courier', '', 7);
            $this->Cell(0, 4, 'SIRET : ' . $this->entreprise['siret'], 0, 1, 'C');
        }
        $this->Line(5, $this->GetY() + 1, 75, $this->GetY() + 1);
        $this->Ln(3);
    }

    function Footer() {
        $this->SetY(-15);
        $this->SetFont('Courier', '', 7);
        $this->Cell(0, 4, 'Merci de votre visite !', 0, 1, 'C');
        $this->Cell(0, 4, $this->conv('Conservez ce reçu pour tout échange'), 0, 1, 'C');
    }
}

// Format ticket : 80mm de large
$pdf = new TicketPDF('P', 'mm', [80, 200]);
$pdf->entreprise = [
    'nom' => getParam('nom_entreprise', 'Mon Activité'),
    'adresse' => getParam('adresse_entreprise', ''),
    'siret' => getParam('siret', ''),
];
$pdf->SetMargins(5, 5, 5);
$pdf->SetAutoPageBreak(true, 15);
$pdf->AliasNbPages();
$pdf->AddPage();

// Infos note
$pdf->SetFont('Courier', 'B', 9);
$pdf->Cell(0, 5, $pdf->conv('REÇU DE VENTE'), 0, 1, 'C');
$pdf->SetFont('Courier', '', 8);
$pdf->Cell(0, 4, 'N° ' . $note['numero'], 0, 1, 'C');
$pdf->Cell(0, 4, 'Date : ' . date('d/m/Y H:i', strtotime($note['date_note'])), 0, 1, 'C');

if ($note['client_nom']) {
    $clientLabel = ($note['client_entreprise'] ? $note['client_entreprise'] . ' - ' : '') . $note['client_prenom'] . ' ' . $note['client_nom'];
    $pdf->Cell(0, 4, $pdf->conv('Client : ' . $clientLabel), 0, 1, 'C');
}

$pdf->Line(5, $pdf->GetY() + 1, 75, $pdf->GetY() + 1);
$pdf->Ln(3);

// Lignes d'articles
$pdf->SetFont('Courier', 'B', 7);
$pdf->Cell(35, 4, 'Article', 0, 0, 'L');
$pdf->Cell(10, 4, 'Qte', 0, 0, 'R');
$pdf->Cell(12, 4, 'P.U.', 0, 0, 'R');
$pdf->Cell(13, 4, 'Total', 0, 1, 'R');
$pdf->Line(5, $pdf->GetY(), 75, $pdf->GetY());
$pdf->SetFont('Courier', '', 7);

foreach ($lignes as $l) {
    $nom = mb_substr($l['designation'], 0, 20);
    $pdf->Cell(35, 4, $pdf->conv($nom), 0, 0, 'L');
    $pdf->Cell(10, 4, number_format((float)$l['quantite'], 0, ',', ''), 0, 0, 'R');
    $pdf->Cell(12, 4, number_format((float)$l['prix_unitaire'], 2, ',', ''), 0, 0, 'R');
    $pdf->Cell(13, 4, number_format((float)$l['montant_ttc'], 2, ',', ''), 0, 1, 'R');
}

$pdf->Line(5, $pdf->GetY() + 1, 75, $pdf->GetY() + 1);
$pdf->Ln(2);

// Totaux
if ((float)$note['total_tva'] > 0) {
    $pdf->SetFont('Courier', '', 8);
    $pdf->Cell(40, 4, 'Total HT', 0, 0, 'L');
    $pdf->Cell(30, 4, number_format((float)$note['total_ht'], 2, ',', ' ') . ' ' . $pdf->conv('€'), 0, 1, 'R');
    $pdf->Cell(40, 4, 'TVA', 0, 0, 'L');
    $pdf->Cell(30, 4, number_format((float)$note['total_tva'], 2, ',', ' ') . ' ' . $pdf->conv('€'), 0, 1, 'R');
}

$pdf->SetFont('Courier', 'B', 10);
$pdf->Cell(40, 6, 'TOTAL TTC', 0, 0, 'L');
$pdf->Cell(30, 6, number_format((float)$note['total_ttc'], 2, ',', ' ') . ' ' . $pdf->conv('€'), 0, 1, 'R');

$pdf->Line(5, $pdf->GetY() + 1, 75, $pdf->GetY() + 1);
$pdf->Ln(2);

// Paiements
$pdf->SetFont('Courier', 'B', 8);
$pdf->Cell(0, 4, 'PAIEMENTS', 0, 1, 'L');
$pdf->SetFont('Courier', '', 8);
foreach ($paiements as $p) {
    $pdf->Cell(40, 4, $pdf->conv($p['moyen_nom']), 0, 0, 'L');
    $pdf->Cell(30, 4, number_format((float)$p['montant'], 2, ',', ' ') . ' ' . $pdf->conv('€'), 0, 1, 'R');
}

$pdf->Ln(5);

$pdf->Output('D', 'ticket_' . $note['numero'] . '.pdf');

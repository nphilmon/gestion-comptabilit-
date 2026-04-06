<?php
/**
 * Générateur de PDF pour devis et factures
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

class DocumentPDF extends FPDF
{
    public $entreprise = [];
    public $docType = '';

    function Header()
    {
        // Fond header
        $this->SetFillColor(44, 62, 80);
        $this->Rect(0, 0, 210, 40, 'F');

        // Nom entreprise
        $this->SetTextColor(255, 255, 255);
        $this->SetFont('Helvetica', 'B', 18);
        $this->SetXY(15, 10);
        $this->Cell(120, 10, $this->conv($this->entreprise['nom'] ?? 'Mon Activite'), 0, 0);

        // Type de document
        $this->SetFont('Helvetica', 'B', 22);
        $this->SetXY(140, 8);
        $this->Cell(55, 14, $this->conv(strtoupper($this->docType)), 0, 0, 'R');

        // Sous-titre entreprise
        $this->SetFont('Helvetica', '', 9);
        $this->SetXY(15, 22);
        $info = [];
        if (!empty($this->entreprise['adresse'])) $info[] = $this->entreprise['adresse'];
        if (!empty($this->entreprise['email'])) $info[] = $this->entreprise['email'];
        if (!empty($this->entreprise['telephone'])) $info[] = $this->entreprise['telephone'];
        $this->Cell(120, 5, $this->conv(implode(' | ', $info)), 0, 1);

        if (!empty($this->entreprise['siret'])) {
            $this->SetXY(15, 28);
            $this->Cell(120, 5, $this->conv('SIRET : ' . $this->entreprise['siret']), 0, 1);
        }

        $this->SetTextColor(0, 0, 0);
        $this->Ln(15);
    }

    function Footer()
    {
        $this->SetY(-25);
        $this->SetDrawColor(200, 200, 200);
        $this->Line(15, $this->GetY(), 195, $this->GetY());
        $this->Ln(3);
        $this->SetFont('Helvetica', '', 7);
        $this->SetTextColor(120, 120, 120);
        $this->Cell(0, 4, $this->conv($this->entreprise['nom'] ?? ''), 0, 1, 'C');
        if (!empty($this->entreprise['tva_mention'])) {
            $this->Cell(0, 4, $this->conv($this->entreprise['tva_mention']), 0, 1, 'C');
        }
        $this->Cell(0, 4, 'Page ' . $this->PageNo() . '/{nb}', 0, 0, 'C');
    }

    // Utility: convert UTF-8 to latin1 for FPDF
    function conv($str)
    {
        return iconv('UTF-8', 'ISO-8859-1//TRANSLIT//IGNORE', $str);
    }
}

// --- Fetch data ---
if ($type === 'devis') {
    $doc = getDevis($id);
    if (!$doc) die('Devis introuvable.');
    $lignes = getLignesDevis($id);
    $docLabel = 'Devis';
    $dateLabel = 'Date';
    $dateValue = $doc['date_devis'];
    $extraDate = 'Valable jusqu\'au : ' . formatDate($doc['date_validite']);
} elseif ($type === 'facture') {
    $doc = getFacture($id);
    if (!$doc) die('Facture introuvable.');
    $lignes = getLignesFacture($id);
    $docLabel = 'Facture';
    $dateLabel = 'Date';
    $dateValue = $doc['date_facture'];
    $extraDate = 'Echéance : ' . formatDate($doc['date_echeance']);
} else {
    $doc = getCommande($id);
    if (!$doc) die('Commande introuvable.');
    $lignes = getLignesCommande($id);
    $docLabel = 'Commande';
    $dateLabel = 'Date';
    $dateValue = $doc['date_commande'];
    $statusLabel = getStatutCommandeLabel($doc['statut'])['label'];
    $extraDate = 'Statut : ' . $statusLabel;
}

// --- Build PDF ---
$pdf = new DocumentPDF('P', 'mm', 'A4');
$pdf->AliasNbPages();

// Entreprise info
$pdf->entreprise = [
    'nom'          => getParam('nom_entreprise', 'Mon Activité'),
    'adresse'      => getParam('adresse_entreprise', ''),
    'email'        => getParam('email_entreprise', ''),
    'telephone'    => getParam('telephone_entreprise', ''),
    'siret'        => getParam('siret', ''),
    'tva_mention'  => $conf['tva_applicable'] ? '' : 'TVA non applicable, art. 293 B du CGI',
];
$pdf->docType = $docLabel;

$pdf->SetAutoPageBreak(true, 30);
$pdf->AddPage();

// --- Document info block ---
$pdf->SetFont('Helvetica', 'B', 11);
$pdf->Cell(95, 7, $pdf->conv($docLabel . ' N° ' . $doc['numero']), 0, 0);
$pdf->SetFont('Helvetica', '', 10);
$pdf->Cell(90, 7, $pdf->conv($dateLabel . ' : ' . formatDate($dateValue)), 0, 1, 'R');

$pdf->SetFont('Helvetica', '', 9);
$pdf->Cell(95, 5, '', 0, 0);
$pdf->Cell(90, 5, $pdf->conv($extraDate), 0, 1, 'R');

$statusLabel = ($type === 'devis') ? getStatutDevisLabel($doc['statut'])['label'] : getStatutFactureLabel($doc['statut'])['label'];
$pdf->Cell(95, 5, $pdf->conv('Statut : ' . $statusLabel), 0, 1);
$pdf->Ln(5);

// --- Client block ---
$clientNom = $doc['client_entreprise'] ?: trim($doc['client_prenom'] . ' ' . $doc['client_nom']);

$pdf->SetFillColor(245, 247, 250);
$pdf->SetFont('Helvetica', 'B', 9);
$pdf->Cell(90, 7, '', 0, 0); // spacer
$pdf->Cell(95, 7, $pdf->conv('  DESTINATAIRE'), 0, 1, 'L', true);

$pdf->SetFont('Helvetica', '', 10);
$startX = 100;
$pdf->SetX($startX);
$pdf->SetFont('Helvetica', 'B', 10);
$pdf->Cell(95, 6, $pdf->conv('  ' . $clientNom), 0, 1);
$pdf->SetFont('Helvetica', '', 9);

if (!empty($doc['client_adresse'])) {
    $pdf->SetX($startX);
    $pdf->Cell(95, 5, $pdf->conv('  ' . $doc['client_adresse']), 0, 1);
}
$cpVille = trim(($doc['client_cp'] ?? '') . ' ' . ($doc['client_ville'] ?? ''));
if ($cpVille) {
    $pdf->SetX($startX);
    $pdf->Cell(95, 5, $pdf->conv('  ' . $cpVille), 0, 1);
}
if (!empty($doc['client_email'])) {
    $pdf->SetX($startX);
    $pdf->Cell(95, 5, $pdf->conv('  ' . $doc['client_email']), 0, 1);
}
if (!empty($doc['client_siret'])) {
    $pdf->SetX($startX);
    $pdf->Cell(95, 5, $pdf->conv('  SIRET : ' . $doc['client_siret']), 0, 1);
}

$pdf->Ln(8);

// --- Objet ---
if (!empty($doc['objet'])) {
    $pdf->SetFont('Helvetica', 'B', 10);
    $pdf->Cell(30, 6, $pdf->conv('Objet : '), 0, 0);
    $pdf->SetFont('Helvetica', '', 10);
    $pdf->Cell(0, 6, $pdf->conv($doc['objet']), 0, 1);
    $pdf->Ln(5);
}

// --- Table header ---
$colWidths = $conf['tva_applicable']
    ? [80, 15, 20, 25, 15, 25]  // description, qte, unite, PU HT, TVA%, total HT
    : [95, 15, 20, 25, 0, 25];

$headers = $conf['tva_applicable']
    ? ['Description', 'Qte', 'Unite', 'P.U. HT', 'TVA %', 'Total HT']
    : ['Description', 'Qte', 'Unite', 'P.U. HT', '', 'Total HT'];

$pdf->SetFillColor(44, 62, 80);
$pdf->SetTextColor(255, 255, 255);
$pdf->SetFont('Helvetica', 'B', 9);

$x = 15;
foreach ($headers as $i => $h) {
    if ($colWidths[$i] === 0) continue;
    $align = ($i >= 1 && $i !== 2) ? 'R' : 'L';
    $pdf->SetX($x);
    $pdf->Cell($colWidths[$i], 8, $pdf->conv($h), 0, 0, $align, true);
    $x += $colWidths[$i];
}
$pdf->Ln();

// --- Table rows ---
$pdf->SetTextColor(0, 0, 0);
$pdf->SetFont('Helvetica', '', 9);
$fill = false;

foreach ($lignes as $l) {
    if ($fill) $pdf->SetFillColor(248, 249, 250);
    else $pdf->SetFillColor(255, 255, 255);

    $x = 15;
    $pdf->SetX($x);
    $pdf->Cell($colWidths[0], 7, $pdf->conv(mb_strimwidth($l['description'], 0, 60, '...')), 0, 0, 'L', true);
    $x += $colWidths[0];

    $qteStr = rtrim(rtrim(number_format($l['quantite'], 3, ',', ' '), '0'), ',');
    $pdf->SetX($x);
    $pdf->Cell($colWidths[1], 7, $pdf->conv($qteStr), 0, 0, 'R', true);
    $x += $colWidths[1];

    $pdf->SetX($x);
    $pdf->Cell($colWidths[2], 7, $pdf->conv($l['unite']), 0, 0, 'L', true);
    $x += $colWidths[2];

    $pdf->SetX($x);
    $pdf->Cell($colWidths[3], 7, number_format($l['prix_unitaire_ht'], 2, ',', ' '), 0, 0, 'R', true);
    $x += $colWidths[3];

    if ($conf['tva_applicable']) {
        $pdf->SetX($x);
        $pdf->Cell($colWidths[4], 7, number_format($l['taux_tva'], 1) . '%', 0, 0, 'R', true);
        $x += $colWidths[4];
    }

    $pdf->SetX($x);
    $pdf->Cell($colWidths[5], 7, number_format($l['montant_ht'], 2, ',', ' '), 0, 0, 'R', true);
    $pdf->Ln();

    $fill = !$fill;
}

// Ligne séparatrice
$pdf->SetDrawColor(44, 62, 80);
$pdf->Line(15, $pdf->GetY(), 195, $pdf->GetY());
$pdf->Ln(3);

// --- Totals ---
$totalsX = 130;
$labelW = 35;
$valW = 30;

$pdf->SetFont('Helvetica', '', 10);
$pdf->SetX($totalsX);
$pdf->Cell($labelW, 7, $pdf->conv('Total HT :'), 0, 0, 'R');
$pdf->Cell($valW, 7, number_format($doc['montant_ht'], 2, ',', ' ') . ' EUR', 0, 1, 'R');

if ($conf['tva_applicable']) {
    $pdf->SetX($totalsX);
    $pdf->Cell($labelW, 7, $pdf->conv('TVA :'), 0, 0, 'R');
    $pdf->Cell($valW, 7, number_format($doc['montant_tva'], 2, ',', ' ') . ' EUR', 0, 1, 'R');
}

$pdf->SetFillColor(44, 62, 80);
$pdf->SetTextColor(255, 255, 255);
$pdf->SetFont('Helvetica', 'B', 12);
$pdf->SetX($totalsX);
$pdf->Cell($labelW, 9, $pdf->conv('Total TTC :'), 0, 0, 'R', true);
$pdf->Cell($valW, 9, number_format($doc['montant_ttc'], 2, ',', ' ') . ' EUR', 0, 1, 'R', true);

$pdf->SetTextColor(0, 0, 0);

// --- Paiements (factures only) ---
if ($type === 'facture' && (float)$doc['montant_paye'] > 0) {
    $pdf->Ln(3);
    $pdf->SetFont('Helvetica', '', 10);
    $pdf->SetX($totalsX);
    $pdf->Cell($labelW, 7, $pdf->conv('Deja paye :'), 0, 0, 'R');
    $pdf->SetTextColor(0, 128, 0);
    $pdf->Cell($valW, 7, number_format($doc['montant_paye'], 2, ',', ' ') . ' EUR', 0, 1, 'R');

    $reste = (float)$doc['montant_ttc'] - (float)$doc['montant_paye'];
    if ($reste > 0.01) {
        $pdf->SetTextColor(200, 0, 0);
        $pdf->SetFont('Helvetica', 'B', 11);
        $pdf->SetX($totalsX);
        $pdf->Cell($labelW, 7, $pdf->conv('Reste a payer :'), 0, 0, 'R');
        $pdf->Cell($valW, 7, number_format($reste, 2, ',', ' ') . ' EUR', 0, 1, 'R');
    }
    $pdf->SetTextColor(0, 0, 0);
}

// --- Notes / Conditions ---
$pdf->Ln(10);
if (!empty($doc['notes'])) {
    $pdf->SetFont('Helvetica', 'B', 9);
    $pdf->Cell(30, 5, $pdf->conv('Notes :'), 0, 1);
    $pdf->SetFont('Helvetica', '', 9);
    $pdf->SetTextColor(80, 80, 80);
    $pdf->MultiCell(180, 5, $pdf->conv($doc['notes']));
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Ln(3);
}

if (!empty($doc['conditions'])) {
    $pdf->SetFont('Helvetica', 'B', 9);
    $pdf->Cell(50, 5, $pdf->conv('Conditions de paiement :'), 0, 1);
    $pdf->SetFont('Helvetica', '', 9);
    $pdf->SetTextColor(80, 80, 80);
    $pdf->MultiCell(180, 5, $pdf->conv($doc['conditions']));
    $pdf->SetTextColor(0, 0, 0);
}

// --- Output ---
$filename = $doc['numero'] . '.pdf';
$pdf->Output('I', $filename);

<?php
/**
 * Générateur de PDF professionnel — charte visuelle alignée sur le thème
 * web (Material Design 3). Utilise FPDF v1.85
 */
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/functions_commercial.php';
requireLogin();
require_once __DIR__ . '/lib/DocumentPdf.php';

$type = $_GET['type'] ?? '';
$id   = (int)($_GET['id'] ?? 0);

if (!in_array($type, ['devis', 'facture', 'commande']) || !$id) {
    die('Paramètres invalides.');
}

$conf = getRegimeConfig();

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
$pdf->endTable();

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
    // Basculer en mode CGV (en-tête/pied minimalistes) à partir de la
    // page suivante — voir le commentaire sur $cgvFirstPage dans
    // lib/DocumentPdf.php : la dernière page du document doit garder son
    // pied de page légal.
    $pdf->cgvFirstPage = $pdf->PageNo() + 1;
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

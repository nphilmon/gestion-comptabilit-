<?php
/**
 * Générateur PDF — Bulletin de paye
 * Gabarit noir/blanc inspiré de la maquette fournie.
 */
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/functions_paie.php';
requireLogin();
require_once __DIR__ . '/lib/BulletinPaiePdf.php';

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
    'montant_net_social' => (float) ($bulletin['montant_net_social'] ?? 0),
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
$taxY = $netY + 16;
// Réserve de la place pour la note (jusqu'à ~2 lignes) avant les boîtes
// du bas, sinon une note même courte chevauche "Congés payés" / "Net payé".
$notesReserve = $b['notes'] !== '' ? 8 : 0;
$botY = $taxY + 22 + $notesReserve;

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

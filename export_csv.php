<?php
/**
 * Export CSV - Exporte les données en CSV
 */
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/functions_commercial.php';
require_once __DIR__ . '/functions_caisse.php';
requireLogin();
requireRole('admin', 'comptable');

$type = $_GET['type'] ?? '';
$annee = (int)($_GET['annee'] ?? date('Y'));

$validTypes = ['transactions', 'clients', 'factures', 'devis', 'commandes', 'produits', 'categories', 'paiements', 'activity_log'];
if (!in_array($type, $validTypes)) {
    setFlash('danger', 'Type d\'export invalide.');
    header('Location: ' . BASE_URL);
    exit;
}

if ($type === 'activity_log') {
    requireRole('admin');
}

// Log
$user = getCurrentUser();
logActivity($user['id'], 'export_csv', "Export $type (année $annee)");

// Headers CSV
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="export_' . $type . '_' . date('Y-m-d_His') . '.csv"');
header('Cache-Control: no-cache');

$output = fopen('php://output', 'w');
// BOM UTF-8 pour Excel
fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

$db = getDB();

switch ($type) {
    case 'transactions':
        fputcsv($output, ['Date', 'Type', 'Description', 'Client / Fournisseur', 'Catégorie', 'Montant', 'Mode de paiement', 'Référence', 'Notes'], ';');
        $rows = getTransactions(['annee' => $annee]);
        foreach ($rows as $r) {
            fputcsv($output, [
                $r['date_transaction'],
                $r['type'] ?? '',
                $r['description'],
                $r['client_fournisseur'] ?? '',
                $r['categorie_nom'] ?? '',
                number_format((float)$r['montant'], 2, ',', ''),
                $r['mode_paiement'] ?? '',
                $r['reference'] ?? '',
                $r['notes'] ?? '',
            ], ';');
        }
        break;

    case 'clients':
        fputcsv($output, ['Nom', 'Email', 'Téléphone', 'Adresse', 'Ville', 'Code postal', 'SIRET', 'Date création'], ';');
        $rows = getClients();
        foreach ($rows as $r) {
            fputcsv($output, [
                $r['nom'], $r['email'] ?? '', $r['telephone'] ?? '',
                $r['adresse'] ?? '', $r['ville'] ?? '', $r['code_postal'] ?? '',
                $r['siret'] ?? '', $r['created_at'] ?? ''
            ], ';');
        }
        break;

    case 'factures':
        fputcsv($output, ['N° Facture', 'Client', 'Date', 'Échéance', 'Montant HT', 'Montant TTC', 'Statut'], ';');
        $rows = getFacturesList();
        foreach ($rows as $r) {
            fputcsv($output, [
                $r['numero'], $r['client_nom'] ?? '', $r['date_facture'],
                $r['date_echeance'] ?? '',
                number_format((float)($r['montant_ht'] ?? 0), 2, ',', ''),
                number_format((float)($r['montant_ttc'] ?? 0), 2, ',', ''),
                $r['statut']
            ], ';');
        }
        break;

    case 'devis':
        fputcsv($output, ['N° Devis', 'Client', 'Date', 'Validité', 'Montant HT', 'Montant TTC', 'Statut'], ';');
        $rows = getDevisList();
        foreach ($rows as $r) {
            fputcsv($output, [
                $r['numero'], $r['client_nom'] ?? '', $r['date_devis'],
                $r['date_validite'] ?? '',
                number_format((float)($r['montant_ht'] ?? 0), 2, ',', ''),
                number_format((float)($r['montant_ttc'] ?? 0), 2, ',', ''),
                $r['statut']
            ], ';');
        }
        break;

    case 'commandes':
        fputcsv($output, ['N° Commande', 'Client', 'Date', 'Montant HT', 'Montant TTC', 'Statut'], ';');
        $rows = getCommandesList();
        foreach ($rows as $r) {
            fputcsv($output, [
                $r['numero'], $r['client_nom'] ?? '', $r['date_commande'],
                number_format((float)($r['montant_ht'] ?? 0), 2, ',', ''),
                number_format((float)($r['montant_ttc'] ?? 0), 2, ',', ''),
                $r['statut']
            ], ';');
        }
        break;

    case 'produits':
        fputcsv($output, ['Référence', 'Nom', 'Prix de vente', 'TVA %', 'Stock', 'Unité', 'Catégorie', 'Actif'], ';');
        $rows = getProduits();
        foreach ($rows as $r) {
            fputcsv($output, [
                $r['reference'] ?? '', $r['nom'],
                number_format((float)($r['prix_vente'] ?? 0), 2, ',', ''),
                $r['taux_tva'] ?? '', $r['stock_actuel'] ?? 0,
                $r['unite'] ?? '', $r['categorie_nom'] ?? '',
                $r['actif'] ? 'Oui' : 'Non'
            ], ';');
        }
        break;

    case 'categories':
        fputcsv($output, ['Nom', 'Type', 'Description'], ';');
        $rows = getCategories();
        foreach ($rows as $r) {
            fputcsv($output, [$r['nom'], $r['type'], $r['description'] ?? ''], ';');
        }
        break;

    case 'paiements':
        fputcsv($output, ['Date', 'Facture', 'Client', 'Montant', 'Mode', 'Référence'], ';');
        $rows = getPaiementsList();
        foreach ($rows as $r) {
            fputcsv($output, [
                $r['date_paiement'], $r['facture_numero'] ?? '', $r['client_nom'] ?? '',
                number_format((float)$r['montant'], 2, ',', ''),
                $r['mode_paiement'] ?? '', $r['reference'] ?? ''
            ], ';');
        }
        break;

    case 'activity_log':
        requireRole('admin');
        fputcsv($output, ['Date', 'Utilisateur', 'Action', 'Détails', 'IP'], ';');
        $rows = getActivityLog(500);
        foreach ($rows as $r) {
            fputcsv($output, [
                $r['created_at'], $r['user_nom'] ?? '', $r['action'],
                $r['details'], $r['ip_address']
            ], ';');
        }
        break;
}

fclose($output);
exit;

<?php
/**
 * Export structuré de préparation à la facturation électronique
 */
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/functions_commercial.php';

requireLogin();
requireRole('admin', 'comptable');

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    exit('Facture invalide.');
}

$facture = getFacture($id);
if (!$facture) {
    http_response_code(404);
    exit('Facture introuvable.');
}

$format = $_GET['format'] ?? null;
$export = getEinvoiceExportContent($id, $format);
$xmlContent = $export['content'];
$filename = preg_replace('/[^A-Za-z0-9_.-]/', '_', 'einvoice-' . ($facture['numero'] ?? ('facture-' . $id)) . '.' . $export['extension']);

header('Content-Type: application/xml; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
echo $xmlContent;

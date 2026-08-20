<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/DocumentPdf.php';

final class DocumentPdfTest extends TestCase
{
    private function makePdf(): DocumentPDF
    {
        $pdf = new DocumentPDF('P', 'mm', 'A4');
        $pdf->AliasNbPages();
        $pdf->entreprise = ['nom' => 'Test SARL'];
        $pdf->docType = 'Facture';
        $pdf->docNumero = 'FA-0001';
        $pdf->legalLines = ['Mentions légales de test'];
        $pdf->SetAutoPageBreak(true, 20);
        return $pdf;
    }

    // Régression : le footer de la dernière page du document ne doit pas
    // basculer en mode CGV simplement parce que cgvFirstPage a été
    // positionné avant l'appel à AddPage() — sinon les mentions légales
    // obligatoires disparaissent de la dernière page de la facture.
    public function testCgvFooterModeFollowsPageBoundaryNotAssignmentOrder(): void
    {
        $pdf = $this->makePdf();
        $pdf->AddPage(); // page 1 : page du document
        $this->assertFalse($pdf->isOnCgvPage(), 'La page 1 doit rester en mode document.');

        $pdf->cgvFirstPage = $pdf->PageNo() + 1; // = 2
        // À cet instant précis (juste avant AddPage), la page courante
        // (celle qu'on est en train de fermer) doit toujours être en
        // mode document : c'est exactement le bug corrigé.
        $this->assertFalse($pdf->isOnCgvPage(), 'La page en cours de fermeture doit garder son pied de page document.');

        $pdf->AddPage(); // page 2 : première page CGV
        $this->assertTrue($pdf->isOnCgvPage(), 'La nouvelle page doit être en mode CGV.');
    }

    // Régression : la hauteur du bloc adresse doit tenir compte des lignes
    // qui se retrouvent sur plusieurs lignes après retour à la ligne
    // automatique (ex. raison sociale longue), pas seulement du nombre
    // d'entrées du tableau.
    public function testAddressBlockGrowsWhenALineWraps(): void
    {
        $pdf = $this->makePdf();
        $pdf->AddPage();

        $bottomShort = $pdf->addressBlock(15, 60, 85, 'Émetteur', ['Nom Court']);
        $heightShort = $bottomShort - 60;

        $longName = "École Supérieure d'Économie & Gestion — Département Général des Affaires Internationales";
        $bottomLong = $pdf->addressBlock(15, 60, 85, 'Émetteur', [$longName]);
        $heightLong = $bottomLong - 60;

        $this->assertGreaterThan(
            $heightShort,
            $heightLong,
            'Le bloc doit grandir quand une ligne se retrouve sur plusieurs lignes.'
        );
    }
}

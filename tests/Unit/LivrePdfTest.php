<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/LivrePdf.php';

final class LivrePdfTest extends TestCase
{
    private function makePdf(): LivrePDF
    {
        $pdf = new LivrePDF('L', 'mm', 'A4');
        $pdf->titre = 'Journal général';
        $pdf->sousTitre = 'Test';
        $pdf->annee = 2026;
        $pdf->AliasNbPages();
        return $pdf;
    }

    // Régression : l'en-tête de colonnes (Date/Libellé/Débit/Crédit...) doit
    // se réafficher automatiquement sur chaque nouvelle page tant qu'un
    // tableau est en cours, sinon un rapport de plusieurs pages perd ses
    // en-têtes à partir de la page 2.
    public function testTableHeaderRepeatsOnPageBreakWhileTableInProgress(): void
    {
        $pdf = $this->makePdf();
        $pdf->AddPage();
        $pdf->SetAutoPageBreak(false);

        $headers = ['Date', 'Libellé', 'Montant'];
        $widths = [30, 100, 30];
        $aligns = ['L', 'L', 'R'];
        $pdf->tableHeader($headers, $widths, $aligns);

        $pageBefore = $pdf->PageNo();
        $pdf->AddPage(); // simule un saut de page déclenché par checkPageBreak()
        $this->assertSame($pageBefore + 1, $pdf->PageNo());

        // On ne peut pas relire le flux PDF facilement en test unitaire,
        // mais on peut vérifier que l'état interne qui pilote la
        // réimpression a bien été positionné par tableHeader() et n'a pas
        // été réinitialisé par le saut de page.
        $ref = new ReflectionProperty(LivrePDF::class, 'continuingTable');
        $ref->setAccessible(true);
        $this->assertTrue($ref->getValue($pdf));
    }

    // Régression : après endTable(), un saut de page ne doit plus
    // réafficher l'en-tête (ex. entre deux rapports ou après les totaux).
    public function testEndTableStopsHeaderRepetition(): void
    {
        $pdf = $this->makePdf();
        $pdf->AddPage();
        $pdf->SetAutoPageBreak(false);
        $pdf->tableHeader(['Date', 'Montant'], [30, 30], ['L', 'R']);
        $pdf->endTable();

        $ref = new ReflectionProperty(LivrePDF::class, 'continuingTable');
        $ref->setAccessible(true);
        $this->assertFalse($ref->getValue($pdf));
    }
}

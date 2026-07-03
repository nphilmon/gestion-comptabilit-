<?php

use PHPUnit\Framework\TestCase;

final class CalculerJoursFractionnementTest extends TestCase
{
    public function testAucunJourHorsSaisonNeDonneAucunBonus(): void
    {
        $bonus = calculerJoursFractionnement([
            ['date_debut' => '2024-07-01', 'date_fin' => '2024-07-05', 'jours_demandes' => 5.0, 'mode_decompte' => 'ouvrables'],
        ]);

        $this->assertSame(0.0, $bonus);
    }

    public function testTroisJoursOuPlusHorsSaisonDonnentDeuxJoursDeBonus(): void
    {
        $bonus = calculerJoursFractionnement([
            ['date_debut' => '2024-12-02', 'date_fin' => '2024-12-06', 'jours_demandes' => 5.0, 'mode_decompte' => 'ouvrables'],
        ]);

        $this->assertSame(2.0, $bonus);
    }

    public function testUnOuDeuxJoursHorsSaisonDonnentUnJourDeBonus(): void
    {
        $bonus = calculerJoursFractionnement([
            ['date_debut' => '2024-11-04', 'date_fin' => '2024-11-05', 'jours_demandes' => 2.0, 'mode_decompte' => 'ouvrables'],
        ]);

        $this->assertSame(1.0, $bonus);
    }

    public function testCumuleLesJoursHorsSaisonSurPlusieursDemandes(): void
    {
        // 1 jour hors saison (29/04) + 1 jour hors saison (4/11) = 2 jours → bonus de 1
        $bonus = calculerJoursFractionnement([
            ['date_debut' => '2024-04-29', 'date_fin' => '2024-04-29', 'jours_demandes' => 1.0, 'mode_decompte' => 'ouvrables'],
            ['date_debut' => '2024-11-04', 'date_fin' => '2024-11-04', 'jours_demandes' => 1.0, 'mode_decompte' => 'ouvrables'],
        ]);

        $this->assertSame(1.0, $bonus);
    }

    public function testNeComptePasLes5eSemaineAuDelaDuPlafondDeCongePrincipal(): void
    {
        // Le congé principal (24 jours ouvrables) est déjà entièrement pris en
        // saison : la demande suivante est donc hors plafond (assimilée à la
        // 5e semaine) et ne doit pas ouvrir droit au fractionnement, même si
        // elle est prise hors saison.
        $bonus = calculerJoursFractionnement([
            ['date_debut' => '2024-06-03', 'date_fin' => '2024-06-29', 'jours_demandes' => 24.0, 'mode_decompte' => 'ouvrables'],
            ['date_debut' => '2024-12-02', 'date_fin' => '2024-12-06', 'jours_demandes' => 5.0, 'mode_decompte' => 'ouvrables'],
        ]);

        $this->assertSame(0.0, $bonus);
    }

    public function testListeVideNeDonneAucunBonus(): void
    {
        $this->assertSame(0.0, calculerJoursFractionnement([]));
    }
}

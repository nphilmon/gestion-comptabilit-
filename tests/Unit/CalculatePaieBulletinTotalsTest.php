<?php

use PHPUnit\Framework\TestCase;

final class CalculatePaieBulletinTotalsTest extends TestCase
{
    public function testSalaireSimpleSansPrimeNiCotisations(): void
    {
        $result = calculatePaieBulletinTotals(['salaire_base_brut' => 2000.0]);

        $this->assertSame(0.0, $result['montant_heures_supplementaires']);
        $this->assertSame(2000.0, $result['salaire_brut']);
        $this->assertSame(2000.0, $result['net_imposable']);
        $this->assertSame(2000.0, $result['net_a_payer']);
        $this->assertSame(2000.0, $result['cout_total_employeur']);
    }

    public function testCalculeAutomatiquementLeMontantDesHeuresSupplementaires(): void
    {
        $result = calculatePaieBulletinTotals([
            'salaire_base_brut' => 2000.0,
            'heures_supplementaires' => 10.0,
            'taux_horaire_majore' => 20.0,
        ]);

        $this->assertSame(200.0, $result['montant_heures_supplementaires']);
        $this->assertSame(2200.0, $result['salaire_brut']);
    }

    public function testNeRecalculePasLeMontantDesHeuresSupplementairesSiDejaFourni(): void
    {
        $result = calculatePaieBulletinTotals([
            'salaire_base_brut' => 2000.0,
            'heures_supplementaires' => 10.0,
            'taux_horaire_majore' => 20.0,
            'montant_heures_supplementaires' => 999.0,
        ]);

        $this->assertSame(999.0, $result['montant_heures_supplementaires']);
    }

    public function testAdditionneToutesLesPrimesEtIndemnitesAuBrut(): void
    {
        $result = calculatePaieBulletinTotals([
            'salaire_base_brut' => 2000.0,
            'prime' => 100.0,
            'bonus' => 50.0,
            'indemnites' => 30.0,
            'indemnite_sante' => 20.0,
            'ancv_ce' => 10.0,
        ]);

        $this->assertSame(2210.0, $result['salaire_brut']);
    }

    public function testSoustraitLesCotisationsSalarialesPourLeNetImposable(): void
    {
        $result = calculatePaieBulletinTotals([
            'salaire_base_brut' => 2000.0,
            'cotisations_salariales' => 440.0,
        ]);

        $this->assertSame(1560.0, $result['net_imposable']);
    }

    public function testSoustraitLesRetenuesPourLeNetAPayerSansPasserSousZero(): void
    {
        $result = calculatePaieBulletinTotals([
            'salaire_base_brut' => 1000.0,
            'cotisations_salariales' => 200.0,
            'retenues' => 5000.0,
        ]);

        // net_imposable = 800, retenues largement supérieures : le net à
        // payer ne doit jamais devenir négatif.
        $this->assertSame(0.0, $result['net_a_payer']);
    }

    public function testAjouteLesCotisationsPatronalesAuCoutEmployeur(): void
    {
        $result = calculatePaieBulletinTotals([
            'salaire_base_brut' => 2000.0,
            'cotisations_patronales' => 840.0,
        ]);

        $this->assertSame(2840.0, $result['cout_total_employeur']);
    }

    public function testIgnoreLesValeursNegativesDesChampsProteges(): void
    {
        $result = calculatePaieBulletinTotals([
            'salaire_base_brut' => -500.0,
            'heures_supplementaires' => -10.0,
            'retenues' => -100.0,
            'cotisations_salariales' => -50.0,
            'cotisations_patronales' => -20.0,
        ]);

        $this->assertSame(0.0, $result['salaire_brut']);
        $this->assertSame(0.0, $result['net_imposable']);
        $this->assertSame(0.0, $result['net_a_payer']);
        $this->assertSame(0.0, $result['cout_total_employeur']);
    }
}

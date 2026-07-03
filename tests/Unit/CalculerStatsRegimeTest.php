<?php

use PHPUnit\Framework\TestCase;

final class CalculerStatsRegimeTest extends TestCase
{
    private function microConf(): array
    {
        return ['is_micro' => true, 'is_societe' => false, 'is_auto' => true];
    }

    private function reelConf(string $impositon): array
    {
        return ['is_micro' => false, 'is_societe' => true, 'is_auto' => false, 'regime_imposition' => $impositon];
    }

    private function baseParams(array $overrides = []): array
    {
        return $overrides + [
            'plafond_ca' => 77700.0,
            'taux_abattement' => 34.0,
            'taux_cotisations_sociales' => 21.1,
            'taux_cfp' => 0.2,
            'versement_liberatoire_actif' => false,
            'taux_versement_liberatoire' => 0.0,
            'tva_applicable' => false,
            'taux_tva' => 20.0,
            'taux_is' => 15.0,
        ];
    }

    public function testMicroEntrepriseCalculeAbattementEtCotisations(): void
    {
        $result = calculerStatsRegime($this->microConf(), 10000.0, 2000.0, $this->baseParams());

        $this->assertEqualsWithDelta(3400.0, $result['abattement'], 0.01);
        $this->assertEqualsWithDelta(6600.0, $result['revenu_imposable'], 0.01);
        $this->assertEqualsWithDelta(2110.0, $result['cotisations_sociales'], 0.01);
        $this->assertEqualsWithDelta(20.0, $result['cfp'], 0.01);
        $this->assertEqualsWithDelta(2130.0, $result['charges_obligatoires'], 0.01);
        // 10000 recettes - 2000 dépenses - 2130 charges obligatoires
        $this->assertEqualsWithDelta(5870.0, $result['benefice_net'], 0.01);
        $this->assertEquals(0.0, $result['tva_a_payer']);
        $this->assertEquals(0.0, $result['is']);
    }

    public function testMicroEntrepriseAppliqueLeVersementLiberatoireQuandActif(): void
    {
        $result = calculerStatsRegime($this->microConf(), 10000.0, 0.0, $this->baseParams([
            'versement_liberatoire_actif' => true,
            'taux_versement_liberatoire' => 2.2,
        ]));

        $this->assertEqualsWithDelta(220.0, $result['versement_liberatoire'], 0.01);
    }

    public function testMicroEntreprisePourcentagePlafondEstNulSiPlafondNonConfigure(): void
    {
        $result = calculerStatsRegime($this->microConf(), 10000.0, 0.0, $this->baseParams([
            'plafond_ca' => 0.0,
        ]));

        $this->assertEquals(0.0, $result['pct_plafond']);
    }

    public function testRegimeReelSansTvaNiIS(): void
    {
        $result = calculerStatsRegime($this->reelConf('ir'), 60000.0, 20000.0, $this->baseParams([
            'tva_applicable' => false,
        ]));

        $this->assertEqualsWithDelta(60000.0, $result['ca_ht'], 0.01);
        $this->assertEqualsWithDelta(20000.0, $result['depenses_ht'], 0.01);
        $this->assertEqualsWithDelta(40000.0, $result['resultat_net'], 0.01);
        $this->assertEquals(0.0, $result['tva_a_payer']);
        $this->assertEquals(0.0, $result['is']);
        $this->assertEqualsWithDelta(40000.0, $result['revenu_imposable'], 0.01);
    }

    public function testRegimeReelCalculeLaTvaACollecterEtADeduire(): void
    {
        $result = calculerStatsRegime($this->reelConf('ir'), 120000.0, 48000.0, $this->baseParams([
            'tva_applicable' => true,
            'taux_tva' => 20.0,
        ]));

        // TVA extraite d'un montant TTC : montant * taux / (100 + taux)
        $this->assertEqualsWithDelta(20000.0, $result['tva_collectee'], 0.01);
        $this->assertEqualsWithDelta(8000.0, $result['tva_deductible'], 0.01);
        $this->assertEqualsWithDelta(12000.0, $result['tva_a_payer'], 0.01);
        $this->assertEqualsWithDelta(100000.0, $result['ca_ht'], 0.01);
        $this->assertEqualsWithDelta(40000.0, $result['depenses_ht'], 0.01);
        $this->assertEqualsWithDelta(60000.0, $result['resultat_net'], 0.01);
    }

    public function testSocieteAlIsAppliqueLeTauxReduitSousLeSeuil(): void
    {
        $result = calculerStatsRegime($this->reelConf('is'), 50000.0, 20000.0, $this->baseParams([
            'tva_applicable' => false,
            'taux_is' => 15.0,
        ]));

        // resultat_net = 30000, sous le seuil de 42500 : IS = 30000 * 15%
        $this->assertEqualsWithDelta(30000.0, $result['resultat_net'], 0.01);
        $this->assertEqualsWithDelta(4500.0, $result['is'], 0.01);
        $this->assertEqualsWithDelta(25500.0, $result['benefice_net'], 0.01);
    }

    public function testSocieteAlIsAppliqueLeTauxNormalAuDelaDuSeuil(): void
    {
        $result = calculerStatsRegime($this->reelConf('is'), 140000.0, 40000.0, $this->baseParams([
            'tva_applicable' => false,
            'taux_is' => 15.0,
        ]));

        // resultat_net = 100000 : 42500 * 15% + (100000 - 42500) * 25%
        $this->assertEqualsWithDelta(100000.0, $result['resultat_net'], 0.01);
        $this->assertEqualsWithDelta(6375.0 + 14375.0, $result['is'], 0.01);
        $this->assertEqualsWithDelta(79250.0, $result['benefice_net'], 0.01);
    }

    public function testSocieteAlIsNAppliqueAucunImpotSurUnResultatNegatif(): void
    {
        $result = calculerStatsRegime($this->reelConf('is'), 10000.0, 30000.0, $this->baseParams([
            'tva_applicable' => false,
        ]));

        $this->assertEqualsWithDelta(-20000.0, $result['resultat_net'], 0.01);
        $this->assertEquals(0.0, $result['is']);
        $this->assertEqualsWithDelta(-20000.0, $result['benefice_net'], 0.01);
    }
}

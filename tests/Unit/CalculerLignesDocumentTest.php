<?php

use PHPUnit\Framework\TestCase;

final class CalculerLignesDocumentTest extends TestCase
{
    public function testCalculeMontantsDUneLigneSimple(): void
    {
        $result = calculerLignesDocument([
            ['quantite' => 2, 'prix_unitaire_ht' => 100, 'taux_tva' => 20],
        ]);

        $this->assertSame(200.0, $result['lignes'][0]['montant_ht']);
        $this->assertSame(40.0, $result['lignes'][0]['montant_tva']);
        $this->assertSame(240.0, $result['lignes'][0]['montant_ttc']);
        $this->assertSame(200.0, $result['total_ht']);
        $this->assertSame(40.0, $result['total_tva']);
        $this->assertSame(240.0, $result['total_ttc']);
    }

    public function testAdditionneLesTotauxSurPlusieursLignes(): void
    {
        $result = calculerLignesDocument([
            ['quantite' => 1, 'prix_unitaire_ht' => 50, 'taux_tva' => 20],
            ['quantite' => 3, 'prix_unitaire_ht' => 10, 'taux_tva' => 10],
        ]);

        $this->assertSame(80.0, $result['total_ht']);
        $this->assertSame(13.0, $result['total_tva']);
        $this->assertSame(93.0, $result['total_ttc']);
    }

    public function testGereUnTauxDeTvaNulPourLaFranchise(): void
    {
        $result = calculerLignesDocument([
            ['quantite' => 5, 'prix_unitaire_ht' => 20, 'taux_tva' => 0],
        ]);

        $this->assertSame(100.0, $result['total_ht']);
        $this->assertSame(0.0, $result['total_tva']);
        $this->assertSame(100.0, $result['total_ttc']);
    }

    public function testArrondiChaqueLigneAvantDAdditionner(): void
    {
        // 3 x 0.1 = 0.30000000000000004 en float brut : la ligne doit être
        // arrondie avant sommation pour éviter les écarts d'arrondi cumulés.
        $result = calculerLignesDocument([
            ['quantite' => 3, 'prix_unitaire_ht' => 0.1, 'taux_tva' => 0],
            ['quantite' => 3, 'prix_unitaire_ht' => 0.1, 'taux_tva' => 0],
            ['quantite' => 3, 'prix_unitaire_ht' => 0.1, 'taux_tva' => 0],
        ]);

        $this->assertSame(0.9, $result['total_ht']);
    }

    public function testListeVideRetourneDesTotauxAZero(): void
    {
        $result = calculerLignesDocument([]);

        $this->assertSame([], $result['lignes']);
        $this->assertSame(0.0, $result['total_ht']);
        $this->assertSame(0.0, $result['total_tva']);
        $this->assertSame(0.0, $result['total_ttc']);
    }
}

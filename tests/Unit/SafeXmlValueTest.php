<?php

use PHPUnit\Framework\TestCase;

final class SafeXmlValueTest extends TestCase
{
    public function testTrimALaFoisLesEspacesEnBordure(): void
    {
        $this->assertSame('Bonjour', safeXmlValue('  Bonjour  '));
    }

    public function testRetireLesCaracteresDeControleInterditsEnXml10(): void
    {
        // \x00 (NUL) et \x0B (tabulation verticale) sont invalides en XML 1.0
        // et faisaient planter DOMDocument::createElement() avant le correctif.
        $dirty = "Adresse\x0Bavec\x00controle";

        $this->assertSame('Adresseaveccontrole', safeXmlValue($dirty));
    }

    public function testConserveLesCaracteresBlancsValidesEnXml(): void
    {
        $withValidWhitespace = "Ligne1\nLigne2\tTabulation\rRetour";

        $this->assertSame($withValidWhitespace, safeXmlValue($withValidWhitespace));
    }

    public function testGereUneValeurNulleCommeChaineVide(): void
    {
        $this->assertSame('', safeXmlValue(null));
    }

    public function testEchappeLesCaracteresSpeciauxXmlPourUsageAvecCreateElement(): void
    {
        // DOMDocument::createElement($name, $value) traite $value comme du XML
        // brut (pas comme du texte à échapper automatiquement) : un "&" ou "<"
        // non échappé y est interprété comme une entité/balise invalide, et
        // l'élément généré reste silencieusement vide, sans erreur visible.
        $dirty = "Notes avec\x0Ccaractere interdit & symbole <test>";
        $clean = safeXmlValue($dirty);

        $doc = new DOMDocument('1.0', 'UTF-8');
        $element = $doc->createElement('notes', $clean);
        $doc->appendChild($element);

        $this->assertSame('Notes aveccaractere interdit & symbole <test>', $element->textContent);
        $this->assertStringContainsString('&amp;', $doc->saveXML());
        $this->assertStringContainsString('&lt;test&gt;', $doc->saveXML());
    }
}

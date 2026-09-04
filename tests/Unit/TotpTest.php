<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/Totp.php';

final class TotpTest extends TestCase
{
    // Clé de test officielle de la RFC 6238, Annexe B ("12345678901234567890"
    // en ASCII), encodée en base32. La RFC teste avec des codes à 8 chiffres ;
    // un code à 6 chiffres en est toujours les 6 derniers chiffres (même
    // troncature, modulo 10^6 au lieu de 10^8), donc on compare à cette partie.
    private const RFC_TEST_SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    public static function rfc6238VectorsProvider(): array
    {
        return [
            'T=59 (8-digit ref 94287082)' => [59, '287082'],
            'T=1111111109 (8-digit ref 07081804)' => [1111111109, '081804'],
            'T=1111111111 (8-digit ref 14050471)' => [1111111111, '050471'],
            'T=1234567890 (8-digit ref 89005924)' => [1234567890, '005924'],
            'T=2000000000 (8-digit ref 69279037)' => [2000000000, '279037'],
            'T=20000000000 (8-digit ref 65353130)' => [20000000000, '353130'],
        ];
    }

    #[DataProvider('rfc6238VectorsProvider')]
    public function testCurrentCodeMatchesRfc6238Vectors(int $timestamp, string $expectedCode): void
    {
        $this->assertSame($expectedCode, Totp::currentCode(self::RFC_TEST_SECRET, $timestamp));
    }

    #[DataProvider('rfc6238VectorsProvider')]
    public function testVerifyAcceptsExactTimeStep(int $timestamp, string $expectedCode): void
    {
        $this->assertTrue(Totp::verify(self::RFC_TEST_SECRET, $expectedCode, 0, $timestamp));
    }

    public function testVerifyToleratesOneStepClockDrift(): void
    {
        // Base alignée sur une frontière de pas (0 est un multiple de 30) pour
        // que "+35s" corresponde sans ambiguïté à exactement un pas plus loin.
        $timestamp = 0;
        $code = Totp::currentCode(self::RFC_TEST_SECRET, $timestamp);
        $this->assertTrue(Totp::verify(self::RFC_TEST_SECRET, $code, 1, $timestamp + 35));
    }

    public function testVerifyRejectsCodeOutsideWindow(): void
    {
        $timestamp = 0;
        $code = Totp::currentCode(self::RFC_TEST_SECRET, $timestamp);
        // 3 pas plus loin (90s), hors de la fenêtre de tolérance de 1.
        $this->assertFalse(Totp::verify(self::RFC_TEST_SECRET, $code, 1, $timestamp + 90));
    }

    public function testVerifyRejectsWrongCode(): void
    {
        $this->assertFalse(Totp::verify(self::RFC_TEST_SECRET, '000000', 1, 59));
    }

    public function testVerifyRejectsMalformedInput(): void
    {
        $this->assertFalse(Totp::verify(self::RFC_TEST_SECRET, '12345', 1, 59));   // trop court
        $this->assertFalse(Totp::verify(self::RFC_TEST_SECRET, '1234567', 1, 59)); // trop long
        $this->assertFalse(Totp::verify(self::RFC_TEST_SECRET, 'abcdef', 1, 59));  // non numérique
        $this->assertFalse(Totp::verify(self::RFC_TEST_SECRET, '', 1, 59));        // vide
    }

    public function testGeneratedSecretRoundTripsThroughCurrentCodeAndVerify(): void
    {
        $secret = Totp::generateSecret();
        $this->assertMatchesRegularExpression('/^[A-Z2-7]+$/', $secret);
        $code = Totp::currentCode($secret);
        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);
        $this->assertTrue(Totp::verify($secret, $code));
    }

    public function testGenerateSecretIsRandomAcrossCalls(): void
    {
        $this->assertNotSame(Totp::generateSecret(), Totp::generateSecret());
    }

    public function testProvisioningUriContainsSecretAndIssuer(): void
    {
        $uri = Totp::provisioningUri('ABCD1234', 'nicolas@example.fr', 'Gestion Comptable Pro');
        $this->assertStringStartsWith('otpauth://totp/', $uri);
        $this->assertStringContainsString('secret=ABCD1234', $uri);
        $this->assertStringContainsString('issuer=Gestion%20Comptable%20Pro', $uri);
        $this->assertStringContainsString('nicolas%40example.fr', $uri);
    }

    public function testFormatSecretForDisplayGroupsByFour(): void
    {
        $this->assertSame('ABCD EFGH IJ', Totp::formatSecretForDisplay('ABCDEFGHIJ'));
    }
}

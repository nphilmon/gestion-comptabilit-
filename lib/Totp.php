<?php
/**
 * TOTP (RFC 6238 / RFC 4226) — génération et vérification de codes à
 * usage unique basés sur le temps, compatibles avec toute application
 * d'authentification standard (Google Authenticator, Authy, 1Password,
 * andOTP...). Aucune dépendance externe : HMAC-SHA1 via hash_hmac(),
 * comparaison en temps constant via hash_equals().
 */
final class Totp
{
    private const SECRET_BYTES = 20; // 160 bits — taille recommandée par la RFC
    private const DIGITS = 6;
    private const PERIOD = 30; // secondes par pas de temps
    private const BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function generateSecret(): string
    {
        return self::base32Encode(random_bytes(self::SECRET_BYTES));
    }

    // otpauth:// URI standard pour affichage en QR code / saisie manuelle.
    public static function provisioningUri(string $secret, string $accountName, string $issuer): string
    {
        $label = rawurlencode($issuer) . ':' . rawurlencode($accountName);
        $query = http_build_query([
            'secret' => $secret,
            'issuer' => $issuer,
            'algorithm' => 'SHA1',
            'digits' => self::DIGITS,
            'period' => self::PERIOD,
        ], '', '&', PHP_QUERY_RFC3986);
        return 'otpauth://totp/' . $label . '?' . $query;
    }

    public static function currentCode(string $secret, ?int $timestamp = null): string
    {
        return self::codeAt($secret, self::counterAt($timestamp ?? time()));
    }

    // Vérifie un code utilisateur en tolérant un décalage de $window pas
    // de temps avant/après le pas courant, pour absorber une petite
    // dérive d'horloge entre le serveur et l'appareil de l'utilisateur.
    public static function verify(string $secret, string $code, int $window = 1, ?int $timestamp = null): bool
    {
        $code = trim($code);
        if (!preg_match('/^\d{' . self::DIGITS . '}$/', $code)) {
            return false;
        }
        $counter = self::counterAt($timestamp ?? time());
        for ($i = -$window; $i <= $window; $i++) {
            if (hash_equals(self::codeAt($secret, $counter + $i), $code)) {
                return true;
            }
        }
        return false;
    }

    private static function counterAt(int $timestamp): int
    {
        return intdiv($timestamp, self::PERIOD);
    }

    // Algorithme HOTP (RFC 4226) appliqué au compteur temporel courant.
    private static function codeAt(string $secret, int $counter): string
    {
        $key = self::base32Decode($secret);
        $binCounter = pack('N*', 0, $counter); // entier 64 bits big-endian (mot de poids fort toujours 0 avant l'an 4147)
        $hash = hash_hmac('sha1', $binCounter, $key, true);
        $offset = ord($hash[strlen($hash) - 1]) & 0x0F;
        $binaryCode = ((ord($hash[$offset]) & 0x7F) << 24)
            | ((ord($hash[$offset + 1]) & 0xFF) << 16)
            | ((ord($hash[$offset + 2]) & 0xFF) << 8)
            | (ord($hash[$offset + 3]) & 0xFF);
        $code = $binaryCode % (10 ** self::DIGITS);
        return str_pad((string) $code, self::DIGITS, '0', STR_PAD_LEFT);
    }

    private static function base32Encode(string $data): string
    {
        $bits = '';
        foreach (str_split($data) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }
        $encoded = '';
        foreach (str_split($bits, 5) as $chunk) {
            if (strlen($chunk) < 5) {
                $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
            }
            $encoded .= self::BASE32_ALPHABET[bindec($chunk)];
        }
        return $encoded;
    }

    private static function base32Decode(string $data): string
    {
        $data = strtoupper(preg_replace('/[^A-Z2-7]/i', '', $data));
        $bits = '';
        foreach (str_split($data) as $char) {
            $pos = strpos(self::BASE32_ALPHABET, $char);
            if ($pos === false) {
                continue;
            }
            $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
        }
        $bytes = '';
        foreach (str_split($bits, 8) as $byteBits) {
            if (strlen($byteBits) < 8) {
                continue; // bits de bourrage finaux, non significatifs
            }
            $bytes .= chr(bindec($byteBits));
        }
        return $bytes;
    }

    // Découpe le secret en groupes de 4 pour l'affichage "saisie manuelle".
    public static function formatSecretForDisplay(string $secret): string
    {
        return trim(chunk_split($secret, 4, ' '));
    }
}

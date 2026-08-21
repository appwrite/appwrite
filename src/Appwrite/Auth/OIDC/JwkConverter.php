<?php

namespace Appwrite\Auth\OIDC;

/**
 * Converts an RSA JSON Web Key to the PEM SubjectPublicKeyInfo encoding that
 * openssl_pkey_get_public() accepts.
 */
class JwkConverter
{
    private const RSA_ENCRYPTION_OID = "\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01"; // 1.2.840.113549.1.1.1

    /**
     * @param string $n base64url-encoded modulus
     * @param string $e base64url-encoded public exponent
     * @throws VerificationException when the key material is not valid base64url
     */
    public static function rsaToPem(string $n, string $e): string
    {
        $modulus = self::decodeBase64Url($n);
        $exponent = self::decodeBase64Url($e);

        if ($modulus === false || $exponent === false || $modulus === '' || $exponent === '') {
            throw new VerificationException('Invalid signing key material');
        }

        $publicKey = self::sequence(self::integer($modulus) . self::integer($exponent));
        $algorithm = self::sequence(self::RSA_ENCRYPTION_OID . "\x05\x00"); // NULL parameters
        $subjectPublicKeyInfo = self::sequence($algorithm . self::bitString($publicKey));

        return "-----BEGIN PUBLIC KEY-----\n"
            . \chunk_split(\base64_encode($subjectPublicKeyInfo), 64, "\n")
            . "-----END PUBLIC KEY-----\n";
    }

    private static function decodeBase64Url(string $data): string|false
    {
        $remainder = \strlen($data) % 4;
        if ($remainder > 0) {
            $data .= \str_repeat('=', 4 - $remainder);
        }

        return \base64_decode(\strtr($data, '-_', '+/'), true);
    }

    private static function integer(string $bytes): string
    {
        // DER INTEGERs are signed; prepend a zero byte when the high bit is set
        // so the value stays positive.
        if ((\ord($bytes[0]) & 0x80) !== 0) {
            $bytes = "\x00" . $bytes;
        }

        return "\x02" . self::length(\strlen($bytes)) . $bytes;
    }

    private static function sequence(string $bytes): string
    {
        return "\x30" . self::length(\strlen($bytes)) . $bytes;
    }

    private static function bitString(string $bytes): string
    {
        $bytes = "\x00" . $bytes; // zero unused bits

        return "\x03" . self::length(\strlen($bytes)) . $bytes;
    }

    private static function length(int $length): string
    {
        if ($length < 0x80) {
            return \chr($length);
        }

        $bytes = \ltrim(\pack('N', $length), "\x00");

        return \chr(0x80 | \strlen($bytes)) . $bytes;
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit\Auth\OIDC;

use Appwrite\Auth\OIDC\JwkConverter;
use Appwrite\Auth\OIDC\VerificationException;
use PHPUnit\Framework\TestCase;

final class JwkConverterTest extends TestCase
{
    /**
     * The converted PEM must describe the same key OpenSSL generated, so a
     * signature made with the private key verifies against the converted
     * public key.
     */
    public function testConvertedPemVerifiesSignatures(): void
    {
        $key = \openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        $this->assertNotFalse($key);

        $details = \openssl_pkey_get_details($key);
        $n = $this->base64UrlEncode($details['rsa']['n']);
        $e = $this->base64UrlEncode($details['rsa']['e']);

        $pem = JwkConverter::rsaToPem($n, $e);

        $this->assertSame(\trim($details['key']), \trim($pem));

        \openssl_sign('payload', $signature, $key, OPENSSL_ALGO_SHA256);
        $public = \openssl_pkey_get_public($pem);

        $this->assertNotFalse($public);
        $this->assertSame(1, \openssl_verify('payload', $signature, $public, OPENSSL_ALGO_SHA256));
    }

    /**
     * A modulus with the high bit set must gain a leading zero byte in DER,
     * otherwise OpenSSL reads it as a negative INTEGER and rejects the key.
     * RSA moduli always have the high bit set, so the roundtrip above covers
     * it; this pins the short-exponent path too.
     */
    public function testCommonExponentProducesLoadableKey(): void
    {
        $key = \openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        $details = \openssl_pkey_get_details($key);

        $pem = JwkConverter::rsaToPem(
            $this->base64UrlEncode($details['rsa']['n']),
            $this->base64UrlEncode("\x01\x00\x01"), // 65537
        );

        $this->assertNotFalse(\openssl_pkey_get_public($pem));
    }

    public function testInvalidBase64UrlIsRejected(): void
    {
        $this->expectException(VerificationException::class);

        JwkConverter::rsaToPem('not base64url!!', 'AQAB');
    }

    public function testEmptyModulusIsRejected(): void
    {
        $this->expectException(VerificationException::class);

        JwkConverter::rsaToPem('', 'AQAB');
    }

    private function base64UrlEncode(string $data): string
    {
        return \rtrim(\strtr(\base64_encode($data), '+/', '-_'), '=');
    }
}

<?php

declare(strict_types=1);

namespace Utopia\Distribution\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Distribution\Exception\Invalid;
use Utopia\Distribution\JWT;

final class JWTTest extends TestCase
{
    private const array CLAIMS = ['iss' => 'issuer', 'aud' => 'https://audience.example/token', 'iat' => 1_700_000_000, 'exp' => 1_700_001_200];

    public function testES256SignatureIsRawAndVerifies(): void
    {
        [$private, $public] = $this->keys(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);

        $token = new JWT($private, JWT::ES256, 'KEY123')->encode(self::CLAIMS);

        [$header, $claims, $signature] = explode('.', $token);
        $raw = $this->decode($signature);
        $this->assertSame(64, \strlen($raw));
        $this->assertSame(['typ' => 'JWT', 'alg' => 'ES256', 'kid' => 'KEY123'], $this->json($header));
        $this->assertSame(self::CLAIMS, $this->json($claims));
        $this->assertSame(1, openssl_verify("{$header}.{$claims}", $this->der($raw), $public, OPENSSL_ALGO_SHA256));
    }

    public function testES256SignaturesOfEveryShapeVerify(): void
    {
        [$private, $public] = $this->keys(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $jwt = new JWT($private, JWT::ES256);

        for ($iteration = 0; $iteration < 256; $iteration++) {
            [$header, $claims, $signature] = explode('.', $jwt->encode(['jti' => $iteration] + self::CLAIMS));
            $raw = $this->decode($signature);

            $this->assertSame(64, \strlen($raw));
            $this->assertSame(1, openssl_verify("{$header}.{$claims}", $this->der($raw), $public, OPENSSL_ALGO_SHA256), "Signature {$iteration} does not verify");
        }
    }

    public function testRS256SignatureVerifies(): void
    {
        [$private, $public] = $this->keys(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);

        $token = new JWT($private, JWT::RS256)->encode(self::CLAIMS);

        [$header, $claims, $signature] = explode('.', $token);
        $this->assertSame(['typ' => 'JWT', 'alg' => 'RS256'], $this->json($header));
        $this->assertSame(256, \strlen($this->decode($signature)));
        $this->assertSame(1, openssl_verify("{$header}.{$claims}", $this->decode($signature), $public, OPENSSL_ALGO_SHA256));
    }

    public function testTamperedTokenFailsVerification(): void
    {
        [$private, $public] = $this->keys(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);

        $token = new JWT($private, JWT::ES256)->encode(self::CLAIMS);

        [$header, , $signature] = explode('.', $token);
        $forged = $this->encode(json_encode(['iss' => 'attacker'] + self::CLAIMS, JSON_THROW_ON_ERROR));
        $this->assertSame(0, openssl_verify("{$header}.{$forged}", $this->der($this->decode($signature)), $public, OPENSSL_ALGO_SHA256));
    }

    public function testUnsupportedAlgorithmThrows(): void
    {
        $this->expectException(Invalid::class);

        new JWT('secret', 'HS256');
    }

    public function testUnreadableKeyThrows(): void
    {
        $this->expectException(Invalid::class);
        $this->expectExceptionMessage('not a readable private key');

        new JWT("-----BEGIN PRIVATE KEY-----\nAAAA\n-----END PRIVATE KEY-----\n", JWT::RS256)->encode(self::CLAIMS);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function nonPemKeys(): iterable
    {
        yield 'text' => ['not a key'];
        yield 'path' => ['file:///etc/hosts'];
        yield 'path to a pem' => ['file://' . __FILE__ . "\n-----BEGIN PRIVATE KEY-----"];
    }

    #[DataProvider('nonPemKeys')]
    public function testNonPemKeyThrowsOnConstruction(string $key): void
    {
        $this->expectException(Invalid::class);
        $this->expectExceptionMessage('PEM text');

        new JWT($key, JWT::ES256);
    }

    public function testKeyPathIsNotRead(): void
    {
        [$private] = $this->keys(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $path = tempnam(sys_get_temp_dir(), 'key');
        $this->assertIsString($path);

        try {
            file_put_contents($path, $private);

            $this->expectException(Invalid::class);

            new JWT('file://' . $path, JWT::ES256)->encode(self::CLAIMS);
        } finally {
            unlink($path);
        }
    }

    public function testDebugOutputRedactsKey(): void
    {
        [$private] = $this->keys(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $jwt = new JWT($private, JWT::ES256, 'KEY123');

        ob_start();
        var_dump($jwt);
        $dump = (string) ob_get_clean();

        $this->assertStringNotContainsString('PRIVATE KEY', $dump);
        $this->assertStringNotContainsString('PRIVATE KEY', print_r($jwt, true));
        $this->assertStringContainsString('KEY123', $dump);
    }

    public function testStaleOpenSslErrorsAreCleared(): void
    {
        [$private] = $this->keys(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $this->assertFalse(openssl_pkey_get_private('stale'));

        new JWT($private, JWT::ES256)->encode(self::CLAIMS);

        $this->assertFalse(openssl_error_string());
    }

    public function testKeyOfAnotherTypeThrows(): void
    {
        [$private] = $this->keys(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);

        $this->expectException(Invalid::class);

        new JWT($private, JWT::ES256)->encode(self::CLAIMS);
    }

    public function testKeyIdIsOmittedWhenAbsent(): void
    {
        [$private] = $this->keys(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);

        [$header] = explode('.', new JWT($private, JWT::ES256)->encode(self::CLAIMS));

        $this->assertArrayNotHasKey('kid', $this->json($header));
    }

    public function testSlashesAreNotEscaped(): void
    {
        [$private] = $this->keys(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);

        [, $claims] = explode('.', new JWT($private, JWT::RS256)->encode(self::CLAIMS));

        $this->assertStringContainsString('"aud":"https://audience.example/token"', $this->decode($claims));
    }

    /**
     * @param array<string, int|string> $options
     *
     * @return array{string, string}
     */
    private function keys(array $options): array
    {
        $key = openssl_pkey_new($options);
        $this->assertInstanceOf(\OpenSSLAsymmetricKey::class, $key);
        $this->assertTrue(openssl_pkey_export($key, $private));
        $this->assertIsString($private);
        $details = openssl_pkey_get_details($key);
        $this->assertIsArray($details);
        $this->assertIsString($details['key']);

        return [$private, $details['key']];
    }

    /**
     * @return array<mixed>
     */
    private function json(string $segment): array
    {
        $decoded = json_decode($this->decode($segment), true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    private function encode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private function decode(string $segment): string
    {
        $bytes = base64_decode(strtr($segment, '-_', '+/'), true);
        $this->assertIsString($bytes);

        return $bytes;
    }

    private function der(string $raw): string
    {
        $body = $this->integer(substr($raw, 0, 32)) . $this->integer(substr($raw, 32));

        return "\x30" . pack('C', \strlen($body)) . $body;
    }

    private function integer(string $part): string
    {
        $part = ltrim($part, "\x00");
        if ($part === '' || (\ord($part[0]) & 0x80) !== 0) {
            $part = "\x00" . $part;
        }

        return "\x02" . pack('C', \strlen($part)) . $part;
    }
}

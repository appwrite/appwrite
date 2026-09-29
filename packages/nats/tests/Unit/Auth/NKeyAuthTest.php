<?php

declare(strict_types=1);

namespace Utopia\NATS\Tests\Unit\Auth;

use PHPUnit\Framework\TestCase;
use Utopia\NATS\Auth\NKeyAuth;

/**
 * Verifies that NKeyAuth derives the correct ed25519 public NKey from a seed
 * (the fix for CredentialsAuth previously sending an empty nkey). Correctness is
 * cross-checked against the sodium extension rather than a hardcoded vector.
 */
final class NKeyAuthTest extends TestCase
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    protected function setUp(): void
    {
        if (!\function_exists('sodium_crypto_sign_seed_keypair')) {
            $this->markTestSkipped('sodium extension required');
        }
    }

    public function testDerivesPublicKeyMatchingSodium(): void
    {
        $rawSeed = random_bytes(32);
        $keyPair = sodium_crypto_sign_seed_keypair($rawSeed);
        $publicRaw = sodium_crypto_sign_publickey($keyPair);

        $seedString = $this->encodeUserSeed($rawSeed);
        $auth = new NKeyAuth('', $seedString);

        $nkey = $auth->publicKey();

        // A user public NKey is 'U' + base32(1 prefix byte + 32-byte key + 2 CRC).
        $this->assertSame('U', $nkey[0]);
        $this->assertSame(56, \strlen($nkey));

        $decoded = $this->base32Decode($nkey);
        $this->assertSame(35, \strlen($decoded));
        $this->assertSame(160, \ord($decoded[0]), 'user role prefix byte');
        // The 32-byte payload must be exactly sodium's public key.
        $this->assertSame($publicRaw, substr($decoded, 1, 32));
    }

    public function testAuthenticateSignatureVerifiesAgainstDerivedKey(): void
    {
        $rawSeed = random_bytes(32);
        $keyPair = sodium_crypto_sign_seed_keypair($rawSeed);
        $publicRaw = sodium_crypto_sign_publickey($keyPair);

        $auth = new NKeyAuth('', $this->encodeUserSeed($rawSeed));
        $nonce = 'server-nonce-' . bin2hex(random_bytes(8));

        $result = $auth->authenticate($nonce);

        $this->assertSame($auth->publicKey(), $result['nkey']);
        $this->assertNotSame('', $result['nkey']);

        $signature = $this->base32Decode($result['sig']);
        $this->assertTrue(
            sodium_crypto_sign_verify_detached($signature, $nonce, $publicRaw),
            'signature must verify against the derived public key',
        );
    }

    public function testExplicitPublicKeyIsPreserved(): void
    {
        $rawSeed = random_bytes(32);
        $auth = new NKeyAuth('UEXPLICITKEY', $this->encodeUserSeed($rawSeed));

        $this->assertSame('UEXPLICITKEY', $auth->publicKey());
    }

    /**
     * Encode a raw 32-byte seed as a user NKey seed string. The seed's own CRC is
     * never validated by the client, so a zero CRC is fine here.
     */
    private function encodeUserSeed(string $rawSeed): string
    {
        $userRole = 160; // PrefixByteUser
        $seedMarker = 144; // PrefixByteSeed
        $b1 = $seedMarker | ($userRole >> 5);
        $b2 = ($userRole & 31) << 3;

        $raw = \chr($b1) . \chr($b2) . $rawSeed . "\x00\x00";

        return $this->base32Encode($raw);
    }

    private function base32Encode(string $input): string
    {
        $output = '';
        $buffer = 0;
        $bitsLeft = 0;

        for ($i = 0, $len = \strlen($input); $i < $len; $i++) {
            $buffer = ($buffer << 8) | \ord($input[$i]);
            $bitsLeft += 8;
            while ($bitsLeft >= 5) {
                $bitsLeft -= 5;
                $output .= self::ALPHABET[($buffer >> $bitsLeft) & 0x1F];
            }
        }

        if ($bitsLeft > 0) {
            $output .= self::ALPHABET[($buffer << (5 - $bitsLeft)) & 0x1F];
        }

        return $output;
    }

    private function base32Decode(string $input): string
    {
        $output = '';
        $buffer = 0;
        $bitsLeft = 0;

        for ($i = 0, $len = \strlen($input); $i < $len; $i++) {
            $val = strpos(self::ALPHABET, $input[$i]);
            $buffer = ($buffer << 5) | $val;
            $bitsLeft += 5;
            if ($bitsLeft >= 8) {
                $bitsLeft -= 8;
                $output .= \chr(($buffer >> $bitsLeft) & 0xFF);
            }
        }

        return $output;
    }
}

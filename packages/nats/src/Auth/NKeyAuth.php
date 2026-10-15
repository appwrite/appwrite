<?php

declare(strict_types=1);

namespace Utopia\NATS\Auth;

use Utopia\NATS\Exception\AuthenticationException;

final class NKeyAuth implements Authenticator
{
    public function __construct(
        private readonly string $publicKey,
        private readonly string $seed,
    ) {
        if (!\function_exists('sodium_crypto_sign_detached')) {
            throw new AuthenticationException('NKey authentication requires the sodium PHP extension');
        }
    }

    public function authenticate(?string $nonce = null): array
    {
        if ($nonce === null) {
            throw new AuthenticationException('NKey authentication requires a server nonce');
        }

        $rawSeed = $this->decodeSeed($this->seed);
        $keyPair = sodium_crypto_sign_seed_keypair($rawSeed);
        $secretKey = sodium_crypto_sign_secretkey($keyPair);
        $signature = sodium_crypto_sign_detached($nonce, $secretKey);

        return [
            'nkey' => $this->publicKey(),
            'sig' => $this->base32Encode($signature),
        ];
    }

    /**
     * The public NKey. When constructed without an explicit public key (e.g. from
     * a credentials file), it is derived from the seed so the server receives the
     * real ed25519 public key rather than an empty string.
     */
    public function publicKey(): string
    {
        if ($this->publicKey !== '') {
            return $this->publicKey;
        }

        return $this->derivePublicKey();
    }

    private function derivePublicKey(): string
    {
        $decoded = $this->base32Decode($this->seed);
        if (\strlen($decoded) < 4) {
            throw new AuthenticationException('Invalid NKey seed');
        }

        // The two-byte seed prefix encodes both the seed marker and the public
        // key's role byte (see nkeys EncodeSeed): b1 = SEED | (role >> 5),
        // b2 = (role & 31) << 3. Recover the role so the derived public key
        // carries the correct prefix (e.g. 'U' for a user).
        $b1 = \ord($decoded[0]);
        $b2 = \ord($decoded[1]);
        $role = (($b1 & 7) << 5) | (($b2 >> 3) & 31);

        $rawSeed = substr($decoded, 2, -2);
        $keyPair = sodium_crypto_sign_seed_keypair($rawSeed);
        $publicKey = sodium_crypto_sign_publickey($keyPair);

        return $this->encodePublicKey($role, $publicKey);
    }

    private function encodePublicKey(int $role, string $publicKey): string
    {
        $raw = \chr($role) . $publicKey;
        $crc = $this->crc16($raw);
        // CRC is appended little-endian, matching the nkeys encoding.
        $raw .= \chr($crc & 0xFF) . \chr(($crc >> 8) & 0xFF);

        return $this->base32Encode($raw);
    }

    /**
     * CRC-16/XMODEM (poly 0x1021, init 0x0000), as used by NATS nkeys.
     */
    private function crc16(string $data): int
    {
        $crc = 0;
        for ($i = 0, $len = \strlen($data); $i < $len; $i++) {
            $crc ^= \ord($data[$i]) << 8;
            for ($j = 0; $j < 8; $j++) {
                $crc = ($crc & 0x8000) !== 0 ? (($crc << 1) ^ 0x1021) & 0xFFFF : ($crc << 1) & 0xFFFF;
            }
        }

        return $crc & 0xFFFF;
    }

    private function decodeSeed(string $seed): string
    {
        $decoded = $this->base32Decode($seed);
        if (\strlen($decoded) < 4) {
            throw new AuthenticationException('Invalid NKey seed');
        }

        // Remove the 2-byte prefix and 2-byte CRC suffix
        return substr($decoded, 2, -2);
    }

    private function base32Decode(string $input): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $input = strtoupper(rtrim($input, '='));
        $output = '';
        $buffer = 0;
        $bitsLeft = 0;

        for ($i = 0, $len = \strlen($input); $i < $len; $i++) {
            $val = strpos($alphabet, $input[$i]);
            if ($val === false) {
                throw new AuthenticationException('Invalid base32 character in NKey seed');
            }
            $buffer = ($buffer << 5) | $val;
            $bitsLeft += 5;

            if ($bitsLeft >= 8) {
                $bitsLeft -= 8;
                $output .= \chr(($buffer >> $bitsLeft) & 0xFF);
            }
        }

        return $output;
    }

    private function base32Encode(string $input): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $output = '';
        $buffer = 0;
        $bitsLeft = 0;

        for ($i = 0, $len = \strlen($input); $i < $len; $i++) {
            $buffer = ($buffer << 8) | \ord($input[$i]);
            $bitsLeft += 8;

            while ($bitsLeft >= 5) {
                $bitsLeft -= 5;
                $output .= $alphabet[($buffer >> $bitsLeft) & 0x1F];
            }
        }

        if ($bitsLeft > 0) {
            $output .= $alphabet[($buffer << (5 - $bitsLeft)) & 0x1F];
        }

        return $output;
    }
}

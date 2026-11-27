<?php

declare(strict_types=1);

namespace Utopia\Distribution;

use Utopia\Distribution\Exception\Invalid;

final readonly class JWT
{
    public const string ES256 = 'ES256';

    public const string RS256 = 'RS256';

    private const int ES256_PART = 32;

    private const string ES256_CURVE = 'prime256v1';

    private const int DER_SEQUENCE = 0x30;

    private const int DER_INTEGER = 0x02;

    private const string PATH = 'file://';

    private const string PEM = '-----BEGIN';

    private const string REDACTED = '[redacted]';

    public function __construct(
        #[\SensitiveParameter]
        private string $key,
        private string $algorithm,
        private ?string $keyId = null,
    ) {
        if ($algorithm !== self::ES256 && $algorithm !== self::RS256) {
            throw new Invalid("JWT algorithm \"{$algorithm}\" is not supported");
        }

        if (str_starts_with($key, self::PATH) || !str_contains($key, self::PEM)) {
            throw new Invalid('JWT key must be PEM text, not a path');
        }
    }

    /**
     * @return array<string, ?string>
     */
    public function __debugInfo(): array
    {
        return ['key' => self::REDACTED, 'algorithm' => $this->algorithm, 'keyId' => $this->keyId];
    }

    /**
     * @param array<string, mixed> $claims
     */
    public function encode(array $claims): string
    {
        $header = ['typ' => 'JWT', 'alg' => $this->algorithm];

        if ($this->keyId !== null) {
            $header['kid'] = $this->keyId;
        }

        $input = $this->base64($this->json($header)) . '.' . $this->base64($this->json($claims));

        $privateKey = $this->privateKey();

        $signature = null;
        if (!openssl_sign($input, $signature, $privateKey, OPENSSL_ALGO_SHA256) || !\is_string($signature)) {
            throw new Invalid('JWT signing failed: ' . ($this->drain() ?? 'unknown OpenSSL error'));
        }

        if ($this->algorithm === self::ES256) {
            $signature = $this->raw($signature);
        }

        return $input . '.' . $this->base64($signature);
    }

    private function privateKey(): \OpenSSLAsymmetricKey
    {
        $this->drain();

        $key = openssl_pkey_get_private($this->key);
        if ($key === false) {
            $this->drain();
            throw new Invalid('JWT key is not a readable private key');
        }

        $details = openssl_pkey_get_details($key);
        if ($details === false) {
            throw new Invalid('JWT key details could not be read');
        }

        $type = $details['type'] ?? null;
        $elliptic = $details['ec'] ?? null;
        $usable = match ($this->algorithm) {
            self::ES256 => $type === OPENSSL_KEYTYPE_EC && \is_array($elliptic) && ($elliptic['curve_name'] ?? null) === self::ES256_CURVE,
            default => $type === OPENSSL_KEYTYPE_RSA,
        };

        if (!$usable) {
            throw new Invalid("JWT key does not match the {$this->algorithm} algorithm");
        }

        return $key;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function json(array $data): string
    {
        try {
            return json_encode($data, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (\JsonException $jsonException) {
            throw new Invalid('JWT segment could not be encoded: ' . $jsonException->getMessage(), previous: $jsonException);
        }
    }

    private function base64(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private function raw(string $der): string
    {
        $offset = 0;
        if ($this->byte($der, $offset) !== self::DER_SEQUENCE) {
            throw new Invalid('ES256 signature is not a DER sequence');
        }

        $length = $this->length($der, $offset);
        if ($offset + $length !== \strlen($der)) {
            throw new Invalid('ES256 signature has a malformed DER length');
        }

        $raw = $this->integer($der, $offset) . $this->integer($der, $offset);
        if ($offset !== \strlen($der)) {
            throw new Invalid('ES256 signature has trailing DER bytes');
        }

        return $raw;
    }

    private function integer(string $der, int &$offset): string
    {
        if ($this->byte($der, $offset) !== self::DER_INTEGER) {
            throw new Invalid('ES256 signature is missing a DER integer');
        }

        $length = $this->length($der, $offset);
        if ($offset + $length > \strlen($der)) {
            throw new Invalid('ES256 signature integer is truncated');
        }

        $value = ltrim(substr($der, $offset, $length), "\x00");
        $offset += $length;

        if (\strlen($value) > self::ES256_PART) {
            throw new Invalid('ES256 signature integer is too long');
        }

        return str_pad($value, self::ES256_PART, "\x00", STR_PAD_LEFT);
    }

    private function length(string $der, int &$offset): int
    {
        $length = $this->byte($der, $offset);
        if ($length < 0x80) {
            return $length;
        }

        $count = $length & 0x7f;
        if ($count === 0 || $count > 2) {
            throw new Invalid('ES256 signature has an unsupported DER length');
        }

        $length = 0;
        for ($index = 0; $index < $count; $index++) {
            $length = ($length << 8) | $this->byte($der, $offset);
        }

        return $length;
    }

    private function byte(string $der, int &$offset): int
    {
        if ($offset >= \strlen($der)) {
            throw new Invalid('ES256 signature is truncated');
        }

        return \ord($der[$offset++]);
    }

    private function drain(): ?string
    {
        $last = null;
        while (($error = openssl_error_string()) !== false) {
            $last = $error;
        }

        return $last;
    }
}

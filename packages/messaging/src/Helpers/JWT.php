<?php

namespace Utopia\Messaging\Helpers;

class JWT
{
    protected const ALGORITHMS = [
        'ES384' => ['openssl', OPENSSL_ALGO_SHA384],
        'ES256' => ['openssl', OPENSSL_ALGO_SHA256],
        'ES256K' => ['openssl', OPENSSL_ALGO_SHA256],
        'RS256' => ['openssl', OPENSSL_ALGO_SHA256],
        'RS384' => ['openssl', OPENSSL_ALGO_SHA384],
        'RS512' => ['openssl', OPENSSL_ALGO_SHA512],
        'HS256' => ['hash_hmac', 'SHA256'],
        'HS384' => ['hash_hmac', 'SHA384'],
        'HS512' => ['hash_hmac', 'SHA512'],
    ];

    /**
     * Convert an array to a JWT, signed with the given key and algorithm.
     *
     * @param  array<string, mixed>  $payload
     *
     * @throws \Exception
     */
    public static function encode(array $payload, string $key, string $algorithm, ?string $keyId = null): string
    {
        $header = [
            'typ' => 'JWT',
            'alg' => $algorithm,
        ];

        if (!\is_null($keyId)) {
            $header['kid'] = $keyId;
        }

        $header = json_encode($header, \JSON_UNESCAPED_SLASHES);
        $payload = json_encode($payload, \JSON_UNESCAPED_SLASHES);

        $segments = [];
        $segments[] = self::safeBase64Encode($header);
        $segments[] = self::safeBase64Encode($payload);

        $signingMaterial = implode('.', $segments);

        $signature = self::sign($signingMaterial, $key, $algorithm);

        $segments[] = self::safeBase64Encode($signature);

        return implode('.', $segments);
    }

    /**
     * @throws \Exception
     */
    private static function sign(string $data, string $key, string $alg): string
    {
        if (empty(static::ALGORITHMS[$alg])) {
            throw new \Exception('Algorithm not supported');
        }

        [$function, $algorithm] = static::ALGORITHMS[$alg];

        switch ($function) {
            case 'openssl':
                $signature = '';

                $success = openssl_sign($data, $signature, $key, $algorithm);

                if (!$success) {
                    throw new \Exception('OpenSSL sign failed for JWT');
                }

                switch ($alg) {
                    case 'ES256':
                    case 'ES256K':
                        $signature = self::signatureFromDER($signature, 256);
                        break;
                    case 'ES384':
                        $signature = self::signatureFromDER($signature, 384);
                        break;
                    default:
                        break;
                }

                return $signature;
            case 'hash_hmac':
                return hash_hmac((string) $algorithm, $data, $key, true);
            default:
                throw new \Exception('Algorithm not supported');
        }
    }

    /**
     * Encodes signature from a DER object.
     *
     * @param  string  $der binary signature in DER format
     * @param  int  $keySize the number of bits in the key
     */
    private static function signatureFromDER(string $der, int $keySize): string
    {
        // OpenSSL returns the ECDSA signatures as a binary ASN.1 DER SEQUENCE
        [$offset, $_] = self::readDER($der);
        [$offset, $r] = self::readDER($der, $offset);
        [$_, $s] = self::readDER($der, $offset);

        // Convert r-value and s-value from signed two's compliment to unsigned big-endian integers
        $r = ltrim((string) $r, "\x00");
        $s = ltrim((string) $s, "\x00");

        // Pad out r and s so that they are $keySize bits long
        $r = str_pad($r, $keySize / 8, "\x00", STR_PAD_LEFT);
        $s = str_pad($s, $keySize / 8, "\x00", STR_PAD_LEFT);

        return $r . $s;
    }

    /**
     * Reads binary DER-encoded data and decodes into a single object
     *
     * @param  int  $offset
     * to decode
     * @return array{int, string|null}
     */
    private static function readDER(string $der, int $offset = 0): array
    {
        $pos = $offset;
        $size = \strlen($der);
        $constructed = (\ord($der[$pos]) >> 5) & 0x01;
        $type = \ord($der[$pos++]) & 0x1F;

        // Length
        $len = \ord($der[$pos++]);
        if (($len & 0x80) !== 0) {
            $n = $len & 0x1F;
            $len = 0;
            while ($n-- && $pos < $size) {
                $len = ($len << 8) | \ord($der[$pos++]);
            }
        }

        // Value
        if ($type === 0x03) {
            $pos++; // Skip the first contents octet (padding indicator)
            $data = substr($der, $pos, $len - 1);
            $pos += $len - 1;
        } elseif ($constructed === 0) {
            $data = substr($der, $pos, $len);
            $pos += $len;
        } else {
            $data = null;
        }

        return [$pos, $data];
    }

    /**
     * Encode a string with URL-safe Base64.
     */
    private static function safeBase64Encode(string $input): string
    {
        return str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($input));
    }
}

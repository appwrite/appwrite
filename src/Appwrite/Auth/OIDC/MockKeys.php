<?php

namespace Appwrite\Auth\OIDC;

/**
 * Signing keys for the mock OAuth2 provider's ID tokens, used by e2e tests.
 *
 * The private key is a PUBLIC TEST FIXTURE - it protects nothing and must
 * never be used outside the mock provider (same risk class as the mock
 * provider's hardcoded client secret). Living in src/ lets the mock JWKS
 * route and the e2e test suite share the exact same key.
 */
class MockKeys
{
    public const KID = 'mock-kid-1';

    private const PRIVATE_KEY = <<<'PEM'
    -----BEGIN PRIVATE KEY-----
    MIIEvAIBADANBgkqhkiG9w0BAQEFAASCBKYwggSiAgEAAoIBAQDirFbh+drs8zp2
    UIAbPyai8FVdNXdH0oWXXsR64hKzxMQ0icX+vCbdkQv3GfdXsDjLDiO8nX14ptZI
    s6NSFKV8JweR6zVj5OmHbUZhPEliKsxFN/N8jJiHgYHdis7Yt9HSW+X0tkdtDPOm
    CJ/pnL4f494raIwhvsMrfz8SQIdsbnZ4/eVbBdKYXewbLJlvBq6AGKuxTZiZpSWf
    F+T81v+eGz27sH64IZJPu+15DTwMStmzzIqxzzaj25+vD1EKpDKzAxD96Hmc9b4Y
    38QKslnSp/FIBbASr4qgsIP1Q7oIHO0a3Zpd+6aZ6YNhywt/yxbcODLb3+B8yaf1
    pFIbKKmLAgMBAAECggEATGAJPkbzrxcdQbRKFeQnXotgF/Hl6PtUK/aweT8nUg8g
    lRs+7V/0MH+o6m+DWbZ0zGZNQEZIepisZv6wLv3p7HUyJcZ8zNXaodj999FaYItP
    HJuHnRW6Zx4J5d3ZaEg3mIuCZfvtAR92ESGi0BISNaiPuUyWuuAN3uAXHk1D1BKZ
    q0y2RKbrFJuo3V24uvp0dT5kfwTx2ac1AbW5YJ6Os7V6WiYFzF3hrdHT4+Z0LrNS
    HQMGExAXB3v7kLfZk57GeFTgClTAtxiDvf7ffWXY87nhTG2VariuIpqUSnEhjfWS
    jgBkNP6Pdb3qfv8BrtjLuZlUFHdpuE6TJuYvjv5NAQKBgQD9O0XaqMpHuPv9QFfb
    xTLZw5MVhqXgMTQIJCRJc0VNdiYOISRFprzNKXAm35GBqc34YXWxu4CbKAYi4SDA
    W69VNmLb57kH+nn+Q7kxkKPQRkmjyXSpQ8Ya24jDGbHbx9yJn9/DxlxHonKjqDJe
    UXq8IG7Uk3cizkbKAaz4URUqQQKBgQDlJryjPw1tkhIhwpJDvd6wUHDKnvDi4Nv5
    Iv9ZtR0ff+a9fCtZ5S5Z8IhdbftYM1UnD+ulBhhTwA87gO/cNRXP1ycZ2hm0yLQP
    JaV6DveITRtCXITPKFce/1JKM5d6sxSJjqpvR/3rYSGvRzlfdk1DlsrPP7hcTW09
    jmUKM0YoywKBgDePSK1H+VGxMYCIHH64joae1WeUqlI9GWhr3ZZL9zmeoYzaEqZB
    hg0ReWzeAoPLaMiFQZhkRjxElMwUTuZFd3ufuiL7fWpVt2xlGX3ZeUeaFFAeRD1b
    BF0iK6h6u7435JhBfovqupZw+uwTXDG7eM1L5GU5kZsOXRO3OGcnCxjBAoGARgD4
    fdKEUqXeHiwnrMQzZJ+eZXf61QSmjsyvP4OB2x6iqd5mC/dkmptNvWUc9MvxxpYp
    geeDxQoWXTI9lIMvH6h1zIMBeWYbA8mXbNtnqV8M5dAHzpVfUBvl0r9CFnzg2Eka
    LhbLLn4k4Twb/drRLcXCPWAU/TW2GqkGmwAg/dMCgYAnqLogWyfqF29OkjAfbvFo
    Mi75LJSefUHxvVqBHpL7qifjPB7BDisV83ITD9N5tY01bLIEql2IosbwrtdJOLVD
    RikD53vHf5/azTA6aTbgL1uORMpyZ9gBXphI+NLFA0lcGywmfcPNqkC5gfAgGOLF
    onPdLq+AkgxXsfZHzu4FiA==
    -----END PRIVATE KEY-----
    PEM;

    /**
     * @return array<string, string> the public key as an RSA JWK
     */
    public static function jwk(): array
    {
        $details = \openssl_pkey_get_details(\openssl_pkey_get_private(self::PRIVATE_KEY));

        return [
            'kty' => 'RSA',
            'use' => 'sig',
            'alg' => 'RS256',
            'kid' => self::KID,
            'n' => self::encode($details['rsa']['n']),
            'e' => self::encode($details['rsa']['e']),
        ];
    }

    /**
     * Mint an RS256-signed ID token with the given claims. Header overrides
     * allow tests to produce deliberately broken tokens (wrong kid, ...).
     *
     * @param array<string, mixed> $claims
     * @param array<string, mixed> $header
     */
    public static function sign(array $claims, array $header = []): string
    {
        $header = \array_merge(['alg' => 'RS256', 'kid' => self::KID, 'typ' => 'JWT'], $header);

        $headerEncoded = self::encode(\json_encode($header));
        $payloadEncoded = self::encode(\json_encode($claims));

        \openssl_sign($headerEncoded . '.' . $payloadEncoded, $signature, \openssl_pkey_get_private(self::PRIVATE_KEY), OPENSSL_ALGO_SHA256);

        return $headerEncoded . '.' . $payloadEncoded . '.' . self::encode($signature);
    }

    private static function encode(string $data): string
    {
        return \rtrim(\strtr(\base64_encode($data), '+/', '-_'), '=');
    }
}

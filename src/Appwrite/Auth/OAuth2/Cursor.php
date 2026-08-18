<?php

namespace Appwrite\Auth\OAuth2;

use Appwrite\Auth\OAuth2;

// Reference Material
// https://api.cursor.com/v1/origin/.well-known/openid-configuration
// https://cursor.com/docs/api/origin
//
// Cursor Origin apps are not a user-login provider: installs are approved on
// cursor.com and confirmed with an EdDSA-signed installation receipt instead
// of an authorization code, and API access tokens are minted from an app JWT
// signed with the app's Ed25519 private key ($appSecret holds the PKCS#8 PEM).
class Cursor extends OAuth2
{
    /**
     * Issuer from the OpenID configuration; also the API base. The JWKS the
     * receipts are verified against lives at {$endpoint}/keys.
     */
    protected string $endpoint = 'https://api.cursor.com/v1/origin';

    /**
     * @var array
     */
    protected array $scopes = [];

    /**
     * @var array
     */
    protected array $tokens = [];

    /**
     * @var array
     */
    protected array $jwks = [];

    public function getName(): string
    {
        return 'cursor';
    }

    public function getLoginURL(): string
    {
        // The install page ignores redirect_uri and state parameters -- the
        // callback URL is taken from the app's own configuration on Cursor.
        $params = [
            'client_id' => $this->appID,
        ];

        if (empty($this->getScopes())) {
            // Without an explicit scope list the install page rejects the
            // request ("requested_scopes requires at least one scope") unless
            // told to read the scopes from the app's registered metadata.
            $params['source'] = 'app-metadata';
        } else {
            $params['scope'] = \implode(' ', $this->getScopes());
        }

        return 'https://cursor.com/codebase/apps/install?' . \http_build_query($params);
    }

    /**
     * Verifies an installation receipt JWT against Cursor's JWKS and returns
     * its claims. The `sub` claim is the installation ID, `state` echoes the
     * state given to the install URL, and `namespace_id` is the workspace.
     *
     * @return array
     * @throws Exception
     */
    public function verifyReceipt(string $receipt): array
    {
        $segments = \explode('.', $receipt);

        if (\count($segments) !== 3) {
            throw new Exception('Installation receipt is not a JWT');
        }

        [$headerSegment, $claimsSegment, $signatureSegment] = $segments;

        $header = \json_decode($this->base64UrlDecode($headerSegment), true);
        $claims = \json_decode($this->base64UrlDecode($claimsSegment), true);
        $signature = $this->base64UrlDecode($signatureSegment);

        if (!\is_array($header) || !\is_array($claims)) {
            throw new Exception('Installation receipt is malformed');
        }

        if (($header['alg'] ?? '') !== 'EdDSA') {
            throw new Exception('Installation receipt has an unexpected algorithm');
        }

        // Receipts are documented to carry this media type; tolerate its
        // absence, but never another Cursor-signed token kind in its place.
        if (isset($header['typ']) && $header['typ'] !== 'origin-installation-receipt+jwt') {
            throw new Exception('Installation receipt has an unexpected type');
        }

        $key = $this->getPublicKey($header['kid'] ?? '');

        if (
            \strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES
            || !\sodium_crypto_sign_verify_detached($signature, $headerSegment . '.' . $claimsSegment, $key)
        ) {
            throw new Exception('Installation receipt signature is invalid');
        }

        if (($claims['iss'] ?? '') !== $this->endpoint) {
            throw new Exception('Installation receipt issuer is invalid');
        }

        if (($claims['aud'] ?? '') !== $this->appID) {
            throw new Exception('Installation receipt audience does not match the configured client ID');
        }

        $now = \time();
        $leeway = 60;

        // Receipts are short-lived, single-use artifacts (about a five-minute
        // window per Cursor's verification rules).
        if (isset($claims['exp']) && $now >= (int)$claims['exp'] + $leeway) {
            throw new Exception('Installation receipt has expired');
        }

        if (isset($claims['nbf']) && $now < (int)$claims['nbf'] - $leeway) {
            throw new Exception('Installation receipt is not yet valid');
        }

        if (isset($claims['iat']) && $now < (int)$claims['iat'] - $leeway) {
            throw new Exception('Installation receipt is issued in the future');
        }

        if (empty($claims['sub'])) {
            throw new Exception('Installation receipt is missing the installation ID');
        }

        return $claims;
    }

    /**
     * App JWT authenticating this app to Cursor's API, signed with the app's
     * Ed25519 private key. Used as a Bearer token for app-level endpoints,
     * most importantly minting installation access tokens.
     */
    public function getAppJWT(): string
    {
        $now = \time();

        $headerSegment = $this->base64UrlEncode(\json_encode([
            'alg' => 'EdDSA',
            'kid' => $this->appID,
            'typ' => 'JWT',
        ]));

        $claimsSegment = $this->base64UrlEncode(\json_encode([
            'iss' => $this->appID,
            'aud' => 'origin-apps',
            'iat' => $now,
            'exp' => $now + 300,
        ]));

        $signature = \sodium_crypto_sign_detached($headerSegment . '.' . $claimsSegment, $this->getSigningKey());

        return $headerSegment . '.' . $claimsSegment . '.' . $this->base64UrlEncode($signature);
    }

    /**
     * There is no authorization-code exchange: the "code" is the installation
     * ID from the receipt's `sub` claim, exchanged for a short-lived (at most
     * 15 minutes) installation access token. An empty or omitted scope list
     * inherits the full installation grant.
     *
     * @param string $code
     *
     * @return array
     */
    protected function getTokens(string $code): array
    {
        if (empty($this->tokens)) {
            $payload = empty($this->getScopes())
                ? '{}'
                : \json_encode(['scopes' => $this->getScopes()]);

            $response = \json_decode($this->request(
                'POST',
                $this->endpoint . '/app/installations/' . \urlencode($code) . '/access_tokens',
                [
                    'Authorization: Bearer ' . $this->getAppJWT(),
                    'Content-Type: application/json',
                ],
                $payload
            ), true);

            $this->tokens = [
                'access_token' => $response['token'] ?? '',
                'expires_in' => isset($response['expiresAt'])
                    ? \max(0, (int)\strtotime($response['expiresAt']) - \time())
                    : 0,
            ];
        }

        return $this->tokens;
    }

    /**
     * Installation tokens cannot be refreshed -- a new one is minted from the
     * installation ID with the app JWT.
     *
     * @param string $refreshToken
     *
     * @return array
     */
    public function refreshTokens(string $refreshToken): array
    {
        $this->tokens = [];

        return $this->getTokens($refreshToken);
    }

    /**
     * End-to-end credential check used when enabling the provider: signing
     * the app JWT proves the private key is well-formed, and the zero-cost
     * rate-limit endpoint rejects it with a 401 unless the app ID and key
     * match a registered app.
     *
     * @throws Exception
     */
    public function verifyCredentials(): void
    {
        $this->request('GET', $this->endpoint . '/rate_limit', [
            'Authorization: Bearer ' . $this->getAppJWT(),
        ]);
    }

    /**
     * Cursor Origin apps act as an installation, never as a user, so there is
     * no user identity behind an access token.
     */
    public function getUserID(string $accessToken): string
    {
        return '';
    }

    public function getUserEmail(string $accessToken): string
    {
        return '';
    }

    public function isEmailVerified(string $accessToken): bool
    {
        return false;
    }

    public function getUserName(string $accessToken): string
    {
        return '';
    }

    /**
     * Ed25519 public key for a JWKS key ID.
     *
     * @throws Exception
     */
    protected function getPublicKey(string $kid): string
    {
        if (empty($this->jwks)) {
            $this->jwks = \json_decode($this->request('GET', $this->endpoint . '/keys'), true)['keys'] ?? [];
        }

        foreach ($this->jwks as $key) {
            if (
                ($key['kid'] ?? '') === $kid
                && ($key['kty'] ?? '') === 'OKP'
                && ($key['crv'] ?? '') === 'Ed25519'
            ) {
                $publicKey = $this->base64UrlDecode($key['x'] ?? '');

                if (\strlen($publicKey) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
                    return $publicKey;
                }
            }
        }

        throw new Exception('Installation receipt is signed with an unknown key');
    }

    /**
     * Ed25519 secret key derived from the PKCS#8 PEM in $appSecret.
     *
     * @throws Exception
     */
    protected function getSigningKey(): string
    {
        // Keys configured through environment variables carry literal \n
        // sequences instead of newlines.
        $pem = \str_replace(['\n', '-----BEGIN PRIVATE KEY-----', '-----END PRIVATE KEY-----'], '', $this->appSecret);
        $der = \base64_decode(\preg_replace('/\s+/', '', $pem) ?? '', true);

        // RFC 8410 PKCS#8 wraps the 32-byte Ed25519 seed in a nested octet
        // string tagged 0x04 0x22 0x04 0x20.
        $offset = \is_string($der) ? \strpos($der, "\x04\x22\x04\x20") : false;

        if ($offset === false || \strlen($der) < $offset + 4 + SODIUM_CRYPTO_SIGN_SEEDBYTES) {
            throw new Exception('Private key is not a PKCS#8 Ed25519 key');
        }

        $seed = \substr($der, $offset + 4, SODIUM_CRYPTO_SIGN_SEEDBYTES);

        return \sodium_crypto_sign_secretkey(\sodium_crypto_sign_seed_keypair($seed));
    }

    protected function base64UrlEncode(string $data): string
    {
        return \rtrim(\strtr(\base64_encode($data), '+/', '-_'), '=');
    }

    protected function base64UrlDecode(string $data): string
    {
        $remainder = \strlen($data) % 4;
        if ($remainder > 0) {
            $data .= \str_repeat('=', 4 - $remainder);
        }

        return (string)\base64_decode(\strtr($data, '-_', '+/'), true);
    }
}

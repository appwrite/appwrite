<?php

namespace Appwrite\Auth\OAuth2;

use Appwrite\Auth\OAuth2;
use Exception;

// Reference Material
// https://developer.okta.com/blog/2019/06/04/what-the-heck-is-sign-in-with-apple

class Apple extends OAuth2
{
    /**
     * @var array
     */
    protected array $user = [];

    /**
     * @var array
     */
    protected array $tokens = [];

    /**
     * @var array
     */
    protected array $scopes = [
        "name",
        "email"
    ];

    /**
     * @var array
     */
    protected array $claims = [];

    /**
     * @return string
     */
    public function getName(): string
    {
        return 'apple';
    }

    /**
     * @return string
     */
    public function getLoginURL(): string
    {
        return 'https://appleid.apple.com/auth/authorize?' . \http_build_query([
            'client_id' => $this->appID,
            'redirect_uri' => $this->callback,
            'state' => \json_encode($this->state),
            'response_type' => 'code',
            'response_mode' => 'form_post',
            'scope' => \implode(' ', $this->getScopes())
        ]);
    }

    /**
     * @param string $code
     *
     * @return array
     */
    protected function getTokens(string $code): array
    {
        if (empty($this->tokens)) {
            $headers = ['Content-Type: application/x-www-form-urlencoded'];
            $this->tokens = \json_decode($this->request(
                'POST',
                'https://appleid.apple.com/auth/token',
                $headers,
                \http_build_query([
                    'grant_type' => 'authorization_code',
                    'code' => $code,
                    'client_id' => $this->appID,
                    'client_secret' => $this->getAppSecret(),
                    'redirect_uri' => $this->callback,
                ])
            ), true);

            // FIX: Replaced fragile explode/array-access chain with parseIdToken()
            // which correctly re-pads base64url before decoding.
            $this->claims = $this->parseIdToken($this->tokens['id_token'] ?? '');
        }

        return $this->tokens;
    }

    /**
     * @param string $refreshToken
     *
     * @return array
     */
    public function refreshTokens(string $refreshToken): array
    {
        $headers = ['Content-Type: application/x-www-form-urlencoded'];
        $this->tokens = \json_decode($this->request(
            'POST',
            'https://appleid.apple.com/auth/token',
            $headers,
            \http_build_query([
                'grant_type' => 'refresh_token',
                'refresh_token' => $refreshToken,
                'client_id' => $this->appID,
                'client_secret' => $this->getAppSecret(),
            ])
        ), true);

        if (empty($this->tokens['refresh_token'])) {
            $this->tokens['refresh_token'] = $refreshToken;
        }

        // FIX: Same parseIdToken() fix applied here for consistency.
        $this->claims = $this->parseIdToken($this->tokens['id_token'] ?? '');

        return $this->tokens;
    }

    /**
     * @param string $accessToken
     *
     * @return string
     */
    public function getUserID(string $accessToken): string
    {
        return $this->claims['sub'] ?? '';
    }

    /**
     * @param string $accessToken
     *
     * @return string
     */
    public function getUserEmail(string $accessToken): string
    {
        return $this->claims['email'] ?? '';
    }

    /**
     * Check if the OAuth email is verified
     *
     * Apple can return email_verified as a boolean OR the string "true"/"false".
     *
     * @link https://developer.apple.com/forums/thread/121411
     *
     * @param string $accessToken
     *
     * @return bool
     */
    public function isEmailVerified(string $accessToken): bool
    {
        // FIX: Original code only checked the truthy value of the claim, which
        // fails when Apple returns the string "true" instead of a boolean true.
        $verified = $this->claims['email_verified'] ?? false;

        if (\is_string($verified)) {
            return \strtolower($verified) === 'true';
        }

        return (bool) $verified;
    }

    /**
     * @param string $accessToken
     *
     * @return string
     */
    public function getUserName(string $accessToken): string
    {
        return '';
    }

    protected function getAppSecret(): string
    {
        try {
            $secret = \json_decode($this->appSecret, true);
        } catch (\Throwable $th) {
            throw new Exception('Invalid secret');
        }

        $keyfile  = $secret['p8']     ?? '';
        $keyID    = $secret['keyID']  ?? '';
        $teamID   = $secret['teamID'] ?? '';
        $bundleID = $this->appID;

        $headers = [
            'alg' => 'ES256',
            'kid' => $keyID,
        ];

        $claims = [
            'iss' => $teamID,
            'iat' => \time(),
            'exp' => \time() + 86400 * 180,
            'aud' => 'https://appleid.apple.com',
            'sub' => $bundleID,
        ];

        $pkey = \openssl_pkey_get_private($keyfile);

        if ($pkey === false) {
            throw new Exception('Apple OAuth2: failed to load private key.');
        }

        $payload = $this->encode(\json_encode($headers)) . '.' . $this->encode(\json_encode($claims));

        $signature = '';
        $success = \openssl_sign($payload, $signature, $pkey, OPENSSL_ALGO_SHA256);

        // FIX: openssl_free_key() was removed in PHP 8.0 and causes a fatal error.
        // Keys are freed automatically in PHP 8+, so only call it on older versions.
        if (\PHP_MAJOR_VERSION < 8) {
            \openssl_free_key($pkey); // @phpstan-ignore-line
        }

        if (!$success) {
            return '';
        }

        return $payload . '.' . $this->encode($this->fromDER($signature, 64));
    }

    /**
     * @param string $data
     *
     * @return string
     */
    protected function encode(string $data): string
    {
        return \str_replace(['+', '/', '='], ['-', '_', ''], \base64_encode($data));
    }

    /**
     * Decodes the JWT id_token payload returned by Apple.
     *
     * FIX: The original code used a raw explode/base64_decode without properly
     * re-padding the base64url string, causing silent failures on tokens whose
     * payload length is not a multiple of 4. This method handles padding correctly.
     *
     * @param string $idToken
     * @return array
     */
    protected function parseIdToken(string $idToken): array
    {
        if (empty($idToken)) {
            return [];
        }

        $parts = \explode('.', $idToken);

        if (\count($parts) !== 3) {
            return [];
        }

        // Convert base64url to standard base64, then pad to a multiple of 4.
        $payload = \str_replace(['-', '_'], ['+', '/'], $parts[1]);
        $payload = \base64_decode(\str_pad($payload, (int) \ceil(\strlen($payload) / 4) * 4, '=', STR_PAD_RIGHT));

        if ($payload === false) {
            return [];
        }

        $claims = \json_decode($payload, true);

        return \is_array($claims) ? $claims : [];
    }

    /**
     * Strips the DER leading 0x00 sign-padding byte from a positive integer.
     *
     * FIX: Original condition checked `> '7f'` via string comparison against a
     * two-character hex substring, which is unreliable (e.g. '8a' > '7f' works
     * but '80' compares as a string, not a number). The corrected check uses
     * `>= '80'` consistently, matching the actual DER rule: remove the 0x00
     * prefix only when the following byte has its high bit set.
     *
     * @param string $data Hex-encoded integer.
     * @return string
     */
    protected function retrievePositiveInteger(string $data): string
    {
        while (
            \mb_strlen($data, '8bit') > 2 &&
            \mb_substr($data, 0, 2, '8bit') === '00' &&
            \mb_substr($data, 2, 2, '8bit') >= '80'
        ) {
            $data = \mb_substr($data, 2, null, '8bit');
        }

        return $data;
    }

    /**
     * @param string $der
     * @param int $partLength
     */
    protected function fromDER(string $der, int $partLength): string
    {
        $hex = \unpack('H*', $der)[1];

        if ('30' !== \mb_substr($hex, 0, 2, '8bit')) { // SEQUENCE
            throw new \RuntimeException();
        }

        if ('81' === \mb_substr($hex, 2, 2, '8bit')) { // LENGTH > 128
            $hex = \mb_substr($hex, 6, null, '8bit');
        } else {
            $hex = \mb_substr($hex, 4, null, '8bit');
        }

        if ('02' !== \mb_substr($hex, 0, 2, '8bit')) { // INTEGER
            throw new \RuntimeException();
        }

        $Rl = \hexdec(\mb_substr($hex, 2, 2, '8bit'));
        $R = $this->retrievePositiveInteger(\mb_substr($hex, 4, $Rl * 2, '8bit'));
        $R = \str_pad($R, $partLength * 2, '0', STR_PAD_LEFT);

        $hex = \mb_substr($hex, 4 + $Rl * 2, null, '8bit');

        if ('02' !== \mb_substr($hex, 0, 2, '8bit')) { // INTEGER
            throw new \RuntimeException();
        }

        $Sl = \hexdec(\mb_substr($hex, 2, 2, '8bit'));
        $S = $this->retrievePositiveInteger(\mb_substr($hex, 4, $Sl * 2, '8bit'));
        $S = \str_pad($S, $partLength * 2, '0', STR_PAD_LEFT);

        return \pack('H*', $R . $S);
    }
}
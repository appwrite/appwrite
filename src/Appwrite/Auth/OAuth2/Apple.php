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

            $this->claims = (isset($this->tokens['id_token'])) ? \explode('.', $this->tokens['id_token']) : [0 => '', 1 => ''];
            $this->claims = (isset($this->claims[1])) ? \json_decode(\base64_decode($this->claims[1]), true) : [];
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

        $this->claims = (isset($this->tokens['id_token'])) ? \explode('.', $this->tokens['id_token']) : [0 => '', 1 => ''];
        $this->claims = (isset($this->claims[1])) ? \json_decode(\base64_decode($this->claims[1]), true) : [];

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
     * @link https://developer.apple.com/forums/thread/121411
     *
     * @param string $accessToken
     *
     * @return bool
     */
    public function isEmailVerified(string $accessToken): bool
    {
        if ($this->claims['email_verified'] ?? false) {
            return true;
        }

        return false;
    }

    /**
     * @param string $accessToken
     *
     * @return string
     */
    public function getUserName(string $accessToken): string
    {
        if (
            isset($this->claims['email']) &&
            !empty($this->claims['email']) &&
            isset($this->claims['email_verified']) &&
            $this->claims['email_verified'] === 'true'
        ) {
            return $this->claims['email'];
        }

        return '';
    }

    protected function getAppSecret(): string
    {
        try {
            $secret = \json_decode($this->appSecret, true);
        } catch (\Throwable $th) {
            throw new Exception('Invalid secret');
        }

        $keyfile = (isset($secret['p8'])) ? $secret['p8'] : ''; // Your p8 Key file
        $keyID = (isset($secret['keyID'])) ? $secret['keyID'] : ''; // Your Key ID
        $teamID = (isset($secret['teamID'])) ? $secret['teamID'] : ''; // Your Team ID (see Developer Portal)
        $bundleID =  $this->appID; // Your Bundle ID

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

        $payload = $this->encode(\json_encode($headers)) . '.' . $this->encode(\json_encode($claims));

        $signature = '';

        $success = \openssl_sign($payload, $signature, $pkey, OPENSSL_ALGO_SHA256);

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
    protected function encode($data): string
    {
        return \str_replace(['+', '/', '='], ['-', '_', ''], \base64_encode($data));
    }

    /**
     * @param string $data
     */
    protected function retrievePositiveInteger(string $data): string
    {
        while ('00' === \mb_substr($data, 0, 2, '8bit') && \mb_substr($data, 2, 2, '8bit') > '7f') {
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
        $R = \str_pad($R, $partLength, '0', STR_PAD_LEFT);

        $hex = \mb_substr($hex, 4 + $Rl * 2, null, '8bit');

        if ('02' !== \mb_substr($hex, 0, 2, '8bit')) { // INTEGER
            throw new \RuntimeException();
        }

        $Sl = \hexdec(\mb_substr($hex, 2, 2, '8bit'));
        $S = $this->retrievePositiveInteger(\mb_substr($hex, 4, $Sl * 2, '8bit'));
        $S = \str_pad($S, $partLength, '0', STR_PAD_LEFT);

        return \pack('H*', $R . $S);
    }
}

<?php

namespace Appwrite\Auth\OAuth2;

use Appwrite\Auth\OAuth2;
use Appwrite\OpenSSL\OpenSSL;
use Utopia\System\System;

// Reference Material
// https://docs.x.com/fundamentals/authentication/oauth-2-0/authorization-code
// https://docs.x.com/x-api/users/get-me

class X extends OAuth2
{
    private const PKCE_STATE_KEY = '_pkce';

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
        'tweet.read',
        'users.read',
        'users.email',
        'offline.access',
    ];

    /**
     * @var string
     */
    private string $pkceVerifier = '';

    /**
     * @return string
     */
    public function getName(): string
    {
        return 'x';
    }

    public function getLoginURL(): string
    {
        $state = $this->state;
        $state[self::PKCE_STATE_KEY] = $this->encryptPKCEVerifier($this->getPKCEVerifier());

        return 'https://x.com/i/oauth2/authorize?' . \http_build_query([
            'response_type' => 'code',
            'client_id' => $this->appID,
            'redirect_uri' => $this->callback,
            'scope' => \implode(' ', $this->getScopes()),
            'state' => $this->base64UrlEncode(\json_encode($state, JSON_THROW_ON_ERROR)),
            'code_challenge' => $this->getPKCEChallenge(),
            'code_challenge_method' => 'S256',
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
            $this->tokens = $this->decodeJsonObject($this->request(
                'POST',
                'https://api.x.com/2/oauth2/token',
                $this->tokenEndpointHeaders(),
                \http_build_query([
                    'code' => $code,
                    'client_id' => $this->appID,
                    'grant_type' => 'authorization_code',
                    'redirect_uri' => $this->callback,
                    'code_verifier' => $this->getPKCEVerifier(),
                ])
            ));
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
        $this->tokens = $this->decodeJsonObject($this->request(
            'POST',
            'https://api.x.com/2/oauth2/token',
            $this->tokenEndpointHeaders(),
            \http_build_query([
                'client_id' => $this->appID,
                'refresh_token' => $refreshToken,
                'grant_type' => 'refresh_token',
            ])
        ));

        if (empty($this->tokens['refresh_token'])) {
            $this->tokens['refresh_token'] = $refreshToken;
        }

        return $this->tokens;
    }

    /**
     * @param string $accessToken
     *
     * @return string
     */
    public function getUserID(string $accessToken): string
    {
        $user = $this->getUser($accessToken);

        return $user['data']['id'] ?? '';
    }

    /**
     * @param string $accessToken
     *
     * @return string
     */
    public function getUserEmail(string $accessToken): string
    {
        $user = $this->getUser($accessToken);

        return $user['data']['confirmed_email'] ?? '';
    }

    /**
     * Check if the OAuth email is verified.
     *
     * X returns a confirmed email only when the app has email access enabled
     * and the authenticated user has a confirmed email address.
     *
     * @param string $accessToken
     *
     * @return bool
     */
    public function isEmailVerified(string $accessToken): bool
    {
        return !empty($this->getUserEmail($accessToken));
    }

    /**
     * @param string $accessToken
     *
     * @return string
     */
    public function getUserName(string $accessToken): string
    {
        $user = $this->getUser($accessToken);

        return $user['data']['name'] ?? '';
    }

    /**
     * @param string $accessToken
     *
     * @return array
     */
    protected function getUser(string $accessToken): array
    {
        if (empty($this->user)) {
            $this->user = $this->decodeJsonObject($this->request(
                'GET',
                'https://api.x.com/2/users/me?user.fields=confirmed_email',
                ['Authorization: Bearer ' . $accessToken]
            ));
        }

        return $this->user;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function parseState(string $state): ?array
    {
        $decoded = $this->base64UrlDecode($state);
        if ($decoded === false) {
            return null;
        }

        $parsed = \json_decode($decoded, true);

        if (!\is_array($parsed)) {
            return null;
        }

        $pkce = $parsed[self::PKCE_STATE_KEY] ?? null;

        if (\is_array($pkce)) {
            $this->pkceVerifier = $this->decryptPKCEVerifier($pkce);
        }

        unset($parsed[self::PKCE_STATE_KEY]);

        return $parsed;
    }

    /**
     * @return list<string>
     */
    private function tokenEndpointHeaders(): array
    {
        return [
            'Authorization: Basic ' . \base64_encode($this->appID . ':' . $this->appSecret),
            'Content-Type: application/x-www-form-urlencoded',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJsonObject(string $json): array
    {
        $decoded = \json_decode($json, true);

        return \is_array($decoded) ? $decoded : [];
    }

    private function getPKCEVerifier(): string
    {
        if ($this->pkceVerifier === '') {
            $this->pkceVerifier = $this->base64UrlEncode(\random_bytes(64));
        }

        return $this->pkceVerifier;
    }

    private function getPKCEChallenge(): string
    {
        return $this->base64UrlEncode(\hash('sha256', $this->getPKCEVerifier(), true));
    }

    private function encryptPKCEVerifier(string $verifier): array
    {
        $iv = OpenSSL::randomPseudoBytes(OpenSSL::cipherIVLength(OpenSSL::CIPHER_AES_128_GCM));
        $key = $this->getPKCEStateKey();
        $tag = null;

        $data = OpenSSL::encrypt($verifier, OpenSSL::CIPHER_AES_128_GCM, $key, OPENSSL_RAW_DATA, $iv, $tag);

        if ($data === false || $tag === null) {
            throw new \Exception('Failed to encrypt PKCE verifier.');
        }

        return [
            'data' => $this->base64UrlEncode($data),
            'iv' => \bin2hex($iv),
            'tag' => \bin2hex($tag),
        ];
    }

    private function decryptPKCEVerifier(array $payload): string
    {
        $data = $payload['data'] ?? '';
        $iv = $payload['iv'] ?? '';
        $tag = $payload['tag'] ?? '';

        if ($data === '' || $iv === '' || $tag === '') {
            return '';
        }

        $decodedData = $this->base64UrlDecode($data);
        $decodedIv = \hex2bin($iv);
        $decodedTag = \hex2bin($tag);

        if ($decodedData === false || $decodedIv === false || $decodedTag === false) {
            return '';
        }

        return OpenSSL::decrypt(
            $decodedData,
            OpenSSL::CIPHER_AES_128_GCM,
            $this->getPKCEStateKey(),
            OPENSSL_RAW_DATA,
            $decodedIv,
            $decodedTag
        ) ?: '';
    }

    private function getPKCEStateKey(): string
    {
        $key = System::getEnv('_APP_OPENSSL_KEY_V1', '');

        if ($key === '') {
            throw new \Exception('X OAuth2 requires _APP_OPENSSL_KEY_V1 to encrypt PKCE state.');
        }

        return $key;
    }

    private function base64UrlEncode(string $value): string
    {
        return \rtrim(\strtr(\base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): string|false
    {
        $padding = \strlen($value) % 4;
        if ($padding > 0) {
            $value .= \str_repeat('=', 4 - $padding);
        }

        return \base64_decode(\strtr($value, '-_', '+/'), true);
    }

}

<?php

namespace Appwrite\Auth\OAuth2;

use Appwrite\Auth\OAuth2;

// Reference Material
// https://docs.x.com/fundamentals/authentication/oauth-2-0/authorization-code
// https://docs.x.com/x-api/users/get-me

class X extends OAuth2
{
    use PKCE;

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
     * @return string
     */
    public function getName(): string
    {
        return 'x';
    }

    public function getLoginURL(): string
    {
        $state = $this->state;
        $state = $this->withPKCEState($state);

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
        // X only populates confirmed_email once the address is confirmed, so its presence is the verification signal
        $user = $this->getUser($accessToken);

        return !empty($user['data']['confirmed_email']);
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

        return $this->restorePKCEState($parsed);
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
}

<?php

namespace Appwrite\Auth\OAuth2;

use Appwrite\Auth\OAuth2;

// Reference Material
// https://docs.x.com/fundamentals/authentication/oauth-2-0/authorization-code
// https://docs.x.com/x-api/users/get-me

class X extends OAuth2
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

    /**
     * @return string
     */
    public function getLoginURL(): string
    {
        return 'https://x.com/i/oauth2/authorize?' . \http_build_query([
            'response_type' => 'code',
            'client_id' => $this->appID,
            'redirect_uri' => $this->callback,
            'scope' => \implode(' ', $this->getScopes()),
            'state' => \json_encode($this->state),
            'code_challenge' => $this->getCodeChallenge(),
            'code_challenge_method' => 'S256',
        ]);
    }

    /**
     * @return string
     */
    public function getPKCEVerifier(): string
    {
        if (empty($this->pkceVerifier)) {
            $this->pkceVerifier = $this->base64UrlEncode(\random_bytes(32));
        }

        return $this->pkceVerifier;
    }

    /**
     * @param string $pkceVerifier
     *
     * @return void
     */
    public function setPKCEVerifier(string $pkceVerifier): void
    {
        $this->pkceVerifier = $pkceVerifier;
    }

    /**
     * @param string $code
     *
     * @return array
     */
    protected function getTokens(string $code): array
    {
        if (empty($this->tokens)) {
            if (empty($this->pkceVerifier)) {
                throw new Exception(\json_encode([
                    'error' => 'invalid_request',
                    'error_description' => 'Missing PKCE verifier.',
                ]), 400);
            }

            $headers = [
                'Authorization: Basic ' . \base64_encode($this->appID . ':' . $this->appSecret),
                'Content-Type: application/x-www-form-urlencoded',
            ];

            $this->tokens = \json_decode($this->request(
                'POST',
                'https://api.x.com/2/oauth2/token',
                $headers,
                \http_build_query([
                    'code' => $code,
                    'client_id' => $this->appID,
                    'grant_type' => 'authorization_code',
                    'redirect_uri' => $this->callback,
                    'code_verifier' => $this->getPKCEVerifier(),
                ])
            ), true);
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
        $headers = [
            'Authorization: Basic ' . \base64_encode($this->appID . ':' . $this->appSecret),
            'Content-Type: application/x-www-form-urlencoded',
        ];

        $this->tokens = \json_decode($this->request(
            'POST',
            'https://api.x.com/2/oauth2/token',
            $headers,
            \http_build_query([
                'client_id' => $this->appID,
                'refresh_token' => $refreshToken,
                'grant_type' => 'refresh_token',
            ])
        ), true);

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
            $this->user = \json_decode($this->request(
                'GET',
                'https://api.x.com/2/users/me?user.fields=confirmed_email',
                ['Authorization: Bearer ' . $accessToken]
            ), true);
        }

        return $this->user;
    }

    /**
     * @return string
     */
    private function getCodeChallenge(): string
    {
        return $this->base64UrlEncode(\hash('sha256', $this->getPKCEVerifier(), true));
    }

    /**
     * @param string $value
     *
     * @return string
     */
    private function base64UrlEncode(string $value): string
    {
        return \rtrim(\strtr(\base64_encode($value), '+/', '-_'), '=');
    }
}

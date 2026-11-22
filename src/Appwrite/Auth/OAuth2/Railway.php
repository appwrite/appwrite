<?php

namespace Appwrite\Auth\OAuth2;

use Appwrite\Auth\OAuth2;

// Reference Material
// https://docs.railway.com/integrations/oauth

class Railway extends OAuth2
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
        'openid',
        'email',
        'profile',
        'offline_access',
    ];

    /**
     * @return string
     */
    public function getName(): string
    {
        return 'railway';
    }

    /**
     * @return string
     */
    public function getLoginURL(): string
    {
        $state = $this->withPKCEState($this->state);

        return 'https://backboard.railway.com/oauth/auth?' . \http_build_query([
            'response_type' => 'code',
            'client_id' => $this->appID,
            'redirect_uri' => $this->callback,
            'scope' => \implode(' ', $this->getScopes()),
            'state' => \json_encode($state),
            'code_challenge' => $this->getPKCEChallenge(),
            'code_challenge_method' => 'S256',
            // Railway only issues a refresh token when consent is requested
            'prompt' => 'consent',
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
            $this->tokens = \json_decode($this->request(
                'POST',
                'https://backboard.railway.com/oauth/token',
                $this->getTokenHeaders(),
                \http_build_query([
                    'grant_type' => 'authorization_code',
                    'redirect_uri' => $this->callback,
                    'code_verifier' => $this->getPKCEVerifier(),
                    'code' => $code,
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
        $this->tokens = \json_decode($this->request(
            'POST',
            'https://backboard.railway.com/oauth/token',
            $this->getTokenHeaders(),
            \http_build_query([
                'grant_type' => 'refresh_token',
                'refresh_token' => $refreshToken,
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

        return $user['sub'] ?? '';
    }

    /**
     * @param string $accessToken
     *
     * @return string
     */
    public function getUserEmail(string $accessToken): string
    {
        $user = $this->getUser($accessToken);

        return $user['email'] ?? '';
    }

    /**
     * Check if the OAuth email is verified
     *
     * @link https://docs.railway.com/integrations/oauth
     *
     * @param string $accessToken
     *
     * @return bool
     */
    public function isEmailVerified(string $accessToken): bool
    {
        $user = $this->getUser($accessToken);

        return ($user['email_verified'] ?? false) === true;
    }

    /**
     * @param string $accessToken
     *
     * @return string
     */
    public function getUserName(string $accessToken): string
    {
        $user = $this->getUser($accessToken);

        return $user['name'] ?? '';
    }

    /**
     * @param string $accessToken
     *
     * @return string
     */
    public function getUserPhoto(string $accessToken): string
    {
        $user = $this->getUser($accessToken);

        return $user['picture'] ?? '';
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
                'https://backboard.railway.com/oauth/me',
                ['Authorization: Bearer ' . \urlencode($accessToken)]
            ), true) ?? [];
        }

        return $this->user;
    }

    /**
     * @return array<string>
     */
    private function getTokenHeaders(): array
    {
        return [
            'Authorization: Basic ' . \base64_encode($this->appID . ':' . $this->appSecret),
            'Content-Type: application/x-www-form-urlencoded',
        ];
    }

    /**
     * Extract the PKCE verifier from the state on the callback so the same
     * value generated in getLoginURL() can be sent to the token endpoint.
     *
     * @param string $state
     *
     * @return array<string, mixed>|null
     */
    public function parseState(string $state): ?array
    {
        $parsed = \json_decode($state, true);

        if (!\is_array($parsed)) {
            return null;
        }

        return $this->restorePKCEState($parsed);
    }
}

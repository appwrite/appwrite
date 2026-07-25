<?php

namespace Appwrite\Auth\OAuth2;

use Appwrite\Auth\OAuth2;

// Reference Material
// https://docs.kick.com/getting-started/generating-tokens-oauth2-flow
// https://docs.kick.com/getting-started/scopes

class Kick extends OAuth2
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
        'user:read',
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
        return 'kick';
    }

    /**
     * @return string
     */
    public function getLoginURL(): string
    {
        $state = $this->state;
        $state[self::PKCE_STATE_KEY] = $this->getPKCEVerifier();

        return 'https://id.kick.com/oauth/authorize?' . \http_build_query([
            'response_type' => 'code',
            'client_id' => $this->appID,
            'redirect_uri' => $this->callback,
            'scope' => \implode(' ', $this->getScopes()),
            'state' => \json_encode($state),
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
            $headers = ['Content-Type: application/x-www-form-urlencoded'];
            $this->tokens = \json_decode($this->request(
                'POST',
                'https://id.kick.com/oauth/token',
                $headers,
                \http_build_query([
                    'grant_type' => 'authorization_code',
                    'client_id' => $this->appID,
                    'client_secret' => $this->appSecret,
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
        $headers = ['Content-Type: application/x-www-form-urlencoded'];
        $this->tokens = \json_decode($this->request(
            'POST',
            'https://id.kick.com/oauth/token',
            $headers,
            \http_build_query([
                'grant_type' => 'refresh_token',
                'client_id' => $this->appID,
                'client_secret' => $this->appSecret,
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

        return isset($user['user_id']) ? (string)$user['user_id'] : '';
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
     * Check if the OAuth email is verified.
     *
     * Kick only returns an email when the user has granted the `user:read`
     * scope and the account email is verified, so a non-empty email is
     * treated as verified.
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

        return $user['name'] ?? '';
    }

    /**
     * @param string $accessToken
     *
     * @return array
     */
    protected function getUser(string $accessToken): array
    {
        if (empty($this->user)) {
            $headers = ['Authorization: Bearer ' . $accessToken];
            $response = \json_decode($this->request(
                'GET',
                'https://api.kick.com/public/v1/users',
                $headers
            ), true);

            $this->user = $response['data'][0] ?? [];
        }

        return $this->user;
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

        $verifier = $parsed[self::PKCE_STATE_KEY] ?? null;
        if (\is_string($verifier)) {
            $this->pkceVerifier = $verifier;
        }

        unset($parsed[self::PKCE_STATE_KEY]);

        return $parsed;
    }

    private function getPKCEVerifier(): string
    {
        if ($this->pkceVerifier === '') {
            $this->pkceVerifier = \rtrim(\strtr(\base64_encode(\random_bytes(64)), '+/', '-_'), '=');
        }

        return $this->pkceVerifier;
    }

    private function getPKCEChallenge(): string
    {
        return \rtrim(\strtr(\base64_encode(\hash('sha256', $this->getPKCEVerifier(), true)), '+/', '-_'), '=');
    }
}

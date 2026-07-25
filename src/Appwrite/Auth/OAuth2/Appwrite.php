<?php

namespace Appwrite\Auth\OAuth2;

use Appwrite\Auth\OAuth2;

// Reference Material
// https://appwrite.io/docs/products/auth
// Appwrite Cloud exposes an OpenID Connect provider for the console project at
// https://cloud.appwrite.io/v1/oauth2/console/.well-known/openid-configuration

class Appwrite extends OAuth2
{
    private const PKCE_STATE_KEY = '_pkce';

    /**
     * @var string
     */
    private string $endpoint = 'https://cloud.appwrite.io/v1/oauth2/console';

    /**
     * @var string
     */
    private string $pkceVerifier = '';

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
        'profile',
        'email',
    ];

    /**
     * @return string
     */
    public function getName(): string
    {
        return 'appwrite';
    }

    /**
     * @return string
     */
    public function getLoginURL(): string
    {
        // The Appwrite OAuth2 provider requires PKCE. Stash the verifier in the
        // state so it survives the redirect, since the adapter is reconstructed
        // statelessly on the callback before the token exchange.
        $state = $this->state;
        $state[self::PKCE_STATE_KEY] = $this->getPKCEVerifier();

        return $this->endpoint . '/authorize?' . \http_build_query([
            'client_id' => $this->appID,
            'redirect_uri' => $this->callback,
            'state' => \json_encode($state),
            'scope' => \implode(' ', $this->getScopes()),
            'response_type' => 'code',
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
            $this->tokens = \json_decode($this->request(
                'POST',
                $this->endpoint . '/token',
                ['Content-Type: application/x-www-form-urlencoded'],
                \http_build_query([
                    'code' => $code,
                    'client_id' => $this->appID,
                    'client_secret' => $this->appSecret,
                    'redirect_uri' => $this->callback,
                    'scope' => \implode(' ', $this->getScopes()),
                    'grant_type' => 'authorization_code',
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
        $this->tokens = \json_decode($this->request(
            'POST',
            $this->endpoint . '/token',
            ['Content-Type: application/x-www-form-urlencoded'],
            \http_build_query([
                'refresh_token' => $refreshToken,
                'client_id' => $this->appID,
                'client_secret' => $this->appSecret,
                'grant_type' => 'refresh_token'
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
     * Check if the User email is verified
     *
     * @param string $accessToken
     *
     * @return bool
     */
    public function isEmailVerified(string $accessToken): bool
    {
        $user = $this->getUser($accessToken);

        return $user['email_verified'] ?? false;
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
            $user = $this->request(
                'GET',
                $this->endpoint . '/userinfo',
                ['Authorization: Bearer ' . \urlencode($accessToken)]
            );
            $this->user = \json_decode($user, true);
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

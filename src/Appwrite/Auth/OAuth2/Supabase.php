<?php

namespace Appwrite\Auth\OAuth2;

use Appwrite\Auth\OAuth2;

// Reference Material
// https://supabase.com/docs/guides/integrations/build-a-supabase-oauth-integration
// https://api.supabase.com/api/v1

class Supabase extends OAuth2
{
    use PKCE;

    /**
     * Prefix of the temporary login roles created for migrations
     */
    public const string ROLE_PREFIX = 'appwrite_migration_';

    /**
     * @var array
     */
    protected array $user = [];

    /**
     * @var array
     */
    protected array $tokens = [];

    /**
     * Supabase sets the scopes on the OAuth app, not in the request
     *
     * @var array
     */
    protected array $scopes = [];

    /**
     * @return string
     */
    public function getName(): string
    {
        return 'supabase';
    }

    /**
     * @return string
     */
    public function getLoginURL(): string
    {
        return 'https://api.supabase.com/v1/oauth/authorize?' . \http_build_query([
            'response_type' => 'code',
            'client_id' => $this->appID,
            'redirect_uri' => $this->callback,
            'state' => \json_encode($this->withPKCEState($this->state)),
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
                'https://api.supabase.com/v1/oauth/token',
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
            'https://api.supabase.com/v1/oauth/token',
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

        return $user['gotrue_id'] ?? '';
    }

    /**
     * @param string $accessToken
     *
     * @return string
     */
    public function getUserEmail(string $accessToken): string
    {
        $user = $this->getUser($accessToken);

        return $user['primary_email'] ?? '';
    }

    /**
     * Supabase does not report whether the email address is verified
     *
     * @param string $accessToken
     *
     * @return bool
     */
    public function isEmailVerified(string $accessToken): bool
    {
        return false;
    }

    /**
     * @param string $accessToken
     *
     * @return string
     */
    public function getUserName(string $accessToken): string
    {
        $user = $this->getUser($accessToken);

        return $user['username'] ?? '';
    }

    /**
     * Revoke the refresh token and every access token issued with it
     */
    public function revokeTokens(string $refreshToken): void
    {
        $this->request(
            'POST',
            'https://api.supabase.com/v1/oauth/revoke',
            ['Content-Type: application/json'],
            (string) \json_encode([
                'client_id' => $this->appID,
                'client_secret' => $this->appSecret,
                'refresh_token' => $refreshToken,
            ])
        );
    }

    /**
     * @return array<array<string, mixed>>
     */
    public function getProjects(string $accessToken): array
    {
        return \json_decode($this->request('GET', 'https://api.supabase.com/v1/projects', ['Authorization: Bearer ' . $accessToken]), true) ?? [];
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
                'https://api.supabase.com/v1/profile',
                ['Authorization: Bearer ' . $accessToken]
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

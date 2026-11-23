<?php

namespace Appwrite\Auth\OAuth2;

use Appwrite\Auth\OAuth2;

// Reference Material
// https://supabase.com/docs/guides/integrations/build-a-supabase-oauth-integration
// https://api.supabase.com/api/v1

/**
 * Authorizes Appwrite against a user's Supabase account so a migration can read
 * their projects. Scopes are set on the Supabase OAuth app, not in the request.
 */
class Supabase extends OAuth2
{
    use PKCE;

    private const string API = 'https://api.supabase.com';

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
        return self::API . '/v1/oauth/authorize?' . \http_build_query([
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
                self::API . '/v1/oauth/token',
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
            self::API . '/v1/oauth/token',
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
     * Revoke the refresh token and every access token issued with it
     */
    public function revokeTokens(string $refreshToken): void
    {
        $this->request(
            'POST',
            self::API . '/v1/oauth/revoke',
            ['Content-Type: application/json'],
            (string) \json_encode([
                'client_id' => $this->appID,
                'client_secret' => $this->appSecret,
                'refresh_token' => $refreshToken,
            ])
        );
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
     * The identity only authorizes migrations and never signs anyone in
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
     * @return array<array<string, mixed>>
     */
    public function getProjects(string $accessToken): array
    {
        return \json_decode($this->request('GET', self::API . '/v1/projects', $this->getHeaders($accessToken)), true) ?? [];
    }

    /**
     * The key that bypasses row level security: the legacy service role key, or a secret key
     */
    public function getSecretKey(string $accessToken, string $projectRef): string
    {
        $keys = \json_decode($this->request(
            'GET',
            self::API . '/v1/projects/' . \rawurlencode($projectRef) . '/api-keys?reveal=true',
            $this->getHeaders($accessToken)
        ), true) ?? [];

        foreach ($keys as $key) {
            if (($key['type'] ?? '') === 'legacy' && ($key['name'] ?? '') === 'service_role') {
                return $key['api_key'] ?? '';
            }
        }

        foreach ($keys as $key) {
            if (($key['type'] ?? '') === 'secret') {
                return $key['api_key'] ?? '';
            }
        }

        return '';
    }

    /**
     * The primary database's connection pooler, which unlike the direct host is reachable over IPv4
     *
     * @return array<string, mixed>
     */
    public function getPooler(string $accessToken, string $projectRef): array
    {
        $poolers = \json_decode($this->request(
            'GET',
            self::API . '/v1/projects/' . \rawurlencode($projectRef) . '/config/database/pooler',
            $this->getHeaders($accessToken)
        ), true) ?? [];

        foreach ($poolers as $pooler) {
            if (($pooler['database_type'] ?? '') === 'PRIMARY') {
                return $pooler;
            }
        }

        return [];
    }

    /**
     * Run SQL on the project's database as its owner
     *
     * @return array<array<string, mixed>>
     */
    public function query(string $accessToken, string $projectRef, string $sql): array
    {
        return \json_decode($this->request(
            'POST',
            self::API . '/v1/projects/' . \rawurlencode($projectRef) . '/database/query',
            [...$this->getHeaders($accessToken), 'Content-Type: application/json'],
            (string) \json_encode(['query' => $sql])
        ), true) ?? [];
    }

    /**
     * @param string $accessToken
     *
     * @return array
     */
    protected function getUser(string $accessToken): array
    {
        if (empty($this->user)) {
            $this->user = \json_decode($this->request('GET', self::API . '/v1/profile', $this->getHeaders($accessToken)), true) ?? [];
        }

        return $this->user;
    }

    /**
     * @return array<string>
     */
    private function getHeaders(string $accessToken): array
    {
        return ['Authorization: Bearer ' . $accessToken];
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

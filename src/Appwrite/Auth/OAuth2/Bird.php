<?php

namespace Appwrite\Auth\OAuth2;

use Appwrite\Auth\OAuth2;
use Utopia\Psr7\Method;
use Utopia\Psr7\Request\Factory as RequestFactory;

// Reference Material
// https://bird.com/auth.md
// https://platform.bird.com/.well-known/oauth-authorization-server
// https://bird.com/docs/cli/reference/whoami

class Bird extends OAuth2
{
    use PKCE;

    /**
     * Issuer and region-independent host for authentication and account
     * administration. API calls route to the region in the token's prefix.
     *
     * @var string
     */
    private string $endpoint = 'https://platform.bird.com/v1/';

    /**
     * @var array
     */
    protected array $user = [];

    /**
     * @var array
     */
    protected array $tokens = [];

    /**
     * Bird has no identity scope: any grant can read the signed-in user.
     * `workspace:read` is the narrowest read-only scope, so the grant is valid
     * even when no custom scopes are requested.
     *
     * @var array
     */
    protected array $scopes = [
        'workspace:read',
    ];

    /**
     * @return string
     */
    public function getName(): string
    {
        return 'bird';
    }

    /**
     * @return string
     */
    public function getLoginURL(): string
    {
        $state = $this->withPKCEState($this->state);

        return $this->endpoint . 'oauth/authorize?' . \http_build_query([
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
            $this->tokens = \json_decode($this->request(
                'POST',
                $this->endpoint . 'oauth/token',
                ['Content-Type: application/x-www-form-urlencoded'],
                \http_build_query([
                    'grant_type' => 'authorization_code',
                    'client_id' => $this->appID,
                    'client_secret' => $this->appSecret,
                    'code' => $code,
                    'redirect_uri' => $this->callback,
                    'code_verifier' => $this->getPKCEVerifier(),
                ])
            ), true);
        }

        return $this->tokens;
    }

    /**
     * Bird rotates refresh tokens on every use and treats reuse of a rotated
     * one as compromise, so the new token always replaces the old one.
     *
     * @param string $refreshToken
     *
     * @return array
     */
    public function refreshTokens(string $refreshToken): array
    {
        $this->tokens = \json_decode($this->request(
            'POST',
            $this->endpoint . 'oauth/token',
            ['Content-Type: application/x-www-form-urlencoded'],
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

        return $user['id'] ?? '';
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
     * @param string $accessToken
     *
     * @return bool
     */
    public function isEmailVerified(string $accessToken): bool
    {
        $user = $this->getUser($accessToken);

        return !empty($user['email_verified_at']);
    }

    /**
     * @param string $accessToken
     *
     * @return string
     */
    public function getUserName(string $accessToken): string
    {
        $user = $this->getUser($accessToken);

        return $user['full_name'] ?? '';
    }

    /**
     * @param string $accessToken
     *
     * @return string
     */
    public function getUserPhoto(string $accessToken): string
    {
        $user = $this->getUser($accessToken);

        return $user['avatar_url'] ?? '';
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
                $this->endpoint . 'auth/me',
                ['Authorization: Bearer ' . \urlencode($accessToken)]
            ), true) ?? [];
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

        return $this->restorePKCEState($parsed);
    }

    public function verifyCredentials(): void
    {
        // Bird answers invalid_client for an unknown client or a wrong secret,
        // whatever the code.
        $response = $this->client
            ->withTimeout(15)
            ->withFollowRedirects(maxHops: 5)
            ->sendRequest((new RequestFactory())->form(
                Method::POST,
                $this->endpoint . 'oauth/token',
                [
                    'grant_type' => 'authorization_code',
                    'client_id' => $this->appID,
                    'client_secret' => $this->appSecret,
                    'code' => 'intentionally-invalid-code',
                    'redirect_uri' => 'https://invalid.appwrite.callback/intentionally-invalid',
                    'code_verifier' => 'intentionally-invalid-verifier-intentionally-invalid',
                ],
            ));

        $json = \json_decode((string) $response->getBody(), true);

        if (isset($json['error']) && $json['error'] === 'invalid_client') {
            throw new \Exception('Bird application with the provided Client ID and/or Client Secret is invalid.');
        }

        // We still expect an error, like invalid_grant or invalid_request,
        // but that indicates valid credentials
    }
}

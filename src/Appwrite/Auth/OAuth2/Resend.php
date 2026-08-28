<?php

namespace Appwrite\Auth\OAuth2;

use Appwrite\Auth\OAuth2;
use Utopia\Fetch\Client as FetchClient;

// Reference Material
// https://resend.com/docs/guides/building-a-resend-oauth-client
// https://api.resend.com/.well-known/oauth-authorization-server

class Resend extends OAuth2
{
    use PKCE;

    /**
     * @var string
     */
    private string $endpoint = 'https://api.resend.com/oauth/';

    /**
     * @var array
     */
    protected array $tokens = [];

    /**
     * @var array
     */
    protected array $claims = [];

    /**
     * Resend has no identity-only scope; `emails:send` is the narrowest
     * scope the authorization server issues. Omitting the scope entirely
     * would grant `full_access` as well.
     *
     * @var array
     */
    protected array $scopes = [
        'emails:send',
    ];

    /**
     * @return string
     */
    public function getName(): string
    {
        return 'resend';
    }

    /**
     * @return string
     */
    public function getLoginURL(): string
    {
        $state = $this->withPKCEState($this->state);

        return $this->endpoint . 'authorize?' . \http_build_query([
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
                $this->endpoint . 'token',
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
     * @param string $refreshToken
     *
     * @return array
     */
    public function refreshTokens(string $refreshToken): array
    {
        $this->tokens = \json_decode($this->request(
            'POST',
            $this->endpoint . 'token',
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
        $claims = $this->getClaims($accessToken);

        return isset($claims['sub']) ? (string)$claims['sub'] : '';
    }

    /**
     * Resend does not expose the account email through OAuth.
     *
     * @param string $accessToken
     *
     * @return string
     */
    public function getUserEmail(string $accessToken): string
    {
        return '';
    }

    /**
     * Check if the OAuth email is verified
     *
     * Resend does not expose an email, so there is nothing to verify.
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
     * Resend does not expose the account name through OAuth.
     *
     * @param string $accessToken
     *
     * @return string
     */
    public function getUserName(string $accessToken): string
    {
        return '';
    }

    /**
     * Resend has no userinfo endpoint and issues no id_token; identity lives
     * in the RFC 9068 JWT access token. The token arrives over TLS directly
     * from the token endpoint and is never used to authorize anything on our
     * side, so the payload is read without verifying the signature.
     *
     * @param string $accessToken
     *
     * @return array
     */
    protected function getClaims(string $accessToken): array
    {
        if (empty($this->claims)) {
            $segments = \explode('.', $accessToken);
            $payload = isset($segments[1]) ? $this->base64UrlDecode($segments[1]) : false;
            $claims = \is_string($payload) ? \json_decode($payload, true) : null;

            $this->claims = \is_array($claims) ? $claims : [];
        }

        return $this->claims;
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
        $client = new FetchClient();
        $client->addHeader('Content-Type', 'application/x-www-form-urlencoded');

        // The redirect_uri must be a well-formed URL; Resend rejects the
        // request shape with invalid_request before authenticating the
        // client, which would mask bad credentials.
        $response = $client->fetch(
            url: $this->endpoint . 'token',
            method: FetchClient::METHOD_POST,
            body: [
                'grant_type' => 'authorization_code',
                'client_id' => $this->appID,
                'client_secret' => $this->appSecret,
                'code' => 'intentionally-invalid-code',
                'redirect_uri' => 'https://invalid.appwrite.callback/intentionally-invalid',
                'code_verifier' => 'intentionally-invalid-verifier-intentionally-invalid',
            ]
        );

        $json = \json_decode($response->getBody(), true);

        if (isset($json['error']) && $json['error'] === 'invalid_client') {
            throw new \Exception('Resend application with the provided Client ID and/or Client Secret is invalid.');
        }

        // We still expect an error, like invalid_grant or invalid_request,
        // but that indicates valid credentials
    }
}

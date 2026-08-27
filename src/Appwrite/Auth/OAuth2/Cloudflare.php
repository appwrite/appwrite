<?php

namespace Appwrite\Auth\OAuth2;

use Appwrite\Auth\OAuth2;
use Utopia\Fetch\Client as FetchClient;

// Reference Material
// https://developers.cloudflare.com/fundamentals/oauth/

class Cloudflare extends OAuth2
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
    ];

    /**
     * @return string
     */
    public function getName(): string
    {
        return 'cloudflare';
    }

    /**
     * @return string
     */
    public function getLoginURL(): string
    {
        return 'https://dash.cloudflare.com/oauth2/auth?' . \http_build_query([
            'client_id' => $this->appID,
            'redirect_uri' => $this->callback,
            'response_type' => 'code',
            'scope' => \implode(' ', $this->getScopes()),
            'state' => \json_encode($this->withPKCEState($this->state)),
            'code_challenge' => $this->getPKCEChallenge(),
            'code_challenge_method' => 'S256',
        ]);
    }

    /**
     * @param string $state
     *
     * @return array|null
     */
    public function parseState(string $state): ?array
    {
        $parsed = \json_decode($state, true);

        if (!\is_array($parsed)) {
            return null;
        }

        return $this->restorePKCEState($parsed);
    }

    /**
     * @param string $code
     *
     * @return array
     */
    protected function getTokens(string $code): array
    {
        if (empty($this->tokens)) {
            $response = $this->request('POST', 'https://dash.cloudflare.com/oauth2/token', [
                'Content-Type: application/x-www-form-urlencoded',
                'Accept: application/json',
            ], \http_build_query([
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $this->callback,
                'client_id' => $this->appID,
                'client_secret' => $this->appSecret,
                'code_verifier' => $this->getPKCEVerifier(),
            ]));

            $this->tokens = $this->parseTokens($response);
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
        $response = $this->request('POST', 'https://dash.cloudflare.com/oauth2/token', [
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: application/json',
        ], \http_build_query([
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
            'client_id' => $this->appID,
            'client_secret' => $this->appSecret,
        ]));

        $this->tokens = $this->parseTokens($response);

        if (empty($this->tokens['refresh_token'])) {
            $this->tokens['refresh_token'] = $refreshToken;
        }

        return $this->tokens;
    }

    /**
     * @param string $response
     *
     * @return array
     */
    private function parseTokens(string $response): array
    {
        $tokens = \json_decode($response, true);

        if (!\is_array($tokens)) {
            $tokens = [];
            \parse_str($response, $tokens);
        }

        if (isset($tokens['error'])) {
            throw new Exception(\json_encode(
                $tokens,
                JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR,
            ), 400);
        }

        if (empty($tokens['access_token'])) {
            throw new Exception(\json_encode([
                'error' => 'access_token_missing',
                'error_description' => 'Cloudflare did not return an access token.',
            ]), 400);
        }

        return $tokens;
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
     * Cloudflare Dashboard OAuth2 only exposes 'sub' in userinfo.
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
     * @param string $accessToken
     *
     * @return bool
     */
    public function isEmailVerified(string $accessToken): bool
    {
        return false;
    }

    /**
     * Cloudflare Dashboard OAuth2 does not expose a name claim.
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
     * Cloudflare Dashboard OAuth2 does not expose a picture claim.
     *
     * @param string $accessToken
     *
     * @return string
     */
    public function getUserPhoto(string $accessToken): string
    {
        return '';
    }

    /**
     * @param string $accessToken
     *
     * @return array
     */
    protected function getUser(string $accessToken): array
    {
        if (empty($this->user)) {
            $user = $this->request('GET', 'https://dash.cloudflare.com/oauth2/userinfo', [
                'Authorization: Bearer ' . \urlencode($accessToken),
                'Accept: application/json',
            ]);

            $decodedUser = \json_decode($user, true);

            if (!\is_array($decodedUser) || isset($decodedUser['error'])) {
                throw new Exception('Cloudflare did not return valid user information.', 400);
            }

            $this->user = $decodedUser;
        }

        return $this->user;
    }

    /**
     * Verify saved credentials by issuing an intentionally-invalid token request.
     *
     * @return void
     */
    public function verifyCredentials(): void
    {
        $client = new FetchClient();
        $client->addHeader('Content-Type', 'application/x-www-form-urlencoded');
        $client->addHeader('Accept', 'application/json');

        $response = $client->fetch(
            url: 'https://dash.cloudflare.com/oauth2/token',
            method: FetchClient::METHOD_POST,
            body: [
                'grant_type' => 'authorization_code',
                'code' => 'intentionally-invalid-code',
                'redirect_uri' => 'https://invalid.appwrite.callback/intentionally-invalid',
                'client_id' => $this->appID,
                'client_secret' => $this->appSecret,
                'code_verifier' => 'intentionally-invalid-verifier-intentionally-invalid',
            ]
        );

        $json = \json_decode($response->getBody(), true);

        if (isset($json['error']) && $json['error'] === 'invalid_client') {
            throw new \Exception('Cloudflare application with the provided Client ID and/or Client Secret is invalid.');
        }
    }
}

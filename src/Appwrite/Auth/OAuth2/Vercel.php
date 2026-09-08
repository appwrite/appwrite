<?php

namespace Appwrite\Auth\OAuth2;

use Appwrite\Auth\OAuth2;

// Reference Material
// https://vercel.com/docs/integrations/create-integration/vercel-api-integrations
// https://vercel.com/docs/rest-api/user/get-the-user

class Vercel extends OAuth2
{
    private string $endpoint = 'https://api.vercel.com';

    protected array $user = [];

    protected array $tokens = [];

    protected array $scopes = [
        'user',
    ];

    public function getName(): string
    {
        return 'vercel';
    }

    public function getLoginURL(): string
    {
        return 'https://vercel.com/oauth/authorize?'.\http_build_query([
            'client_id' => $this->appID,
            'redirect_uri' => $this->callback,
            'scope' => \implode(' ', $this->getScopes()),
            'response_type' => 'code',
            'state' => \json_encode($this->state),
        ]);
    }

    protected function getTokens(string $code): array
    {
        if (empty($this->tokens)) {
            $this->tokens = \json_decode($this->request(
                'POST',
                $this->endpoint.'/v2/oauth/access_token',
                ['Content-Type: application/x-www-form-urlencoded'],
                \http_build_query([
                    'client_id' => $this->appID,
                    'client_secret' => $this->appSecret,
                    'code' => $code,
                    'redirect_uri' => $this->callback,
                ])
            ), true);
        }

        if (! isset($this->tokens['access_token'])) {
            throw new Exception('Vercel did not return a valid access token.', 400);
        }

        return $this->tokens;
    }

    public function refreshTokens(string $refreshToken): array
    {
        $this->tokens = \json_decode($this->request(
            'POST',
            $this->endpoint.'/v2/oauth/access_token',
            ['Content-Type: application/x-www-form-urlencoded'],
            \http_build_query([
                'grant_type' => 'refresh_token',
                'refresh_token' => $refreshToken,
                'client_id' => $this->appID,
                'client_secret' => $this->appSecret,
            ])
        ), true);

        if (! isset($this->tokens['access_token'])) {
            throw new Exception('Vercel did not return a valid access token.', 400);
        }

        if (empty($this->tokens['refresh_token'])) {
            $this->tokens['refresh_token'] = $refreshToken;
        }

        return $this->tokens;
    }

    public function getUserID(string $accessToken): string
    {
        $user = $this->getUser($accessToken);

        return $user['user']['id'] ?? '';
    }

    public function getUserEmail(string $accessToken): string
    {
        $user = $this->getUser($accessToken);

        return $user['user']['email'] ?? '';
    }

    /**
     * Vercel's /v2/user endpoint does not return an email_verified signal.
     */
    public function isEmailVerified(string $accessToken): bool
    {
        return false;
    }

    public function getUserName(string $accessToken): string
    {
        $user = $this->getUser($accessToken);

        return $user['user']['name'] ?? $user['user']['username'] ?? '';
    }

    protected function getUser(string $accessToken): array
    {
        if (empty($this->user)) {
            $response = \json_decode($this->request(
                'GET',
                $this->endpoint.'/v2/user',
                ['Authorization: Bearer '.$accessToken]
            ), true);

            if (! \is_array($response['user'] ?? null)) {
                throw new Exception('Vercel did not return valid user information.', 400);
            }

            $this->user = $response;
        }

        return $this->user;
    }
}

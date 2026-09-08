<?php

namespace Appwrite\Auth\OAuth2;

use Appwrite\Auth\OAuth2;
use Utopia\Fetch\Client as FetchClient;

// Reference Material
// https://vercel.com/docs/integrations/create-integration/vercel-api-integrations
// https://vercel.com/docs/rest-api/user/get-the-user

class Vercel extends OAuth2
{
    /**
     * @var string
     */
    private string $endpoint = 'https://api.vercel.com';

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
        'user',
    ];

    /**
     * @return string
     */
    public function getName(): string
    {
        return 'vercel';
    }

    /**
     * @return string
     */
    public function getLoginURL(): string
    {
        return 'https://vercel.com/oauth/authorize?' . \http_build_query([
            'client_id' => $this->appID,
            'redirect_uri' => $this->callback,
            'scope' => \implode(' ', $this->getScopes()),
            'response_type' => 'code',
            'state' => \json_encode($this->state),
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
                $this->endpoint . '/v2/oauth/access_token',
                ['Content-Type: application/x-www-form-urlencoded'],
                \http_build_query([
                    'client_id' => $this->appID,
                    'client_secret' => $this->appSecret,
                    'code' => $code,
                    'redirect_uri' => $this->callback,
                ])
            ), true);
        }

        if (!isset($this->tokens['access_token'])) {
            throw new Exception('Vercel did not return a valid access token.', 400);
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
            $this->endpoint . '/v2/oauth/access_token',
            ['Content-Type: application/x-www-form-urlencoded'],
            \http_build_query([
                'grant_type' => 'refresh_token',
                'refresh_token' => $refreshToken,
                'client_id' => $this->appID,
                'client_secret' => $this->appSecret,
            ])
        ), true);

        if (!isset($this->tokens['access_token'])) {
            throw new Exception('Vercel did not return a valid access token.', 400);
        }

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

        return $user['user']['id'] ?? '';
    }

    /**
     * @param string $accessToken
     *
     * @return string
     */
    public function getUserEmail(string $accessToken): string
    {
        $user = $this->getUser($accessToken);

        return $user['user']['email'] ?? '';
    }

    /**
     * Vercel's /v2/user endpoint does not return an email_verified signal.
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

        return $user['user']['name'] ?? $user['user']['username'] ?? '';
    }

    /**
     * @param string $accessToken
     *
     * @return array
     */
    protected function getUser(string $accessToken): array
    {
        if (empty($this->user)) {
            $response = \json_decode($this->request(
                'GET',
                $this->endpoint . '/v2/user',
                ['Authorization: Bearer ' . $accessToken]
            ), true);

            if (!\is_array($response['user'] ?? null)) {
                throw new Exception('Vercel did not return valid user information.', 400);
            }

            $this->user = $response;
        }

        return $this->user;
    }

    /**
     * @return void
     */
    public function verifyCredentials(): void
    {
        $client = new FetchClient();
        $client->addHeader('Content-Type', 'application/x-www-form-urlencoded');

        $response = $client->fetch(
            url: $this->endpoint . '/v2/oauth/access_token',
            method: FetchClient::METHOD_POST,
            body: [
                'client_id' => $this->appID,
                'client_secret' => $this->appSecret,
                'code' => 'intentionally-invalid-code',
                'redirect_uri' => 'https://invalid.appwrite.callback/intentionally-invalid',
            ]
        );

        $json = \json_decode($response->getBody(), true);

        $code = $json['error']['code'] ?? $json['error'] ?? null;

        if ($code === 'invalid_client') {
            throw new \Exception('Vercel application with the provided Client ID and/or Client Secret is invalid.');
        }
    }
}

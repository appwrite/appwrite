<?php

namespace Appwrite\Auth\OAuth2;

use Appwrite\Auth\OAuth2;
use Utopia\Fetch\Client as FetchClient;

class HuggingFace extends OAuth2
{
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
        return 'huggingface';
    }

    /**
     * @return string
     */
    public function getLoginURL(): string
    {
        return 'https://huggingface.co/oauth/authorize?' . \http_build_query([
            'client_id' => $this->appID,
            'redirect_uri' => $this->callback,
            'response_type' => 'code',
            'scope' => \implode(' ', $this->getScopes()),
            'state' => \json_encode($this->state)
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
            $response = $this->request(
                'POST',
                'https://huggingface.co/oauth/token',
                [
                    'Content-Type: application/x-www-form-urlencoded',
                    'Authorization: Basic ' . \base64_encode($this->appID . ':' . $this->appSecret),
                ],
                \http_build_query([
                    'grant_type' => 'authorization_code',
                    'code' => $code,
                    'redirect_uri' => $this->callback,
                    'client_id' => $this->appID,
                ])
            );

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
        $response = $this->request(
            'POST',
            'https://huggingface.co/oauth/token',
            [
                'Content-Type: application/x-www-form-urlencoded',
                'Authorization: Basic ' . \base64_encode($this->appID . ':' . $this->appSecret),
            ],
            \http_build_query([
                'grant_type' => 'refresh_token',
                'refresh_token' => $refreshToken,
                'client_id' => $this->appID,
            ])
        );

        $this->tokens = $this->parseTokens($response);

        if (empty($this->tokens['refresh_token'])) {
            $this->tokens['refresh_token'] = $refreshToken;
        }

        return $this->tokens;
    }

    /**
     * @return array<string, mixed>
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
                'error_description' => 'Hugging Face did not return an access token.',
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
     * @link https://huggingface.co/.well-known/openid-configuration
     *
     * @param string $accessToken
     *
     * @return bool
     */
    public function isEmailVerified(string $accessToken): bool
    {
        $user = $this->getUser($accessToken);

        if ($user['email_verified'] ?? false) {
            return true;
        }

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

        return $user['name'] ?? '';
    }

    /**
     * @param string $accessToken
     *
     * @return string
     */
    public function getUserSlug(string $accessToken): string
    {
        $user = $this->getUser($accessToken);

        return $user['preferred_username'] ?? '';
    }

    /**
     * @param string $accessToken
     *
     * @return array
     */
    protected function getUser(string $accessToken)
    {
        if (empty($this->user)) {
            $user = $this->request(
                'GET',
                'https://huggingface.co/oauth/userinfo',
                ['Authorization: Bearer ' . \urlencode($accessToken)]
            );

            $decodedUser = \json_decode($user, true);

            if (!\is_array($decodedUser) || isset($decodedUser['error'])) {
                throw new Exception('Hugging Face did not return valid user information.', 400);
            }

            $this->user = $decodedUser;
        }

        return $this->user;
    }

    public function verifyCredentials(): void
    {
        $client = new FetchClient();
        $client->addHeader('Content-Type', 'application/x-www-form-urlencoded');
        $client->addHeader('Authorization', 'Basic ' . \base64_encode($this->appID . ':' . $this->appSecret));

        $response = $client->fetch(
            url: 'https://huggingface.co/oauth/token',
            method: FetchClient::METHOD_POST,
            body: [
                'grant_type' => 'authorization_code',
                'code' => 'intentionally-invalid-code',
                'redirect_uri' => 'intentionally-invalid-redirect',
                'client_id' => $this->appID,
            ]
        );

        $json = \json_decode($response->getBody(), true);

        if (isset($json['error']) && $json['error'] === 'invalid_client') {
            throw new \Exception('Hugging Face application with the provided Client ID and/or Client Secret is invalid.');
        }

        // We still expect an error, like invalid_grant or invalid_request,
        // but that indicates valid credentials
    }
}

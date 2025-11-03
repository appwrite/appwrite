<?php

namespace Appwrite\Auth\OAuth2;

use Appwrite\Auth\OAuth2;

// Reference Material
// https://developers.tiktok.com/doc/login-kit-web

class TikTok extends OAuth2
{
    /**
     * @var string
     */
    private string $endpoint = 'https://www.tiktok.com/v2/auth/authorize/';

    /**
     * @var string
     */
    private string $apiEndpoint = 'https://open.tiktokapis.com';

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
        'user.info.basic',
    ];

    /**
     * @return string
     */
    public function getName(): string
    {
        return 'tiktok';
    }

    /**
     * @return string
     */
    public function getLoginURL(): string
    {
        return $this->endpoint . '?' .
            \http_build_query([
                'client_key' => $this->appID,
                'scope' => \implode(',', $this->getScopes()),
                'response_type' => 'code',
                'redirect_uri' => $this->callback,
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
            $headers = [
                'Content-Type: application/x-www-form-urlencoded',
                'Cache-Control: no-cache',
            ];

            $this->tokens = \json_decode($this->request(
                'POST',
                $this->apiEndpoint . '/v2/oauth/token/',
                $headers,
                \http_build_query([
                    'client_key' => $this->appID,
                    'client_secret' => $this->appSecret,
                    'code' => $code,
                    'grant_type' => 'authorization_code',
                    'redirect_uri' => $this->callback
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
        $headers = [
            'Content-Type: application/x-www-form-urlencoded',
            'Cache-Control: no-cache',
        ];

        $this->tokens = \json_decode($this->request(
            'POST',
            $this->apiEndpoint . '/v2/oauth/token/',
            $headers,
            \http_build_query([
                'client_key' => $this->appID,
                'client_secret' => $this->appSecret,
                'grant_type' => 'refresh_token',
                'refresh_token' => $refreshToken
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

        return $user['open_id'] ?? '';
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
     * TikTok does not provide email in basic scope, so we return false
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

        return $user['display_name'] ?? '';
    }

    /**
     * @param string $accessToken
     *
     * @return array
     */
    protected function getUser(string $accessToken): array
    {
        if (empty($this->user)) {
            $headers = [
                'Authorization: Bearer ' . $accessToken,
                'Content-Type: application/json',
            ];

            $response = $this->request(
                'GET',
                $this->apiEndpoint . '/v2/user/info/?fields=open_id,union_id,avatar_url,display_name',
                $headers
            );

            $result = \json_decode($response, true);

            // TikTok returns data in a nested structure
            $this->user = $result['data']['user'] ?? [];
        }

        return $this->user;
    }
}

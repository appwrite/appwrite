<?php

namespace Appwrite\Auth\OAuth2;

use Appwrite\Auth\OAuth2;

// Reference Material
// https://developer.vippsmobilepay.com/docs/APIs/login-api/api-guide/core-concepts/
// https://developer.vippsmobilepay.com/api/login/

class Vipps extends OAuth2
{
    private string $endpoint = 'https://api.vipps.no';

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
        'name',
        'email',
    ];

    /**
     * @return string
     */
    public function getName(): string
    {
        return 'vipps';
    }

    /**
     * @return string
     */
    public function getLoginURL(): string
    {
        return $this->endpoint . '/access-management-1.0/access/oauth2/auth?' . \http_build_query([
            'response_type' => 'code',
            'client_id' => $this->appID,
            'redirect_uri' => $this->callback,
            'scope' => \implode(' ', $this->getScopes()),
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
            $headers = [
                'Content-Type: application/x-www-form-urlencoded',
                'Authorization: Basic ' . \base64_encode(\urlencode($this->appID) . ':' . \urlencode($this->appSecret)),
            ];
            $this->tokens = \json_decode($this->request(
                'POST',
                $this->endpoint . '/access-management-1.0/access/oauth2/token',
                $headers,
                \http_build_query([
                    'grant_type' => 'authorization_code',
                    'redirect_uri' => $this->callback,
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
        $headers = [
            'Content-Type: application/x-www-form-urlencoded',
            'Authorization: Basic ' . \base64_encode(\urlencode($this->appID) . ':' . \urlencode($this->appSecret)),
        ];
        $this->tokens = \json_decode($this->request(
            'POST',
            $this->endpoint . '/access-management-1.0/access/oauth2/token',
            $headers,
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
     * @param string $accessToken
     *
     * @return bool
     */
    public function isEmailVerified(string $accessToken): bool
    {
        $user = $this->getUser($accessToken);

        return ($user['email_verified'] ?? false) === true;
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
            $this->user = \json_decode($this->request(
                'GET',
                $this->endpoint . '/vipps-userinfo-api/userinfo',
                ['Authorization: Bearer ' . \urlencode($accessToken)]
            ), true) ?? [];
        }

        return $this->user;
    }
}

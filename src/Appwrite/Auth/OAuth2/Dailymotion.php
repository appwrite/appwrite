<?php

namespace Appwrite\Auth\OAuth2;

use Appwrite\Auth\OAuth2;

// Reference Material
// https://developers.dailymotion.com/api/#authentication

class Dailymotion extends OAuth2
{
    /**
     * @var string
     */
    private string $endpoint = 'https://api.dailymotion.com';

    /**
     * @var string
     */
    private string $authEndpoint = 'https://www.dailymotion.com/oauth/authorize';

    /**
     * @var array
     */
    protected array $scopes = [
        'userinfo',
        'email',
    ];

    /**
     * @var array
     */
    protected array $fields = [
        'email',
        'id',
        'fullname',
        'verified',
    ];

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
        return 'dailymotion';
    }

    /**
     * @return array
     */
    public function getFields(): array
    {
        return $this->fields;
    }

    /**
     * @return string
     */
    public function getLoginURL(): string
    {
        $url = $this->authEndpoint.'?'.
            \http_build_query([
                'response_type' => 'code',
                'client_id' => $this->appID,
                'state' => \json_encode($this->state),
                'redirect_uri' => $this->callback,
                'scope' => \implode(' ', $this->getScopes()),
            ]);

        return $url;
    }

    /**
     * @param  string  $code
     * @return array
     */
    protected function getTokens(string $code): array
    {
        if (empty($this->tokens)) {
            $this->tokens = \json_decode($this->request(
                'POST',
                $this->endpoint.'/oauth/token',
                ['Content-Type: application/x-www-form-urlencoded'],
                \http_build_query([
                    'grant_type' => 'authorization_code',
                    'client_id' => $this->appID,
                    'client_secret' => $this->appSecret,
                    'redirect_uri' => $this->callback,
                    'code' => $code,
                    'scope' => \implode(' ', $this->getScopes()),
                ])
            ), true);
        }

        return $this->tokens;
    }

    /**
     * @param  string  $refreshToken
     * @return array
     */
    public function refreshTokens(string $refreshToken): array
    {
        $this->tokens = \json_decode($this->request(
            'POST',
            $this->endpoint.'/oauth/token',
            ['Content-Type: application/x-www-form-urlencoded'],
            \http_build_query([
                'grant_type' => 'refresh_token',
                'refresh_token' => $refreshToken,
                'client_id' => $this->appID,
                'client_secret' => $this->appSecret,
            ])
        ), true);

        if (empty($this->tokens['refresh_token'])) {
            $this->tokens['refresh_token'] = $refreshToken;
        }

        return $this->tokens;
    }

    /**
     * @param  string  $accessToken
     * @return string
     */
    public function getUserID(string $accessToken): string
    {
        $user = $this->getUser($accessToken);

        $userId = $user['id'] ?? '';

        return $userId;
    }

    /**
     * @param  string  $accessToken
     * @return string
     */
    public function getUserEmail(string $accessToken): string
    {
        $user = $this->getUser($accessToken);
        $userEmail = $user['email'] ?? '';

        return $userEmail;
    }

    /**
     * Check if the OAuth email is verified
     *
     * @link https://developers.dailymotion.com/api/#user-fields
     *
     * @param  string  $accessToken
     * @return bool
     */
    public function isEmailVerified(string $accessToken): bool
    {
        $user = $this->getUser($accessToken);

        return $user['verified'] ?? false;
    }

    /**
     * @param  string  $accessToken
     * @return string
     */
    public function getUserName(string $accessToken): string
    {
        $user = $this->getUser($accessToken);

        $username = $user['fullname'] ?? '';

        return $username;
    }

    /**
     * @param  string  $accessToken
     * @return array
     */
    protected function getUser(string $accessToken): array
    {
        if (empty($this->user)) {
            $user = $this->request(
                'GET',
                $this->endpoint.'/user/me?fields='.\implode(',', $this->getFields()),
                ['Authorization: Bearer '.\urlencode($accessToken)],
            );
            $this->user = \json_decode($user, true);
        }

        return $this->user;
    }
}

<?php

namespace Appwrite\Auth\OAuth2;

use Appwrite\Auth\OAuth2;

// Reference Material
// https://disqus.com/api/docs/auth/

class Disqus extends OAuth2
{
    private string $endpoint = 'https://disqus.com/api/';
    protected array $user = [];
    protected array $tokens = [];
    protected array $scopes = [
        'read',
        'email',
    ];

    public function getName(): string
    {
        return 'disqus';
    }

    public function getLoginURL(): string
    {
        $url = $this->endpoint . 'oauth/2.0/authorize/?' .
            \http_build_query([
                'response_type' => 'code',
                'client_id' => $this->appID,
                'state' => \json_encode($this->state),
                'redirect_uri' => $this->callback,
                'scope' => \implode(',', $this->getScopes())
            ]);

        return $url;
    }

    protected function getTokens(string $code): array
    {
        if (empty($this->tokens)) {
            $this->tokens = \json_decode($this->request(
                'POST',
                $this->endpoint . 'oauth/2.0/access_token/',
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

    public function refreshTokens(string $refreshToken): array
    {
        $this->tokens = \json_decode($this->request(
            'POST',
            $this->endpoint . 'oauth/2.0/access_token/?',
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

    public function getUserID(string $accessToken): string
    {
        $user = $this->getUser($accessToken);

        $userId = $user['response']['id'];

        return $userId;
    }

    public function getUserEmail(string $accessToken): string
    {
        $user = $this->getUser($accessToken);

        $userEmail = $user['response']['email'];

        return $userEmail;
    }

    public function isEmailVerified(string $accessToken): bool
    {
        $user = $this->getUser($accessToken);

        $isVerified = $user['response']['isAnonymous'];

        return $isVerified;
    }

    public function getUserName(string $accessToken): string
    {
        $user = $this->getUser($accessToken);

        $username = $user['response']['username'] ?? '';

        return $username;
    }

    protected function getUser(string $accessToken): array
    {
        if (empty($this->user)) {
            $user = $this->request(
                'GET',
                $this->endpoint . '3.0/users/details.json?' . \http_build_query([
                    'access_token' => $accessToken,
                    'api_key' => $this->appID,
                    'api_secret' => $this->appSecret
                ]),
            );
            $this->user = \json_decode($user, true);
        }

        return $this->user;
    }
}

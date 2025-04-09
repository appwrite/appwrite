<?php

namespace Appwrite\Auth\OAuth2;

use Appwrite\Auth\OAuth2;

// Reference Material
// https://www.figma.com/developers/api#oauth2
// https://www.figma.com/developers/api#authentication

class Figma extends OAuth2
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
        'current_user:read'
    ];

    /**
     * @return string
     */
    public function getName(): string
    {
        return 'figma';
    }

    /**
     * @return string
     */
    public function getLoginURL(): string
    {
        return 'https://www.figma.com/oauth?' . \http_build_query([
            'response_type' => 'code',
            'client_id' => $this->appID,
            'redirect_uri' => $this->callback,
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
            $headers = [
                'Content-Type: application/x-www-form-urlencoded',
                'Authorization: Basic ' . \base64_encode($this->appID . ':' . $this->appSecret)
            ];
            $this->tokens = \json_decode($this->request(
                'POST',
                'https://api.figma.com/v1/oauth/token',
                $headers,
                \http_build_query([
                    'redirect_uri' => $this->callback,
                    'code' => $code,
                    'grant_type' => 'authorization_code'
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
            'Authorization: Basic ' . \base64_encode($this->appID . ':' . $this->appSecret)
        ];
        $this->tokens = \json_decode($this->request(
            'POST',
            'https://api.figma.com/v1/oauth/refresh',
            $headers,
            \http_build_query([
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
     * Figma requires email verification during signup,
     * so if we have an email, it's verified
     *
     * @param string $accessToken
     *
     * @return bool
     */
    public function isEmailVerified(string $accessToken): bool
    {
        $email = $this->getUserEmail($accessToken);

        return !empty($email);
    }

    /**
     * @param string $accessToken
     *
     * @return string
     */
    public function getUserName(string $accessToken): string
    {
        $user = $this->getUser($accessToken);

        return $user['handle'] ?? '';
    }

    /**
     * @param string $accessToken
     *
     * @return array
     */
    protected function getUser(string $accessToken): array
    {
        if (empty($this->user)) {
            $headers = ['Authorization: Bearer ' . $accessToken];
            $user = $this->request(
                'GET',
                'https://api.figma.com/v1/me',
                $headers
            );
            $this->user = \json_decode($user, true);
        }

        return $this->user;
    }
}

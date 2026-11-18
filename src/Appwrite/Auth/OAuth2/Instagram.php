<?php

namespace Appwrite\Auth\OAuth2;

use Appwrite\Auth\OAuth2;

// Reference Material
// https://developers.facebook.com/docs/instagram-platform/instagram-api-with-instagram-login/business-login

class Instagram extends OAuth2
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
        'instagram_business_basic',
    ];

    /**
     * @return string
     */
    public function getName(): string
    {
        return 'instagram';
    }

    /**
     * @return string
     */
    public function getLoginURL(): string
    {
        return 'https://www.instagram.com/oauth/authorize?' . \http_build_query([
            'response_type' => 'code',
            'client_id' => $this->appID,
            'redirect_uri' => $this->callback,
            'scope' => \implode(',', $this->getScopes()),
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
            $response = $this->request(
                'POST',
                'https://api.instagram.com/oauth/access_token',
                ['Content-Type: application/x-www-form-urlencoded'],
                \http_build_query([
                    'grant_type' => 'authorization_code',
                    'client_id' => $this->appID,
                    'client_secret' => $this->appSecret,
                    'redirect_uri' => $this->callback,
                    'code' => $code,
                ])
            );

            $tokens = \json_decode($response, true);
            // Documented as wrapped in a data list, but also returned as a flat object
            $tokens = $tokens['data'][0] ?? $tokens;

            if (!\is_array($tokens) || empty($tokens['access_token'])) {
                throw new Exception($response, 424);
            }

            // The short-lived token lasts an hour and cannot be refreshed
            $this->tokens = $this->parseTokens($this->request(
                'GET',
                'https://graph.instagram.com/access_token?' . \http_build_query([
                    'grant_type' => 'ig_exchange_token',
                    'client_secret' => $this->appSecret,
                    'access_token' => $tokens['access_token'],
                ])
            ));
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
        $this->tokens = $this->parseTokens($this->request(
            'GET',
            'https://graph.instagram.com/refresh_access_token?' . \http_build_query([
                'grant_type' => 'ig_refresh_token',
                'access_token' => $refreshToken,
            ])
        ));

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

        return (string) ($user['user_id'] ?? '');
    }

    /**
     * Instagram does not share the account's email address
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
     * @param string $accessToken
     *
     * @return string
     */
    public function getUserName(string $accessToken): string
    {
        $user = $this->getUser($accessToken);

        return $user['name'] ?? $user['username'] ?? '';
    }

    /**
     * @param string $accessToken
     *
     * @return string
     */
    public function getUserPhoto(string $accessToken): string
    {
        $user = $this->getUser($accessToken);

        return $user['profile_picture_url'] ?? '';
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
                'https://graph.instagram.com/me?' . \http_build_query([
                    'fields' => 'user_id,username,name,profile_picture_url',
                    'access_token' => $accessToken,
                ])
            ), true) ?? [];
        }

        return $this->user;
    }

    /**
     * Instagram refreshes the long-lived access token itself, so it doubles as the refresh token.
     *
     * @param string $response
     *
     * @return array
     */
    private function parseTokens(string $response): array
    {
        $tokens = \json_decode($response, true);

        if (!\is_array($tokens) || empty($tokens['access_token'])) {
            throw new Exception($response, 424);
        }

        $tokens['refresh_token'] = $tokens['access_token'];

        return $tokens;
    }
}

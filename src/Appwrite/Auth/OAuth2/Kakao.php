<?php

namespace Appwrite\Auth\OAuth2;

use Appwrite\Auth\OAuth2;

// Reference Material
// https://developers.kakao.com/docs/latest/ko/kakaologin/rest-api

class Kakao extends OAuth2
{
    /**
     * @var array
     */
    protected array $scopes = [
        'profile_nickname',
        'profile_image',
        'account_email',
        // Optional scopes that can be enabled based on app requirements:
        // 'openid',      // for OpenID Connect ID tokens
        // 'gender',      // for user's gender information
        // 'age_range',   // for user's age range
        // 'birthday'     // for user's birthday
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
        return 'kakao';
    }

    /**
     * @return string
     */
    public function getLoginURL(): string
    {
        return 'https://kauth.kakao.com/oauth/authorize?' . \http_build_query([
            'client_id' => $this->appID,
            'redirect_uri' => $this->callback,
            'response_type' => 'code',
            'state' => \json_encode($this->state),
            'scope' => \implode(' ', $this->getScopes())
        ]);
    }

    /**
     * @param string $code
     *
     * @return array
     * @throws \Exception
     */
    protected function getTokens(string $code): array
    {
        if (empty($code)) {
            throw new \Exception('Authorization code is required');
        }

        if (empty($this->tokens)) {
            $headers = ['Content-Type: application/x-www-form-urlencoded'];
            $response = $this->request(
                'POST',
                'https://kauth.kakao.com/oauth/token',
                $headers,
                \http_build_query([
                    'grant_type' => 'authorization_code',
                    'client_id' => $this->appID,
                    'client_secret' => $this->appSecret,
                    'redirect_uri' => $this->callback,
                    'code' => $code
                ])
            );

            if (empty($response)) {
                throw new \Exception('Failed to exchange code for token: Empty response from server');
            }

            $tokens = \json_decode($response, true);
            
            if (\json_last_error() !== JSON_ERROR_NONE) {
                throw new \Exception('Failed to exchange code for token: Invalid JSON response - ' . \json_last_error_msg());
            }

            if (empty($tokens) || !isset($tokens['access_token'])) {
                throw new \Exception('Failed to exchange code for token: Invalid token response');
            }

            $this->tokens = $tokens;
        }

        return $this->tokens;
    }

    /**
     * @param string $refreshToken
     *
     * @return array
     * @throws \Exception
     */
    public function refreshTokens(string $refreshToken): array
    {
        if (empty($refreshToken)) {
            throw new \Exception('Refresh token is required');
        }

        $headers = ['Content-Type: application/x-www-form-urlencoded'];
        $response = $this->request(
            'POST',
            'https://kauth.kakao.com/oauth/token',
            $headers,
            \http_build_query([
                'grant_type' => 'refresh_token',
                'client_id' => $this->appID,
                'client_secret' => $this->appSecret,
                'refresh_token' => $refreshToken
            ])
        );

        if (empty($response)) {
            throw new \Exception('Failed to refresh token: Empty response from server');
        }

        $tokens = \json_decode($response, true);
        
        if (\json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception('Failed to refresh token: Invalid JSON response - ' . \json_last_error_msg());
        }

        if (empty($tokens) || !isset($tokens['access_token'])) {
            throw new \Exception('Failed to refresh token: Invalid token response');
        }

        $this->tokens = $tokens;

        // If the server didn't return a new refresh token, keep the old one
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
        return (string)($user['id'] ?? '');
    }

    /**
     * @param string $accessToken
     *
     * @return string
     */
    public function getUserEmail(string $accessToken): string
    {
        $user = $this->getUser($accessToken);
        return $user['kakao_account']['email'] ?? '';
    }

    /**
     * Check if the OAuth email is verified
     *
     * @param string $accessToken
     *
     * @return bool
     */
    public function isEmailVerified(string $accessToken): bool
    {
        $user = $this->getUser($accessToken);
        return $user['kakao_account']['is_email_verified'] ?? false;
    }

    /**
     * @param string $accessToken
     *
     * @return string
     */
    public function getUserName(string $accessToken): string
    {
        $user = $this->getUser($accessToken);
        return $user['kakao_account']['profile']['nickname'] ?? '';
    }

    /**
     * @param string $accessToken
     *
     * @return array
     * @throws \Exception
     */
    protected function getUser(string $accessToken): array
    {
        if (empty($accessToken)) {
            throw new \Exception('Access token is required');
        }

        if (empty($this->user)) {
            $headers = [
                'Authorization: Bearer ' . $accessToken,
                'Content-Type: application/x-www-form-urlencoded;charset=utf-8'
            ];
            
            $response = $this->request('GET', 'https://kapi.kakao.com/v2/user/me', $headers);

            if (empty($response)) {
                throw new \Exception('Failed to fetch user data: Empty response from server');
            }

            $user = \json_decode($response, true);
            
            if (\json_last_error() !== JSON_ERROR_NONE) {
                throw new \Exception('Failed to fetch user data: Invalid JSON response - ' . \json_last_error_msg());
            }

            if (empty($user) || !isset($user['id'])) {
                throw new \Exception('Failed to fetch user data: Invalid user data response');
            }

            $this->user = $user;
        }

        return $this->user;
    }
}

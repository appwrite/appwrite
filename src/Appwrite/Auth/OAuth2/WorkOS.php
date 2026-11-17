<?php

namespace Appwrite\Auth\OAuth2;

use Appwrite\Auth\OAuth2;

// Reference Material
// https://workos.com/docs/reference/user-management/authentication/get-authorization-url
// https://workos.com/docs/reference/user-management/authentication/code

class WorkOS extends OAuth2
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
    protected array $scopes = [];

    /**
     * @return string
     */
    public function getName(): string
    {
        return 'workos';
    }

    /**
     * Sends the user to AuthKit, which offers every connection configured
     * for the WorkOS environment.
     *
     * @return string
     */
    public function getLoginURL(): string
    {
        return 'https://api.workos.com/user_management/authorize?' . \http_build_query([
            'response_type' => 'code',
            'client_id' => $this->appID,
            'redirect_uri' => $this->callback,
            'provider' => 'authkit',
            'state' => \json_encode($this->state),
        ]);
    }

    /**
     * The token response also carries the authenticated user.
     *
     * @param string $code
     *
     * @return array
     */
    protected function getTokens(string $code): array
    {
        if (empty($this->tokens)) {
            $this->tokens = \json_decode($this->request(
                'POST',
                'https://api.workos.com/user_management/authenticate',
                ['Content-Type: application/x-www-form-urlencoded'],
                \http_build_query([
                    'grant_type' => 'authorization_code',
                    'client_id' => $this->appID,
                    'client_secret' => $this->appSecret,
                    'code' => $code,
                ])
            ), true);

            $this->user = $this->tokens['user'] ?? [];
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
            'https://api.workos.com/user_management/authenticate',
            ['Content-Type: application/x-www-form-urlencoded'],
            \http_build_query([
                'grant_type' => 'refresh_token',
                'client_id' => $this->appID,
                'client_secret' => $this->appSecret,
                'refresh_token' => $refreshToken,
            ])
        ), true);

        if (empty($this->tokens['refresh_token'])) {
            $this->tokens['refresh_token'] = $refreshToken;
        }

        if (!empty($this->tokens['user'])) {
            $this->user = $this->tokens['user'];
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
        return $this->user['id'] ?? '';
    }

    /**
     * @param string $accessToken
     *
     * @return string
     */
    public function getUserEmail(string $accessToken): string
    {
        return $this->user['email'] ?? '';
    }

    /**
     * Check if the OAuth email is verified
     *
     * @link https://workos.com/docs/reference/user-management/user
     *
     * @param string $accessToken
     *
     * @return bool
     */
    public function isEmailVerified(string $accessToken): bool
    {
        return ($this->user['email_verified'] ?? false) === true;
    }

    /**
     * @param string $accessToken
     *
     * @return string
     */
    public function getUserName(string $accessToken): string
    {
        return \trim(($this->user['first_name'] ?? '') . ' ' . ($this->user['last_name'] ?? ''));
    }

    /**
     * @param string $accessToken
     *
     * @return string
     */
    public function getUserPhoto(string $accessToken): string
    {
        return $this->user['profile_picture_url'] ?? '';
    }
}

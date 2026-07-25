<?php

namespace Appwrite\Auth\OAuth2;

use Appwrite\Auth\OAuth2;

class Linkedin extends OAuth2
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
        'email'
    ];

    /**
     * Documentation.
     *
     * OAuth:
     * https://developer.linkedin.com/docs/oauth2
     *
     * API/V2:
     * https://developer.linkedin.com/docs/guide/v2
     *
     * Basic Profile Fields:
     * https://developer.linkedin.com/docs/fields/basic-profile
     */

    /**
     * @return string
     */
    public function getName(): string
    {
        return 'linkedin';
    }

    /**
     * @return string
     */
    public function getLoginURL(): string
    {
        return 'https://www.linkedin.com/oauth/v2/authorization?' . \http_build_query([
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
            $this->tokens = \json_decode($this->request(
                'POST',
                'https://www.linkedin.com/oauth/v2/accessToken',
                ['Content-Type: application/x-www-form-urlencoded'],
                \http_build_query([
                    'grant_type' => 'authorization_code',
                    'code' => $code,
                    'redirect_uri' => $this->callback,
                    'client_id' => $this->appID,
                    'client_secret' => $this->appSecret,
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
        $this->tokens = \json_decode($this->request(
            'POST',
            'https://www.linkedin.com/oauth/v2/accessToken',
            ['Content-Type: application/x-www-form-urlencoded'],
            \http_build_query([
                'grant_type' => 'refresh_token',
                'refresh_token' => $refreshToken,
                'redirect_uri' => $this->callback,
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
     * If present, the email is verified. This was verfied through a manual Linkedin sign up process
     *
     * @param string $accessToken
     *
     * @return bool
     */
    public function isEmailVerified(string $accessToken): bool
    {
        $user = $this->getUser($accessToken);
        return $user['email_verified'] ?? false;
    }

    /**
     * @param string $accessToken
     *
     * @return string
     */
    public function getUserName(string $accessToken): string
    {
        $user = $this->getUser($accessToken);
        $name = '';

        if (isset($user['name'])) {
            return $user['name'];
        }

        if (isset($user['given_name'])) {
            $name = $user['given_name'];
        }

        if (isset($user['family_name'])) {
            $name = (empty($name)) ? $user['family_name'] : $name . ' ' . $user['family_name'];
        }

        return $name;
    }

    /**
     * @param string $accessToken
     *
     * @return array
     */
    protected function getUser(string $accessToken)
    {
        if (empty($this->user)) {
            $this->user = \json_decode($this->request('GET', 'https://api.linkedin.com/v2/userinfo', ['Authorization: Bearer ' . \urlencode($accessToken)]), true);
        }

        return $this->user;
    }
}

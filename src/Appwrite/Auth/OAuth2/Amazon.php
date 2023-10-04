<?php

namespace Appwrite\Auth\OAuth2;

use Appwrite\Auth\OAuth2;

class Amazon extends OAuth2
{
    // Constants for URLs
    const AMAZON_AUTHORIZE_URL = 'https://www.amazon.com/ap/oa';
    const AMAZON_TOKEN_URL = 'https://api.amazon.com/auth/o2/token';
    const AMAZON_USER_PROFILE_URL = 'https://api.amazon.com/user/profile';

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
        'profile'
    ];

    /**
     * @return string
     */
    public function getName(): string
    {
        return 'amazon';
    }

    /**
     * @param string $state
     *
     * @return array
     */
    public function parseState(string $state): array
    {
        return json_decode(html_entity_decode($state), true);
    }

    /**
     * @return string
     */
    public function getLoginURL(): string
    {
        $params = [
            'response_type' => 'code',
            'client_id' => $this->appID,
            'scope' => implode(' ', $this->getScopes()),
            'state' => json_encode($this->state),
            'redirect_uri' => $this->callback,
        ];

        return self::AMAZON_AUTHORIZE_URL . '?' . http_build_query($params);
    }

    /**
     * @param string $code
     *
     * @return array
     */
    protected function getTokens(string $code): array
    {
        if (empty($this->tokens)) {
            $params = [
                'code' => $code,
                'client_id' => $this->appID,
                'client_secret' => $this->appSecret,
                'redirect_uri' => $this->callback,
                'grant_type' => 'authorization_code',
            ];

            $headers = ['Content-Type: application/x-www-form-urlencoded;charset=UTF-8'];
            $this->tokens = json_decode($this->request('POST', self::AMAZON_TOKEN_URL, $headers, http_build_query($params)), true);
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
        $params = [
            'client_id' => $this->appID,
            'client_secret' => $this->appSecret,
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
        ];

        $headers = ['Content-Type: application/x-www-form-urlencoded;charset=UTF-8'];
        $this->tokens = json_decode($this->request('POST', self::AMAZON_TOKEN_URL, $headers, http_build_query($params)), true);

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

        return $user['user_id'] ?? '';
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
     * If present, the email is verified. This was verified through a manual Amazon sign-up process.
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
            $user = $this->request('GET', self::AMAZON_USER_PROFILE_URL . '?access_token=' . urlencode($accessToken));
            $this->user = json_decode($user, true);
        }

        return $this->user;
    }
}

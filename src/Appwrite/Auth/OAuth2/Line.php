<?php

namespace Appwrite\Auth\OAuth2;

use Appwrite\Auth\OAuth2;

class Line extends OAuth2
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
        'profile',
        'openid',
        'email',
    ];

    /**
     * @var array
     */
    protected array $idToken = [];

    /**
     * @return string
     */
    public function getName(): string
    {
        return 'line';
    }

    /**
     * @return string
     */
    public function getLoginURL(): string
    {
        return 'https://access.line.me/oauth2/v2.1/authorize?' . \http_build_query([
            'response_type' => 'code',
            'client_id' => $this->appID,
            'redirect_uri' => $this->callback,
            'state' => \json_encode($this->state),
            'scope' => \implode('%20', $this->getScopes())
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
                'https://api.line.me/oauth2/v2.1/token',
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
            'https://api.line.me/oauth2/v2.1/token',
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
     * @param string $accessToken
     *
     * @return string
     */
    public function getUserID(string $accessToken): string
    {
        $user = $this->getUser($accessToken);
        $userInfo = $this->verifyToken();

        return ($user) ? $userInfo['sub'] : '';
    }

    /**
     * @param string $accessToken
     *
     * @return string
     */
    public function getUserEmail(string $accessToken): string
    {
        $user = $this->getUser($accessToken);
        $userInfo = $this->verifyToken();

        return ($user) ? $userInfo['email'] : '';
    }

    /**
     * Check if the OAuth email is verified
     *
     * @link https://docs.github.com/en/rest/users/emails#list-email-addresses-for-the-authenticated-user
     *
     * @param string $accessToken
     *
     * @return bool
     */
    public function isEmailVerified(string $accessToken): bool
    {   
        $user = $this->getUser($accessToken);
        $userInfo = $this->verifyToken();
    
        return ($user && $userInfo['email']) ? true : false ;
    }

    /**
     * @param string $accessToken
     *
     * @return string
     */
    public function getUserName(string $accessToken): string
    {
        $user = $this->getUser($accessToken);
        $userInfo = $this->verifyToken();
    
        return ($user) ? $userInfo['name'] : '';
    }

    /**
     * @param string $accessToken
     *
     * @return array
     */
    protected function getUser(string $accessToken): array
    {
        if (empty($this->user)) {
            $headers = ['Authorization: Bearer ' . \urlencode($accessToken)];
            $user = $this->request('GET', ' https://api.line.me/oauth2/v2.1/userinfo', $headers);
            $this->user = \json_decode($user, true);
        }

        return $this->user;
    }

    /**
     * @param string $accessToken
     *
     * @return array
     */
    protected function verifyToken(): array
    {
        if (empty($this->idToken)) {
            $this->idToken = \json_decode($this->request(
                'POST',
                'https://api.line.me/oauth2/v2.1/verify',
                ['Content-Type: application/x-www-form-urlencoded'],
                \http_build_query([
                    'id_token' => $this->tokens['id_token'],
                    'client_id' => $this->appID,
                    'client_secret' => $this->appSecret,
                    
                ])
            ), true);
        }
        return $this->idToken;
    }
}

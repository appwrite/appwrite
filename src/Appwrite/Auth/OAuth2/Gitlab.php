<?php

namespace Appwrite\Auth\OAuth2;

use Appwrite\Auth\OAuth2;

// Reference Material
// https://docs.gitlab.com/ee/api/oauth2.html

class Gitlab extends OAuth2
{
    /**
     * @var array
     */
    protected $user = [];
    
    /**
     * @var array
     */
    protected $tokens = [];

    /**
     * @var array
     */
    protected $scopes = [
        'read_user'
    ];

    /**
     * @return string
     */
    public function getName(): string
    {
        return 'gitlab';
    }

    /**
     * @return string
     */
    public function getLoginURL(): string
    {
        return 'https://gitlab.com/oauth/authorize?'.\http_build_query([
            'client_id' => $this->appID,
            'redirect_uri' => $this->callback,
            'scope' => \implode(' ', $this->getScopes()),
            'state' => \json_encode($this->state),
            'response_type' => 'code'
        ]);
    }

    /**
     * @param string $code
     *
     * @return array
     */
    protected function getTokens(string $code): array
    {
        if(empty($this->tokens)) {
            $this->tokens = \json_decode($this->request(
                'POST',
                'https://gitlab.com/oauth/token?' . \http_build_query([
                    'code' => $code,
                    'client_id' => $this->appID,
                    'client_secret' => $this->appSecret,
                    'redirect_uri' => $this->callback,
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
    public function refreshTokens(string $refreshToken):array
    {
        $this->tokens = \json_decode($this->request(
            'POST',
            'https://gitlab.com/oauth/token?' . \http_build_query([
                'refresh_token' => $refreshToken,
                'client_id' => $this->appID,
                'client_secret' => $this->appSecret,
                'grant_type' => 'refresh_token'
            ])
        ), true);

        if(empty($this->tokens['refresh_token'])) {
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

        if (isset($user['id'])) {
            return $user['id'];
        }

        return '';
    }

    /**
     * @param string $accessToken
     *
     * @return string
     */
    public function getUserEmail(string $accessToken): string
    {
        $user = $this->getUser($accessToken);

        if (isset($user['email'])) {
            return $user['email'];
        }

        return '';
    }

    /**
     * @param string $accessToken
     *
     * @return string
     */
    public function getUserName(string $accessToken): string
    {
        $user = $this->getUser($accessToken);

        if (isset($user['name'])) {
            return $user['name'];
        }

        return '';
    }

    /**
     * @param string $accessToken
     *
     * @return array
     */
    protected function getUser(string $accessToken): array
    {
        if (empty($this->user)) {
            $user = $this->request('GET', 'https://gitlab.com/api/v4/user?access_token='.\urlencode($accessToken));
            $this->user = \json_decode($user, true);
        }

        return $this->user;
    }
}

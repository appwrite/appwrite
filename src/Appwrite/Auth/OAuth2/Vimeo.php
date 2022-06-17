<?php

namespace Appwrite\Auth\OAuth2;

use Appwrite\Auth\OAuth2;

// Reference Material
// https://developer.vimeo.com/api/authentication

class Vimeo extends OAuth2
{
    /**
     * @var string
     */
    private string $endpoint = 'https://api.vimeo.com';
    
     /**
     * @var array
     */
    protected array $scopes = [
        'public',
        'private'
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
        return 'vimeo';
    }

    /**
     * @return string
     */
    public function getLoginURL(): string
    {
        return $this->endpoint . '/oauth/authorize?'.\http_build_query([
            'client_id' => $this->appID,
            'response_type' => 'code',
            'redirect_uri' => $this->callback,
            'state'=> \json_encode($this->state),
            'scope'=> \implode(' ', $this->getScopes())
        ]);
    }

    /**
     * @param string $code
     *
     * @return array
     */
    public function getAccessToken(string $code):string
    {
        // https://developer.vimeo.com/api/authentication#using-the-client-credentials-grant-step-2
        $accessToken = $this->request(
            'POST',
            $this->endpoint . '/oauth/access_token', [
                'Content-Type' => 'application/json',
                'Accept' => 'application/vnd.vimeo.*+json;version=3.4',
            ], \http_build_query([
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $this->callback,
                'client_id' => $this->appID,
                'client_secret' => $this->appSecret,
            ]));

        $accessToken = \json_decode($accessToken, true);

        if (isset($accessToken['access_token'])) {
            return $accessToken['access_token'];
        }

        return '';
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
            $this->endpoint . 'token?' . \http_build_query([
                "client_id" => $this->appID,
                "client_secret" => $this->appSecret,
                "refresh_token" => $refreshToken,
                "grant_type" => "refresh_token",
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
    public function getUserID(string $accessToken):string
    {
        $user = $this->getUser($accessToken);

        if (isset($user['uri'])) {
            return $user['uri'];
        }

        return '';
    }

    /**
     * @param string $accessToken
     *
     * @return string
     */
    public function getUserEmail(string $accessToken):string
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
    public function getUserName(string $accessToken):string
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
    protected function getUser(string $accessToken)
    {
        if (empty($this->user)) {
            $this->user = \json_decode($this->request(
                'GET',
                $this->endpoint . "/me",
                ['Authorization: Bearer '.\urlencode($accessToken)]
            ), true);
        }

        \var_dump(\json_encode($this->user));

        return $this->user;
    }

        /**
     * Check if the OAuth email is verified
     *
     * @link https://discord.com/developers/docs/resources/user
     *
     * @param string $accessToken
     *
     * @return bool
     */
    public function isEmailVerified(string $accessToken): bool
    {
        $user = $this->getUser($accessToken);

        if ($user['verified'] ?? false) {
            return true;
        }

        return false;
    }

        /**
     * @param string $code
     *
     * @return array
     */
    protected function getTokens(string $code): array
    {
        // TODO: Endpoint could be different, or have different response
        if (empty($this->tokens)) {
            $this->tokens = \json_decode($this->request(
                'POST',
                $this->endpoint . '/oauth2/token',
                ['Content-Type: application/x-www-form-urlencoded'],
                \http_build_query([
                    'grant_type' => 'authorization_code',
                    'code' => $code,
                    'redirect_uri' => $this->callback,
                    'client_id' => $this->appID,
                    'client_secret' => $this->appSecret,
                    'scope' => \implode(' ', $this->getScopes())
                ])
            ), true);
        }

        return $this->tokens;
    }
}
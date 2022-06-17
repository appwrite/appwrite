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
    private $endpoint = [];
    
     /**
     * @var array
     */
    protected $scopes = [
        "private"
    ];
    
    /**
     * @var array
     */
    protected $user = [];
    
    /**
     * @var array
     */
    protected $tokens = [];
    
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
        return 'https://api.vimeo.com/oauth/authorize?'.\http_build_query([
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
            'https://api.vimeo.com/oauth/access_token'.\http_build_query([
                'client_id' => $this->appID,
                'code' => $code,
                'redirect_uri' => $this->callback,
                'grant_type' => 'authorization_code'
            ])
        );

        $accessToken = \json_decode($accessToken, true); //

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

        if (isset($user['display_name'])) {
            return $user['display_name'];
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
                $this->resourceEndpoint . "me",
                ['Authorization: Bearer '.\urlencode($accessToken)]
            ), true);
        }

        return $this->user;
    }
}
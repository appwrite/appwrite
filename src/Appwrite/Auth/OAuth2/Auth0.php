<?php

namespace Appwrite\Auth\OAuth2;

use Appwrite\Auth\OAuth2;

// Reference Material
// https://auth0.com/docs/api/authentication

class Auth0 extends OAuth2
{  
     /**
     * @var array
     */
    protected $scopes = [
        'openid',
        'profile',
        'email',
        'offline_access'
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
        return 'auth0';
    }

    /**
     * @return string
     */
    public function getLoginURL(): string
    {
        return 'https://'.$this->getAuth0Domain().'/authorize?'.\http_build_query([
            'client_id' => $this->appID,
            'redirect_uri' => $this->callback,
            'state'=> \json_encode($this->state),
            'scope'=> \implode(' ', $this->getScopes()),
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
            $headers = ['Content-Type: application/x-www-form-urlencoded'];
            $this->tokens = \json_decode($this->request(
                'POST',
                'https://'.$this->getAuth0Domain().'/oauth/token',
                $headers,
                \http_build_query([
                    'code' => $code,
                    'client_id' => $this->appID,
                    'client_secret' => $this->getClientSecret(),
                    'redirect_uri' => $this->callback,
                    'scope' => \implode(' ', $this->getScopes()),
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
        $headers = ['Content-Type: application/x-www-form-urlencoded'];
        $this->tokens = \json_decode($this->request(
            'POST',
            'https://'.$this->getAuth0Domain().'/oauth/token',
            $headers,
            \http_build_query([
                'refresh_token' => $refreshToken,
                'client_id' => $this->appID,
                'client_secret' => $this->getClientSecret(),
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
        
        if (isset($user['sub'])) {
            return $user['sub'];
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
            $headers = ['Authorization: Bearer '. \urlencode($accessToken)];
            $user = $this->request('GET', 'https://'.$this->getAuth0Domain().'/userinfo', $headers);
            $this->user = \json_decode($user, true);
        }

        return $this->user;
    }

    /**
     * Extracts the Client Secret from the JSON stored in appSecret
     * 
     * @return string
     */
    protected function getClientSecret(): string
    {
        $secret = $this->getAppSecret();

        return (isset($secret['clientSecret'])) ? $secret['clientSecret'] : ''; 
    }

     /**
     * Extracts the Auth0 Domain from the JSON stored in appSecret
     * 
     * @return string
     */
    protected function getAuth0Domain(): string
    {
        $secret = $this->getAppSecret();
        return (isset($secret['auth0Domain'])) ? $secret['auth0Domain'] : ''; 
    }

    /**
     * Decode the JSON stored in appSecret
     * 
     * @return array
     */
    protected function getAppSecret(): array
    {    
        try {
            $secret = \json_decode($this->appSecret, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $th) {
            throw new \Exception('Invalid secret');
        }
        return $secret;
    }
}
<?php

namespace Appwrite\Auth\OAuth2;

use Appwrite\Auth\OAuth2;

// Reference Material
// https://developers.dailymotion.com/api/#authentication

class Dailymotion extends OAuth2
{
    /**
     * @var string
     */
    private $endpoint = 'https://api.dailymotion.com';
    private $authEndpoint = 'https://www.dailymotion.com/oauth/authorize';
    
     /**
     * @var array
     */
    protected $scopes = [
        'userinfo',
        'email',
    ];

    /**
     * @var array
     */
    protected $fields = [
        'avatar_url',
        'email',
        'first_name',
        'id',
        'last_name',
        'status',
        'username',
        'verified'
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
        return 'dailymotion';
    }

    /**
     * @return string
     */
    public function getLoginURL(): string
    {
        $url = $this->authEndpoint . '?' .
        \http_build_query([
            'response_type' => 'code',
            'client_id' => $this->appID,
            'state' => \json_encode($this->state),
            'redirect_uri' => $this->callback,
            'scope' => \implode(' ', $this->getScopes())
        ]);

        return $url;
    }

    /**
     * 
     *
     * @return array
     */
        protected function getFields(): array {
        return $this->fields;
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
                $this->endpoint . 'oauth/token',
                ["Content-Type: application/x-www-form-urlencoded"],
                \http_build_query([
                    'grant_type' => 'authorization_code',
                    "client_id" => $this->appID,
                    "client_secret" => $this->appSecret,
                    "redirect_uri" => $this->callback,
                    'scope' => \implode(' ', $this->getScopes())
                ])
            );

            $output = [];
            \parse_str($response, $output);
            $this->tokens = $output;
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
        // TODO: Fire request to oauth API to generate access_token using refresh token
        $this->tokens = \json_decode($this->request(
            'POST',
            $this->endpoint . '/oauth/token',
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
        
        // TODO: Pick user ID from $user response 
        $userId = $user['id'] ?? '';
        
        return $userId;
    }

    /**
     * @param string $accessToken
     *
     * @return string
     */
    public function getUserEmail(string $accessToken): string
    {
        $user = $this->getUser($accessToken);
        
        // TODO: Pick user email from $user response 
        $userEmail = $user['email'] ?? '';
        
        return $userEmail;
    }

    /**
     * Check if the OAuth email is verified
     *
     * @link https://developers.dailymotion.com/api/#user-fields
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
     * @param string $accessToken
     *
     * @return string
     */
    public function getUserName(string $accessToken): string
    {
        $user = $this->getUser($accessToken);
        
        // TODO: Pick username from $user response 
        $username = $user['username'] ?? '';
        
        return $username;
    }
    
     /**
     * @param string $accessToken
     *
     * @return array
     */
    protected function getUser(string $accessToken)
    {
        if (empty($this->user)) {
            $user = $this->request(
                'GET',
                $this->endpoint . '/user/me?',
                ['Authorization: Bearer ' . \urlencode($accessToken)],
                \http_build_query([
                    'fields' => \implode(',', $this->getFields())])
            );
            $this->user = \json_decode($user, true);
        }

        return $this->user;
    }
}
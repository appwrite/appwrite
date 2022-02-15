<?php

namespace Appwrite\Auth\OAuth2;

use Appwrite\Auth\OAuth2;


class Oidc extends OAuth2
{
    /**
     * @var string
     */
    protected $version = 'v1';
    
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
        'openid',
        'email',
        'profile',
    ];

    /**
     * @var string
     */
    protected $configuration = [
        'authorization_endpoint' => '',
        'token_endpoint' => '',
        'userinfo_endpoint' => '',
    ];

    /**
     * Oidc constructor.
     *
     * @param string $appId
     * @param string $jsonSecret
     * @param string $callback
     * @param array  $state
     * @param array $scopes
     */
    public function __construct(string $appId, string $jsonSecret, string $callback, array $state = [], array $scopes = [])
    {
        // Extract appSecret and discovery from JSON
        try {
            $json = \json_decode($jsonSecret, true);
            $appSecret = isset($json['clientSecret']) ? $json['clientSecret'] : '';
            $discovery = isset($json['discovery']) ? $json['discovery'] : '';
        } catch (\Throwable $th) {
            throw new Exception('Invalid secret');
        }
        parent::__construct($appId, $appSecret, $callback, $state, $scopes);
        
        // Get config from .well-known discovery endpoint
        $configResponse = \json_decode($this->request('GET',$discovery),true);
        $this->configuration['authorization_endpoint'] = isset($configResponse['authorization_endpoint']) ? $configResponse['authorization_endpoint'] : '';
        $this->configuration['token_endpoint'] = isset($configResponse['token_endpoint']) ? $configResponse['token_endpoint'] : '';
        $this->configuration['userinfo_endpoint'] = isset($configResponse['userinfo_endpoint']) ? $configResponse['userinfo_endpoint'] : '';
    }

    /**
     * @return string
     */
    public function getName(): string
    {
        return 'oidc';
    }

    /**
     * @return string
     */
    public function getLoginURL(): string
    {
        return $this->configuration['authorization_endpoint'].'?'. \http_build_query([
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
     * @return string
     */
    public function getTokens(string $code): array
    {
        if(empty($this->tokens)){
            $headers = ['Content-Type: application/x-www-form-urlencoded;charset=UTF-8'];
            $this->tokens = \json_decode($this->request(
                'POST',
                $this->configuration['token_endpoint'],
                $headers,
                \http_build_query([
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
     * @return string
     */
    public function refreshTokens(string $refreshToken): array
    {
        $headers = ['Content-Type: application/x-www-form-urlencoded;charset=UTF-8'];
        $this->tokens = \json_decode($this->request(
            'POST',
            $this->configuration['token_endpoint'],
            $headers,
            \http_build_query([
                'refresh_token' => $refreshToken,
                'client_id' => $this->appID,
                'client_secret' => $this->appSecret,
                'redirect_uri' => $this->callback,
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
            $headers = ['Authorization: Bearer '.$accessToken];
            $user = $this->request('GET', $this->configuration['userinfo_endpoint'],$headers);
            $this->user = \json_decode($user, true);
        }
        return $this->user;
    }
}

<?php

namespace Appwrite\Auth\OAuth2;

use Appwrite\Auth\OAuth2;

// Reference Material
// https://developer.box.com/reference/

class Box extends OAuth2
{
    /**
     * @var string
     */
    private $endpoint = 'https://account.box.com/api/oauth2/';

    /**
     * @var string
     */
    private $resourceEndpoint = 'https://api.box.com/2.0/';

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
        'manage_app_users',
    ];

    /**
     * @return string
     */
    public function getName(): string
    {
        return 'box';
    }

    /**
     * @return string
     */
    public function getLoginURL(): string
    {
        $url = $this->endpoint . 'authorize?'.
            \http_build_query([
                'response_type' => 'code',
                'client_id' => $this->appID,
                'scope' => \implode(',', $this->getScopes()),
                'redirect_uri' => $this->callback,
                'state' => \json_encode($this->state),
            ]);

        return $url;
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
                $this->endpoint . 'token',
                $headers,
                \http_build_query([
                    "client_id" => $this->appID,
                    "client_secret" => $this->appSecret,
                    "code" => $code,
                    "grant_type" => "authorization_code",
                    "scope" => \implode(',', $this->getScopes()),
                    "redirect_uri" => $this->callback
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
        $headers = ['Content-Type: application/x-www-form-urlencoded'];
        $this->tokens = \json_decode($this->request(
            'POST',
            $this->endpoint . 'token',
            $headers,
            \http_build_query([
                "client_id" => $this->appID,
                "client_secret" => $this->appSecret,
                "refresh_token" => $refreshToken,
                "grant_type" => "refresh_token",
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

        if (isset($user['login'])) {
            return $user['login'];
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
        $header = [
            'Authorization: Bearer '.\urlencode($accessToken),
        ];
        if (empty($this->user)) {
            $user = $this->request(
                'GET',
                $this->resourceEndpoint . 'me',
                $header
            );
            $this->user = \json_decode($user, true);
        }

        return $this->user;
    }
}

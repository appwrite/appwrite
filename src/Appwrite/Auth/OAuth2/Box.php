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
     * @return string
     */
    public function getAccessToken(string $code): string
    {
        $header = "Content-Type: application/x-www-form-urlencoded";
        $accessToken = $this->request(
            'POST',
            $this->endpoint . 'token',
            [$header],
            \http_build_query([
                "client_id" => $this->appID,
                "client_secret" => $this->appSecret,
                "code" => $code,
                "grant_type" => "authorization_code",
                "scope" =>  \implode(',', $this->getScopes()),
                "redirect_uri" => $this->callback
            ])
        );

        $accessToken = \json_decode($accessToken, true);

        if (array_key_exists('access_token', $accessToken)) {
            return $accessToken['access_token'];
        }

        return '';
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
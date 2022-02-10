<?php

namespace Appwrite\Auth\OAuth2;

use Appwrite\Auth\OAuth2;
use Utopia\Exception;

// Reference Material
// https://dev.bitly.com/v4_documentation.html

class Bitly extends OAuth2
{

    /**
     * @var string
     */
    private $endpoint = 'https://bitly.com/oauth/';

    /**
     * @var string
     */
    private $resourceEndpoint = 'https://api-ssl.bitly.com/';

    /**
     * @var array
     */
    protected $scopes = [];

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
    public function getName():string
    {
        return 'bitly';
    }

    /**
     * @return string
     */
    public function getLoginURL():string
    {
        return $this->endpoint . 'authorize?'.
            \http_build_query([
                'client_id' => $this->appID,
                'redirect_uri' => $this->callback,
                'state' => \json_encode($this->state)
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
                $this->resourceEndpoint . 'oauth/access_token',
                ["Content-Type: application/x-www-form-urlencoded"],
                \http_build_query([
                    "client_id" => $this->appID,
                    "client_secret" => $this->appSecret,
                    "code" => $code,
                    "redirect_uri" => $this->callback,
                    "state" => \json_encode($this->state)
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
            $this->resourceEndpoint . 'oauth/access_token',
            ["Content-Type: application/x-www-form-urlencoded"],
            \http_build_query([
                "client_id" => $this->appID,
                "client_secret" => $this->appSecret,
                "refresh_token" => $refreshToken,
                'grant_type' => 'refresh_token'
            ])
        ), true);

        if(empty($this->tokens['refresh_token'])) {
            $this->tokens['refresh_token'] = $refreshToken;
        }

        return $this->tokens;
    }

    /**
     * @param $accessToken
     *
     * @return string
     */
    public function getUserID(string $accessToken):string
    {
        $user = $this->getUser($accessToken);

        if (isset($user['login'])) {
            return $user['login'];
        }

        return '';
    }

    /**
     * @param $accessToken
     *
     * @return string
     */
    public function getUserEmail(string $accessToken):string
    {
        $user = $this->getUser($accessToken);

        if (isset($user['emails'])) {
            return $user['emails'][0]['email'];
        }

        return '';
    }

    /**
     * @param $accessToken
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
        $headers = [
            'Authorization: Bearer '. \urlencode($accessToken),
            "Accept: application/json"
        ];

        if (empty($this->user)) {
            $this->user = \json_decode($this->request('GET', $this->resourceEndpoint . "v4/user", $headers), true);
        }


        return $this->user;
    }
}

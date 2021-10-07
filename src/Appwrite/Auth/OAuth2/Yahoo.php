<?php

namespace Appwrite\Auth\OAuth2;

use Appwrite\Auth\OAuth2;

// Reference Material
// https://developer.yahoo.com/oauth2/guide/

class Yahoo extends OAuth2
{

    /**
     * @var string
     */
    private $endpoint = 'https://api.login.yahoo.com/oauth2/';

    /**
     * @var string
     */
    private $resourceEndpoint = 'https://api.login.yahoo.com/openid/v1/userinfo';

    /**
     * @var array
     */
    protected $scopes = [
        'sdct-r',
        'sdpp-w',
    ];

    /**
     * @var array
     */
    protected $user = [];

    /**
     * @return string
     */
    public function getName(): string
    {
        return 'yahoo';
    }

    /**
     * @param $state
     *
     * @return array
     */
    public function parseState(string $state)
    {
        return \json_decode(\html_entity_decode($state), true);
    }

    /**
     * @return string
     */
    public function getLoginURL(): string
    {
        return $this->endpoint . 'request_auth?' .
            \http_build_query([
                'response_type' => 'code',
                'client_id' => $this->appID,
                'scope' => \implode(' ', $this->getScopes()),
                'redirect_uri' => $this->callback,
                'state' => \json_encode($this->state)
            ]);
    }

    /**
     * @param string $code
     *
     * @return string
     */
    public function getAccessToken(string $code): string
    {
        $header = [
            "Authorization: Basic " . \base64_encode($this->appID . ":" . $this->appSecret),
            "Content-Type: application/x-www-form-urlencoded",
        ];

        $result = \json_decode($this->request(
            'POST',
            $this->endpoint . 'get_token',
            $header,
            \http_build_query([
                "code" => $code,
                "grant_type" => "authorization_code",
                "redirect_uri" => $this->callback
            ])
        ), true);

        if (isset($result['access_token'])) {
            return $result['access_token'];
        }

        return '';
    }

    /**
     * @param $accessToken
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
     * @param $accessToken
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
     * @param $accessToken
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
    protected function getUser(string $accessToken)
    {
        if (empty($this->user)) {
            $this->user = \json_decode($this->request(
                'GET',
                $this->resourceEndpoint,
                ['Authorization: Bearer ' . \urlencode($accessToken)]
            ), true);
        }

        return $this->user;
    }
}

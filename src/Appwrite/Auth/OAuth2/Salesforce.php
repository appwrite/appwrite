<?php

namespace Appwrite\Auth\OAuth2;

use Appwrite\Auth\OAuth2;

// Reference Material
// https://help.salesforce.com/articleView?id=remoteaccess_oauth_endpoints.htm&type=5
// https://help.salesforce.com/articleView?id=remoteaccess_oauth_tokens_scopes.htm&type=5
// https://help.salesforce.com/articleView?id=remoteaccess_oauth_web_server_flow.htm&type=5

class Salesforce extends OAuth2
{
    /**
     * @var array
     */
    protected $user = [];

    /**
     * @var array
     */
    protected $scopes = [
        "openid"
    ];

    /**
     * @return string
     */
    public function getName(): string
    {
        return 'Salesforce';
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
        return 'https://login.salesforce.com/services/oauth2/authorize?'.\http_build_query([
                'response_type' => 'code',
                'client_id' => $this->appID,
                'redirect_uri'=> $this->callback,
                'scope'=> \implode(' ', $this->getScopes()),
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
        $headers = [
            "Authorization: Basic " . \base64_encode($this->appID . ":" . $this->appSecret),
            "Content-Type: application/x-www-form-urlencoded",
        ];

        $accessToken = $this->request(
            'POST',
            'https://login.salesforce.com/services/oauth2/token',
            $headers,
            \http_build_query([
                'code' => $code,
                'redirect_uri' => $this->callback ,
                'grant_type' => 'authorization_code'
            ])
        );
        $accessToken = \json_decode($accessToken, true);

        if (isset($accessToken['access_token'])) {
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

        if (isset($user['user_id'])) {
            return $user['user_id'];
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
            $user = $this->request('GET', 'https://login.salesforce.com/services/oauth2/userinfo?access_token='.\urlencode($accessToken));
            $this->user = \json_decode($user, true);
        }
        return $this->user;
    }
}

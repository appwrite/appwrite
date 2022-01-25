<?php

namespace Appwrite\Auth\OAuth2;

use Appwrite\Auth\OAuth2;

// Reference Material

class WSO2 extends OAuth2
{
    /**
     * @var array
     */
    protected $scopes = [
        'openid'
    ];

    /**
     * @var array
     */
    protected $user = [];
    /**
     * @var string
    */
    protected $idToken  = '';
    /**
     * @return string
     */
    public function getName(): string
    {
        return 'wso2';
    }

    /**
     * @return string
     */

    public function getLoginURL(): string
    {
        return 'https://api.conta.stag.intelbras.com/auth/authorize?'. \http_build_query([
            'response_type' => 'code',
            'client_id' => $this->appID,
            'redirect_uri' => $this->callback,
            'scope' => \implode(' ', $this->getScopes()),
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
        $response = $this->request(
            'POST',
            'https://api.conta.stag.intelbras.com/auth/token',
            ['Content-Type: application/x-www-form-urlencoded'],
            \http_build_query([
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $this->callback,
                'client_id' => $this->appID,
                'client_secret' => $this->appSecret,
            ])
        );

        $accessToken = \json_decode($response, true);
        $idToken = \json_decode($response, true);

        if (isset($idToken['id_token'])) {
            $this->idToken = $idToken['id_token'];
        }

        if (isset($accessToken['access_token'])) {
            return $accessToken['access_token'];
        }

        return '';
    }
    /**
     * @param string $idToken
     *
     * @return string
     */
    public function getAccessIdToken(): string
    { 
        return $this->idToken;
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

        $name = '';

        if (isset($user['givenName'])) {
            $name = $user['givenName'];
        }

        if (isset($user['familyName'])) {
            $name = (empty($name)) ? $user['familyName'] : $name.' '.$user['familyName'];
        }

        return $name;
    }

    /**
     * @param string $accessToken
     *
     * @return array
     */
    protected function getUser(string $accessToken): array
    {
        if (empty($this->user)) {
            $this->user = \json_decode($this->request('GET', 'https://api.conta.stag.intelbras.com/me', ['Authorization: Bearer '.\urlencode($accessToken)]), true);
        }

        return $this->user;
    }
}

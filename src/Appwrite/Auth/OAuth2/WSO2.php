<?php

namespace Appwrite\Auth\OAuth2;

use Appwrite\Auth\OAuth2;

// Reference Material

class Wso2 extends OAuth2
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

        try {
            $params = \json_decode($this->appSecret, true);
        } catch (\Throwable $th) {
            throw new Exception('Invalid secret');
        }   

        $url = $params['clientUrl'].'/'.$params['clientAuthorizeEndPoint'];

        return $url.'?'. \http_build_query([
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

        try {
            $params = \json_decode($this->appSecret, true);
        } catch (\Throwable $th) {
            throw new Exception('Invalid secret');
        }

        $url = $params['clientUrl'].'/'.$params['clientTokenEndPoint'];
         
        $response = $this->request(
            'POST',
            $url,
            ['Content-Type: application/x-www-form-urlencoded'],
            \http_build_query([
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $this->callback,
                'client_id' => $this->appID,
                'client_secret' => $params['clientSecret'],
            ])
        );

        $data = \json_decode($response, true);

        if (isset($data['id_token'])) {
            $this->idToken = $data['id_token'];
        }

        if (isset($data['access_token'])) {
            return $data['access_token'];
        }

        return '';
    }

    /**
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

        try {
            $params = \json_decode($this->appSecret, true);
        } catch (\Throwable $th) {
            throw new Exception('Invalid secret');
        }

        $url = $params['clientUrl'].'/'.$params['clientMeEndPoint'];

        if (empty($this->user)) {
            $this->user = \json_decode($this->request('GET', $url, ['Authorization: Bearer '.\urlencode($accessToken)]), true);
        }

        return $this->user;
    }
}

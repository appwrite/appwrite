<?php

namespace Auth\OAuth;

use Auth\OAuth;

class Twitter extends OAuth
{
    /**
     * @var array
     */
    protected $user = [];

    /**
     * @var array
     */
    protected $scope = [
        'r_basicprofile',
        'r_emailaddress',
    ];

    /**
     * Documentation.
            * POST oauth/access_token
            * GET oauth/authenticate
            * GET oauth/authorize
            * POST oauth/request_token
            * POST oauth2/token
            * POST oauth2/invalidate_token
            * POST oauth/invalidate_token
     */
    /**
     * @return string
     */
    public function getName():string
    {
        return 'twitter';
    }

    /**
     * @return string
     */
    public function getLoginURL():string
    {
        return 'https://www.twitter.com/oauth/v2/authorization?'.http_build_query([
                'response_type' => 'code',
                'client_id' => $this->appID,
                'redirect_uri' => $this->callback,
                'scope' => implode(' ', $this->scope),
                'state' => json_encode($this->state),
            ]);
    }

    /**
     * @param string $code
     *
     * @return string
     */
    public function getAccessToken(string $code):string
    {
        $accessToken = $this->request(
            'POST',
            'https://developer.twitter.com/en/docs/basics/authentication/api-reference/token',
            ['Content-Type: application/x-www-form-urlencoded'],
            http_build_query([
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $this->callback,
                'client_id' => $this->appID,
                'client_secret' => $this->appSecret,
            ])
        );

        $accessToken = json_decode($accessToken, true);

        if (isset($accessToken['access_token'])) {
            return $accessToken['access_token'];
        }

        return '';
    }

    /**
     * @param $accessToken
     *
     * @return string
     */
    public function getUserID(string $accessToken):string
    {
        $user = $this->getUser($accessToken);

        if (isset($user['id'])) {
            return $user['id'];
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
        $email = json_decode($this->request('GET', '', ['Authorization: Bearer '.urlencode($accessToken)]), true);

        if (
            isset($email['elements']) &&
            isset($email['elements'][0]) &&
            isset($email['elements'][0]['handle~']) &&
            isset($email['elements'][0]['handle~']['emailAddress'])
        ) {
            return $email['elements'][0]['handle~']['emailAddress'];
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
        $name = '';

        if (isset($user['localizedFirstName'])) {
            $name = $user['localizedFirstName'];
        }

        if (isset($user['localizedLastName'])) {
            $name = (empty($name)) ? $user['localizedLastName'] : $name.' '.$user['localizedLastName'];
        }

        return $name;
    }

    /**
     * @param string $accessToken
     *
     * @return array
     */
    protected function getUser(string $accessToken)
    {
        if (empty($this->user)) {
            $this->user = json_decode($this->request('GET', '', ['Authorization: Bearer '.urlencode($accessToken)]), true);
        }

        return $this->user;
    }
}

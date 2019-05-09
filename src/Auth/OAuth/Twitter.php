<?php

namespace Auth\OAuth;

use Auth\OAuth;

/**
 * @package Auth\OAuth
 *
 * @see https://developers.google.com/+/web/api/rest/latest/people
 * @see https://github.com/thephpleague/oauth2-google/blob/master/src/Provider/Google.php
 */
class Twitter extends OAuth
{
    /**
     * @var array
     */
    protected $user = [];

    /**
     * @return string
     */
    public function getName():string
    {
        return 'google';
    }

    /**
     * Google OAuth scopes list:
     * @see https://developers.google.com/identity/protocols/googlescopes
     *
     * @return string
     */
    public function getLoginURL():string
    {
        return 'https://accounts.google.com/o/oauth2/v2/auth?client_id=' . urlencode($this->appID) . '&redirect_uri=' . urlencode($this->callback) . '&scope=' . urlencode('profile email') . '&response_type=code';
    }

    /**
     * @param string $code
     * @return string
     */
    public function getAccessToken(string $code):string
    {
        $accessToken = $this->request('POST', 'https://www.googleapis.com/oauth2/v4/token?' .
            'client_id=' . urlencode($this->appID) .
            '&redirect_uri=' . urlencode($this->callback) .
            '&client_secret=' . urlencode($this->appSecret) .
            '&code=' . urlencode($code) .
            '&grant_type=authorization_code'
        );

        $accessToken = json_decode($accessToken, true);

        if(isset($accessToken['access_token'])) {
            return $accessToken['access_token'];
        }

        return '';
    }

    /**
     * @param string $accessToken
     * @return string
     */
    public function getUserID(string $accessToken):string
    {
        $user = $this->getUser($accessToken);

        if(isset($user['id'])) {
            return $user['id'];
        }

        return '';
    }

    /**
     * @param string $accessToken
     * @return string
     */
    public function getUserEmail(string $accessToken):string
    {
        $user = $this->getUser($accessToken);

        if(isset($user['email'])) {
            return $user['email'];
        }

        return '';
    }

    /**
     * @param string $accessToken
     * @return string
     */
    public function getUserName(string $accessToken):string
    {
        $user = $this->getUser($accessToken);

        if(isset($user['name'])) {
            return $user['name'];
        }

        return '';
    }

    /**
     * @param string $accessToken
     * @return array
     */
    protected function getUser(string $accessToken):array
    {
        if(empty($this->user)) {
            $user = $this->request('GET', 'https://graph.facebook.com/' . $this->version . '/me?fields=email,name&access_token=' . urlencode($accessToken));

            $this->user = json_decode($user, true);
        }

        return $this->user;
    }
}
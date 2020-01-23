<?php

namespace Auth\OAuth;

use Auth\OAuth;

// Reference Material
// https://developer.twitter.com/en/docs/basics/authentication/guides/log-in-with-twitter
// Step 1: POST oauth/request_token
// Step 2: GET oauth/authorize
// Step 3: POST oauth/access_token
// Step 4: GET account/verify_credentials

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
        return 'twitter';
    }

    /**
     * @return string
     */
    public function getLoginURL():string
    {

    }

    /**
     * @param string $code
     *
     * @return string
     */
    public function getAccessToken(string $code):string
    {


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
    public function getUserName(string $accessToken):string
    {
        $user = $this->getUser($accessToken);

        if (isset($user['display_name'])) {
            return $user['display_name'];
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

        }

        return $this->user;
    }
}

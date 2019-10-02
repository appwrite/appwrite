<?php

namespace Auth\OAuth;

use Auth\OAuth;

class Slack extends OAuth
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
        return 'slack';
    }

    /**
     * @return string
     */
    public function getLoginURL():string
    {
        return 'https://slack.com/oauth/authorize'.
            '?client_id='.urlencode($this->appID).
            '&scope='.urlencode("identity.avatar,identity.basic,identity.email,identity.team").
            '&redirect_uri='.urlencode($this->callback);
    }

    /**
     * @param string $code
     *
     * @return string
     */
    public function getAccessToken(string $code):string
    {
        $accessToken = $this->request(
            'GET',
            'https://slack.com/api/oauth.access'.
            '?client_id='.urlencode($this->appID).
            '&client_secret='.urlencode($this->appSecret).
            '&redirect_uri='.urlencode($this->callback).
            '&code='.urlencode($code)
        );

        $accessToken = json_decode($accessToken, true); //

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
    public function getUserID(string $accessToken):string
    {
        $user = $this->getUser($accessToken);

        if (isset($user['user']['id'])) {
            return $user['user']['id'];
        }

        return '';
    }

    /**
     * @param string $accessToken
     *
     * @return string
     */
    public function getUserEmail(string $accessToken):string
    {
        $user = $this->getUser($accessToken);

        if (isset($user['user']['email'])) {
            return $user['user']['email'];
        }

        return '';
    }

    /**
     * @param string $accessToken
     *
     * @return string
     */
    public function getUserName(string $accessToken):string
    {
        $user = $this->getUser($accessToken);

        if (isset($user['user']['name'])) {
            return $user['user']['name'];
        }

        return '';
    }

    /**
     * @param string $accessToken
     *
     * @return array
     */
    protected function getUser(string $accessToken):array
    {
        if (empty($this->user)) {
            $user = $this->request(
                'GET',
                'https://slack.com/api/users.identity&token='.urlencode($accessToken));

            $this->user = json_decode($user, true);
        }

        return $this->user;
    }
}

//http://localhost:8080/v1/auth/oauth/slack?project=5d94eda5e2b8a&success=http://localhost:8080/?success=1&failure=http://localhost:8080/auth/signin?failure=2

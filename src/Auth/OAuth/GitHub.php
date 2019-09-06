<?php

namespace Auth\OAuth;

use Auth\OAuth;

class Github extends OAuth
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
        return 'github';
    }

    /**
     * @return string
     */
    public function getLoginURL():string
    {
        return 'https://github.com/login/oauth/authorize?client_id='.urlencode($this->appID).'&redirect_uri='.urlencode($this->callback).'&scope=user:email&state='.urlencode(json_encode($this->state));
    }

    /**
     * @param string $code
     *
     * @return string
     */
    public function getAccessToken(string $code):string
    {
        $accessToken = $this->request('POST', 'https://github.com/login/oauth/access_token', [],
            'client_id='.urlencode($this->appID).
            '&redirect_uri='.urlencode($this->callback).
            '&client_secret='.urlencode($this->appSecret).
            '&code='.urlencode($code)
        );

        $output = [];

        parse_str($accessToken, $output);

        if (isset($output['access_token'])) {
            return $output['access_token'];
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
        $emails = json_decode($this->request('GET', 'https://api.github.com/user/emails', ['Authorization: token '.urlencode($accessToken)]), true);

        foreach ($emails as $email) {
            if ($email['primary'] && $email['verified']) {
                return $email['email'];
            }
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
        if (empty($this->user)) {
            $this->user = json_decode($this->request('GET', 'https://api.github.com/user', ['Authorization: token '.urlencode($accessToken)]), true);
        }

        return $this->user;
    }
}

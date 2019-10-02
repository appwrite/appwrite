<?php

namespace Auth\OAuth;

use Auth\OAuth;

class Gitlab extends OAuth
{
    /**
     * @var array
     */
    protected $user = [];

    /**
     * @return string
     */
    public function getName(): string
    {
        return 'gitlab';
    }

    /**
     * @return string
     */
    public function getLoginURL(): string
    {
        return 'https://gitlab.com/oauth/authorize?'.
            'client_id='.urlencode($this->appID).
            '&redirect_uri='.urlencode($this->callback).
            '&scope=read_user'.
            '&state='.urlencode(json_encode($this->state)).
            '&response_type=code';
    }

    /**
     * @param string $code
     *
     * @return string
     */
    public function getAccessToken(string $code): string
    {
        $accessToken = $this->request(
            'POST',
            'https://gitlab.com/oauth/token?'.
                'code='.urlencode($code).
                '&client_id='.urlencode($this->appID).
                '&client_secret='.urlencode($this->appSecret).
                '&redirect_uri='.urlencode($this->callback).
                '&grant_type=authorization_code'
        );

        $accessToken = json_decode($accessToken, true);

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
            $user = $this->request('GET', 'https://gitlab.com/api/v4/user?access_token='.urlencode($accessToken));
            $this->user = json_decode($user, true);
        }

        return $this->user;
    }
}

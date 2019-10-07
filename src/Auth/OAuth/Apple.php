<?php

namespace Auth\OAuth;

use Auth\OAuth;

// Reference Material
// https://developer.okta.com/blog/2019/06/04/what-the-heck-is-sign-in-with-apple

class Apple extends OAuth
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
        return 'apple';
    }
    
    /**
     * @return string
     */
    public function getLoginURL(): string
    {
        return 'https://appleid.apple.com/auth/authorize?'.
            'client_id='.urlencode($this->appID).
            '&redirect_uri='.urlencode($this->callback).
            '&state='.urlencode(json_encode($this->state)).
            '&response_type=code'.
            '&response_mode=form_post'.
            '&scope=name+email';
    }

    /**
     * @param string $code
     *
     * @return string
     */
    public function getAccessToken(string $code): string
    {
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        $accessToken = $this->request(
            'POST',
            'https://appleid.apple.com/auth/token',
            $headers,
            'code='.urlencode($code).
            '&client_id='.urlencode($this->appID).
            '&client_secret='.urlencode($this->appSecret).
            '&redirect_uri='.urlencode($this->callback).
            '&grant_type=authorization_code'
        );

        var_dump($accessToken);
        exit();

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

        if (isset($user['account_id'])) {
            return $user['account_id'];
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
            return $user['name']['display_name'];
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
            $headers[] = 'Authorization: Bearer '. urlencode($accessToken);
            $user = $this->request('POST', '', $headers);
            $this->user = json_decode($user, true);
        }

        return $this->user;
    }
}

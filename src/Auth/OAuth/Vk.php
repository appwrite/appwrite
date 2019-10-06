<?php

namespace Auth\OAuth;

use Auth\OAuth;

// Reference Material
// https://vk.com/dev/first_guide
// https://vk.com/dev/auth_sites
// https://vk.com/dev/api_requests
// https://plugins.miniorange.com/guide-to-configure-vkontakte-as-oauth-server

class Vk extends OAuth
{
    /**
     * @var array
     */
    protected $user = [];

    /**
     * @var string
     */
    protected $version = '5.101';


    /**
     * @return string
     */
    public function getName(): string
    {
        return 'vk';
    }

    /**
     * @return string
     */
    public function getLoginURL(): string
    {
        return 'https://oauth.vk.com/authorize?' .
            'client_id='.urlencode($this->appID).
            '&redirect_uri='.urlencode($this->callback).
            '&response_type=code'.
            '&state='.urlencode(json_encode($this->state)). 
            '&v='.urlencode($this->version).
            '&scope=openid+email';
    }

    /**
     * @param string $code
     *
     * @return string
     */
    public function getAccessToken(string $code): string
    {

        $headers[] = 'Content-Type: application/x-www-form-urlencoded;charset=UTF-8';
        $accessToken = $this->request(
            'POST',
            'https://oauth.vk.com/access_token?',
            $headers,
            'code=' . urlencode($code) .
            '&client_id=' . urlencode($this->appID) .
            '&client_secret=' . urlencode($this->appSecret).
            '&redirect_uri='.urlencode($this->callback)
        );
        $accessToken = json_decode($accessToken, true);

        if(isset($accessToken['email'])){
            $this->user['email'] = $accessToken['email'];
        }

        if(isset($accessToken['user_id'])){
            $this->user['user_id'] = $accessToken['user_id'];
        }

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
        if (empty($this->user['name'])) {
            $user = $this->request(
                'GET', 
                'https://api.vk.com/method/users.get?'.
                'v='.urlencode($this->version).
                '&fields=id,name,email,first_name,last_name'.
                '&access_token='.urlencode($accessToken)
            );
            
            $user = json_decode($user, true);
            $this->user['name'] = $user['response'][0]['first_name'] ." ".$user['response'][0]['last_name'];
            
        }
        return $this->user;
    }
}

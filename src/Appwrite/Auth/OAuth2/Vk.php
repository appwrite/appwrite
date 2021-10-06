<?php

namespace Appwrite\Auth\OAuth2;

use Appwrite\Auth\OAuth2;

// Reference Material
// https://vk.com/dev/first_guide
// https://vk.com/dev/auth_sites
// https://vk.com/dev/api_requests
// https://plugins.miniorange.com/guide-to-configure-vkontakte-as-oauth-server

class Vk extends OAuth2
{
    /**
     * @var array
     */
    protected $user = [];

    /**
     * @var array
     */
    protected $scopes = [
        'openid',
        'email'
    ];

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
        return 'https://oauth.vk.com/authorize?' . \http_build_query([
            'client_id' => $this->appID,
            'redirect_uri' => $this->callback,
            'response_type' => 'code',
            'state' => \json_encode($this->state),
            'v' => $this->version,
            'scope' => \implode(' ', $this->getScopes())
        ]);
    }

    /**
     * @param string $code
     *
     * @return string
     */
    public function getAccessToken(string $code): string
    {
        $headers = ['Content-Type: application/x-www-form-urlencoded;charset=UTF-8'];
        $accessToken = $this->request(
            'POST',
            'https://oauth.vk.com/access_token?',
            $headers,
            \http_build_query([
                'code' => $code,
                'client_id' => $this->appID,
                'client_secret' => $this->appSecret,
                'redirect_uri' => $this->callback
            ])
        );
        $accessToken = \json_decode($accessToken, true);

        if (isset($accessToken['email'])) {
            $this->user['email'] = $accessToken['email'];
        }

        if (isset($accessToken['user_id'])) {
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
                'https://api.vk.com/method/users.get?' . \http_build_query([
                    'v' => $this->version,
                    'fields' => 'id,name,email,first_name,last_name',
                    'access_token' => $accessToken
                ])
            );

            $user = \json_decode($user, true);
            $this->user['name'] = $user['response'][0]['first_name'] . " " . $user['response'][0]['last_name'];
        }
        return $this->user;
    }
}

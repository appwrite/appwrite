<?php

namespace Appwrite\Auth\OAuth2;

use Appwrite\Auth\OAuth2;

// Reference Material
// https://developer.yammer.com/docs/oauth-2

class Yammer extends OAuth2
{
    /**
     * @var string
     */
    private $endpoint = 'https://www.yammer.com/oauth2/';

    /**
     * @var array
     */
    protected $user = [];

    /**
     * @return string
     */
    public function getName(): string
    {
        return 'yammer';
    }

    /**
     * @return string
     */
    public function getLoginURL(): string
    {
        return $this->endpoint . 'oauth2/authorize?'.
        \http_build_query([
            'client_id' => $this->appID,
            'response_type' => 'code',
            'redirect_uri' => $this->callback,
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
        $headers = ['Content-Type: application/x-www-form-urlencoded'];

        $accessToken = $this->request(
            'POST',
            $this->endpoint . 'access_token?',
            $headers,
            \http_build_query([
                'client_id' => $this->appID,
                'client_secret' => $this->appSecret,
                'code' => $code,
                'grant_type' => 'authorization_code'
            ])
        );

        $accessToken = \json_decode($accessToken, true);

        if (isset($accessToken['access_token']['token'])) {
            return $accessToken['access_token']['token'];
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

        if (isset($user['full_name'])) {
            return $user['full_name'];
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
            $headers = ['Authorization: Bearer '. \urlencode($accessToken)];
            $user = $this->request('GET', 'https://www.yammer.com/api/v1/users/current.json', $headers);
            $this->user = \json_decode($user, true);
        }

        return $this->user;
    }
}

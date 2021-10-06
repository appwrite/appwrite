<?php

namespace Appwrite\Auth\OAuth2;

use Appwrite\Auth\OAuth2;

// Reference Material
// https://developer.wordpress.com/docs/wpcc/

class WordPress extends OAuth2
{
    /**
     * @var array
     */
    protected $user = [];

    /**
     * @var array
     */
    protected $scopes = [
        'auth',
    ];

    /**
     * @return string
     */
    public function getName(): string
    {
        return 'wordpress';
    }

    /**
     * @return string
     */
    public function getLoginURL(): string
    {
        return 'https://public-api.wordpress.com/oauth2/authorize?' . \http_build_query([
            'client_id' => $this->appID,
            'redirect_uri' => $this->callback,
            'response_type' => 'code',
            'scope' => $this->getScopes(),
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
        $accessToken = $this->request(
            'POST',
            'https://public-api.wordpress.com/oauth2/token',
            [],
            \http_build_query([
                'client_id' => $this->appID,
                'redirect_uri' => $this->callback,
                'client_secret' => $this->appSecret,
                'grant_type' => 'authorization_code',
                'code' => $code
            ])
        );

        $accessToken = \json_decode($accessToken, true);

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
    public function getUserID(string $accessToken): string
    {
        $user = $this->getUser($accessToken);

        if (isset($user['ID'])) {
            return $user['ID'];
        }

        return '';
    }

    /**
     * @param $accessToken
     *
     * @return string
     */
    public function getUserEmail(string $accessToken): string
    {
        $user = $this->getUser($accessToken);

        if (isset($user['email']) && $user['verified']) {
            return $user['email'];
        }

        return '';
    }

    /**
     * @param $accessToken
     *
     * @return string
     */
    public function getUserName(string $accessToken): string
    {
        $user = $this->getUser($accessToken);

        if (isset($user['username'])) {
            return $user['username'];
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
            $this->user = \json_decode($this->request('GET', 'https://public-api.wordpress.com/rest/v1/me', ['Authorization: Bearer ' . $accessToken]), true);
        }

        return $this->user;
    }
}

<?php

namespace Appwrite\Auth\OAuth2;

use Appwrite\Auth\OAuth2;

class Facebook extends OAuth2
{
    /**
     * @var string
     */
    protected $version = 'v2.8';

    /**
     * @var array
     */
    protected $user = [];

    /**
     * @var array
     */
    protected $scopes = [
        'email'
    ];

    /**
     * @return string
     */
    public function getName(): string
    {
        return 'facebook';
    }

    /**
     * @return string
     */
    public function getLoginURL(): string
    {
        return 'https://www.facebook.com/' . $this->version . '/dialog/oauth?' . \http_build_query([
            'client_id' => $this->appID,
            'redirect_uri' => $this->callback,
            'scope' => \implode(' ', $this->getScopes()),
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
            'GET',
            'https://graph.facebook.com/' . $this->version . '/oauth/access_token?' . \http_build_query([
                'client_id' => $this->appID,
                'redirect_uri' => $this->callback,
                'client_secret' => $this->appSecret,
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
            $user = $this->request('GET', 'https://graph.facebook.com/' . $this->version . '/me?fields=email,name&access_token=' . \urlencode($accessToken));

            $this->user = \json_decode($user, true);
        }

        return $this->user;
    }
}

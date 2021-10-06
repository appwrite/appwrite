<?php

namespace Appwrite\Auth\OAuth2;

use Appwrite\Auth\OAuth2;

// Reference Material
// https://developers.google.com/oauthplayground/
// https://developers.google.com/identity/protocols/OAuth2
// https://developers.google.com/identity/protocols/OAuth2WebServer

class Google extends OAuth2
{
    /**
     * @var string
     */
    protected $version = 'v4';

    /**
     * @var array
     */
    protected $scopes = [
        'https://www.googleapis.com/auth/userinfo.email',
        'https://www.googleapis.com/auth/userinfo.profile',
        'openid'
    ];

    /**
     * @var array
     */
    protected $user = [];

    /**
     * @return string
     */
    public function getName(): string
    {
        return 'google';
    }

    /**
     * @return string
     */
    public function getLoginURL(): string
    {
        return 'https://accounts.google.com/o/oauth2/v2/auth?' . \http_build_query([
            'client_id' => $this->appID,
            'redirect_uri' => $this->callback,
            'scope' => \implode(' ', $this->getScopes()),
            'state' => \json_encode($this->state),
            'response_type' => 'code'
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
            'https://oauth2.googleapis.com/token?' . \http_build_query([
                'code' => $code,
                'client_id' => $this->appID,
                'client_secret' => $this->appSecret,
                'redirect_uri' => $this->callback,
                'scope' => null,
                'grant_type' => 'authorization_code'
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
            $user = $this->request('GET', 'https://www.googleapis.com/oauth2/v2/userinfo?access_token=' . \urlencode($accessToken));
            $this->user = \json_decode($user, true);
        }

        return $this->user;
    }
}

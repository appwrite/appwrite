<?php

namespace Appwrite\Auth\OAuth2;

use Appwrite\Auth\OAuth2;

// Reference Material
// https://discordapp.com/developers/docs/topics/oauth2

class Discord extends OAuth2
{
    /**
     * @var string
     */
    private $endpoint = 'https://discordapp.com/api';

    /**
     * @var array
     */
    protected $user = [];

    /**
     * @var array
     */
    protected $scopes = [
        'identify',
        'email'
    ];

    /**
     * @return string
     */
    public function getName(): string
    {
        return 'discord';
    }

    /**
     * @return string
     */
    public function getLoginURL(): string
    {
        $url = $this->endpoint . '/oauth2/authorize?' .
            \http_build_query([
                'response_type' => 'code',
                'client_id' => $this->appID,
                'state' => \json_encode($this->state),
                'scope' => \implode(' ', $this->getScopes()),
                'redirect_uri' => $this->callback
            ]);

        return $url;
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
            $this->endpoint . '/oauth2/token',
            ['Content-Type: application/x-www-form-urlencoded'],
            \http_build_query([
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $this->callback,
                'client_id' => $this->appID,
                'client_secret' => $this->appSecret,
                'scope' => \implode(' ', $this->getScopes())
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
    protected function getUser(string $accessToken): array
    {
        if (empty($this->user)) {
            $user = $this->request(
                'GET',
                $this->endpoint . '/users/@me',
                ['Authorization: Bearer ' . \urlencode($accessToken)]
            );
            $this->user = \json_decode($user, true);
        }

        return $this->user;
    }
}

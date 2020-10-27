<?php

namespace Appwrite\Auth\OAuth2;

use Appwrite\Auth\OAuth2;

// Reference Material
// https://tech.yandex.com/passport/doc/dg/reference/request-docpage/
// https://tech.yandex.com/oauth/doc/dg/reference/web-client-docpage/


class Yandex extends OAuth2
{
    /**
     * @var array
     */
    protected $user = [];

    /**
     * @var array
     */
    protected $scopes = [];

    /**
     * @return string
     */
    public function getName(): string
    {
        return 'Yandex';
    }

    /**
     * @param $state
     *
     * @return array
     */
    public function parseState(string $state)
    {
        return \json_decode(\html_entity_decode($state), true);
    }


    /**
     * @return string
     */
    public function getLoginURL(): string
    {
        return 'https://oauth.yandex.com/authorize?'.\http_build_query([
                'response_type' => 'code',
                'client_id' => $this->appID,
                'scope'=> \implode(' ', $this->getScopes()),
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
        $headers = [
            "Authorization: Basic " . \base64_encode($this->appID . ":" . $this->appSecret),
            "Content-Type: application/x-www-form-urlencoded",
        ];

        $accessToken = $this->request(
            'POST',
            'https://oauth.yandex.com/token',
            $headers,
            \http_build_query([
                'code' => $code,
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

        if (isset($user['default_email'])) {
            return $user['default_email'];
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

        if (isset($user['display_name'])) {
            return $user['display_name'];
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
            $user = $this->request('GET', 'https://login.yandex.ru/info?'.\http_build_query([
                'format' => 'json',
                'oauth_token' => $accessToken
            ]));
            $this->user = \json_decode($user, true);
        }
        return $this->user;
    }
}

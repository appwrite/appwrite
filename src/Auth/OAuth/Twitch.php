<?php

namespace Auth\OAuth;

use Auth\OAuth;

// Reference Material
// https://dev.twitch.tv/docs/authentication

class Twitch extends OAuth
{

    /**
     * @var string
     */
    private $endpoint = 'https://id.twitch.tv';

    /**
     * @var array
     */
    protected $scope = [
            'user:read:email',
    ];

    /**
     * @var array
     */
    protected $user = [];

    /**
     * @return string
     */
    public function getName():string
    {
        return 'twitch';
    }

    /**
     * @return string
     */
    public function getLoginURL():string
    {
        return $this->endpoint . '/oauth2/authorize?'.
            http_build_query([
                'response_type' => 'code',
                'client_id' => $this->appID,
                'scope' => implode(' ', $this->scope),
                'redirect_uri' => $this->callback,
                'state' => $this->state,
            ]);
    }

    /**
     * @param string $code
     *
     * @return string
     */
    public function getAccessToken(string $code):string
    {
        $accessToken = $this->request(
            'POST',
             $this->endpoint . '/oauth2/token?',
            [],
            http_build_query([
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $this->callback,
                'client_id' => $this->appID,
                'client_secret' => $this->appSecret,
            ])
        );

        $accessToken = json_decode($accessToken, true);

        if (isset($output['access_token'])) {
            return $output['access_token'];
        }

        return '';
    }

    /**
     * @param $accessToken
     *
     * @return string
     */
    public function getUserID(string $accessToken):string
    {
        $user = $this->getUser($accessToken);

        if (isset($user['id'])) {
            return $user['id'];
        }

        return '';
    }

    /**
     * @param $accessToken
     *
     * @return string
     */
    public function getUserEmail(string $accessToken):string
    {
        $emails = json_decode($this->request('GET', $this->endpoint . '/userinfo', ['Authorization: Bearer '.urlencode($accessToken)]), true);

        foreach ($emails as $email) {
            if ($email['primary'] && $email['verified']) {
                return $email['email'];
            }
        }

        return '';
    }

    /**
     * @param $accessToken
     *
     * @return string
     */
    public function getUserName(string $accessToken):string
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
    protected function getUser(string $accessToken)
    {
        if (empty($this->user)) {
            $this->user = json_decode($this->request('GET', $this->endpoint . '/userinfo', ['Authorization: Bearer '.urlencode($accessToken)]), true);
        }

        return $this->user;
    }
}

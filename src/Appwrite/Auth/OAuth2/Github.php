<?php

namespace Appwrite\Auth\OAuth2;

use Appwrite\Auth\OAuth2;

class Github extends OAuth2
{
    /**
     * @var array
     */
    protected $user = [];

    /**
     * @var array
     */
    protected $scopes = [
        'user:email',
    ];

    /**
     * @return string
     */
    public function getName(): string
    {
        return 'github';
    }

    /**
     * @return string
     */
    public function getLoginURL(): string
    {
        return 'https://github.com/login/oauth/authorize?' . \http_build_query([
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
            'POST',
            'https://github.com/login/oauth/access_token',
            [],
            \http_build_query([
                'client_id' => $this->appID,
                'redirect_uri' => $this->callback,
                'client_secret' => $this->appSecret,
                'code' => $code
            ])
        );

        $output = [];

        \parse_str($accessToken, $output);

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
    public function getUserID(string $accessToken): string
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
    public function getUserEmail(string $accessToken): string
    {
        $emails = \json_decode($this->request('GET', 'https://api.github.com/user/emails', ['Authorization: token ' . \urlencode($accessToken)]), true);

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
    protected function getUser(string $accessToken)
    {
        if (empty($this->user)) {
            $this->user = \json_decode($this->request('GET', 'https://api.github.com/user', ['Authorization: token ' . \urlencode($accessToken)]), true);
        }

        return $this->user;
    }
}

<?php

namespace Appwrite\Auth\OAuth2;

use Appwrite\Auth\OAuth2;
use Utopia\Exception;

class Slack extends OAuth2
{
    /**
     * @var array
     */
    protected $user = [];
    
    /**
     * @var array
     */
    protected $tokens = [];

    /**
     * @var array
     */
    protected $scopes = [
        'identity.avatar',
        'identity.basic',
        'identity.email',
        'identity.team'
    ];

    /**
     * @return string
     */
    public function getName():string
    {
        return 'slack';
    }

    /**
     * @return string
     */
    public function getLoginURL():string
    {
        // https://api.slack.com/docs/oauth#step_1_-_sending_users_to_authorize_and_or_install
        return 'https://slack.com/oauth/authorize?'.\http_build_query([
            'client_id'=> $this->appID,
            'scope' => \implode(' ', $this->getScopes()),
            'redirect_uri' => $this->callback,
            'state' => \json_encode($this->state)
        ]);
    }

    /**
     * @param string $code
     *
     * @return array
     */
    protected function getTokens(string $code): array
    {
        if(empty($this->tokens)) {
            // https://api.slack.com/docs/oauth#step_3_-_exchanging_a_verification_code_for_an_access_token
            $this->tokens = \json_decode($this->request(
                'GET',
                'https://slack.com/api/oauth.access?' . \http_build_query([
                    'client_id' => $this->appID,
                    'client_secret' => $this->appSecret,
                    'code' => $code,
                    'redirect_uri' => $this->callback
                ])
            ), true);
        }

        return $this->tokens;
    }

    /**
     * @param string $refreshToken
     *
     * @return array
     */
    public function refreshTokens(string $refreshToken):array
    {
        $this->tokens = \json_decode($this->request(
            'GET',
            'https://slack.com/api/oauth.access?' . \http_build_query([
                'client_id' => $this->appID,
                'client_secret' => $this->appSecret,
                'refresh_token' => $refreshToken,
                'grant_type' => 'refresh_token'
            ])
        ), true);

        if(empty($this->tokens['refresh_token'])) {
            $this->tokens['refresh_token'] = $refreshToken;
        }

        return $this->tokens;
    }

    /**
     * @param string $accessToken
     *
     * @return string
     */
    public function getUserID(string $accessToken):string
    {
        $user = $this->getUser($accessToken);

        if (isset($user['user']['id'])) {
            return $user['user']['id'];
        }

        return '';
    }

    /**
     * @param string $accessToken
     *
     * @return string
     */
    public function getUserEmail(string $accessToken):string
    {
        $user = $this->getUser($accessToken);

        if (isset($user['user']['email'])) {
            return $user['user']['email'];
        }

        return '';
    }

    /**
     * @param string $accessToken
     *
     * @return string
     */
    public function getUserName(string $accessToken):string
    {
        $user = $this->getUser($accessToken);

        if (isset($user['user']['name'])) {
            return $user['user']['name'];
        }

        return '';
    }

    /**
     * @param string $accessToken
     *
     * @return array
     */
    protected function getUser(string $accessToken):array
    {
        if (empty($this->user)) {
            // https://api.slack.com/methods/users.identity
            $user = $this->request(
                'GET',
                'https://slack.com/api/users.identity?token='.\urlencode($accessToken)
            );

            $this->user = \json_decode($user, true);
        }

        return $this->user;
    }
}

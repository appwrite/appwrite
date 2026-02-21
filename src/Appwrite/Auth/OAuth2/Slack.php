<?php

namespace Appwrite\Auth\OAuth2;

use Appwrite\Auth\OAuth2;

class Slack extends OAuth2
{
    /**
     * @var array
     */
    protected array $user = [];

    /**
     * @var array
     */
    protected array $tokens = [];

    /**
     * @var array
     */
    protected array $scopes = [
        'openid',
        'email',
        'profile'
    ];

    /**
     * @return string
     */
    public function getName(): string
    {
        return 'slack';
    }

    /**
     * @link https://api.slack.com/authentication/oauth-v2
     *
     * @return string
     */
    public function getLoginURL(): string
    {
        return 'https://slack.com/oauth/v2/authorize?' . \http_build_query([
            'client_id' => $this->appID,
            'user_scope' => \implode(' ', $this->getScopes()),
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
        if (empty($this->tokens)) {
            $this->tokens = \json_decode($this->request(
                'GET',
                'https://slack.com/api/oauth.v2.access?' . \http_build_query([
                    'client_id' => $this->appID,
                    'client_secret' => $this->appSecret,
                    'code' => $code,
                    'redirect_uri' => $this->callback
                ])
            ), true)['authed_user'] ?? [];
        }

        return $this->tokens;
    }

    /**
     * @param string $refreshToken
     *
     * @return array
     */
    public function refreshTokens(string $refreshToken): array
    {
        $this->tokens = \json_decode($this->request(
            'GET',
            'https://slack.com/api/oauth.v2.access?' . \http_build_query([
                'client_id' => $this->appID,
                'client_secret' => $this->appSecret,
                'refresh_token' => $refreshToken,
                'grant_type' => 'refresh_token'
            ])
        ), true)['authed_user'] ?? [];

        if (empty($this->tokens['refresh_token'])) {
            $this->tokens['refresh_token'] = $refreshToken;
        }

        return $this->tokens;
    }

    /**
     * @param string $accessToken
     *
     * @return string
     */
    public function getUserID(string $accessToken): string
    {
        $user = $this->getUser($accessToken);

        return $user['user']['id'] ?? '';
    }

    /**
     * @param string $accessToken
     *
     * @return string
     */
    public function getUserEmail(string $accessToken): string
    {
        $user = $this->getUser($accessToken);

        return $user['user']['email'] ?? '';
    }

    /**
     * Check if the OAuth email is verified
     *
     * If present, the email is verified. This was verfied through a manual Slack sign up process
     *
     * @link https://slack.com/help/articles/207262907-Change-your-email-address
     *
     * @param string $accessToken
     *
     * @return bool
     */
    public function isEmailVerified(string $accessToken): bool
    {
        $email = $this->getUserEmail($accessToken);

        return !empty($email);
    }

    /**
     * @param string $accessToken
     *
     * @return string
     */
    public function getUserName(string $accessToken): string
    {
        $user = $this->getUser($accessToken);

        return $user['user']['name'] ?? '';
    }

    /**
     * @link https://api.slack.com/methods/users.identity
     *
     * @param string $accessToken
     *
     * @return array
     */
    protected function getUser(string $accessToken): array
    {
        if (empty($this->user)) {
            $user = $this->request(
                'GET',
                'https://slack.com/api/users.identity',
                ['Authorization: Bearer ' . \urlencode($accessToken)]
            );
            $this->user = \json_decode($user, true);
        }

        return $this->user;
    }
}

<?php

namespace Appwrite\Auth\OAuth2;

use Appwrite\Auth\OAuth2;

// Reference Material
// https://github.com/reddit-archive/reddit/wiki/OAuth2
// https://www.reddit.com/dev/api/#GET_api_v1_me

class Reddit extends OAuth2
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
        'identity',
    ];

    /**
     * @return string
     */
    public function getName(): string
    {
        return 'reddit';
    }

    /**
     * @return string
     */
    public function getLoginURL(): string
    {
        return 'https://www.reddit.com/api/v1/authorize?' . \http_build_query([
            'response_type' => 'code',
            'client_id' => $this->appID,
            'redirect_uri' => $this->callback,
            'duration' => 'permanent',
            'scope' => \implode(' ', $this->getScopes()),
            'state' => \json_encode($this->state),
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
                'POST',
                'https://www.reddit.com/api/v1/access_token',
                [
                    'Authorization: Basic ' . \base64_encode($this->appID . ':' . $this->appSecret),
                    'Content-Type: application/x-www-form-urlencoded',
                    $this->getUserAgent(),
                ],
                \http_build_query([
                    'grant_type' => 'authorization_code',
                    'redirect_uri' => $this->callback,
                    'code' => $code,
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
    public function refreshTokens(string $refreshToken): array
    {
        $this->tokens = \json_decode($this->request(
            'POST',
            'https://www.reddit.com/api/v1/access_token',
            [
                'Authorization: Basic ' . \base64_encode($this->appID . ':' . $this->appSecret),
                'Content-Type: application/x-www-form-urlencoded',
                $this->getUserAgent(),
            ],
            \http_build_query([
                'grant_type' => 'refresh_token',
                'refresh_token' => $refreshToken,
            ])
        ), true);

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

        return $user['id'] ?? '';
    }

    /**
     * Reddit does not share the account email with OAuth2 apps.
     *
     * @param string $accessToken
     *
     * @return string
     */
    public function getUserEmail(string $accessToken): string
    {
        return '';
    }

    /**
     * @param string $accessToken
     *
     * @return bool
     */
    public function isEmailVerified(string $accessToken): bool
    {
        return false;
    }

    /**
     * @param string $accessToken
     *
     * @return string
     */
    public function getUserName(string $accessToken): string
    {
        $user = $this->getUser($accessToken);

        return $user['name'] ?? '';
    }

    /**
     * @param string $accessToken
     *
     * @return string
     */
    public function getUserPhoto(string $accessToken): string
    {
        $user = $this->getUser($accessToken);

        // Reddit HTML-escapes the query string of avatar URLs
        return \html_entity_decode($user['icon_img'] ?? '');
    }

    /**
     * @param string $accessToken
     *
     * @return array
     */
    protected function getUser(string $accessToken): array
    {
        if (empty($this->user)) {
            $this->user = \json_decode($this->request(
                'GET',
                'https://oauth.reddit.com/api/v1/me',
                [
                    'Authorization: Bearer ' . \urlencode($accessToken),
                    $this->getUserAgent(),
                ]
            ), true) ?? [];
        }

        return $this->user;
    }

    /**
     * Reddit throttles generic user agents and asks for one unique to the app.
     *
     * @link https://github.com/reddit-archive/reddit/wiki/API#rules
     *
     * @return string
     */
    private function getUserAgent(): string
    {
        return 'User-Agent: web:' . $this->appID . ':1.0 (by Appwrite)';
    }
}

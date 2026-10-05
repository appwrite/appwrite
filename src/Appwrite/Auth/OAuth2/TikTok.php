<?php

namespace Appwrite\Auth\OAuth2;

use Appwrite\Auth\OAuth2;

// Reference Material
// https://developers.tiktok.com/doc/login-kit-web
// https://developers.tiktok.com/doc/oauth-user-access-token-management
// https://developers.tiktok.com/doc/tiktok-api-v2-get-user-info

class TikTok extends OAuth2
{
    /**
     * @var string
     */
    private string $endpoint = 'https://open.tiktokapis.com/v2/';

    /**
     * Fields the user info endpoint returns for the `user.info.basic` scope.
     * TikTok requires them to be requested explicitly.
     *
     * @var array
     */
    private const USER_FIELDS = [
        'open_id',
        'union_id',
        'display_name',
        'avatar_url',
    ];

    /**
     * @var array
     */
    protected array $user = [];

    /**
     * @var array
     */
    protected array $tokens = [];

    /**
     * TikTok grants no email scope; `user.info.basic` is the narrowest scope
     * that returns an identity.
     *
     * @var array
     */
    protected array $scopes = [
        'user.info.basic',
    ];

    /**
     * @return string
     */
    public function getName(): string
    {
        return 'tiktok';
    }

    /**
     * @return string
     */
    public function getLoginURL(): string
    {
        return 'https://www.tiktok.com/v2/auth/authorize/?' . \http_build_query([
            'client_key' => $this->appID,
            'response_type' => 'code',
            'scope' => \implode(',', $this->getScopes()),
            'redirect_uri' => $this->callback,
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
            $this->tokens = $this->parseTokens($this->request(
                'POST',
                $this->endpoint . 'oauth/token/',
                [
                    'Content-Type: application/x-www-form-urlencoded',
                    'Cache-Control: no-cache',
                ],
                \http_build_query([
                    'client_key' => $this->appID,
                    'client_secret' => $this->appSecret,
                    'code' => $code,
                    'grant_type' => 'authorization_code',
                    'redirect_uri' => $this->callback,
                ])
            ));
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
        $this->tokens = $this->parseTokens($this->request(
            'POST',
            $this->endpoint . 'oauth/token/',
            [
                'Content-Type: application/x-www-form-urlencoded',
                'Cache-Control: no-cache',
            ],
            \http_build_query([
                'client_key' => $this->appID,
                'client_secret' => $this->appSecret,
                'grant_type' => 'refresh_token',
                'refresh_token' => $refreshToken,
            ])
        ));

        if (empty($this->tokens['refresh_token'])) {
            $this->tokens['refresh_token'] = $refreshToken;
        }

        return $this->tokens;
    }

    /**
     * TikTok answers a rejected token request with an `error` field rather
     * than only an HTTP error status, so the base request() error handling
     * cannot be relied on alone.
     *
     * @param string $response
     *
     * @return array
     */
    private function parseTokens(string $response): array
    {
        $tokens = \json_decode($response, true);

        if (!\is_array($tokens) || isset($tokens['error'])) {
            throw new Exception($response, 400);
        }

        return $tokens;
    }

    /**
     * @param string $accessToken
     *
     * @return string
     */
    public function getUserID(string $accessToken): string
    {
        $user = $this->getUser($accessToken);

        return isset($user['open_id']) ? (string)$user['open_id'] : '';
    }

    /**
     * TikTok does not expose the account email through Login Kit.
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
     * Check if the OAuth email is verified
     *
     * TikTok does not expose an email, so there is nothing to verify.
     *
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

        return $user['display_name'] ?? '';
    }

    /**
     * @param string $accessToken
     *
     * @return string
     */
    public function getUserPhoto(string $accessToken): string
    {
        $user = $this->getUser($accessToken);

        return $user['avatar_url'] ?? '';
    }

    /**
     * @param string $accessToken
     *
     * @return array
     */
    protected function getUser(string $accessToken): array
    {
        if (empty($this->user)) {
            $response = \json_decode($this->request(
                'GET',
                $this->endpoint . 'user/info/?' . \http_build_query([
                    'fields' => \implode(',', self::USER_FIELDS),
                ]),
                ['Authorization: Bearer ' . $accessToken]
            ), true);

            if (!\is_array($response['data']['user'] ?? null)) {
                throw new Exception('TikTok did not return valid user information.', 400);
            }

            $this->user = $response['data']['user'];
        }

        return $this->user;
    }
}

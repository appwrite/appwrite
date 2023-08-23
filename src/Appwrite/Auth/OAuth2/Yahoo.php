<?php

namespace Appwrite\Auth\OAuth2;

use Appwrite\Auth\OAuth2;

// Reference Material
// https://developer.yahoo.com/oauth2/guide/

class Yahoo extends OAuth2
{
    /**
     * @var string
     */
    private string $endpoint = 'https://api.login.yahoo.com/oauth2/';

    /**
     * @var string
     */
    private string $resourceEndpoint = 'https://api.login.yahoo.com/openid/v1/userinfo';

    /**
     * @var array
     */
    protected array $scopes = [
        'sdct-r',
        'sdpp-w',
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
     * @return string
     */
    public function getName(): string
    {
        return 'yahoo';
    }

    /**
     * @param $state
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
        return $this->endpoint.'request_auth?'.
            \http_build_query([
                'response_type' => 'code',
                'client_id' => $this->appID,
                'scope' => \implode(' ', $this->getScopes()),
                'redirect_uri' => $this->callback,
                'state' => \json_encode($this->state),
            ]);
    }

    /**
     * @param  string  $code
     * @return array
     */
    protected function getTokens(string $code): array
    {
        if (empty($this->tokens)) {
            $headers = [
                'Authorization: Basic '.\base64_encode($this->appID.':'.$this->appSecret),
                'Content-Type: application/x-www-form-urlencoded',
            ];

            $this->tokens = \json_decode($this->request(
                'POST',
                $this->endpoint.'get_token',
                $headers,
                \http_build_query([
                    'code' => $code,
                    'grant_type' => 'authorization_code',
                    'redirect_uri' => $this->callback,
                ])
            ), true);
        }

        return $this->tokens;
    }

    /**
     * @param  string  $refreshToken
     * @return array
     */
    public function refreshTokens(string $refreshToken): array
    {
        $headers = [
            'Authorization: Basic '.\base64_encode($this->appID.':'.$this->appSecret),
            'Content-Type: application/x-www-form-urlencoded',
        ];

        $this->tokens = \json_decode($this->request(
            'POST',
            $this->endpoint.'get_token',
            $headers,
            \http_build_query([
                'refresh_token' => $refreshToken,
                'grant_type' => 'refresh_token',
            ])
        ), true);

        if (empty($this->tokens['refresh_token'])) {
            $this->tokens['refresh_token'] = $refreshToken;
        }

        return $this->tokens;
    }

    /**
     * @param  string  $accessToken
     * @return string
     */
    public function getUserID(string $accessToken): string
    {
        $user = $this->getUser($accessToken);

        return $user['sub'] ?? '';
    }

    /**
     * @param  string  $accessToken
     * @return string
     */
    public function getUserEmail(string $accessToken): string
    {
        $user = $this->getUser($accessToken);

        return $user['email'] ?? '';
    }

    /**
     * Check if the OAuth email is verified
     *
     * If present, the email is verified. This was verfied through a manual Yahoo sign up process
     *
     * @param  string  $accessToken
     * @return bool
     */
    public function isEmailVerified(string $accessToken): bool
    {
        $email = $this->getUserEmail($accessToken);

        return ! empty($email);
    }

    /**
     * @param  string  $accessToken
     * @return string
     */
    public function getUserName(string $accessToken): string
    {
        $user = $this->getUser($accessToken);

        return $user['name'] ?? '';
    }

    /**
     * @param  string  $accessToken
     * @return array
     */
    protected function getUser(string $accessToken)
    {
        if (empty($this->user)) {
            $this->user = \json_decode($this->request(
                'GET',
                $this->resourceEndpoint,
                ['Authorization: Bearer '.\urlencode($accessToken)]
            ), true);
        }

        return $this->user;
    }
}

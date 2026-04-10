<?php

namespace Appwrite\Auth\OAuth2;

use Appwrite\Auth\OAuth2;

class Gitea extends OAuth2
{
    /**
     * @var string
     */
    protected string $endpoint;

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
    protected array $scopes = [];

    /**
     * @param string $appId
     * @param string $appSecret
     * @param string $callback
     * @param array $state
     * @param array $scopes
     * @param string $endpoint Base URL of the Gitea instance (e.g. https://gitea.example.com)
     */
    public function __construct(string $appId, string $appSecret, string $callback, array $state = [], array $scopes = [], string $endpoint = '')
    {
        $this->endpoint = rtrim($endpoint, '/');
        parent::__construct($appId, $appSecret, $callback, $state, $scopes);
    }

    /**
     * @return string
     */
    public function getName(): string
    {
        return 'gitea';
    }

    /**
     * @return string
     */
    public function getLoginURL(): string
    {
        return $this->endpoint . '/login/oauth/authorize?' . \http_build_query([
            'client_id' => $this->appID,
            'redirect_uri' => $this->callback,
            'response_type' => 'code',
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
            $response = $this->request(
                'POST',
                $this->endpoint . '/login/oauth/access_token',
                ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'],
                \http_build_query([
                    'client_id' => $this->appID,
                    'client_secret' => $this->appSecret,
                    'redirect_uri' => $this->callback,
                    'grant_type' => 'authorization_code',
                    'code' => $code,
                ])
            );

            $this->tokens = \json_decode($response, true) ?? [];
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
        $response = $this->request(
            'POST',
            $this->endpoint . '/login/oauth/access_token',
            ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'],
            \http_build_query([
                'client_id' => $this->appID,
                'client_secret' => $this->appSecret,
                'grant_type' => 'refresh_token',
                'refresh_token' => $refreshToken,
            ])
        );

        $this->tokens = \json_decode($response, true) ?? [];

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

        return \strval($user['id'] ?? '');
    }

    /**
     * @param string $accessToken
     *
     * @return string
     */
    public function getUserEmail(string $accessToken): string
    {
        $user = $this->getUser($accessToken);

        return $user['email'] ?? '';
    }

    /**
     * @param string $accessToken
     *
     * @return bool
     */
    public function isEmailVerified(string $accessToken): bool
    {
        return true;
    }

    /**
     * @param string $accessToken
     *
     * @return string
     */
    public function getUserName(string $accessToken): string
    {
        $user = $this->getUser($accessToken);

        return $user['full_name'] ?? $user['login'] ?? '';
    }

    /**
     * @param string $accessToken
     *
     * @return string
     */
    public function getUserSlug(string $accessToken): string
    {
        $user = $this->getUser($accessToken);

        return $user['login'] ?? '';
    }

    /**
     * @param string $accessToken
     *
     * @return array
     */
    protected function getUser(string $accessToken): array
    {
        if (empty($this->user)) {
            $response = $this->request('GET', $this->endpoint . '/api/v1/user', ['Authorization: token ' . \urlencode($accessToken)]);
            $this->user = \json_decode($response, true) ?? [];
        }

        return $this->user;
    }

    /**
     * @param string $accessToken
     * @param string $repositoryName
     * @param bool $private
     *
     * @return array
     */
    public function createRepository(string $accessToken, string $repositoryName, bool $private): array
    {
        $response = $this->request(
            'POST',
            $this->endpoint . '/api/v1/user/repos',
            ['Authorization: token ' . \urlencode($accessToken), 'Content-Type: application/json'],
            \json_encode([
                'name' => $repositoryName,
                'private' => $private,
            ])
        );

        return \json_decode($response, true) ?? [];
    }
}

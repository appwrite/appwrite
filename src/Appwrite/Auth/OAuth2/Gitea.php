<?php

namespace Appwrite\Auth\OAuth2;

use Appwrite\Auth\OAuth2;

// Reference Material
// https://docs.gitea.com/development/oauth2-provider

class Gitea extends OAuth2
{
    /**
     * @var string
     */
    protected string $endpoint = '';

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
        'read:user',
    ];

    /**
     * Set the URL of the Gitea instance (self-hosted).
     */
    public function setEndpoint(string $endpoint): void
    {
        $this->endpoint = \rtrim($endpoint, '/');
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
            'scope' => \implode(' ', $this->getScopes()),
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
            $response = $this->request(
                'POST',
                $this->endpoint . '/login/oauth/access_token',
                ['Content-Type: application/json'],
                \json_encode([
                    'client_id' => $this->appID,
                    'client_secret' => $this->appSecret,
                    'grant_type' => 'authorization_code',
                    'redirect_uri' => $this->callback,
                    'code' => $code
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
            ['Content-Type: application/json'],
            \json_encode([
                'client_id' => $this->appID,
                'client_secret' => $this->appSecret,
                'grant_type' => 'refresh_token',
                'refresh_token' => $refreshToken
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
        $user = $this->getUser($accessToken);

        return $user['verified'] ?? false;
    }

    /**
     * @param string $accessToken
     *
     * @return string
     */
    public function getUserName(string $accessToken): string
    {
        $user = $this->getUser($accessToken);

        return $user['full_name'] ?? '';
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
    protected function getUser(string $accessToken)
    {
        if (empty($this->user)) {
            $this->user = \json_decode($this->request('GET', $this->endpoint . '/api/v1/user', ['Authorization: token ' . \urlencode($accessToken)]), true) ?? [];

            // Gitea reports the primary email's verification separately
            $emails = \json_decode($this->request('GET', $this->endpoint . '/api/v1/user/emails', ['Authorization: token ' . \urlencode($accessToken)]), true);

            foreach (\is_array($emails) ? $emails : [] as $email) {
                if (($email['primary'] ?? false) === true) {
                    $this->user['email'] = $email['email'] ?? ($this->user['email'] ?? '');
                    $this->user['verified'] = $email['verified'] ?? false;
                    break;
                }
            }
        }

        return $this->user;
    }

    public function createRepository(string $accessToken, string $repositoryName, bool $private): array
    {
        $repository = $this->request('POST', $this->endpoint . '/api/v1/user/repos', ['Authorization: token ' . \urlencode($accessToken), 'Content-Type: application/json'], \json_encode([
            'name' => $repositoryName,
            'private' => $private
        ]));

        return \json_decode($repository, true) ?? [];
    }
}

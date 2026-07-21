<?php

namespace Appwrite\Auth\OAuth2;

use Appwrite\Auth\OAuth2;
use Appwrite\Vcs\EnvOAuth2;
use Utopia\System\System;

// Reference Material
// https://docs.gitlab.com/ee/api/oauth2.html

class Gitlab extends OAuth2 implements EnvOAuth2
{
    /**
     * Only official gitlab.com is supported -- fixed, not configurable.
     */
    protected const ENDPOINT = 'https://gitlab.com';

    public static function fromEnv(): OAuth2&EnvOAuth2
    {
        return new self(System::getEnv('_APP_VCS_GITLAB_CLIENT_ID', ''), System::getEnv('_APP_VCS_GITLAB_CLIENT_SECRET', ''), '');
    }

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
        'read_user'
    ];

    /**
     * @return string
     */
    public function getName(): string
    {
        return 'gitlab';
    }

    /**
     * @return string
     */
    public function getLoginURL(): string
    {
        return $this->getEndpoint() . '/oauth/authorize?' . \http_build_query([
            'client_id' => $this->appID,
            'redirect_uri' => $this->callback,
            'scope' => \implode(' ', $this->getScopes()),
            'state' => \json_encode($this->state),
            'response_type' => 'code'
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
                $this->getEndpoint() . '/oauth/token?' . \http_build_query([
                    'code' => $code,
                    'client_id' => $this->appID,
                    'client_secret' => $this->appSecret,
                    'redirect_uri' => $this->callback,
                    'grant_type' => 'authorization_code'
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
            $this->getEndpoint() . '/oauth/token?' . \http_build_query([
                'refresh_token' => $refreshToken,
                'client_id' => $this->appID,
                'client_secret' => $this->appSecret,
                'grant_type' => 'refresh_token'
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

        return $user['email'] ?? '';
    }

    /**
     * Check if the OAuth email is verified
     *
     * @link https://docs.gitlab.com/ee/api/users.html#list-current-user-for-normal-users
     *
     * @param string $accessToken
     *
     * @return bool
     */
    public function isEmailVerified(string $accessToken): bool
    {
        $user = $this->getUser($accessToken);

        if ($user['confirmed_at'] ?? false) {
            return true;
        }

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
    public function getUserSlug(string $accessToken): string
    {
        $user = $this->getUser($accessToken);

        return $user['username'] ?? '';
    }

    /**
     * @link https://docs.gitlab.com/ee/api/projects.html#create-project
     *
     * @param string $accessToken
     * @param string $repositoryName
     * @param bool $private
     *
     * @return array
     */
    public function createRepository(string $accessToken, string $repositoryName, bool $private): array
    {
        $repository = $this->request('POST', $this->getEndpoint() . '/api/v4/projects', ['Authorization: Bearer ' . $accessToken, 'Content-Type: application/json'], \json_encode([
            'name' => $repositoryName,
            'visibility' => $private ? 'private' : 'public',
        ]));

        $repository = \json_decode($repository, true) ?? [];

        // Normalize to the GitHub/Gitea field shape ProviderRepository expects.
        if (isset($repository['visibility'])) {
            $repository['private'] = $repository['visibility'] !== 'public';
        }

        if (isset($repository['last_activity_at'])) {
            $repository['pushed_at'] = $repository['last_activity_at'];
        }

        if (isset($repository['message']) && !\is_string($repository['message'])) {
            $repository['message'] = \json_encode($repository['message']);
        }

        return $repository;
    }

    /**
     * @param string $accessToken
     *
     * @return array
     */
    protected function getUser(string $accessToken): array
    {
        if (empty($this->user)) {
            $user = $this->request('GET', $this->getEndpoint() . '/api/v4/user?access_token=' . \urlencode($accessToken));
            $this->user = \json_decode($user, true);
        }

        return $this->user;
    }

    protected function getEndpoint(): string
    {
        return self::ENDPOINT;
    }
}

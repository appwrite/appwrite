<?php

namespace Appwrite\Auth\OAuth2;

use Appwrite\Auth\OAuth2;
use Utopia\Http\Exception;

class Mock extends OAuth2
{
    /**
     * @var string
     */
    protected string $version = 'v1';

    /**
     * @var array
     */
    protected array $scopes = [
        'email'
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
        return 'mock';
    }

    /**
     * @return string
     */
    public function getLoginURL(): string
    {
        return 'http://localhost/' . $this->version . '/mock/tests/general/oauth2?' . \http_build_query([
            'client_id' => $this->appID,
            'redirect_uri' => $this->callback,
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
            $this->tokens = \json_decode($this->request(
                'GET',
                'http://localhost/' . $this->version . '/mock/tests/general/oauth2/token?' .
                    \http_build_query([
                        'client_id' => $this->appID,
                        'redirect_uri' => $this->callback,
                        'client_secret' => $this->appSecret,
                        'code' => $code
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
            'GET',
            'http://localhost/' . $this->version . '/mock/tests/general/oauth2/token?' .
                \http_build_query([
                    'client_id' => $this->appID,
                    'client_secret' => $this->appSecret,
                    'refresh_token' => $refreshToken,
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

        return $user['id'] ?? '';
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

        return $user['name'] ?? '';
    }

    /**
     * @param string $accessToken
     *
     * @return array
     */
    protected function getUser(string $accessToken): array
    {
        if (empty($this->user)) {
            $user = $this->request('GET', 'http://localhost/' . $this->version . '/mock/tests/general/oauth2/user?token=' . \urlencode($accessToken));

            $this->user = \json_decode($user, true);
        }

        return $this->user;
    }
}

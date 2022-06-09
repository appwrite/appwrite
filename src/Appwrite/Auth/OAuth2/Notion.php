<?php

namespace Appwrite\Auth\OAuth2;

use Appwrite\Auth\OAuth2;

class Notion extends OAuth2
{
    /**
     * @var string
     */
    private string $endpoint = 'https://api.notion.com/v1';

    /**
     * @var string
     */
    private string $version = '2021-08-16';

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
     * @return string
     */
    public function getName(): string
    {
        return 'notion';
    }

    /**
     * @return string
     */
    public function getLoginURL(): string
    {
        return $this->endpoint . '/oauth/authorize?' . \http_build_query([
            'client_id' => $this->appID,
            'redirect_uri' => $this->callback,
            'response_type' => 'code',
            'state' => \json_encode($this->state),
            'owner' => 'user'
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
            $headers = ['Authorization: Basic ' . \base64_encode($this->appID . ':' . $this->appSecret)];
            $this->tokens = \json_decode($this->request(
                'POST',
                $this->endpoint . '/oauth/token',
                $headers,
                \http_build_query([
                    'grant_type' => 'authorization_code',
                    'redirect_uri' => $this->callback,
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
        $headers = ['Authorization: Basic ' . \base64_encode($this->appID . ':' . $this->appSecret)];
        $this->tokens = \json_decode($this->request(
            'POST',
            $this->endpoint . '/oauth/token',
            $headers,
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
        $response = $this->getUser($accessToken);

        return $response['bot']['owner']['user']['id'] ?? '';
    }

    /**
     * @param string $accessToken
     *
     * @return string
     */
    public function getUserEmail(string $accessToken): string
    {
        $response = $this->getUser($accessToken);

        return $response['bot']['owner']['user']['person']['email'] ?? '';
    }

    /**
     * Check if the OAuth email is verified
     *
     * If present, the email is verified. This was verfied through a manual Notion sign up process
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
        $response = $this->getUser($accessToken);

        return $response['bot']['owner']['user']['name'] ?? '';
    }

    /**
     * @param string $accessToken
     *
     * @return array
     */
    protected function getUser(string $accessToken): array
    {
        $headers = [
            'Notion-Version: ' . $this->version,
            'Authorization: Bearer ' . \urlencode($accessToken)
        ];

        if (empty($this->user)) {
            $this->user = \json_decode($this->request('GET', $this->endpoint . '/users/me', $headers), true);
        }

        return $this->user;
    }
}

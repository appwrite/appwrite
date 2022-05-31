<?php

namespace Appwrite\Auth\OAuth2;

use Appwrite\Auth\OAuth2;

class Zoom extends OAuth2
{
    /**
     * @var string
     */
    private string $endpoint = 'https://zoom.us';

    /**
     * @var string
     */
    private string $version = '2022-03-26';

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
        'user_info:read'
    ];

    /**
     * @return string
     */
    public function getName(): string
    {
        return 'zoom';
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
            $headers = ['Authorization: Basic ' . \base64_encode($this->appID . ':' . $this->appSecret), 'Content-Type: application/x-www-form-urlencoded'];
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
        $headers = ['Authorization: Basic ' . \base64_encode($this->appID . ':' . $this->appSecret), 'Content-Type: application/x-www-form-urlencoded'];
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

        return $response['id'] ?? '';
    }

    /**
     * @param string $accessToken
     *
     * @return string
     */
    public function getUserEmail(string $accessToken): string
    {
        $response = $this->getUser($accessToken);

        return $response['email'] ?? '';
    }

    /**
     * Check if the OAuth email is verified
     *
     * @link https://marketplace.zoom.us/docs/api-reference/zoom-api/methods/#operation/user
     *
     * @param string $accessToken
     *
     * @return bool
     */
    public function isEmailVerified(string $accessToken): bool
    {
        $user = $this->getUser($accessToken);

        if (($user['verified'] ?? false) === 1) {
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
        $response = $this->getUser($accessToken);

        return ($response['first_name'] ?? '') . ' ' . ($response['last_name'] ?? '');
    }

    /**
     * @param string $accessToken
     *
     * @return array
     */
    protected function getUser(string $accessToken)
    {
        $headers = [
            'Authorization: Bearer ' . \urlencode($accessToken)
        ];

        if (empty($this->user)) {
            $this->user = \json_decode($this->request('GET', 'https://api.zoom.us/v2/users/me', $headers), true);
        }

        return $this->user;
    }
}

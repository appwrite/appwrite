<?php

namespace Appwrite\Auth\OAuth2;

use Appwrite\Auth\OAuth2;

class Autodesk extends OAuth2
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
        'user-profile:read',
    ];

    /**
     * @return string
     */
    public function getName(): string
    {
        return 'autodesk';
    }

    /**
     * @return string
     */
    public function getLoginURL(): string
    {
        return 'https://developer.api.autodesk.com/authentication/v1/authorize?' . \http_build_query([
            'client_id' => $this->appID,
            'scope' => \implode(' ', $this->getScopes()),
            'state' => \json_encode($this->state),
            'redirect_uri' => $this->callback,
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
            $headers = ['Content-Type: application/x-www-form-urlencoded'];
            $response = $this->request(
                'POST',
                'https://developer.api.autodesk.com/authentication/v1/gettoken',
                $headers,
                \http_build_query([
                    'client_id' => $this->appID,
                    'redirect_uri' => $this->callback,
                    'client_secret' => $this->appSecret,
                    'code' => $code,
                    'grant_type' => 'authorization_code'
                ])
            );

            $this->tokens = \json_decode($response, true);
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
            'https://developer.api.autodesk.com/authentication/v1/refreshtoken',
            [],
            \http_build_query([
                'client_id' => $this->appID,
                'client_secret' => $this->appSecret,
                'grant_type' => 'refresh_token',
                'code' => $code,
                'redirect_uri' => $this->callback,
            ])
        );

        $output = [];
        \parse_str($response, $output);
        $this->tokens = $output;

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

        return $user['userId'] ?? '';
    }

    /**
     * @param string $accessToken
     *
     * @return string
     */
    public function getUserEmail(string $accessToken): string
    {
        $user = $this->getUser($accessToken);

        return $user['emailId'] ?? '';
    }

    /**
     * Check if the OAuth email is verified
     *
     * @link https://docs.github.com/en/rest/users/emails#list-email-addresses-for-the-authenticated-user
     *
     * @param string $accessToken
     *
     * @return bool
     */
    public function isEmailVerified(string $accessToken): bool
    {
        $user = $this->getUser($accessToken);

        if ($user['emailVerified'] ?? false) {
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

        return $user['userName'] ?? '';
    }

    /**
     * @param string $accessToken
     *
     * @return array
     */
    protected function getUser(string $accessToken): array
    {
        if (empty($this->user)) {
            $headers = ['Authorization: Bearer ' . \urlencode($accessToken)];
            $user = $this->request('GET', 'https://developer.api.autodesk.com/userprofile/v1/users/@me', $headers);
            $this->user = \json_decode($user, true);
        }

        return $this->user;
    }
}

<?php

namespace Appwrite\Auth\OAuth2;

use Appwrite\Auth\OAuth2;

// Reference Material
// https://dev.twitch.tv/docs/authentication

class Spotify extends OAuth2
{
    /**
     * @var string
     */
    private string $endpoint = 'https://accounts.spotify.com/';

    /**
     * @var string
     */
    private string $resourceEndpoint = 'https://api.spotify.com/v1/';

    /**
     * @var array
     */
    protected array $scopes = [
        'user-read-email',
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
        return 'spotify';
    }

    /**
     * @return string
     */
    public function getLoginURL(): string
    {
        return $this->endpoint . 'authorize?' .
            \http_build_query([
                'response_type' => 'code',
                'client_id' => $this->appID,
                'scope' => \implode(' ', $this->getScopes()),
                'redirect_uri' => $this->callback,
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
            $headers = ['Authorization: Basic ' . \base64_encode($this->appID . ':' . $this->appSecret)];
            $this->tokens = \json_decode($this->request(
                'POST',
                $this->endpoint . 'api/token',
                $headers,
                \http_build_query([
                    "code" => $code,
                    "grant_type" => "authorization_code",
                    "redirect_uri" => $this->callback
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
            $this->endpoint . 'api/token',
            $headers,
            \http_build_query([
                "refresh_token" => $refreshToken,
                "grant_type" => "refresh_token",
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
     * Spotify does not assure that the email is verified
     *
     * @link https://developer.spotify.com/documentation/web-api/reference/#/operations/get-current-users-profile
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
     * @return array
     */
    protected function getUser(string $accessToken)
    {
        if (empty($this->user)) {
            $this->user = \json_decode($this->request(
                'GET',
                $this->resourceEndpoint . 'me',
                ['Authorization: Bearer ' . \urlencode($accessToken)]
            ), true);
        }

        return $this->user;
    }
}

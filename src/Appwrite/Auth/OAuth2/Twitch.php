<?php

namespace Appwrite\Auth\OAuth2;

use Appwrite\Auth\OAuth2;

// Reference Material
// https://dev.twitch.tv/docs/authentication

class Twitch extends OAuth2
{
    /**
     * @var string
     */
    private string $endpoint = 'https://id.twitch.tv/oauth2/';

    /**
     * @var string
     */
    private string $resourceEndpoint = 'https://api.twitch.tv/helix/users';

    /**
     * @var array
     */
    protected array $scopes = [
        'user:read:email',
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
        return 'twitch';
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
                'force_verify' => true,
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
                'POST',
                $this->endpoint . 'token?' . \http_build_query([
                    "client_id" => $this->appID,
                    "client_secret" => $this->appSecret,
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
        $this->tokens = \json_decode($this->request(
            'POST',
            $this->endpoint . 'token?' . \http_build_query([
                "client_id" => $this->appID,
                "client_secret" => $this->appSecret,
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
     * If present, the email is verified
     *
     * @link https://dev.twitch.tv/docs/api/reference#get-users
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
            $response = \json_decode($this->request(
                'GET',
                $this->resourceEndpoint,
                [
                    'Authorization: Bearer ' . \urlencode($accessToken),
                    'Client-Id: ' . \urlencode($this->appID)
                ]
            ), true);

            $this->user = $response['data']['0'] ?? [];
        }

        return $this->user;
    }
}

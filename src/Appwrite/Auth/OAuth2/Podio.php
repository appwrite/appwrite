<?php

namespace Appwrite\Auth\OAuth2;

use Appwrite\Auth\OAuth2;

// Reference Material
// https://developers.podio.com/doc/oauth-authorization

class Podio extends OAuth2
{
    /**
     * Endpoint used for initiating OAuth flow
     *
     * @var string
     */
    private string $endpoint = 'https://podio.com/oauth';

    /**
     * Endpoint for communication with API server
     *
     * @var string
     */
    private string $apiEndpoint = 'https://api.podio.com';

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
    protected array $scopes = []; // No scopes required

    /**
     * @return string
     */
    public function getName(): string
    {
        return 'podio';
    }

    /**
     * @return string
     */
    public function getLoginURL(): string
    {
        $url = $this->endpoint.'/authorize?'.
            \http_build_query([
                'client_id' => $this->appID,
                'state' => \json_encode($this->state),
                'redirect_uri' => $this->callback,
            ]);

        return $url;
    }

    /**
     * @param  string  $code
     * @return array
     */
    protected function getTokens(string $code): array
    {
        if (empty($this->tokens)) {
            $this->tokens = \json_decode($this->request(
                'POST',
                $this->apiEndpoint.'/oauth/token',
                ['Content-Type: application/x-www-form-urlencoded'],
                \http_build_query([
                    'grant_type' => 'authorization_code',
                    'code' => $code,
                    'redirect_uri' => $this->callback,
                    'client_id' => $this->appID,
                    'client_secret' => $this->appSecret,
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
        $this->tokens = \json_decode($this->request(
            'POST',
            $this->apiEndpoint.'/oauth/token',
            ['Content-Type: application/x-www-form-urlencoded'],
            \http_build_query([
                'grant_type' => 'refresh_token',
                'refresh_token' => $refreshToken,
                'client_id' => $this->appID,
                'client_secret' => $this->appSecret,
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

        return \strval($user['user_id']) ?? '';
    }

    /**
     * @param  string  $accessToken
     * @return string
     */
    public function getUserEmail(string $accessToken): string
    {
        $user = $this->getUser($accessToken);

        return $user['mail'] ?? '';
    }

    /**
     * Check if the OAuth email is verified
     *
     * @param  string  $accessToken
     * @return bool
     */
    public function isEmailVerified(string $accessToken): bool
    {
        $user = $this->getUser($accessToken);

        $mails = $user['mails'];
        $mainMailIndex = \array_search($user['mail'], \array_map(fn ($m) => $m['mail'], $mails));
        $mainMain = $mails[$mainMailIndex];

        if ($mainMain['verified'] ?? false) {
            return true;
        }

        return false;
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
    protected function getUser(string $accessToken): array
    {
        if (empty($this->user)) {
            $user = \json_decode($this->request(
                'GET',
                $this->apiEndpoint.'/user',
                ['Authorization: Bearer '.\urlencode($accessToken)]
            ), true);

            $profile = \json_decode($this->request(
                'GET',
                $this->apiEndpoint.'/user/profile',
                ['Authorization: Bearer '.\urlencode($accessToken)]
            ), true);

            $this->user = $user;
            $this->user['name'] = $profile['name'];
        }

        return $this->user;
    }
}

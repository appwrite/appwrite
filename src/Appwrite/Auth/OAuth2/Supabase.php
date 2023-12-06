<?php

namespace Appwrite\Auth\OAuth2;

use Appwrite\Auth\OAuth2;

// Reference Material
// https://supabase.com/docs/guides/platform/oauth-apps/build-a-supabase-integration

class Supabase extends OAuth2
{
    /**
     * @var string
     */
    private string $endpoint = 'https://api.supabase.com/v1';

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
        return 'supabase';
    }

    /**
     * @return string
     */
    public function getLoginURL(): string
    {
        $url = $this->endpoint . '/oauth/authorize?' .
            \http_build_query([
                'response_type' => 'code',
                'client_id' => $this->appID,
                'state' => \json_encode($this->state),
                'redirect_uri' => $this->callback
            ]);

        return $url;
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
                $this->endpoint . '/oauth/token',
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
     * @param string $refreshToken
     *
     * @return array
     */
    public function refreshTokens(string $refreshToken): array
    {
        $this->tokens = \json_decode($this->request(
            'POST',
            $this->endpoint . '/oauth/token',
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
     * @link https://discord.com/developers/docs/resources/user
     *
     * @param string $accessToken
     *
     * @return bool
     */
    public function isEmailVerified(string $accessToken): bool
    {
        $user = $this->getUser($accessToken);

        if ($user['verified'] ?? false) {
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

        return $user['username'] ?? '';
    }

    /**
     * @param string $accessToken
     *
     * @return array
     */
    protected function getUser(string $accessToken): array
    {
        if (empty($this->user)) {
            $user = $this->request(
                'GET',
                $this->endpoint . '/organizations',
                ['Authorization: Bearer ' . \urlencode($accessToken)]
            );
            \var_dump($user);
            $this->user = \json_decode($user, true);
            $this->user = [
                'username' => $this->user[0]['name'],
                'verified' => false,
                'email' => $this->user[0]['name'] . '@supabase.internal',
                'id' => $this->user[0]['id']
            ];
        }

        return $this->user;
    }
}

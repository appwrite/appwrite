<?php

namespace Appwrite\Auth\OAuth2;

use Appwrite\Auth\OAuth2;

// Reference Material
// https://zoho.com/accounts/protocol/oauth.html

class Zoho extends OAuth2
{
    /**
     * @var string
     */
    private string $endpoint = 'https://accounts.zoho.com';

    /**
     * @var array
     */
    protected array $scopes = [
        'email',
        'profile',
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
        return 'zoho';
    }

    /**
     * @return string
     */
    public function getLoginURL(): string
    {
        $url = $this->endpoint . '/oauth/v2/auth?' .
            \http_build_query([
                'response_type' => 'code',
                'client_id' => $this->appID,
                'state' => \json_encode($this->state),
                'redirect_uri' => $this->callback,
                'scope' => \implode(' ', $this->getScopes())
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
                $this->endpoint . '/oauth/v2/token',
                ["Content-Type: application/x-www-form-urlencoded"],
                \http_build_query([
                    'grant_type' => 'authorization_code',
                    "client_id" => $this->appID,
                    "client_secret" => $this->appSecret,
                    "redirect_uri" => $this->callback,
                    'code' => $code,
                    'scope' => \implode(' ', $this->getScopes()),
                ])
            ), true);
            $this->user = (isset($this->tokens['id_token'])) ? \explode('.', $this->tokens['id_token']) : [0 => '', 1 => ''];
            $this->user = (isset($this->user[1])) ? \json_decode(\base64_decode($this->user[1]), true) : [];
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
            $this->endpoint . '/oauth/v2/token',
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

        $this->user = (isset($this->tokens['id_token'])) ? \explode('.', $this->tokens['id_token']) : [0 => '', 1 => ''];
        $this->user = (isset($this->user[1])) ? \json_decode(\base64_decode($this->user[1]), true) : [];

        return $this->tokens;
    }

    /**
     * @param string $accessToken
     *
     * @return string
     */
    public function getUserID(string $accessToken): string
    {
        return $this->user['sub'] ?? '';
    }

    /**
     * @param string $accessToken
     *
     * @return string
     */
    public function getUserEmail(string $accessToken): string
    {
        return $this->user['email'] ?? '';
    }

    /**
     * @param string $accessToken
     *
     * @return bool
     */
    public function isEmailVerified(string $accessToken): bool
    {
        if ($this->user['email_verified'] ?? false) {
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
        return $this->user['name'] ?? '';
    }
}

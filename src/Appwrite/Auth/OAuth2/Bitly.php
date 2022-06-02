<?php

namespace Appwrite\Auth\OAuth2;

use Appwrite\Auth\OAuth2;

// Reference Material
// https://dev.bitly.com/v4_documentation.html

class Bitly extends OAuth2
{
    /**
     * @var string
     */
    private string $endpoint = 'https://bitly.com/oauth/';

    /**
     * @var string
     */
    private string $resourceEndpoint = 'https://api-ssl.bitly.com/';

    /**
     * @var array
     */
    protected array $scopes = [];

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
        return 'bitly';
    }

    /**
     * @return string
     */
    public function getLoginURL(): string
    {
        return $this->endpoint . 'authorize?' .
            \http_build_query([
                'client_id' => $this->appID,
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
            $response = $this->request(
                'POST',
                $this->resourceEndpoint . 'oauth/access_token',
                ["Content-Type: application/x-www-form-urlencoded"],
                \http_build_query([
                    "client_id" => $this->appID,
                    "client_secret" => $this->appSecret,
                    "code" => $code,
                    "redirect_uri" => $this->callback,
                    "state" => \json_encode($this->state)
                ])
            );

            $output = [];
            \parse_str($response, $output);
            $this->tokens = $output;
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
            $this->resourceEndpoint . 'oauth/access_token',
            ["Content-Type: application/x-www-form-urlencoded"],
            \http_build_query([
                "client_id" => $this->appID,
                "client_secret" => $this->appSecret,
                "refresh_token" => $refreshToken,
                'grant_type' => 'refresh_token'
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

        return $user['login'] ?? '';
    }

    /**
     * @param string $accessToken
     *
     * @return string
     */
    public function getUserEmail(string $accessToken): string
    {
        $user = $this->getUser($accessToken);

        if (isset($user['emails'])) {
            foreach ($user['emails'] as $email) {
                if ($email['is_verified'] === true) {
                    return $email['email'];
                }
            }
        }

        return '';
    }

    /**
     * Check if the OAuth email is verified
     *
     * @link https://dev.bitly.com/api-reference#getUser
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
    protected function getUser(string $accessToken)
    {
        $headers = [
            'Authorization: Bearer ' . \urlencode($accessToken),
            "Accept: application/json"
        ];

        if (empty($this->user)) {
            $this->user = \json_decode($this->request('GET', $this->resourceEndpoint . "v4/user", $headers), true);
        }

        return $this->user;
    }
}

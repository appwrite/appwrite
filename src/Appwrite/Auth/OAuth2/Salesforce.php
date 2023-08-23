<?php

namespace Appwrite\Auth\OAuth2;

use Appwrite\Auth\OAuth2;

// Reference Material
// https://help.salesforce.com/articleView?id=remoteaccess_oauth_endpoints.htm&type=5
// https://help.salesforce.com/articleView?id=remoteaccess_oauth_tokens_scopes.htm&type=5
// https://help.salesforce.com/articleView?id=remoteaccess_oauth_web_server_flow.htm&type=5

class Salesforce extends OAuth2
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
        'openid',
    ];

    /**
     * @return string
     */
    public function getName(): string
    {
        return 'Salesforce';
    }

    /**
     * @param  string  $state
     * @return array
     */
    public function parseState(string $state)
    {
        return \json_decode(\html_entity_decode($state), true);
    }

    /**
     * @return string
     */
    public function getLoginURL(): string
    {
        return 'https://login.salesforce.com/services/oauth2/authorize?'.\http_build_query([
            'response_type' => 'code',
            'client_id' => $this->appID,
            'redirect_uri' => $this->callback,
            'scope' => \implode(' ', $this->getScopes()),
            'state' => \json_encode($this->state),
        ]);
    }

    /**
     * @param  string  $code
     * @return array
     */
    protected function getTokens(string $code): array
    {
        if (empty($this->tokens)) {
            $headers = [
                'Authorization: Basic '.\base64_encode($this->appID.':'.$this->appSecret),
                'Content-Type: application/x-www-form-urlencoded',
            ];
            $this->tokens = \json_decode($this->request(
                'POST',
                'https://login.salesforce.com/services/oauth2/token',
                $headers,
                \http_build_query([
                    'code' => $code,
                    'redirect_uri' => $this->callback,
                    'grant_type' => 'authorization_code',
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
        $headers = [
            'Authorization: Basic '.\base64_encode($this->appID.':'.$this->appSecret),
            'Content-Type: application/x-www-form-urlencoded',
        ];
        $this->tokens = \json_decode($this->request(
            'POST',
            'https://login.salesforce.com/services/oauth2/token',
            $headers,
            \http_build_query([
                'refresh_token' => $refreshToken,
                'grant_type' => 'refresh_token',
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

        return $user['user_id'] ?? '';
    }

    /**
     * @param  string  $accessToken
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
     * @link https://help.salesforce.com/s/articleView?id=sf.remoteaccess_using_userinfo_endpoint.htm&type=5
     *
     * @param  string  $accessToken
     * @return bool
     */
    public function isEmailVerified(string $accessToken): bool
    {
        $user = $this->getUser($accessToken);

        if ($user['email_verified'] ?? false) {
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
            $user = $this->request('GET', 'https://login.salesforce.com/services/oauth2/userinfo?access_token='.\urlencode($accessToken));
            $this->user = \json_decode($user, true);
        }

        return $this->user;
    }
}

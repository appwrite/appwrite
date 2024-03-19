<?php

namespace Appwrite\Auth\OAuth2;

use Appwrite\Auth\OAuth2;

// Reference Material
// https://openid.net/connect/faq/

class Oidc extends OAuth2
{
    /**
     * @var array
     */
    protected array $scopes = [
        'openid',
        'profile',
        'email',
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
        return 'oidc';
    }

    protected array $wellKnownConfiguration = [];

    /**
     * @return string
     */
    public function getLoginURL(): string
    {
        return $this->getAuthorizationEndpoint() . '?' . \http_build_query([
            'client_id' => $this->appID,
            'redirect_uri' => $this->callback,
            'state' => \json_encode($this->state),
            'scope' => \implode(' ', $this->getScopes()),
            'response_type' => 'code',
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
            $this->tokens = \json_decode($this->request(
                'POST',
                $this->getTokenEndpoint(),
                $headers,
                \http_build_query([
                    'code' => $code,
                    'client_id' => $this->appID,
                    'client_secret' => $this->getClientSecret(),
                    'redirect_uri' => $this->callback,
                    'scope' => \implode(' ', $this->getScopes()),
                    'grant_type' => 'authorization_code'
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
        $headers = ['Content-Type: application/x-www-form-urlencoded'];
        $this->tokens = \json_decode($this->request(
            'POST',
            $this->getTokenEndpoint(),
            $headers,
            \http_build_query([
                'refresh_token' => $refreshToken,
                'client_id' => $this->appID,
                'client_secret' => $this->getClientSecret(),
                'grant_type' => 'refresh_token'
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

        if (isset($user['sub'])) {
            return $user['sub'];
        }

        return '';
    }

    /**
     * @param string $accessToken
     *
     * @return string
     */
    public function getUserEmail(string $accessToken): string
    {
        $user = $this->getUser($accessToken);

        if (isset($user['email'])) {
            return $user['email'];
        }

        return '';
    }

    /**
     * Check if the User email is verified
     *
     * @param string $accessToken
     *
     * @return bool
     */
    public function isEmailVerified(string $accessToken): bool
    {
        $user = $this->getUser($accessToken);

        return $user['email_verified'] ?? false;
    }

    /**
     * @param string $accessToken
     *
     * @return string
     */
    public function getUserName(string $accessToken): string
    {
        $user = $this->getUser($accessToken);

        if (isset($user['name'])) {
            return $user['name'];
        }

        return '';
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
            $user = $this->request('GET', $this->getUserinfoEndpoint(), $headers);
            $this->user = \json_decode($user, true);
        }

        return $this->user;
    }

    /**
     * Extracts the Client Secret from the JSON stored in appSecret
     *
     * @return string
     */
    protected function getClientSecret(): string
    {
        $secret = $this->getAppSecret();

        return $secret['clientSecret'] ?? '';
    }

    /**
    * Extracts the well known endpoint from the JSON stored in appSecret.
    *
    * @return string
    */
    protected function getWellKnownEndpoint(): string
    {
        $secret = $this->getAppSecret();
        return $secret['wellKnownEndpoint'] ?? '';
    }

    /**
    * Extracts the authorization endpoint from the JSON stored in appSecret.
    *
    * If one is not provided, it will be retrieved from the well-known configuration.
     *
     * @return string
     */
    protected function getAuthorizationEndpoint(): string
    {
        $secret = $this->getAppSecret();

        $endpoint = $secret['authorizationEndpoint'] ?? '';
        if (!empty($endpoint)) {
            return $endpoint;
        }

        $wellKnownConfiguration = $this->getWellKnownConfiguration();
        return $wellKnownConfiguration['authorization_endpoint'] ?? '';
    }

    /**
    * Extracts the token endpoint from the JSON stored in appSecret.
    *
    * If one is not provided, it will be retrieved from the well-known configuration.
    *
    * @return string
    */
    protected function getTokenEndpoint(): string
    {
        $secret = $this->getAppSecret();

        $endpoint = $secret['tokenEndpoint'] ?? '';
        if (!empty($endpoint)) {
            return $endpoint;
        }

        $wellKnownConfiguration = $this->getWellKnownConfiguration();
        return $wellKnownConfiguration['token_endpoint'] ?? '';
    }

    /**
    * Extracts the userinfo endpoint from the JSON stored in appSecret.
    *
    * If one is not provided, it will be retrieved from the well-known configuration.
    *
    * @return string
    */
    protected function getUserinfoEndpoint(): string
    {
        $secret = $this->getAppSecret();
        $endpoint = $secret['userinfoEndpoint'] ?? '';
        if (!empty($endpoint)) {
            return $endpoint;
        }

        $wellKnownConfiguration = $this->getWellKnownConfiguration();
        return $wellKnownConfiguration['userinfo_endpoint'] ?? '';
    }

    /**
     * Get the well-known configuration using the well known endpoint
     */
    protected function getWellKnownConfiguration(): array
    {
        if (empty($this->wellKnownConfiguration)) {
            $response = $this->request('GET', $this->getWellKnownEndpoint());
            $this->wellKnownConfiguration = \json_decode($response, true);
        }

        return $this->wellKnownConfiguration;
    }

    /**
     * Decode the JSON stored in appSecret
     *
     * @return array
     */
    protected function getAppSecret(): array
    {
        try {
            $secret = \json_decode($this->appSecret, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $th) {
            throw new \Exception('Invalid secret');
        }
        return $secret;
    }
}

<?php

namespace Appwrite\Auth;

use Appwrite\Auth\OAuth2\Exception;

abstract class OAuth2
{
    /**
     * @var string
     */
    protected string $appID;

    /**
     * @var string
     */
    protected string $appSecret;

    /**
     * @var string
     */
    protected string $callback;

    /**
     * @var array
     */
    protected array $state;

    /**
     * @var array
     */
    protected array $scopes;

    /**
     * OAuth2 constructor.
     *
     * @param string $appId
     * @param string $appSecret
     * @param string $callback
     * @param array  $state
     * @param array $scopes
     */
    public function __construct(string $appId, string $appSecret, string $callback, array $state = [], array $scopes = [])
    {
        $this->appID = $appId;
        $this->appSecret = $appSecret;
        $this->callback = $callback;
        $this->state = $state;
        foreach ($scopes as $scope) {
            $this->addScope($scope);
        }
    }

    /**
     * @return string
     */
    abstract public function getName(): string;

    /**
     * @return string
     */
    abstract public function getLoginURL(): string;

    /**
     * @param string $code
     *
     * @return array
     */
    abstract protected function getTokens(string $code): array;

    /**
     * @param string $refreshToken
     *
     * @return array
     */
    abstract public function refreshTokens(string $refreshToken): array;

    /**
     * @param string $accessToken
     *
     * @return string
     */
    abstract public function getUserID(string $accessToken): string;

    /**
     * @param string $accessToken
     *
     * @return string
     */
    abstract public function getUserEmail(string $accessToken): string;

    /**
     * Check if the OAuth email is verified
     *
     * @param string $accessToken
     *
     * @return bool
     */
    abstract public function isEmailVerified(string $accessToken): bool;

    /**
     * @param string $accessToken
     *
     * @return string
     */
    abstract public function getUserName(string $accessToken): string;

    /**
     * @param $scope
     *
     * @return $this
     */
    protected function addScope(string $scope): OAuth2
    {
        // Add a scope to the scopes array if it isn't already present
        if (!\in_array($scope, $this->scopes)) {
            $this->scopes[] = $scope;
        }

        return $this;
    }

    /**
     * @return array
     */
    protected function getScopes(): array
    {
        return $this->scopes;
    }

    /**
     * @param string $code
     *
     * @return string
     */
    public function getAccessToken(string $code): string
    {
        $tokens = $this->getTokens($code);

        return $tokens['access_token'] ?? '';
    }

    /**
     * @param string $code
     *
     * @return string
     */
    public function getRefreshToken(string $code): string
    {
        $tokens = $this->getTokens($code);

        return $tokens['refresh_token'] ?? '';
    }

    /**
     * @param string $code
     *
     * @return string
     */
    public function getAccessTokenExpiry(string $code): int
    {
        $tokens = $this->getTokens($code);

        return $tokens['expires_in'] ?? 0;
    }

    // The parseState function was designed specifically for Amazon OAuth2 Adapter to override.
    // The response from Amazon is html encoded and hence it needs to be html_decoded before
    // json_decoding
    /**
     * @param $state
     *
     * @return array
     */
    public function parseState(string $state)
    {
        return \json_decode($state, true);
    }

    /**
     * @param string $method
     * @param string $url
     * @param array  $headers
     * @param string $payload
     *
     * @return string
     */
    protected function request(string $method, string $url = '', array $headers = [], string $payload = ''): string
    {
        $ch = \curl_init($url);

        \curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        \curl_setopt($ch, CURLOPT_HEADER, 0);
        \curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        \curl_setopt($ch, CURLOPT_USERAGENT, 'Appwrite OAuth2');

        if (!empty($payload)) {
            \curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        }

        $headers[] = 'Content-length: ' . \strlen($payload);
        \curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        // Send the request & save response to $response
        $response = \curl_exec($ch);

        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        \curl_close($ch);

        if ($code >= 400) {
            throw new Exception($response, $code);
        }

        return (string)$response;
    }
}

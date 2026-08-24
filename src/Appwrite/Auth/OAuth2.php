<?php

namespace Appwrite\Auth;

use Appwrite\Auth\OAuth2\Exception;

abstract class OAuth2
{
    protected string $appID;

    protected string $appSecret;

    protected string $callback;

    protected array $state;

    protected array $scopes;

    /**
     * OAuth2 constructor.
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

    abstract public function getName(): string;

    abstract public function getLoginURL(): string;

    abstract protected function getTokens(string $code): array;

    abstract public function refreshTokens(string $refreshToken): array;

    abstract public function getUserID(string $accessToken): string;

    abstract public function getUserEmail(string $accessToken): string;

    /**
     * Check if the OAuth email is verified
     */
    abstract public function isEmailVerified(string $accessToken): bool;

    abstract public function getUserName(string $accessToken): string;

    /**
     * Return the URL of the user's profile photo from the provider.
     *
     * Returns an empty string when the provider does not expose a photo or
     * the user has not set one. Concrete adapters override this only when
     * their API reliably provides a photo URL; the base implementation is a
     * safe no-op so all existing adapters remain valid without changes.
     */
    public function getUserPhoto(string $accessToken): string
    {
        return '';
    }

    /**
     * @return $this
     */
    protected function addScope(string $scope): OAuth2
    {
        // Add a scope to the scopes array if it isn't already present
        if (! \in_array($scope, $this->scopes)) {
            $this->scopes[] = $scope;
        }

        return $this;
    }

    protected function getScopes(): array
    {
        return $this->scopes;
    }

    public function getAccessToken(string $code): string
    {
        $tokens = $this->getTokens($code);

        return $tokens['access_token'] ?? '';
    }

    public function getRefreshToken(string $code): string
    {
        $tokens = $this->getTokens($code);

        return $tokens['refresh_token'] ?? '';
    }

    public function getAccessTokenExpiry(string $code): int
    {
        $tokens = $this->getTokens($code);

        return $tokens['expires_in'] ?? 0;
    }

    // The parseState function was designed specifically for Amazon OAuth2 Adapter to override.
    // The response from Amazon is html encoded and hence it needs to be html_decoded before
    // json_decoding
    /**
     * @return array
     */
    public function parseState(string $state)
    {
        return \json_decode($state, true);
    }

    protected function request(string $method, string $url = '', array $headers = [], string $payload = ''): string
    {
        $ch = \curl_init($url);

        \curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        \curl_setopt($ch, CURLOPT_HEADER, 0);
        \curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        \curl_setopt($ch, CURLOPT_USERAGENT, 'Appwrite OAuth2');

        if (! empty($payload)) {
            \curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            $headers[] = 'Content-length: '.\strlen($payload);
        }

        \curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        // Send the request & save response to $response
        $response = \curl_exec($ch);

        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($code >= 400) {
            throw new Exception($response, $code);
        }

        return (string) $response;
    }
}

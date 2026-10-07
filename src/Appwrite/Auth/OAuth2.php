<?php

namespace Appwrite\Auth;

use Appwrite\Auth\OAuth2\Exception;
use Psr\Http\Client\ClientExceptionInterface;
use Utopia\Client\Client;
use Utopia\Client\Exception\DestinationException;
use Utopia\Psr7\ContentType;
use Utopia\Psr7\Header;
use Utopia\Psr7\Request\Factory as RequestFactory;

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
     * @param Client $client Carries every request to the provider; its destination decides which addresses it may reach
     * @param string $appId
     * @param string $appSecret
     * @param string $callback
     * @param array  $state
     * @param array $scopes
     */
    public function __construct(protected readonly Client $client, string $appId, string $appSecret, string $callback, array $state = [], array $scopes = [])
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
     * Return the URL of the user's profile photo from the provider.
     *
     * Returns an empty string when the provider does not expose a photo or
     * the user has not set one. Concrete adapters override this only when
     * their API reliably provides a photo URL; the base implementation is a
     * safe no-op so all existing adapters remain valid without changes.
     *
     * @param string $accessToken
     *
     * @return string
     */
    public function getUserPhoto(string $accessToken): string
    {
        return '';
    }

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
     * @return int
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
        $fields = [Header::USER_AGENT => 'Appwrite OAuth2'];
        foreach ($headers as $header) {
            [$name, $value] = \array_map(trim(...), \explode(':', $header, 2)) + [1 => ''];
            $fields[$name] = $value;
        }

        $factory = new RequestFactory();
        $request = empty($payload)
            ? $factory->createRequest($method, $url)
            : $factory->body($method, $url, $payload, ContentType::FORM_URLENCODED);
        foreach ($fields as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        try {
            $response = $this->client->sendRequest($request);
        } catch (DestinationException $exception) {
            throw new Exception($exception->getMessage(), 400);
        } catch (ClientExceptionInterface) {
            return '';
        }

        $body = (string) $response->getBody();

        if ($response->getStatusCode() >= 400) {
            throw new Exception($body, $response->getStatusCode());
        }

        return $body;
    }
}

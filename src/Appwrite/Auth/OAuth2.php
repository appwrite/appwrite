<?php

namespace Appwrite\Auth;

abstract class OAuth2
{
    /**
     * @var string
     */
    protected $appID;

    /**
     * @var string
     */
    protected $appSecret;

    /**
     * @var string
     */
    protected $callback;

    /**
     * @var array
     */
    protected $state;

    /**
     * @var array
     */
    protected $scopes;

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
     * @return string
     */
    abstract public function getAccessToken(string $code): string;

    /**
     * @param $accessToken
     *
     * @return string
     */
    abstract public function getUserID(string $accessToken): string;

    /**
     * @param $accessToken
     *
     * @return string
     */
    abstract public function getUserEmail(string $accessToken): string;

    /**
     * @param $accessToken
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

        \curl_close($ch);

        return (string)$response;
    }
}

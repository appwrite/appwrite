<?php

namespace Appwrite\Auth\OAuth2;

use Appwrite\Auth\OAuth2;
use Utopia\Client\Adapter\Curl\Client as CurlAdapter;
use Utopia\Client\Client;
use Utopia\Psr7\Method;
use Utopia\Psr7\Request\Factory as RequestFactory;

// Reference Material
// https://developers.webflow.com/data/reference/oauth-app
// https://developers.webflow.com/data/reference/token/authorized-by

class Webflow extends OAuth2
{
    /**
     * @var string
     */
    private string $resourceEndpoint = 'https://api.webflow.com/v2/token/authorized_by';

    /**
     * @var array
     */
    protected array $scopes = [
        'authorized_user:read',
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
        return 'webflow';
    }

    /**
     * @return string
     */
    public function getLoginURL(): string
    {
        return 'https://webflow.com/oauth/authorize?' . \http_build_query([
            'response_type' => 'code',
            'client_id' => $this->appID,
            'redirect_uri' => $this->callback,
            'scope' => \implode(' ', $this->getScopes()),
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
            $this->tokens = \json_decode($this->request(
                'POST',
                'https://api.webflow.com/oauth/access_token',
                ['Content-Type: application/x-www-form-urlencoded'],
                \http_build_query([
                    'grant_type' => 'authorization_code',
                    'client_id' => $this->appID,
                    'client_secret' => $this->appSecret,
                    'redirect_uri' => $this->callback,
                    'code' => $code
                ])
            ), true);
        }

        return $this->tokens;
    }

    /**
     * Webflow exposes no refresh grant, so there is nothing to exchange.
     *
     * @param string $refreshToken
     *
     * @return array
     */
    public function refreshTokens(string $refreshToken): array
    {
        $this->tokens['refresh_token'] = $refreshToken;

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
     * @param string $accessToken
     *
     * @return bool
     */
    public function isEmailVerified(string $accessToken): bool
    {
        // Provider exposes no email verification signal, so treat as unverified until one is confirmed
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

        return \trim(($user['firstName'] ?? '') . ' ' . ($user['lastName'] ?? ''));
    }

    /**
     * @param string $accessToken
     *
     * @return array
     */
    protected function getUser(string $accessToken): array
    {
        if (empty($this->user)) {
            $this->user = \json_decode($this->request(
                'GET',
                $this->resourceEndpoint,
                ['Authorization: Bearer ' . $accessToken]
            ), true);
        }

        return $this->user;
    }

    public function verifyCredentials(): void
    {
        $response = (new Client(new CurlAdapter()))
            ->withTimeout(15)
            ->withFollowRedirects(maxHops: 5)
            ->sendRequest((new RequestFactory())->form(
                Method::POST,
                'https://api.webflow.com/oauth/access_token',
                [
                    'grant_type' => 'authorization_code',
                    'client_id' => $this->appID,
                    'client_secret' => $this->appSecret,
                    'redirect_uri' => 'https://invalid.appwrite.callback/intentionally-invalid',
                    'code' => 'intentionally-invalid-code',
                ],
            ));

        $json = \json_decode((string) $response->getBody(), true);

        if (isset($json['error']) && $json['error'] === 'invalid_client') {
            throw new \Exception('Webflow application with the provided Client ID and/or Client Secret is invalid.');
        }

        // We still expect an error, like invalid_grant or invalid_request,
        // but that indicates valid credentials
    }
}

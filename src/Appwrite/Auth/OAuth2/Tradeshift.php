<?php

namespace Appwrite\Auth\OAuth2;

use Appwrite\Auth\OAuth2;

// Reference Material
// https://developers.tradeshift.com/docs/api

class Tradeshift extends OAuth2
{
    public const TRADESHIFT_SANDBOX_API_DOMAIN = 'api-sandbox.tradeshift.com';

    public const TRADESHIFT_API_DOMAIN = 'api.tradeshift.com';

    private array $apiDomain = [
        'sandbox' => self::TRADESHIFT_SANDBOX_API_DOMAIN,
        'live' => self::TRADESHIFT_API_DOMAIN,
    ];

    private array $endpoint = [
        'sandbox' => 'https://'.self::TRADESHIFT_SANDBOX_API_DOMAIN.'/tradeshift/',
        'live' => 'https://'.self::TRADESHIFT_API_DOMAIN.'/tradeshift/',
    ];

    private array $resourceEndpoint = [
        'sandbox' => 'https://'.self::TRADESHIFT_SANDBOX_API_DOMAIN.'/tradeshift/rest/external/',
        'live' => 'https://'.self::TRADESHIFT_API_DOMAIN.'/tradeshift/rest/external/',
    ];

    protected string $environment = 'live';

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
        'offline',
    ];

    /**
     * @return string
     */
    public function getName(): string
    {
        return 'tradeshift';
    }

    /**
     * @return string
     */
    public function getLoginURL(): string
    {
        $httpQuery = \http_build_query([
            'response_type' => 'code',
            'client_id' => $this->appID,
            'scope' => \implode(' ', $this->getScopes()),
            'redirect_uri' => \str_replace('localhost', '127.0.0.1', $this->callback),
            'state' => \json_encode($this->state),
        ]);

        $url = $this->endpoint[$this->environment].'auth/login?'.$httpQuery;

        return $url;
    }

    /**
     * @param  string  $code
     * @return array
     */
    protected function getTokens(string $code): array
    {
        if (empty($this->tokens)) {
            $this->tokens = \json_decode($this->request(
                'POST',
                $this->endpoint[$this->environment].'auth/token',
                ['Authorization: Basic '.\base64_encode($this->appID.':'.$this->appSecret)],
                \http_build_query([
                    'grant_type' => 'authorization_code',
                    'code' => $code,
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
        $this->tokens = \json_decode($this->request(
            'POST',
            $this->endpoint[$this->environment].'auth/token',
            ['Authorization: Basic '.\base64_encode($this->appID.':'.$this->appSecret)],
            \http_build_query([
                'grant_type' => 'refresh_token',
                'refresh_token' => $refreshToken,
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

        return $user['Id'] ?? '';
    }

    /**
     * @param  string  $accessToken
     * @return string
     */
    public function getUserEmail(string $accessToken): string
    {
        $user = $this->getUser($accessToken);

        return $user['Username'] ?? '';
    }

    /**
     * Check if the OAuth email is verified
     *
     * If present, the email is verified. This was verfied through a manual Tradeshift sign up process
     *
     * @param  string  $accessToken
     * @return bool
     */
    public function isEmailVerified(string $accessToken): bool
    {
        $email = $this->getUser($accessToken);

        return ! empty($email);
    }

    /**
     * @param  string  $accessToken
     * @return string
     */
    public function getUserName(string $accessToken): string
    {
        $user = $this->getUser($accessToken);

        $firstName = $user['FirstName'] ?? '';
        $lastName = $user['LastName'] ?? '';

        return $firstName.' '.$lastName;
    }

    /**
     * @param  string  $accessToken
     * @return array
     */
    protected function getUser(string $accessToken): array
    {
        $header = [
            'Content-Type: application/json',
            'Accept: application/json',
            'Host: '.urlencode($this->apiDomain[$this->environment]),
            'Authorization: Bearer '.$accessToken,
        ];

        if (empty($this->user)) {
            $response = $this->request(
                'GET',
                $this->resourceEndpoint[$this->environment].'account/info/user',
                $header
            );
            $this->user = \json_decode($response, true);
        }

        return $this->user;
    }
}

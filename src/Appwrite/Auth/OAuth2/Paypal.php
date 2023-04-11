<?php

namespace Appwrite\Auth\OAuth2;

use Appwrite\Auth\OAuth2;

// Reference Material
// https://developer.paypal.com/docs/api/overview/

class Paypal extends OAuth2
{
    /**
     * @var array
     */
    private array $endpoint = [
        'sandbox' => 'https://www.sandbox.paypal.com/',
        'live' => 'https://www.paypal.com/',
    ];

    /**
     * @var array
     */
    private array $resourceEndpoint = [
        'sandbox' => 'https://api.sandbox.paypal.com/v1/',
        'live' => 'https://api.paypal.com/v1/',
    ];

    /**
     * @var string
     */
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
        'profile',
        'email',
    ];

    /**
     * @return string
     */
    public function getName(): string
    {
        return 'paypal';
    }

    /**
     * @return string
     */
    public function getLoginURL(): string
    {
        $url = $this->endpoint[$this->environment].'connect/?'.
            \http_build_query([
                'flowEntry' => 'static',
                'response_type' => 'code',
                'client_id' => $this->appID,
                'scope' => \implode(' ', $this->getScopes()),
                // paypal is not accepting localhost string into return uri
                'redirect_uri' => \str_replace('localhost', '127.0.0.1', $this->callback),
                'state' => \json_encode($this->state),
            ]);

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
                $this->resourceEndpoint[$this->environment].'oauth2/token',
                ['Authorization: Basic '.\base64_encode($this->appID.':'.$this->appSecret)],
                \http_build_query([
                    'code' => $code,
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
        $this->tokens = \json_decode($this->request(
            'POST',
            $this->resourceEndpoint[$this->environment].'oauth2/token',
            ['Authorization: Basic '.\base64_encode($this->appID.':'.$this->appSecret)],
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

        return $user['payer_id'] ?? '';
    }

    /**
     * @param  string  $accessToken
     * @return string
     */
    public function getUserEmail(string $accessToken): string
    {
        $user = $this->getUser($accessToken);

        if (isset($user['emails'])) {
            $email = array_filter($user['emails'], function ($email) {
                return $email['primary'] === true;
            });

            if (! empty($email)) {
                return $email[0]['value'];
            }
        }

        return '';
    }

    /**
     * Check if the OAuth email is verified
     *
     * @link https://developer.paypal.com/docs/api/identity/v1/#userinfo_get
     *
     * @param  string  $accessToken
     * @return bool
     */
    public function isEmailVerified(string $accessToken): bool
    {
        $user = $this->getUser($accessToken);

        if ($user['verified_account'] ?? false) {
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
        $header = [
            'Content-Type: application/json',
            'Authorization: Bearer '.\urlencode($accessToken),
        ];
        if (empty($this->user)) {
            $user = $this->request(
                'GET',
                $this->resourceEndpoint[$this->environment].'identity/oauth2/userinfo?schema=paypalv1.1',
                $header
            );
            $this->user = \json_decode($user, true);
        }

        return $this->user;
    }
}

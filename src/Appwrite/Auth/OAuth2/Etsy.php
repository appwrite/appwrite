<?php

namespace Appwrite\Auth\OAuth2;

use Appwrite\Auth\OAuth2;

class Etsy extends OAuth2
{
    /**
     * @var string
     */
    private string $endpoint = 'https://api.etsy.com/v3/public';

    /**
     * @var string
     */
    private string $version = '2022-07-14';

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
        'email_r',
        'profile_r',
    ];

    /**
     * @var string
     */
    private string $pkce = '';

    /**
     * @return string
     */
    private function getPKCE(): string
    {
        if (empty($this->pkce)) {
            $this->pkce = \bin2hex(\random_bytes(rand(43, 128)));
        }

        return $this->pkce;
    }

    /**
     * @return string
     */
    public function getName(): string
    {
        return 'etsy';
    }

    /**
     * @return string
     */
    public function getLoginURL(): string
    {
        return 'https://www.etsy.com/oauth/connect/oauth/authorize?'.\http_build_query([
            'client_id' => $this->appID,
            'redirect_uri' => $this->callback,
            'response_type' => 'code',
            'state' => \json_encode($this->state),
            'scope' => $this->scopes,
            'code_challenge' => $this->getPKCE(),
            'code_challenge_method' => 'S256',
        ]);
    }

    /**
     * @param  string  $code
     * @return array
     */
    protected function getTokens(string $code): array
    {
        if (empty($this->tokens)) {
            $headers = ['Content-Type: application/x-www-form-urlencoded'];

            $this->tokens = \json_decode($this->request(
                'POST',
                $this->endpoint.'/oauth/token',
                $headers,
                \http_build_query([
                    'grant_type' => 'authorization_code',
                    'client_id' => $this->appID,
                    'redirect_uri' => $this->callback,
                    'code' => $code,
                    'code_verifier' => $this->getPKCE(),
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
        $headers = ['Content-Type: application/x-www-form-urlencoded'];

        $this->tokens = \json_decode($this->request(
            'POST',
            $this->endpoint.'/oauth/token',
            $headers,
            \http_build_query([
                'grant_type' => 'refresh_token',
                'client_id' => $this->appID,
                'refresh_token' => $refreshToken,
            ])
        ), true);

        if (empty($this->tokens['refresh_token'])) {
            $this->tokens['refresh_token'] = $refreshToken;
        }

        return $this->tokens;
    }

    /**
     * @param $accessToken
     * @return string
     */
    public function getUserID(string $accessToken): string
    {
        $components = explode('.', $accessToken);

        return $components[0];
    }

    /**
     * @param $accessToken
     * @return string
     */
    public function getUserEmail(string $accessToken): string
    {
        return $this->getUser($accessToken)['primary_email'];
    }

    /**
     * Check if the OAuth email is verified
     *
     * OAuth is only allowed if account has been verified through Etsy, itself.
     *
     * @param  string  $accessToken
     * @return bool
     */
    public function isEmailVerified(string $accessToken): bool
    {
        $email = $this->getUserEmail($accessToken);

        return ! empty($email);
    }

    /**
     * @param $accessToken
     * @return string
     */
    public function getUserName(string $accessToken): string
    {
        return $this->getUser($accessToken)['login_name'];
    }

    /**
     * @param  string  $accessToken
     * @return array
     */
    protected function getUser(string $accessToken): array
    {
        if (! empty($this->user)) {
            return $this->user;
        }

        $headers = ['Authorization: Bearer '.$accessToken];

        $this->user = \json_decode($this->request(
            'GET',
            'https://api.etsy.com/v3/application/users/'.$this->getUserID($accessToken),
        ), true);

        return $this->user;
    }
}

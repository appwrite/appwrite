<?php

namespace Appwrite\Auth\OAuth2;

use Appwrite\Auth\OAuth2;

class Stripe extends OAuth2
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
     * @var string
     */
    protected string $stripeAccountId = '';

    /**
     * @var array
     */
    protected array $scopes = [
        'read_write',
    ];

    /**
     * @var array
     */
    protected array $grantType = [
        'authorize' => 'authorization_code',
        'refresh' => 'refresh_token',
    ];

    /**
     * @return string
     */
    public function getName(): string
    {
        return 'stripe';
    }

    /**
     * @return string
     */
    public function getLoginURL(): string
    {
        return 'https://connect.stripe.com/oauth/authorize?'.\http_build_query([
            'response_type' => 'code', // The only option at the moment is "code."
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
            $this->tokens = \json_decode($this->request(
                'POST',
                'https://connect.stripe.com/oauth/token',
                [],
                \http_build_query([
                    'grant_type' => $this->grantType['authorize'],
                    'code' => $code,
                ])
            ), true);

            $this->stripeAccountId = $this->tokens['stripe_user_id'];
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
            'https://connect.stripe.com/oauth/token',
            [],
            \http_build_query([
                'grant_type' => $this->grantType['refresh'],
                'refresh_token' => $refreshToken,
            ])
        ), true);

        if (empty($this->tokens['refresh_token'])) {
            $this->tokens['refresh_token'] = $refreshToken;
        }

        $this->stripeAccountId = $this->tokens['stripe_user_id'];

        return $this->tokens;
    }

    /**
     * @param  string  $accessToken
     * @return string
     */
    public function getUserID(string $accessToken): string
    {
        $user = $this->getUser($accessToken);

        return $user['id'] ?? '';
    }

    /**
     * @param  string  $accessToken
     * @return string
     */
    public function getUserEmail(string $accessToken): string
    {
        $user = $this->getUser($accessToken);

        if (empty($user)) {
            return '';
        }

        return $user['email'] ?? '';
    }

    /**
     * Check if the OAuth email is verified
     *
     * If present, the email is verified. This was verfied through a manual Stripe sign up process
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
    protected function getUser(string $accessToken)
    {
        if (empty($this->user) && ! empty($this->stripeAccountId)) {
            $this->user = \json_decode(
                $this->request(
                    'GET',
                    'https://api.stripe.com/v1/accounts/'.$this->stripeAccountId,
                    ['Authorization: Bearer '.\urlencode($accessToken)]
                ),
                true
            );
        }

        return $this->user;
    }
}

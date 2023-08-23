<?php

namespace Appwrite\Auth\OAuth2;

use Appwrite\Auth\OAuth2;

class Linkedin extends OAuth2
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
     * @var array
     */
    protected array $scopes = [
        'r_liteprofile',
        'r_emailaddress',
    ];

    /**
     * Documentation.
     *
     * OAuth:
     * https://developer.linkedin.com/docs/oauth2
     *
     * API/V2:
     * https://developer.linkedin.com/docs/guide/v2
     *
     * Basic Profile Fields:
     * https://developer.linkedin.com/docs/fields/basic-profile
     */

    /**
     * @return string
     */
    public function getName(): string
    {
        return 'linkedin';
    }

    /**
     * @return string
     */
    public function getLoginURL(): string
    {
        return 'https://www.linkedin.com/oauth/v2/authorization?'.\http_build_query([
            'response_type' => 'code',
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
                'https://www.linkedin.com/oauth/v2/accessToken',
                ['Content-Type: application/x-www-form-urlencoded'],
                \http_build_query([
                    'grant_type' => 'authorization_code',
                    'code' => $code,
                    'redirect_uri' => $this->callback,
                    'client_id' => $this->appID,
                    'client_secret' => $this->appSecret,
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
            'https://www.linkedin.com/oauth/v2/accessToken',
            ['Content-Type: application/x-www-form-urlencoded'],
            \http_build_query([
                'grant_type' => 'refresh_token',
                'refresh_token' => $refreshToken,
                'redirect_uri' => $this->callback,
                'client_id' => $this->appID,
                'client_secret' => $this->appSecret,
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

        return $user['id'] ?? '';
    }

    /**
     * @param  string  $accessToken
     * @return string
     */
    public function getUserEmail(string $accessToken): string
    {
        $email = \json_decode($this->request('GET', 'https://api.linkedin.com/v2/emailAddress?q=members&projection=(elements*(handle~))', ['Authorization: Bearer '.\urlencode($accessToken)]), true);

        return $email['elements'][0]['handle~']['emailAddress'] ?? '';
    }

    /**
     * Check if the OAuth email is verified
     *
     * If present, the email is verified. This was verfied through a manual Linkedin sign up process
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
        $name = '';

        if (isset($user['localizedFirstName'])) {
            $name = $user['localizedFirstName'];
        }

        if (isset($user['localizedLastName'])) {
            $name = (empty($name)) ? $user['localizedLastName'] : $name.' '.$user['localizedLastName'];
        }

        return $name;
    }

    /**
     * @param  string  $accessToken
     * @return array
     */
    protected function getUser(string $accessToken)
    {
        if (empty($this->user)) {
            $this->user = \json_decode($this->request('GET', 'https://api.linkedin.com/v2/me', ['Authorization: Bearer '.\urlencode($accessToken)]), true);
        }

        return $this->user;
    }
}

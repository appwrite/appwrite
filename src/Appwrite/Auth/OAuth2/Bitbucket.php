<?php

namespace Appwrite\Auth\OAuth2;

use Appwrite\Auth\OAuth2;

// Reference Material
// https://confluence.atlassian.com/bitbucket/oauth-on-bitbucket-cloud-238027431.html#OAuthonBitbucketCloud-Createaconsumer

class Bitbucket extends OAuth2
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
    protected array $scopes = [];

    /**
     * @return string
     */
    public function getName(): string
    {
        return 'bitbucket';
    }

    /**
     * @return string
     */
    public function getLoginURL(): string
    {
        return 'https://bitbucket.org/site/oauth2/authorize?'.\http_build_query([
            'response_type' => 'code',
            'client_id' => $this->appID,
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
            // Required as per Bitbucket Spec.
            $headers = ['Content-Type: application/x-www-form-urlencoded'];
            $this->tokens = \json_decode($this->request(
                'POST',
                'https://bitbucket.org/site/oauth2/access_token',
                $headers,
                \http_build_query([
                    'code' => $code,
                    'client_id' => $this->appID,
                    'client_secret' => $this->appSecret,
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
        $headers = ['Content-Type: application/x-www-form-urlencoded'];
        $this->tokens = \json_decode($this->request(
            'POST',
            'https://bitbucket.org/site/oauth2/access_token',
            $headers,
            \http_build_query([
                'client_id' => $this->appID,
                'client_secret' => $this->appSecret,
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

        return $user['uuid'] ?? '';
    }

    /**
     * @param  string  $accessToken
     * @return string
     */
    public function getUserEmail(string $accessToken): string
    {
        $user = $this->getUser($accessToken);

        return $user['email'] ?? '';
    }

    /**
     * Check if the OAuth email is verified
     *
     * @param  string  $accessToken
     * @return bool
     */
    public function isEmailVerified(string $accessToken): bool
    {
        $user = $this->getUser($accessToken);

        if ($user['is_confirmed'] ?? false) {
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

        return $user['display_name'] ?? '';
    }

    /**
     * @param  string  $accessToken
     * @return array
     */
    protected function getUser(string $accessToken): array
    {
        if (empty($this->user)) {
            $user = $this->request('GET', 'https://api.bitbucket.org/2.0/user?access_token='.\urlencode($accessToken));
            $this->user = \json_decode($user, true);

            $emails = $this->request('GET', 'https://api.bitbucket.org/2.0/user/emails?access_token='.\urlencode($accessToken));
            $emails = \json_decode($emails, true);
            if (isset($emails['values'])) {
                foreach ($emails['values'] as $email) {
                    if ($email['is_confirmed']) {
                        $this->user['email'] = $email['email'];
                        $this->user['is_confirmed'] = $email['is_confirmed'];
                        break;
                    }
                }
            }
        }

        return $this->user;
    }
}

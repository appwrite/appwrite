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
    protected $user = [];

    /**
     * @var array
     */
    protected $scopes = [];

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
        return 'https://bitbucket.org/site/oauth2/authorize?' . \http_build_query([
            'response_type' => 'code',
            'client_id' => $this->appID,
            'scope' => \implode(' ', $this->getScopes()),
            'state' => \json_encode($this->state),
        ]);
    }

    /**
     * @param string $code
     *
     * @return string
     */
    public function getAccessToken(string $code): string
    {
        // Required as per Bitbucket Spec.
        $headers = ['Content-Type: application/x-www-form-urlencoded'];

        $accessToken = $this->request(
            'POST',
            'https://bitbucket.org/site/oauth2/access_token',
            $headers,
            \http_build_query([
                'code' => $code,
                'client_id' => $this->appID,
                'client_secret' => $this->appSecret,
                'grant_type' => 'authorization_code'
            ])
        );

        $accessToken = \json_decode($accessToken, true);

        if (isset($accessToken['access_token'])) {
            return $accessToken['access_token'];
        }

        return '';
    }

    /**
     * @param string $accessToken
     *
     * @return string
     */
    public function getUserID(string $accessToken): string
    {
        $user = $this->getUser($accessToken);

        if (isset($user['uuid'])) {
            return $user['uuid'];
        }

        return '';
    }

    /**
     * @param string $accessToken
     *
     * @return string
     */
    public function getUserEmail(string $accessToken): string
    {
        $user = $this->getUser($accessToken);

        if (isset($user['email'])) {
            return $user['email'];
        }

        return '';
    }

    /**
     * @param string $accessToken
     *
     * @return string
     */
    public function getUserName(string $accessToken): string
    {
        $user = $this->getUser($accessToken);

        if (isset($user['display_name'])) {
            return $user['display_name'];
        }

        return '';
    }

    /**
     * @param string $accessToken
     *
     * @return array
     */
    protected function getUser(string $accessToken): array
    {
        if (empty($this->user)) {
            $user = $this->request('GET', 'https://api.bitbucket.org/2.0/user?access_token=' . \urlencode($accessToken));
            $this->user = \json_decode($user, true);

            $email = $this->request('GET', 'https://api.bitbucket.org/2.0/user/emails?access_token=' . \urlencode($accessToken));
            $this->user['email'] = \json_decode($email, true)['values'][0]['email'];
        }
        return $this->user;
    }
}

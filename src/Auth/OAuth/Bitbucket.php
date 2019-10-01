<?php

namespace Auth\OAuth;

use Auth\OAuth;

// Reference Material
// https://confluence.atlassian.com/bitbucket/oauth-on-bitbucket-cloud-238027431.html#OAuthonBitbucketCloud-Createaconsumer

class Bitbucket extends OAuth
{
    /**
     * @var array
     */
    protected $user = [];

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
        return 'https://bitbucket.org/site/oauth2/authorize?' .
            'client_id=' . urlencode($this->appID).
            '&state=' . urlencode(json_encode($this->state)).
            '&response_type=code';
    }

    /**
     * @param string $code
     *
     * @return string
     */
    public function getAccessToken(string $code): string
    {
        // Required as per Bitbucket Spec.
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';

        $accessToken = $this->request(
            'POST',
            'https://bitbucket.org/site/oauth2/access_token',
            $headers,
            'code=' . urlencode($code) .
            '&client_id=' . urlencode($this->appID) .
            '&client_secret=' . urlencode($this->appSecret).
            '&grant_type=authorization_code'
        );

        $accessToken = json_decode($accessToken, true);

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

        if (isset($user['account_id'])) {
            return $user['account_id'];
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
            $user = $this->request('GET', 'https://api.bitbucket.org/2.0/user?access_token='.urlencode($accessToken));
            $this->user = json_decode($user, true);

            $email = $this->request('GET', 'https://api.bitbucket.org/2.0/user/emails?access_token='.urlencode($accessToken));
            $this->user['email'] = json_decode($email, true)['values'][0]['email'];
        }
        return $this->user;
    }
}

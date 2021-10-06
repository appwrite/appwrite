<?php

namespace Appwrite\Auth\OAuth2;

use Appwrite\Auth\OAuth2;

// Reference Material
// https://docs.microsoft.com/en-us/azure/active-directory/develop/v2-oauth2-auth-code-flow
// https://docs.microsoft.com/en-us/graph/auth-v2-user

class Microsoft extends OAuth2
{
    /**
     * @var array
     */
    protected $user = [];

    /**
     * @var array
     */
    protected $scopes = [
        'offline_access',
        'user.read'
    ];

    /**
     * @return string
     */
    public function getName(): string
    {
        return 'microsoft';
    }

    /**
     * @return string
     */
    public function getLoginURL(): string
    {
        return 'https://login.microsoftonline.com/common/oauth2/v2.0/authorize?' . \http_build_query([
            'client_id' => $this->appID,
            'redirect_uri' => $this->callback,
            'state' => \json_encode($this->state),
            'scope' => \implode(' ', $this->getScopes()),
            'response_type' => 'code',
            'response_mode' => 'query'
        ]);
    }

    /**
     * @param string $code
     *
     * @return string
     */
    public function getAccessToken(string $code): string
    {
        $headers = ['Content-Type: application/x-www-form-urlencoded'];

        $accessToken = $this->request(
            'POST',
            'https://login.microsoftonline.com/common/oauth2/v2.0/token',
            $headers,
            \http_build_query([
                'code' => $code,
                'client_id' => $this->appID,
                'client_secret' => $this->appSecret,
                'redirect_uri' => $this->callback,
                'scope' => \implode(' ', $this->getScopes()),
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

        if (isset($user['id'])) {
            return $user['id'];
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

        if (isset($user['userPrincipalName'])) {
            return $user['userPrincipalName'];
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

        if (isset($user['displayName'])) {
            return $user['displayName'];
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
            $headers = ['Authorization: Bearer ' . \urlencode($accessToken)];
            $user = $this->request('GET', 'https://graph.microsoft.com/v1.0/me', $headers);
            $this->user = \json_decode($user, true);
        }

        return $this->user;
    }
}

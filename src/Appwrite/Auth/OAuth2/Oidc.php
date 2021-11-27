<?php

namespace Appwrite\Auth\OAuth2;

use Appwrite\Auth\OAuth2;


class Oidc extends OAuth2
{
    /**
     * @var string
     */
    protected $version = 'v1';

    /**
     * @var array
     */
    protected $scopes = [
        'openid',
        'email',
        'profile',
    ];

    /**
     * @var array
     */
    protected $user = [];

    /**
     * @return string
     */
    public function getName(): string
    {
        return 'oidc';
    }

    /**
     * @return string
     */
    public function getLoginURL(): string
    {
        $envScopes = getEnv('_APP_OIDC_SCOPES');

        if(!empty($envScopes)){
            $scopesToAdd = explode(' ',$envScopes);
            foreach ($scopesToAdd as $scope) {
                $this->addScope($scope);
            }
        }

        return getEnv('_APP_OIDC_AUTH_ENDPOINT').'?'. \http_build_query([
            'client_id' => $this->appID,
            'redirect_uri' => $this->callback,
            'scope' => \implode(' ', $this->getScopes()),
            'state' => \json_encode($this->state),
            'response_type' => 'code'
        ]);
    }

    /**
     * @param string $code
     *
     * @return string
     */
    public function getAccessToken(string $code): string
    {
        $headers = ['Content-Type: application/x-www-form-urlencoded;charset=UTF-8'];
        $accessToken = $this->request(
            'POST',
            getEnv('_APP_OIDC_TOKEN_ENDPOINT'),
            $headers,
            \http_build_query([
                'code' => $code,
                'client_id' => $this->appID,
                'client_secret' => $this->appSecret,
                'redirect_uri' => $this->callback,
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

        if (isset($user['sub'])) {
            return $user['sub'];
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

        if (isset($user['name'])) {
            return $user['name'];
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
            $headers = ['Authorization: Bearer '.$accessToken];
            $user = $this->request('GET', getEnv('_APP_OIDC_USERINFO_ENDPOINT'),
                $headers);
            $this->user = \json_decode($user, true);
        }
        return $this->user;
    }
}

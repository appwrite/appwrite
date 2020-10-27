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
    private $endpoint = [
        'sandbox' => 'https://www.sandbox.paypal.com/',
        'live' => 'https://www.paypal.com/',
    ];

    /**
     * @var array
     */
    private $resourceEndpoint = [
        'sandbox' => 'https://api.sandbox.paypal.com/v1/',
        'live' => 'https://api.paypal.com/v1/',
    ];

    /**
     * @var string
     */
    protected $environment = 'live';

    /**
     * @var array
     */
    protected $user = [];

    /**
     * @var array
     */
    protected $scopes = [
        'openid',
        'profile',
        'email'
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
        $url = $this->endpoint[$this->environment] . 'connect/?'.
            \http_build_query([
                'flowEntry' => 'static',
                'response_type' => 'code',
                'client_id' => $this->appID,
                'scope' => \implode(' ', $this->getScopes()),
                // paypal is not accepting localhost string into return uri
                'redirect_uri' => \str_replace("localhost", "127.0.0.1", $this->callback),
                'state' => \json_encode($this->state),
            ]);

        return $url;
    }

    /**
     * @param string $code
     *
     * @return string
     */
    public function getAccessToken(string $code): string
    {
        $accessToken = $this->request(
            'POST',
            $this->resourceEndpoint[$this->environment] . 'oauth2/token',
            ['Authorization: Basic ' . \base64_encode($this->appID . ':' . $this->appSecret)],
            \http_build_query([
                'code' => $code,
                'grant_type' => 'authorization_code',
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

        if (isset($user['payer_id'])) {
            return $user['payer_id'];
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

        if (isset($user['emails'])) {
            return $user['emails'][0]['value'];
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
        $header = [
            'Content-Type: application/json',
            'Authorization: Bearer '.\urlencode($accessToken),
        ];
        if (empty($this->user)) {
            $user = $this->request(
                'GET',
                $this->resourceEndpoint[$this->environment] . 'identity/oauth2/userinfo?schema=paypalv1.1',
                $header
            );
            $this->user = \json_decode($user, true);
        }

        return $this->user;
    }
}

<?php

namespace Appwrite\Auth\OAuth2;

use Appwrite\Auth\OAuth2;

class Notion extends OAuth2
{
    /**
     * @var string
     */
    private $endpoint = 'https://api.etsy.com/v3/public';

    /**
     * @var string
     */
    private $version = '2022-03-10';

    /**
     * @var array
     */
    protected $user = [];
    
    /**
     * @var array
     */
    protected $tokens = [];

    /**
     * @var array
     */
    protected $scopes = [
      "address_r",
      "address_w",
      "billing_r",
      "cart_r",
      "cart_w",
      "email_r",
      "favorites_r",
      "favorites_w",
      "feedback_r",
      "listings_d",
      "listings_r",
      "listings_w",
      "profile_r",
      "profile_w",
      "recommend_r",
      "recommend_w",
      "shops_r",
      "shops_w",
      "transactions_r",
      "transactions_w",    
    ];

    private $pkce = '';

    private function getPKCE()
    {
        if(empty($this->pkce)) {
            $this->pkce = \bin2hex(\random_bytes(rand(43, 128)));
        }

        return $this->pkce;
    }

    /**
     * @return string
     */
    public function getName():string
    {
        return 'etsy';
    }

    /**
     * @return string
     */
    public function getLoginURL():string
    {
        return 'https://www.etsy.com/oauth/connect/oauth/authorize?'. \http_build_query([
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
     * @param string $code
     *
     * @return array
     */
    protected function getTokens(string $code): array
    {
        if(empty($this->tokens)) {
            $headers = ['Content-Type: application/x-www-form-urlencoded'];

            $this->tokens = \json_decode($this->request(
                'POST',
                $this->endpoint . '/oauth/token',
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
     * @param string $refreshToken
     *
     * @return array
     */
    public function refreshTokens(string $refreshToken):array
    {
        $headers = ['Content-Type: application/x-www-form-urlencoded'];

        $this->tokens = \json_decode($this->request(
            'POST',
            $this->endpoint . '/oauth/token',
            $headers,
            \http_build_query([
                'grant_type' => 'refresh_token',
                'client_id' => $this->appID,
                'refresh_token' => $refreshToken,
            ])
        ), true);

        if(empty($this->tokens['refresh_token'])) {
            $this->tokens['refresh_token'] = $refreshToken;
        }

        return $this->tokens;
    }

    /**
     * @param $accessToken
     *
     * @return string
     */
    public function getUserID(string $accessToken):string
    {
        $response = $this->getUser($accessToken);

        if (isset($response['bot']['owner']['user']['id'])) {
            return $response['bot']['owner']['user']['id'];
        }

        return '';
    }

    /**
     * @param $accessToken
     *
     * @return string
     */
    public function getUserEmail(string $accessToken):string
    {
        return '';
    }

    /**
     * @param $accessToken
     *
     * @return string
     */
    public function getUserName(string $accessToken):string
    {
        return '';
    }

    /**
     * @param string $accessToken
     *
     * @return array
     */
    protected function getUser(string $accessToken)
    {
        return $this->user;
    }
}

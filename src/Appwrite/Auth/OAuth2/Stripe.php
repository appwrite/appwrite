<?php

namespace Appwrite\Auth\OAuth2;

use Appwrite\Auth\OAuth2;

class Stripe extends OAuth2
{
    /**
     * @var array
     */
    protected $user = [];

    /**
     * @var string
     */
    protected $stripeAccountId = '';

    /**
     * @var array
     */
    protected $scopes = [
        'read_write',
    ];

    /** 
     * @return string
     */

    protected $grantType = [
      'authorize' => 'authorization_code',
      'refresh' => 'refresh_token'
    ];

    /**
     * @return string
     */
    public function getName():string
    {
        return 'stripe';
    }

    /**
     * @return string
     */
    public function getLoginURL():string
    {
        return 'https://connect.stripe.com/oauth/authorize?'. \http_build_query([
            'response_type' => 'code', // The only option at the moment is "code."
            'client_id' => $this->appID,
            'redirect_uri' => $this->callback,
            'scope' => \implode(' ', $this->getScopes()),
            'state' => \json_encode($this->state)
        ]);
    }

    /**
     * @param string $code
     *
     * @return array
     */
    public function getTokens(string $code): array
    {
        $result = $this->request(
            'POST',
            'https://connect.stripe.com/oauth/token',
            [],
            \http_build_query([
                'grant_type' => $this->grantType['authorize'],
                'code' => $code
            ])
        );

        $result = \json_decode($result, true);

        if (isset($result['stripe_user_id'])) {
          $this->stripeAccountId = $result['stripe_user_id'];
        }

        return [
            'access' => $result['access_token'],
            'refresh' => $result['refresh_token']
        ];
    }

    /**
     * @param $accessToken
     *
     * @return string
     */
    public function getUserID(string $accessToken):string
    {
        $user = $this->getUser($accessToken);

        if (isset($user['id'])) {
            return $user['id'];
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
        $user = $this->getUser($accessToken);
        
        if(empty($user)) {
          return '';
        }

        return $user['email'] ?? '';
    }

    /**
     * @param $accessToken
     *
     * @return string
     */
    public function getUserName(string $accessToken):string
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
    protected function getUser(string $accessToken)
    {
        if (empty($this->user) && !empty($this->stripeAccountId)) {
            $this->user = \json_decode(
              $this->request(
                'GET', 
                'https://api.stripe.com/v1/accounts/' . $this->stripeAccountId, 
                ['Authorization: Bearer '.\urlencode($accessToken)]
              ), 
              true
            );

            
        }

        return $this->user;
    }
}

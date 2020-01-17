<?php

namespace Auth\OAuth;

use Auth\OAuth;

// Reference Material
// https://developer.twitter.com/en/docs/basics/authentication/guides/log-in-with-twitter
// Step 1: POST oauth/request_token
// Step 2: GET oauth/authorize
// Step 3: POST oauth/access_token
// Step 4: GET account/verify_credentials

class Twitter extends OAuth
{

    /**
     * @var string
     */
    private $endpoint = 'https://api.twitter.com/oauth/';

    /**
     * will store the token from requestToken
     * @var string
     */
    private $oauthToken = '';

    /**
     * @var array
     */
    protected $user = [];


    private function requestToken():string
    {
        $url = $this->endpoint . 'request_token';
        $time = time();
        $params = [
            'oauth_nonce' => trim(base64_encode($time, '=')),
            'oauth_callback' => $this->callback,
            'oauth_signature_method' => 'HMAC-SHA1',
            'oauth_timestamp' => $time,
            'oauth_consumer_key' => $this->appID,
            'oauth_signature' => $this->appSecret,
            'oauth_version' => '1.0'
        ];
        $header = 'OAuth ' . implode(',', $params);

        $response = $this->request('POST', $url, [$header]);

        if ($response) {

        }

        return '';
    }

    /**
     * @return string
     */
    public function getName():string
    {
        return 'twitter';
    }

    /**
     * @return string
     */
    public function getLoginURL():string
    {
        return $this->endpoint . 'authorize?'.
            http_build_query([
                'oauth_token' => 'code',
            ]);
    }

    /**
     * @param string $code
     *
     * @return string
     */
    public function getAccessToken(string $code):string
    {
        $result = json_decode($this->request(
            'POST',
            $this->endpoint . 'token',
            [],
            http_build_query([
                "client_id" => $this->appID,
                "client_secret" => $this->appSecret,
                "code" => $code,
                "grant_type" => "authorization_code",
                "redirect_uri" => $this->callback
            ])
        ), true);

        if (isset($result['access_token'])) {
            return $result['access_token'];
        }

        return '';
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

        if (isset($user['email'])) {
            return $user['email'];
        }

        return '';
    }

    /**
     * @param $accessToken
     *
     * @return string
     */
    public function getUserName(string $accessToken):string
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
    protected function getUser(string $accessToken)
    {
        if (empty($this->user)) {
            $this->user = json_decode($this->request('GET',
                $this->resourceEndpoint, ['Authorization: Bearer '.urlencode($accessToken)]), true)['data']['0'];
        }

        return $this->user;
    }
}

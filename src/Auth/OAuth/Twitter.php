<?php

namespace Auth\OAuth;

use Auth\OAuth;

// OAuth v1 implementation

// Reference Material
// https://developer.twitter.com/en/docs/basics/authentication/guides/log-in-with-twitter
// Step 1: POST oauth/request_token
// Step 2: GET oauth/authorize
// Step 3: POST oauth/access_token
// Step 4: GET account/verify_credentials

class Twitter extends OAuth
{



    /**
     * @var array
     */
    protected $user = [];


    private $requestTokenURL = 'https://api.twitter.com/oauth/request_token';
    private $accessTokenURL = 'https://api.twitter.com/oauth/access_token';
    private $authorizeURL = 'https://api.twitter.com/oauth/authorize';
    private $showUserURL = 'https://api.twitter.com/1.1/account/verify_credentials.json';

    private $oauthUnauthorizedToken = '';
    private $oauthVerifier = '';
    private $oauthToken = '';
    private $oauthTokenSecret = '';

    private $oauth = [];

    private $method = '';
    private $endpoint = '';


    private function buildOAuthData(array $params = [], bool $isApiRequest = false)
    {
        $time = time();
        $this->oauth = [
            'oauth_nonce' => md5($time),
            'oauth_callback' => $this->callback,
            'oauth_consumer_key' => $this->appID,
            'oauth_signature_method' => 'HMAC-SHA1',
            'oauth_timestamp' => $time,
            'oauth_version' => '1.0'
        ];

        if ($isApiRequest) {
            $this->oauth['oauth_token'] = $this->oauthToken;
        }

        if (!empty($params)) {
            foreach ($params as $k => $v) {
                $this->oauth[$k] = $v;
            }
        }

        ksort($this->oauth);

        $this->oauth['oauth_signature'] = $this->createSignature();

    }

    private function buildOAuthHeader():string
    {
        $buffer = [];
        ksort($this->oauth);
        $oauthAllowedKeys = [
            'oauth_consumer_key',
            'oauth_nonce',
            'oauth_callback',
            'oauth_signature',
            'oauth_signature_method',
            'oauth_timestamp',
            'oauth_token',
            'oauth_version'
        ];
        foreach ($this->oauth as $k => $v) {
            if (in_array($k, $oauthAllowedKeys)) {
                $buffer[] = $k . '="' . rawurlencode($v) . '"';
            }
        }
        return 'Authorization: OAuth ' . implode(', ', $buffer);
    }

    private function createSignatureBaseString():string
    {
        $buffer = [];
        foreach ($this->oauth as $k => $v) {
            $buffer[] = rawurlencode($k) . '=' . rawurlencode($v);
        }

        $parameterString = implode('&', $buffer);

        return strtoupper($this->method) . '&' . rawurlencode($this->endpoint) . '&' . rawurlencode($parameterString);
    }

    private function createSigningKey():string
    {
        return rawurlencode($this->appSecret) . "&" . (empty($this->oauthTokenSecret) ? '' : rawurlencode($this->oauthTokenSecret));
    }

    private function createSignature():string
    {
        return base64_encode(hash_hmac('sha1', $this->createSignatureBaseString(), $this->createSigningKey(), true));
    }


    private function requestOAuthRequestToken()
    {
        $this->method = 'POST';
        $this->endpoint = $this->requestTokenURL;

        $this->buildOAuthData();
        $header = $this->buildOAuthHeader();

        $response = request($this->method, $this->endpoint, [$header]);

        $buffer = [];
        parse_str($response, $buffer);

        if (is_array($buffer)) {
            $this->oauthUnauthorizedToken = $buffer['oauth_token'];
            $this->oauthVerifier = $buffer['oauth_verifier'];
        }
    }

    private function requestOAuthAccessToken()
    {
        $this->method = 'POST';
        $this->endpoint = $this->accessTokenURL;

        $payload = http_build_query([
            'oauth_consumer_key' => $this->appID,
            'oauth_token' => $this->oauthUnauthorizedToken,
            'oauth_verifier' => $this->oauthVerifier
        ]);

        $response = request($this->method, $this->endpoint, [], $payload);

        if ($response) {
            $buffer = [];
            parse_str($response, $buffer);
            $this->oauthToken = $buffer['oauth_token'];
            $this->oauthTokenSecret = $buffer['oauth_token_secret'];
        }

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
        if ( empty($this->oauthToken) ) {
            $this->requestOAuthRequestToken();
            return $this->authorizeURL . "?oauth_token=" . $this->oauthUnauthorizedToken;
        }
        return '';
    }

    /**
     * @param string $code
     *
     * @return string
     */
    public function getAccessToken(string $code):string
    {
        $this->requestOAuthAccessToken();
        $code = '';
        if ( !empty($this->oauthToken) ) {
            return $this->oauthToken;
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
        if ( empty($this->user) ) {
            $this->method = 'GET';
            $this->endpoint = $this->showUserURL;
            $params = [
                'include_email' => 'true',
                'include_entities' => 'true'
            ];
            $this->buildOAuthData($params, true);
            $header = $this->buildOAuthHeader();

            $response = request($this->method, $this->endpoint . '?' . http_build_query($params), [$header]);

            $this->user = json_decode($response, true);
        }

        return $this->user;
    }
}

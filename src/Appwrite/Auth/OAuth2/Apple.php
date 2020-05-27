<?php

namespace Appwrite\Auth\OAuth2;

use Appwrite\Auth\OAuth2;

// Reference Material
// https://developer.okta.com/blog/2019/06/04/what-the-heck-is-sign-in-with-apple

class Apple extends OAuth2
{
    /**
     * @var array
     */
    protected $user = [];

    /**
     * @var array
     */
    protected $scopes = [
        "name", 
        "email"
    ];

    /**
     * @return string
     */
    public function getName(): string
    {
        return 'apple';
    }
    
    /**
     * @return string
     */
    public function getLoginURL(): string
    {
        return 'https://appleid.apple.com/auth/authorize?'.http_build_query([
            'client_id' => $this->appID,
            'redirect_uri' => $this->callback,
            'state' => json_encode($this->state),
            'response_type' => 'code',
            'response_mode' => 'form_post',
            'scope' => implode(' ', $this->getScopes())
        ]);
    }

    /**
     * @param string $code
     *
     * @return string
     */
    public function getAccessToken(string $code): string
    {
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        $accessToken = $this->request(
            'POST',
            'https://appleid.apple.com/auth/token',
            $headers,
            http_build_query([
                'code' => $code,
                'client_id' => $this->appID,
                'client_secret' => $this->appSecret,
                'redirect_uri' => $this->callback,
                'grant_type' => 'authorization_code'
            ])
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

        if (isset($user['name'])) {
            return $user['name']['display_name'];
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
            $headers[] = 'Authorization: Bearer '. urlencode($accessToken);
            $user = $this->request('POST', '', $headers);
            $this->user = json_decode($user, true);
        }

        return $this->user;
    }

    protected function getToken($p8)
    {
        $keyfile = 'AuthKey_AABBCC1234.p8';               # <- Your AuthKey file
        $keyid = '4LFF7TZ6Q5';                            # <- Your Key ID
        $teamid = 'YJHMCSNREU';                           # <- Your Team ID (see Developer Portal)
        $bundleid = 'test2.appwrite.io';                  # <- Your Bundle ID
        $url = 'https://api.development.push.apple.com';  # <- development url, or use http://api.push.apple.com for production environment
        $token = 'e2c48ed32ef9b018........';              # <- Device Token
        
        function base64($data) {
            return rtrim(strtr(base64_encode(json_encode($data)), '+/', '-_'), '=');
        }

        $message = '{"aps":{"alert":"Hi there!","sound":"default"}}';

        $key = openssl_pkey_get_private('file://'.$keyfile);

        $header = ['alg'=>'ES256', 'kid'=>$keyid];
        $claims = ['iss'=>$teamid, 'iat'=>time()];

        $header_encoded = base64($header);
        $claims_encoded = base64($claims);

        $signature = '';
        openssl_sign($header_encoded . '.' . $claims_encoded, $signature, $key, 'sha256');
        $jwt = $header_encoded . '.' . $claims_encoded . '.' . base64_encode($signature);

    }
}

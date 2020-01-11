<?php

namespace Auth;

abstract class OAuth
{
    /**
     * @var string
     */
    protected $appID;

    /**
     * @var string
     */
    protected $appSecret;

    /**
     * @var string
     */
    protected $callback;

    /**
     * @var string
     */
    protected $state;

    /**
     * @var array
     */
    protected $scopes;

    /**
     * OAuth constructor.
     *
     * @param string $appId
     * @param string $appSecret
     * @param string $callback
     * @param array  $state
     * @param array $scope
     */
    public function __construct(string $appId, string $appSecret, string $callback, $state = [], $scopes)
    {
        $this->appID = $appId;
        $this->appSecret = $appSecret;
        $this->callback = $callback;
        $this->state = $state;
        $this->scopes = $scopes;
        var_dump($scopes);
        exit();
    }

    /**
     * @return string
     */
    abstract public function getName():string;

    /**
     * @return string
     */
    abstract public function getLoginURL():string;

    /**
     * @param string $code
     *
     * @return string
     */
    abstract public function getAccessToken(string $code):string;

    /**
     * @param $accessToken
     *
     * @return string
     */
    abstract public function getUserID(string $accessToken):string;

    /**
     * @param $accessToken
     *
     * @return string
     */
    abstract public function getUserEmail(string $accessToken):string;

    /**
     * @param $accessToken
     *
     * @return string
     */
    abstract public function getUserName(string $accessToken):string;


    // The parseState function was designed specifically for Amazon OAuth Adapter to override.
    // The response from Amazon is html encoded and hence it needs to be html_decoded before
    // json_decoding
    /**
     * @param $state
     *
     * @return string
     */
    public function parseState(string $state)
    {
        return json_decode($state, true);
    }

    /**
     * @param string $method
     * @param string $url
     * @param array  $headers
     * @param string $payload
     *
     * @return string
     */
    protected function request(string $method, string $url = '', array $headers = [], string $payload = ''):string
    {
        $ch = curl_init($url);

        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Console_OAuth_Agent');

        if (!empty($payload)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        }

        $headers[] = 'Content-length: '.strlen($payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        // Send the request & save response to $response
        $response = curl_exec($ch);

        curl_close($ch);

        return $response;
    }
}

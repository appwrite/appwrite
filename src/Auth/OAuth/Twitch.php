<?php

namespace Auth\OAuth;

use Auth\OAuth;

// Reference Material
// https://dev.twitch.tv/docs/authentication/getting-tokens-oauth/

class Twitch extends OAuth
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
        return 'twitch';
    }
    
    /**
     * @return string
     */
    public function getLoginURL(): string
    {
        return 'https://id.twitch.tv/oauth2/authorize' .
            '?client_id=' . urlencode($this->appID).
            '&redirect_uri='.urlencode($this->callback).
            '&state=' . urlencode(json_encode($this->state)).
            '&response_type=code'.
            '&scope=read_user';
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
            'https://id.twitch.tv/oauth2/token'.
                '?code='.urlencode($code).
                '&client_id='.urlencode($this->appID).
                '&client_secret='.urlencode($this->appSecret).
                '&redirect_uri='.urlencode($this->callback).
                '&grant_type=authorization_code'
        );

        $accessToken = json_decode($accessToken, true);

        if (isset($accessToken['access_token'])) {
            return $accessToken['access_token'];
        }

        return '';
    }
}

<?php

namespace Auth\OAuth;

use Auth\OAuth;

// Reference Material
// https://www.dropbox.com/developers/reference/oauth-guide
// https://www.dropbox.com/developers/documentation/http/documentation#users-get_current_account 
class Dropbox extends OAuth
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
        return 'dropbox';
    }
    
    /**
     * @return string
     */
    public function getLoginURL(): string
    {
        return 'https://login.microsoftonline.com/common/oauth2/v2.0/authorize?'.
            'client_id='.urlencode($this->appID).
            '&redirect_uri='.urlencode($this->callback).
            '&state='.urlencode(json_encode($this->state)).
            '&scope=offline_access+user.read'.
            '&response_type=code'.
            '&response_mode=query';
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
            'https://login.microsoftonline.com/common/oauth2/v2.0/token',
            $headers,
            'code='.urlencode($code).
            '&client_id='.urlencode($this->appID).
            '&client_secret='.urlencode($this->appSecret).
            '&redirect_uri='.urlencode($this->callback).
            '&scope=offline_access+user.read'.
            '&grant_type=authorization_code'
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
            $headers[] = 'Authorization: Bearer '. urlencode($accessToken);
            $user = $this->request('GET', 'https://graph.microsoft.com/v1.0/me', $headers);
            $this->user = json_decode($user, true);
        }

        return $this->user;
    }
}

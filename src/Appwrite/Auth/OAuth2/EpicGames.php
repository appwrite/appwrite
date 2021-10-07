<?php

namespace Appwrite\Auth\OAuth2;

use Appwrite\Auth\OAuth2;

// Reference Material
// https://dev.epicgames.com/docs/services/en-US/WebAPIRef/AuthWebAPI/index.html

class Epicgames extends OAuth2
{
    /**
     * @var array
     */
    protected $user = [];

    /**
     * @var array
     */
    protected $scopes = [
        'user:email',
    ];
    
    /**
     * @return string
     */
    public function getName(): string
    {
        return 'epicgames';
    }

    /**
     * @return string
     */
    public function getLoginURL(): string
    {
        return 'https://www.epicgames.com/id/authorize?'. \http_build_query([
            'client_id' => $this->appID,
            'redirect_uri' => $this->callback,
            'scope' => \implode(' ', $this->getScopes()),
            'state' => \json_encode($this->state)
        ]);
    }

    /**
     * @param string $code
     *
     * @return string
     */
    public function getAccessToken(string $code): string
    {
        // TODO: Fire request to oauth API to generate access_token
        $accessToken = $this->request(
            'POST',
            'https://api.epicgames.dev/epic/oauth/v1/token',
            [],
            \http_build_query([
                'client_id' => $this->appID,
                'redirect_uri' => $this->callback,
                'client_secret' => $this->appSecret,
                'code' => $code
            ])
        );

        $output = [];

        \parse_str($accessToken, $output);

        if (isset($output['access_token'])) {
            return $output['access_token'];
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
        // TODO: Fetch user from oauth API and select the user ID
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
        // TODO: Fetch user from oauth API and select the user's email
        $emails = \json_decode($this->request('GET', 'https://api.epicgames.dev/epic/id/v1/accounts', ['Authorization: token '.\urlencode($accessToken)]), true);
        
        foreach ($emails as $email) {
            if ($email['primary'] && $email['verified']) {
                return $email['email'];
            }
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
}

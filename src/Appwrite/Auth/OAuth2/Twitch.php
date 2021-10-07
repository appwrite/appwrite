<?php

namespace Appwrite\Auth\OAuth2;

use Appwrite\Auth\OAuth2;

// Reference Material
// https://dev.twitch.tv/docs/authentication

class Twitch extends OAuth2
{

    /**
     * @var string
     */
    private $endpoint = 'https://id.twitch.tv/oauth2/';

    /**
     * @var string
     */
    private $resourceEndpoint = 'https://api.twitch.tv/helix/users';

    /**
     * @var array
     */
    protected $scopes = [
        'user:read:email',
    ];

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
        return $this->endpoint . 'authorize?' .
            \http_build_query([
                'response_type' => 'code',
                'client_id' => $this->appID,
                'scope' => \implode(' ', $this->getScopes()),
                'redirect_uri' => $this->callback,
                'force_verify' => true,
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
        $result = \json_decode($this->request(
            'POST',
            $this->endpoint . 'token?' . \http_build_query([
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
    public function getUserID(string $accessToken): string
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
    public function getUserEmail(string $accessToken): string
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
    public function getUserName(string $accessToken): string
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
            $response = \json_decode($this->request(
                'GET',
                $this->resourceEndpoint,
                [
                    'Authorization: Bearer ' . \urlencode($accessToken),
                    'Client-Id: ' . \urlencode($this->appID)
                ]
            ), true);

            $this->user = $response['data']['0'] ?? [];
        }

        return $this->user;
    }
}

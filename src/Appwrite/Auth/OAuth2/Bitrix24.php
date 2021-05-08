<?php

namespace Appwrite\Auth\OAuth2;

use Appwrite\Auth\OAuth2;

// Reference Material
// https://training.bitrix24.com/rest_help/oauth/authentication.php

class Bitrix24 extends OAuth2
{
    /**
     * @var array
     */
    protected $user = [];

    /**
     * @var array
     */
    protected $scopes = [];

    /**
     * @return string
     */
    public function getName(): string
    {
        return 'bitrix24';
    }

    /**
     * @param $state
     *
     * @return array
     */
    public function parseState(string $state)
    {
        return \json_decode(\html_entity_decode($state), true);
    }


    /**
     * @return string
     */
    public function getLoginURL(): string
    {
      return 'https://itdelta.bitrix24.ru/oauth/authorize/?'.\http_build_query([
              'client_id' => $this->appID,
              'state' => \json_encode($this->state),
          ]);
    }

    /**
     * @param string $code
     *
     * @return string
     */
    public function getAccessToken(string $code): string
    {
        $headers = ['Content-Type: application/x-www-form-urlencoded;charset=UTF-8'];
        $accessToken = $this->request(
            'POST',
            'https://oauth.bitrix.info/oauth/token/',
            $headers,
            \http_build_query([
                'code' => $code,
                'client_id' => $this->appID ,
                'client_secret' => $this->appSecret,
                'grant_type' => 'authorization_code'
            ])
        );

        $accessToken = \json_decode($accessToken, true);

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

        if (isset($user['ID'])) {
            return $user['ID'];
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

        if (isset($user['EMAIL'])) {
            return $user['EMAIL'];
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

        $res = '';
        if (isset($user['NAME'])) {
            $res = $user['NAME'];
        }

        if (isset($user['LAST_NAME'])) {
            $res .= ' '.$user['LAST_NAME'];
        }

        return trim($res);
    }

    /**
     * @param string $accessToken
     *
     * @return array
     */
    protected function getUser(string $accessToken): array
    {
        if (empty($this->user)) {
            $user = $this->request('GET', 'https://itdelta.bitrix24.ru/rest/user.current.json?auth='.\urlencode($accessToken));
            $response = \json_decode($user, true);
            $this->user = $response['result'];
        }
        return $this->user;
    }
}

<?php

namespace Appwrite\Auth\OAuth2;

use Appwrite\Auth\OAuth2;

// Reference Material
// https://www.figma.com/developers/api#authentication

class Figma extends OAuth2
{
    /**
     * @var string
     */
    private string $endpoint = 'https://www.figma.com';

    /**
     * @var string
     */
    private string $resourceEndpoint = 'https://api.figma.com/v1';

    /**
     * @var array
     */
    protected array $user = [];

    /**
     * @var array
     */
    protected array $tokens = [];

    /**
     * @var array
     */
    protected array $scopes = [
        'files:read',
    ];

    /**
     * @return string
     */
    public function getName(): string
    {
        return 'figma';
    }

    public function getLoginURL(): string
    {
        return $this->endpoint . '/oauth?' . \http_build_query([
            'client_id' => $this->appID,
            'redirect_uri' => $this->callback,
            'scope' => \implode(',', $this->getScopes()),
            'state' => \json_encode($this->state),
            'response_type' => 'code'
        ]);
    }
    /**
     * @return string
     */
    protected function getTokens(string $code): array
    {
        if (empty($this->tokens)) {
            $url = $this->endpoint . '/api/oauth/token';
            $postData = http_build_query([
                'client_id' => $this->appID,
                'client_secret' => $this->appSecret,
                'redirect_uri' => $this->callback,
                'code' => $code,
                'grant_type' => 'authorization_code'
            ]);
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

            $response = curl_exec($ch);

            if (curl_errno($ch)) {
                throw new \Exception('Curl error: ' . curl_error($ch));
            }

            curl_close($ch);

            $decodedResponse = \json_decode($response, true);

            if (isset($decodedResponse['error'])) {
                throw new \Exception('Error retrieving tokens: ' . $decodedResponse['error']);
            }

            $this->tokens = $decodedResponse;
        }

        return $this->tokens;
    }



    /**
     * @param string $code
     *
     * @return array
     */


    /**
     * @param string $refreshToken
     *
     * @return array
     */
    public function refreshTokens(string $refreshToken): array
    {
        $url = $this->endpoint . '/api/oauth/refresh';
        $postData = http_build_query([
            'client_id' => $this->appID,
            'client_secret' => $this->appSecret,
            'refresh_token' => $refreshToken
        ]);

        $headers = ['Content-Type: application/x-www-form-urlencoded'];

        $response = $this->request('POST', $url, $headers, $postData);
        $this->tokens = \json_decode($response, true);

        if (isset($this->tokens['error'])) {
            throw new \Exception('Error refreshing tokens: ' . $this->tokens['error']);
        }

        if (empty($this->tokens['refresh_token'])) {
            $this->tokens['refresh_token'] = $refreshToken;
        }

        return $this->tokens;
    }


    /**
     * @param string $accessToken
     *
     * @return string
     */
    public function getUserID(string $accessToken): string
    {
        $user = $this->getUser($accessToken);

        return $user['id'] ?? '';
    }

    /**
     * @param string $accessToken
     *
     * @return string
     */
    public function getUserEmail(string $accessToken): string
    {
        $user = $this->getUser($accessToken);

        return $user['email'] ?? '';
    }

    /**
     * Check if the OAuth email is verified
     *
     * @param string $accessToken
     *
     * @return bool
     */
    public function isEmailVerified(string $accessToken): bool
    {
        // Figma doesn't provide email verification status
        // Assuming email is verified if it's present
        $email = $this->getUserEmail($accessToken);

        return !empty($email);
    }

    /**
     * @param string $accessToken
     *
     * @return string
     */
    public function getUserName(string $accessToken): string
    {
        $user = $this->getUser($accessToken);

        return $user['handle'] ?? '';
    }

    /**
     * @param string $accessToken
     *
     * @return array
     */
    protected function getUser(string $accessToken): array
    {
        if (empty($this->user)) {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $this->resourceEndpoint . '/me');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Authorization: Bearer ' . \urlencode($accessToken),
            ]);

            $user = curl_exec($ch);

            if ($user === false) {
                throw new \Exception('Curl error: ' . curl_error($ch));
            }

            curl_close($ch);

            $this->user = \json_decode($user, true);
        }

        return $this->user;
    }


}

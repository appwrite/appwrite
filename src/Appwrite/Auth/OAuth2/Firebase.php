<?php

namespace Appwrite\Auth\OAuth2;

use Appwrite\Auth\OAuth2;

class Firebase extends OAuth2
{
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
        'https://www.googleapis.com/auth/firebase',
        'https://www.googleapis.com/auth/datastore',
        'https://www.googleapis.com/auth/cloud-platform',
        'https://www.googleapis.com/auth/identitytoolkit',
        'https://www.googleapis.com/auth/userinfo.profile'
    ];

    /**
     * @return string
     */
    public function getName(): string
    {
        return 'firebase';
    }

    /**
     * @return string
     */
    public function getLoginURL(): string
    {
        return 'https://accounts.google.com/o/oauth2/v2/auth?' . \http_build_query([
            'access_type' => 'offline',
            'client_id' => $this->appID,
            'redirect_uri' => $this->callback,
            'scope' => \implode(' ', $this->getScopes()),
            'state' => \json_encode($this->state),
            'response_type' => 'code',
            'prompt' => 'consent',
        ]);
    }

    /**
     * @param string $code
     *
     * @return array
     */
    protected function getTokens(string $code): array
    {
        if (empty($this->tokens)) {
            $response = $this->request(
                'POST',
                'https://oauth2.googleapis.com/token',
                [],
                \http_build_query([
                    'client_id' => $this->appID,
                    'redirect_uri' => $this->callback,
                    'client_secret' => $this->appSecret,
                    'code' => $code,
                    'grant_type' => 'authorization_code'
                ])
            );

            $this->tokens =  \json_decode($response, true);
        }

        return $this->tokens;
    }

    /**
     * @param string $refreshToken
     *
     * @return array
     */
    public function refreshTokens(string $refreshToken): array
    {
        $response = $this->request(
            'POST',
            'https://github.com/login/oauth/access_token',
            [],
            \http_build_query([
                'client_id' => $this->appID,
                'client_secret' => $this->appSecret,
                'grant_type' => 'refresh_token',
                'refresh_token' => $refreshToken
            ])
        );

        $output = [];
        \parse_str($response, $output);
        $this->tokens = $output;

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
     * @link https://docs.github.com/en/rest/users/emails#list-email-addresses-for-the-authenticated-user
     *
     * @param string $accessToken
     *
     * @return bool
     */
    public function isEmailVerified(string $accessToken): bool
    {
        $user = $this->getUser($accessToken);

        if ($user['verified'] ?? false) {
            return true;
        }

        return false;
    }

    /**
     * @param string $accessToken
     *
     * @return string
     */
    public function getUserName(string $accessToken): string
    {
        $user = $this->getUser($accessToken);

        return $user['name'] ?? '';
    }

    /**
     * @param string $accessToken
     *
     * @return array
     */
    protected function getUser(string $accessToken)
    {
        if (empty($this->user)) {
            $response = $this->request(
                'GET',
                'https://www.googleapis.com/oauth2/v1/userinfo?access_token=' . \urlencode($accessToken),
                [],
            );

            $this->user = \json_decode($response, true);
        }

        return $this->user;
    }

    public function getProjects(string $accessToken): array
    {
        $projects = $this->request('GET', 'https://firebase.googleapis.com/v1beta1/projects', ['Authorization: Bearer ' . \urlencode($accessToken)]);

        $projects = \json_decode($projects, true);

        return $projects['results'];
    }

    public function createServiceAccount(string $accessToken, string $projectID): array
    {
        // Create Service Account
        $response = $this->request(
            'POST',
            'https://iam.googleapis.com/v1/projects/' . $projectID . '/serviceAccounts',
            [
                'Authorization: Bearer ' . \urlencode($accessToken),
                'Content-Type: application/json'
            ],
            json_encode([
                'accountId' => 'appwrite-migrations',
                'serviceAccount' => [
                    'displayName' => 'Appwrite Migrations'
                ]
            ])
        );

        $response = json_decode($response, true);

        // Create Service Account Key
        $responseKey = $this->request(
            'POST',
            'https://iam.googleapis.com/v1/projects/' . $projectID . '/serviceAccounts/' . $response['email'] . '/keys',
            [
                'Authorization: Bearer ' . \urlencode($accessToken),
                'Content-Type: application/json'
            ]
        );

        $responseKey = json_decode($responseKey, true);

        return json_decode(base64_decode($responseKey['privateKeyData']), true);
    }
}

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
     * @var array
     */
    protected array $iamPermissions = [
        // Database
        'datastore.databases.get',
        'datastore.databases.list',
        'datastore.entities.get',
        'datastore.entities.list',
        'datastore.indexes.get',
        'datastore.indexes.list',
        // Generic Firebase permissions
        'firebase.projects.get',

        // Auth
        'firebaseauth.configs.get',
        'firebaseauth.configs.getHashConfig',
        'firebaseauth.configs.getSecret',
        'firebaseauth.users.get',
        'identitytoolkit.tenants.get',
        'identitytoolkit.tenants.list',

        // IAM Assignment
        'iam.serviceAccounts.list',

        // Storage
        'storage.buckets.get',
        'storage.buckets.list',
        'storage.objects.get',
        'storage.objects.list'
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
            'https://oauth2.googleapis.com/token',
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

    /*
        Be careful with the setIAMPolicy method, it will overwrite all existing policies
    **/
    public function assignIAMRole(string $accessToken, string $email, string $projectId, array $role)
    {
        // Get IAM Roles
        $iamRoles = $this->request('POST', 'https://cloudresourcemanager.googleapis.com/v1/projects/' . $projectId . ':getIamPolicy', [
            'Authorization: Bearer ' . \urlencode($accessToken),
            'Content-Type: application/json'
        ]);

        $iamRoles = \json_decode($iamRoles, true);

        $iamRoles['bindings'][] = [
            'role' => $role['name'],
            'members' => [
                'serviceAccount:' . $email
            ]
        ];

        // Set IAM Roles
        $this->request('POST', 'https://cloudresourcemanager.googleapis.com/v1/projects/' . $projectId . ':setIamPolicy', [
            'Authorization: Bearer ' . \urlencode($accessToken),
            'Content-Type: application/json'
        ], \json_encode([
            'policy' => $iamRoles
        ]));
    }

    private function generateRandomString($length = 10): string
    {
        $characters = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $charactersLength = strlen($characters);
        $randomString = '';
        for ($i = 0; $i < $length; $i++) {
            $randomString .= $characters[random_int(0, $charactersLength - 1)];
        }
        return $randomString;
    }

    private function createCustomRole(string $accessToken, string $projectId): array
    {
        // Check if role already exists
        try {
            $role = $this->request('GET', 'https://iam.googleapis.com/v1/projects/' . $projectId . '/roles/appwriteMigrations', [
                'Content-Type: application/json',
                'Authorization: Bearer ' . \urlencode($accessToken),
            ]);

            $role = \json_decode($role, true);

            return $role;
        } catch (\Throwable $e) {
            if ($e->getCode() !== 404) {
                throw $e;
            }
        }

        // Create role if doesn't exist or isn't correct
        $role = $this->request(
            'POST',
            'https://iam.googleapis.com/v1/projects/' . $projectId . '/roles/',
            [
                'Content-Type: application/json',
                'Authorization: Bearer ' . \urlencode($accessToken),
            ],
            \json_encode(
                [
                    'roleId' => 'appwriteMigrations',
                    'role' => [
                        'title' => 'Appwrite Migrations',
                        'description' => 'A helper role for Appwrite Migrations',
                        'includedPermissions' => $this->iamPermissions,
                        'stage' => 'GA'
                    ]
                ]
            )
        );

        return json_decode($role, true);
    }

    public function createServiceAccount(string $accessToken, string $projectId): array
    {
        // Create Service Account
        $uid = $this->generateRandomString();

        $response = $this->request(
            'POST',
            'https://iam.googleapis.com/v1/projects/' . $projectId . '/serviceAccounts',
            [
                'Authorization: Bearer ' . \urlencode($accessToken),
                'Content-Type: application/json'
            ],
            \json_encode([
                'accountId' => 'appwrite-' . $uid,
                'serviceAccount' => [
                    'displayName' => 'Appwrite Migrations ' . $uid
                ]
            ])
        );

        $response = json_decode($response, true);

        // Create and assign IAM Roles
        $role = $this->createCustomRole($accessToken, $projectId);

        \sleep(1); // Wait for IAM to propagate changes.

        $this->assignIAMRole($accessToken, $response['email'], $projectId, $role);

        // Create Service Account Key
        $responseKey = $this->request(
            'POST',
            'https://iam.googleapis.com/v1/projects/' . $projectId . '/serviceAccounts/' . $response['email'] . '/keys',
            [
                'Authorization: Bearer ' . \urlencode($accessToken),
                'Content-Type: application/json'
            ]
        );

        $responseKey = json_decode($responseKey, true);

        return json_decode(base64_decode($responseKey['privateKeyData']), true);
    }

    public function cleanupServiceAccounts(string $accessToken, string $projectId)
    {
        // List Service Accounts
        $response = $this->request(
            'GET',
            'https://iam.googleapis.com/v1/projects/' . $projectId . '/serviceAccounts',
            [
                'Authorization: Bearer ' . \urlencode($accessToken),
                'Content-Type: application/json'
            ]
        );

        $response = json_decode($response, true);

        if (empty($response['accounts'])) {
            return false;
        }

        foreach ($response['accounts'] as $account) {
            if (strpos($account['email'], 'appwrite-') !== false) {
                $this->request(
                    'DELETE',
                    'https://iam.googleapis.com/v1/projects/' . $projectId . '/serviceAccounts/' . $account['email'],
                    [
                        'Authorization: Bearer ' . \urlencode($accessToken),
                        'Content-Type: application/json'
                    ]
                );
            }
        }

        return true;
    }
}

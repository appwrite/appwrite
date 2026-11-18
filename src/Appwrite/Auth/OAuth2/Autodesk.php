<?php

namespace Appwrite\Auth\OAuth2;

use Appwrite\Auth\OAuth2;

class Autodesk extends OAuth2
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
        'user-profile:read',
    ];

    /**
     * @return string
     */
    public function getName(): string
    {
        return 'autodesk';
    }

    /**
     * @return string
     */
    public function getLoginURL(): string
    {
        return 'https://developer.api.autodesk.com/authentication/v2/authorize?' . \http_build_query([
            'client_id' => $this->appID,
            'scope' => \implode(' ', $this->getScopes()),
            'state' => \json_encode($this->state),
            'redirect_uri' => $this->callback,
            'response_type' => 'code'
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
            $headers = [
                'Content-Type: application/x-www-form-urlencoded',
                'Authorization: Basic ' . \base64_encode($this->appID . ':' . $this->appSecret),
            ];
            $response = $this->request(
                'POST',
                'https://developer.api.autodesk.com/authentication/v2/token',
                $headers,
                \http_build_query([
                    'redirect_uri' => $this->callback,
                    'code' => $code,
                    'grant_type' => 'authorization_code'
                ])
            );

            $this->tokens = \json_decode($response, true);
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
        $headers = [
            'Content-Type: application/x-www-form-urlencoded',
            'Authorization: Basic ' . \base64_encode($this->appID . ':' . $this->appSecret),
        ];
        $response = $this->request(
            'POST',
            'https://developer.api.autodesk.com/authentication/v2/token',
            $headers,
            \http_build_query([
                'grant_type' => 'refresh_token',
                'refresh_token' => $refreshToken,
            ])
        );

        $this->tokens = \json_decode($response, true);

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

        return $user['userId'] ?? '';
    }

    /**
     * @param string $accessToken
     *
     * @return string
     */
    public function getUserEmail(string $accessToken): string
    {
        $user = $this->getUser($accessToken);

        return $user['emailId'] ?? '';
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

        if ($user['emailVerified'] ?? false) {
            return true;
        }

        return false;
    }

    /**
     * `profileImages` is a flat object keyed by size (sizeX20…sizeX360), not an array.
     *
     * @param string $accessToken
     *
     * @return string
     *
     * @see https://github.com/Autodesk-Forge/forge-api-nodejs-client/blob/master/docs/UserProfile.md
     * @see https://github.com/Autodesk-Forge/forge-api-nodejs-client/blob/master/src/model/UserProfileProfileImages.js
     */
    public function getUserPhoto(string $accessToken): string
    {
        $user = $this->getUser($accessToken);
        $images = $user['profileImages'] ?? [];

        return $images['sizeX360']
            ?? $images['sizeX240']
            ?? $images['sizeX176']
            ?? $images['sizeX160']
            ?? $images['sizeX120']
            ?? $images['sizeX80']
            ?? $images['sizeX58']
            ?? $images['sizeX50']
            ?? $images['sizeX40']
            ?? $images['sizeX20']
            ?? '';
    }

    /**
     * @param string $accessToken
     *
     * @return string
     */
    public function getUserName(string $accessToken): string
    {
        $user = $this->getUser($accessToken);

        return $user['userName'] ?? '';
    }

    /**
     * @param string $accessToken
     *
     * @return array
     */
    protected function getUser(string $accessToken): array
    {
        if (empty($this->user)) {
            $headers = ['Authorization: Bearer ' . \urlencode($accessToken)];
            $user = $this->request('GET', 'https://developer.api.autodesk.com/userprofile/v1/users/@me', $headers);
            $this->user = \json_decode($user, true);
        }

        return $this->user;
    }
}

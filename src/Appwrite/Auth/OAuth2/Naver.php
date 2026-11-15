<?php

namespace Appwrite\Auth\OAuth2;

use Appwrite\Auth\OAuth2;

// Reference Material
// https://developers.naver.com/docs/login/api/api.md
// https://developers.naver.com/docs/login/profile/profile.md
// https://developers.naver.com/docs/login/devguide/devguide.md

class Naver extends OAuth2
{
    /**
     * @var string
     */
    private string $endpoint = 'https://nid.naver.com/oauth2.0/';

    /**
     * @var array
     */
    protected array $user = [];

    /**
     * @var array
     */
    protected array $tokens = [];

    /**
     * Naver has no scope parameter. The consent items are picked on the
     * application in the developer console and the member chooses which of
     * the optional ones to grant.
     *
     * @var array
     */
    protected array $scopes = [];

    /**
     * @var string
     */
    private string $returnedState = '';

    /**
     * @return string
     */
    public function getName(): string
    {
        return 'naver';
    }

    /**
     * @return string
     */
    public function getLoginURL(): string
    {
        return $this->endpoint . 'authorize?' . \http_build_query([
            'response_type' => 'code',
            'client_id' => $this->appID,
            'redirect_uri' => $this->callback,
            'state' => \json_encode($this->state),
        ]);
    }

    /**
     * Naver checks the state again on the code exchange, and the callback
     * builds a fresh provider, so keep the value it returned.
     *
     * @param string $state
     *
     * @return array|null
     */
    public function parseState(string $state): ?array
    {
        $this->returnedState = $state;

        return \json_decode($state, true);
    }

    /**
     * @param string $code
     *
     * @return array
     */
    protected function getTokens(string $code): array
    {
        if (empty($this->tokens)) {
            $this->tokens = $this->parseTokens($this->request(
                'POST',
                $this->endpoint . 'token',
                ['Content-Type: application/x-www-form-urlencoded'],
                \http_build_query([
                    'grant_type' => 'authorization_code',
                    'client_id' => $this->appID,
                    'client_secret' => $this->appSecret,
                    'code' => $code,
                    'state' => $this->returnedState,
                ])
            ));
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
        $this->tokens = $this->parseTokens($this->request(
            'POST',
            $this->endpoint . 'token',
            ['Content-Type: application/x-www-form-urlencoded'],
            \http_build_query([
                'grant_type' => 'refresh_token',
                'client_id' => $this->appID,
                'client_secret' => $this->appSecret,
                'refresh_token' => $refreshToken,
            ])
        ));

        // Naver renews the access token without repeating the refresh token
        if (empty($this->tokens['refresh_token'])) {
            $this->tokens['refresh_token'] = $refreshToken;
        }

        return $this->tokens;
    }

    /**
     * Naver answers a rejected token request with an `error` body under an
     * HTTP 200, so the base request() error handling never sees it.
     *
     * @param string $response
     *
     * @return array
     */
    private function parseTokens(string $response): array
    {
        $tokens = \json_decode($response, true);

        if (!\is_array($tokens) || isset($tokens['error'])) {
            throw new Exception($response, 400);
        }

        return $tokens;
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
     * Naver reports nothing about the address on the profile, so it cannot be
     * treated as verified.
     *
     * @param string $accessToken
     *
     * @return bool
     */
    public function isEmailVerified(string $accessToken): bool
    {
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

        // The member name and the nickname are separate consent items, either
        // of which can be declined on its own
        return $user['name'] ?? $user['nickname'] ?? '';
    }

    /**
     * @param string $accessToken
     *
     * @return string
     */
    public function getUserPhoto(string $accessToken): string
    {
        $user = $this->getUser($accessToken);

        return $user['profile_image'] ?? '';
    }

    /**
     * @param string $accessToken
     *
     * @return array
     */
    protected function getUser(string $accessToken): array
    {
        if (empty($this->user)) {
            $response = \json_decode($this->request(
                'GET',
                'https://openapi.naver.com/v1/nid/me',
                ['Authorization: Bearer ' . $accessToken]
            ), true);

            if (!\is_array($response['response'] ?? null)) {
                throw new Exception('Naver did not return valid user information.', 400);
            }

            $this->user = $response['response'];
        }

        return $this->user;
    }
}

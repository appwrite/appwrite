<?php

namespace Appwrite\Auth\OAuth2;

use Appwrite\Auth\OAuth2;

// Reference Material
// https://developers.line.biz/en/docs/line-login/integrate-line-login/
// https://developers.line.biz/en/reference/line-login/

class Line extends OAuth2
{
    use PKCE;

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
        'openid',
        'profile',
        'email',
    ];

    /**
     * @return string
     */
    public function getName(): string
    {
        return 'line';
    }

    /**
     * @return string
     */
    public function getLoginURL(): string
    {
        $state = $this->withPKCEState($this->state);

        return 'https://access.line.me/oauth2/v2.1/authorize?' . \http_build_query([
            'response_type' => 'code',
            'client_id' => $this->appID,
            'redirect_uri' => $this->callback,
            'scope' => \implode(' ', $this->getScopes()),
            'state' => \json_encode($state),
            'code_challenge' => $this->getPKCEChallenge(),
            'code_challenge_method' => 'S256',
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
            $this->tokens = $this->parseTokens($this->request(
                'POST',
                'https://api.line.me/oauth2/v2.1/token',
                ['Content-Type: application/x-www-form-urlencoded'],
                \http_build_query([
                    'grant_type' => 'authorization_code',
                    'client_id' => $this->appID,
                    'client_secret' => $this->appSecret,
                    'redirect_uri' => $this->callback,
                    'code_verifier' => $this->getPKCEVerifier(),
                    'code' => $code,
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
            'https://api.line.me/oauth2/v2.1/token',
            ['Content-Type: application/x-www-form-urlencoded'],
            \http_build_query([
                'grant_type' => 'refresh_token',
                'client_id' => $this->appID,
                'client_secret' => $this->appSecret,
                'refresh_token' => $refreshToken,
            ])
        ));

        if (empty($this->tokens['refresh_token'])) {
            $this->tokens['refresh_token'] = $refreshToken;
        }

        return $this->tokens;
    }

    /**
     * @param string $response
     *
     * @return array
     *
     * @throws Exception
     */
    private function parseTokens(string $response): array
    {
        $tokens = \json_decode($response, true);

        if (!\is_array($tokens) || empty($tokens['access_token'])) {
            throw new Exception($response, 424);
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

        return $user['sub'] ?? '';
    }

    /**
     * LINE only returns the email inside the ID token, and only for channels
     * approved for the email permission.
     *
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
     * LINE does not say whether the email is verified.
     *
     * @link https://developers.line.biz/en/reference/line-login/#verify-id-token
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

        return $user['name'] ?? '';
    }

    /**
     * @param string $accessToken
     *
     * @return string
     */
    public function getUserPhoto(string $accessToken): string
    {
        $user = $this->getUser($accessToken);

        return $user['picture'] ?? '';
    }

    /**
     * Claims come from verifying the ID token returned with the access token.
     *
     * @param string $accessToken
     *
     * @return array
     */
    protected function getUser(string $accessToken): array
    {
        if (empty($this->user)) {
            $idToken = $this->tokens['id_token'] ?? '';

            if (empty($idToken)) {
                return [];
            }

            $this->user = \json_decode($this->request(
                'POST',
                'https://api.line.me/oauth2/v2.1/verify',
                ['Content-Type: application/x-www-form-urlencoded'],
                \http_build_query([
                    'id_token' => $idToken,
                    'client_id' => $this->appID,
                ])
            ), true) ?? [];
        }

        return $this->user;
    }

    /**
     * Extract the PKCE verifier from the state on the callback so the same
     * value generated in getLoginURL() can be sent to the token endpoint.
     *
     * @param string $state
     *
     * @return array<string, mixed>|null
     */
    public function parseState(string $state): ?array
    {
        $parsed = \json_decode($state, true);

        if (!\is_array($parsed)) {
            return null;
        }

        return $this->restorePKCEState($parsed);
    }
}

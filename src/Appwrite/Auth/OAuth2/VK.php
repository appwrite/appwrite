<?php

namespace Appwrite\Auth\OAuth2;

use Appwrite\Auth\OAuth2;

// Reference Material
// https://id.vk.com/about/business/go/docs/ru/vkid/latest/vk-id/connection/api-integration/api-description

class VK extends OAuth2
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
        'vkid.personal_info',
        'email',
    ];

    /**
     * The state as sent to VK, which the token request has to repeat.
     */
    private string $encodedState = '';

    /**
     * @return string
     */
    public function getName(): string
    {
        return 'vk';
    }

    /**
     * @return string
     */
    public function getLoginURL(): string
    {
        // VK only accepts letters, digits, underscores and hyphens in the state
        $state = $this->base64UrlEncode((string) \json_encode($this->withPKCEState($this->state)));

        return 'https://id.vk.ru/authorize?' . \http_build_query([
            'response_type' => 'code',
            'client_id' => $this->appID,
            'redirect_uri' => $this->callback,
            'scope' => \implode(' ', $this->getScopes()),
            'state' => $state,
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
                'https://id.vk.ru/oauth2/auth',
                ['Content-Type: application/x-www-form-urlencoded'],
                \http_build_query([
                    'grant_type' => 'authorization_code',
                    'client_id' => $this->appID,
                    'client_secret' => $this->appSecret,
                    'redirect_uri' => $this->callback,
                    'code_verifier' => $this->getPKCEVerifier(),
                    'device_id' => $this->deviceId,
                    'state' => $this->encodedState,
                    'code' => $code,
                ])
            ), $this->deviceId);
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
        [$deviceId, $token] = \array_pad(\explode(':', $refreshToken, 2), 2, '');

        $this->tokens = $this->parseTokens($this->request(
            'POST',
            'https://id.vk.ru/oauth2/auth',
            ['Content-Type: application/x-www-form-urlencoded'],
            \http_build_query([
                'grant_type' => 'refresh_token',
                'client_id' => $this->appID,
                'client_secret' => $this->appSecret,
                'device_id' => $deviceId,
                'state' => \bin2hex(\random_bytes(16)),
                'refresh_token' => $token,
            ])
        ), $deviceId);

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

        return (string) ($user['user_id'] ?? '');
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
     * VK does not report whether the email address is verified
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

        return \trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
    }

    /**
     * @param string $accessToken
     *
     * @return string
     */
    public function getUserPhoto(string $accessToken): string
    {
        $user = $this->getUser($accessToken);

        return $user['avatar'] ?? '';
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
                'POST',
                'https://id.vk.ru/oauth2/user_info',
                ['Content-Type: application/x-www-form-urlencoded'],
                \http_build_query([
                    'client_id' => $this->appID,
                    'access_token' => $accessToken,
                ])
            ), true);

            $this->user = $response['user'] ?? [];
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
        $parsed = \json_decode((string) \base64_decode(\strtr($state, '-_', '+/')), true);

        if (!\is_array($parsed)) {
            return null;
        }

        $this->encodedState = $state;

        return $this->restorePKCEState($parsed);
    }

    /**
     * VK refuses a refresh without the device the tokens were issued to, so the
     * device ID is kept with the refresh token.
     *
     * @param string $response
     * @param string $deviceId
     *
     * @return array
     */
    private function parseTokens(string $response, string $deviceId): array
    {
        $tokens = \json_decode($response, true);

        if (!\is_array($tokens) || empty($tokens['access_token'])) {
            throw new Exception($response, 424);
        }

        if (!empty($tokens['refresh_token'])) {
            $tokens['refresh_token'] = $deviceId . ':' . $tokens['refresh_token'];
        }

        return $tokens;
    }
}

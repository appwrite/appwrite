<?php

namespace Appwrite\Auth\OAuth2;

use Appwrite\Auth\OAuth2;

// Reference Material
// https://discordapp.com/developers/docs/topics/oauth2

class Discord extends OAuth2
{
    /**
     * @var string
     */
    private string $endpoint = 'https://discordapp.com/api';

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
        'identify',
        'email'
    ];

    /**
     * @return string
     */
    public function getName(): string
    {
        return 'discord';
    }

    /**
     * @return string
     */
    public function getLoginURL(): string
    {
        $url = $this->endpoint . '/oauth2/authorize?' .
            \http_build_query([
                'response_type' => 'code',
                'client_id' => $this->appID,
                'state' => \json_encode($this->state),
                'scope' => \implode(' ', $this->getScopes()),
                'redirect_uri' => $this->callback,
                'prompt' => $this->getPrompt() ?: null,
            ]);

        return $url;
    }

    /**
     * @param string $code
     *
     * @return array
     */
    protected function getTokens(string $code): array
    {
        if (empty($this->tokens)) {
            $this->tokens = \json_decode($this->request(
                'POST',
                $this->endpoint . '/oauth2/token',
                ['Content-Type: application/x-www-form-urlencoded'],
                \http_build_query([
                    'grant_type' => 'authorization_code',
                    'code' => $code,
                    'redirect_uri' => $this->callback,
                    'client_id' => $this->appID,
                    'client_secret' => $this->getClientSecret(),
                    'scope' => \implode(' ', $this->getScopes())
                ])
            ), true);
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
        $this->tokens = \json_decode($this->request(
            'POST',
            $this->endpoint . '/oauth2/token',
            ['Content-Type: application/x-www-form-urlencoded'],
            \http_build_query([
                'grant_type' => 'refresh_token',
                'refresh_token' => $refreshToken,
                'client_id' => $this->appID,
                'client_secret' => $this->getClientSecret(),
            ])
        ), true);

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
     * @link https://discord.com/developers/docs/resources/user
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
    public function getUserPhoto(string $accessToken): string
    {
        $user = $this->getUser($accessToken);
        $id = $user['id'] ?? '';
        $avatar = $user['avatar'] ?? '';

        if ($id === '') {
            return '';
        }

        if ($avatar !== '') {
            $extension = \str_starts_with($avatar, 'a_') ? 'gif' : 'png';

            return 'https://cdn.discordapp.com/avatars/' . $id . '/' . $avatar . '.' . $extension . '?size=512';
        }

        $discriminator = $user['discriminator'] ?? '0';

        if ($discriminator === '0') {
            $index = (\intval($id) >> 22) % 6;
        } else {
            $index = (int) $discriminator % 5;
        }

        return 'https://cdn.discordapp.com/embed/avatars/' . $index . '.png';
    }

    /**
     * @param string $accessToken
     *
     * @return string
     */
    public function getUserName(string $accessToken): string
    {
        $user = $this->getUser($accessToken);

        return $user['username'] ?? '';
    }

    /**
     * @param string $accessToken
     *
     * @return array
     */
    protected function getUser(string $accessToken): array
    {
        if (empty($this->user)) {
            $user = $this->request(
                'GET',
                $this->endpoint . '/users/@me',
                ['Authorization: Bearer ' . \urlencode($accessToken)]
            );
            $this->user = \json_decode($user, true);
        }

        return $this->user;
    }

    /**
     * Extracts the Client Secret from the JSON stored in appSecret
     *
     * @return string
     */
    protected function getClientSecret(): string
    {
        $secret = $this->getAppSecret();

        return $secret['clientSecret'] ?? $this->appSecret;
    }

    /**
     * Extracts the prompt values from the JSON stored in appSecret
     *
     * @return string
     */
    protected function getPrompt(): string
    {
        $secret = $this->getAppSecret();

        return \implode(' ', $secret['prompt'] ?? []);
    }

    /**
     * Decode the JSON stored in appSecret.
     * Falls back to treating the raw string as the client secret for backwards compatibility.
     *
     * @return array
     */
    protected function getAppSecret(): array
    {
        try {
            $secret = \json_decode($this->appSecret, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $th) {
            return ['clientSecret' => $this->appSecret];
        }

        if (!\is_array($secret)) {
            return ['clientSecret' => $this->appSecret];
        }

        return $secret;
    }
}

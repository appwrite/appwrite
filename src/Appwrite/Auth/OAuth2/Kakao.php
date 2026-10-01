<?php

namespace Appwrite\Auth\OAuth2;

use Appwrite\Auth\OAuth2;
use Utopia\Client\Adapter\Curl\Client as CurlAdapter;
use Utopia\Client\Client;
use Utopia\Psr7\ContentType;
use Utopia\Psr7\Header;
use Utopia\Psr7\Method;
use Utopia\Psr7\Request\Factory as RequestFactory;

// Reference Material
// https://developers.kakao.com/docs/latest/en/kakaologin/common
// https://developers.kakao.com/docs/latest/en/kakaologin/rest-api
// https://developers.kakao.com/docs/latest/en/kakaologin/trouble-shooting

class Kakao extends OAuth2
{
    /**
     * @var string
     */
    private string $endpoint = 'https://kauth.kakao.com/oauth/';

    /**
     * @var array
     */
    protected array $user = [];

    /**
     * @var array
     */
    protected array $tokens = [];

    /**
     * Kakao asks for the consent items configured on the app when no scope is
     * requested, and rejects the authorization (KOE205) as soon as a scope is
     * not activated there, so nothing is requested by default. Extra consent
     * items can still be passed per request.
     *
     * @var array
     */
    protected array $scopes = [];

    /**
     * @return string
     */
    public function getName(): string
    {
        return 'kakao';
    }

    /**
     * @return string
     */
    public function getLoginURL(): string
    {
        $query = [
            'client_id' => $this->appID,
            'redirect_uri' => $this->callback,
            'response_type' => 'code',
            'state' => \json_encode($this->state),
            'prompt' => $this->getPrompt() ?: null,
        ];

        $scopes = $this->getScopes();
        if (!empty($scopes)) {
            $query['scope'] = \implode(',', $scopes);
        }

        return $this->endpoint . 'authorize?' . \http_build_query($query);
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
                $this->endpoint . 'token',
                ['Content-Type: application/x-www-form-urlencoded;charset=utf-8'],
                \http_build_query([
                    'grant_type' => 'authorization_code',
                    'client_id' => $this->appID,
                    'client_secret' => $this->getClientSecret(),
                    'redirect_uri' => $this->callback,
                    'code' => $code,
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
            $this->endpoint . 'token',
            ['Content-Type: application/x-www-form-urlencoded;charset=utf-8'],
            \http_build_query([
                'grant_type' => 'refresh_token',
                'client_id' => $this->appID,
                'client_secret' => $this->getClientSecret(),
                'refresh_token' => $refreshToken,
            ])
        ), true);

        // Kakao only returns a new refresh token when the current one is
        // about to expire
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

        return isset($user['id']) ? (string)$user['id'] : '';
    }

    /**
     * The email is only present when the account has the email consent item
     * granted.
     *
     * @param string $accessToken
     *
     * @return string
     */
    public function getUserEmail(string $accessToken): string
    {
        $user = $this->getUser($accessToken);

        return $user['kakao_account']['email'] ?? '';
    }

    /**
     * Check if the OAuth email is verified
     *
     * Kakao reports both whether the address is verified and whether it is
     * still valid; an unverified or invalidated address must not be trusted.
     *
     * @param string $accessToken
     *
     * @return bool
     */
    public function isEmailVerified(string $accessToken): bool
    {
        $user = $this->getUser($accessToken);

        if (empty($user['kakao_account']['email'])) {
            return false;
        }

        return ($user['kakao_account']['is_email_verified'] ?? false) === true
            && ($user['kakao_account']['is_email_valid'] ?? false) === true;
    }

    /**
     * @param string $accessToken
     *
     * @return string
     */
    public function getUserName(string $accessToken): string
    {
        $user = $this->getUser($accessToken);

        return $user['kakao_account']['profile']['nickname'] ?? '';
    }

    /**
     * @param string $accessToken
     *
     * @return string
     */
    public function getUserPhoto(string $accessToken): string
    {
        $user = $this->getUser($accessToken);

        return $user['kakao_account']['profile']['profile_image_url'] ?? '';
    }

    /**
     * @param string $accessToken
     *
     * @return array
     */
    protected function getUser(string $accessToken): array
    {
        if (empty($this->user)) {
            $user = \json_decode($this->request(
                'GET',
                'https://kapi.kakao.com/v2/user/me',
                ['Authorization: Bearer ' . $accessToken]
            ), true);

            $this->user = \is_array($user) ? $user : [];
        }

        return $this->user;
    }

    public function verifyCredentials(): void
    {
        $response = (new Client(new CurlAdapter()))
            ->withTimeout(15)
            ->withFollowRedirects(maxHops: 5)
            ->sendRequest((new RequestFactory())->form(
                Method::POST,
                $this->endpoint . 'token',
                [
                    'grant_type' => 'authorization_code',
                    'client_id' => $this->appID,
                    'client_secret' => $this->getClientSecret(),
                    'redirect_uri' => 'https://invalid.appwrite.callback/intentionally-invalid',
                    'code' => 'intentionally-invalid-code',
                ],
                [
                    Header::CONTENT_TYPE => ContentType::FORM_URLENCODED . ';charset=utf-8',
                ],
            ));

        $json = \json_decode((string) $response->getBody(), true);

        // KOE010, raised before the authorization code is looked at
        if (isset($json['error']) && $json['error'] === 'invalid_client') {
            throw new \Exception('Kakao application with the provided REST API key and/or Client Secret is invalid.');
        }

        // We still expect an error, like invalid_grant or invalid_request,
        // but that indicates valid credentials
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

        return \implode(',', $secret['prompt'] ?? []);
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

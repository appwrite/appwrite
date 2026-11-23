<?php

namespace Appwrite\Platform\Modules\Mock\Http\Mock\Distribution\Google\Token;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\Platform\Modules\Mock\Http\Mock\Distribution\Google\Base;
use Appwrite\Utopia\Response;
use Utopia\Cache\Cache;
use Utopia\System\System;
use Utopia\Validator\Text;
use Utopia\Validator\WhiteList;

class Create extends Base
{
    private const string ALGORITHM = 'RS256';
    private const int SKEW = 60;
    private const string INVALID_SIGNATURE = 'Invalid JWT Signature.';
    private const string INVALID_TIMEFRAME = 'Invalid JWT: Token must be a short-lived token (60 minutes) and in a reasonable timeframe. Check your iat and exp values in the JWT claim.';
    private const string INVALID_AUDIENCE = 'Invalid JWT: Failed audience check.';
    private const string INVALID_SCOPE = 'Invalid JWT: Missing required scope.';

    public static function getName(): string
    {
        return 'createDistributionGoogleToken';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath(self::PREFIX . '/token')
            ->desc('Create Google OAuth2 access token')
            ->groups(['mock'])
            ->label('scope', 'public')
            ->label('docs', false)
            ->label('mock', true)
            ->param('grant_type', '', new WhiteList([self::GRANT], true), 'OAuth2 grant type.')
            ->param('assertion', '', new Text(0), 'Signed RS256 JWT assertion.')
            ->inject('response')
            ->inject('cache')
            ->callback($this->action(...));
    }

    public function action(string $grantType, string $assertion, Response $response, Cache $cache): void
    {
        $isDevelopment = System::getEnv('_APP_ENV', 'production') === 'development';

        if (!$isDevelopment) {
            throw new Exception(Exception::GENERAL_NOT_IMPLEMENTED);
        }

        $segments = \explode('.', $assertion);

        if (\count($segments) !== 3) {
            $this->grantError($response, self::INVALID_SIGNATURE);
            return;
        }

        [$header, $payload, $signature] = $segments;
        $headerClaims = $this->decode($header);
        $claims = $this->decode($payload);

        if ($headerClaims === null || $claims === null || ($headerClaims['alg'] ?? '') !== self::ALGORITHM || !\is_string($claims['iss'] ?? null)) {
            $this->grantError($response, self::INVALID_SIGNATURE);
            return;
        }

        $account = $cache->load($this->key(self::RESOURCE_ACCOUNT, $claims['iss']), self::TTL);
        $signature = \base64_decode(\strtr($signature, '-_', '+/'), true);

        if (
            !\is_array($account)
            || $signature === false
            || \openssl_verify($header . '.' . $payload, $signature, (string) $account['publicKey'], OPENSSL_ALGO_SHA256) !== 1
        ) {
            $this->grantError($response, self::INVALID_SIGNATURE);
            return;
        }

        if (($claims['aud'] ?? '') !== self::AUDIENCE) {
            $this->grantError($response, self::INVALID_AUDIENCE);
            return;
        }

        $scope = \is_string($claims['scope'] ?? null) ? $claims['scope'] : '';

        if (!\in_array(self::SCOPE, \explode(' ', $scope), true)) {
            $this->grantError($response, self::INVALID_SCOPE);
            return;
        }

        $issuedAt = $claims['iat'] ?? null;
        $expiresAt = $claims['exp'] ?? null;
        $now = \time();

        if (
            !\is_int($issuedAt)
            || !\is_int($expiresAt)
            || $expiresAt <= $issuedAt
            || $expiresAt - $issuedAt > self::TOKEN_LIFETIME
            || $expiresAt < $now
            || $issuedAt > $now + self::SKEW
        ) {
            $this->grantError($response, self::INVALID_TIMEFRAME);
            return;
        }

        $token = \bin2hex(\random_bytes(32));

        $cache->save($this->key(self::RESOURCE_TOKEN, $token), $claims['iss'], '', self::TOKEN_LIFETIME);

        $response->json([
            'access_token' => $token,
            'scope' => $scope,
            'token_type' => 'Bearer',
            'expires_in' => self::TOKEN_LIFETIME,
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decode(string $segment): ?array
    {
        $json = \base64_decode(\strtr($segment, '-_', '+/'), true);

        if ($json === false) {
            return null;
        }

        $decoded = \json_decode($json, true);

        return \is_array($decoded) ? $decoded : null;
    }
}

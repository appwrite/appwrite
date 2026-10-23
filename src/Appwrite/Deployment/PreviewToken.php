<?php

namespace Appwrite\Deployment;

use Ahc\Jwt\JWT;
use Ahc\Jwt\JWTException;
use Appwrite\Extend\Exception;
use Utopia\System\System;

/**
 * What the preview cookie holds for a protected deployment preview.
 *
 * The console proves who is visiting with one of its own JWTs; that JWT
 * authenticates its holder on the whole console API, and the cookie it
 * would sit in travels with every request to the preview, which a site
 * renders server-side with the request headers in hand. So the JWT is
 * exchanged here for a token that is good for nothing but this preview:
 * signed with a key of its own, bound to the host it was issued for, and
 * expiring with the JWT it came from.
 */
final class PreviewToken
{
    private const string CONSOLE = 'console';

    /**
     * Exchange a console JWT for a token authorizing previews on $host.
     *
     * @throws Exception when the JWT is not a console session JWT
     */
    public static function issue(string $jwt, string $host): string
    {
        try {
            $payload = (new JWT(self::consoleKey(), 'HS256', 3600, 0))->decode($jwt);
        } catch (JWTException $error) {
            throw new Exception(Exception::USER_JWT_INVALID, 'Failed to verify JWT. ' . $error->getMessage());
        }

        $userId = (string) ($payload['userId'] ?? '');
        $sessionId = (string) ($payload['sessionId'] ?? '');
        if (($payload['projectId'] ?? '') !== self::CONSOLE || $userId === '' || $sessionId === '') {
            throw new Exception(Exception::USER_JWT_INVALID, 'Previews are authorized with a console session JWT.');
        }

        return (new JWT(self::key(), 'HS256', 3600, 0))->encode([
            'host' => $host,
            'userId' => $userId,
            'sessionId' => $sessionId,
            'exp' => (int) ($payload['exp'] ?? \time()),
        ]);
    }

    /**
     * The user and session a token authorizes on $host, or null when it is
     * not a valid token for that host.
     *
     * @return array{userId: string, sessionId: string}|null
     */
    public static function verify(string $token, string $host): ?array
    {
        if ($token === '') {
            return null;
        }

        try {
            $payload = (new JWT(self::key(), 'HS256', 3600, 0))->decode($token);
        } catch (\Throwable) {
            return null;
        }

        if (($payload['host'] ?? '') !== $host) {
            return null;
        }

        $userId = (string) ($payload['userId'] ?? '');
        $sessionId = (string) ($payload['sessionId'] ?? '');
        if ($userId === '' || $sessionId === '') {
            return null;
        }

        return ['userId' => $userId, 'sessionId' => $sessionId];
    }

    private static function consoleKey(): string
    {
        return System::getEnv('_APP_OPENSSL_KEY_V1', '');
    }

    /**
     * Derived from the platform key but not equal to it, so a preview token
     * fails verification wherever a console JWT or ephemeral key is accepted.
     */
    private static function key(): string
    {
        return \hash('sha256', 'preview:' . self::consoleKey());
    }
}

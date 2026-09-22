<?php

namespace Appwrite\Deployment;

use Ahc\Jwt\JWT;
use Utopia\System\System;

/**
 * Short-lived, self-contained presigned token for a deployment artifact.
 *
 * Signed with `_APP_OPENSSL_KEY_V1` (HS256), it lets the jobs-service sidecar
 * hit the deployment source-download endpoint without a session or API key. The
 * token binds the deployment id and the artifact type ("source"), so a leaked
 * token cannot be repurposed for another deployment or artifact.
 */
final class Token
{
    public const TYPE_SOURCE = 'source';

    public static function sign(string $deploymentId, string $type, int $ttl): string
    {
        return (new JWT(self::key(), 'HS256', $ttl))->encode([
            'deploymentId' => $deploymentId,
            'type' => $type,
        ]);
    }

    /**
     * Verify a token against the expected deployment id and artifact type.
     * Returns true only when the signature, expiry, deployment id and type
     * all match.
     */
    public static function verify(string $token, string $deploymentId, string $type): bool
    {
        if ($token === '') {
            return false;
        }

        try {
            $payload = (new JWT(self::key(), 'HS256'))->decode($token);
        } catch (\Throwable) {
            return false;
        }

        return ($payload['deploymentId'] ?? '') === $deploymentId
            && ($payload['type'] ?? '') === $type;
    }

    private static function key(): string
    {
        return System::getEnv('_APP_OPENSSL_KEY_V1', '');
    }
}

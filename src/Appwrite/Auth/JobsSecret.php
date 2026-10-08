<?php

declare(strict_types=1);

namespace Appwrite\Auth;

/**
 * HMAC secret for jobs-service callbacks to `/v1/jobs/event`.
 *
 * Empty values and the public shipped placeholder are never treated as
 * configured: verification fails closed so a known default cannot authenticate.
 * The digest matches OpenRuntimes\Orchestrator\Callback\Signature (`sha256=` + HMAC-SHA256).
 */
final class JobsSecret
{
    public const string PLACEHOLDER = 'your-secret-key';

    public static function isInsecure(?string $secret): bool
    {
        return $secret === null || $secret === '' || $secret === self::PLACEHOLDER;
    }

    public static function verify(string $body, string $signature, ?string $secret): bool
    {
        if ($secret === null || self::isInsecure($secret) || $signature === '') {
            return false;
        }

        $expected = 'sha256=' . \hash_hmac('sha256', $body, $secret);

        return \hash_equals($expected, $signature);
    }
}

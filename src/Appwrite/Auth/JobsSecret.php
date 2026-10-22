<?php

declare(strict_types=1);

namespace Appwrite\Auth;

use Utopia\Auth\Proofs\Token;
use Utopia\Console\Console;

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

    /**
     * The value the repository's own .env carries for development. It is as
     * public as the placeholder; it stays accepted so a checkout works, and
     * production refuses it.
     */
    public const string DEVELOPMENT = '41a51c959d1027bd9f40e86217ddad25f4567b4e07af16f54a1c556bad7ddaf8';

    public static function isInsecure(?string $secret): bool
    {
        return $secret === null || $secret === '' || $secret === self::PLACEHOLDER;
    }

    /**
     * @throws \RuntimeException
     */
    public static function assertProduction(string $env, ?string $secret): void
    {
        if ($env !== 'production') {
            return;
        }

        if ($secret === null || $secret === '') {
            throw new \RuntimeException('_APP_JOBS_SECRET is missing. Set a unique secret before running in production.');
        }

        if ($secret === self::PLACEHOLDER || $secret === self::DEVELOPMENT) {
            Console::warning('_APP_JOBS_SECRET is a public value. Anyone can forge build callbacks to /v1/jobs/event and drive the jobs API.');
        }
    }

    public static function resolve(string $secret): string
    {
        if (!self::isInsecure($secret)) {
            return $secret;
        }

        return (new Token())->generate();
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

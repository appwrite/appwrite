<?php

declare(strict_types=1);

namespace Appwrite\Auth;

use Utopia\Auth\Proofs\Token;
use Utopia\Console\Console;

/**
 * Bearer secret Appwrite presents to the executor, which creates and drives
 * runtime containers for every project on the host. Nothing is encrypted
 * with it, so it can be rotated at any time; the shipped placeholder is
 * never acceptable.
 */
final class ExecutorSecret
{
    public const string PLACEHOLDER = 'your-secret-key';

    public static function isInsecure(?string $secret): bool
    {
        return $secret === null || $secret === '' || $secret === self::PLACEHOLDER;
    }

    /**
     * The configured secret, or a fresh one when it is empty or the
     * placeholder: on a fresh install and on an upgrade alike, since nothing
     * depends on the old value.
     */
    public static function resolve(string $secret): string
    {
        if (!self::isInsecure($secret)) {
            return $secret;
        }

        return (new Token())->generate();
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
            throw new \RuntimeException('_APP_EXECUTOR_SECRET is missing. Set a unique secret before running in production.');
        }

        if ($secret === self::PLACEHOLDER) {
            Console::warning('_APP_EXECUTOR_SECRET is the public default "' . self::PLACEHOLDER . '". Anything that reaches the executor can create and drive runtime containers for every project.');
        }
    }
}

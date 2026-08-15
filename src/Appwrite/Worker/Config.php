<?php

namespace Appwrite\Worker;

use Appwrite\Event\Event;
use Utopia\System\System;

final class Config
{
    /**
     * Worker Action names that a combined worker consumes.
     *
     * @var list<string>
     */
    public const array NAMES = [
        'webhooks',
        'deletes',
        'databases',
        'builds',
        'jobs',
        'screenshots',
        'certificates',
        'functions',
        'mails',
        'notifications',
        'messaging',
        'migrations',
    ];

    /**
     * Per-queue coroutine caps. Databases must stay at 1 to avoid deadlocks.
     * Omit → 1, never 8.
     *
     * @var array<string, int>
     */
    public const array COROUTINES = [
        'databases' => 1,
        'mails' => 1,
        'messaging' => 1,
        'migrations' => 1,
        'notifications' => 1,
        'webhooks' => 8,
        'deletes' => 8,
        'builds' => 8,
        'jobs' => 8,
        'screenshots' => 8,
        'certificates' => 8,
        'functions' => 8,
    ];

    public static function queue(string $worker): string
    {
        return match ($worker) {
            'databases' => System::getEnv('_APP_QUEUE_NAME', 'database_db_main'),
            'webhooks' => System::getEnv('_APP_WEBHOOK_QUEUE_NAME', Event::WEBHOOK_QUEUE_NAME),
            'deletes' => System::getEnv('_APP_DELETE_QUEUE_NAME', Event::DELETE_QUEUE_NAME),
            'builds' => System::getEnv('_APP_BUILDS_QUEUE_NAME', Event::BUILDS_QUEUE_NAME),
            'jobs' => System::getEnv('_APP_JOBS_QUEUE_NAME', Event::JOBS_QUEUE_NAME),
            'screenshots' => System::getEnv('_APP_SCREENSHOTS_QUEUE_NAME', Event::SCREENSHOTS_QUEUE_NAME),
            'certificates' => System::getEnv('_APP_CERTIFICATES_QUEUE_NAME', Event::CERTIFICATES_QUEUE_NAME),
            'functions' => System::getEnv('_APP_FUNCTIONS_QUEUE_NAME', Event::FUNCTIONS_QUEUE_NAME),
            'mails' => System::getEnv('_APP_MAILS_QUEUE_NAME', Event::MAILS_QUEUE_NAME),
            'notifications' => System::getEnv('_APP_NOTIFICATIONS_QUEUE_NAME', Event::NOTIFICATIONS_QUEUE_NAME),
            'messaging' => System::getEnv('_APP_MESSAGING_QUEUE_NAME', Event::MESSAGING_QUEUE_NAME),
            'migrations' => System::getEnv('_APP_MIGRATIONS_QUEUE_NAME', Event::MIGRATIONS_QUEUE_NAME),
            default => System::getEnv('_APP_QUEUE_NAME', 'v1-' . $worker),
        };
    }

    public static function maxCoroutines(string $worker, bool $env = false): int
    {
        // Concurrent database-worker coroutines can deadlock on ordered writes
        // / adapter locks. This is a correctness constraint, not a tuning knob.
        if ($worker === 'databases') {
            return 1;
        }

        $default = self::COROUTINES[$worker] ?? 1;

        if ($env) {
            $value = System::getEnv('_APP_WORKER_MAX_COROUTINES');
            if ($value !== false && $value !== null && $value !== '') {
                return max(1, (int) $value);
            }
        }

        return $default;
    }

    /**
     * @param list<string> $names
     */
    public static function total(array $names): int
    {
        $sum = 0;
        foreach ($names as $name) {
            $sum += self::maxCoroutines($name);
        }

        return max(1, $sum);
    }

    /**
     * @param list<string> $names
     * @return array<string, array{queue: string, maxCoroutines: int}>
     */
    public static function jobs(array $names, bool $env = false): array
    {
        $jobs = [];
        foreach ($names as $name) {
            $jobs[$name] = [
                'queue' => self::queue($name),
                'maxCoroutines' => self::maxCoroutines($name, $env && \count($names) === 1),
            ];
        }

        return $jobs;
    }
}

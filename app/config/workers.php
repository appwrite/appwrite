<?php

/**
 * Queue workers consumed by app/worker.php.
 *
 * Keys are Platform worker Action names. Each entry is the same shape a single
 * worker used to get from env + defaults — queue name (overridable) and
 * maxCoroutines. databases must stay at 1 (ordered writes / adapter locks).
 */

use Appwrite\Event\Event;

return [
    // Usage is emitted for every completed API request. Keep ClickHouse writes
    // concurrent so this queue cannot starve mails, builds, and other workers.
    'stats-usage' => [
        'queue' => Event::STATS_USAGE_QUEUE_NAME,
        'queueEnv' => '_APP_STATS_USAGE_QUEUE_NAME',
        'maxCoroutines' => 8,
    ],
    'stats-resources' => [
        'queue' => Event::STATS_RESOURCES_QUEUE_NAME,
        'queueEnv' => '_APP_STATS_RESOURCES_QUEUE_NAME',
        'maxCoroutines' => 1,
    ],
    'webhooks' => [
        'queue' => Event::WEBHOOK_QUEUE_NAME,
        'queueEnv' => '_APP_WEBHOOK_QUEUE_NAME',
        'maxCoroutines' => 8,
    ],
    'deletes' => [
        'queue' => Event::DELETE_QUEUE_NAME,
        'queueEnv' => '_APP_DELETE_QUEUE_NAME',
        'maxCoroutines' => 8,
    ],
    'databases' => [
        'queue' => 'database_db_main',
        'queueEnv' => '_APP_QUEUE_NAME',
        'maxCoroutines' => 1,
    ],
    'builds' => [
        'queue' => Event::BUILDS_QUEUE_NAME,
        'queueEnv' => '_APP_BUILDS_QUEUE_NAME',
        'maxCoroutines' => 8,
    ],
    'jobs' => [
        'queue' => Event::JOBS_QUEUE_NAME,
        'queueEnv' => '_APP_JOBS_QUEUE_NAME',
        'maxCoroutines' => 8,
    ],
    'screenshots' => [
        'queue' => Event::SCREENSHOTS_QUEUE_NAME,
        'queueEnv' => '_APP_SCREENSHOTS_QUEUE_NAME',
        'maxCoroutines' => 8,
    ],
    'certificates' => [
        'queue' => Event::CERTIFICATES_QUEUE_NAME,
        'queueEnv' => '_APP_CERTIFICATES_QUEUE_NAME',
        'maxCoroutines' => 8,
    ],
    'executions' => [
        'queue' => Event::EXECUTIONS_QUEUE_NAME,
        'queueEnv' => '_APP_EXECUTIONS_QUEUE_NAME',
        'maxCoroutines' => 8,
    ],
    'functions' => [
        'queue' => Event::FUNCTIONS_QUEUE_NAME,
        'queueEnv' => '_APP_FUNCTIONS_QUEUE_NAME',
        'maxCoroutines' => 8,
    ],
    'mails' => [
        'queue' => Event::MAILS_QUEUE_NAME,
        'queueEnv' => '_APP_MAILS_QUEUE_NAME',
        'maxCoroutines' => 1,
    ],
    'notifications' => [
        'queue' => Event::NOTIFICATIONS_QUEUE_NAME,
        'queueEnv' => '_APP_NOTIFICATIONS_QUEUE_NAME',
        'maxCoroutines' => 1,
    ],
    'messaging' => [
        'queue' => Event::MESSAGING_QUEUE_NAME,
        'queueEnv' => '_APP_MESSAGING_QUEUE_NAME',
        'maxCoroutines' => 1,
    ],
    'migrations' => [
        'queue' => Event::MIGRATIONS_QUEUE_NAME,
        'queueEnv' => '_APP_MIGRATIONS_QUEUE_NAME',
        'maxCoroutines' => 1,
    ],
];

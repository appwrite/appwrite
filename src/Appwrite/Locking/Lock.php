<?php

namespace Appwrite\Locking;

use Appwrite\Extend\Exception;
use Closure;
use Throwable;
use Utopia\Console;
use Utopia\Database\Document;
use Utopia\Lock\Lock as UtopiaLock;
use Utopia\Logger\Log;
use Utopia\Logger\Logger;
use Utopia\System\System;
use Utopia\Telemetry\Adapter as Telemetry;

final class Lock
{
    private const FAIL_TTL_SECONDS = 10;

    private const FAIL_WAIT_SECONDS = 3.0;

    private const SKIP_TTL_SECONDS = 5;

    private const SKIP_WAIT_SECONDS = 0.0;

    private const REPORT_RATE_LIMIT_SECONDS = 60;

    private const OUTCOME_ACQUIRED = 'acquired';

    private const OUTCOME_SKIPPED = 'skipped';

    private const OUTCOME_CONTENDED = 'contended';

    private const OUTCOME_BACKEND_ERROR = 'backend_error';

    private const OUTCOME_RELEASE_ERROR = 'release_error';

    private readonly bool $enabled;

    private readonly mixed $attempts;

    private readonly string $projectInternalId;

    /** @var array<string,int> */
    private static array $lastReportAt = [];

    /**
     * @var Closure(string, int, Closure(UtopiaLock): mixed): mixed
     */
    private readonly Closure $useLock;

    public function __construct(
        Closure $useLock,
        Telemetry $telemetry,
        private readonly ?Logger $logger,
        Document $project,
    ) {
        $this->useLock = $useLock;
        $this->enabled = System::getEnv('_APP_LOCKING_ENABLED', 'enabled') !== 'disabled';
        $this->attempts = $telemetry->createCounter('lock.attempts', null, 'Distributed lock acquire outcomes');
        $this->projectInternalId = (string) ($project->getSequence() ?: $project->getId());
    }

    public function withKey(
        string $key,
        Closure $fn,
        string $target,
        int $ttl = self::FAIL_TTL_SECONDS,
        float $waitTimeout = self::FAIL_WAIT_SECONDS,
    ): mixed {
        return $this->execute(
            $key,
            $target,
            $fn,
            ttl: $ttl,
            waitTimeout: $waitTimeout,
            skipOnContention: false,
        );
    }

    public function tryWithKey(
        string $key,
        Closure $fn,
        string $target,
        int $ttl = self::SKIP_TTL_SECONDS,
    ): mixed {
        return $this->execute(
            $key,
            $target,
            $fn,
            ttl: $ttl,
            waitTimeout: self::SKIP_WAIT_SECONDS,
            skipOnContention: true,
        );
    }

    private function execute(
        string $key,
        string $target,
        Closure $fn,
        int $ttl = self::FAIL_TTL_SECONDS,
        float $waitTimeout = self::FAIL_WAIT_SECONDS,
        bool $skipOnContention = false,
    ): mixed {
        if (! $this->enabled) {
            return $fn();
        }

        $labels = ['target' => $target, 'project' => $this->projectInternalId];

        $callbackException = false;

        try {
            return ($this->useLock)($key, $ttl, function (UtopiaLock $lock) use ($fn, $key, $labels, $skipOnContention, $target, $waitTimeout, &$callbackException): mixed {
                return $this->executeWithLock($lock, $key, $target, $labels, $fn, $waitTimeout, $skipOnContention, $callbackException);
            });
        } catch (\RedisException $e) {
            if ($callbackException) {
                throw $e;
            }

            $this->attempts->add(1, ['outcome' => self::OUTCOME_BACKEND_ERROR, ...$labels]);
            $this->reportError(self::OUTCOME_BACKEND_ERROR, $key, $target, $e);

            return $fn();
        } catch (\Exception $e) {
            if ($callbackException) {
                throw $e;
            }

            // Pool exhaustion throws a bare \Exception before lock acquisition.
            // Only that exact class is fail-open; subclasses must propagate.
            if (get_class($e) !== \Exception::class) {
                throw $e;
            }
            $this->attempts->add(1, ['outcome' => self::OUTCOME_BACKEND_ERROR, ...$labels]);
            $this->reportError(self::OUTCOME_BACKEND_ERROR, $key, $target, $e);

            return $fn();
        }
    }

    /**
     * @param array{target: string, project: string} $labels
     */
    private function executeWithLock(
        UtopiaLock $lock,
        string $key,
        string $target,
        array $labels,
        Closure $fn,
        float $waitTimeout,
        bool $skipOnContention,
        bool &$callbackException,
    ): mixed {
        try {
            $acquired = $lock->acquire($waitTimeout);
        } catch (\RedisException $e) {
            $this->attempts->add(1, ['outcome' => self::OUTCOME_BACKEND_ERROR, ...$labels]);
            $this->reportError(self::OUTCOME_BACKEND_ERROR, $key, $target, $e);

            $callbackException = true;
            return $fn();
        }

        if (! $acquired) {
            if ($skipOnContention) {
                $this->attempts->add(1, ['outcome' => self::OUTCOME_SKIPPED, ...$labels]);

                return null;
            }
            $this->attempts->add(1, ['outcome' => self::OUTCOME_CONTENDED, ...$labels]);
            throw new Exception(Exception::GENERAL_RESOURCE_LOCKED);
        }

        $this->attempts->add(1, ['outcome' => self::OUTCOME_ACQUIRED, ...$labels]);
        $callbackException = true;
        try {
            return $fn();
        } finally {
            try {
                $lock->release();
            } catch (Throwable $e) {
                $this->attempts->add(1, ['outcome' => self::OUTCOME_RELEASE_ERROR, ...$labels]);
                $this->reportError(self::OUTCOME_RELEASE_ERROR, $key, $target, $e);
            }
        }
    }

    /**
     * Rate-limit backend/release reports so outages don't flood Sentry.
     */
    private function reportError(string $action, string $key, string $target, Throwable $e): void
    {
        Console::warning("Lock {$action} for {$key}: {$e->getMessage()}");

        if ($this->logger === null) {
            return;
        }

        $bucket = $action.':'.$target;
        $now = time();
        if ((self::$lastReportAt[$bucket] ?? 0) + self::REPORT_RATE_LIMIT_SECONDS > $now) {
            return;
        }
        self::$lastReportAt[$bucket] = $now;

        $log = new Log();
        $log->setNamespace('http');
        $log->setServer(System::getEnv('_APP_LOGGING_SERVICE_IDENTIFIER', \gethostname()));
        $log->setVersion(APP_VERSION_STABLE);
        $log->setType(Log::TYPE_WARNING);
        $log->setMessage('Distributed lock '.$action.': '.$e->getMessage());
        $log->setAction("lock.{$action}");
        $log->setEnvironment(System::getEnv('_APP_ENV', 'development') === 'production'
            ? Log::ENVIRONMENT_PRODUCTION
            : Log::ENVIRONMENT_STAGING);
        $log->addTag('lock.target', $target);
        $log->addTag('lock.project', $this->projectInternalId);
        // Strip trailing document ID to keep aggregator cardinality bounded.
        $log->addTag('lock.key_pattern', preg_replace('/:[^:]+$/', ':*', $key));
        $log->addTag('code', $e->getCode());
        $log->addExtra('file', $e->getFile());
        $log->addExtra('line', $e->getLine());
        $log->addExtra('trace', $e->getTraceAsString());

        try {
            $this->logger->addLog($log);
        } catch (Throwable) {
        }
    }
}

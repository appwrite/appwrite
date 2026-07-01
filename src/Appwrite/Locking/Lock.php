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
        private readonly Log $log,
        private readonly ?Logger $logger,
        Document $project,
    ) {
        $this->useLock = $useLock;
        $this->enabled = System::getEnv('_APP_LOCKING_ENABLED', 'enabled') !== 'disabled';
        $this->attempts = $telemetry->createCounter('lock.attempts', null, 'Distributed lock acquire outcomes');
        $sequence = $project->getSequence();
        $this->projectInternalId = ($sequence !== null && $sequence !== '') ? (string) $sequence : 'unknown';
    }

    /**
     * Try-once lock around an arbitrary callback for a platform document.
     * Idempotent metadata writes should not make HTTP requests wait behind
     * another pod doing the same update.
     */
    public function run(string $collection, string $id, Closure $fn): void
    {
        $this->tryWithKey($this->key($collection, $id), $fn, target: $collection);
    }

    /**
     * Distributed lock around a platform document that returns the callback result.
     */
    public function runOrFail(string $collection, string $id, Closure $fn): mixed
    {
        return $this->execute($this->key($collection, $id), $collection, $fn);
    }

    /**
     * Generic lock primitive with full control over key, TTL, contention
     * behavior, and wait timeout. Escape hatch for non-platform keys (cache,
     * queue, edge) and for unusual TTL/timeout requirements.
     *
     * Caller may pass `target` for telemetry; otherwise it's extracted by
     * position from the key (best-effort for keys following the standard
     * `lock:platform:{project}:{target}:...` shape).
     */
    public function withKey(
        string $key,
        Closure $fn,
        int $ttl = self::FAIL_TTL_SECONDS,
        float $waitTimeout = self::FAIL_WAIT_SECONDS,
        ?string $target = null,
    ): mixed {
        return $this->execute(
            $key,
            $target ?? self::inferTargetFromKey($key),
            $fn,
            ttl: $ttl,
            waitTimeout: $waitTimeout,
            skipOnContention: false,
        );
    }

    /**
     * Try-once lock around a callback. On contention, skip the callback and
     * return null; this is for best-effort timestamp/access metadata writes.
     */
    public function tryWithKey(
        string $key,
        Closure $fn,
        int $ttl = self::SKIP_TTL_SECONDS,
        ?string $target = null,
    ): mixed {
        return $this->execute(
            $key,
            $target ?? self::inferTargetFromKey($key),
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

        try {
            return ($this->useLock)($key, $ttl, function (UtopiaLock $lock) use ($fn, $key, $labels, $skipOnContention, $target, $waitTimeout): mixed {
                return $this->executeWithLock($lock, $key, $target, $labels, $fn, $waitTimeout, $skipOnContention);
            });
        } catch (CallbackRedisException $e) {
            throw $e->getRedisException();
        } catch (\RedisException $e) {
            $this->attempts->add(1, ['outcome' => self::OUTCOME_BACKEND_ERROR, ...$labels]);
            $this->reportError(self::OUTCOME_BACKEND_ERROR, $key, $target, $e);

            return $fn();
        } catch (\Exception $e) {
            // Pool::pop() throws a bare \Exception (not \RedisException) when it
            // can't hand out a connection within the retry/sync-timeout budget.
            // That happens before the callback runs, so fail open. Match the
            // literal class only: business exceptions (Appwrite\Extend\Exception,
            // etc.) also extend \Exception and must keep propagating.
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
    ): mixed {
        try {
            $acquired = $lock->acquire($waitTimeout);
        } catch (\RedisException $e) {
            $this->attempts->add(1, ['outcome' => self::OUTCOME_BACKEND_ERROR, ...$labels]);
            $this->reportError(self::OUTCOME_BACKEND_ERROR, $key, $target, $e);

            return $fn();
        }

        if (! $acquired) {
            if ($skipOnContention) {
                $this->attempts->add(1, ['outcome' => self::OUTCOME_SKIPPED, ...$labels]);

                return null;
            }
            $this->attempts->add(1, ['outcome' => self::OUTCOME_CONTENDED, ...$labels]);
            // No custom message: the lock key embeds collection and document ID.
            throw new Exception(Exception::GENERAL_RESOURCE_LOCKED);
        }

        $this->attempts->add(1, ['outcome' => self::OUTCOME_ACQUIRED, ...$labels]);
        try {
            try {
                return $fn();
            } catch (\RedisException $e) {
                throw new CallbackRedisException($e);
            }
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
     * Best-effort target extraction for telemetry. Assumes the standard
     * `lock:platform:{project}:{target}:...` shape. For non-platform keys
     * passed via withKey(), callers should pass `target` explicitly.
     */
    private static function inferTargetFromKey(string $key): string
    {
        $parts = explode(':', $key, 5);

        return $parts[3] ?? 'unknown';
    }

    /**
     * Shared platform lock key builder. Exposed so higher-level decorators can
     * keep the platform key shape centralized while adding narrower scopes.
     */
    public function key(string $collection, string $id, ?string $attribute = null): string
    {
        return self::buildKey($this->projectInternalId, $collection, $id, $attribute);
    }

    /**
     * Build a key for a project document that was resolved after this request
     * lock was constructed, such as router/custom-domain resolution.
     */
    public function keyForProject(Document $project, string $collection, string $id, ?string $attribute = null): string
    {
        $sequence = $project->getSequence();
        $projectInternalId = ($sequence !== null && $sequence !== '') ? (string) $sequence : 'unknown';

        return self::buildKey($projectInternalId, $collection, $id, $attribute);
    }

    private static function buildKey(string $projectInternalId, string $collection, string $id, ?string $attribute = null): string
    {
        $key = "lock:platform:{$projectInternalId}:{$collection}:{$id}";

        return $attribute === null ? $key : "{$key}:{$attribute}";
    }

    /**
     * Rate-limited to one push per REPORT_RATE_LIMIT_SECONDS per (action, target)
     * so a sustained backend outage doesn't flood Sentry across the pod fleet.
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

        $this->log->setNamespace('http');
        $this->log->setServer(System::getEnv('_APP_LOGGING_SERVICE_IDENTIFIER', \gethostname()));
        $this->log->setVersion(APP_VERSION_STABLE);
        $this->log->setType(Log::TYPE_WARNING);
        $this->log->setMessage('Distributed lock '.$action.': '.$e->getMessage());
        $this->log->setAction("lock.{$action}");
        $this->log->setEnvironment(System::getEnv('_APP_ENV', 'development') === 'production'
            ? Log::ENVIRONMENT_PRODUCTION
            : Log::ENVIRONMENT_STAGING);
        $this->log->addTag('lock.target', $target);
        $this->log->addTag('lock.project', $this->projectInternalId);
        // Strip trailing document ID to keep aggregator cardinality bounded.
        $this->log->addTag('lock.key_pattern', preg_replace('/:[^:]+$/', ':*', $key));
        $this->log->addTag('code', $e->getCode());
        $this->log->addExtra('file', $e->getFile());
        $this->log->addExtra('line', $e->getLine());
        $this->log->addExtra('trace', $e->getTraceAsString());

        try {
            $this->logger->addLog($this->log);
        } catch (Throwable) {
        }
    }
}

final class CallbackRedisException extends \RuntimeException
{
    public function __construct(\RedisException $previous)
    {
        parent::__construct($previous->getMessage(), $previous->getCode(), $previous);
    }

    public function getRedisException(): \RedisException
    {
        /** @var \RedisException */
        return parent::getPrevious();
    }
}

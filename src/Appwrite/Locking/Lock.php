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
    private const SKIP_TTL_SECONDS = 5;

    private const FAIL_TTL_SECONDS = 10;

    private const FAIL_WAIT_SECONDS = 3.0;

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
    private readonly Closure $withLock;

    public function __construct(
        Closure $withLock,
        Telemetry $telemetry,
        private readonly Log $log,
        private readonly ?Logger $logger,
        Document $project,
    ) {
        $this->withLock = $withLock;
        $this->enabled = System::getEnv('_APP_LOCKING_ENABLED', 'enabled') !== 'disabled';
        $this->attempts = $telemetry->createCounter('lock.attempts', null, 'Distributed lock acquire outcomes');
        $sequence = $project->getSequence();
        $this->projectInternalId = ($sequence !== null && $sequence !== '') ? (string) $sequence : 'unknown';
    }

    /**
     * Skip-on-contention lock around an arbitrary callback for a platform
     * document. For idempotent multi-statement writes that don't fit `set`.
     */
    public function run(string $collection, string $id, Closure $fn): void
    {
        $this->execute($this->key($collection, $id), $collection, $fn);
    }

    /**
     * Block-then-409 lock around an arbitrary callback for a platform document.
     * For read-modify-write endpoints where silently dropping a concurrent
     * request would lose user data.
     */
    public function runOrFail(string $collection, string $id, Closure $fn): mixed
    {
        return $this->execute($this->key($collection, $id), $collection, $fn, ttl: self::FAIL_TTL_SECONDS, orFail: true);
    }

    /**
     * Generic lock primitive with full control over key, TTL, contention
     * behavior, and wait timeout. Escape hatch for non-platform keys
     * (cache, queue, edge) and for unusual TTL/timeout requirements.
     *
     * Caller may pass `target` for telemetry; otherwise it's extracted by
     * position from the key (best-effort for keys following the standard
     * `lock:platform:{project}:{target}:...` shape).
     */
    public function withKey(
        string $key,
        Closure $fn,
        int $ttl = self::SKIP_TTL_SECONDS,
        bool $orFail = false,
        float $waitTimeout = self::FAIL_WAIT_SECONDS,
        ?string $target = null,
    ): mixed {
        return $this->execute(
            $key,
            $target ?? self::inferTargetFromKey($key),
            $fn,
            ttl: $ttl,
            orFail: $orFail,
            waitTimeout: $waitTimeout,
        );
    }

    private function execute(
        string $key,
        string $target,
        Closure $fn,
        int $ttl = self::SKIP_TTL_SECONDS,
        bool $orFail = false,
        float $waitTimeout = self::FAIL_WAIT_SECONDS,
    ): mixed {
        if (! $this->enabled) {
            return $fn();
        }

        $labels = ['target' => $target, 'project' => $this->projectInternalId];
        $lockCallbackStarted = false;

        try {
            return ($this->withLock)($key, $ttl, function (UtopiaLock $lock) use ($key, $target, $fn, $orFail, $waitTimeout, $labels, &$lockCallbackStarted) {
                $lockCallbackStarted = true;

                try {
                    $acquired = $orFail ? $lock->acquire($waitTimeout) : $lock->tryAcquire();
                } catch (\RedisException $e) {
                    $this->attempts->add(1, ['outcome' => self::OUTCOME_BACKEND_ERROR, ...$labels]);
                    $this->reportError(self::OUTCOME_BACKEND_ERROR, $key, $target, $e);

                    return $fn();
                }

                if (! $acquired) {
                    if ($orFail) {
                        $this->attempts->add(1, ['outcome' => self::OUTCOME_CONTENDED, ...$labels]);
                        // No custom message: the lock key embeds collection and document ID.
                        throw new Exception(Exception::GENERAL_RESOURCE_LOCKED);
                    }
                    $this->attempts->add(1, ['outcome' => self::OUTCOME_SKIPPED, ...$labels]);

                    return;
                }

                $this->attempts->add(1, ['outcome' => self::OUTCOME_ACQUIRED, ...$labels]);
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
            });
        } catch (\RedisException $e) {
            if ($lockCallbackStarted) {
                throw $e;
            }

            $this->attempts->add(1, ['outcome' => self::OUTCOME_BACKEND_ERROR, ...$labels]);
            $this->reportError(self::OUTCOME_BACKEND_ERROR, $key, $target, $e);

            return $fn();
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
        $key = "lock:platform:{$this->projectInternalId}:{$collection}:{$id}";

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

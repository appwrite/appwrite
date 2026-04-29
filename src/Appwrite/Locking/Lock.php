<?php

namespace Appwrite\Locking;

use Appwrite\Extend\Exception;
use Closure;
use Throwable;
use Utopia\Console;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\Lock\Distributed as DistributedLock;
use Utopia\Logger\Log;
use Utopia\Logger\Logger;
use Utopia\System\System;
use Utopia\Telemetry\Adapter as Telemetry;

final class Lock
{
    private readonly bool $enabled;

    private readonly mixed $attempts;

    private readonly string $projectInternalId;

    /** @var array<string,int> */
    private static array $lastReportAt = [];

    public function __construct(
        private readonly \Redis $redis,
        Telemetry $telemetry,
        private readonly Database $dbForPlatform,
        private readonly Authorization $authorization,
        private readonly Log $log,
        private readonly ?Logger $logger,
        Document $project,
    ) {
        $this->enabled = System::getEnv('_APP_LOCKING_ENABLED', 'enabled') !== 'disabled';
        $this->attempts = $telemetry->createCounter('lock.attempts', null, 'Distributed lock acquire outcomes');
        $sequence = $project->getSequence();
        $this->projectInternalId = ($sequence !== null && $sequence !== '') ? (string) $sequence : 'unknown';
    }

    /**
     * Throttled single-attribute write under a per-attribute skip-on-contention
     * lock with authorization bypass. For idempotent timestamp-style updates
     * (accessedAt, mcpAccessedAt) where regional pods writing the same value
     * would thrash the platform DB.
     */
    public function set(
        string $collection,
        string $id,
        string $attribute = 'accessedAt',
        ?string $value = null,
    ): void {
        $key = "lock:platform:{$this->projectInternalId}:{$collection}:{$id}:{$attribute}";
        $this->execute($key, $collection, function () use ($collection, $id, $attribute, $value) {
            $this->authorization->skip(fn () => $this->dbForPlatform->updateDocument(
                $collection,
                $id,
                new Document([$attribute => $value ?? DateTime::now()])
            ));
        });
    }

    /**
     * Skip-on-contention lock around an arbitrary callback for a platform
     * document. For idempotent multi-statement writes that don't fit `set`.
     */
    public function run(string $collection, string $id, Closure $fn): void
    {
        $key = "lock:platform:{$this->projectInternalId}:{$collection}:{$id}";
        $this->execute($key, $collection, $fn);
    }

    /**
     * Block-then-409 lock around an arbitrary callback for a platform document.
     * For read-modify-write endpoints where silently dropping a concurrent
     * request would lose user data.
     */
    public function runOrFail(string $collection, string $id, Closure $fn): mixed
    {
        $key = "lock:platform:{$this->projectInternalId}:{$collection}:{$id}";

        return $this->execute($key, $collection, $fn, ttl: 10, orFail: true);
    }

    /**
     * Generic lock primitive with full control over key, TTL, contention
     * behavior, and wait timeout. Escape hatch for non-platform keys
     * (cache, queue, edge) and for unusual TTL/timeout requirements.
     *
     * Caller may pass `target` for telemetry; otherwise it's extracted by
     * position from the key (best-effort for keys following the standard
     * `lock:<scope>:<...>:<target>:<...>` shape).
     */
    public function withKey(
        string $key,
        Closure $fn,
        int $ttl = 5,
        bool $orFail = false,
        float $waitTimeout = 3.0,
        ?string $target = null,
    ): mixed {
        return $this->execute(
            $key,
            $target ?? self::targetOf($key),
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
        int $ttl = 5,
        bool $orFail = false,
        float $waitTimeout = 3.0,
    ): mixed {
        if (! $this->enabled) {
            return $fn();
        }

        $lock = new DistributedLock($this->redis, $key, $ttl);
        $labels = ['target' => $target, 'project' => $this->projectInternalId];

        try {
            $acquired = $orFail ? $lock->acquire($waitTimeout) : $lock->tryAcquire();
        } catch (\RedisException $e) {
            $this->attempts->add(1, ['outcome' => 'backend_error', ...$labels]);
            $this->reportError('backend_error', $key, $target, $e);

            return $fn();
        }

        if (! $acquired) {
            if ($orFail) {
                $this->attempts->add(1, ['outcome' => 'contended', ...$labels]);
                // No custom message — the lock key embeds collection + document id.
                throw new Exception(Exception::GENERAL_RESOURCE_LOCKED);
            }
            $this->attempts->add(1, ['outcome' => 'skipped', ...$labels]);

            return null;
        }

        $this->attempts->add(1, ['outcome' => 'acquired', ...$labels]);
        try {
            return $fn();
        } finally {
            try {
                $lock->release();
            } catch (Throwable $e) {
                $this->attempts->add(1, ['outcome' => 'release_error', ...$labels]);
                $this->reportError('release_error', $key, $target, $e);
            }
        }
    }

    private static function targetOf(string $key): string
    {
        $parts = explode(':', $key, 4);

        return $parts[2] ?? 'unknown';
    }

    /**
     * Rate-limited to one push per 60s per (action, target) so a sustained
     * backend outage doesn't flood Sentry across the pod fleet.
     */
    private function reportError(string $action, string $key, string $target, Throwable $e): void
    {
        Console::warning("Lock {$action} for {$key}: {$e->getMessage()}");

        if ($this->logger === null) {
            return;
        }

        $bucket = $action.':'.$target;
        $now = time();
        if ((self::$lastReportAt[$bucket] ?? 0) + 60 > $now) {
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

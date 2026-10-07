<?php

namespace Appwrite\Event\Publisher;

use Appwrite\Event\Message\Usage as UsageMessage;
use Utopia\Console;
use Utopia\Queue\Publisher\Synchronous as Publisher;
use Utopia\Queue\Queue;
use Utopia\System\System;

readonly class Usage extends Base
{
    /**
     * Pending usage jobs kept in the shared cache Redis.
     *
     * One job is about 2KB. 32768 stays near 64MB, well under the roughly
     * 240k jobs that fill the 512MB allkeys-lru instance. -1 uncaps the list.
     */
    public const int DEFAULT_MAX_PENDING = 32768;

    public function __construct(
        Publisher $publisher,
        protected Queue $queue
    ) {
        parent::__construct($publisher);
    }

    /**
     * Enqueue a usage message
     */
    public function enqueue(UsageMessage $message): string|bool
    {
        if (System::getEnv('_APP_USAGE_STATS', 'enabled') === 'disabled') {
            return false;
        }

        try {
            $blocked = $this->atCapacity();
        } catch (\Throwable $th) {
            Console::error('[Usage] Failed to read usage queue depth: ' . $th->getMessage());
            return false;
        }

        if ($blocked) {
            return false;
        }

        try {
            return $this->publish($this->queue, $message);
        } catch (\Throwable $th) {
            Console::error('[Usage] Failed to publish usage message: ' . $th->getMessage());
            return false;
        }
    }

    /**
     * Get the size of the usage queue
     */
    public function getSize(bool $failed = false): int
    {
        return $this->getQueueSize($this->queue, $failed);
    }

    /**
     * Depth and the push are separate commands, so in-flight publishes can
     * pass the cap by the number of publishes already under way.
     */
    private function atCapacity(): bool
    {
        static $warnedAt = 0;

        $max = $this->pendingLimit();
        if ($max === null) {
            return false;
        }

        $size = $this->getSize();
        if ($size < $max) {
            return false;
        }

        $now = time();
        if ($now - $warnedAt >= 60) {
            $warnedAt = $now;
            Console::warning('[Usage] Queue has ' . $size . ' pending jobs (limit ' . $max . '); dropping further usage metrics');
        }

        return true;
    }

    /**
     * Null leaves the queue uncapped. Only -1 does that.
     */
    private function pendingLimit(): ?int
    {
        $raw = System::getEnv('_APP_STATS_USAGE_QUEUE_MAX', (string) self::DEFAULT_MAX_PENDING);
        if ($raw === '-1') {
            return null;
        }

        if (!ctype_digit($raw) || (int) $raw < 1) {
            return self::DEFAULT_MAX_PENDING;
        }

        return (int) $raw;
    }
}

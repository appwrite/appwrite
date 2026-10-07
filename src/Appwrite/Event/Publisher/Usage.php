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
     * Pending jobs kept on the usage list in the shared Redis.
     *
     * The official Compose Redis is 512mb with allkeys-lru, and one usage job
     * is a few KB, so an uncapped backlog fills the instance and evicts the
     * document cache. 8192 jobs stay around 20MB. Set the env var to -1 to
     * leave the list uncapped.
     */
    public const int DEFAULT_MAX_PENDING = 8192;

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
            if ($this->atCapacity()) {
                return false;
            }

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
     * True when another job would let the usage backlog grow into the cache.
     *
     * The depth check and the push are separate commands, so concurrent
     * publishers can overshoot by the number of in-flight enqueues.
     */
    private function atCapacity(): bool
    {
        static $warnedAt = 0;

        $max = $this->pendingLimit();
        if ($max === null || $this->getSize() < $max) {
            return false;
        }

        $now = time();
        if ($now - $warnedAt >= 60) {
            $warnedAt = $now;
            Console::warning('[Usage] Queue reached ' . $max . ' pending jobs; dropping usage metrics so the shared Redis cache is not evicted');
        }

        return true;
    }

    /**
     * Pending-job cap, or null when the queue is left uncapped.
     *
     * Anything other than -1 or a positive integer keeps the default. "0" is
     * not a cap: System::getEnv already treats it as unset.
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

<?php

namespace Appwrite\Mqtt\Event;

use Appwrite\Messaging\Adapter\Mqtt;
use Appwrite\Mqtt\Handler as Broker;
use Swoole\Coroutine\Channel;
use Utopia\Mqtt\Server;
use Utopia\Span\Span;

/** Handles the broker's internal events: revoke, unsubscribe and deliver. */
class Handler
{
    /** Permission checks in flight, revoke and unsubscribe together. */
    private int $running = 0;

    public function __construct(
        private readonly Server $server,
        private readonly Broker $broker,
        private readonly Mqtt $mqtt,
        private readonly float $timeout,
        private readonly int $checks,
    ) {
    }

    public function handle(mixed $redis, string $channel, string $payload): void
    {
        $event = \json_decode($payload, true);
        if (!\is_array($event)) {
            return;
        }

        $projectId = (string) ($event['project'] ?? '');

        match ($event['action'] ?? '') {
            Mqtt::ACTION_REVOKE => $this->revoke($projectId, $event),
            Mqtt::ACTION_UNSUBSCRIBE => $this->unsubscribe($projectId, $event),
            default => $this->deliver($projectId, $event),
        };
    }

    /**
     * Revoke a user's connections; fails closed if verification doesn't finish in time.
     *
     * @param array<string, mixed> $event
     */
    private function revoke(string $projectId, array $event): void
    {
        $userId = (string) ($event['userId'] ?? '');

        $span = Span::init('mqtt.revoke');
        $span->set('project.id', $projectId);
        $span->set('user.id', $userId);
        $span->set('mqtt.is_broker', true);

        try {
            $outcome = $this->verify(fn (): int => $this->broker->revoke($projectId, $userId));

            if (\is_string($outcome)) {
                $span->set('mqtt.verification', $outcome);
                $span->set('mqtt.disconnected', $this->broker->expel($projectId, $userId));
                $span->setError($this->unverified($outcome));

                return;
            }

            if ($outcome instanceof \Throwable) {
                $span->set('mqtt.verification', 'failed');
                $span->setError($outcome);

                return;
            }

            $span->set('mqtt.verification', 'verified');
            $span->set('mqtt.disconnected', $outcome);
        } finally {
            $span->finish();
        }
    }

    /**
     * Drop a user's subscription to a topic; fails closed if the membership check doesn't finish in time.
     *
     * @param array<string, mixed> $event
     */
    private function unsubscribe(string $projectId, array $event): void
    {
        $userId = (string) ($event['userId'] ?? '');
        $topic = (string) ($event['topic'] ?? '');

        $span = Span::init('mqtt.unsubscribe');
        $span->set('project.id', $projectId);
        $span->set('user.id', $userId);
        $span->set('mqtt.topic', $topic);
        $span->set('mqtt.is_broker', true);

        try {
            $outcome = $this->verify(fn (): int => $this->broker->unsubscribe($this->server, $projectId, $userId, $topic));

            if (\is_string($outcome)) {
                $span->set('mqtt.verification', $outcome);
                $span->set('mqtt.unsubscribed', $this->broker->drop($this->server, $projectId, $userId, $topic));
                $span->setError($this->unverified($outcome));

                return;
            }

            if ($outcome instanceof \Throwable) {
                $span->set('mqtt.verification', 'failed');
                $span->setError($outcome);

                return;
            }

            $span->set('mqtt.verification', 'verified');
            $span->set('mqtt.unsubscribed', $outcome);
        } finally {
            $span->finish();
        }
    }

    /**
     * Run a permission check in a child coroutine, waiting at most $timeout.
     *
     * @param \Closure(): int $check
     * @return int|\Throwable|string the check's count or error, or why it fails closed unverified
     */
    private function verify(\Closure $check): int|\Throwable|string
    {
        // A stuck check keeps its slot and its database connection, so the cap bounds the leak.
        if ($this->running >= $this->checks) {
            return 'check_limit';
        }

        $result = new Channel(1);
        $this->running++;
        $started = \go(function () use ($check, $result): void {
            try {
                $result->push($check());
            } catch (\Throwable $error) {
                $result->push($error);
            } finally {
                $this->running--;
            }
        });

        // An inline check could block on the same dead read.
        if ($started === false) {
            $this->running--;

            return 'coroutine_limit';
        }

        $outcome = $result->pop($this->timeout);

        return $outcome === false ? 'timed_out' : $outcome;
    }

    /** A check that could not complete is a failure: the error keeps the span past trace filters. */
    private function unverified(string $reason): \RuntimeException
    {
        return new \RuntimeException(match ($reason) {
            'timed_out' => "Permission verification timed out after {$this->timeout}s",
            'coroutine_limit' => 'Permission verification skipped: coroutine limit reached',
            default => 'Permission verification skipped: check limit reached',
        });
    }

    /** @param array<string, mixed> $event */
    private function deliver(string $projectId, array $event): void
    {
        $topic = (string) ($event['topic'] ?? '');
        $qos = (int) ($event['qos'] ?? 0);
        $sequence = (int) ($event['sequence'] ?? 0);
        $publishedAt = (float) ($event['publishedAt'] ?? 0.0);
        $message = \base64_decode((string) ($event['payload'] ?? ''));

        $span = Span::init('mqtt.deliver');
        $span->set('project.id', $projectId);
        $span->set('mqtt.topic', $topic);
        $span->set('mqtt.qos', $qos);
        $span->set('mqtt.is_broker', true);

        $delivered = $this->broker->deliver($this->server, $projectId, $topic, $message, $qos, $sequence, $publishedAt);

        $span->set('mqtt.subscribers', $delivered);
        if ($delivered === 0) {
            $this->mqtt->messagesDropped->add(1, ['reason' => 'no_subscriber']);
        }

        $span->finish();
    }
}

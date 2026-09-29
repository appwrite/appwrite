<?php

declare(strict_types=1);

namespace Utopia\NATS\JetStream;

use Utopia\NATS\Connection;
use Utopia\NATS\Exception\TimeoutException;
use Utopia\NATS\Subscription;

final class Consumer
{
    /**
     * Seconds by which the server's pull-request expiry is pulled in ahead of
     * the client deadline. Capped at a tenth of the timeout so a short fetch
     * still leaves the server most of the window.
     */
    private const FETCH_EXPIRY_MARGIN = 0.1;

    public function __construct(
        private readonly Connection $conn,
        private readonly string $stream,
        private ConsumerInfo $info,
        private readonly string $apiPrefix = '$JS.API',
    ) {}

    /**
     * Fetch a batch of messages from the consumer.
     */
    public function fetch(int $batch = 1, ?float $timeout = null, bool $noWait = false, ?int $maxBytes = null): MessageBatch
    {
        $timeout ??= 5.0;
        $requestSubject = "{$this->apiPrefix}.CONSUMER.MSG.NEXT.{$this->stream}.{$this->getName()}";

        $request = ['batch' => $batch];
        if ($noWait) {
            // No 'expires' alongside it. A pull request that carries an expiry
            // waits the whole window and then answers 408 Request Timeout, so
            // no_wait does nothing at all; sent on its own it comes back 404 No
            // Messages immediately, which is the only reason to ask for it. A
            // caller polling an empty consumer therefore paid the full timeout
            // per call -- 0.25s of poll is a ceiling of four calls a second.
            $request['no_wait'] = true;
        } else {
            // Expire the server's pull request just before the client stops
            // waiting. On an identical deadline a message the server dispatches
            // at the boundary is dropped here while the server has already
            // counted the delivery, burning an attempt against maxDeliver for
            // nothing.
            $serverExpiry = $timeout - min(self::FETCH_EXPIRY_MARGIN, $timeout * 0.1);
            $request['expires'] = StreamConfig::secondsToNanos($serverExpiry);
        }
        if ($maxBytes !== null) {
            $request['max_bytes'] = $maxBytes;
        }

        $payload = json_encode($request, JSON_THROW_ON_ERROR);

        $inbox = $this->conn->newInbox();
        $sub = $this->conn->subscribe($inbox);

        $messageBatch = new MessageBatch($this->conn);

        try {
            $this->conn->publish($requestSubject, $payload, $inbox);
            $this->collectBatch($sub, $messageBatch, $batch, $timeout);
        } finally {
            // Outside a finally this leaked the inbox subscription on any throw,
            // and attemptReconnect() re-subscribes every leaked sid on each
            // reconnect -- so the leak compounds across a reconnect storm.
            $sub->unsubscribe();
        }

        return $messageBatch;
    }

    /**
     * Gather up to $batch messages for a pull request, stopping on the server's
     * terminal status messages or when the client deadline passes.
     */
    private function collectBatch(
        Subscription $sub,
        MessageBatch $messageBatch,
        int $batch,
        float $timeout,
    ): void {
        $deadline = microtime(true) + $timeout;

        while (\count($messageBatch) < $batch) {
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                break;
            }

            $msg = $sub->nextMessage($remaining);
            if (!$msg instanceof \Utopia\NATS\Message) {
                break;
            }

            if ($msg->headers instanceof \Utopia\NATS\Headers) {
                $status = $msg->headers->getStatus();

                // 100 = flow control / idle heartbeat: keep-alive, not data, and
                // the only status that means "carry on waiting".
                if ($status === '100') {
                    if ($msg->replyTo !== null && $msg->replyTo !== '') {
                        $this->conn->publish($msg->replyTo, '');
                    }
                    continue;
                }

                // Every other status ends this pull request. Naming the expected
                // codes (404/408/409) and falling through on the rest handed the
                // unnamed ones back as data: a 503 No Responders -- which the
                // server sends while a consumer is still being created or a raft
                // leader is moving -- became a message with an empty body, and a
                // caller that json_decode()s the payload got null and died on it.
                // A status frame carries no payload whatever its number, so it can
                // never be a message.
                if ($status !== '') {
                    break;
                }
            }

            $messageBatch->addMessage($msg);
        }
    }

    /**
     * Fetch the next single message.
     */
    public function next(?float $timeout = null): ?JetStreamMessage
    {
        $batch = $this->fetch(1, $timeout);
        $messages = $batch->getMessages();
        return $messages[0] ?? null;
    }

    public function info(bool $refresh = false): ConsumerInfo
    {
        if ($refresh) {
            $subject = "{$this->apiPrefix}.CONSUMER.INFO.{$this->stream}.{$this->getName()}";
            try {
                $response = $this->conn->request($subject);
                $data = json_decode($response->data, true, 512, JSON_THROW_ON_ERROR);
                JetStream::checkError($data);
                $this->info = ConsumerInfo::fromArray($data);
            } catch (TimeoutException) {
                // Return cached info on timeout
            }
        }
        return $this->info;
    }

    public function getName(): string
    {
        return $this->info->name;
    }

    public function getStream(): string
    {
        return $this->stream;
    }
}

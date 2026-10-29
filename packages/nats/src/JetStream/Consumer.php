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

    /**
     * Seconds a fetch waits for each further frame once its timeout has passed:
     * enough to read a reply already on the socket, too short to wait for work.
     */
    private const FETCH_DRAIN_WAIT = 0.05;

    public function __construct(
        private readonly Connection $conn,
        private readonly string $stream,
        private ConsumerInfo $info,
        private readonly string $apiPrefix = '$JS.API',
    ) {
    }

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
            // Expire the server's pull request just before the timeout, so its
            // 408 ends a waiting fetch on time.
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

        // Capture a throw from the pull itself so a failure tearing down the
        // inbox (dead socket → reconnect → ConnectionException) cannot replace
        // it: PHP replaces an in-flight exception with one raised from finally.
        $pending = null;
        try {
            $this->conn->publish($requestSubject, $payload, $inbox);
            $this->collectBatch($sub, $messageBatch, $batch, $timeout);
        } catch (\Throwable $error) {
            $pending = $error;
        }

        try {
            // Outside a finally this leaked the inbox subscription on any throw,
            // and attemptReconnect() re-subscribes every leaked sid on each
            // reconnect -- so the leak compounds across a reconnect storm.
            $sub->unsubscribe();
        } catch (\Throwable $unsubscribeError) {
            // The connection is already dying; keep the pull's diagnosis when
            // both fail. A bare finally that re-raises would swap MaxPayload /
            // JetStream / -ERR for a generic reconnect failure.
            $pending ??= $unsubscribeError;
        }

        if ($pending instanceof \Throwable) {
            throw $pending;
        }

        return $messageBatch;
    }

    /**
     * Gather up to $batch messages for a pull request, stopping on the server's
     * terminal status, or once the timeout has passed and nothing more is waiting.
     */
    private function collectBatch(
        Subscription $sub,
        MessageBatch $messageBatch,
        int $batch,
        float $timeout,
    ): void {
        $deadline = microtime(true) + $timeout;
        $drained = false;

        while (\count($messageBatch) < $batch) {
            // Past the timeout, still read what the server already sent: it counted
            // each as delivered, and one left unread is held until ackWait and
            // charged an attempt, however long this reader was kept off the socket.
            $remaining = $deadline - microtime(true);
            $msg = $sub->nextMessage($remaining > 0 ? $remaining : self::FETCH_DRAIN_WAIT);
            if (!$msg instanceof \Utopia\NATS\Message) {
                // A wait that ran into the timeout looks once more before giving up.
                if ($remaining > 0 && !$drained) {
                    $drained = true;
                    continue;
                }
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

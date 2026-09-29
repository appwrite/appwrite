<?php

declare(strict_types=1);

namespace Utopia\NATS\KeyValue;

use Utopia\NATS\Connection;
use Utopia\NATS\Exception\JetStreamException;
use Utopia\NATS\Exception\KeyValueException;
use Utopia\NATS\Headers;
use Utopia\NATS\JetStream\AckPolicy;
use Utopia\NATS\JetStream\ConsumerConfig;
use Utopia\NATS\JetStream\DeliverPolicy;
use Utopia\NATS\JetStream\JetStream;
use Utopia\NATS\JetStream\JetStreamMessage;
use Utopia\NATS\JetStream\MsgMetadata;
use Utopia\NATS\Message;
use Utopia\NATS\Subscription;

final class KeyValue
{
    public function __construct(
        private readonly Connection $conn,
        private readonly JetStream $js,
        private readonly string $bucket,
    ) {}

    public function get(string $key): KeyValueEntry
    {
        $this->validateKey($key);

        $subject = "\$KV.{$this->bucket}.{$key}";

        try {
            $msg = $this->conn->request("\$JS.API.DIRECT.GET.KV_{$this->bucket}", json_encode([
                'last_by_subj' => $subject,
            ], JSON_THROW_ON_ERROR));
        } catch (\Throwable) {
            throw new KeyValueException("Key not found: {$key}");
        }

        // Check for delete/purge markers
        if ($msg->headers instanceof \Utopia\NATS\Headers) {
            $op = $msg->headers->get('KV-Operation');
            if ($op === 'DEL' || $op === 'PURGE') {
                throw new KeyValueException("Key not found: {$key}");
            }
        }

        $revision = 0;
        $created = null;
        if ($msg->headers instanceof \Utopia\NATS\Headers) {
            $seqStr = $msg->headers->get('Nats-Sequence');
            if ($seqStr !== null) {
                $revision = (int) $seqStr;
            }
            $created = $msg->headers->get('Nats-Time-Stamp');
        }

        return new KeyValueEntry(
            bucket: $this->bucket,
            key: $key,
            value: $msg->data,
            revision: $revision,
            created: $created,
            operation: KeyValueOperation::Put,
        );
    }

    /**
     * Put a value, returning the revision number.
     */
    public function put(string $key, string $value): int
    {
        $this->validateKey($key);

        $subject = "\$KV.{$this->bucket}.{$key}";
        $ack = $this->js->publish($subject, $value);

        return $ack->sequence;
    }

    /**
     * Create a key only if it does not already exist.
     */
    public function create(string $key, string $value): int
    {
        $this->validateKey($key);

        $subject = "\$KV.{$this->bucket}.{$key}";
        $headers = new Headers();
        $headers->set('Nats-Expected-Last-Subject-Sequence', '0');

        try {
            $ack = $this->js->publish($subject, $value, $headers);
        } catch (JetStreamException $e) {
            // Only a rejected CAS publish means the key exists. Anything else --
            // a stale connection, a timeout, a permissions error -- must keep its
            // own identity, because "you lost the race" is a verdict the caller
            // acts on by not retrying.
            if ($e->apiError?->errCode !== JetStream::ERR_WRONG_LAST_SEQUENCE) {
                throw $e;
            }

            throw new KeyValueException("Key already exists: {$key}", $e->getCode(), previous: $e);
        }

        return $ack->sequence;
    }

    /**
     * Update a key only if the current revision matches (CAS).
     */
    public function update(string $key, string $value, int $revision): int
    {
        $this->validateKey($key);

        $subject = "\$KV.{$this->bucket}.{$key}";

        try {
            $ack = $this->js->publish(
                $subject,
                $value,
                expectedLastSubjectSeq: $revision,
            );
        } catch (JetStreamException $e) {
            if ($e->apiError?->errCode !== JetStream::ERR_WRONG_LAST_SEQUENCE) {
                throw $e;
            }

            throw new KeyValueException("Wrong last revision for key: {$key}", $e->getCode(), previous: $e);
        }

        return $ack->sequence;
    }

    public function delete(string $key): void
    {
        $this->validateKey($key);

        $subject = "\$KV.{$this->bucket}.{$key}";
        $headers = new Headers();
        $headers->set('KV-Operation', 'DEL');

        $this->js->publish($subject, '', $headers);
    }

    public function purge(string $key): void
    {
        $this->validateKey($key);

        $subject = "\$KV.{$this->bucket}.{$key}";
        $headers = new Headers();
        $headers->set('KV-Operation', 'PURGE');
        $headers->set('Nats-Rollup', 'sub');

        $this->js->publish($subject, '', $headers);
    }

    /** @return list<string> */
    public function keys(): array
    {
        $streamName = "KV_{$this->bucket}";
        $subject = "\$KV.{$this->bucket}.>";

        // Use stream subjects to get all keys
        try {
            $msg = $this->conn->request('$JS.API.STREAM.INFO.' . $streamName, json_encode([
                'subjects_filter' => $subject,
            ], JSON_THROW_ON_ERROR));

            $data = json_decode($msg->data, true, 512, JSON_THROW_ON_ERROR);
            JetStream::checkError($data);

            $keys = [];
            $prefix = "\$KV.{$this->bucket}.";
            $subjects = $data['state']['subjects'] ?? [];
            foreach ($subjects as $subj => $count) {
                if (str_starts_with((string) $subj, $prefix)) {
                    $keys[] = substr((string) $subj, \strlen($prefix));
                }
            }

            return $keys;
        } catch (\Throwable) {
            return [];
        }
    }

    public function status(): KeyValueStatus
    {
        $info = $this->js->getStreamInfo("KV_{$this->bucket}");

        return new KeyValueStatus(
            bucket: $this->bucket,
            values: $info->state->messages,
            bytes: $info->state->bytes,
            history: $info->config->maxMsgsPerSubject,
            ttl: $info->config->maxAge,
            streamInfo: $info,
        );
    }

    /**
     * Fetch a specific revision of a key by its stream sequence.
     */
    public function getRevision(string $key, int $seq): KeyValueEntry
    {
        $this->validateKey($key);

        return $this->fetchStored(['seq' => $seq], $key);
    }

    /**
     * Return every stored revision for a key, oldest first.
     *
     * @return list<KeyValueEntry>
     */
    public function history(string $key): array
    {
        $this->validateKey($key);

        $stream = "KV_{$this->bucket}";
        $subject = "\$KV.{$this->bucket}.{$key}";

        $consumer = $this->js->createConsumer($stream, new ConsumerConfig(
            deliverPolicy: DeliverPolicy::All,
            ackPolicy: AckPolicy::Explicit,
            filterSubject: $subject,
            inactiveThreshold: 30.0,
        ));

        try {
            $entries = [];
            foreach ($consumer->fetch(1024, 1.0) as $msg) {
                $entries[] = $this->entryFromDelivered($key, $msg);
                $msg->ack();
            }

            return $entries;
        } finally {
            try {
                $this->js->deleteConsumer($stream, $consumer->getName());
            } catch (\Throwable) {
                // Ephemeral consumer will expire on its own.
            }
        }
    }

    /**
     * Watch a key (or wildcard pattern) for updates.
     *
     * The returned subscription must be pumped by the connection
     * (e.g. `$conn->wait()`), and unsubscribed when no longer needed.
     *
     * With no options, only new updates are delivered (the historical
     * default). Options change what is delivered:
     *  - includeHistory: every stored revision is delivered first, then live updates.
     *  - updatesOnly: only updates from now on (equivalent to the default).
     *  - ignoreDeletes: DEL/PURGE marker entries are not passed to `$callback`.
     *  - metaOnly: entries carry headers/metadata only, with an empty value.
     *
     * When `$onInitDone` is provided it is invoked exactly once, right after the
     * initial/historical set has been fully delivered and before any live update.
     * If there is no history to replay it fires immediately. Every `$callback`
     * invocation after `$onInitDone` is a live update.
     *
     * @param callable(KeyValueEntry): void $callback
     * @param callable(): void|null         $onInitDone
     */
    public function watch(
        string $keyPattern,
        callable $callback,
        ?KeyValueWatchOptions $options = null,
        ?callable $onInitDone = null,
    ): Subscription {
        $options ??= new KeyValueWatchOptions(updatesOnly: true);

        $stream = "KV_{$this->bucket}";
        $filter = "\$KV.{$this->bucket}.{$keyPattern}";
        $deliverSubject = $this->conn->newInbox();

        $deliverPolicy = match (true) {
            $options->includeHistory => DeliverPolicy::All->value,
            $options->updatesOnly => DeliverPolicy::New->value,
            default => DeliverPolicy::LastPerSubject->value,
        };

        $config = [
            'deliver_subject' => $deliverSubject,
            'deliver_policy' => $deliverPolicy,
            'ack_policy' => 'none',
            'filter_subject' => $filter,
            'inactive_threshold' => 30 * 1_000_000_000,
        ];
        if ($options->metaOnly) {
            $config['headers_only'] = true;
        }

        $response = $this->conn->request("\$JS.API.CONSUMER.CREATE.{$stream}", json_encode([
            'stream_name' => $stream,
            'config' => $config,
        ], JSON_THROW_ON_ERROR));
        $data = json_decode($response->data, true, 512, JSON_THROW_ON_ERROR);
        JetStream::checkError($data);

        $numPending = (int) ($data['num_pending'] ?? 0);
        $delivered = 0;
        $initSignaled = false;

        $sub = $this->conn->subscribe($deliverSubject, function (Message $msg) use ($callback, $options, $onInitDone, $numPending, &$delivered, &$initSignaled): void {
            // Ignore JetStream idle heartbeats / flow control (100 status).
            if ($msg->headers instanceof Headers && $msg->headers->getStatus() !== '') {
                return;
            }

            $delivered++;
            $entry = $this->entryFromMessage($msg);

            $isMarker = $entry->operation === KeyValueOperation::Delete
                || $entry->operation === KeyValueOperation::Purge;
            if (!$options->ignoreDeletes || !$isMarker) {
                $callback($entry);
            }

            if (!$initSignaled && $delivered >= $numPending) {
                $initSignaled = true;
                if ($onInitDone !== null) {
                    $onInitDone();
                }
            }
        });

        if ($numPending === 0 && !$initSignaled) {
            $initSignaled = true;
            if ($onInitDone !== null) {
                $onInitDone();
            }
        }

        return $sub;
    }

    /**
     * Remove DEL/PURGE tombstone markers: for every key whose latest entry is a
     * delete/purge marker, purge that subject.
     *
     * @param float|null $threshold When set, only markers older than this many
     *                              seconds are removed; newer markers are kept.
     *                              Null removes all delete markers.
     * @return int Number of keys whose tombstones were removed.
     */
    public function purgeDeletes(?float $threshold = null): int
    {
        $purged = 0;
        $now = microtime(true);

        foreach ($this->keys() as $key) {
            $subject = "\$KV.{$this->bucket}.{$key}";

            try {
                $msg = $this->conn->request("\$JS.API.DIRECT.GET.KV_{$this->bucket}", json_encode([
                    'last_by_subj' => $subject,
                ], JSON_THROW_ON_ERROR));
            } catch (\Throwable) {
                continue;
            }

            if (!$msg->headers instanceof Headers) {
                continue;
            }

            $op = $msg->headers->get('KV-Operation');
            if ($op !== 'DEL' && $op !== 'PURGE') {
                continue;
            }

            // keep=1 retains a not-yet-expired marker; keep=0 removes the subject entirely.
            $keep = 0;
            if ($threshold !== null) {
                $ts = $msg->headers->get('Nats-Time-Stamp');
                $created = $ts !== null ? strtotime($ts) : false;
                if ($created !== false && ($now - $created) < $threshold) {
                    $keep = 1;
                }
            }

            $response = $this->conn->request("\$JS.API.STREAM.PURGE.KV_{$this->bucket}", json_encode([
                'filter' => $subject,
                'keep' => $keep,
            ], JSON_THROW_ON_ERROR));
            $result = json_decode($response->data, true, 512, JSON_THROW_ON_ERROR);
            JetStream::checkError($result);

            if ($keep === 0) {
                $purged++;
            }
        }

        return $purged;
    }

    public function getBucket(): string
    {
        return $this->bucket;
    }

    private function entryFromDelivered(string $key, JetStreamMessage $msg): KeyValueEntry
    {
        return new KeyValueEntry(
            bucket: $this->bucket,
            key: $key,
            value: $msg->getData(),
            revision: $msg->metadata()->streamSequence,
            created: $msg->metadata()->timestamp,
            operation: $this->operationFromHeaders($msg->getHeaders()),
        );
    }

    private function entryFromMessage(Message $msg): KeyValueEntry
    {
        $prefix = "\$KV.{$this->bucket}.";
        $key = str_starts_with($msg->subject, $prefix)
            ? substr($msg->subject, \strlen($prefix))
            : $msg->subject;

        $revision = 0;
        $created = null;
        if ($msg->replyTo !== null) {
            $meta = MsgMetadata::fromReplySubject($msg->replyTo);
            $revision = $meta->streamSequence;
            $created = $meta->timestamp;
        }

        return new KeyValueEntry(
            bucket: $this->bucket,
            key: $key,
            value: $msg->data,
            revision: $revision,
            created: $created,
            operation: $this->operationFromHeaders($msg->headers),
        );
    }

    /**
     * @param array<string, mixed> $request
     */
    private function fetchStored(array $request, string $key): KeyValueEntry
    {
        try {
            $response = $this->conn->request(
                "\$JS.API.STREAM.MSG.GET.KV_{$this->bucket}",
                json_encode($request, JSON_THROW_ON_ERROR),
            );
        } catch (\Throwable $e) {
            throw new KeyValueException("Revision not found for key: {$key}", $e->getCode(), previous: $e);
        }

        $data = json_decode($response->data, true, 512, JSON_THROW_ON_ERROR);
        JetStream::checkError($data);

        $stored = $data['message'] ?? null;
        if (!\is_array($stored)) {
            throw new KeyValueException("Revision not found for key: {$key}");
        }

        // A sequence lookup is stream-global: reject a revision that belongs to a different key.
        $expectedSubject = "\$KV.{$this->bucket}.{$key}";
        if (isset($stored['subject']) && $stored['subject'] !== $expectedSubject) {
            throw new KeyValueException("Revision not found for key: {$key}");
        }

        $headers = null;
        if (isset($stored['hdrs']) && \is_string($stored['hdrs'])) {
            $headers = Headers::fromWire((string) base64_decode($stored['hdrs'], true));
        }

        return new KeyValueEntry(
            bucket: $this->bucket,
            key: $key,
            value: isset($stored['data']) ? (string) base64_decode((string) $stored['data'], true) : '',
            revision: (int) ($stored['seq'] ?? 0),
            created: $stored['time'] ?? null,
            operation: $this->operationFromHeaders($headers),
        );
    }

    private function operationFromHeaders(?Headers $headers): KeyValueOperation
    {
        if (!$headers instanceof Headers) {
            return KeyValueOperation::Put;
        }

        return match ($headers->get('KV-Operation')) {
            'DEL' => KeyValueOperation::Delete,
            'PURGE' => KeyValueOperation::Purge,
            default => KeyValueOperation::Put,
        };
    }

    private function validateKey(string $key): void
    {
        if ($key === '' || str_contains($key, ' ') || str_contains($key, '>') || str_contains($key, '*')) {
            throw new KeyValueException("Invalid key: {$key}");
        }
    }
}

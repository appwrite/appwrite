<?php

declare(strict_types=1);

namespace Utopia\NATS\ObjectStore;

use Utopia\NATS\Connection;
use Utopia\NATS\Exception\JetStreamException;
use Utopia\NATS\Exception\ObjectStoreException;
use Utopia\NATS\Headers;
use Utopia\NATS\JetStream\AckPolicy;
use Utopia\NATS\JetStream\ConsumerConfig;
use Utopia\NATS\JetStream\DeliverPolicy;
use Utopia\NATS\JetStream\JetStream;
use Utopia\NATS\JetStream\StreamInfo;
use Utopia\NATS\Message;
use Utopia\NATS\Subscription;

final class ObjectStore
{
    private const CHUNK_SIZE = 128 * 1024;

    public function __construct(
        private readonly Connection $conn,
        private readonly JetStream $js,
        private readonly string $bucket,
    ) {}

    /**
     * Create (or update) the backing stream and return a store handle.
     */
    public static function createOrUpdate(Connection $conn, JetStream $js, ObjectStoreConfig $config): self
    {
        $js->createOrUpdateStream($config->toStreamConfig());

        return new self($conn, $js, $config->bucket);
    }

    /**
     * Store an object, chunking its data and writing a meta record.
     */
    public function put(string $name, string $data): ObjectMeta
    {
        [$previous, $previousSeq] = $this->readMetaWithSeq($name);

        return $this->writeVersion($name, $data, $previous, $previousSeq);
    }

    /**
     * Write a new version of an object, expecting the meta subject to still be at
     * $expectedSeq (0 = must not exist yet). Separated from put() so the optimistic
     * concurrency path can be driven deterministically in tests.
     */
    private function writeVersion(string $name, string $data, ?ObjectMeta $previous, int $expectedSeq): ObjectMeta
    {
        $nuid = strtoupper(bin2hex(random_bytes(12)));
        $chunkSubject = "\$O.{$this->bucket}.C.{$nuid}";

        $chunks = 0;
        $length = \strlen($data);
        for ($offset = 0; $offset < $length; $offset += self::CHUNK_SIZE) {
            $this->js->publish($chunkSubject, substr($data, $offset, self::CHUNK_SIZE));
            $chunks++;
        }

        $meta = new ObjectMeta(
            name: $name,
            bucket: $this->bucket,
            nuid: $nuid,
            size: $length,
            chunks: $chunks,
            digest: $this->digest($data),
            modified: gmdate('Y-m-d\TH:i:s\Z'),
        );

        // Optimistic concurrency: the meta publish only succeeds if the meta subject's
        // last sequence still matches what we read (0 means "must not exist yet"). If a
        // concurrent or stale writer already advanced it, JetStream rejects the publish,
        // we purge only the chunks THIS put wrote (never the previous NUID), and surface a
        // clear conflict. This replaces the former last-writer-wins behaviour that could
        // orphan the loser's chunks.
        $headers = new Headers();
        $headers->set('Nats-Rollup', 'sub');
        try {
            $this->js->publish(
                $this->metaSubject($name),
                json_encode($meta->toArray(), JSON_THROW_ON_ERROR),
                $headers,
                expectedLastSubjectSeq: $expectedSeq,
            );
        } catch (JetStreamException $e) {
            // Purge only when the server actually rejected the CAS publish. On a
            // transport failure the meta publish is ambiguous -- it may well have
            // landed -- and purging here would delete the chunks this put just
            // wrote successfully.
            if ($e->apiError?->errCode !== JetStream::ERR_WRONG_LAST_SEQUENCE) {
                throw $e;
            }

            $this->purgeChunks($nuid);
            throw new ObjectStoreException("conflicting concurrent write for object: {$name}", $e->getCode(), previous: $e);
        }

        // Reclaim chunks left behind by a prior version of this object.
        if ($previous instanceof ObjectMeta && $previous->nuid !== '' && $previous->nuid !== $nuid) {
            $this->purgeChunks($previous->nuid);
        }

        return $meta;
    }

    /**
     * Retrieve an object's bytes, reassembling and verifying its chunks.
     */
    public function get(string $name): string
    {
        $meta = $this->readMeta($name);
        if (!$meta instanceof ObjectMeta || $meta->deleted) {
            throw new \RuntimeException("Object not found: {$name}");
        }

        // A link transparently resolves to its target's bytes (same bucket). A
        // bucket link has no target object, so it cannot be read as an object.
        if ($meta->link instanceof ObjectLink) {
            if ($meta->link->name === null || $meta->link->name === '') {
                throw new ObjectStoreException("cannot get a bucket link: {$name}");
            }

            return $this->get($meta->link->name);
        }

        $data = '';
        if ($meta->chunks > 0) {
            $stream = "OBJ_{$this->bucket}";
            $subject = "\$O.{$this->bucket}.C.{$meta->nuid}";

            $consumer = $this->js->createConsumer($stream, new ConsumerConfig(
                deliverPolicy: DeliverPolicy::All,
                ackPolicy: AckPolicy::Explicit,
                filterSubject: $subject,
                inactiveThreshold: 30.0,
            ));

            try {
                foreach ($consumer->fetch($meta->chunks, 10.0) as $msg) {
                    $data .= $msg->getData();
                    $msg->ack();
                }
            } finally {
                try {
                    $this->js->deleteConsumer($stream, $consumer->getName());
                } catch (\Throwable) {
                    // Ephemeral consumer expires on its own.
                }
            }
        }

        if (\strlen($data) !== $meta->size || $this->digest($data) !== $meta->digest) {
            throw new \RuntimeException("Object integrity check failed: {$name}");
        }

        return $data;
    }

    public function getMeta(string $name): ObjectMeta
    {
        $meta = $this->readMeta($name);
        if (!$meta instanceof ObjectMeta || $meta->deleted) {
            throw new \RuntimeException("Object not found: {$name}");
        }

        return $meta;
    }

    public function delete(string $name): void
    {
        [$meta, $expectedSeq] = $this->readMetaWithSeq($name);
        if (!$meta instanceof ObjectMeta || $meta->deleted) {
            return;
        }

        $this->deleteVersion($meta, $expectedSeq);
    }

    /**
     * Commit a deletion tombstone guarded by optimistic concurrency, then reclaim the
     * object's chunks. The guarded publish goes FIRST: a concurrent put()/updateMeta()
     * advances the meta subject, so this conflicts and throws before any chunk is purged,
     * leaving the replacement version intact. Separated so the conflict path is testable.
     */
    private function deleteVersion(ObjectMeta $meta, int $expectedSeq): void
    {
        // Write a deletion marker (tombstone) rather than purging the meta subject,
        // so watchers observe the delete and get()/list() still treat it as gone.
        // The rollup header collapses the subject to this single record.
        $tombstone = new ObjectMeta(
            name: $meta->name,
            bucket: $meta->bucket,
            nuid: '',
            size: 0,
            chunks: 0,
            digest: '',
            description: $meta->description,
            modified: gmdate('Y-m-d\TH:i:s\Z'),
            deleted: true,
        );

        $this->publishMeta($tombstone, $expectedSeq);

        // Only after the tombstone is committed do we reclaim the chunks.
        if ($meta->nuid !== '') {
            $this->purgeChunks($meta->nuid);
        }
    }

    /**
     * List all (non-deleted) objects in the bucket.
     *
     * @return list<ObjectMeta>
     */
    public function list(): array
    {
        $stream = "OBJ_{$this->bucket}";
        $subject = "\$O.{$this->bucket}.M.>";

        try {
            $consumer = $this->js->createConsumer($stream, new ConsumerConfig(
                deliverPolicy: DeliverPolicy::LastPerSubject,
                ackPolicy: AckPolicy::Explicit,
                filterSubject: $subject,
                inactiveThreshold: 30.0,
            ));
        } catch (\Throwable) {
            return [];
        }

        try {
            $objects = [];
            foreach ($consumer->fetch(1024, 1.0) as $msg) {
                $msg->ack();
                $decoded = json_decode((string) $msg->getData(), true, 512, JSON_THROW_ON_ERROR);
                if (!\is_array($decoded)) {
                    continue;
                }
                $meta = ObjectMeta::fromArray($decoded);
                if (!$meta->deleted) {
                    $objects[] = $meta;
                }
            }

            return $objects;
        } finally {
            try {
                $this->js->deleteConsumer($stream, $consumer->getName());
            } catch (\Throwable) {
                // Ephemeral consumer expires on its own.
            }
        }
    }

    public function status(): StreamInfo
    {
        return $this->js->getStreamInfo("OBJ_{$this->bucket}");
    }

    /**
     * Watch the bucket for object meta updates (puts and deletes), delivering an
     * ObjectMeta to the callback for each change.
     *
     * Backed by an ephemeral push consumer on the meta subject created via the raw
     * JetStream API. The returned subscription must be pumped by the connection
     * (e.g. `$conn->wait()` / `$conn->processMessage()`) and unsubscribed when done.
     *
     * @param callable(ObjectMeta): void $callback
     * @param bool                       $includeHistory deliver the current meta of every object first
     */
    public function watch(callable $callback, bool $includeHistory = false): Subscription
    {
        $stream = "OBJ_{$this->bucket}";
        $filter = "\$O.{$this->bucket}.M.>";
        $deliverSubject = $this->conn->newInbox();

        $payload = json_encode([
            'stream_name' => $stream,
            'config' => [
                'deliver_subject' => $deliverSubject,
                'deliver_policy' => $includeHistory ? 'last_per_subject' : 'new',
                'ack_policy' => 'none',
                'filter_subject' => $filter,
                'inactive_threshold' => 30 * 1_000_000_000,
            ],
        ], JSON_THROW_ON_ERROR);

        $response = $this->conn->request("\$JS.API.CONSUMER.CREATE.{$stream}", $payload);
        $data = json_decode($response->data, true, 512, JSON_THROW_ON_ERROR);
        JetStream::checkError($data);

        return $this->conn->subscribe($deliverSubject, function (Message $msg) use ($callback): void {
            // Ignore JetStream idle heartbeats / flow control (status messages).
            if ($msg->headers instanceof Headers && $msg->headers->getStatus() !== '') {
                return;
            }

            $decoded = json_decode($msg->data, true, 512, JSON_THROW_ON_ERROR);
            if (!\is_array($decoded)) {
                return;
            }

            $callback(ObjectMeta::fromArray($decoded));
        });
    }

    /**
     * Create a link under $linkName pointing to another object in this bucket.
     * get($linkName) then transparently resolves to the target's bytes.
     */
    public function addLink(string $linkName, string $targetObjectName): ObjectMeta
    {
        return $this->writeLink($linkName, new ObjectLink($this->bucket, $targetObjectName));
    }

    /**
     * Create a link under $linkName pointing to an entire bucket. A bucket link
     * cannot be read via get(); doing so throws.
     */
    public function addBucketLink(string $linkName, string $targetBucket): ObjectMeta
    {
        return $this->writeLink($linkName, new ObjectLink($targetBucket));
    }

    /**
     * Update mutable meta fields, preserving the stored bytes (nuid/size/chunks/digest).
     *
     * @param array<string, string>|null $metadata
     */
    public function updateMeta(string $name, ?string $description = null, ?array $metadata = null): ObjectMeta
    {
        [$previous, $expectedSeq] = $this->readMetaWithSeq($name);
        if (!$previous instanceof ObjectMeta || $previous->deleted) {
            throw new \RuntimeException("Object not found: {$name}");
        }

        $meta = new ObjectMeta(
            name: $previous->name,
            bucket: $previous->bucket,
            nuid: $previous->nuid,
            size: $previous->size,
            chunks: $previous->chunks,
            digest: $previous->digest,
            description: $description ?? $previous->description,
            modified: gmdate('Y-m-d\TH:i:s\Z'),
            metadata: $metadata ?? $previous->metadata,
            link: $previous->link,
        );

        $this->publishMeta($meta, $expectedSeq);

        return $meta;
    }

    /**
     * Seal the bucket, making it permanently read-only by updating the backing
     * stream config. After sealing, the server rejects put()/delete().
     */
    public function seal(): void
    {
        $stream = "OBJ_{$this->bucket}";

        $infoResponse = $this->conn->request("\$JS.API.STREAM.INFO.{$stream}");
        $info = json_decode($infoResponse->data, true, 512, JSON_THROW_ON_ERROR);
        JetStream::checkError($info);

        $config = $info['config'] ?? [];
        if (!\is_array($config)) {
            throw new ObjectStoreException("cannot read stream config for bucket: {$this->bucket}");
        }
        // Empty JSON objects in the info response (e.g. consumer_limits) decode to
        // empty PHP arrays and would re-encode as [] — which the API rejects as
        // invalid JSON for a struct field. Drop them; the server re-applies defaults.
        $config = array_filter($config, static fn(mixed $v): bool => $v !== []);
        $config['sealed'] = true;

        $updateResponse = $this->conn->request(
            "\$JS.API.STREAM.UPDATE.{$stream}",
            json_encode($config, JSON_THROW_ON_ERROR),
        );
        $updated = json_decode($updateResponse->data, true, 512, JSON_THROW_ON_ERROR);
        JetStream::checkError($updated);
    }

    public function getBucket(): string
    {
        return $this->bucket;
    }

    private function writeLink(string $linkName, ObjectLink $link): ObjectMeta
    {
        [$previous, $expectedSeq] = $this->readMetaWithSeq($linkName);

        $meta = new ObjectMeta(
            name: $linkName,
            bucket: $this->bucket,
            nuid: '',
            size: 0,
            chunks: 0,
            digest: '',
            modified: gmdate('Y-m-d\TH:i:s\Z'),
            link: $link,
        );

        $this->publishMeta($meta, $expectedSeq);

        // Reclaim chunks left behind if this name previously held a real object.
        if ($previous instanceof ObjectMeta && $previous->nuid !== '') {
            $this->purgeChunks($previous->nuid);
        }

        return $meta;
    }

    /**
     * Publish a meta record with a rolling-up header under the optimistic-concurrency
     * guard: the publish only succeeds if the meta subject is still at $expectedSeq.
     */
    private function publishMeta(ObjectMeta $meta, int $expectedSeq): void
    {
        $headers = new Headers();
        $headers->set('Nats-Rollup', 'sub');

        try {
            $this->js->publish(
                $this->metaSubject($meta->name),
                json_encode($meta->toArray(), JSON_THROW_ON_ERROR),
                $headers,
                expectedLastSubjectSeq: $expectedSeq,
            );
        } catch (JetStreamException $e) {
            if ($e->apiError?->errCode !== JetStream::ERR_WRONG_LAST_SEQUENCE) {
                throw $e;
            }

            throw new ObjectStoreException("conflicting concurrent write for object: {$meta->name}", $e->getCode(), previous: $e);
        }
    }

    private function readMeta(string $name): ?ObjectMeta
    {
        return $this->readMetaWithSeq($name)[0];
    }

    /**
     * Read the latest meta record for an object along with its stream sequence.
     * The sequence is 0 when no meta record exists, which callers use as the
     * expected-last-subject-sequence for an optimistic first write.
     *
     * @return array{0: ?ObjectMeta, 1: int}
     */
    private function readMetaWithSeq(string $name): array
    {
        try {
            $response = $this->conn->request(
                "\$JS.API.STREAM.MSG.GET.OBJ_{$this->bucket}",
                json_encode(['last_by_subj' => $this->metaSubject($name)], JSON_THROW_ON_ERROR),
            );
        } catch (\Throwable) {
            return [null, 0];
        }

        $data = json_decode($response->data, true, 512, JSON_THROW_ON_ERROR);
        if (isset($data['error']) || !isset($data['message']['data'])) {
            return [null, 0];
        }

        $decoded = json_decode((string) base64_decode((string) $data['message']['data'], true), true, 512, JSON_THROW_ON_ERROR);
        if (!\is_array($decoded)) {
            return [null, 0];
        }

        return [ObjectMeta::fromArray($decoded), (int) ($data['message']['seq'] ?? 0)];
    }

    private function purgeChunks(string $nuid): void
    {
        try {
            $this->js->purgeStream("OBJ_{$this->bucket}", "\$O.{$this->bucket}.C.{$nuid}");
        } catch (\Throwable) {
            // Best effort cleanup.
        }
    }

    private function metaSubject(string $name): string
    {
        $token = rtrim(strtr(base64_encode($name), '+/', '-_'), '=');

        return "\$O.{$this->bucket}.M.{$token}";
    }

    private function digest(string $data): string
    {
        $raw = hash('sha256', $data, true);

        return 'SHA-256=' . rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}

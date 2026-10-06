<?php

declare(strict_types=1);

namespace Utopia\Database\Adapter;

use Redis as RedisClient;
use Utopia\Database\Adapter;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Exception as DatabaseException;
use Utopia\Database\Exception\Duplicate as DuplicateException;
use Utopia\Database\Exception\Limit as LimitException;
use Utopia\Database\Exception\NotFound as NotFoundException;
use Utopia\Database\Exception\Operator as OperatorException;
use Utopia\Database\Exception\Query as QueryException;
use Utopia\Database\Exception\Transaction as TransactionException;
use Utopia\Database\Exception\Unique as UniqueException;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Operator;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;

/**
 * Redis-backed adapter mirroring the Memory adapter's surface.
 *
 * Storage key schema (every key is prefixed with `KEY_PREFIX:`):
 *
 *     {ns}                      = getNamespace()
 *     {db}                      = current setDatabase() value
 *     {col}                     = collection ID
 *
 *     Key                                          | Type | Holds
 *     ---------------------------------------------+------+----------------------------------
 *     {ns}:{db}:dbs                                | SET  | database names
 *     {ns}:{db}:cols                               | SET  | collection IDs in this db
 *     {ns}:{db}:meta:{col}                         | HASH | fields: schema, attrs, indexes, docCount, sizeBytes
 *     {ns}:{db}:doc:{col}:{id}                     | STRING | JSON-encoded Document
 *     {ns}:{db}:idx:{col}                          | SET  | doc IDs in collection (for SCAN/list)
 *     {ns}:{db}:perm:{col}:{r|c|u|d|w}:{role}      | SET  | doc IDs by action+role (non-shared)
 *     {ns}:{db}:perm:t:{tenant}:{col}:{letter}:{role} | SET | shared-tables variant
 *     {ns}:{db}:perm:doc:{col}:{id}                | HASH | role -> csv("read,update,delete")
 *     {ns}:{db}:perm:t:{tenant}:doc:{col}:{id}     | HASH | shared-tables variant
 *     {ns}:{db}:tenants:{col}:{tenant}             | SET  | doc IDs filtered by tenant
 *
 * Transaction model: `tx()` is a single-shot wrapper that surfaces
 * `\RedisException` as `TransactionException`. There is NO retry, no
 * `WATCH`/`MULTI`/`EXEC`, and no automatic OCC — retrying would replay
 * journal side-effects (duplicate `INCR` on sequence keys, double
 * pipelined SADDs). Real OCC is a follow-up; `getSupportForTransactionRetries()`
 * returns `false` so the shared trait's OCC tests stay off. Pessimistic
 * update locks are intentionally unsupported.
 *
 * Rollback contract: `rollbackJournal()` MUST use raw `\Redis` client
 * commands only — calling a public adapter method re-enters `journal()`
 * and recurses infinitely. All inverses route through `rawDeleteDoc()`
 * and `rawRestoreDoc()`.
 */
class Redis extends Adapter
{
    public const string KEY_PREFIX = 'utopia';

    public const string SEP = ':';

    /**
     * Default SCAN MATCH batch size — also the variadic DEL chunk size
     * used by collection purge. Aligned with the test harness teardown
     * documented in Contract.md.
     */
    private const int SCAN_BATCH_SIZE = 500;

    /**
     * Maximum depth for `json_decode` when reading document payloads and
     * meta-hash fields. Matches the PHP default; hoisted so the value is
     * named once instead of repeated 8+ times across the file.
     */
    private const int JSON_DECODE_DEPTH = 512;

    private RedisClient $client;

    /**
     * @var array<int, array<int, array{op: string, payload: array<string, mixed>}>>
     */
    private array $journalStack = [];

    public function __construct(RedisClient $client)
    {
        $this->client = $client;
    }

    /**
     * Join the supplied parts with `SEP`. Does NOT prepend `KEY_PREFIX` —
     * call sites compose the prefix by passing `$this->ns()` (which is
     * `'KEY_PREFIX:{namespace}:{database}'`) as the first argument.
     */
    private function key(string ...$parts): string
    {
        return \implode(self::SEP, $parts);
    }

    /**
     * Build the `'KEY_PREFIX:{namespace}:{database}'` prefix shared by
     * every adapter-produced key. All call sites that construct a Redis
     * key MUST pass `$this->ns()` as the first argument to `key()` —
     * passing the raw namespace/database produces unprefixed keys that
     * collide across processes.
     */
    private function ns(): string
    {
        return $this->nsFor($this->getNamespace(), $this->getDatabase());
    }

    /**
     * Variant of `ns()` that targets a specific database name within the
     * current namespace. Used by `exists()` / `delete()` and similar
     * cross-database operations where the Adapter's bound database is
     * not the database under inspection.
     */
    private function nsFor(string $namespace, string $database): string
    {
        return self::KEY_PREFIX . self::SEP . $namespace . self::SEP . $database;
    }

    /**
     * Build the namespace-only prefix `'KEY_PREFIX:{namespace}'`.
     * Used for keys that are shared across all databases in a namespace,
     * such as the database-registry SET (`dbs`). Unlike `ns()` this does
     * NOT include the currently bound database name, so `create()`,
     * `exists()`, `list()`, and `delete()` all read/write the same key
     * regardless of which database is currently selected.
     */
    private function nsBase(): string
    {
        return self::KEY_PREFIX . self::SEP . $this->getNamespace();
    }

    /**
     * Build the document storage key. Lower-cases `$id` to match MariaDB's
     * default case-insensitive UID semantics. Under shared tables every doc
     * key is bucketed by tenant so two tenants can hold the same id without
     * colliding — `null` tenants land under the `_` bucket alongside global
     * METADATA rows.
     */
    private function docKey(string $collection, string $id, int|string|null $tenant = null): string
    {
        $id = \strtolower($id);
        if (! $this->getSharedTables()) {
            return $this->key($this->ns(), 'doc', $collection, $id);
        }

        $bucket = $this->bucketFor($tenant);

        return $this->key($this->ns(), 'doc', 't', $bucket, $collection, $id);
    }

    /**
     * Build the doc-id index SET key for a collection. Tenant-scoped under
     * shared tables so per-tenant `find()` / `count()` see only their own
     * ids and a recreated collection does not inherit foreign ids.
     */
    private function idxKey(string $collection, int|string|null $tenant = null): string
    {
        if (! $this->getSharedTables()) {
            return $this->key($this->ns(), 'idx', $collection);
        }

        return $this->key($this->ns(), 'idx', 't', $this->bucketFor($tenant), $collection);
    }

    /**
     * Build the sequence counter key for a collection. Tenant-scoped under
     * shared tables so each tenant gets an independent monotonic id space.
     */
    private function seqKey(string $collection, int|string|null $tenant = null): string
    {
        if (! $this->getSharedTables()) {
            return $this->key($this->ns(), 'seq', $collection);
        }

        return $this->key($this->ns(), 'seq', 't', $this->bucketFor($tenant), $collection);
    }

    /**
     * Resolve the tenant-bucket segment for shared-tables doc/idx/seq keys,
     * mapping `null` to the literal `'_'` so all shared-tables keys share a
     * single bucket convention.
     */
    private function bucketFor(int|string|null $tenant): string
    {
        if ($tenant === null) {
            $tenant = $this->getTenant();
        }

        return $tenant === null ? '_' : (string) $tenant;
    }

    private function encode(Document $document): string
    {
        return \json_encode(
            $document->getArrayCopy(),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
        );
    }

    private function decode(string $payload): Document
    {
        try {
            /** @var array<string, mixed> $data */
            $data = \json_decode($payload, true, self::JSON_DECODE_DEPTH, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new DatabaseException('Document decode failed: ' . $e->getMessage(), 0, $e);
        }

        return new Document($data);
    }

    /**
     * Single-shot wrapper for journal-tracked Redis operations. Does NOT
     * retry — Redis transient errors propagate as `TransactionException`.
     * Retrying here would replay journal side-effects (duplicate entries,
     * non-idempotent commands like `INCR` on the sequence key advancing
     * twice) so we leave retry policy to call sites that can prove
     * idempotency. OCC support via WATCH/MULTI/EXEC is a follow-up
     * (see Contract.md). `getSupportForTransactionRetries()` returns
     * `false` so the shared trait suite skips OCC-retry assertions.
     *
     * @param callable(RedisClient): mixed $fn
     */
    protected function tx(callable $fn): mixed
    {
        try {
            return $fn($this->client);
        } catch (\RedisException $exception) {
            throw new TransactionException('tx failed: ' . $exception->getMessage(), 0, $exception);
        }
    }

    /**
     * Persist a document's permissions into the inverted role/action sets and
     * the per-document role->letters HASH. The same writes are journalled so
     * T56 can revert them on rollback.
     *
     * NOTE: opens its own `multi(\Redis::PIPELINE)` block. MUST NOT be wrapped
     * inside a MULTI/EXEC: phpredis does not support nested MULTI, and
     * pipelining inside a transaction would queue commands incorrectly. If
     * `tx()` ever gains real WATCH/MULTI/EXEC, this method must be refactored
     * to either share the outer connection's mode, take an `inMulti` flag,
     * or be split into a non-pipelined variant. Same constraint applies to
     * `clearPermissions()` and `getSequences()`.
     */
    private function writePermissions(string $collection, string $id, Document $document): void
    {
        // Document keys (`doc:{col}:{id}`) and the index SET (`idx:{col}`) both
        // use `\strtolower($id)`. The inverted permission SETs must follow the
        // same convention so `applyPermissionFilter()` can intersect ids from
        // the index SET with the perm SETs without case mismatch.
        $id = \strtolower($id);

        $byRole = [];
        foreach (Database::PERMISSIONS as $type) {
            foreach ($document->getPermissionsByType($type) as $role) {
                $byRole[$role][] = self::actionLetter($type);
            }
        }

        if ($byRole === []) {
            return;
        }

        $hashKey = $this->permDocKey($collection, $id);
        $hashFields = [];
        $writes = [];
        foreach ($byRole as $role => $letters) {
            $unique = \array_values(\array_unique($letters));
            \sort($unique);
            $hashFields[$role] = \implode(',', $unique);
            foreach ($unique as $letter) {
                $writes[] = [$role, $letter];
            }
        }

        // Pipeline the SADD writes so a doc with N (role,action) pairs hits
        // Redis in a single round trip rather than N+1 sequential sends.
        $this->client->multi(\Redis::PIPELINE);
        try {
            foreach ($writes as [$role, $letter]) {
                $this->client->sAdd($this->permKey($collection, $letter, $role), $id);
            }
            $this->client->hMSet($hashKey, $hashFields);
            $this->client->exec();
        } catch (\Throwable $e) {
            // PIPELINE-mode discard is version-dependent across phpredis
            // (no-op in 5.x, raises in some 4.x). Swallow any failure here
            // so we propagate the original cause, not a teardown error.
            try {
                $this->client->discard();
            } catch (\Throwable) {
                // ignore
            }
            throw $e;
        }

        // Journal one entry per (role, letter) pair so rollback dispatches
        // through the existing 'createPerm' case without a bespoke handler.
        foreach ($writes as [$role, $letter]) {
            $this->journal('createPerm', [
                'collection' => $collection,
                'id' => $id,
                'role' => $role,
                'letter' => $letter,
            ]);
        }
    }

    /**
     * Strip every permission entry for ($collection, $id) from the inverted
     * sets and the per-doc HASH, recording the previous state in the journal
     * so T56 can replay it on rollback.
     *
     * NOTE: same nested-pipeline constraint as `writePermissions()`. MUST NOT
     * be wrapped inside a MULTI/EXEC. See `writePermissions()` docblock for
     * the refactor checklist if `tx()` ever gains real transaction support.
     */
    private function clearPermissions(string $collection, string $id): void
    {
        // Mirror writePermissions(): all perm-set operations key off the
        // lowercased id so reads and writes stay symmetric.
        $id = \strtolower($id);
        $hashKey = $this->permDocKey($collection, $id);
        /** @var array<string, string>|false $hash */
        $hash = $this->client->hGetAll($hashKey);
        if ($hash === false || $hash === []) {
            return;
        }

        $removals = [];
        foreach ($hash as $role => $letterCsv) {
            if ($letterCsv === '') {
                continue;
            }
            foreach (\explode(',', $letterCsv) as $letter) {
                $removals[] = [$role, $letter];
            }
        }

        // Pipeline the SREMs and HDEL together — one round trip per call site.
        $this->client->multi(\Redis::PIPELINE);
        try {
            foreach ($removals as [$role, $letter]) {
                $this->client->sRem($this->permKey($collection, $letter, $role), $id);
            }
            $this->client->del($hashKey);
            $this->client->exec();
        } catch (\Throwable $e) {
            // PIPELINE-mode discard is version-dependent across phpredis;
            // swallow the teardown error so we surface the original cause.
            try {
                $this->client->discard();
            } catch (\Throwable) {
                // ignore
            }
            throw $e;
        }

        // Emit one 'deletePerm' per pair so rollback can replay each SADD
        // and rehydrate the per-doc HASH entry independently.
        foreach ($removals as [$role, $letter]) {
            $this->journal('deletePerm', [
                'collection' => $collection,
                'id' => $id,
                'role' => $role,
                'letter' => $letter,
                'previous' => $hash[$role] ?? '',
            ]);
        }
    }

    /**
     * Restrict $ids to those visible to the current authorization context for
     * the given $action. Returns $ids unchanged when authorization is off so
     * privileged code paths bypass the filter.
     *
     * @param array<int, string> $ids
     * @return array<int, string>
     */
    private function applyPermissionFilter(string $collection, array $ids, string $action): array
    {
        if ($ids === []) {
            return $ids;
        }
        if ($this->authorization->getStatus() === false) {
            return $ids;
        }

        $roles = $this->authorization->getRoles();
        if ($roles === []) {
            return [];
        }

        $letter = self::actionLetter($action);
        $keys = [];
        foreach ($roles as $role) {
            $keys[] = $this->permKey($collection, $letter, $role);
        }

        if (\count($keys) === 1) {
            /** @var array<int, string>|false $allowed */
            $allowed = $this->client->sMembers($keys[0]);
        } else {
            $first = \array_shift($keys);
            /** @var array<int, string>|false $allowed */
            $allowed = $this->client->sUnion($first, ...$keys);
        }
        if ($allowed === false || $allowed === []) {
            return [];
        }

        $allowedSet = \array_flip($allowed);

        return \array_values(\array_filter($ids, static fn (string $id): bool => isset($allowedSet[$id])));
    }

    /**
     * Translate a `Database::PERMISSION_*` action string to the single-letter
     * suffix used in `{ns}:{db}:perm:{col}:{letter}:{role}` set keys.
     */
    private static function actionLetter(string $action): string
    {
        return match ($action) {
            Database::PERMISSION_READ => 'r',
            Database::PERMISSION_CREATE => 'c',
            Database::PERMISSION_UPDATE => 'u',
            Database::PERMISSION_DELETE => 'd',
            Database::PERMISSION_WRITE => 'w',
            default => throw new DatabaseException('Unknown permission action: ' . $action),
        };
    }

    /**
     * Resolve the tenant-bucket segment for shared-tables perm keys, mapping
     * a null tenant to the literal `'_'` so all shared-tables perm keys share
     * a single inversion convention. Returns null when shared tables are off.
     */
    private function tenantBucket(): ?string
    {
        if (! $this->getSharedTables()) {
            return null;
        }
        $tenant = $this->getTenant();

        return $tenant === null ? '_' : (string) $tenant;
    }

    /**
     * Build the role/action set key, scoping by tenant under shared tables so
     * cross-tenant role overlaps don't leak document ids.
     */
    private function permKey(string $collection, string $letter, string $role): string
    {
        $bucket = $this->tenantBucket();
        if ($bucket !== null) {
            return $this->ns() . self::SEP . 'perm' . self::SEP . 't' . self::SEP . $bucket . self::SEP . $collection . self::SEP . $letter . self::SEP . $role;
        }

        return $this->ns() . self::SEP . 'perm' . self::SEP . $collection . self::SEP . $letter . self::SEP . $role;
    }

    /**
     * Build the per-document role->letters HASH key for ($collection, $id),
     * applying the same tenant scoping as `permKey()` so reads/writes stay
     * symmetric under shared tables.
     */
    private function permDocKey(string $collection, string $id): string
    {
        $bucket = $this->tenantBucket();
        if ($bucket !== null) {
            return $this->ns() . self::SEP . 'perm' . self::SEP . 't' . self::SEP . $bucket . self::SEP . 'doc' . self::SEP . $collection . self::SEP . $id;
        }

        return $this->ns() . self::SEP . 'perm' . self::SEP . 'doc' . self::SEP . $collection . self::SEP . $id;
    }

    /**
     * Append a mutation entry to the topmost journal frame. Outside a
     * transaction the entry is dropped — non-transactional writes pay
     * zero overhead. The `op` discriminator drives `rollbackJournal()`'s
     * dispatch to raw inverse helpers.
     *
     * @param array<string, mixed> $payload
     */
    protected function journal(string $op, array $payload): void
    {
        if ($this->inTransaction === 0) {
            return;
        }
        $this->journalStack[\count($this->journalStack) - 1][] = [
            'op' => $op,
            'payload' => $payload,
        ];
    }

    /**
     * Pop the topmost journal frame and replay its inverse operations in
     * reverse order. Uses raw `\Redis` client commands only — calling a
     * public adapter method would re-enter `journal()` and recurse
     * infinitely. New `op` discriminators must be added to the dispatch
     * switch below.
     */
    protected function rollbackJournal(): void
    {
        $frame = \array_pop($this->journalStack);
        if ($frame === null) {
            return;
        }

        for ($i = \count($frame) - 1; $i >= 0; $i--) {
            $entry = $frame[$i];
            $op = $entry['op'];
            $payload = $entry['payload'];

            switch ($op) {
                case 'createDoc':
                    /** @var string $collection */
                    $collection = $payload['collection'];
                    /** @var string $id */
                    $id = $payload['id'];
                    $this->rawDeleteDoc(
                        $collection,
                        $id,
                        isset($payload['docKey']) ? (string) $payload['docKey'] : null,
                        isset($payload['idxKey']) ? (string) $payload['idxKey'] : null,
                        isset($payload['permDocKey']) ? (string) $payload['permDocKey'] : null,
                    );
                    break;

                case 'deleteDoc':
                    /** @var string $collection */
                    $collection = $payload['collection'];
                    /** @var string $id */
                    $id = $payload['id'];
                    /** @var string $beforePayload */
                    $beforePayload = $payload['payload'];
                    $this->rawRestoreDoc(
                        $collection,
                        $id,
                        $beforePayload,
                        isset($payload['docKey']) ? (string) $payload['docKey'] : null,
                        isset($payload['idxKey']) ? (string) $payload['idxKey'] : null,
                    );
                    break;

                case 'updateDoc':
                    /** @var string $collection */
                    $collection = $payload['collection'];
                    /** @var string $id */
                    $id = $payload['id'];
                    /** @var string $beforePayload */
                    $beforePayload = $payload['payload'];
                    $docKey = isset($payload['docKey']) ? (string) $payload['docKey'] : $this->docKey($collection, $id);
                    $this->client->set($docKey, $beforePayload);
                    // If the update changed the id, the new key must be removed
                    // and the old id restored to the index set.
                    if (isset($payload['newId']) && \is_string($payload['newId']) && $payload['newId'] !== $id) {
                        $newId = $payload['newId'];
                        $newDocKey = isset($payload['newDocKey']) ? (string) $payload['newDocKey'] : $this->docKey($collection, $newId);
                        $this->client->del($newDocKey);
                        $idxKey = isset($payload['idxKey']) ? (string) $payload['idxKey'] : $this->idxKey($collection);
                        $this->client->sRem($idxKey, \strtolower($newId));
                        $this->client->sAdd($idxKey, \strtolower($id));
                    }
                    break;

                case 'createPerm':
                    // Inverse of writePermissions: drop the (role, letter)
                    // membership and the per-doc HASH entry for that role.
                    /** @var string $collection */
                    $collection = $payload['collection'];
                    /** @var string $letter */
                    $letter = $payload['letter'];
                    /** @var string $role */
                    $role = $payload['role'];
                    /** @var string $id */
                    $id = $payload['id'];
                    $this->client->sRem($this->permKey($collection, $letter, $role), $id);
                    $this->client->hDel($this->permDocKey($collection, $id), $role);
                    break;

                case 'deletePerm':
                    // Inverse of clearPermissions: restore the (role, letter)
                    // membership and rehydrate the per-doc HASH entry.
                    /** @var string $collection */
                    $collection = $payload['collection'];
                    /** @var string $letter */
                    $letter = $payload['letter'];
                    /** @var string $role */
                    $role = $payload['role'];
                    /** @var string $id */
                    $id = $payload['id'];
                    $this->client->sAdd($this->permKey($collection, $letter, $role), $id);
                    if (isset($payload['previous']) && \is_string($payload['previous']) && $payload['previous'] !== '') {
                        $this->client->hSet($this->permDocKey($collection, $id), $role, $payload['previous']);
                    }
                    break;

                default:
                    throw new TransactionException('Unknown journal op: ' . $op);
            }
        }
    }

    /**
     * Pop the topmost journal frame and, when nested, splice its entries
     * onto the parent frame so an outer rollback still rewinds inner
     * work. At the outermost level the frame is discarded — Wave-2
     * writes go directly to Redis (no two-phase commit), so the journal
     * exists purely for rollback compensation.
     */
    protected function commitJournal(): void
    {
        $frame = \array_pop($this->journalStack);
        if ($frame === null) {
            return;
        }

        if ($frame !== [] && $this->journalStack !== []) {
            $outerIndex = \count($this->journalStack) - 1;
            \array_push($this->journalStack[$outerIndex], ...$frame);
        }
    }

    private function rawDeleteDoc(string $collection, string $id, ?string $docKey = null, ?string $idxKey = null, ?string $permDocKey = null): void
    {
        // writePermissions/clearPermissions key the per-doc HASH off the
        // lowercased id; lowercase here too so rollback of a mixed-case
        // create id actually deletes the perm doc HASH that was written.
        $lowerId = \strtolower($id);
        $this->client->del($docKey ?? $this->docKey($collection, $lowerId));
        $this->client->sRem($idxKey ?? $this->idxKey($collection), $lowerId);
        $this->client->del($permDocKey ?? $this->permDocKey($collection, $lowerId));
    }

    private function rawRestoreDoc(string $collection, string $id, string $payload, ?string $docKey = null, ?string $idxKey = null): void
    {
        $lowerId = \strtolower($id);
        $this->client->set($docKey ?? $this->docKey($collection, $lowerId), $payload);
        $this->client->sAdd($idxKey ?? $this->idxKey($collection), $lowerId);
    }

    /**
     * @param array<int, Query> $queries
     * @param array<int, string> $orderAttributes
     * @param array<int, string> $orderTypes
     * @param array<string, mixed> $cursor
     * @return array<int, Document>
     */
    protected function evaluateQueries(string $collection, array $queries, ?int $limit, ?int $offset, array $orderAttributes, array $orderTypes, array $cursor, string $cursorDirection): array
    {
        $collectionId = $this->filter($collection);
        $metaKey = $this->key($this->ns(), 'meta', $collectionId);

        if ((bool) $this->client->exists($metaKey) === false) {
            throw new NotFoundException('Collection not found');
        }

        return $this->tx(function (RedisClient $client) use ($collectionId, $queries, $limit, $offset, $orderAttributes, $orderTypes, $cursor, $cursorDirection): array {
            $documents = $this->loadCollectionDocuments($client, $collectionId, Database::PERMISSION_READ);
            $documents = $this->filterDocumentsByQueries($collectionId, $documents, $queries);
            $documents = $this->orderDocuments($documents, $orderAttributes, $orderTypes, $cursorDirection);
            $documents = $this->cursorDocuments($documents, $orderAttributes, $orderTypes, $cursor, $cursorDirection);

            if (! \is_null($offset)) {
                $documents = \array_slice($documents, $offset);
            }
            if (! \is_null($limit)) {
                $documents = \array_slice($documents, 0, $limit);
            }

            if ($cursorDirection === Database::CURSOR_BEFORE) {
                $documents = \array_reverse($documents);
            }

            return $documents;
        });
    }

    public function getDriver(): mixed
    {
        return 'redis';
    }

    public function setTimeout(int $milliseconds, string $event = Database::EVENT_ALL): void
    {
    }

    public function ping(): bool
    {
        return (bool) $this->client->ping();
    }

    public function reconnect(): void
    {
    }

    protected function quote(string $string): string
    {
        return '"' . $string . '"';
    }

    public function getLimitForString(): int
    {
        return 4294967295;
    }

    public function getLimitForInt(): int
    {
        return 4294967295;
    }

    public function getLimitForAttributes(): int
    {
        return 1017;
    }

    public function getLimitForIndexes(): int
    {
        return 64;
    }

    public function getMaxIndexLength(): int
    {
        return 1024;
    }

    public function getMaxVarcharLength(): int
    {
        return 16381;
    }

    public function getMaxUIDLength(): int
    {
        return 255;
    }

    public function getMinDateTime(): \DateTime
    {
        return new \DateTime('0001-01-01 00:00:00');
    }

    public function getIdAttributeType(): string
    {
        // Sequence ids are sourced from `INCR`, which returns integers.
        // The validator rejects string-valued sequences when this returns
        // VAR_STRING, so mirror Memory's VAR_INTEGER stance.
        return Database::VAR_INTEGER;
    }

    public function getSupportForSchemas(): bool
    {
        return true;
    }

    public function getSupportForAttributes(): bool
    {
        return true;
    }

    public function setSupportForAttributes(bool $support): bool
    {
        return true;
    }

    public function getSupportForSchemaAttributes(): bool
    {
        return false;
    }

    public function getSupportForSchemaIndexes(): bool
    {
        return false;
    }

    public function getSupportForIndex(): bool
    {
        return true;
    }

    public function getSupportForIndexArray(): bool
    {
        return false;
    }

    public function getSupportForCastIndexArray(): bool
    {
        return false;
    }

    public function getSupportForUniqueIndex(): bool
    {
        return true;
    }

    public function getSupportForFulltextIndex(): bool
    {
        return true;
    }

    public function getSupportForFulltextWildcardIndex(): bool
    {
        return false;
    }

    public function getSupportForCasting(): bool
    {
        return true;
    }

    public function getSupportForQueryContains(): bool
    {
        return true;
    }

    public function getSupportForTimeouts(): bool
    {
        return false;
    }

    public function getSupportForRelationships(): bool
    {
        return true;
    }

    public function getSupportForUpdateLock(): bool
    {
        return false;
    }

    public function getSupportForBatchOperations(): bool
    {
        return true;
    }

    public function getSupportForAttributeResizing(): bool
    {
        return true;
    }

    public function getSupportForGetConnectionId(): bool
    {
        return false;
    }

    public function getSupportForUpserts(): bool
    {
        return false;
    }

    public function getSupportForUpsertOnUniqueIndex(): bool
    {
        return false;
    }

    public function getSupportForVectors(): bool
    {
        return false;
    }

    public function getSupportForCacheSkipOnFailure(): bool
    {
        return false;
    }

    public function getSupportForCaching(): bool
    {
        // The Redis adapter is itself the store; reads hit Redis directly and
        // it runs with a no-op cache, so the Database cache layer is bypassed.
        return false;
    }

    public function getSupportForReconnection(): bool
    {
        return false;
    }

    public function getSupportForHostname(): bool
    {
        return false;
    }

    public function getSupportForBatchCreateAttributes(): bool
    {
        return true;
    }

    public function getSupportForSpatialAttributes(): bool
    {
        return false;
    }

    public function getSupportForObject(): bool
    {
        return true;
    }

    public function getSupportForObjectIndexes(): bool
    {
        return false;
    }

    public function getSupportForSpatialIndexNull(): bool
    {
        return false;
    }

    public function getSupportForOperators(): bool
    {
        return true;
    }

    public function getSupportForOptionalSpatialAttributeWithExistingRows(): bool
    {
        return false;
    }

    public function getSupportForSpatialIndexOrder(): bool
    {
        return false;
    }

    public function getSupportForSpatialAxisOrder(): bool
    {
        return false;
    }

    public function getSupportForBoundaryInclusiveContains(): bool
    {
        return false;
    }

    public function getSupportForDistanceBetweenMultiDimensionGeometryInMeters(): bool
    {
        return false;
    }

    public function getSupportForMultipleFulltextIndexes(): bool
    {
        return false;
    }

    public function getSupportForIdenticalIndexes(): bool
    {
        return false;
    }

    public function getSupportForOrderRandom(): bool
    {
        return true;
    }

    /**
     * Get max BIGINT limit
     *
     * @return int
     */
    public function getLimitForBigInt(): int
    {
        return Database::MAX_BIG_INT;
    }

    public function getSupportForInternalCasting(): bool
    {
        return false;
    }

    public function getSupportForUTCCasting(): bool
    {
        return false;
    }

    public function getSupportForIntegerBooleans(): bool
    {
        return false;
    }

    public function getSupportForAlterLocks(): bool
    {
        return false;
    }

    public function getSupportNonUtfCharacters(): bool
    {
        return false;
    }

    public function getSupportForTrigramIndex(): bool
    {
        return false;
    }

    public function getSupportForPCRERegex(): bool
    {
        return true;
    }

    public function getSupportForPOSIXRegex(): bool
    {
        return false;
    }

    public function getSupportForTransactionRetries(): bool
    {
        // The current `tx()` body is a network-error retry loop, not a
        // WATCH/MULTI/EXEC OCC implementation. Reporting `false` keeps the
        // shared trait's OCC-retry tests from running against semantics this
        // adapter doesn't yet provide. Mirror Memory's stance until a real
        // optimistic concurrency layer lands.
        return false;
    }

    public function getSupportForNestedTransactions(): bool
    {
        return true;
    }

    public function getCountOfDefaultAttributes(): int
    {
        return \count(Database::INTERNAL_ATTRIBUTES);
    }

    public function getCountOfDefaultIndexes(): int
    {
        return \count(Database::INTERNAL_INDEXES);
    }

    public function getDocumentSizeLimit(): int
    {
        return 0;
    }

    public function getAttributeWidth(Document $collection): int
    {
        return 0;
    }

    public function getKeywords(): array
    {
        return [];
    }

    /**
     * @param array<int, string> $selections
     */
    protected function getAttributeProjection(array $selections, string $prefix): mixed
    {
        return $selections;
    }

    public function getConnectionId(): string
    {
        return '0';
    }

    public function getInternalIndexesKeys(): array
    {
        return [];
    }

    public function getTenantQuery(string $collection, string $alias = ''): string
    {
        return '';
    }

    protected function execute(mixed $stmt): bool
    {
        return true;
    }

    public function decodePoint(string $wkb): array
    {
        throw new DatabaseException('Spatial types are not implemented in the Redis adapter');
    }

    public function decodeLinestring(string $wkb): array
    {
        throw new DatabaseException('Spatial types are not implemented in the Redis adapter');
    }

    public function decodePolygon(string $wkb): array
    {
        throw new DatabaseException('Spatial types are not implemented in the Redis adapter');
    }

    public function castingBefore(Document $collection, Document $document): Document
    {
        return $document;
    }

    public function castingAfter(Document $collection, Document $document): Document
    {
        return $document;
    }

    public function setUTCDatetime(string $value): mixed
    {
        return $value;
    }

    /**
     * Surface relationship attributes registered on the collection's meta.attrs
     * as null when the document does not carry them — mirrors MariaDB selecting
     * a `DEFAULT NULL` column even when no row has set it (and Memory's
     * `documentToRow` null-surface pass).
     *
     * METADATA is exempt: relationship attributes for user collections are
     * nested inside the metadata row's `attributes` payload, not stored as
     * top-level keys. Surfacing nulls there would clobber that nested array.
     */
    private function surfaceRelationshipAttributes(string $collection, Document $document): Document
    {
        if ($collection === Database::METADATA) {
            return $document;
        }

        $metaKey = $this->key($this->ns(), 'meta', $this->filter($collection));
        $attributes = $this->readAttributesField($this->client, $metaKey);
        $relationshipKeys = $this->extractRelationshipKeys($attributes);
        if ($relationshipKeys === []) {
            return $document;
        }

        return $this->surfaceRelationshipAttributesUsing($relationshipKeys, $document);
    }

    /**
     * Loop-friendly companion to `surfaceRelationshipAttributes`. Callers that
     * iterate large result sets (e.g. `find()` / `loadCollectionDocuments`)
     * read meta.attrs once, derive the relationship key list via
     * `extractRelationshipKeys`, and pass it here per document — avoiding N
     * round trips to Redis for the same meta hash.
     *
     * @param array<int, string> $relationshipKeys
     */
    private function surfaceRelationshipAttributesUsing(array $relationshipKeys, Document $document): Document
    {
        if ($relationshipKeys === []) {
            return $document;
        }

        $payload = $document->getArrayCopy();
        foreach ($relationshipKeys as $key) {
            if (! \array_key_exists($key, $payload)) {
                $document->setAttribute($key, null);
            }
        }

        return $document;
    }

    /**
     * Extract the list of relationship attribute keys from a decoded
     * meta.attrs records array. Returned as a positional list so callers can
     * iterate without extra `array_keys` calls.
     *
     * @param array<int, array<string, mixed>> $attributes
     * @return array<int, string>
     */
    private function extractRelationshipKeys(array $attributes): array
    {
        $keys = [];
        foreach ($attributes as $attribute) {
            if (($attribute['type'] ?? null) !== Database::VAR_RELATIONSHIP) {
                continue;
            }
            $key = (string) ($attribute['$id'] ?? $attribute['key'] ?? '');
            if ($key === '') {
                continue;
            }
            $keys[] = $key;
        }

        return $keys;
    }

    /**
     * Rename a top-level field across every document in a collection. Mirrors
     * Memory's `renameDocumentField`. Used by `updateRelationship` to migrate
     * stored payloads when a relationship key is renamed.
     *
     * Schema-level (non-journalled): same convention as `createAttribute` /
     * `renameAttribute` — schema mutations are not transactional and therefore
     * do not register inverse entries with `journal()`. The transaction
     * wrapper is used solely to surface `\RedisException` as
     * `TransactionException`.
     */
    private function renameDocumentField(string $collection, string $oldKey, string $newKey): void
    {
        $collection = $this->filter($collection);
        $oldKey = $this->filter($oldKey);
        $newKey = $this->filter($newKey);

        if ($oldKey === $newKey) {
            return;
        }

        $idxKey = $this->idxKey($collection);

        $this->tx(function (RedisClient $client) use ($collection, $oldKey, $newKey, $idxKey): void {
            /** @var array<int, string>|false $docIds */
            $docIds = $client->sMembers($idxKey);
            if (! \is_array($docIds) || $docIds === []) {
                return;
            }

            foreach ($docIds as $docId) {
                $docKey = $this->docKey($collection, $docId);
                $payload = $client->get($docKey);
                if (! \is_string($payload) || $payload === '') {
                    continue;
                }

                /** @var array<string, mixed> $decoded */
                $decoded = \json_decode($payload, true, self::JSON_DECODE_DEPTH, JSON_THROW_ON_ERROR);
                if (! \array_key_exists($oldKey, $decoded)) {
                    continue;
                }

                $decoded[$newKey] = $decoded[$oldKey];
                unset($decoded[$oldKey]);

                $client->set(
                    $docKey,
                    \json_encode($decoded, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION),
                );
            }
        });
    }

    /**
     * Remove a top-level field from every document in a collection. Mirrors
     * Memory's `dropDocumentField`. Used by `deleteRelationship` to scrub
     * stored payloads when a relationship column is dropped.
     *
     * Same non-journalled schema-op contract as `renameDocumentField`.
     */
    private function dropDocumentField(string $collection, string $field): void
    {
        $collection = $this->filter($collection);
        $field = $this->filter($field);
        $idxKey = $this->idxKey($collection);

        $this->tx(function (RedisClient $client) use ($collection, $field, $idxKey): void {
            /** @var array<int, string>|false $docIds */
            $docIds = $client->sMembers($idxKey);
            if (! \is_array($docIds) || $docIds === []) {
                return;
            }

            foreach ($docIds as $docId) {
                $docKey = $this->docKey($collection, $docId);
                $payload = $client->get($docKey);
                if (! \is_string($payload) || $payload === '') {
                    continue;
                }

                /** @var array<string, mixed> $decoded */
                $decoded = \json_decode($payload, true, self::JSON_DECODE_DEPTH, JSON_THROW_ON_ERROR);
                if (! \array_key_exists($field, $decoded)) {
                    continue;
                }

                unset($decoded[$field]);

                $client->set(
                    $docKey,
                    \json_encode($decoded, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION),
                );
            }
        });
    }

    /**
     * Resolve the junction collection name for an M2M relationship. Mirrors
     * `Database::getJunctionCollection` — the junction is named after the
     * parent/child sequence pair (`_{parent}_{child}` for the parent side,
     * reversed for the child side).
     *
     * Reads the METADATA collection's docs for both sides and extracts each
     * `$sequence`. Returns null when either METADATA row is missing or has
     * no sequence — callers treat that as a no-op (skip the rename).
     */
    private function resolveJunctionCollection(string $collection, string $relatedCollection, string $side): ?string
    {
        $collectionDoc = $this->loadMetadataDocument($collection);
        $relatedDoc = $this->loadMetadataDocument($relatedCollection);
        if ($collectionDoc === null || $relatedDoc === null) {
            return null;
        }

        $collectionSequence = $collectionDoc->getSequence();
        $relatedSequence = $relatedDoc->getSequence();
        if ($collectionSequence === null || $relatedSequence === null || $collectionSequence === '' || $relatedSequence === '') {
            return null;
        }

        return $side === Database::RELATION_SIDE_PARENT
            ? '_' . $collectionSequence . '_' . $relatedSequence
            : '_' . $relatedSequence . '_' . $collectionSequence;
    }

    /**
     * Read a single METADATA document directly from the doc key, bypassing
     * the public `getDocument` path so this helper can be called from inside
     * schema operations (which build a Document collection lazily).
     */
    private function loadMetadataDocument(string $collection): ?Document
    {
        $id = $this->filter($collection);
        $payload = $this->client->get($this->docKey(Database::METADATA, $id));
        // Fall back to the null-tenant METADATA row under shared tables —
        // bootstrap writes the global metadata schema with $tenant=null.
        if ((! \is_string($payload) || $payload === '') && $this->getSharedTables()) {
            $payload = $this->client->get($this->docKey(Database::METADATA, $id, '_'));
        }
        if (! \is_string($payload) || $payload === '') {
            return null;
        }

        return $this->decode($payload);
    }

    // === @architect:T20 owns: schema + collection + attribute ops ===

    public function create(string $name): bool
    {
        $name = $this->filter($name);
        $dbsKey = $this->key($this->nsBase(), 'dbs');

        $this->tx(fn (RedisClient $client) => $client->sAdd($dbsKey, $name));

        return true;
    }

    public function exists(string $database, ?string $collection = null): bool
    {
        $database = $this->filter($database);
        $dbsKey = $this->key($this->nsBase(), 'dbs');

        if ((bool) $this->client->sIsMember($dbsKey, $database) === false) {
            return false;
        }

        if ($collection === null) {
            return true;
        }

        $collection = $this->filter($collection);
        $namespace = $this->getNamespace();
        $colsKey = $this->key($this->nsFor($namespace, $database), 'cols');

        return (bool) $this->client->sIsMember($colsKey, $collection);
    }

    public function list(): array
    {
        $dbsKey = $this->key($this->nsBase(), 'dbs');
        /** @var array<int, string>|false $names */
        $names = $this->client->sMembers($dbsKey);
        if ($names === false) {
            $names = [];
        }

        $databases = [];
        foreach ($names as $name) {
            $databases[] = new Document(['name' => $name]);
        }

        return $databases;
    }

    public function delete(string $name): bool
    {
        $name = $this->filter($name);
        $namespace = $this->getNamespace();
        $dbsKey = $this->key($this->nsBase(), 'dbs');
        $colsKey = $this->key($this->nsFor($namespace, $name), 'cols');

        $this->tx(function (RedisClient $client) use ($name, $namespace, $dbsKey, $colsKey): void {
            /** @var array<int, string>|false $collections */
            $collections = $client->sMembers($colsKey);
            if (\is_array($collections)) {
                foreach ($collections as $collection) {
                    $this->purgeCollectionKeys($client, $namespace, $name, $collection);
                }
            }

            $client->del($colsKey);
            $client->sRem($dbsKey, $name);
        });

        return true;
    }

    public function createCollection(string $name, array $attributes = [], array $indexes = []): bool
    {
        $id = $this->filter($name);
        $colsKey = $this->key($this->ns(), 'cols');
        $metaKey = $this->key($this->ns(), 'meta', $id);
        $idxKey = $this->idxKey($id);

        if ((bool) $this->client->exists($metaKey)) {
            throw new DuplicateException('Collection already exists');
        }

        $attributePayload = [];
        foreach ($attributes as $attribute) {
            $attributePayload[] = $attribute->getArrayCopy();
        }
        $indexPayload = [];
        foreach ($indexes as $index) {
            $indexPayload[] = $index->getArrayCopy();
        }

        $schema = new Document([
            '$id' => $id,
            'name' => $name,
            'attributes' => $attributePayload,
            'indexes' => $indexPayload,
        ]);

        $this->tx(function (RedisClient $client) use ($id, $colsKey, $metaKey, $idxKey, $schema, $attributePayload, $indexPayload): void {
            $client->hMSet($metaKey, [
                'schema' => \json_encode($schema->getArrayCopy(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'attrs' => \json_encode($attributePayload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'indexes' => \json_encode($indexPayload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'docCount' => '0',
                'sizeBytes' => '0',
            ]);
            // Reserve the doc-id index set so SCAN/list operations work even
            // before the first document write. Redis cannot persist empty
            // sets, so we materialise the key on first write — but we still
            // delete it on collection drop to clean up any prior contents.
            $client->del($idxKey);
            $client->sAdd($colsKey, $id);
        });

        return true;
    }

    public function deleteCollection(string $id): bool
    {
        $id = $this->filter($id);
        $namespace = $this->getNamespace();
        $database = $this->getDatabase();
        $colsKey = $this->key($this->ns(), 'cols');

        $this->tx(function (RedisClient $client) use ($id, $namespace, $database, $colsKey): void {
            $this->purgeCollectionKeys($client, $namespace, $database, $id);
            $client->sRem($colsKey, $id);
        });

        return true;
    }

    public function analyzeCollection(string $collection): bool
    {
        // Redis maintains no internal table statistics; mirrors Memory's
        // behavior for adapters without a stats subsystem.
        return false;
    }

    public function getSizeOfCollection(string $collection): int
    {
        return $this->computeCollectionSize($collection);
    }

    public function getSizeOfCollectionOnDisk(string $collection): int
    {
        // Redis stores the working set in memory; on-disk size mirrors
        // logical size for the purposes of the size-tracking tests.
        return $this->computeCollectionSize($collection);
    }

    public function createAttribute(string $collection, string $id, string $type, int $size, bool $signed = true, bool $array = false, bool $required = false): bool
    {
        $collection = $this->filter($collection);
        $id = $this->filter($id);
        $metaKey = $this->key($this->ns(), 'meta', $collection);

        if ((bool) $this->client->exists($metaKey) === false) {
            throw new NotFoundException('Collection not found');
        }

        $this->tx(function (RedisClient $client) use ($metaKey, $id, $type, $size, $signed, $array, $required): void {
            $attrs = $this->readAttributesField($client, $metaKey);
            $attrs = $this->upsertAttributeRecord($attrs, [
                '$id' => $id,
                'key' => $id,
                'type' => $type,
                'size' => $size,
                'signed' => $signed,
                'array' => $array,
                'required' => $required,
            ]);
            $client->hSet(
                $metaKey,
                'attrs',
                \json_encode($attrs, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            );
        });

        return true;
    }

    public function createAttributes(string $collection, array $attributes): bool
    {
        foreach ($attributes as $attribute) {
            $this->createAttribute(
                $collection,
                (string) $attribute['$id'],
                (string) $attribute['type'],
                (int) ($attribute['size'] ?? 0),
                (bool) ($attribute['signed'] ?? true),
                (bool) ($attribute['array'] ?? false),
                (bool) ($attribute['required'] ?? false),
            );
        }

        return true;
    }

    public function updateAttribute(string $collection, string $id, string $type, int $size, bool $signed = true, bool $array = false, ?string $newKey = null, bool $required = false): bool
    {
        $collection = $this->filter($collection);
        $id = $this->filter($id);
        $metaKey = $this->key($this->ns(), 'meta', $collection);

        if ((bool) $this->client->exists($metaKey) === false) {
            throw new NotFoundException('Collection not found');
        }

        if (! empty($newKey) && $newKey !== $id) {
            $this->renameAttribute($collection, $id, $newKey);
            $id = $this->filter($newKey);
        }

        $this->tx(function (RedisClient $client) use ($metaKey, $id, $type, $size, $signed, $array, $required): void {
            $attrs = $this->readAttributesField($client, $metaKey);
            $attrs = $this->upsertAttributeRecord($attrs, [
                '$id' => $id,
                'key' => $id,
                'type' => $type,
                'size' => $size,
                'signed' => $signed,
                'array' => $array,
                'required' => $required,
            ]);
            $client->hSet(
                $metaKey,
                'attrs',
                \json_encode($attrs, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            );
        });

        return true;
    }

    public function deleteAttribute(string $collection, string $id): bool
    {
        $collection = $this->filter($collection);
        $id = $this->filter($id);
        $metaKey = $this->key($this->ns(), 'meta', $collection);

        if ((bool) $this->client->exists($metaKey) === false) {
            return true;
        }

        $this->tx(function (RedisClient $client) use ($metaKey, $id): void {
            $attrs = $this->readAttributesField($client, $metaKey);
            $filtered = [];
            foreach ($attrs as $attribute) {
                $existingId = (string) ($attribute['$id'] ?? $attribute['key'] ?? '');
                if ($this->filter($existingId) === $id) {
                    continue;
                }
                $filtered[] = $attribute;
            }
            $client->hSet(
                $metaKey,
                'attrs',
                \json_encode($filtered, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            );
        });

        $this->dropDocumentField($collection, $id);

        return true;
    }

    public function renameAttribute(string $collection, string $old, string $new): bool
    {
        $collection = $this->filter($collection);
        $old = $this->filter($old);
        $new = $this->filter($new);
        $metaKey = $this->key($this->ns(), 'meta', $collection);

        if ((bool) $this->client->exists($metaKey) === false) {
            throw new NotFoundException('Collection not found');
        }

        $this->tx(function (RedisClient $client) use ($metaKey, $old, $new): void {
            $attrs = $this->readAttributesField($client, $metaKey);
            $touched = false;
            foreach ($attrs as $i => $attribute) {
                $existingId = (string) ($attribute['$id'] ?? $attribute['key'] ?? '');
                if ($this->filter($existingId) !== $old) {
                    continue;
                }
                $attribute['$id'] = $new;
                $attribute['key'] = $new;
                $attrs[$i] = $attribute;
                $touched = true;
            }
            if (! $touched) {
                return;
            }
            $client->hSet(
                $metaKey,
                'attrs',
                \json_encode($attrs, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            );
        });

        $this->renameDocumentField($collection, $old, $new);

        return true;
    }

    public function getSchemaAttributes(string $collection): array
    {
        return [];
    }

    public function getCountOfAttributes(Document $collection): int
    {
        return \count($collection->getAttribute('attributes', [])) + $this->getCountOfDefaultAttributes();
    }

    /**
     * Read and decode the `attrs` JSON field on a collection meta hash. Returns
     * a plain list of attribute record arrays (empty when the field is absent
     * or stored empty).
     *
     * @return array<int, array<string, mixed>>
     */
    private function readAttributesField(RedisClient $client, string $metaKey): array
    {
        $raw = $client->hGet($metaKey, 'attrs');
        if (! \is_string($raw) || $raw === '') {
            return [];
        }
        /** @var array<int, array<string, mixed>> $decoded */
        $decoded = \json_decode($raw, true, self::JSON_DECODE_DEPTH, JSON_THROW_ON_ERROR);

        return \array_values($decoded);
    }

    /**
     * Pre-flight unique-index check: scan the collection's existing rows for
     * conflicts with `$document` against every UNIQUE index on the collection,
     * mirroring Memory's `checkUniqueSignatures`. Throws DuplicateException
     * on the first collision so callers don't waste a write round trip when
     * MariaDB would have rejected the row.
     *
     * `$excludeId` lets `updateDocument` skip the document being updated.
     */
    private function enforceUniqueIndexes(RedisClient $client, string $collection, Document $document, ?string $excludeId = null): void
    {
        $metaKey = $this->key($this->ns(), 'meta', $collection);
        $indexes = $this->readIndexesField($client, $metaKey);

        $uniqueIndexes = [];
        foreach ($indexes as $index) {
            if (($index['type'] ?? '') !== Database::INDEX_UNIQUE) {
                continue;
            }
            $attributes = $index['attributes'] ?? [];
            if (empty($attributes)) {
                continue;
            }
            $uniqueIndexes[] = $attributes;
        }

        if ($uniqueIndexes === []) {
            return;
        }

        // Build the new document's signatures up-front. Indexes that have any
        // null component are treated as distinct (mirrors MariaDB's UNIQUE
        // semantics — NULL never collides with another NULL).
        $newSignatures = [];
        $sharedTables = $this->getSharedTables();
        $tenant = $sharedTables ? ($document->getAttribute('$tenant') ?? $this->getTenant()) : null;
        foreach ($uniqueIndexes as $i => $attributes) {
            $signature = [];
            $hasNull = false;
            foreach ($attributes as $attribute) {
                $value = $this->resolveDocumentAttribute($document, (string) $attribute);
                if ($value === null) {
                    $hasNull = true;
                    break;
                }
                $signature[] = $this->normalizeIndexValue($value);
            }
            if ($hasNull) {
                continue;
            }
            if ($sharedTables) {
                \array_unshift($signature, $tenant);
            }
            $newSignatures[$i] = \serialize($signature);
        }

        if ($newSignatures === []) {
            return;
        }

        $idxKey = $this->idxKey($collection);
        /** @var array<int, string> $docIds */
        $docIds = $client->sMembers($idxKey);
        if (empty($docIds)) {
            return;
        }

        $excludeKey = $excludeId !== null ? \strtolower($excludeId) : null;
        $docKeys = [];
        foreach ($docIds as $docId) {
            if ($excludeKey !== null && \strtolower((string) $docId) === $excludeKey) {
                continue;
            }
            $docKeys[(string) $docId] = $this->docKey($collection, (string) $docId);
        }
        if ($docKeys === []) {
            return;
        }

        /** @var array<int, mixed> $payloads */
        $payloads = $client->mGet(\array_values($docKeys));
        $position = 0;
        foreach ($docKeys as $docId => $_) {
            $payload = $payloads[$position++] ?? null;
            if (! \is_string($payload) || $payload === '') {
                continue;
            }
            $existing = $this->decode($payload);
            if ($sharedTables) {
                $rowTenant = $existing->getAttribute('$tenant');
                if ($rowTenant !== $tenant) {
                    continue;
                }
            }
            foreach ($newSignatures as $i => $newHash) {
                $attributes = $uniqueIndexes[$i];
                $signature = [];
                $hasNull = false;
                foreach ($attributes as $attribute) {
                    $value = $this->resolveDocumentAttribute($existing, (string) $attribute);
                    if ($value === null) {
                        $hasNull = true;
                        break;
                    }
                    $signature[] = $this->normalizeIndexValue($value);
                }
                if ($hasNull) {
                    continue;
                }
                if ($sharedTables) {
                    \array_unshift($signature, $tenant);
                }
                if (\serialize($signature) === $newHash) {
                    throw new UniqueException('Unique index violation');
                }
            }
        }
    }

    /**
     * Insert or replace an attribute record matched by `$id`/`key`. Returns a
     * fresh list (re-indexed) so the JSON encodes as an array, never an object.
     *
     * @param array<int, array<string, mixed>> $attrs
     * @param array<string, mixed> $record
     * @return array<int, array<string, mixed>>
     */
    private function upsertAttributeRecord(array $attrs, array $record): array
    {
        $targetId = (string) ($record['$id'] ?? '');
        $replaced = false;
        foreach ($attrs as $i => $existing) {
            $existingId = (string) ($existing['$id'] ?? $existing['key'] ?? '');
            if ($existingId !== $targetId) {
                continue;
            }
            $attrs[$i] = $record;
            $replaced = true;
            break;
        }
        if (! $replaced) {
            $attrs[] = $record;
        }

        return \array_values($attrs);
    }

    /**
     * Drop every key associated with a single collection inside `{ns}:{db}`.
     * Used by both deleteCollection and the cascading delete() path. Permission
     * sets and document blobs are SCANned because we can't enumerate them
     * without an index — the doc-id set under `idx:{col}` is authoritative for
     * existing documents but permission roles vary, so we SCAN the prefix.
     */
    private function purgeCollectionKeys(RedisClient $client, string $namespace, string $database, string $collection): void
    {
        $collection = $this->filter($collection);
        $prefix = $this->nsFor($namespace, $database);
        $metaKey = $this->key($prefix, 'meta', $collection);
        $idxKey = $this->key($prefix, 'idx', $collection);
        $seqKey = $this->key($prefix, 'seq', $collection);

        // Non-shared layout: walk the doc-id index for variadic DEL of every
        // doc + perm-doc HASH. Cheap when the set is empty.
        /** @var array<int, string>|false $docIds */
        $docIds = $client->sMembers($idxKey);
        if (\is_array($docIds) && $docIds !== []) {
            $keys = [];
            foreach ($docIds as $docId) {
                $keys[] = $this->key($prefix, 'doc', $collection, $docId);
                $keys[] = $this->key($prefix, 'perm', 'doc', $collection, $docId);
                if (\count($keys) >= self::SCAN_BATCH_SIZE) {
                    $client->del(...$keys);
                    $keys = [];
                }
            }
            if ($keys !== []) {
                $client->del(...$keys);
            }
        }

        // Shared-tables doc/idx/seq sweep: tenants-bucketed under
        // `{prefix}:doc:t:{tenant}:{col}:*`, `{prefix}:idx:t:{tenant}:{col}`
        // and `{prefix}:seq:t:{tenant}:{col}`. Run unconditionally so a
        // collection populated while shared-tables was on can still be
        // purged after the test resets the flag back off.
        $this->deleteByPattern($client, $prefix . self::SEP . 'doc' . self::SEP . 't' . self::SEP . '*' . self::SEP . $collection . self::SEP . '*');
        $this->deleteByPattern($client, $prefix . self::SEP . 'idx' . self::SEP . 't' . self::SEP . '*' . self::SEP . $collection);
        $this->deleteByPattern($client, $prefix . self::SEP . 'seq' . self::SEP . 't' . self::SEP . '*' . self::SEP . $collection);

        // Non-shared-tables perm-set sweep. permKey() emits this layout when
        // shared tables is OFF: `{prefix}:perm:{col}:{letter}:{role}`.
        $this->deleteByPattern($client, $this->key($prefix, 'perm', $collection) . self::SEP . '*');
        // Shared-tables perm sweep. permKey()/permDocKey() emit
        // `{prefix}:perm:t:{tenant}:{col}:...` and
        // `{prefix}:perm:t:{tenant}:doc:{col}:...` respectively. The non-shared
        // pattern above does NOT match these, so without this sweep dropping a
        // collection under shared tables leaves stale role/doc HASH keys
        // behind — and a recreated collection inherits stale grants.
        $this->deleteByPattern($client, $prefix . self::SEP . 'perm' . self::SEP . 't' . self::SEP . '*' . self::SEP . $collection . self::SEP . '*');
        $this->deleteByPattern($client, $prefix . self::SEP . 'perm' . self::SEP . 't' . self::SEP . '*' . self::SEP . 'doc' . self::SEP . $collection . self::SEP . '*');
        $this->deleteByPattern($client, $this->key($prefix, 'tenants', $collection) . self::SEP . '*');

        $client->del($metaKey, $idxKey, $seqKey);
    }

    /**
     * SCAN-and-DEL helper — MATCHes the supplied glob in batches so we don't
     * block the server with a giant KEYS call. Honours the same 500-key batch
     * size used by the test harness teardown.
     */
    private function deleteByPattern(RedisClient $client, string $pattern): void
    {
        $cursor = null;
        do {
            /** @var array<int, string>|false $batch */
            $batch = $client->scan($cursor, $pattern, self::SCAN_BATCH_SIZE);
            if (\is_array($batch) && $batch !== []) {
                $client->del(...$batch);
            }
        } while ($cursor !== 0 && $cursor !== null);
    }

    /**
     * Compute the size of a collection by summing memory used by its meta
     * hash, every document blob, the doc-id index, and any permission sets.
     *
     * Redis `MEMORY USAGE` is used when supported (Redis 4.0+). We fall back
     * to STRLEN/HLEN approximations so the adapter still produces a non-zero
     * size on builds (or test doubles) where MEMORY USAGE isn't routed.
     */
    private function computeCollectionSize(string $collection): int
    {
        $collection = $this->filter($collection);
        $metaKey = $this->key($this->ns(), 'meta', $collection);

        if ((bool) $this->client->exists($metaKey) === false) {
            return 0;
        }

        $total = $this->measureKey($metaKey);

        $idxKey = $this->idxKey($collection);
        $total += $this->measureKey($idxKey);

        /** @var array<int, string>|false $docIds */
        $docIds = $this->client->sMembers($idxKey);
        if (\is_array($docIds)) {
            foreach ($docIds as $docId) {
                $total += $this->measureKey($this->docKey($collection, (string) $docId));
                // Route through permDocKey() so the tenant-bucketed shape is
                // honoured under shared tables; otherwise the per-document
                // perm HASH is missed entirely.
                $total += $this->measureKey($this->permDocKey($collection, (string) $docId));
            }
        }

        // Inverted permission SETs live under permKey()'s shape — tenant
        // bucketed under shared tables, flat otherwise. Pick the matching
        // SCAN prefix so both layouts contribute to the size estimate.
        $bucket = $this->tenantBucket();
        if ($bucket !== null) {
            $permPrefix = $this->ns() . self::SEP . 'perm' . self::SEP . 't' . self::SEP . $bucket . self::SEP . $collection . self::SEP . '*';
        } else {
            $permPrefix = $this->key($this->ns(), 'perm', $collection) . self::SEP . '*';
        }
        $cursor = null;
        do {
            /** @var array<int, string>|false $batch */
            $batch = $this->client->scan($cursor, $permPrefix, self::SCAN_BATCH_SIZE);
            if (\is_array($batch)) {
                foreach ($batch as $key) {
                    $total += $this->measureKey($key);
                }
            }
        } while ($cursor !== 0 && $cursor !== null);

        return $total;
    }

    /**
     * Best-effort size probe for a single Redis key. Prefers `MEMORY USAGE`
     * (returns the bytes Redis itself reports). Falls back to the encoded
     * payload length when MEMORY USAGE is unavailable, so the result remains
     * a stable monotonically-growing integer for size-tracking tests.
     */
    private function measureKey(string $key): int
    {
        try {
            /** @var int|false|null $usage */
            $usage = $this->client->rawCommand('MEMORY', 'USAGE', $key);
            if (\is_int($usage)) {
                return $usage;
            }
        } catch (\Throwable) {
            // Fall through to the structural fallback below.
        }

        $type = $this->client->type($key);
        switch ($type) {
            case RedisClient::REDIS_STRING:
                $value = $this->client->get($key);

                return \is_string($value) ? \strlen($value) + \strlen($key) : 0;
            case RedisClient::REDIS_HASH:
                $entries = $this->client->hGetAll($key);
                $bytes = \strlen($key);
                if (\is_array($entries)) {
                    foreach ($entries as $field => $value) {
                        $bytes += \strlen((string) $field) + \strlen((string) $value);
                    }
                }

                return $bytes;
            case RedisClient::REDIS_SET:
                $members = $this->client->sMembers($key);
                $bytes = \strlen($key);
                if (\is_array($members)) {
                    foreach ($members as $member) {
                        $bytes += \strlen((string) $member);
                    }
                }

                return $bytes;
            default:
                return 0;
        }
    }

    // === @architect:T20 end ===





    // === @architect:T30 owns: document CRUD + bulk + increase ===

    public function getDocument(Document $collection, string $id, array $queries = [], bool $forUpdate = false): Document
    {
        $col = $this->filter($collection->getId());
        $payload = $this->client->get($this->docKey($col, $id));
        // Mirror Memory's METADATA fallback: under shared tables the
        // bootstrap METADATA row is written with a null tenant and must
        // be visible to every tenant.
        if ((! \is_string($payload) || $payload === '') && $this->getSharedTables() && $col === Database::METADATA) {
            $payload = $this->client->get($this->docKey($col, $id, '_'));
        }

        if (! \is_string($payload) || $payload === '') {
            return new Document([]);
        }

        $document = $this->decode($payload);

        // Mirror the loadCollectionDocuments tenant filter: under shared
        // tables a doc key written for tenant A must not surface for tenant
        // B. Permission filtering can't catch this on the single-doc path
        // because the caller already knows the id. METADATA collections
        // are exempt — they intentionally serve null-tenant rows to every
        // tenant.
        if ($this->getSharedTables()) {
            $rowTenant = $document->getAttribute('$tenant');
            $tenant = $this->getTenant();
            $allowNullTenant = $col === Database::METADATA && $rowTenant === null;
            if (! $allowNullTenant && $rowTenant !== $tenant) {
                return new Document([]);
            }
        }

        if ($col !== Database::METADATA) {
            $document = $this->surfaceRelationshipAttributes($col, $document);
        }

        $selections = [];
        foreach ($queries as $query) {
            if ($query instanceof Query && $query->getMethod() === Query::TYPE_SELECT) {
                foreach ($query->getValues() as $value) {
                    $selections[] = (string) $value;
                }
            }
        }

        if (! empty($selections) && ! \in_array('*', $selections, true)) {
            $projected = [];
            foreach ($document->getArrayCopy() as $field => $value) {
                if (\str_starts_with((string) $field, '$') || \str_starts_with((string) $field, '_')) {
                    $projected[$field] = $value;

                    continue;
                }
                if (\in_array($field, $selections, true)) {
                    $projected[$field] = $value;
                }
            }
            $document = new Document($projected);
        }

        return $document;
    }

    public function createDocument(Document $collection, Document $document): Document
    {
        $col = $this->filter($collection->getId());
        $id = $document->getId();
        if ($id === '') {
            $id = ID::unique();
            $document->setAttribute('$id', $id);
        }
        $tenant = $document->getTenant();
        $docKey = $this->docKey($col, $id, $tenant);
        $idxKey = $this->idxKey($col, $tenant);
        $seqKey = $this->seqKey($col, $tenant);
        $permDocKey = $this->permDocKey($col, $id);

        return $this->tx(function (RedisClient $redis) use ($col, $id, $document, $docKey, $idxKey, $seqKey, $permDocKey): Document {
            if ((bool) $redis->exists($docKey)) {
                if ($this->skipDuplicates) {
                    // Mirrors MariaDB's `INSERT IGNORE` and Memory's skipDuplicates path:
                    // duplicate primary key is silently dropped and the existing row's
                    // sequence is returned so the caller can still emit an onNext event.
                    $existingPayload = $redis->get($docKey);
                    if (\is_string($existingPayload) && $existingPayload !== '') {
                        $existing = $this->decode($existingPayload);
                        $document->setAttribute('$sequence', $existing->getSequence() ?? '');
                    }

                    return $document;
                }
                throw new DuplicateException('Document already exists');
            }

            try {
                $this->enforceUniqueIndexes($redis, $col, $document);
            } catch (DuplicateException $e) {
                if ($this->skipDuplicates) {
                    return $document;
                }
                throw $e;
            }

            $sequence = $document->getSequence();
            if (empty($sequence)) {
                $next = $redis->incr($seqKey);
                $sequence = (string) $next;
            } else {
                $sequence = (string) $sequence;
                $current = $redis->get($seqKey);
                if (! \is_string($current) || (int) $sequence > (int) $current) {
                    $redis->set($seqKey, $sequence);
                }
            }
            $document->setAttribute('$sequence', $sequence);

            $redis->set($docKey, $this->encode($document));
            $redis->sAdd($idxKey, \strtolower($id));

            $this->writePermissions($col, $id, $document);
            $this->journal('createDoc', [
                'collection' => $col,
                'id' => $id,
                'docKey' => $docKey,
                'idxKey' => $idxKey,
                'permDocKey' => $permDocKey,
            ]);

            return $document;
        });
    }

    public function createDocuments(Document $collection, array $documents): array
    {
        $created = [];
        foreach ($documents as $document) {
            $created[] = $this->createDocument($collection, $document);
        }

        return $created;
    }

    public function updateDocument(Document $collection, string $id, Document $document, bool $skipPermissions): Document
    {
        $col = $this->filter($collection->getId());
        $oldKey = $this->docKey($col, $id);
        $idxKey = $this->idxKey($col);
        // METADATA fallback: under shared tables the bootstrap METADATA row
        // is written with a null tenant; subsequent updates from another
        // tenant must still resolve to that row instead of throwing.
        $useNullTenant = false;
        if ($col === Database::METADATA && $this->getSharedTables() && $this->getTenant() !== null) {
            if ((bool) $this->client->exists($oldKey) === false) {
                $oldKey = $this->docKey($col, $id, '_');
                $useNullTenant = true;
            }
        }

        return $this->tx(function (RedisClient $redis) use ($col, $id, $document, $skipPermissions, $oldKey, $idxKey, $useNullTenant): Document {
            $existingPayload = $redis->get($oldKey);
            if (! \is_string($existingPayload) || $existingPayload === '') {
                throw new NotFoundException('Document not found');
            }

            $existing = $this->decode($existingPayload);
            if ($col !== Database::METADATA) {
                $existing = $this->surfaceRelationshipAttributes($col, $existing);
            }
            $newId = $document->getId() !== '' ? $document->getId() : $id;
            // Stay on the null-tenant key when the existing row was located
            // there; rewriting under the current tenant would split the row.
            $newKey = $useNullTenant ? $this->docKey($col, $newId, '_') : $this->docKey($col, $newId);
            // Idx set scoping mirrors the located row so per-tenant ids remain
            // separate but the null-tenant METADATA row stays in the null
            // tenant's idx set.
            $effectiveIdxKey = $useNullTenant ? $this->idxKey($col, '_') : $idxKey;

            if ($newId !== $id && (bool) $redis->exists($newKey)) {
                throw new DuplicateException('Document already exists');
            }

            $resolved = $this->applyOperators($document->getArrayCopy(), $existing->getArrayCopy());
            $merged = \array_merge($existing->getArrayCopy(), $resolved);
            $merged['$id'] = $newId;
            $mergedDocument = new Document($merged);

            $this->enforceUniqueIndexes($redis, $col, $mergedDocument, $id);

            $payload = $this->encode($mergedDocument);

            if ($newId !== $id) {
                $redis->del($oldKey);
                $redis->sRem($effectiveIdxKey, \strtolower($id));
            }
            $redis->set($newKey, $payload);
            $redis->sAdd($effectiveIdxKey, \strtolower($newId));

            $this->journal('updateDoc', [
                'collection' => $col,
                'id' => $id,
                'newId' => $newId,
                'payload' => $existingPayload,
                'docKey' => $oldKey,
                'newDocKey' => $newKey,
                'idxKey' => $effectiveIdxKey,
            ]);

            if (! $skipPermissions) {
                $this->clearPermissions($col, $id);
                if ($newId !== $id) {
                    $this->clearPermissions($col, $newId);
                }
                $this->writePermissions($col, $newId, $mergedDocument);
            }

            return $mergedDocument;
        });
    }

    public function updateDocuments(Document $collection, Document $updates, array $documents): int
    {
        if (empty($documents)) {
            return 0;
        }

        $attrs = $updates->getAttributes();
        $hasCreatedAt = ! empty($updates->getCreatedAt());
        $hasUpdatedAt = ! empty($updates->getUpdatedAt());
        $hasPermissions = $updates->offsetExists('$permissions');
        if (empty($attrs) && ! $hasCreatedAt && ! $hasUpdatedAt && ! $hasPermissions) {
            return 0;
        }

        $col = $this->filter($collection->getId());

        // Drop any caller-provided keys: pipeline results are indexed
        // sequentially, so positional iteration here MUST start at 0.
        $documents = \array_values($documents);

        return $this->tx(function (RedisClient $redis) use ($col, $documents, $updates, $attrs, $hasCreatedAt, $hasUpdatedAt, $hasPermissions): int {
            // Pipeline existing-payload GETs in a single round trip — mirrors
            // upsertDocuments() and avoids one synchronous round trip per
            // document, which dominates wall time on bulk updates.
            $docKeys = [];
            foreach ($documents as $doc) {
                $docKeys[] = $this->docKey($col, $doc->getId());
            }

            $redis->multi(\Redis::PIPELINE);
            foreach ($docKeys as $docKey) {
                $redis->get($docKey);
            }
            $existingPayloads = $redis->exec();
            if (! \is_array($existingPayloads)) {
                $existingPayloads = [];
            }

            // Cache the relationship-key list once per bulk call so the
            // null-surface pass is N reads of a local list, not N reads of
            // meta.attrs.
            $relationshipKeys = [];
            if ($col !== Database::METADATA) {
                $metaKey = $this->key($this->ns(), 'meta', $this->filter($col));
                $attributes = $this->readAttributesField($redis, $metaKey);
                $relationshipKeys = $this->extractRelationshipKeys($attributes);
            }

            $count = 0;
            foreach ($documents as $i => $doc) {
                $uid = $doc->getId();
                $docKey = $docKeys[$i];
                $existingPayload = $existingPayloads[$i] ?? false;
                if (! \is_string($existingPayload) || $existingPayload === '') {
                    continue;
                }

                $existing = $this->decode($existingPayload);
                if (! empty($relationshipKeys)) {
                    $existing = $this->surfaceRelationshipAttributesUsing($relationshipKeys, $existing);
                }
                $merged = $existing->getArrayCopy();
                $resolved = $this->applyOperators($attrs, $merged);
                foreach ($resolved as $attribute => $value) {
                    $merged[$attribute] = $value;
                }
                if ($hasCreatedAt) {
                    $merged['$createdAt'] = $updates->getCreatedAt();
                }
                if ($hasUpdatedAt) {
                    $merged['$updatedAt'] = $updates->getUpdatedAt();
                }
                if ($hasPermissions) {
                    $merged['$permissions'] = $updates->getPermissions();
                }

                $mergedDocument = new Document($merged);
                $redis->set($docKey, $this->encode($mergedDocument));

                $this->journal('updateDoc', [
                    'collection' => $col,
                    'id' => $uid,
                    'newId' => $uid,
                    'payload' => $existingPayload,
                    'docKey' => $docKey,
                ]);

                if ($hasPermissions) {
                    $this->clearPermissions($col, $uid);
                    $this->writePermissions($col, $uid, $mergedDocument);
                }

                $count++;
            }

            return $count;
        });
    }

    public function upsertDocuments(
        Document $collection,
        string $attribute,
        array $changes
    ): array {
        if (empty($changes)) {
            return $changes;
        }

        $col = $this->filter($collection->getId());

        return $this->tx(function (RedisClient $redis) use ($col, $attribute, $changes): array {
            $results = [];

            // Phase 1: pipeline GETs of every doc so we know create vs update
            // in a single round trip. Mirror createDocument and route every
            // doc/idx/seq key through the document's own tenant so a batch
            // that mixes tenants under shared tables doesn't silently
            // misroute to the adapter-bound bucket.
            $redis->multi(\Redis::PIPELINE);
            foreach ($changes as $change) {
                $document = $change->getNew();
                $redis->get($this->docKey($col, $document->getId(), $document->getTenant()));
            }
            $existingPayloads = $redis->exec();
            if (! \is_array($existingPayloads)) {
                $existingPayloads = [];
            }

            // Cache the relationship-key list once per bulk call (see
            // updateDocuments) so we surface nulls without re-reading
            // meta.attrs per change.
            $relationshipKeys = [];
            if ($col !== Database::METADATA) {
                $metaKey = $this->key($this->ns(), 'meta', $this->filter($col));
                $attributes = $this->readAttributesField($redis, $metaKey);
                $relationshipKeys = $this->extractRelationshipKeys($attributes);
            }

            foreach ($changes as $i => $change) {
                $document = $change->getNew();
                $id = $document->getId();
                $tenant = $document->getTenant();
                $docKey = $this->docKey($col, $id, $tenant);
                $idxKey = $this->idxKey($col, $tenant);
                $seqKey = $this->seqKey($col, $tenant);
                $existingPayload = $existingPayloads[$i] ?? false;

                if (\is_string($existingPayload) && $existingPayload !== '') {
                    $existing = $this->decode($existingPayload);
                    if (! empty($relationshipKeys)) {
                        $existing = $this->surfaceRelationshipAttributesUsing($relationshipKeys, $existing);
                    }
                    $existingArray = $existing->getArrayCopy();
                    $resolved = $this->applyOperators($document->getArrayCopy(), $existingArray);
                    $merged = \array_merge($existingArray, $resolved);
                    $merged['$id'] = $id;

                    if ($attribute !== '') {
                        $previous = $existing->getAttribute($attribute);
                        $delta = $document->getAttribute($attribute);
                        $previousNumeric = \is_numeric($previous) ? $previous + 0 : 0;
                        $deltaNumeric = \is_numeric($delta) ? $delta + 0 : 0;
                        $merged[$attribute] = $previousNumeric + $deltaNumeric;
                    }

                    $mergedDocument = new Document($merged);
                    $redis->set($docKey, $this->encode($mergedDocument));

                    $this->journal('updateDoc', [
                        'collection' => $col,
                        'id' => $id,
                        'newId' => $id,
                        'payload' => $existingPayload,
                        'docKey' => $docKey,
                    ]);

                    $this->clearPermissions($col, $id);
                    $this->writePermissions($col, $id, $mergedDocument);

                    $results[] = $mergedDocument;
                } else {
                    // Insert path: parity with createDocument — reject writes
                    // that would violate a UNIQUE index before the row lands
                    // in the keyspace.
                    $this->enforceUniqueIndexes($redis, $col, $document);

                    $sequence = $document->getSequence();
                    if (empty($sequence)) {
                        $next = $redis->incr($seqKey);
                        $sequence = (string) $next;
                    } else {
                        $sequence = (string) $sequence;
                        $current = $redis->get($seqKey);
                        if (! \is_string($current) || (int) $sequence > (int) $current) {
                            $redis->set($seqKey, $sequence);
                        }
                    }
                    $document->setAttribute('$sequence', $sequence);

                    $resolved = $this->applyOperators($document->getArrayCopy(), []);
                    foreach ($resolved as $attr => $value) {
                        $document->setAttribute($attr, $value);
                    }

                    $redis->set($docKey, $this->encode($document));
                    $redis->sAdd($idxKey, \strtolower($id));

                    $this->writePermissions($col, $id, $document);
                    $this->journal('createDoc', [
                        'collection' => $col,
                        'id' => $id,
                        'docKey' => $docKey,
                        'idxKey' => $idxKey,
                        'permDocKey' => $this->permDocKey($col, $id),
                    ]);

                    $results[] = $document;
                }
            }

            return $results;
        });
    }

    public function getSequences(string $collection, array $documents): array
    {
        if (empty($documents)) {
            return $documents;
        }

        $this->client->multi(\Redis::PIPELINE);
        try {
            $indexes = [];
            foreach ($documents as $index => $doc) {
                if (! empty($doc->getSequence())) {
                    continue;
                }
                $this->client->get($this->docKey($collection, $doc->getId()));
                $indexes[] = $index;
            }
            // No work queued — discard the empty pipeline so the connection
            // does not stay in MULTI mode after returning early.
            if ($indexes === []) {
                try {
                    $this->client->discard();
                } catch (\Throwable) {
                    // PIPELINE-mode discard is version-dependent across phpredis.
                }
                return $documents;
            }
            $payloads = $this->client->exec();
        } catch (\Throwable $e) {
            try {
                $this->client->discard();
            } catch (\Throwable) {
                // PIPELINE-mode discard is version-dependent across phpredis.
            }
            throw new TransactionException('Failed to load sequences: ' . $e->getMessage(), 0, $e);
        }
        if (! \is_array($payloads)) {
            return $documents;
        }

        foreach ($indexes as $position => $index) {
            $payload = $payloads[$position] ?? false;
            if (! \is_string($payload) || $payload === '') {
                continue;
            }
            $existing = $this->decode($payload);
            $sequence = $existing->getSequence();
            if (! empty($sequence)) {
                $documents[$index]->setAttribute('$sequence', (string) $sequence);
            }
        }

        return $documents;
    }

    public function deleteDocument(string $collection, string $id): bool
    {
        $collection = $this->filter($collection);
        $docKey = $this->docKey($collection, $id);
        $idxKey = $this->idxKey($collection);

        return $this->tx(function (RedisClient $redis) use ($collection, $id, $docKey, $idxKey): bool {
            $payload = $redis->get($docKey);
            if (! \is_string($payload) || $payload === '') {
                return false;
            }

            $this->journal('deleteDoc', [
                'collection' => $collection,
                'id' => $id,
                'payload' => $payload,
                'docKey' => $docKey,
                'idxKey' => $idxKey,
            ]);

            $this->clearPermissions($collection, $id);
            $redis->del($docKey);
            $redis->sRem($idxKey, \strtolower($id));

            return true;
        });
    }

    public function deleteDocuments(string $collection, array $sequences, array $permissionIds): int
    {
        if (empty($sequences) && empty($permissionIds)) {
            return 0;
        }

        $collection = $this->filter($collection);
        $idxKey = $this->idxKey($collection);

        return $this->tx(function (RedisClient $redis) use ($collection, $sequences, $permissionIds, $idxKey): int {
            $sequenceSet = [];
            foreach ($sequences as $sequence) {
                $sequenceSet[(string) $sequence] = true;
            }

            $allIds = $redis->sMembers($idxKey);
            if (! \is_array($allIds)) {
                $allIds = [];
            }

            $docKeys = [];
            $redis->multi(\Redis::PIPELINE);
            foreach ($allIds as $id) {
                $docKey = $this->docKey($collection, (string) $id);
                $docKeys[(string) $id] = $docKey;
                $redis->get($docKey);
            }
            $payloads = $redis->exec();
            if (! \is_array($payloads)) {
                $payloads = [];
            }

            $deleted = [];
            foreach ($allIds as $position => $id) {
                $payload = $payloads[$position] ?? false;
                if (! \is_string($payload) || $payload === '') {
                    continue;
                }
                $document = $this->decode($payload);
                $matchesSequence = isset($sequenceSet[(string) $document->getSequence()]);
                if ($matchesSequence) {
                    $deleted[$document->getId()] = ['payload' => $payload, 'docKey' => $docKeys[(string) $id]];
                }
            }

            foreach ($deleted as $documentId => $deleteEntry) {
                $deletedDocKey = $deleteEntry['docKey'];
                $this->journal('deleteDoc', [
                    'collection' => $collection,
                    'id' => (string) $documentId,
                    'payload' => $deleteEntry['payload'],
                    'docKey' => $deletedDocKey,
                    'idxKey' => $idxKey,
                ]);
                $this->clearPermissions($collection, (string) $documentId);
                $redis->del($deletedDocKey);
                $redis->sRem($idxKey, \strtolower((string) $documentId));
            }

            // Permission-only cleanup for ids the caller listed but that did
            // not match by sequence — mirrors Memory adapter semantics.
            foreach ($permissionIds as $permissionId) {
                $documentId = (string) $permissionId;
                if (isset($deleted[$documentId])) {
                    continue;
                }
                $this->clearPermissions($collection, $documentId);
            }

            return \count($deleted);
        });
    }

    public function increaseDocumentAttribute(
        string $collection,
        string $id,
        string $attribute,
        int|float $value,
        string $updatedAt,
        int|float|null $min = null,
        int|float|null $max = null
    ): bool {
        $collection = $this->filter($collection);
        $docKey = $this->docKey($collection, $id);

        return $this->tx(function (RedisClient $redis) use ($collection, $id, $attribute, $value, $updatedAt, $min, $max, $docKey): bool {
            $payload = $redis->get($docKey);
            if (! \is_string($payload) || $payload === '') {
                throw new NotFoundException('Document not found');
            }

            $document = $this->decode($payload);
            $current = $document->getAttribute($attribute);
            $current = \is_numeric($current) ? $current + 0 : 0;

            // Mirrors MariaDB's bound semantics — silent no-op when bounds
            // exclude the row. Caller has pre-adjusted bounds by $value.
            if (! \is_null($min) && $current < $min) {
                return true;
            }
            if (! \is_null($max) && $current > $max) {
                return true;
            }

            $document->setAttribute($attribute, $current + $value);
            $document->setAttribute('$updatedAt', $updatedAt);

            $redis->set($docKey, $this->encode($document));

            $this->journal('updateDoc', [
                'collection' => $collection,
                'id' => $id,
                'newId' => $id,
                'payload' => $payload,
                'docKey' => $docKey,
            ]);

            return true;
        });
    }

    // === @architect:T30 end ===





    // === @architect:T40 owns: indexes + queries + counts ===

    public function createIndex(string $collection, string $id, string $type, array $attributes, array $lengths, array $orders, array $indexAttributeTypes = [], array $collation = [], int $ttl = 1): bool
    {
        $collection = $this->filter($collection);
        $id = $this->filter($id);
        $metaKey = $this->key($this->ns(), 'meta', $collection);

        if ((bool) $this->client->exists($metaKey) === false) {
            throw new NotFoundException('Collection not found');
        }

        return $this->tx(function (RedisClient $client) use ($metaKey, $collection, $id, $type, $attributes, $lengths, $orders): bool {
            $indexes = $this->readIndexesField($client, $metaKey);

            foreach ($indexes as $existing) {
                if (($existing['$id'] ?? $existing['key'] ?? null) === $id) {
                    throw new DuplicateException('Index already exists');
                }
            }

            // Unique-index pre-flight: scan existing documents for collisions so
            // index creation fails up-front rather than silently allowing
            // duplicate values to coexist under a "unique" constraint.
            if ($type === Database::INDEX_UNIQUE && ! empty($attributes)) {
                $idxKey = $this->idxKey($collection);
                /** @var array<int, string> $docIds */
                $docIds = $client->sMembers($idxKey);
                if (! empty($docIds)) {
                    $sharedTables = $this->getSharedTables();
                    $currentTenant = $sharedTables ? $this->getTenant() : null;
                    // Single mGet round trip instead of N sequential GETs so
                    // unique-index creation on a populated collection scales
                    // with payload size rather than RTT count.
                    $docKeys = [];
                    foreach ($docIds as $docId) {
                        $docKeys[] = $this->docKey($collection, (string) $docId);
                    }
                    /** @var array<int, mixed> $payloads */
                    $payloads = $client->mGet($docKeys);
                    $seen = [];
                    foreach ($payloads as $payload) {
                        if (! \is_string($payload)) {
                            continue;
                        }
                        $document = $this->decode($payload);
                        // Under shared tables the inverted-index set fans
                        // across every tenant; only probe rows that belong
                        // to the active tenant so cross-tenant rows don't
                        // produce spurious collisions.
                        if ($sharedTables) {
                            $rowTenant = $document->getAttribute('$tenant');
                            if ($rowTenant !== $currentTenant) {
                                continue;
                            }
                        }
                        $signature = [];
                        $hasNull = false;
                        foreach ($attributes as $attribute) {
                            $value = $this->resolveDocumentAttribute($document, (string) $attribute);
                            if ($value === null) {
                                $hasNull = true;
                                break;
                            }
                            $signature[] = $this->normalizeIndexValue($value);
                        }
                        if ($hasNull) {
                            continue;
                        }
                        if ($sharedTables) {
                            \array_unshift($signature, $currentTenant);
                        }
                        $hash = \serialize($signature);
                        if (isset($seen[$hash])) {
                            throw new UniqueException('Cannot create unique index: existing rows already contain duplicate values');
                        }
                        $seen[$hash] = true;
                    }
                }
            }

            $indexes[] = [
                '$id' => $id,
                'key' => $id,
                'type' => $type,
                'attributes' => \array_values($attributes),
                'lengths' => \array_values($lengths),
                'orders' => \array_values($orders),
            ];

            $client->hSet(
                $metaKey,
                'indexes',
                \json_encode($indexes, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            );

            return true;
        });
    }

    public function deleteIndex(string $collection, string $id): bool
    {
        $collection = $this->filter($collection);
        $id = $this->filter($id);
        $metaKey = $this->key($this->ns(), 'meta', $collection);

        if ((bool) $this->client->exists($metaKey) === false) {
            return true;
        }

        return $this->tx(function (RedisClient $client) use ($metaKey, $id): bool {
            $indexes = $this->readIndexesField($client, $metaKey);
            $filtered = [];
            foreach ($indexes as $index) {
                if (($index['$id'] ?? $index['key'] ?? null) === $id) {
                    continue;
                }
                $filtered[] = $index;
            }

            $client->hSet(
                $metaKey,
                'indexes',
                \json_encode(\array_values($filtered), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            );

            return true;
        });
    }

    public function renameIndex(string $collection, string $old, string $new): bool
    {
        $collection = $this->filter($collection);
        $old = $this->filter($old);
        $new = $this->filter($new);
        $metaKey = $this->key($this->ns(), 'meta', $collection);

        if ((bool) $this->client->exists($metaKey) === false) {
            throw new NotFoundException('Collection not found');
        }

        return $this->tx(function (RedisClient $client) use ($metaKey, $old, $new): bool {
            $indexes = $this->readIndexesField($client, $metaKey);
            $changed = false;
            foreach ($indexes as $i => $index) {
                if (($index['$id'] ?? $index['key'] ?? null) === $old) {
                    $indexes[$i]['$id'] = $new;
                    $indexes[$i]['key'] = $new;
                    $changed = true;
                    break;
                }
            }

            if (! $changed) {
                return true;
            }

            $client->hSet(
                $metaKey,
                'indexes',
                \json_encode(\array_values($indexes), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            );

            return true;
        });
    }

    public function find(Document $collection, array $queries = [], ?int $limit = 25, ?int $offset = null, array $orderAttributes = [], array $orderTypes = [], array $cursor = [], string $cursorDirection = Database::CURSOR_AFTER, string $forPermission = Database::PERMISSION_READ): array
    {
        $collectionId = $this->filter($collection->getId());
        $metaKey = $this->key($this->ns(), 'meta', $collectionId);

        if ((bool) $this->client->exists($metaKey) === false) {
            throw new NotFoundException('Collection not found');
        }

        return $this->tx(function (RedisClient $client) use ($collectionId, $queries, $limit, $offset, $orderAttributes, $orderTypes, $cursor, $cursorDirection, $forPermission): array {
            $documents = $this->loadCollectionDocuments($client, $collectionId, $forPermission);
            $documents = $this->filterDocumentsByQueries($collectionId, $documents, $queries);
            $documents = $this->orderDocuments($documents, $orderAttributes, $orderTypes, $cursorDirection);
            $documents = $this->cursorDocuments($documents, $orderAttributes, $orderTypes, $cursor, $cursorDirection);

            if (! \is_null($offset)) {
                $documents = \array_slice($documents, $offset);
            }
            if (! \is_null($limit)) {
                $documents = \array_slice($documents, 0, $limit);
            }

            $selections = $this->extractSelectionsFromQueries($queries);
            if (! empty($selections)) {
                $projected = [];
                foreach ($documents as $document) {
                    $projected[] = $this->projectDocument($document, $selections);
                }
                $documents = $projected;
            }

            if ($cursorDirection === Database::CURSOR_BEFORE) {
                $documents = \array_reverse($documents);
            }

            return $documents;
        });
    }

    public function sum(Document $collection, string $attribute, array $queries = [], ?int $max = null): float|int
    {
        $collectionId = $this->filter($collection->getId());
        $metaKey = $this->key($this->ns(), 'meta', $collectionId);

        if ((bool) $this->client->exists($metaKey) === false) {
            throw new NotFoundException('Collection not found');
        }

        return $this->tx(function (RedisClient $client) use ($collectionId, $attribute, $queries, $max): float|int {
            $documents = $this->loadCollectionDocuments($client, $collectionId, Database::PERMISSION_READ);
            $documents = $this->filterDocumentsByQueries($collectionId, $documents, $queries);

            if (! \is_null($max)) {
                $documents = \array_slice($documents, 0, $max);
            }

            $sum = 0;
            $isFloat = false;
            foreach ($documents as $document) {
                $value = $this->resolveDocumentAttribute($document, $attribute);
                if ($value === null) {
                    continue;
                }
                if (\is_float($value)) {
                    $isFloat = true;
                }
                if (\is_numeric($value)) {
                    $sum += $value;
                }
            }

            return $isFloat ? (float) $sum : (int) $sum;
        });
    }

    public function count(Document $collection, array $queries = [], ?int $max = null): int
    {
        $collectionId = $this->filter($collection->getId());
        $metaKey = $this->key($this->ns(), 'meta', $collectionId);

        if ((bool) $this->client->exists($metaKey) === false) {
            throw new NotFoundException('Collection not found');
        }

        // Fast path: no query filters, authorization disabled, and shared
        // tables off means the `idx:{collection}` SET cardinality matches the
        // visible doc count directly. Under shared tables the SET is shared
        // across tenants — `sCard` would return the union count, leaking
        // cross-tenant rows — so we fall through to the slow path which
        // hydrates and tenant-filters via `loadCollectionDocuments`.
        // Authorization-on also requires hydration so the permission filter
        // actually runs.
        // TODO: this path still scans the full collection when queries are
        // present — acceptable parity with Memory, but a known scaling limit
        // and unsuitable for large production collections.
        if (
            empty($queries)
            && $this->authorization->getStatus() === false
            && $this->getSharedTables() === false
        ) {
            $idxKey = $this->idxKey($collectionId);
            $cardinality = $this->client->sCard($idxKey);
            if (\is_int($cardinality)) {
                return $max === null ? $cardinality : \min($max, $cardinality);
            }
        }

        return $this->tx(function (RedisClient $client) use ($collectionId, $queries, $max): int {
            $documents = $this->loadCollectionDocuments($client, $collectionId, Database::PERMISSION_READ);
            $documents = $this->filterDocumentsByQueries($collectionId, $documents, $queries);

            if (! \is_null($max)) {
                $documents = \array_slice($documents, 0, $max);
            }

            return \count($documents);
        });
    }

    public function getSchemaIndexes(string $collection): array
    {
        // Mirror Memory: Redis maintains no on-disk schema, so the adapter
        // exposes no schema-level indexes. Index metadata lives on the
        // collection Document and is read by Database via getCollection().
        return [];
    }

    public function getCountOfIndexes(Document $collection): int
    {
        return \count($collection->getAttribute('indexes', [])) + \count(Database::INTERNAL_INDEXES);
    }

    /**
     * Read and JSON-decode the indexes field on a collection meta hash.
     *
     * @return array<int, array<string, mixed>>
     */
    private function readIndexesField(RedisClient $client, string $metaKey): array
    {
        $raw = $client->hGet($metaKey, 'indexes');
        if (! \is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = \json_decode($raw, true, self::JSON_DECODE_DEPTH, JSON_THROW_ON_ERROR);
        if (! \is_array($decoded)) {
            return [];
        }

        /** @var array<int, array<string, mixed>> $decoded */
        return $decoded;
    }

    /**
     * Hydrate every document in the collection's id-set, applying tenant and
     * permission filters. Returns Documents in insertion-set order.
     *
     * @return array<int, Document>
     */
    private function loadCollectionDocuments(RedisClient $client, string $collection, string $forPermission): array
    {
        $idxKey = $this->idxKey($collection);
        /** @var array<int, string> $ids */
        $ids = $client->sMembers($idxKey);
        if (empty($ids)) {
            return [];
        }

        // Permission filter through the T50-owned hook before fetching to
        // avoid round-tripping payloads we will discard anyway.
        if ($this->authorization->getStatus()) {
            $ids = $this->applyPermissionFilter($collection, $ids, $forPermission);
            if (empty($ids)) {
                return [];
            }
        }

        $keys = [];
        foreach ($ids as $id) {
            $keys[] = $this->docKey($collection, (string) $id);
        }

        /** @var array<int, mixed> $payloads */
        $payloads = $client->mGet($keys);
        $sharedTables = $this->getSharedTables();
        $tenant = $sharedTables ? $this->getTenant() : null;
        $allowNullTenant = $sharedTables && $collection === Database::METADATA;

        // Read meta.attrs once and cache the relationship-key list across the
        // decode loop — `surfaceRelationshipAttributes` would re-read meta on
        // every document otherwise.
        $relationshipKeys = [];
        if ($collection !== Database::METADATA) {
            $metaKey = $this->key($this->ns(), 'meta', $this->filter($collection));
            $attributes = $this->readAttributesField($client, $metaKey);
            $relationshipKeys = $this->extractRelationshipKeys($attributes);
        }

        $documents = [];
        foreach ($payloads as $payload) {
            if (! \is_string($payload) || $payload === '') {
                continue;
            }
            $document = $this->decode($payload);

            if ($sharedTables) {
                $rowTenant = $document->getAttribute('$tenant');
                $crossTenant = $rowTenant !== $tenant
                    && ! ($allowNullTenant && $rowTenant === null);
                if ($crossTenant) {
                    continue;
                }
            }

            if (! empty($relationshipKeys)) {
                $document = $this->surfaceRelationshipAttributesUsing($relationshipKeys, $document);
            }

            $documents[] = $document;
        }

        return $documents;
    }

    /**
     * Apply non-pagination query filters to the supplied documents.
     *
     * @param  array<int, Document>  $documents
     * @param  array<int, Query>  $queries
     * @return array<int, Document>
     */
    private function filterDocumentsByQueries(string $collection, array $documents, array $queries): array
    {
        if (empty($documents)) {
            return [];
        }

        $effective = [];
        foreach ($queries as $query) {
            $method = $query->getMethod();
            if (\in_array($method, [
                Query::TYPE_SELECT,
                Query::TYPE_ORDER_ASC,
                Query::TYPE_ORDER_DESC,
                Query::TYPE_ORDER_RANDOM,
                Query::TYPE_LIMIT,
                Query::TYPE_OFFSET,
                Query::TYPE_CURSOR_AFTER,
                Query::TYPE_CURSOR_BEFORE,
            ], true)) {
                continue;
            }
            $effective[] = $query;
        }

        if (empty($effective)) {
            return \array_values($documents);
        }

        $output = [];
        foreach ($documents as $document) {
            $matched = true;
            foreach ($effective as $query) {
                if (! $this->matchesDocument($document, $query)) {
                    $matched = false;
                    break;
                }
            }
            if ($matched) {
                $output[] = $document;
            }
        }

        return $output;
    }

    /**
     * Resolve a single Query against a Document, mirroring Memory's matches()
     * but operating on the Document's natural `$id`/`$tenant`/etc. layout.
     */
    private function matchesDocument(Document $document, Query $query): bool
    {
        $method = $query->getMethod();

        if ($method === Query::TYPE_AND) {
            foreach ($query->getValues() as $sub) {
                if (! ($sub instanceof Query) || ! $this->matchesDocument($document, $sub)) {
                    return false;
                }
            }

            return true;
        }

        if ($method === Query::TYPE_OR) {
            foreach ($query->getValues() as $sub) {
                if ($sub instanceof Query && $this->matchesDocument($document, $sub)) {
                    return true;
                }
            }

            return false;
        }

        $attribute = $query->getAttribute();
        $value = $this->resolveDocumentAttribute($document, $attribute);
        $values = $query->getValues();

        if ($query->isObjectAttribute() && ! \str_contains($attribute, '.')) {
            return $this->matchesDocumentObject($value, $query);
        }

        switch ($method) {
            case Query::TYPE_EQUAL:
                foreach ($values as $candidate) {
                    if ($this->valuesEqual($value, $candidate)) {
                        return true;
                    }
                }

                return false;

            case Query::TYPE_NOT_EQUAL:
                if ($value === null) {
                    return false;
                }
                foreach ($values as $candidate) {
                    if ($this->valuesEqual($value, $candidate)) {
                        return false;
                    }
                }

                return true;

            case Query::TYPE_LESSER:
                return $value !== null && $value < $values[0];

            case Query::TYPE_LESSER_EQUAL:
                return $value !== null && $value <= $values[0];

            case Query::TYPE_GREATER:
                return $value !== null && $value > $values[0];

            case Query::TYPE_GREATER_EQUAL:
                return $value !== null && $value >= $values[0];

            case Query::TYPE_IS_NULL:
                return $value === null;

            case Query::TYPE_IS_NOT_NULL:
                return $value !== null;

            case Query::TYPE_BETWEEN:
                return $value !== null && $value >= $values[0] && $value <= $values[1];

            case Query::TYPE_NOT_BETWEEN:
                if ($value === null) {
                    return false;
                }

                return $value < $values[0] || $value > $values[1];

            case Query::TYPE_STARTS_WITH:
                return \is_string($value) && \is_string($values[0] ?? null) && \str_starts_with($value, (string) $values[0]);

            case Query::TYPE_NOT_STARTS_WITH:
                if ($value === null) {
                    return false;
                }

                return ! \is_string($value) || ! \is_string($values[0] ?? null) || ! \str_starts_with($value, (string) $values[0]);

            case Query::TYPE_ENDS_WITH:
                return \is_string($value) && \is_string($values[0] ?? null) && \str_ends_with($value, (string) $values[0]);

            case Query::TYPE_NOT_ENDS_WITH:
                if ($value === null) {
                    return false;
                }

                return ! \is_string($value) || ! \is_string($values[0] ?? null) || ! \str_ends_with($value, (string) $values[0]);

            case Query::TYPE_CONTAINS:
            case Query::TYPE_CONTAINS_ANY:
                $haystack = $this->coerceArrayValue($value);
                if ($haystack === null && \is_string($value)) {
                    foreach ($values as $needle) {
                        if (\is_string($needle) && \stripos($value, $needle) !== false) {
                            return true;
                        }
                    }

                    return false;
                }
                if (! \is_array($haystack)) {
                    return false;
                }
                foreach ($values as $needle) {
                    foreach ($haystack as $item) {
                        if ($this->valuesEqual($item, $needle)) {
                            return true;
                        }
                    }
                }

                return false;

            case Query::TYPE_NOT_CONTAINS:
                if ($value === null) {
                    return false;
                }

                return ! $this->matchesDocument($document, new Query(Query::TYPE_CONTAINS, $attribute, $values));

            case Query::TYPE_CONTAINS_ALL:
                $haystack = $this->coerceArrayValue($value);
                if (! \is_array($haystack)) {
                    return false;
                }
                foreach ($values as $needle) {
                    $found = false;
                    foreach ($haystack as $item) {
                        if ($this->valuesEqual($item, $needle)) {
                            $found = true;
                            break;
                        }
                    }
                    if (! $found) {
                        return false;
                    }
                }

                return true;

            case Query::TYPE_SEARCH:
                if (! \is_string($value)) {
                    return false;
                }
                $needle = (string) ($values[0] ?? '');
                if ($needle === '') {
                    return false;
                }

                return $this->matchesFulltextRedis($value, $needle);

            case Query::TYPE_NOT_SEARCH:
                if ($value === null) {
                    return false;
                }
                if (! \is_string($value)) {
                    return true;
                }
                $needle = (string) ($values[0] ?? '');
                if ($needle === '') {
                    return true;
                }

                return ! $this->matchesFulltextRedis($value, $needle);

            case Query::TYPE_REGEX:
                if (! \is_string($value)) {
                    return false;
                }
                $pattern = (string) ($values[0] ?? '');
                $delimited = '#' . \str_replace('#', '\\#', $pattern) . '#u';

                return @\preg_match($delimited, $value) === 1;
        }

        throw new QueryException('Query method not supported by Redis adapter: ' . $method);
    }

    /**
     * Object-attribute query semantics — JSONB-style containment used for
     * Postgres-flavoured equal/contains operators against decoded objects.
     */
    private function matchesDocumentObject(mixed $value, Query $query): bool
    {
        $haystack = $this->decodeObjectishValue($value);
        $values = $query->getValues();
        $method = $query->getMethod();

        switch ($method) {
            case Query::TYPE_EQUAL:
                if ($haystack === null) {
                    return false;
                }
                foreach ($values as $candidate) {
                    if ($this->jsonContainment($haystack, $candidate)) {
                        return true;
                    }
                }

                return false;

            case Query::TYPE_NOT_EQUAL:
                if ($haystack === null) {
                    return false;
                }
                foreach ($values as $candidate) {
                    if ($this->jsonContainment($haystack, $candidate)) {
                        return false;
                    }
                }

                return true;

            case Query::TYPE_CONTAINS:
            case Query::TYPE_CONTAINS_ANY:
                if ($haystack === null) {
                    return false;
                }
                foreach ($values as $candidate) {
                    if ($this->jsonContainment($haystack, $this->wrapScalarObjectCandidate($candidate))) {
                        return true;
                    }
                }

                return false;

            case Query::TYPE_CONTAINS_ALL:
                if ($haystack === null) {
                    return false;
                }
                foreach ($values as $candidate) {
                    if (! $this->jsonContainment($haystack, $this->wrapScalarObjectCandidate($candidate))) {
                        return false;
                    }
                }

                return true;

            case Query::TYPE_NOT_CONTAINS:
                if ($haystack === null) {
                    return false;
                }
                foreach ($values as $candidate) {
                    if ($this->jsonContainment($haystack, $this->wrapScalarObjectCandidate($candidate))) {
                        return false;
                    }
                }

                return true;

            case Query::TYPE_IS_NULL:
                return $value === null;

            case Query::TYPE_IS_NOT_NULL:
                return $value !== null;
        }

        throw new QueryException('Query method ' . $method . ' not supported for object attributes');
    }

    /**
     * Stable ordering across Documents. Random short-circuits via shuffle to
     * preserve usort transitivity; absent attributes fall back to $sequence.
     *
     * @param  array<int, Document>  $documents
     * @param  array<int, string>  $orderAttributes
     * @param  array<int, string>  $orderTypes
     * @return array<int, Document>
     */
    private function orderDocuments(array $documents, array $orderAttributes, array $orderTypes, string $cursorDirection): array
    {
        foreach ($orderTypes as $type) {
            if ($type === Database::ORDER_RANDOM) {
                \shuffle($documents);

                return $documents;
            }
        }

        $reverse = $cursorDirection === Database::CURSOR_BEFORE;

        if (empty($orderAttributes)) {
            \usort($documents, function (Document $a, Document $b) use ($reverse): int {
                $av = $a->getAttribute('$sequence', 0);
                $bv = $b->getAttribute('$sequence', 0);
                $av = \is_numeric($av) ? $av + 0 : 0;
                $bv = \is_numeric($bv) ? $bv + 0 : 0;
                if ($av === $bv) {
                    return 0;
                }
                $cmp = ($av < $bv) ? -1 : 1;

                return $reverse ? -$cmp : $cmp;
            });

            return $documents;
        }

        $directions = [];
        foreach ($orderAttributes as $i => $attribute) {
            $direction = $orderTypes[$i] ?? Database::ORDER_ASC;
            if ($reverse) {
                $direction = $direction === Database::ORDER_ASC ? Database::ORDER_DESC : Database::ORDER_ASC;
            }
            $directions[$i] = $direction === Database::ORDER_ASC ? 1 : -1;
        }

        \usort($documents, function (Document $a, Document $b) use ($orderAttributes, $directions): int {
            foreach ($orderAttributes as $i => $attribute) {
                $av = $this->resolveDocumentAttribute($a, $attribute);
                $bv = $this->resolveDocumentAttribute($b, $attribute);
                if ($av === $bv) {
                    continue;
                }
                if ($av === null) {
                    $cmp = -1;
                } elseif ($bv === null) {
                    $cmp = 1;
                } else {
                    $cmp = ($av < $bv) ? -1 : 1;
                }

                return $cmp * $directions[$i];
            }

            return 0;
        });

        return $documents;
    }

    /**
     * Discard documents preceding the supplied cursor on the active sort.
     *
     * @param  array<int, Document>  $documents
     * @param  array<int, string>  $orderAttributes
     * @param  array<int, string>  $orderTypes
     * @param  array<string, mixed>  $cursor
     * @return array<int, Document>
     */
    private function cursorDocuments(array $documents, array $orderAttributes, array $orderTypes, array $cursor, string $cursorDirection): array
    {
        if (empty($cursor)) {
            return $documents;
        }

        if (empty($orderAttributes)) {
            $orderAttributes = ['$sequence'];
            $orderTypes = [Database::ORDER_ASC];
        }

        $reverse = $cursorDirection === Database::CURSOR_BEFORE;
        $resolved = [];
        foreach ($orderAttributes as $i => $attribute) {
            $direction = $orderTypes[$i] ?? Database::ORDER_ASC;
            if ($reverse) {
                $direction = $direction === Database::ORDER_ASC ? Database::ORDER_DESC : Database::ORDER_ASC;
            }
            $resolved[] = [
                'attribute' => $attribute,
                'asc' => $direction === Database::ORDER_ASC,
                'ref' => $cursor[$attribute] ?? null,
            ];
        }

        $output = [];
        foreach ($documents as $document) {
            foreach ($resolved as $entry) {
                $current = $this->resolveDocumentAttribute($document, $entry['attribute']);
                $ref = $entry['ref'];
                if ($current === $ref) {
                    continue;
                }
                if ($current === null) {
                    if (! $entry['asc']) {
                        $output[] = $document;
                    }

                    continue 2;
                }
                if ($ref === null) {
                    if ($entry['asc']) {
                        $output[] = $document;
                    }

                    continue 2;
                }
                if ($entry['asc'] ? ($current > $ref) : ($current < $ref)) {
                    $output[] = $document;
                }

                continue 2;
            }
        }

        return $output;
    }

    /**
     * Resolve a dotted attribute path on a Document, falling back to nested
     * decoded JSON traversal when the head segment holds a string payload.
     */
    private function resolveDocumentAttribute(Document $document, string $attribute): mixed
    {
        // Redis stores documents as raw JSON, so attribute keys keep symbols
        // (`$`, `.`, etc.) verbatim. Try a direct lookup first — only when the
        // literal key misses do we fall back to the filtered alias and then to
        // dotted-path traversal (mirrors Memory's `resolveAttributeValue`).
        if ($document->offsetExists($attribute)) {
            return $document->getAttribute($attribute);
        }

        $filtered = $this->filter($attribute);
        if ($filtered !== $attribute && $document->offsetExists($filtered)) {
            return $document->getAttribute($filtered);
        }

        if (! \str_contains($attribute, '.')) {
            return null;
        }

        [$head, $rest] = \explode('.', $attribute, 2);
        $value = $document->getAttribute($head);
        if (\is_string($value) && $value !== '' && ($value[0] === '{' || $value[0] === '[')) {
            $decoded = \json_decode($value, true);
            if (\is_array($decoded)) {
                $value = $decoded;
            }
        }
        if ($value instanceof Document) {
            $value = $value->getArrayCopy();
        }

        return $this->traverseNestedPath($value, $rest);
    }

    /**
     * Walk a remaining dotted path through arrays, returning null on miss.
     */
    private function traverseNestedPath(mixed $value, string $path): mixed
    {
        foreach (\explode('.', $path) as $part) {
            if ($value instanceof Document) {
                $value = $value->getArrayCopy();
            }
            if (\is_array($value) && \array_key_exists($part, $value)) {
                $value = $value[$part];

                continue;
            }

            return null;
        }

        return $value;
    }

    /**
     * Normalise a value for unique-index hashing. Booleans collapse to ints
     * and numeric strings collapse to numbers so signatures match SQL casts.
     */
    private function normalizeIndexValue(mixed $value): mixed
    {
        if (\is_bool($value)) {
            return $value ? 1 : 0;
        }
        if (\is_string($value) && \is_numeric($value)) {
            return $value + 0;
        }

        return $value;
    }

    /**
     * Equal-with-numeric-coercion mirroring Memory::looseEquals — covers the
     * "1" == 1 case Database tests rely on.
     */
    private function valuesEqual(mixed $a, mixed $b): bool
    {
        if ($a === $b) {
            return true;
        }
        if (\is_numeric($a) && \is_numeric($b)) {
            return $a + 0 === $b + 0;
        }

        return false;
    }

    /**
     * Decode a CONTAINS-target into an array if possible. Returns null when
     * the value is neither an array nor a JSON-encoded array string.
     *
     * @return array<mixed>|null
     */
    private function coerceArrayValue(mixed $value): ?array
    {
        if (\is_array($value)) {
            return $value;
        }
        if (\is_string($value) && $value !== '' && ($value[0] === '[' || $value[0] === '{')) {
            $decoded = \json_decode($value, true);

            return \is_array($decoded) ? $decoded : null;
        }

        return null;
    }

    /**
     * Decode an object-typed attribute value for JSONB-style containment.
     */
    private function decodeObjectishValue(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }
        if (\is_array($value)) {
            return $value;
        }
        if ($value instanceof Document) {
            return $value->getArrayCopy();
        }
        if (\is_string($value) && $value !== '' && ($value[0] === '{' || $value[0] === '[')) {
            $decoded = \json_decode($value, true);
            if (\is_array($decoded)) {
                return $decoded;
            }
        }

        return $value;
    }

    /**
     * Postgres `@>` JSONB containment in PHP — recursive subset semantics
     * with list-element matching for array haystacks.
     */
    private function jsonContainment(mixed $haystack, mixed $candidate): bool
    {
        if (\is_array($haystack) && \array_is_list($haystack)) {
            if (\is_array($candidate) && \array_is_list($candidate)) {
                foreach ($candidate as $needle) {
                    $matched = false;
                    foreach ($haystack as $item) {
                        if ($this->jsonContainment($item, $needle)) {
                            $matched = true;
                            break;
                        }
                    }
                    if (! $matched) {
                        return false;
                    }
                }

                return true;
            }
            foreach ($haystack as $item) {
                if ($this->jsonContainment($item, $candidate)) {
                    return true;
                }
            }

            return false;
        }
        if (\is_array($haystack) && \is_array($candidate)) {
            foreach ($candidate as $key => $value) {
                if (! \array_key_exists($key, $haystack)) {
                    return false;
                }
                if (! $this->jsonContainment($haystack[$key], $value)) {
                    return false;
                }
            }

            return true;
        }
        if ($haystack === $candidate) {
            return true;
        }
        if (\is_numeric($haystack) && \is_numeric($candidate)) {
            return $haystack + 0 === $candidate + 0;
        }

        return false;
    }

    /**
     * Wrap `['skills' => 'typescript']` into `['skills' => ['typescript']]`
     * so contains-style probes hit array entries inside the haystack.
     */
    private function wrapScalarObjectCandidate(mixed $candidate): mixed
    {
        if (! \is_array($candidate) || \count($candidate) !== 1) {
            return $candidate;
        }
        $key = \array_key_first($candidate);
        $value = $candidate[$key];
        if (\is_array($value)) {
            return $candidate;
        }

        return [$key => [$value]];
    }

    /**
     * Natural-language fulltext approximation: tokenise on
     * whitespace/punctuation, support trailing wildcard prefix matching, and
     * honour quoted phrases as case-insensitive substring probes.
     */
    private function matchesFulltextRedis(string $haystack, string $needle): bool
    {
        if (\preg_match('/^"(.*)"$/u', \trim($needle), $matches) === 1) {
            $phrase = \mb_strtolower($matches[1]);
            if ($phrase === '') {
                return false;
            }

            return \str_contains(\mb_strtolower($haystack), $phrase);
        }

        $haystackTokens = $this->tokenizeForSearch($haystack);
        $needleTokens = $this->tokenizeForSearch($needle);
        if (empty($needleTokens) || empty($haystackTokens)) {
            return false;
        }
        $set = \array_flip($haystackTokens);
        foreach ($needleTokens as $token) {
            if (\str_ends_with($token, '*')) {
                $prefix = \substr($token, 0, -1);
                if ($prefix === '') {
                    continue;
                }
                foreach ($haystackTokens as $candidate) {
                    if (\str_starts_with($candidate, $prefix)) {
                        return true;
                    }
                }

                continue;
            }
            if (isset($set[$token])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, string>
     */
    private function tokenizeForSearch(string $text): array
    {
        $lower = \mb_strtolower($text);
        $parts = \preg_split('/[^\p{L}\p{N}*]+/u', $lower) ?: [];

        return \array_values(\array_filter($parts, fn (string $p): bool => $p !== ''));
    }

    /**
     * Extract user-requested attributes from any TYPE_SELECT queries. Internal
     * attributes (prefixed with `$` or `_`) are always preserved — only user
     * attributes are subject to projection.
     *
     * @param  array<int, Query>  $queries
     * @return array<int, string>
     */
    private function extractSelectionsFromQueries(array $queries): array
    {
        $selections = [];
        foreach ($queries as $query) {
            if ($query->getMethod() !== Query::TYPE_SELECT) {
                continue;
            }
            foreach ($query->getValues() as $value) {
                if (\is_string($value)) {
                    $selections[] = $value;
                }
            }
        }

        return $selections;
    }

    /**
     * Project a Document down to the supplied user-attribute selection.
     * `*` short-circuits projection (no filter applied). Internal attributes
     * (prefixed `$` / `_`) are always retained.
     *
     * @param  array<int, string>  $selections
     */
    private function projectDocument(Document $document, array $selections): Document
    {
        if (\in_array('*', $selections, true)) {
            return $document;
        }

        $projected = [];
        foreach ($document->getArrayCopy() as $field => $value) {
            if (\is_string($field) && (\str_starts_with($field, '$') || \str_starts_with($field, '_'))) {
                $projected[$field] = $value;

                continue;
            }
            if (\in_array($field, $selections, true)) {
                $projected[$field] = $value;
            }
        }

        return new Document($projected);
    }

    // === @architect:T40 end ===





    // === @architect:T50 owns: permissions + relationships ===

    public function createRelationship(string $collection, string $relatedCollection, string $type, bool $twoWay = false, string $id = '', string $twoWayKey = ''): bool
    {
        // Redis stores documents as flexible JSON blobs, so the relationship
        // "column" is registered on the collection's meta.attrs list rather
        // than added as a physical schema column. Mirrors Memory's
        // `registerRelationshipField` — minimal record only; the orchestrator
        // writes the full options (onDelete / side / related-collection) onto
        // the METADATA collection separately. The M2M junction collection
        // itself is created by the wrapper via the standard createCollection
        // path with explicit attributes.
        switch ($type) {
            case Database::RELATION_ONE_TO_ONE:
                $this->createAttribute($collection, $id, Database::VAR_RELATIONSHIP, 0, true, false, false);
                if ($twoWay) {
                    $this->createAttribute($relatedCollection, $twoWayKey, Database::VAR_RELATIONSHIP, 0, true, false, false);
                }
                break;
            case Database::RELATION_ONE_TO_MANY:
                $this->createAttribute($relatedCollection, $twoWayKey, Database::VAR_RELATIONSHIP, 0, true, false, false);
                break;
            case Database::RELATION_MANY_TO_ONE:
                $this->createAttribute($collection, $id, Database::VAR_RELATIONSHIP, 0, true, false, false);
                break;
            case Database::RELATION_MANY_TO_MANY:
                // Junction columns live on the junction collection, which is
                // created with explicit attributes by the wrapper.
                break;
            default:
                throw new DatabaseException('Invalid relationship type');
        }

        return true;
    }

    public function updateRelationship(string $collection, string $relatedCollection, string $type, bool $twoWay, string $key, string $twoWayKey, string $side, ?string $newKey = null, ?string $newTwoWayKey = null): bool
    {
        $key = $this->filter($key);
        $twoWayKey = $this->filter($twoWayKey);
        $newKey = $newKey !== null ? $this->filter($newKey) : null;
        $newTwoWayKey = $newTwoWayKey !== null ? $this->filter($newTwoWayKey) : null;

        switch ($type) {
            case Database::RELATION_ONE_TO_ONE:
                if ($newKey !== null && $newKey !== $key) {
                    $this->renameAttribute($collection, $key, $newKey);
                }
                if ($twoWay && $newTwoWayKey !== null && $newTwoWayKey !== $twoWayKey) {
                    $this->renameAttribute($relatedCollection, $twoWayKey, $newTwoWayKey);
                }
                break;
            case Database::RELATION_ONE_TO_MANY:
                if ($side === Database::RELATION_SIDE_PARENT) {
                    if ($newTwoWayKey !== null && $newTwoWayKey !== $twoWayKey) {
                        $this->renameAttribute($relatedCollection, $twoWayKey, $newTwoWayKey);
                    }
                } else {
                    if ($newKey !== null && $newKey !== $key) {
                        $this->renameAttribute($collection, $key, $newKey);
                    }
                }
                break;
            case Database::RELATION_MANY_TO_ONE:
                if ($side === Database::RELATION_SIDE_CHILD) {
                    if ($newTwoWayKey !== null && $newTwoWayKey !== $twoWayKey) {
                        $this->renameAttribute($relatedCollection, $twoWayKey, $newTwoWayKey);
                    }
                } else {
                    if ($newKey !== null && $newKey !== $key) {
                        $this->renameAttribute($collection, $key, $newKey);
                    }
                }
                break;
            case Database::RELATION_MANY_TO_MANY:
                $junction = $this->resolveJunctionCollection($collection, $relatedCollection, $side);
                if ($junction !== null) {
                    if ($newKey !== null && $newKey !== $key) {
                        $this->renameAttribute($junction, $key, $newKey);
                    }
                    if ($newTwoWayKey !== null && $newTwoWayKey !== $twoWayKey) {
                        $this->renameAttribute($junction, $twoWayKey, $newTwoWayKey);
                    }
                }
                break;
            default:
                throw new DatabaseException('Invalid relationship type');
        }

        return true;
    }

    public function deleteRelationship(string $collection, string $relatedCollection, string $type, bool $twoWay, string $key, string $twoWayKey, string $side): bool
    {
        $key = $this->filter($key);
        $twoWayKey = $this->filter($twoWayKey);

        switch ($type) {
            case Database::RELATION_ONE_TO_ONE:
                if ($side === Database::RELATION_SIDE_PARENT) {
                    $this->deleteAttribute($collection, $key);
                    if ($twoWay) {
                        $this->deleteAttribute($relatedCollection, $twoWayKey);
                    }
                } else {
                    $this->deleteAttribute($relatedCollection, $twoWayKey);
                    if ($twoWay) {
                        $this->deleteAttribute($collection, $key);
                    }
                }
                break;
            case Database::RELATION_ONE_TO_MANY:
                if ($side === Database::RELATION_SIDE_PARENT) {
                    $this->deleteAttribute($relatedCollection, $twoWayKey);
                } else {
                    $this->deleteAttribute($collection, $key);
                }
                break;
            case Database::RELATION_MANY_TO_ONE:
                if ($side === Database::RELATION_SIDE_PARENT) {
                    $this->deleteAttribute($collection, $key);
                } else {
                    $this->deleteAttribute($relatedCollection, $twoWayKey);
                }
                break;
            case Database::RELATION_MANY_TO_MANY:
                // Junction collection is dropped by the wrapper via cleanupCollection.
                break;
            default:
                throw new DatabaseException('Invalid relationship type');
        }

        return true;
    }

    // === @architect:T50 end ===





    // === @architect:T56 owns: transactions + journal ===

    public function startTransaction(): bool
    {
        $this->journalStack[] = [];
        $this->inTransaction++;

        return true;
    }

    public function commitTransaction(): bool
    {
        if ($this->inTransaction === 0) {
            return false;
        }

        $this->commitJournal();
        $this->inTransaction--;

        return true;
    }

    public function rollbackTransaction(): bool
    {
        if ($this->inTransaction === 0) {
            return false;
        }

        try {
            $this->rollbackJournal();
            $this->inTransaction--;
        } catch (\Throwable $e) {
            // A failed rollback (mid-replay) leaves the transaction in an
            // indeterminate state. Discard all pending journal state so the
            // connection is clean for reuse. Both must be cleared together to
            // preserve the count($journalStack) === inTransaction invariant:
            // resetting only the counter would strand parent frames that later
            // transactions merge into, growing the stack without bound.
            $this->inTransaction = 0;
            $this->journalStack = [];
            throw $e;
        }

        return true;
    }

    // === @architect:T56 end ===

    /**
     * Resolve any Operator-typed attributes against the existing document
     * before persisting. Mirrors Memory::applyOperators — non-operator
     * values pass through untouched.
     *
     * @param  array<string, mixed>  $attrs  Incoming attributes (may contain Operator instances)
     * @param  array<string, mixed>  $existing  Decoded document used as the operator's "current" value
     * @return array<string, mixed>
     */
    protected function applyOperators(array $attrs, array $existing): array
    {
        $result = [];
        foreach ($attrs as $attribute => $value) {
            if (Operator::isOperator($value)) {
                /** @var Operator $value */
                $result[$attribute] = $this->applyOperator($existing[$attribute] ?? null, $value);

                continue;
            }
            $result[$attribute] = $value;
        }

        return $result;
    }

    /**
     * Apply a single Operator to a stored value and return the new value.
     * Mirrors Memory::applyOperator — the SQL adapters express the same
     * semantics in CASE/JSON helpers (see MariaDB::getOperatorSQL).
     */
    protected function applyOperator(mixed $current, Operator $operator): mixed
    {
        $values = $operator->getValues();
        $method = $operator->getMethod();

        switch ($method) {
            case Operator::TYPE_INCREMENT:
                $by = $values[0] ?? 1;
                $max = $values[1] ?? null;
                $base = \is_numeric($current) ? $current + 0 : 0;
                if ($max !== null) {
                    // Guard: if the RESULT would exceed the max, leave it unchanged. Comparing
                    // remaining headroom keeps us inside PHP's int range. Must NOT short-circuit
                    // on `$base >= $max` — a negative $by moves the value down, so an over-max
                    // base can still land within bound (e.g. 52 + (-5) = 47 <= 50 must apply).
                    if (($max - $base) < $by) {
                        return $this->preserveNumericType($base, $base);
                    }
                }

                return $this->preserveNumericType($base, $base + $by);

            case Operator::TYPE_DECREMENT:
                $by = $values[0] ?? 1;
                $min = $values[1] ?? null;
                $base = \is_numeric($current) ? $current + 0 : 0;
                if ($min !== null) {
                    // Guard: leave unchanged only if the RESULT would go below min. Don't
                    // short-circuit on `$base <= $min` — a negative $by moves the value up.
                    if (($base - $min) < $by) {
                        return $this->preserveNumericType($base, $base);
                    }
                }

                return $this->preserveNumericType($base, $base - $by);

            case Operator::TYPE_MULTIPLY:
                $by = $values[0] ?? 1;
                $max = $values[1] ?? null;
                $base = \is_numeric($current) ? $current + 0 : 0;
                $result = $base * $by;
                if ($max !== null && $result > $max) {
                    return $this->preserveNumericType($base, $base);
                }

                return $this->preserveNumericType($base, $result);

            case Operator::TYPE_DIVIDE:
                $by = $values[0] ?? 1;
                $min = $values[1] ?? null;
                if ($by == 0) {
                    return $current;
                }
                $base = \is_numeric($current) ? $current + 0 : 0;
                $result = $base / $by;
                if ($min !== null && $result < $min) {
                    return $this->preserveNumericType($base, $base);
                }

                return $this->preserveNumericType($base, $result);

            case Operator::TYPE_MODULO:
                $by = $values[0] ?? 1;
                if ($by == 0) {
                    return $current;
                }
                $base = \is_numeric($current) ? (int) $current : 0;

                return $base % (int) $by;

            case Operator::TYPE_POWER:
                $by = $values[0] ?? 1;
                $max = $values[1] ?? null;
                $base = \is_numeric($current) ? $current + 0 : 0;
                if ($max !== null) {
                    // Leave the value unchanged for undefined inputs (0 to a negative power, or a
                    // negative base to a fractional exponent) — they produce INF/NaN, not a number.
                    if (($base == 0 && $by < 0) || ($base < 0 && \floor($by) != $by)) {
                        return $this->preserveNumericType($base, $base);
                    }
                    $result = $base ** $by;
                    // A result that overflows (INF) or exceeds the max also leaves the value as-is.
                    if (!\is_finite($result) || $result > $max) {
                        return $this->preserveNumericType($base, $base);
                    }

                    return $this->preserveNumericType($base, $result);
                }

                // 0 to a negative power, or a negative base to a fractional exponent, is not a real
                // number. Fail loudly with a clear exception rather than storing INF/NaN (which
                // also can't be JSON-encoded, so it would otherwise surface as a raw JsonException).
                $result = $base ** $by;
                if (!\is_finite($result)) {
                    throw new LimitException('Value out of range');
                }

                return $this->preserveNumericType($base, $result);

            case Operator::TYPE_STRING_CONCAT:
                return ((string) ($current ?? '')) . (string) ($values[0] ?? '');

            case Operator::TYPE_STRING_REPLACE:
                $search = (string) ($values[0] ?? '');
                $replace = (string) ($values[1] ?? '');
                if ($current === null) {
                    return null;
                }

                return \str_replace($search, $replace, (string) $current);

            case Operator::TYPE_TOGGLE:
                return ! (bool) $current;

            case Operator::TYPE_ARRAY_APPEND:
                $list = $this->coerceArray($current);

                return [...$list, ...\array_values($values)];

            case Operator::TYPE_ARRAY_PREPEND:
                $list = $this->coerceArray($current);

                return [...\array_values($values), ...$list];

            case Operator::TYPE_ARRAY_INSERT:
                $list = $this->coerceArray($current);
                $index = (int) ($values[0] ?? 0);
                $value = $values[1] ?? null;
                if ($index < 0) {
                    $index = 0;
                }
                if ($index > \count($list)) {
                    $index = \count($list);
                }
                \array_splice($list, $index, 0, [$value]);

                return $list;

            case Operator::TYPE_ARRAY_REMOVE:
                $list = $this->coerceArray($current);
                $needle = $values[0] ?? null;

                return \array_values(\array_filter($list, fn ($item) => $item !== $needle));

            case Operator::TYPE_ARRAY_UNIQUE:
                $list = $this->coerceArray($current);

                return \array_values(\array_unique($list, SORT_REGULAR));

            case Operator::TYPE_ARRAY_INTERSECT:
                $list = $this->coerceArray($current);
                $other = \array_values($values);

                return \array_values(\array_filter($list, fn ($item) => \in_array($item, $other, false)));

            case Operator::TYPE_ARRAY_DIFF:
                $list = $this->coerceArray($current);
                $other = \array_values($values);

                return \array_values(\array_filter($list, fn ($item) => ! \in_array($item, $other, false)));

            case Operator::TYPE_ARRAY_FILTER:
                $list = $this->coerceArray($current);
                $condition = (string) ($values[0] ?? '');
                $compare = $values[1] ?? null;

                return \array_values(\array_filter($list, fn ($item) => $this->matchesArrayFilter($item, $condition, $compare)));

            case Operator::TYPE_DATE_ADD_DAYS:
                $days = (int) ($values[0] ?? 0);

                return $this->shiftDate($current, $days * 86400);

            case Operator::TYPE_DATE_SUB_DAYS:
                $days = (int) ($values[0] ?? 0);

                return $this->shiftDate($current, -$days * 86400);

            case Operator::TYPE_DATE_SET_NOW:
                return DateTime::now();
        }

        throw new OperatorException("Invalid operator: {$method}");
    }

    protected function applyNumericLimit(int|float $value, int|float|null $bound, bool $isUpper): int|float
    {
        if ($bound === null) {
            return $value;
        }

        return $isUpper ? \min($value, $bound) : \max($value, $bound);
    }

    /**
     * Preserve int-ness when the original value is an int — without this,
     * PHP's arithmetic promotes the result to float and the Range validator
     * rejects an integer column post-update.
     */
    protected function preserveNumericType(int|float $original, int|float $result): int|float
    {
        if (\is_int($original) && \is_float($result) && $result === (float) (int) $result) {
            return (int) $result;
        }

        return $result;
    }

    /**
     * @return array<mixed>
     */
    protected function coerceArray(mixed $value): array
    {
        if (\is_array($value)) {
            return \array_values($value);
        }
        if (\is_string($value) && $value !== '') {
            $decoded = \json_decode($value, true);
            if (\is_array($decoded)) {
                return \array_values($decoded);
            }
        }

        return [];
    }

    protected function matchesArrayFilter(mixed $item, string $condition, mixed $compare): bool
    {
        return match ($condition) {
            Query::TYPE_EQUAL => $item == $compare,
            Query::TYPE_NOT_EQUAL => $item != $compare,
            Query::TYPE_GREATER => \is_numeric($item) && \is_numeric($compare) && $item + 0 > $compare + 0,
            Query::TYPE_GREATER_EQUAL => \is_numeric($item) && \is_numeric($compare) && $item + 0 >= $compare + 0,
            Query::TYPE_LESSER => \is_numeric($item) && \is_numeric($compare) && $item + 0 < $compare + 0,
            Query::TYPE_LESSER_EQUAL => \is_numeric($item) && \is_numeric($compare) && $item + 0 <= $compare + 0,
            Query::TYPE_IS_NULL => $item === null,
            Query::TYPE_IS_NOT_NULL => $item !== null,
            default => true,
        };
    }

    protected function shiftDate(mixed $current, int $seconds): ?string
    {
        if ($current === null) {
            return null;
        }
        try {
            $base = new \DateTime((string) $current);
        } catch (\Throwable) {
            return $current === '' ? null : (string) $current;
        }
        $base->modify(($seconds >= 0 ? '+' : '') . $seconds . ' seconds');

        return DateTime::format($base);
    }
}

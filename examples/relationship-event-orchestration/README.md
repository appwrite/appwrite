# Appwrite Relationship Event Orchestration

Production-grade workaround and reference architecture for the silent-event
behavior in Appwrite 1.6.x when documents are created via the *relationship
cascade* (parent A -> nested child B) instead of being created directly in
collection B.

This package contains:

- A **root-cause analysis** anchored to specific source lines in this repo.
- A **reproducible demo** (`npm run repro:bug`) that proves the bug.
- A **production orchestrator** (`src/orchestrator/`) that guarantees every
  child fires a real `documents.*.create` event.
- An **Appwrite Function** (`src/functions/relationshipEventFanout/`) that
  bolts onto unmodified clients and back-fills events for relationship
  cascades by observing the parent's create event.
- An **outbox** (`src/lib/outbox.ts`) that decouples event delivery from
  Appwrite's internal bus, giving at-least-once semantics.

---

## A. Technical explanation (root cause)

When you `POST /v1/databases/:db/collections/:A/documents` with a body that
contains nested objects on a relationship attribute (one-to-many A -> B),
Appwrite's `Create` action does this (see
`src/Appwrite/Platform/Modules/Databases/Http/Databases/Collections/Documents/Create.php`):

1. Builds a single `Utopia\Database\Document` for the parent that embeds
   nested child documents.
2. Calls `$dbForDatabases->createDocuments(...)` ONCE for the parent
   collection. The underlying `utopia-php/database` adapter sees the
   relationship field, splits it apart, and writes each child row in
   collection B inside the same storage transaction (cascade).
3. After the cascade completes, Appwrite sets a SINGLE event on
   `$queueForEvents`:

   ```php
   $queueForEvents
       ->setParam('documentId', $created[0]->getId())
       ->setParam('rowId', $created[0]->getId())
       ->setEvent('databases.[databaseId].collections.[collectionId].documents.[documentId].create');
   ```

   That `setEvent` is the only call that ultimately makes it to the
   per-request shutdown hook in `app/controllers/shared/api.php` line ~833,
   which is what dispatches the function/webhook/realtime payloads.

4. `processDocument()` in `Documents/Action.php` then recursively walks
   nested relations -- but ONLY to decorate metadata (`$databaseId`,
   `$collectionId`). It does **not** enqueue child events.

5. `triggerBulk()` only fans out over the top-level `$documents` array; it
   never visits cascaded grandchildren.

So child rows in B exist on disk, indexes are updated, permissions are
applied, but the per-request event pipeline never sees them. Functions /
webhooks subscribed to
`databases.*.collections.B.documents.*.create` therefore stay silent.

There is one apparent counter-example in `app/init/resources/request.php`
where a `Database::EVENT_DOCUMENT_CREATE` listener fans the storage-level
event back into the HTTP event queue:

```php
$eventDatabaseListener = function (...) {
    // Only trigger events for user creation with the database listener.
    if ($document->getCollection() !== 'users') {
        return;
    }
    ...
};
```

That listener is whitelisted to the `users` collection only ("intentional",
per the inline comment). It is **not** generalized for arbitrary
project-defined collections -- partly because firing the queue from inside a
storage-layer listener would also fire for every internal write (system
collections, audits, etc.), and partly because the event payload would
require enough HTTP context (route, project, response model) that the
storage layer doesn't have.

**Classification:** intentional architectural limitation, not a code bug.
It is the predictable outcome of:

- treating relationship cascades as a single storage operation,
- emitting the HTTP-layer event from the action that received the HTTP
  call, not from the storage adapter.

Reasonable changes upstream would either:

1. Extend `processDocument()` to enqueue per-collection events for cascaded
   children (still requires duplicating realtime + functions + webhooks
   dispatch for each, like `triggerBulk` already does), or
2. Generalize the `EVENT_DOCUMENT_CREATE` listener with a per-collection
   allowlist + payload shaping, similar to the `users.*.create` carve-out.

Until upstream lands one of those, application code must guarantee events
itself. That is what this package does.

### Exact source-area patch suggestion (if you maintain the fork)

File: `src/Appwrite/Platform/Modules/Databases/Http/Databases/Collections/Documents/Create.php`

Replace the single `setEvent` block (~lines 528-531) with a fan-out over
both the parent and every nested cascaded child. A minimal sketch:

```php
$this->emitCascadedCreateEvents(
    database: $database,
    rootCollection: $collection,
    rootDocument: $created[0],
    dbForProject: $dbForProject,
    queueForEvents: $queueForEvents,
    queueForRealtime: $queueForRealtime,
    queueForFunctions: $queueForFunctions,
    queueForWebhooks: $queueForWebhooks,
    eventProcessor: $eventProcessor,
);
```

Where `emitCascadedCreateEvents()` walks the freshly-created tree with
`processDocument`-style recursion, and for every nested `Document` whose
`$collection` is not the root collection, it:

1. Resolves the child's collection metadata
2. Calls `$queueForEvents->setEvent(...)` with that child's
   `databaseId`/`collectionId`/`documentId`
3. Pushes one trigger per `queueForFunctions` / `queueForWebhooks` /
   `queueForRealtime`, exactly like `triggerBulk` does today

The reason this hasn't shipped is that it requires the action to know
how to re-permission-check each child's payload against the project's
event subscriptions, and to keep cost predictable when you cascade
deep graphs. Both are solvable but invasive.

---

## B. Architecture recommendation

```
                       +-------------------+
   Client request ---> |  Orchestrator API |---+
                       +-------------------+   |
                              | 1. Create children directly in B
                              v
                       +-------------------+
                       |  Collection B     |---+  fires real
                       +-------------------+   |  documents.*.create
                              | 2. mirror to outbox
                              v
                       +-------------------+
                       |  outbox_events    |---+ relay worker
                       +-------------------+   | (CRON Appwrite Fn)
                              |
                              v
                       +-------------------+
                       |  Collection A     |  attach children by id
                       +-------------------+
```

Three independent guarantees:

1. **Appwrite-native events** fire because we create each child as a real
   top-level write. Functions, webhooks, and realtime channels all work
   unchanged.
2. **Outbox** provides at-least-once delivery even if a downstream consumer
   is down at the moment the native event fires.
3. **Compensating deletes** roll the orchestration back if any step fails
   after children were created -- preventing orphaned child documents.

If you cannot change client code, run the Appwrite Function in
`src/functions/relationshipEventFanout/` instead. It subscribes to A's
parent-create event and back-fills child events.

---

## C. Implementation

```
examples/relationship-event-orchestration/
|-- src/
|   |-- lib/
|   |   |-- appwrite.ts        # SDK client factory
|   |   |-- config.ts          # env loading
|   |   |-- idempotency.ts     # Appwrite-backed idempotency store
|   |   |-- logger.ts          # pino logger
|   |   |-- outbox.ts          # at-least-once outbox
|   |   `-- retry.ts           # exponential backoff w/ jitter
|   |-- orchestrator/
|   |   |-- createParentWithChildren.ts  # main orchestrator
|   |   `-- demo.ts            # runnable example
|   |-- functions/
|   |   `-- relationshipEventFanout/
|   |       |-- src/main.ts    # Appwrite Function entrypoint
|   |       |-- package.json
|   |       `-- tsconfig.json
|   `-- repro/
|       |-- setup.ts           # provisions collections + relationship
|       |-- demonstrate-bug.ts # shows direct works, cascade silent
|       `-- verify-fix.ts      # orchestrator path -- all events fire
|-- .env.example
|-- package.json
`-- tsconfig.json
```

### Quick start

```bash
cp .env.example .env       # fill in endpoint, project, api key
npm install
npm run setup              # creates db, collections, relationship, outbox, idempotency
npm run repro:bug          # observe missing child events
npm run repro:fix          # observe all events fire
```

---

## D. Suggested API flow

Step by step for `createParentWithChildren`:

1. Caller builds an idempotency key from the canonicalized request body.
2. Orchestrator reserves the key in `idempotency_keys`:
   - on `completed`, return cached result (true idempotency)
   - on `inflight`, fail-fast and let the caller retry later
3. For each child:
   - createDocument(B, childId, data) with exponential backoff
   - record in `outbox_events` with `correlationId = ${corr}:child:${childId}`
4. createDocument(A, parentId, { ...parent, relationshipKey: [childIds] })
5. Record parent in outbox with `correlationId = ${corr}:parent:${parentId}`
6. complete(idempotencyKey, result)
7. On any failure, compensating delete of children + parent + outbox rows

---

## E. Alternative approaches

| Approach | Native events fire? | Code change required | Failure isolation | Notes |
|---|---|---|---|---|
| Nested create (parent + relationship objects) | Parent only; children silent | None | Worst: atomic but events lost | Today's broken default |
| Child-first orchestrator (this repo) | All children + parent | Caller migrates to orchestrator | Compensating delete on partial failure | Recommended for new code |
| Fan-out Appwrite Function on parent event | Synthetic via outbox + webhook | None on client | Eventual consistency: at-least-once with dedupe | Best for legacy clients |
| Poll collection B for new docs | All | None | Latency seconds-minutes | Last resort |
| Patch Appwrite source `Create.php` | All | Fork maintenance | Best | Section A includes the patch sketch |

---

## F. Reliability concerns addressed

- **Idempotency.** Every orchestration call hashes a canonical view of its
  input into an idempotency key, reserved atomically via Appwrite's unique
  `$id` constraint. Replays short-circuit to the cached result.
- **Retries.** Every Appwrite write uses `withRetry` with jittered
  exponential backoff. Transient classes (5xx, 429, network) are retried;
  4xx are not.
- **Rollback.** On any failure mid-orchestration, the compensating delete
  removes the parent, any children that were created, and any outbox rows.
  The compensating path itself uses safe-delete (logs failures, never
  throws) so a dead bucket doesn't block recovery.
- **At-least-once delivery.** Even if Appwrite drops a native event under
  load, the outbox row exists and a relay worker keeps retrying with an
  attempt counter and exponential pull cadence.
- **Race conditions.** Two parallel callers with the same idempotency key:
  one reserves, the other sees `inflight` and fails-fast. There is no
  silent overwrite. Two parallel callers with the same correlation id on
  the outbox: the second call is coalesced (see `Outbox.enqueue`).
- **Cascade cleanup.** Collection A's relationship to B is provisioned
  with `cascade` delete behavior. If you delete a parent later, Appwrite
  deletes the children; native delete events also have the same blind
  spot for cascades, so the same orchestrator pattern (delete-children-first,
  then delete-parent) applies.

---

## Edge cases

- **Multi-level relationships** (A -> B -> C): apply the orchestrator
  recursively. The relationship key on B then takes child-C IDs.
- **Existing children attached by ID**: pass `children[i].id` to attach an
  existing B; the orchestrator skips creation and just attaches.
- **Permission divergence**: collection B and A often have different
  permission requirements. The orchestrator uses a server-side API key
  and explicit `Permission.*` writes; you should narrow the key scopes
  to `documents.write` and `databases.read` at minimum.
- **Concurrent identical submissions**: handled by idempotency reservation.
- **Hot reload of the function**: the Appwrite Function uses
  `correlationId = parentId:childId:parentUpdatedAt` so re-invocation
  on the same event doesn't double-fanout.

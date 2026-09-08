# appwrite-event

Appwrite queue event payloads and helpers.

Rust port of the parts of `Appwrite\Event\*` the Users API foundation needs:
building a queue message body (`Appwrite\Event\Event`), expanding an event
pattern like `users.[userId].create` into every concrete/wildcard event name
Realtime and webhooks match against (`Event::generateEvents()`), the delete
and audit message shapes (`Appwrite\Event\Message\{Delete,Audit}`), and the
publisher trait boundary those messages travel through
(`Appwrite\Event\Publisher\{Delete,Audit}`).

## Install

```toml
appwrite-event = { workspace = true }
```

## API

### `Event`

Builder mirroring the subset of `Appwrite\Event\Event` used to assemble a
queue message (`preparePayload()`), not publisher dispatch (`trigger()`) --
that belongs in `apps/server` once a `utopia-queue` publisher is wired up.

| Method | PHP equivalent |
|---|---|
| `Event::new()` | `new Event($publisher)` (minus the publisher) |
| `set_project`, `project` | `setProject`/`getProject` |
| `set_user`, `user`, `user_id` | `setUser`/`getUser`/`getUserId` |
| `set_event`, `event` | `setEvent`/`getEvent` |
| `set_param`, `param`, `params` | `setParam`/`getParam`/`getParams` |
| `set_payload`, `payload` | `setPayload`/`getPayload` (sensitive-field list is not tracked; see below) |
| `set_context`, `context` | `setContext`/`getContext` |
| `set_paused`, `is_paused` | `setPaused` |
| `to_message() -> Result<Value, EventError>` | `preparePayload()` |
| `reset()` | `reset()` |

```rust
use appwrite_event::Event;
use serde_json::json;

let message = Event::new()
    .set_project(json!({"$id": "proj1"}))
    .set_event("users.[userId].create")
    .set_param("userId", "user1")
    .set_payload(json!({"$id": "user1"}))
    .to_message()
    .unwrap();

assert_eq!(message["project"]["$id"], "proj1");
```

`to_message()` returns `{ project, user, userId, payload, context, events }`,
matching PHP's queue payload shape. Publisher-specific trimming
(`Event::trimPayload()`, which shrinks `project` to `$id`/`$sequence`/`database`
before enqueueing) is the publisher's job, not the builder's -- see
`AuditMessage::to_json`, which does exactly that trim for the audit queue.

### `generate_events`

Rust port of `Event::generateEvents(string $pattern, array $params)`, scoped
to patterns with **at most one sub-resource level**
(`type.resource.subType.subResource.action[.attribute]`) -- every Users API
event fits this shape (`users.[userId].create`, `users.[userId].update.email`,
`users.[userId].sessions.[sessionId].create`, `users.[userId].targets.[targetId].create`).
Given a pattern and its params, it returns every concrete event name
(placeholders substituted) plus every wildcard variant PHP produces,
including the single-parameter wildcard combinations PHP's nested-loop
`str_replace` passes generate.

```rust
use std::collections::BTreeMap;
use appwrite_event::generate_events;

let mut params = BTreeMap::new();
params.insert("userId".to_string(), "user1".to_string());

let events = generate_events("users.[userId].create", &params).unwrap();
assert!(events.contains(&"users.user1.create".to_string()));
assert!(events.contains(&"users.*.create".to_string()));
```

A pattern referencing a param that was never set (`Event::set_param`) returns
`EventError::MissingParam`, matching PHP's `InvalidArgumentException`.

### `DeleteMessage` / `AuditMessage`

Plain data structs mirroring `Appwrite\Event\Message\Delete::toArray()` and
`Appwrite\Event\Message\Audit::toArray()` via `to_json()`. Constants
`DELETE_TYPE_DOCUMENT`, `DELETE_TYPE_USERS`, `DELETE_TYPE_TARGET`,
`DELETE_TYPE_SESSIONS`, `RESOURCE_TYPE_USERS` mirror the corresponding
`DELETE_TYPE_*`/`RESOURCE_TYPE_*` constants in `app/init/constants.php` that
the Users API delete/session/target flows use.

### `DeletePublisher` / `AuditPublisher`

Trait boundary mirroring `Appwrite\Event\Publisher\{Delete,Audit}`, minus the
`Utopia\Queue\Publisher`/Redis wiring (that belongs in `apps/server`). Ships
with:

- `MemoryDeletePublisher`, `MemoryAuditPublisher` -- push into an in-process
  `Vec` behind a `Mutex`; `messages()`/`drain()` for assertions.
- `CallbackDeletePublisher<F>` -- forwards each enqueued message to a
  closure, for tests that want to observe enqueue order without holding a
  publisher reference.

## Deviations from PHP

- `generate_events` does not port `subSubResource` handling or the
  databases/collections-vs-tables mirroring PHP layers on top
  (`Event::mirrorCollectionEvents()`, `Event::getDatabaseTypeEvents()`).
  Neither applies to the Users API domain this crate is scaffolding for;
  both would need a dedicated `appwrite-event-database` (or similar) port if
  the database event system moves to Rust.
  `Event::parseEventPattern()`'s `subSubType`/`subSubResource` fields are
  dropped from the internal parsed-pattern representation for the same
  reason.
- `AuditPublisher::enqueue` does not read `_APP_EDITION` to no-op on
  self-hosted installs; that policy is an `apps/server` deployment concern,
  not part of the publisher's domain contract.
- Publishers are `Send + Sync` traits over owned messages rather than PHP's
  `readonly class` wrapping a shared `Utopia\Queue\Publisher` instance --
  idiomatic for a Rust trait boundary meant to be implemented by a
  Redis-backed publisher later.

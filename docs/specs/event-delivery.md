# Event delivery identity and receipts

Appwrite events can carry a stable logical envelope ID across webhook, Function,
and Realtime publication. The envelope lets each recipient recognize a replay
and lets Appwrite remember which fanout targets completed.

## Creating an envelope

The producer owns the operation identity and terminal outcome. It must derive the
envelope before triggering the event:

```php
$event->setEnvelopeId(Envelope::forOutcome(
    operationId: $operationId,
    outcome: $outcome,
));
```

The operation ID must be stable for the full operation. The outcome must identify
one terminal transition, such as `completed`, `failed`, or `canceled`. Repeating
the same operation and outcome produces the same envelope; a different outcome
produces a different envelope.

Generic event producers must not mint a random envelope. An empty envelope keeps
the legacy behavior and bypasses receipt storage.

The platform database must run the V25 (`2.0.0`) migration before any producer
emits a non-empty envelope. Deploying an envelope-bearing producer against an
older schema is unsupported because the receipt collection does not exist.

## Fanout receipts

A completed delivery is keyed by:

- source project
- source project generation
- envelope ID
- sink (`webhook`, `function`, or `realtime`)
- target ID

The key is stored in the platform `eventReceipts` collection after the target
delivery returns successfully. A retry skips completed targets and attempts only
targets without a receipt. This allows a fanout interrupted after one target to
resume at the next target.

Receipts are retained for the lifetime of their project generation so a delayed
replay cannot repeat a completed effect. Project deletion removes only receipts
for the deleted generation; recreating the same public project ID cannot inherit
or lose receipts from another generation. There is no time-based eviction because
the event retry horizon is not bounded by this component.

Webhook requests expose the envelope as `X-Appwrite-Event-Id`. Event-triggered
Functions reuse a deterministic execution ID for the same envelope and Function,
receive `x-appwrite-event-id`, and receive `APPWRITE_FUNCTION_EVENT_ID` in v2
runtimes. Realtime messages expose it as `data.envelopeId`. A Realtime receipt
records that the Redis Pub/Sub publish call completed; it is not an
acknowledgement from a Realtime worker or connected client.

Function execution result events derive a child envelope from the parent
envelope, Function ID, deterministic execution ID, and terminal execution status.

## Delivery guarantees

The logical event identity is stable. Webhook and Function transport remains at
least once. A process can crash after a recipient accepts a request and before
Appwrite records the receipt. In that window, the recipient can receive the same
logical envelope again. Concurrent attempts can produce the same effect before
either one records its receipt.

Envelope-bearing Realtime publication errors propagate so an outer durable
worker can retry the event. Redis Pub/Sub itself is best effort: a successful
publish with no subscribed Realtime worker cannot be distinguished from a
delivered message. The Realtime receipt therefore prevents repeated publication
after Redis accepts it, but does not make Realtime transport durable or at least
once.

Recipients that require exactly-once effects must deduplicate by the exposed
envelope ID or deterministic Function execution ID. The receipt prevents replays
after completion is recorded; it does not claim physical exactly-once transport
or Realtime subscriber delivery.

| Interruption point | Receipt | Retry behavior |
| --- | --- | --- |
| Before target delivery | Missing | Attempts the target |
| During target delivery | Missing | Attempts the target with the same envelope |
| After target accepts, before receipt write | Missing | May replay the same envelope |
| After receipt write | Complete | Skips the target |
| After one target completes, before the next | Mixed | Skips completed targets and resumes missing targets |

Queue-level atomic enqueue deduplication is a separate concern. Receipt-backed
fanout does not depend on it and must not be described as an atomic transport
claim.

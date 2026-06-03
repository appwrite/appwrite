import { Databases, ID } from 'node-appwrite';
import { config } from '../lib/config.js';
import { logger } from '../lib/logger.js';
import { withRetry } from '../lib/retry.js';
import {
  IdempotencyStore,
  buildIdempotencyKey,
} from '../lib/idempotency.js';
import { Outbox } from '../lib/outbox.js';

/**
 * Public input contract.
 *
 * `parent`   -> attributes of the parent document (excluding the relationship
 *               field).
 * `children` -> attributes for each child document. We will create them in
 *               collection B FIRST so their `documents.*.create` events fire
 *               natively, then attach them to the parent by ID.
 * `relationshipKey` -> name of the relationship attribute on A pointing to B.
 *                       Example: `tasks`.
 */
export type CreateParentWithChildrenInput = {
  parentId?: string;            // optional; defaults to ID.unique()
  parent: Record<string, unknown>;
  children: Array<{
    id?: string;
    data: Record<string, unknown>;
  }>;
  relationshipKey: string;
  /** Optional opaque correlation id for tracing across logs/outbox. */
  correlationId?: string;
};

export type CreateParentWithChildrenResult = {
  parentId: string;
  childrenIds: string[];
  correlationId: string;
};

/**
 * Production-grade orchestrator that GUARANTEES child-document
 * `documents.*.create` events fire when creating a parent + children
 * graph, by inverting the natural Appwrite "parent-first cascade" order.
 *
 * Strategy: children-first, attach-by-id.
 *
 *   1. Reserve idempotency key.
 *   2. Create each child in collection B directly. Each create dispatches
 *      a real `databases.[db].collections.[B].documents.[id].create` event
 *      that subscribed functions/webhooks/realtime channels will receive.
 *   3. Mirror each child in the outbox so a relay worker can re-deliver
 *      to bespoke automations even if Appwrite's event bus drops a message.
 *   4. Create the parent in collection A with the relationship field set to
 *      an array of child IDs (not nested objects).
 *   5. On any failure after step 2, run a compensating delete on the
 *      successfully created children + outbox rows.
 *   6. Persist the final result against the idempotency key.
 *
 * This pattern works because creating a child by ID-only on the parent does
 * NOT trigger a child-document cascade in Appwrite -- it's a normal "attach
 * an existing document via relationship" path and produces no implicit child
 * creates that would bypass events.
 */
export class ParentWithChildrenOrchestrator {
  constructor(
    private readonly db: Databases,
    private readonly idempotency: IdempotencyStore,
    private readonly outbox: Outbox,
  ) {}

  async run(
    input: CreateParentWithChildrenInput,
  ): Promise<CreateParentWithChildrenResult> {
    const correlationId =
      input.correlationId ?? `corr_${ID.unique()}`;

    const idempotencyKey = buildIdempotencyKey({
      op: 'createParentWithChildren',
      parentId: input.parentId ?? null,
      children: input.children.map((c) => ({
        id: c.id ?? null,
        data: c.data,
      })),
      relationshipKey: input.relationshipKey,
    });

    const claim = await this.idempotency.begin<CreateParentWithChildrenResult>(
      idempotencyKey,
    );

    if (claim.state === 'completed') {
      logger.info(
        { idempotencyKey, correlationId },
        'idempotent replay; returning cached result',
      );
      return claim.result;
    }
    if (claim.state === 'inflight') {
      throw new Error(
        `another worker is already processing key=${idempotencyKey} (started ${claim.startedAt})`,
      );
    }

    const log = logger.child({ correlationId, idempotencyKey });
    log.info({ children: input.children.length }, 'orchestrator: starting');

    const createdChildIds: string[] = [];
    const enqueuedOutboxIds: string[] = [];
    let parentDocumentId: string | null = null;

    try {
      // 1. Create children FIRST so each fires its own create event.
      for (const child of input.children) {
        const childId = child.id ?? ID.unique();

        const created = await withRetry(
          () =>
            this.db.createDocument(
              config.databaseId,
              config.collectionB,
              childId,
              child.data,
            ),
          { ...defaultPolicy, operation: 'createChild' },
        );
        createdChildIds.push(created.$id);

        // Mirror to outbox to make the downstream delivery durable.
        const row = await this.outbox.enqueue({
          kind: `databases.${config.databaseId}.collections.${config.collectionB}.documents.create`,
          databaseId: config.databaseId,
          collectionId: config.collectionB,
          documentId: created.$id,
          payload: created as unknown as Record<string, unknown>,
          correlationId: `${correlationId}:child:${created.$id}`,
        });
        enqueuedOutboxIds.push(row.$id);
      }

      // 2. Create parent and ATTACH by id rather than nest.
      parentDocumentId = input.parentId ?? ID.unique();
      const parent = await withRetry(
        () =>
          this.db.createDocument(
            config.databaseId,
            config.collectionA,
            parentDocumentId!,
            {
              ...input.parent,
              [input.relationshipKey]: createdChildIds,
            },
          ),
        { ...defaultPolicy, operation: 'createParent' },
      );

      // 3. Mirror the parent event too so a fan-out worker can react.
      await this.outbox.enqueue({
        kind: `databases.${config.databaseId}.collections.${config.collectionA}.documents.create`,
        databaseId: config.databaseId,
        collectionId: config.collectionA,
        documentId: parent.$id,
        payload: parent as unknown as Record<string, unknown>,
        correlationId: `${correlationId}:parent:${parent.$id}`,
      });

      const result: CreateParentWithChildrenResult = {
        parentId: parent.$id,
        childrenIds: createdChildIds,
        correlationId,
      };

      await this.idempotency.complete(idempotencyKey, result);

      log.info(
        { parentId: parent.$id, children: createdChildIds.length },
        'orchestrator: success',
      );
      return result;
    } catch (error) {
      log.error({ err: error }, 'orchestrator: failure, compensating');
      await this.compensate({
        log,
        createdChildIds,
        enqueuedOutboxIds,
        parentDocumentId,
      });
      throw error;
    }
  }

  private async compensate(args: {
    log: ReturnType<typeof logger.child>;
    createdChildIds: string[];
    enqueuedOutboxIds: string[];
    parentDocumentId: string | null;
  }): Promise<void> {
    const { log, createdChildIds, enqueuedOutboxIds, parentDocumentId } = args;

    if (parentDocumentId) {
      await this.safeDelete(
        () =>
          this.db.deleteDocument(
            config.databaseId,
            config.collectionA,
            parentDocumentId,
          ),
        log,
        { parentId: parentDocumentId },
      );
    }

    for (const id of createdChildIds) {
      await this.safeDelete(
        () =>
          this.db.deleteDocument(
            config.databaseId,
            config.collectionB,
            id,
          ),
        log,
        { childId: id },
      );
    }

    for (const id of enqueuedOutboxIds) {
      await this.safeDelete(
        () =>
          this.db.deleteDocument(
            config.databaseId,
            'outbox_events',
            id,
          ),
        log,
        { outboxId: id },
      );
    }
  }

  private async safeDelete(
    fn: () => Promise<unknown>,
    log: ReturnType<typeof logger.child>,
    context: Record<string, unknown>,
  ): Promise<void> {
    try {
      await fn();
    } catch (error) {
      log.warn({ err: error, ...context }, 'compensation delete failed');
    }
  }
}

const defaultPolicy = {
  attempts: 5,
  baseDelayMs: 150,
  maxDelayMs: 3_000,
};

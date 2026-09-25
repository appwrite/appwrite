import { Databases, ID, Permission, Query, Role } from 'node-appwrite';
import { config } from './config.js';
import { logger } from './logger.js';
import { withRetry } from './retry.js';

/**
 * Outbox pattern.
 *
 * Why we need it:
 *   Appwrite 1.6.x does NOT emit `documents.*.create` events for child
 *   documents that are cascaded into existence via a parent's relationship
 *   field (see RCA in README). That means functions subscribed to
 *   `databases.*.collections.B.documents.*.create` will silently miss those
 *   creations.
 *
 * Strategy:
 *   1. Application code writes an *outbox row* in the same logical operation
 *      that produces the side effect (here: a child-document create).
 *   2. A relay worker (Appwrite Function on a CRON, or any consumer of the
 *      `databases.*.documents.*.create` event on the outbox collection)
 *      delivers it to the real downstream automation: HTTP webhook, queue,
 *      function execution.
 *   3. Rows are marked `delivered` after success, or get an attempt-counter
 *      bump on failure so the relay can back off.
 *
 * Because Appwrite reliably fires events for *direct* document creation,
 * writing to the outbox is the bridge that makes our system event-driven
 * even though native relationship cascades are silent.
 */
export const OUTBOX_COLLECTION = 'outbox_events';

export type OutboxEvent = {
  kind: string;                    // e.g. 'documents.children.create'
  databaseId: string;
  collectionId: string;
  documentId: string;
  payload: Record<string, unknown>;
  correlationId: string;
};

export type OutboxRow = OutboxEvent & {
  $id: string;
  status: 'pending' | 'delivered' | 'failed';
  attempts: number;
  lastError: string | null;
  enqueuedAt: string;
  deliveredAt: string | null;
};

export class Outbox {
  constructor(private readonly db: Databases) {}

  /**
   * Enqueue an event. Must be safe to call repeatedly with the same
   * `correlationId` -- duplicates are coalesced via a query check.
   */
  async enqueue(event: OutboxEvent): Promise<OutboxRow> {
    const existing = await this.db.listDocuments(
      config.databaseId,
      OUTBOX_COLLECTION,
      [Query.equal('correlationId', event.correlationId), Query.limit(1)],
    );
    if (existing.total > 0) {
      logger.debug(
        { correlationId: event.correlationId },
        'outbox: duplicate enqueue ignored',
      );
      return existing.documents[0] as unknown as OutboxRow;
    }

    return (await this.db.createDocument(
      config.databaseId,
      OUTBOX_COLLECTION,
      ID.unique(),
      {
        kind: event.kind,
        databaseId: event.databaseId,
        collectionId: event.collectionId,
        documentId: event.documentId,
        payload: JSON.stringify(event.payload),
        correlationId: event.correlationId,
        status: 'pending',
        attempts: 0,
        lastError: null,
        enqueuedAt: new Date().toISOString(),
        deliveredAt: null,
      },
      [
        Permission.read(Role.any()),
        Permission.update(Role.any()),
        Permission.delete(Role.any()),
      ],
    )) as unknown as OutboxRow;
  }

  async markDelivered(id: string): Promise<void> {
    await this.db.updateDocument(config.databaseId, OUTBOX_COLLECTION, id, {
      status: 'delivered',
      deliveredAt: new Date().toISOString(),
      lastError: null,
    });
  }

  async markFailed(id: string, error: unknown): Promise<void> {
    const row = (await this.db.getDocument(
      config.databaseId,
      OUTBOX_COLLECTION,
      id,
    )) as unknown as OutboxRow;
    await this.db.updateDocument(config.databaseId, OUTBOX_COLLECTION, id, {
      status: row.attempts >= 9 ? 'failed' : 'pending',
      attempts: row.attempts + 1,
      lastError: stringifyError(error),
    });
  }

  /**
   * Drain pending events. Should be called from the relay worker.
   * Returns the number of rows successfully delivered.
   */
  async drain(
    handler: (row: OutboxRow) => Promise<void>,
    batch = 25,
  ): Promise<number> {
    const pending = await this.db.listDocuments(
      config.databaseId,
      OUTBOX_COLLECTION,
      [
        Query.equal('status', 'pending'),
        Query.orderAsc('enqueuedAt'),
        Query.limit(batch),
      ],
    );

    let delivered = 0;
    for (const doc of pending.documents) {
      const row = doc as unknown as OutboxRow;
      try {
        await withRetry(() => handler(row), {
          attempts: 3,
          baseDelayMs: 200,
          maxDelayMs: 2_000,
          operation: `outbox.deliver.${row.kind}`,
        });
        await this.markDelivered(row.$id);
        delivered++;
      } catch (error) {
        await this.markFailed(row.$id, error);
      }
    }
    return delivered;
  }
}

function stringifyError(error: unknown): string {
  if (error instanceof Error) {
    return `${error.name}: ${error.message}`;
  }
  return JSON.stringify(error);
}

/**
 * Provision the outbox collection. Called by `repro/setup.ts`.
 */
export async function ensureOutboxCollection(db: Databases): Promise<void> {
  try {
    await db.getCollection(config.databaseId, OUTBOX_COLLECTION);
    return;
  } catch (error) {
    if ((error as { code?: number }).code !== 404) throw error;
  }

  await db.createCollection(
    config.databaseId,
    OUTBOX_COLLECTION,
    'Outbox Events',
    [
      Permission.read(Role.any()),
      Permission.create(Role.any()),
      Permission.update(Role.any()),
      Permission.delete(Role.any()),
    ],
    true,
  );

  await db.createStringAttribute(config.databaseId, OUTBOX_COLLECTION, 'kind', 128, true);
  await db.createStringAttribute(config.databaseId, OUTBOX_COLLECTION, 'databaseId', 64, true);
  await db.createStringAttribute(config.databaseId, OUTBOX_COLLECTION, 'collectionId', 64, true);
  await db.createStringAttribute(config.databaseId, OUTBOX_COLLECTION, 'documentId', 64, true);
  await db.createStringAttribute(config.databaseId, OUTBOX_COLLECTION, 'payload', 1_000_000, false);
  await db.createStringAttribute(config.databaseId, OUTBOX_COLLECTION, 'correlationId', 128, true);
  await db.createStringAttribute(config.databaseId, OUTBOX_COLLECTION, 'status', 16, true, 'pending');
  await db.createIntegerAttribute(config.databaseId, OUTBOX_COLLECTION, 'attempts', true, 0);
  await db.createStringAttribute(config.databaseId, OUTBOX_COLLECTION, 'lastError', 4096, false);
  await db.createDatetimeAttribute(config.databaseId, OUTBOX_COLLECTION, 'enqueuedAt', true);
  await db.createDatetimeAttribute(config.databaseId, OUTBOX_COLLECTION, 'deliveredAt', false);

  await new Promise((r) => setTimeout(r, 1500));

  await db.createIndex(
    config.databaseId,
    OUTBOX_COLLECTION,
    'status_enqueuedAt_idx',
    'key',
    ['status', 'enqueuedAt'],
    ['ASC', 'ASC'],
  );
  await db.createIndex(
    config.databaseId,
    OUTBOX_COLLECTION,
    'correlationId_idx',
    'key',
    ['correlationId'],
    ['ASC'],
  );
}

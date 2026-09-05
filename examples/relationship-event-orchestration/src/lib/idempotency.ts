import { Databases, ID, Permission, Query, Role } from 'node-appwrite';
import { config } from './config.js';
import { logger } from './logger.js';

/**
 * Idempotency store backed by an Appwrite collection.
 *
 * The contract is:
 *   begin(key, ttlSec)   -> RESERVED | INFLIGHT | COMPLETED
 *   complete(key, result)-> attaches the result so future replays can
 *                           short-circuit
 *
 * The reservation uses an Appwrite document with the idempotency key as `$id`.
 * Duplicate `$id` triggers a duplicate-document error (409) atomically, so we
 * never need a distributed lock service.
 */
export const IDEMPOTENCY_COLLECTION = 'idempotency_keys';

export type IdempotencyOutcome<TResult> =
  | { state: 'reserved' }
  | { state: 'inflight'; startedAt: string }
  | { state: 'completed'; result: TResult; finishedAt: string };

export class IdempotencyStore {
  constructor(private readonly db: Databases) {}

  /**
   * Try to claim an idempotency key.
   *
   * RETURNS:
   *  - `reserved` : this caller owns the operation; proceed
   *  - `inflight` : another caller is still working; the caller may poll or fail-fast
   *  - `completed`: the operation is finished; the caller should return the cached result
   */
  async begin<TResult>(
    key: string,
    ttlSeconds: number = config.idempotencyTtlSeconds,
  ): Promise<IdempotencyOutcome<TResult>> {
    try {
      await this.db.createDocument(
        config.databaseId,
        IDEMPOTENCY_COLLECTION,
        key,
        {
          status: 'inflight',
          startedAt: new Date().toISOString(),
          expiresAt: new Date(Date.now() + ttlSeconds * 1000).toISOString(),
          result: null,
        },
        [
          Permission.read(Role.any()),
          Permission.update(Role.any()),
          Permission.delete(Role.any()),
        ],
      );
      return { state: 'reserved' };
    } catch (error) {
      const code = (error as { code?: number }).code;
      if (code !== 409) {
        throw error;
      }

      const existing = await this.db.getDocument(
        config.databaseId,
        IDEMPOTENCY_COLLECTION,
        key,
      );

      if (existing.status === 'completed') {
        return {
          state: 'completed',
          result: existing.result as TResult,
          finishedAt: existing.finishedAt as string,
        };
      }
      return {
        state: 'inflight',
        startedAt: existing.startedAt as string,
      };
    }
  }

  /**
   * Mark an idempotency key as completed and attach the result.
   * The result must be JSON-serializable.
   */
  async complete<TResult>(key: string, result: TResult): Promise<void> {
    await this.db.updateDocument(
      config.databaseId,
      IDEMPOTENCY_COLLECTION,
      key,
      {
        status: 'completed',
        finishedAt: new Date().toISOString(),
        result: JSON.stringify(result),
      },
    );
  }

  /**
   * Best-effort cleanup of expired idempotency rows. Safe to run from a cron.
   */
  async gc(batch = 100): Promise<number> {
    const now = new Date().toISOString();
    let removed = 0;

    const expired = await this.db.listDocuments(
      config.databaseId,
      IDEMPOTENCY_COLLECTION,
      [Query.lessThan('expiresAt', now), Query.limit(batch)],
    );

    for (const doc of expired.documents) {
      try {
        await this.db.deleteDocument(
          config.databaseId,
          IDEMPOTENCY_COLLECTION,
          doc.$id,
        );
        removed++;
      } catch (error) {
        logger.warn({ id: doc.$id, error }, 'idempotency gc: failed to delete');
      }
    }

    return removed;
  }
}

/**
 * Helper: deterministic idempotency key for a (parent, children) submission.
 * Callers should hash a stable shape that includes the user, parent id, and
 * children payload to make replays safe.
 */
export function buildIdempotencyKey(parts: Record<string, unknown>): string {
  const canonical = JSON.stringify(parts, Object.keys(parts).sort());
  return `idem_${djb2(canonical)}`;
}

function djb2(input: string): string {
  let hash = 5381;
  for (let i = 0; i < input.length; i++) {
    hash = ((hash << 5) + hash + input.charCodeAt(i)) | 0;
  }
  return (hash >>> 0).toString(36);
}

/**
 * Provision the idempotency collection. Called by `repro/setup.ts`.
 */
export async function ensureIdempotencyCollection(db: Databases): Promise<void> {
  try {
    await db.getCollection(config.databaseId, IDEMPOTENCY_COLLECTION);
    return;
  } catch (error) {
    if ((error as { code?: number }).code !== 404) throw error;
  }

  await db.createCollection(
    config.databaseId,
    IDEMPOTENCY_COLLECTION,
    'Idempotency Keys',
    [
      Permission.read(Role.any()),
      Permission.create(Role.any()),
      Permission.update(Role.any()),
      Permission.delete(Role.any()),
    ],
    true, // documentSecurity
  );

  await db.createStringAttribute(
    config.databaseId,
    IDEMPOTENCY_COLLECTION,
    'status',
    32,
    true,
  );
  await db.createDatetimeAttribute(
    config.databaseId,
    IDEMPOTENCY_COLLECTION,
    'startedAt',
    true,
  );
  await db.createDatetimeAttribute(
    config.databaseId,
    IDEMPOTENCY_COLLECTION,
    'finishedAt',
    false,
  );
  await db.createDatetimeAttribute(
    config.databaseId,
    IDEMPOTENCY_COLLECTION,
    'expiresAt',
    true,
  );
  await db.createStringAttribute(
    config.databaseId,
    IDEMPOTENCY_COLLECTION,
    'result',
    1_000_000,
    false,
  );

  await db.createIndex(
    config.databaseId,
    IDEMPOTENCY_COLLECTION,
    'expiresAt_idx',
    'key',
    ['expiresAt'],
    ['ASC'],
  );

  await waitForAttributes(db, IDEMPOTENCY_COLLECTION, [
    'status',
    'startedAt',
    'finishedAt',
    'expiresAt',
    'result',
  ]);
}

async function waitForAttributes(
  db: Databases,
  collectionId: string,
  keys: string[],
): Promise<void> {
  // Attribute creation is asynchronous in Appwrite. We poll until all keys
  // become 'available' or 30 s pass, whichever first.
  const deadline = Date.now() + 30_000;
  while (Date.now() < deadline) {
    const list = await db.listAttributes(config.databaseId, collectionId);
    const byKey = new Map(list.attributes.map((a: any) => [a.key, a.status]));
    if (keys.every((k) => byKey.get(k) === 'available')) return;
    await new Promise((r) => setTimeout(r, 500));
  }
  throw new Error(
    `attributes ${keys.join(', ')} did not become available within 30s`,
  );
}

/**
 * Relationship Event Fan-Out Function
 *
 * Deploy this as an Appwrite Function subscribed to:
 *     databases.*.collections.<A>.documents.*.create
 *     databases.*.collections.<A>.documents.*.update
 *
 * The function inspects the parent document's relationship field, finds child
 * documents that the cascade created silently, and either:
 *   (a) re-issues a synthetic event via the outbox collection, or
 *   (b) directly executes a downstream Appwrite Function by ID, or
 *   (c) calls an external webhook.
 *
 * It is idempotent: each child is keyed in the outbox by
 * `${parent.$id}:${child.$id}:${parent.$updatedAt}` so retries do not duplicate
 * fan-out.
 *
 * Build:
 *   npm install
 *   npm run build
 *
 * Variables (configure on the Function):
 *   APPWRITE_FUNCTION_API_ENDPOINT   (set automatically)
 *   APPWRITE_FUNCTION_PROJECT_ID     (set automatically)
 *   APPWRITE_API_KEY                 - dynamic key recommended, scopes:
 *                                      databases.read, documents.write
 *   PARENT_COLLECTION_ID             - id of collection A
 *   CHILD_COLLECTION_ID              - id of collection B
 *   RELATIONSHIP_KEY                 - name of the relationship attribute
 *   FANOUT_FUNCTION_ID               - optional, function to execute per child
 *   FANOUT_WEBHOOK_URL               - optional, HTTP webhook per child
 */

import { Client, Databases, Functions, ID, Query } from 'node-appwrite';

type EventContext = {
  req: {
    headers: Record<string, string>;
    bodyJson?: unknown;
    bodyRaw?: string;
  };
  res: {
    json: (data: unknown, status?: number) => unknown;
    empty: () => unknown;
  };
  log: (msg: unknown) => void;
  error: (msg: unknown) => void;
};

type AppwriteDocument = {
  $id: string;
  $collectionId: string;
  $databaseId: string;
  $createdAt: string;
  $updatedAt: string;
  [key: string]: unknown;
};

export default async function (context: EventContext): Promise<unknown> {
  const { req, res, log, error } = context;

  const eventHeader =
    req.headers['x-appwrite-event'] ?? req.headers['X-Appwrite-Event'];
  if (!eventHeader) {
    return res.json(
      { ok: false, reason: 'missing x-appwrite-event header' },
      400,
    );
  }

  const payload =
    typeof req.bodyJson === 'object' && req.bodyJson !== null
      ? (req.bodyJson as AppwriteDocument)
      : (JSON.parse(req.bodyRaw ?? '{}') as AppwriteDocument);

  if (!payload.$id || !payload.$collectionId) {
    return res.json({ ok: false, reason: 'invalid event payload' }, 400);
  }

  const parentCollectionId = required('PARENT_COLLECTION_ID');
  const childCollectionId = required('CHILD_COLLECTION_ID');
  const relationshipKey = required('RELATIONSHIP_KEY');

  if (payload.$collectionId !== parentCollectionId) {
    return res.json({
      ok: true,
      skipped: true,
      reason: `collection ${payload.$collectionId} is not the configured parent`,
    });
  }

  const client = new Client()
    .setEndpoint(required('APPWRITE_FUNCTION_API_ENDPOINT'))
    .setProject(required('APPWRITE_FUNCTION_PROJECT_ID'))
    .setKey(required('APPWRITE_API_KEY'));

  const db = new Databases(client);
  const functions = new Functions(client);

  // Re-fetch the parent so we have the latest relationship state. The event
  // payload sometimes lacks the resolved relationship snapshot.
  const parent = (await db.getDocument(
    payload.$databaseId,
    parentCollectionId,
    payload.$id,
  )) as AppwriteDocument;

  const related = parent[relationshipKey];
  if (related == null) {
    log('parent has no related children; nothing to fan out');
    return res.empty();
  }

  const childIds = normalizeRelated(related);
  log(`parent ${parent.$id}: ${childIds.length} children`);

  const seen = new Set<string>();
  for (const childId of childIds) {
    if (seen.has(childId)) continue;
    seen.add(childId);

    const dedupeKey = `${parent.$id}:${childId}:${parent.$updatedAt}`;

    const exists = await db
      .listDocuments(payload.$databaseId, 'outbox_events', [
        Query.equal('correlationId', dedupeKey),
        Query.limit(1),
      ])
      .catch(() => ({ total: 0, documents: [] }));

    if (exists.total > 0) {
      log(`child ${childId} already fanned out; skipping`);
      continue;
    }

    let child: AppwriteDocument;
    try {
      child = (await db.getDocument(
        payload.$databaseId,
        childCollectionId,
        childId,
      )) as AppwriteDocument;
    } catch (err) {
      error(`failed to read child ${childId}: ${stringifyError(err)}`);
      continue;
    }

    await db.createDocument(payload.$databaseId, 'outbox_events', ID.unique(), {
      kind: `databases.${payload.$databaseId}.collections.${childCollectionId}.documents.create`,
      databaseId: payload.$databaseId,
      collectionId: childCollectionId,
      documentId: child.$id,
      payload: JSON.stringify(child),
      correlationId: dedupeKey,
      status: 'pending',
      attempts: 0,
      lastError: null,
      enqueuedAt: new Date().toISOString(),
      deliveredAt: null,
    });

    const fanoutFunctionId = process.env.FANOUT_FUNCTION_ID;
    if (fanoutFunctionId) {
      try {
        await functions.createExecution(
          fanoutFunctionId,
          JSON.stringify({
            event: `databases.${payload.$databaseId}.collections.${childCollectionId}.documents.${child.$id}.create`,
            source: 'relationshipEventFanout',
            child,
            parentId: parent.$id,
          }),
          true, // async
        );
      } catch (err) {
        error(`fanout function execution failed: ${stringifyError(err)}`);
      }
    }

    const webhookUrl = process.env.FANOUT_WEBHOOK_URL;
    if (webhookUrl) {
      try {
        const response = await fetch(webhookUrl, {
          method: 'POST',
          headers: {
            'content-type': 'application/json',
            'x-correlation-id': dedupeKey,
            'x-source': 'relationshipEventFanout',
          },
          body: JSON.stringify({ parentId: parent.$id, child }),
        });
        if (!response.ok) {
          error(
            `webhook ${webhookUrl} responded with status ${response.status}`,
          );
        }
      } catch (err) {
        error(`webhook delivery failed: ${stringifyError(err)}`);
      }
    }
  }

  return res.json({ ok: true, fanned: childIds.length });
}

function normalizeRelated(value: unknown): string[] {
  if (Array.isArray(value)) {
    return value
      .map((v) => (typeof v === 'string' ? v : (v as { $id?: string })?.$id))
      .filter((v): v is string => !!v);
  }
  if (typeof value === 'string') return [value];
  if (typeof value === 'object' && value !== null) {
    const id = (value as { $id?: string }).$id;
    return id ? [id] : [];
  }
  return [];
}

function required(key: string): string {
  const value = process.env[key];
  if (!value) {
    throw new Error(`missing required env var ${key}`);
  }
  return value;
}

function stringifyError(err: unknown): string {
  if (err instanceof Error) return `${err.name}: ${err.message}`;
  return JSON.stringify(err);
}

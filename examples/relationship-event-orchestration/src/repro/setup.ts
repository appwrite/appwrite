import { Databases, Permission, RelationshipType, Role } from 'node-appwrite';
import { buildDatabases } from '../lib/appwrite.js';
import { config } from '../lib/config.js';
import { logger } from '../lib/logger.js';
import { ensureIdempotencyCollection } from '../lib/idempotency.js';
import { ensureOutboxCollection } from '../lib/outbox.js';

async function ensureDatabase(db: Databases): Promise<void> {
  try {
    await db.get(config.databaseId);
    logger.info({ db: config.databaseId }, 'database exists');
  } catch (error) {
    if ((error as { code?: number }).code !== 404) throw error;
    await db.create(config.databaseId, 'App', true);
    logger.info({ db: config.databaseId }, 'database created');
  }
}

async function ensureCollection(
  db: Databases,
  id: string,
  name: string,
): Promise<void> {
  try {
    await db.getCollection(config.databaseId, id);
    logger.info({ id }, 'collection exists');
    return;
  } catch (error) {
    if ((error as { code?: number }).code !== 404) throw error;
  }
  await db.createCollection(
    config.databaseId,
    id,
    name,
    [
      Permission.read(Role.any()),
      Permission.create(Role.any()),
      Permission.update(Role.any()),
      Permission.delete(Role.any()),
    ],
    true,
  );
  logger.info({ id }, 'collection created');
}

async function ensureAttribute(
  fn: () => Promise<unknown>,
  label: string,
): Promise<void> {
  try {
    await fn();
    logger.info({ label }, 'attribute created');
  } catch (error) {
    const code = (error as { code?: number }).code;
    if (code === 409) {
      logger.info({ label }, 'attribute already exists');
      return;
    }
    throw error;
  }
}

async function waitForAttributesAvailable(
  db: Databases,
  collectionId: string,
  keys: string[],
): Promise<void> {
  const deadline = Date.now() + 30_000;
  while (Date.now() < deadline) {
    const list = await db.listAttributes(config.databaseId, collectionId);
    const byKey = new Map(list.attributes.map((a: any) => [a.key, a.status]));
    const allReady = keys.every((k) => byKey.get(k) === 'available');
    if (allReady) return;
    await new Promise((r) => setTimeout(r, 750));
  }
  throw new Error(
    `attributes ${keys.join(', ')} did not become available in 30s`,
  );
}

async function main(): Promise<void> {
  const db = buildDatabases();
  await ensureDatabase(db);

  // Child collection (B)
  await ensureCollection(db, config.collectionB, 'Children (B)');
  await ensureAttribute(
    () =>
      db.createStringAttribute(
        config.databaseId,
        config.collectionB,
        'title',
        256,
        true,
      ),
    'B.title',
  );
  await ensureAttribute(
    () =>
      db.createStringAttribute(
        config.databaseId,
        config.collectionB,
        'priority',
        32,
        false,
      ),
    'B.priority',
  );
  await waitForAttributesAvailable(db, config.collectionB, ['title', 'priority']);

  // Parent collection (A)
  await ensureCollection(db, config.collectionA, 'Parents (A)');
  await ensureAttribute(
    () =>
      db.createStringAttribute(
        config.databaseId,
        config.collectionA,
        'name',
        256,
        true,
      ),
    'A.name',
  );
  await ensureAttribute(
    () =>
      db.createStringAttribute(
        config.databaseId,
        config.collectionA,
        'status',
        32,
        false,
      ),
    'A.status',
  );
  await waitForAttributesAvailable(db, config.collectionA, ['name', 'status']);

  // Relationship A -> B (one-to-many)
  await ensureAttribute(
    () =>
      db.createRelationshipAttribute(
        config.databaseId,
        config.collectionA,
        config.collectionB,
        RelationshipType.OneToMany,
        true,
        'tasks',
        'parent',
        'cascade',
      ),
    'A.tasks (relationship)',
  );

  await ensureIdempotencyCollection(db);
  await ensureOutboxCollection(db);

  logger.info('setup complete');
}

main().catch((error) => {
  logger.error({ err: error }, 'setup failed');
  process.exit(1);
});

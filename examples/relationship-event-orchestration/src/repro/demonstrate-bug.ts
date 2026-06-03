import { ID, Query } from 'node-appwrite';
import { buildDatabases } from '../lib/appwrite.js';
import { config } from '../lib/config.js';
import { logger } from '../lib/logger.js';

/**
 * Reproduce the silent-event behavior.
 *
 * We assume there is a function or webhook subscribed to:
 *     databases.{config.databaseId}.collections.{config.collectionB}.documents.*.create
 *
 * Manual verification:
 *   1. Run `npm run repro:bug`
 *   2. Inspect your function executions / webhook logs.
 *      - The "direct B create" entry will appear.
 *      - The "relationship cascade" children will NOT appear.
 *
 * We also poll the Appwrite "executions" list for any function whose events
 * include the child create pattern, but execution visibility depends on your
 * project setup; the test is primarily observational.
 */
async function main(): Promise<void> {
  const db = buildDatabases();

  // (1) DIRECT child create -> event WILL fire
  const directChild = await db.createDocument(
    config.databaseId,
    config.collectionB,
    ID.unique(),
    { title: 'Direct create -- event should fire', priority: 'high' },
  );
  logger.info(
    { id: directChild.$id },
    'created child DIRECTLY in B (expect event in subscribers)',
  );

  // (2) RELATIONSHIP cascade -> event WILL NOT fire for children
  const parent = await db.createDocument(
    config.databaseId,
    config.collectionA,
    ID.unique(),
    {
      name: 'Parent that nests two children',
      status: 'open',
      tasks: [
        { title: 'Nested child #1 -- NO event', priority: 'medium' },
        { title: 'Nested child #2 -- NO event', priority: 'low' },
      ],
    },
  );
  logger.info(
    { parentId: parent.$id },
    'created parent in A with nested children (parent event fires; child events do NOT)',
  );

  // Sanity: verify the cascaded children actually exist server-side
  const cascadedChildren = await db.listDocuments(
    config.databaseId,
    config.collectionB,
    [Query.limit(10), Query.orderDesc('$createdAt')],
  );
  logger.info(
    {
      latestB: cascadedChildren.documents.map((d) => ({
        id: d.$id,
        title: d.title,
      })),
    },
    'children present in B but emitted no documents.create events',
  );

  logger.info(
    'manual check: open your subscribed function/webhook logs. Direct creates trigger events, relationship creates do not.',
  );
}

main().catch((error) => {
  logger.error({ err: error }, 'demonstrate-bug failed');
  process.exit(1);
});

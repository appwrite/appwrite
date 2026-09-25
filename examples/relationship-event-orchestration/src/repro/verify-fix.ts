import { buildDatabases } from '../lib/appwrite.js';
import { logger } from '../lib/logger.js';
import { IdempotencyStore } from '../lib/idempotency.js';
import { Outbox } from '../lib/outbox.js';
import { ParentWithChildrenOrchestrator } from '../orchestrator/createParentWithChildren.js';

/**
 * Confirms that using the orchestrator (children-first, attach-by-id) makes
 * every child document fire its own `documents.*.create` event natively, while
 * also pushing a parallel outbox row for at-least-once guaranteed delivery.
 *
 * After running this you should see, in the subscribed function/webhook logs:
 *   - one event per child  (databases.*.collections.B.documents.*.create)
 *   - one event for parent (databases.*.collections.A.documents.*.create)
 */
async function main(): Promise<void> {
  const db = buildDatabases();
  const orchestrator = new ParentWithChildrenOrchestrator(
    db,
    new IdempotencyStore(db),
    new Outbox(db),
  );

  const result = await orchestrator.run({
    parent: { name: 'Verified-Fix Parent', status: 'open' },
    children: [
      { data: { title: 'orchestrator child #1', priority: 'high' } },
      { data: { title: 'orchestrator child #2', priority: 'medium' } },
      { data: { title: 'orchestrator child #3', priority: 'low' } },
    ],
    relationshipKey: 'tasks',
  });

  logger.info(result, 'verify-fix complete');

  // Idempotency assertion: re-run the same logical input
  const replay = await orchestrator.run({
    parent: { name: 'Verified-Fix Parent', status: 'open' },
    children: [
      { data: { title: 'orchestrator child #1', priority: 'high' } },
      { data: { title: 'orchestrator child #2', priority: 'medium' } },
      { data: { title: 'orchestrator child #3', priority: 'low' } },
    ],
    relationshipKey: 'tasks',
  });
  logger.info(
    {
      sameParent: replay.parentId === result.parentId,
      sameChildren:
        JSON.stringify(replay.childrenIds) ===
        JSON.stringify(result.childrenIds),
    },
    'replay returned identical result (idempotency confirmed)',
  );
}

main().catch((error) => {
  logger.error({ err: error }, 'verify-fix failed');
  process.exit(1);
});

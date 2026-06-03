import { buildDatabases } from '../lib/appwrite.js';
import { logger } from '../lib/logger.js';
import { IdempotencyStore } from '../lib/idempotency.js';
import { Outbox } from '../lib/outbox.js';
import { ParentWithChildrenOrchestrator } from './createParentWithChildren.js';

async function main(): Promise<void> {
  const db = buildDatabases();
  const orchestrator = new ParentWithChildrenOrchestrator(
    db,
    new IdempotencyStore(db),
    new Outbox(db),
  );

  const result = await orchestrator.run({
    parent: { name: 'Sprint 42', status: 'open' },
    children: [
      { data: { title: 'Write the RFC', priority: 'high' } },
      { data: { title: 'Land the patch', priority: 'medium' } },
      { data: { title: 'Update docs', priority: 'low' } },
    ],
    relationshipKey: 'tasks',
  });

  logger.info(result, 'orchestrator demo: complete');
}

main().catch((error) => {
  logger.error({ err: error }, 'orchestrator demo: failed');
  process.exit(1);
});

# Database schema queue

All database schema jobs use `v1-database`, or `_APP_DATABASE_QUEUE_NAME` when
configured on both producers and consumers. The publisher no longer selects a
queue from a project's database DSN. Explicit per-call queue overrides still work.

The CE worker runs eight handlers concurrently by default. A standalone worker
can override that with `_APP_WORKER_MAX_COROUTINES`. Each action acquires process
mutexes for the resolved project-metadata and product-data backing hosts, in
sorted order, and holds them through schema and metadata changes. This preserves
shard-wide exclusion for shared tables and relationships while allowing work on
independent backings to overlap. Unknown hostnames share one conservative lock.

Run **one worker process and one active database-worker deployment**. Mutexes are
process-local; multiple processes are rejected, and a deployment must use Recreate
rather than overlap old and new pods. This is not distributed locking.

Before upgrading, stop schema-job producers and drain their old DSN-derived
queues (including failed/retried and in-flight work). Then stop the old consumers,
upgrade producers and start the single shared-queue consumer. Alternatively,
upgrade producers first while keeping the shared consumer stopped: new jobs wait
on `v1-database` while the old workers drain. Do not run the new consumer alongside
legacy consumers. Retain unresolved old failed/dead messages for explicit recovery;
an empty pending list alone is not proof that a queue is drained.

The same rule applies on rollback: stop producers and drain shared-queue work
before switching routing back. No automatic queue-name migration occurs.

`tests/e2e/Services/Databases/Legacy/WorkerConcurrencyTest.php` exercises real
MySQL schema changes and metadata under coroutine contention and injected failure.
It runs in the MariaDB E2E matrix, or locally with `_APP_DDL_TEST_DSN`,
`_APP_DDL_TEST_USER` and `_APP_DDL_TEST_PASSWORD` against an isolated MySQL server.

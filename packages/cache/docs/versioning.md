# Opaque versions (breaking change)

Numeric generation leases and `Feature\Leasable` are removed. Require the new cache major release together with the matching database release; do not deploy this over old database consumers that call `getGeneration()`.

`Feature\Versioned` provides atomic `loadVersioned()` snapshots and `compareAndSwap()`. CAS returns the exact new version it committed, or `false` on a conflicting version. Unavailability throws; it is never an empty record or a CAS conflict. Versions are opaque, fresh identities after eviction, not counters. `getLease()` / `saveWithLease()` use the same identities without a compatibility prefix.

Redis, Redis multiplexing and in-process Memory support this capability. Wrappers forward it. Other adapters retain ordinary cache operations but cannot perform conditional fills; database consumers must bypass coordinated caching on those adapters. Open circuit breakers reject versioned operations without calling the backend; failed probes count toward the breaker and throw.

These tokens reject stale fills; they do not elect a single database-fetch winner. Writer registrations and their lifecycle belong to the database protocol.

Drain legacy writers, clear disposable cached data, deploy matching libraries everywhere, then resume. Rollback requires the same drain/clear cutover. No additional state service is required. A successful post-commit invalidation is still necessary; durable replay of lost invalidations is separate work.

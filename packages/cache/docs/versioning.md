# Opaque versions (breaking change)

The ordinary cache API uses opaque versions; `Feature\Leasable` is removed. Require the new cache major release together with the matching database release; do not deploy this over old database consumers that call `getGeneration()`.

`Feature\Versioned` provides atomic `loadVersioned()` snapshots and `compareAndSwap()`. CAS returns the exact new version it committed, or `false` on a conflicting version. Unavailability throws; it is never an empty record or a CAS conflict. Versions are opaque, fresh identities after eviction, not counters. `getLease()` / `saveWithLease()` use the same identities without a compatibility prefix.

Redis, Redis multiplexing and in-process Memory support this capability. Wrappers forward it. Other adapters retain ordinary cache operations but cannot perform conditional fills; database consumers must bypass coordinated caching on those adapters. Open circuit breakers reject versioned operations without calling the backend; failed probes count toward the breaker and throw.

These tokens reject stale fills; they do not elect a single database-fetch winner. Writer registrations and their lifecycle belong to the database protocol.

Cache 6.0/database 9.0 require a coordinated drain/clear upgrade. Cache 6.1 adds an explicit temporary `Cache::legacy()` facade for the database 9.1 rolling bridge instead. That facade preserves the cache-5 Redis wire format and numeric leases solely for migration; ordinary cache operations never fall back to it. It uses the same adapter, pools and circuit breaker. No opaque fields are written into legacy hashes. Roll the database bridge through legacy reads + dual invalidation, v2 reads + dual invalidation, then v2 only; verify each fleet/in-flight-write barrier before advancing. Keep v2 keys isolated and preserve their namespace during eventual bridge removal. See the database migration documentation for rollback limits. No additional state service is required. A successful post-commit invalidation is still necessary; durable replay of lost invalidations is separate work.

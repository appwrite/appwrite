# Opaque versions (breaking change)

The ordinary cache API uses opaque versions; `Feature\Leasable` is removed. Require the new cache major release together with the matching database release; do not deploy this over old database consumers that call `getGeneration()`.

`Feature\Versioned` provides atomic `loadVersioned()` snapshots and `compareAndSwap()`. CAS returns the exact new version it committed, or `false` on a conflicting version. Unavailability throws; it is never an empty record or a CAS conflict. Versions are opaque, fresh identities after eviction, not counters. `getLease()` / `saveWithLease()` use the same identities without a compatibility prefix.

Redis, Redis multiplexing and in-process Memory support this capability. Wrappers forward it. Other adapters retain ordinary cache operations but cannot perform conditional fills; database consumers must bypass coordinated caching on those adapters. Open circuit breakers reject versioned operations without calling the backend; failed probes count toward the breaker and throw.

These tokens reject stale fills; they do not elect a single database-fetch winner. Writer registrations and their lifecycle belong to the database protocol.

The temporary legacy numeric-generation facade and Redis scripts are removed. Applications completing the database 9.1 bridge migration must keep their existing key namespace: `$cache->withNamespace('v2:')` shares data, versions and invalidations with bridge clients already in v2 mode. Pass that same view to every database/query-cache user. Purge recordings retain logical keys for semantic replay; the namespace is applied once by the receiving cache. `flush()` remains a whole-backend operation.

Remove migration flags only after the v2-only code has reached the complete fleet, including ad-hoc Jobs and draining processes. Never return to legacy readers after contraction. No cache flush or additional state service is required. A successful post-commit invalidation remains necessary; durable replay of lost invalidations is separate work.

---
layout: post
title: "Announcing list response caching: Instant reads with TTL-based caching"
description: Cache list query responses in memory with a single parameter. Set a TTL, skip the database round-trip on repeated reads, and purge on demand when freshness matters.
date: 2026-04-17
cover: /images/blog/announcing-list-cache-ttl/cover.avif
timeToRead: 4
author: jake-barnby
category: announcement
featured: false
callToAction: true
faqs:
  - question: "How do I cache a list query response in Appwrite?"
    answer: "Pass a `ttl` parameter (in seconds) to your `listRows` call. The first request runs normally and stores the response in an in-memory cache. Every subsequent identical request returns the cached response until the TTL expires. See the [Appwrite Databases docs](/docs/products/databases) for full SDK examples."
  - question: "What counts as an identical request for caching purposes?"
    answer: "Cache lookups key on the database, table, queries, and other request parameters together. Two requests must match on all of those to hit the same cache entry. Changing a query, limit, offset, or auth context produces a different key and triggers a fresh database fetch."
  - question: "When should I use TTL-based caching versus building my own cache?"
    answer: "Use the built-in TTL cache for read-heavy endpoints where the data changes infrequently (leaderboards, product listings, dashboards, reference tables). Build your own cache only when you need richer invalidation, multi-region replication, or coordination across services that [Appwrite](/docs/products/databases) doesn't cover."
  - question: "How long should I set the TTL?"
    answer: "Pick a TTL that matches how stale the data can be. A dashboard showing aggregated stats might tolerate 60 seconds, while a product catalog could comfortably cache for several minutes. Shorter TTLs cut staleness but reduce cache hit rate, so test against your traffic to find the right balance."
  - question: "Does the cache invalidate automatically when data changes?"
    answer: "No, TTL-based caching is time-based, not invalidation-based. The cached response stays in memory until the TTL expires regardless of writes to the underlying rows. If you need fresher data right after a known write, use a shorter TTL or skip the `ttl` parameter for that read."
  - question: "Is the cache shared across regions or per-instance?"
    answer: "The cache is in-memory on Appwrite's serving layer, so behavior may vary by region and instance. For high cache hit rates, ensure your clients consistently send the same query parameters and target the same region. Treat TTL caching as a best-effort acceleration, not a strict shared cache."
---

Read-heavy workloads hit the same queries over and over. Leaderboards, product listings, dashboard feeds, and reference tables all follow the same pattern: the data changes infrequently, but the reads never stop. Every request still runs a full database query, even when the result hasn't changed since the last call.

Until now, the only option was to build your own caching layer on top of Appwrite. That meant extra infrastructure, invalidation logic, and another moving part to maintain.

Today, we are introducing **TTL-based list response caching** for Appwrite Databases. Pass a `ttl` parameter to any list endpoint, and Appwrite caches the response in memory. Repeated identical requests return the cached result instantly, without touching the database, until the TTL expires.

# How it works

Add the `ttl` parameter (in seconds) to any `listRows` call. The first request executes normally and stores the result in an in-memory cache. Every subsequent identical request returns the cached response until the TTL expires.

{% multicode %}
```client-web
const rows = await tablesDB.listRows({
    databaseId: '<DATABASE_ID>',
    tableId: '<TABLE_ID>',
    queries: [
        Query.equal('status', 'active'),
        Query.limit(25)
    ],
    ttl: 60 // Cache for 60 seconds
});
```
```server-nodejs
const rows = await tablesDB.listRows({
    databaseId: '<DATABASE_ID>',
    tableId: '<TABLE_ID>',
    queries: [
        sdk.Query.equal('status', 'active'),
        sdk.Query.limit(25)
    ],
    ttl: 60 // Cache for 60 seconds
});
```
```server-python
rows = tables_db.list_rows(
    database_id='<DATABASE_ID>',
    table_id='<TABLE_ID>',
    queries=[
        Query.equal('status', 'active'),
        Query.limit(25)
    ],
    ttl=60  # Cache for 60 seconds
)
```
```server-ruby
rows = tables_db.list_rows(
    database_id: '<DATABASE_ID>',
    table_id: '<TABLE_ID>',
    queries: [
        Appwrite::Query.equal('status', 'active'),
        Appwrite::Query.limit(25)
    ],
    ttl: 60  # Cache for 60 seconds
)
```
```server-deno
const rows = await tablesDB.listRows({
    databaseId: '<DATABASE_ID>',
    tableId: '<TABLE_ID>',
    queries: [
        Query.equal('status', 'active'),
        Query.limit(25)
    ],
    ttl: 60 // Cache for 60 seconds
});
```
```server-php
$rows = $tablesDB->listRows(
    databaseId: '<DATABASE_ID>',
    tableId: '<TABLE_ID>',
    queries: [
        Query::equal('status', ['active']),
        Query::limit(25)
    ],
    ttl: 60 // Cache for 60 seconds
);
```
```server-go
rows, err := tablesDB.ListRows(
    "<DATABASE_ID>",
    "<TABLE_ID>",
    tablesDB.WithListRowsQueries([]string{
        query.Equal("status", []interface{}{"active"}),
        query.Limit(25),
    }),
    tablesDB.WithListRowsTtl(60), // Cache for 60 seconds
)
```
```server-swift
let rows = try await tablesDB.listRows(
    databaseId: "<DATABASE_ID>",
    tableId: "<TABLE_ID>",
    queries: [
        Query.equal("status", value: "active"),
        Query.limit(25)
    ],
    ttl: 60 // Cache for 60 seconds
)
```
```server-kotlin
val rows = tablesDB.listRows(
    databaseId = "<DATABASE_ID>",
    tableId = "<TABLE_ID>",
    queries = listOf(
        Query.equal("status", "active"),
        Query.limit(25)
    ),
    ttl = 60 // Cache for 60 seconds
)
```
```server-rust
let rows = tables_db.list_rows(
    "<DATABASE_ID>",
    "<TABLE_ID>",
    Some(vec![
        "equal(\"status\", [\"active\"])".to_string(),
        "limit(25)".to_string(),
    ]),
    None,     // transaction_id
    None,     // total
    Some(60), // ttl - Cache for 60 seconds
).await?;
```
```server-dotnet
RowList rows = await tablesDB.ListRows(
    databaseId: "<DATABASE_ID>",
    tableId: "<TABLE_ID>",
    queries: new List<string> {
        Query.Equal("status", new List<object> { "active" }),
        Query.Limit(25)
    },
    ttl: 60 // Cache for 60 seconds
);
```
```server-dart
RowList rows = await tablesDB.listRows(
    databaseId: '<DATABASE_ID>',
    tableId: '<TABLE_ID>',
    queries: [
        Query.equal('status', 'active'),
        Query.limit(25)
    ],
    ttl: 60, // Cache for 60 seconds
);
```
```server-java
tablesDB.listRows(
    "<DATABASE_ID>",
    "<TABLE_ID>",
    List.of(
        Query.equal("status", List.of("active")),
        Query.limit(25)
    ),
    null, // transactionId
    null, // total
    60, // ttl - Cache for 60 seconds
    new CoroutineCallback<>((result, error) -> {
        if (error != null) {
            error.printStackTrace();
            return;
        }
        System.out.println(result);
    })
);
```
```client-flutter
final rows = await tablesDB.listRows(
    databaseId: '<DATABASE_ID>',
    tableId: '<TABLE_ID>',
    queries: [
        Query.equal('status', 'active'),
        Query.limit(25)
    ],
    ttl: 60, // Cache for 60 seconds
);
```
```client-apple
let rows = try await tablesDB.listRows(
    databaseId: "<DATABASE_ID>",
    tableId: "<TABLE_ID>",
    queries: [
        Query.equal("status", value: "active"),
        Query.limit(25)
    ],
    ttl: 60 // Cache for 60 seconds
)
```
```client-android-kotlin
val rows = tablesDB.listRows(
    databaseId = "<DATABASE_ID>",
    tableId = "<TABLE_ID>",
    queries = listOf(
        Query.equal("status", "active"),
        Query.limit(25)
    ),
    ttl = 60 // Cache for 60 seconds
)
```
{% /multicode %}

Set `ttl` between `1` and `86400` (24 hours). The default is `0`, which means caching is disabled. The response includes an `X-Appwrite-Cache` header with value `hit` or `miss`, so you always know whether a response was served from cache.

# Permission-aware by design

Caching does not compromise security. Each cached entry is scoped to the caller's authorization roles, so users with different permissions always receive their own results. There is no risk of one user seeing another user's data through the cache.

# Built for stale-tolerant reads

This feature is designed for workloads where slightly stale data is acceptable. Row writes do **not** automatically invalidate the cache. If you update a row, cached responses will continue to serve the previous result until the TTL expires.

This is a deliberate trade-off. Automatic invalidation on every write would eliminate most of the performance benefit. Instead, you choose the TTL that fits your use case:

- **Short TTLs (5 to 30 seconds)** for feeds and dashboards where near-real-time matters
- **Medium TTLs (60 to 300 seconds)** for product listings, search results, and leaderboards
- **Long TTLs (3600+ seconds)** for reference data, configuration tables, and rarely changing content

Schema changes (adding or removing columns and indexes) invalidate cached entries automatically, so structural updates always take effect immediately.

# Purge on demand

When you need fresh data immediately, you can purge the cache manually by calling `updateTable` with `purge` set to `true`.

{% multicode %}
```server-nodejs
await tablesDB.updateTable({
    databaseId: '<DATABASE_ID>',
    tableId: '<TABLE_ID>',
    purge: true
});
```
```server-python
tables_db.update_table(
    database_id='<DATABASE_ID>',
    table_id='<TABLE_ID>',
    purge=True
)
```
```server-ruby
tables_db.update_table(
    database_id: '<DATABASE_ID>',
    table_id: '<TABLE_ID>',
    purge: true
)
```
```server-deno
await tablesDB.updateTable({
    databaseId: '<DATABASE_ID>',
    tableId: '<TABLE_ID>',
    purge: true
});
```
```server-php
$tablesDB->updateTable(
    databaseId: '<DATABASE_ID>',
    tableId: '<TABLE_ID>',
    purge: true
);
```
```server-go
tablesDB.UpdateTable(
    "<DATABASE_ID>",
    "<TABLE_ID>",
    tablesDB.WithUpdateTablePurge(true),
)
```
```server-swift
let _ = try await tablesDB.updateTable(
    databaseId: "<DATABASE_ID>",
    tableId: "<TABLE_ID>",
    purge: true
)
```
```server-kotlin
tablesDB.updateTable(
    databaseId = "<DATABASE_ID>",
    tableId = "<TABLE_ID>",
    purge = true
)
```
```server-rust
tables_db.update_table(
    "<DATABASE_ID>",
    "<TABLE_ID>",
    None,       // name
    None,       // permissions
    None,       // row_security
    None,       // enabled
    Some(true), // purge
).await?;
```
```server-dotnet
await tablesDB.UpdateTable(
    databaseId: "<DATABASE_ID>",
    tableId: "<TABLE_ID>",
    purge: true
);
```
```server-dart
await tablesDB.updateTable(
    databaseId: '<DATABASE_ID>',
    tableId: '<TABLE_ID>',
    purge: true,
);
```
```server-java
tablesDB.updateTable(
    "<DATABASE_ID>",
    "<TABLE_ID>",
    null, // name
    null, // permissions
    null, // rowSecurity
    null, // enabled
    true, // purge
    new CoroutineCallback<>((result, error) -> {
        if (error != null) {
            error.printStackTrace();
            return;
        }
        System.out.println(result);
    })
);
```
{% /multicode %}

This clears all cached list responses for that table in a single operation.

# Available now

List response caching is available today on Appwrite Cloud.

# More resources

- [Rows: Cache list responses](/docs/products/databases/rows#cache-list-responses)
- [Pagination: Cache list responses](/docs/products/databases/pagination#cache-list-responses)
- [Announcing Full Schema Creation: Provision complete tables in one atomic call](/blog/post/announcing-full-schema-creation)

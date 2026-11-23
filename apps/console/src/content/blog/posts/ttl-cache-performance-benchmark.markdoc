---
layout: post
title: "Benchmarking TTL list caching: a 100,000-request look at Appwrite at scale"
description: We ran 100,000 listRows calls per phase against a 10,000-row table, with and without TTL caching, to measure the latency gains. The results were more decisive than we expected.
date: 2026-04-21
cover: /images/blog/ttl-cache-performance-benchmark/cover.avif
timeToRead: 8
author: atharva
category: performance
featured: false
faqs:
  - question: "What is TTL caching in Appwrite Databases?"
    answer: "TTL (time to live) caching lets you cache the result of a listRows call for a fixed number of seconds. You pass a ttl parameter to the existing listRows call, and Appwrite serves identical repeat requests from memory until the TTL expires. The underlying query, endpoint, and SDK do not change. See the [Appwrite Databases docs](/docs/products/databases) for details."
  - question: "How much faster are cached reads in the benchmark?"
    answer: "Across 100,000 sequential listRows calls against a 10,000 row table, the cached phase served requests dramatically faster than the uncached phase, with the largest gains at the tail of the latency distribution (P95 and P99). The full breakdown and an interactive visualisation are at ttl-benchmark.appwrite.network."
  - question: "When should I avoid using TTL caching?"
    answer: "Avoid TTL caching for queries where staleness is unacceptable, for example, displaying a user's account balance or current cart contents. It works best for read heavy, slow changing data such as product listings, category pages, leaderboards, or content feeds."
  - question: "How is the cache invalidated?"
    answer: "Invalidation is purely time based. Once the TTL window expires, the next request repopulates the cache from the database. There is no write triggered invalidation, so pick a TTL that matches how stale the data can safely be."
  - question: "Does caching change the response shape or query syntax?"
    answer: "No. The only difference is the ttl parameter on listRows. Your query, filters, sort, pagination, and the returned row structure stay identical, which makes it safe to roll out behind a feature flag."
  - question: "Where is the benchmark source code?"
    answer: "The full benchmark, including the seed script, query definitions, and run harness, is open source at github.com/appwrite-community/ttl-benchmark. You can reproduce the numbers on your own Appwrite instance."
---

Last week we [introduced TTL-based list caching](/blog/post/announcing-list-cache-ttl) for Appwrite Databases. The announcement covered what the feature does and how to use it. This post is the follow-up: we put the cache under sustained load, measured it, and then broke the numbers down so you can decide whether the feature is worth wiring into your own read paths.

Instead of a synthetic micro-benchmark, we ran a realistic workload: a **10,000 row** product catalog, a filtered listing query with sort and pagination, and **100,000 sequential requests per phase**. The full benchmark is open source and lives at [appwrite-community/ttl-benchmark](https://github.com/appwrite-community/ttl-benchmark), so you can reproduce the numbers on your own instance. Every measurement below comes from that run.

{% info title="Explore the results interactively" %}
We also built an interactive visualisation of this run at [ttl-benchmark.appwrite.network](https://ttl-benchmark.appwrite.network). Pin percentiles, watch the live race simulator, and play with the scale slider while you read.
{% /info %}

# The workload

The benchmark lives in a standalone script that we wrote alongside this post. It has two phases and does nothing fancy between them:

1. **Phase 1: no cache.** **100,000** calls to `listRows` with `ttl: 0`.
2. **Phase 2: TTL cache.** **100,000** calls to the exact same query with `ttl: 300`.

Both phases run against the same Appwrite instance, the same table, and the same network path. The only variable is the `ttl` parameter.

## Schema

The seed script provisions a `products` table with fifteen columns covering the shapes you would expect in a product catalog: identifiers, categorical fields, numeric ranges, contact data, timestamps, and tag arrays.

| column | type | notes |
| --- | --- | --- |
| `name` | text | Brand + adjective + noun |
| `description` | text | One-paragraph marketing copy |
| `sku` | text | Unique identifier |
| `category` | enum | 8 retail categories |
| `brand` | text | One of 16 seeded brands |
| `price` | float | 5.00 to 500.00 |
| `stock` | integer | 0 to 500 |
| `inStock` | boolean | derived from stock |
| `rating` | float | 2.0 to 5.0 |
| `reviewCount` | integer | 0 to 8000 |
| `manufacturerEmail` | email | support contact |
| `manufacturerUrl` | url | product page |
| `releasedAt` | datetime | up to 4 years ago |
| `warehouseIp` | ip | IPv4 origin |
| `tags` | text (array) | up to 4 tags per row |

Most field values are derived from a seeded PRNG keyed on the row index, so two runs on different machines produce the same distribution of names, prices, categories, and ratings. Two fields intentionally drift between runs: row IDs (generated fresh by `ID.unique()`) and `releasedAt` (anchored to the current wall clock). Neither affects the query path we are measuring.

## The query

The cached endpoint we exercise is a typical product listing: filter by category, threshold on rating, sort by popularity, and paginate.

```javascript
const QUERY = [
    Query.equal('category', 'electronics'),
    Query.greaterThan('rating', 3.5),
    Query.orderDesc('reviewCount'),
    Query.limit(25)
];
```

This shape matters. It combines a categorical filter, a numeric threshold, a sort, and a limit, which is exactly the kind of query that benefits most from caching, because repeated identical requests are cheap to serve from memory but expensive to plan and execute against the database.

# Enabling TTL caching

Turning TTL caching on is a single parameter on the existing `listRows` call. The SDK, endpoint, and query remain unchanged, and invalidation is handled server-side by the TTL window.

{% multicode %}
```server-nodejs
const rows = await tablesDB.listRows({
    databaseId: '<DATABASE_ID>',
    tableId: '<TABLE_ID>',
    queries: [
        Query.equal('category', 'electronics'),
        Query.greaterThan('rating', 3.5),
        Query.orderDesc('reviewCount'),
        Query.limit(25)
    ],
    ttl: 300
});
```
```server-python
rows = tables_db.list_rows(
    database_id='<DATABASE_ID>',
    table_id='<TABLE_ID>',
    queries=[
        Query.equal('category', 'electronics'),
        Query.greater_than('rating', 3.5),
        Query.order_desc('reviewCount'),
        Query.limit(25)
    ],
    ttl=300
)
```
```server-php
$rows = $tablesDB->listRows(
    databaseId: '<DATABASE_ID>',
    tableId: '<TABLE_ID>',
    queries: [
        Query::equal('category', ['electronics']),
        Query::greaterThan('rating', 3.5),
        Query::orderDesc('reviewCount'),
        Query::limit(25)
    ],
    ttl: 300
);
```
{% /multicode %}

The first request executes a normal query and stores the result in memory. Every identical follow-up request served inside the TTL window returns the cached payload. Each response carries an `X-Appwrite-Cache` header of either `hit` or `miss`, so you can verify the cache is doing what you expect in production traffic.

# The measurement harness

We want answers to three questions:

- How much faster does the average request get?
- What happens to the tail, especially p95 and p99?
- How much time do you save across a read-heavy session?

The harness is deliberately boring. One connection, sequential calls, `performance.now()` around each request, and a sorted array at the end for the percentiles. Running sequentially gives clean per-request timing without the noise that concurrent pipelining introduces.

```javascript
async function runPhase({ ttl, iterations }) {
    const samples = new Float64Array(iterations);
    const startedAt = Date.now();
    for (let i = 0; i < iterations; i++) {
        const t0 = performance.now();
        await db.listRows({
            databaseId: DATABASE_ID,
            tableId: TABLE_ID,
            queries: QUERY,
            ...(ttl ? { ttl } : {})
        });
        samples[i] = performance.now() - t0;
    }
    return { samples, wall: Date.now() - startedAt };
}
```

After both phases finish, the script writes a markdown report to `results/` with frontmatter that captures every parameter of the run. The same report is the source of the numbers you are about to read.

# Results

Here is the final output from the benchmark, run against a locally hosted Appwrite instance with the TTL feature enabled:

![Benchmark output in the terminal](/images/blog/ttl-cache-performance-benchmark/benchmark-output.avif)

And the same data in a table, for readers who prefer it that way:

| metric | no cache | ttl cache |
| --- | ---: | ---: |
| total wall | 22m 43.4s | 10m 44.5s |
| avg / req | 13.626 ms | 6.440 ms |
| min | 10.783 ms | 4.146 ms |
| p50 | 13.187 ms | 6.108 ms |
| p90 | 15.173 ms | 7.862 ms |
| p95 | 16.450 ms | 8.966 ms |
| p99 | 21.303 ms | 12.527 ms |
| max | 118.957 ms | 75.173 ms |
| req / sec | 73 | 155 |

# Reading the numbers

The headline is simple: average latency dropped from **13.626 ms** to **6.440 ms**, a **2.12x speedup** and a **52.7% reduction**. But averages hide interesting detail, so it is worth looking at the rest of the distribution.

## Throughput doubles

The no-cache phase sustained **73 requests per second** on a single connection. The cached phase sustained **155**. That ratio is exactly what the latency numbers predict, and it means a read-heavy endpoint can absorb **roughly twice the traffic** on the same Appwrite instance, with no client-side changes beyond the `ttl` parameter.

## The tail compresses

Averages and medians improve a lot. The tail improves too, but not by the same multiplier.

- p95: 16.450 ms to 8.966 ms, a **1.83x speedup**.
- p99: 21.303 ms to 12.527 ms, a **1.70x speedup**.

This is expected. The cache removes query planning, execution, and permission evaluation from the hot path, which are the dominant cost for the average call. What remains in the tail is network, TLS, and the occasional GC pause, none of which caching can remove.

## Minimums reveal the floor

The fastest cached response came in at **4.146 ms**. That is the practical lower bound on this workload: network round trip, TLS handshake reuse, JSON decode on the client, and a memory read on the server.

## Wall clock is the number your users feel

The no-cache phase took **22 minutes 43 seconds** to complete 100,000 requests. The cached phase took **10 minutes 44 seconds**. The difference, **11 minutes 58 seconds**, is time Appwrite did not spend executing the same query a hundred thousand times.

For a dashboard that polls a leaderboard every few seconds across a few thousand concurrent users, that difference translates directly into lower latency for every reader and a noticeably snappier feel on the client side.

# Caveats worth stating

No benchmark is free of context, and this one has three worth calling out.

1. **The cache hits on identical queries only.** Change the category, the limit, or the sort direction and you are in cache-miss territory until the new key warms. In production, bucket your queries so that a small number of keys cover the hot paths.
2. **Writes do not invalidate the cache.** That is deliberate: automatic invalidation on every row write would eliminate most of the performance benefit. Pick a TTL that matches your tolerance for stale data, or call `updateTable` with `purge: true` when you need a forced refresh.
3. **Local and cloud will differ.** These numbers come from a local instance. Cloud tenants will see different absolute values because of network path and cross-region effects, but the shape of the curve (average cuts roughly in half, tail compresses a bit less) holds up consistently in our testing.

# Purging the cache on demand

When you know the underlying data has changed and stale responses are not acceptable, you can clear all cached list responses for a table in a single call:

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
```server-php
$tablesDB->updateTable(
    databaseId: '<DATABASE_ID>',
    tableId: '<TABLE_ID>',
    purge: true
);
```
{% /multicode %}

This is the right escape hatch after a bulk import, a moderator action on a product listing, or any other event where your application knows a table changed and wants subsequent reads to reflect that immediately.

# When the feature pays off

Based on this run and the workloads we have instrumented since the feature shipped, TTL caching is a clear win when three conditions hold:

- The same query shape fires more than a handful of times per TTL window.
- Stale responses within the window are acceptable, or the window is short enough that staleness is bounded.
- The query is non-trivial, meaning it has filters, sorting, or a large result set. Trivial queries against small tables are already fast and see smaller gains.

The catalog listing in this benchmark satisfies all three. So do leaderboards, dashboard feeds, reference tables, configuration stores, and most public product pages.

# Try it yourself

The full benchmark, including the seeder, the product generator, and the markdown report writer, runs with a single command once you set your endpoint, project id, and API key. Point it at any Appwrite instance that has TTL caching enabled and you will get your own numbers in under forty minutes.

```bash
node setup.js   # provisions the database, table, and 10k rows
node bench.js   # runs both phases and writes results/<timestamp>.md
```

If you want to explore further:

- [Interactive benchmark dashboard](https://ttl-benchmark.appwrite.network)
- [appwrite-community/ttl-benchmark on GitHub](https://github.com/appwrite-community/ttl-benchmark)
- [Announcing list response caching: Instant reads with TTL-based caching](/blog/post/announcing-list-cache-ttl)
- [Rows: Cache list responses](/docs/products/databases/rows#cache-list-responses)
- [Pagination: Cache list responses](/docs/products/databases/pagination#cache-list-responses)

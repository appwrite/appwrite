---
layout: post
title: "Database relationships are out of beta"
description: After a year of performance overhauls, opt-in loading, and full query support, database relationships in Appwrite are graduating from experimental to production-ready.
date: 2026-05-12
cover: /images/blog/relationships-are-out-of-beta/cover.avif
timeToRead: 5
author: jake-barnby
category: announcement
featured: true
faqs:
  - question: "What does 'production-ready' mean for database relationships in Appwrite?"
    answer: "It means the API and behavior are stable, performance is fast enough for real workloads, and the feature set is complete. Column types, directionality, on-delete behaviors, nested creation, query selection, and dot-notation filter queries are all locked in. We'll evolve database relationships, but we won't break them."
  - question: "Do I need to update my code or SDK to benefit from this?"
    answer: "No. If you're already using relationships, you don't need to do anything. The 12-18x performance improvements apply automatically, and existing queries continue to work. Opt-in relationship loading is fully backward compatible, so older SDK versions retain their previous behavior."
  - question: "Can I filter rows based on related row data?"
    answer: "Yes. You can use filter queries directly on relationship columns using dot notation, like `Query.equal('author.name', ['Jake'])`. All comparison operators are supported, including equal, notEqual, greaterThan, lessThan, between, contains, and spatial queries."
  - question: "Are there any remaining limitations?"
    answer: "Relationships are restricted to a maximum nesting depth of three levels. Relationship column key, type, and directionality cannot be updated after creation, only the on-delete behavior can be changed. These limits keep query performance predictable."
  - question: "Is this available on self-hosted Appwrite?"
    answer: "Yes. Database relationships are production-ready on both Appwrite Cloud and self-hosted installations. The performance gains, query support, and stability guarantees apply to both."
---

Database relationships have been part of Appwrite for a while, but they shipped with an "experimental" label and a real set of rough edges. Payloads got bloated, queries couldn't reach across related rows, and performance wasn't where it needed to be for production workloads.

Over the past year, we've fixed all of that. Today, we're dropping the experimental label.

**Database relationships in Appwrite are now production-ready.** The API is stable, performance is fast, and the feature set is complete.

# What changed over the past year

This isn't a single release, it's the accumulation of a year of work. Here's what landed along the way.

## Opt-in relationship loading

The first major shift came in August 2025 with [opt-in relationship loading](/blog/post/announcing-opt-in-relationship-loading). Previously, querying a row pulled in every related row automatically, which often meant fetching data you didn't need and shipping bloated JSON payloads back to your app.

We flipped the default. Now, rows return only their own fields unless you explicitly request related data through query selection. The result: smaller payloads, less bandwidth, faster responses, and no more accidental N+1 surprises.

## Filter queries across relationships

For a long time, the answer to "how do I find all posts by a specific author?" was "fetch everything and filter in your app." That changed in February 2026 with [relationship queries](/blog/post/announcing-relationship-queries).

You can now filter directly against relationship columns:

```js
// Get all posts where the author's name is 'Jake'
await tablesDB.listRows({
    databaseId: 'blog',
    tableId: 'posts',
    queries: [
        Query.equal('author.name', ['Jake'])
    ],
});
```

Every comparison operator works on relationship fields: `equal`, `notEqual`, `greaterThan`, `lessThan`, `between`, `contains`, and the full set of spatial queries. Filtering happens in the database, not in your application layer.

## 12-18x faster relationship performance

Alongside [query support](/blog/post/announcing-relationship-queries), we rewrote the internals of how relationships are resolved. Reads, writes, and joins across related tables are now **12-18x faster** across the board. There's nothing to configure, no flag to flip. If you're using relationships today, they're already faster.

That performance work is what made it safe to drop the experimental label. Relationships are now fast enough to be the default way you model connected data.

## CSV export with relationship support

The Console now [exports relationship fields cleanly as IDs](/blog/post/announcing-csv-export) when you download a table as CSV. Small thing, but it means relationships don't break your export pipelines or reporting workflows anymore.

# What "production-ready" actually means

When we called relationships experimental, we were telling you two things: the API might change, and the performance might not hold up under real load. Both of those caveats are gone.

- **The API is stable.** Column types, directionality, on-delete behaviors, nested creation, dot-notation queries, and query selection are all locked in. We'll evolve them, but we won't break them.
- **Performance holds up under load.** With the 12-18x improvements and opt-in loading, relationships handle real workloads without the payload bloat or latency footguns that originally earned the experimental label.
- **The feature set is complete.** All four relationship types, both directionalities, all three on-delete behaviors, full query support, and full permission inheritance. You can model what you need to model.

# What this unlocks

With queryable, fast, stable relationships, you can confidently build:

- **Normalized data models** without the duplication anomalies that plague flat schemas.
- **Filtered views across tables**, like "all articles by authors in a specific country" or "all orders containing a product in a category."
- **Search with relational context** that runs in the database instead of your app.
- **Dashboards and reports** that aggregate across related tables in a single query.
- **Snappier UIs** for any view that loads multiple levels of related data.

# Availability

Relationships are production-ready on both **Appwrite Cloud** and **self-hosted**. If you're already using relationships, you don't need to do anything, the performance and stability gains have been rolling out to you all along.

# More resources

- [Read the relationships documentation](/docs/products/databases/relationships)
- [Announcing relationship queries: Filter across related data with ease](/blog/post/announcing-relationship-queries)
- [Announcing opt-in relationship loading](/blog/post/announcing-opt-in-relationship-loading)
- [Simplify your data management with relationships](/blog/post/simplify-your-data-management-with-relationships)
- [Learn about queries in Appwrite Databases](/docs/products/databases/queries)

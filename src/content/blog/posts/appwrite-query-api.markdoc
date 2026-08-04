---
layout: post
title: "Appwrite's Query API: filtering, sorting, and pagination"
description: Learn how to use Appwrite's Query API to filter, sort, and paginate database results efficiently, including offset and cursor pagination.
date: 2026-03-25
lastUpdated: 2026-06-29
cover: /images/blog/appwrite-query-api/cover.avif
timeToRead: 5
author: aditya-oberai
category: product, tutorial
featured: false
unlisted: true
faqs:
  - question: "What is the Appwrite Query API?"
    answer: "The Query API is a set of helper methods on the Query class that you pass as an array to listRows or equivalent calls. Each method generates a filter, sort directive, or pagination instruction. Multiple queries are combined with AND logic, while passing several values to a single method behaves like an OR across those values."
  - question: "Which filter operators does Query support?"
    answer: "Query supports equal, notEqual, greaterThan, lessThan, greaterThanEqual, lessThanEqual, contains, search, isNull, isNotNull, startsWith, endsWith, and between. The search operator requires a fulltext index on the target column. Numeric and date comparisons work directly on indexed columns for best performance."
  - question: "How do I sort results in Appwrite?"
    answer: "Use Query.orderAsc(field) and Query.orderDesc(field), and chain multiple ordering calls to break ties on additional fields. Sorting works on small datasets without an index, but large tables need a key index on any column you frequently sort on to avoid slow scans."
  - question: "What is the difference between offset and cursor pagination?"
    answer: "Offset pagination uses Query.limit and Query.offset together and is straightforward for jump-to-page UIs, but it gets slow on deep pages and can skip or repeat rows when data changes between requests. Cursor pagination uses Query.cursorAfter or Query.cursorBefore with a stable row ID, scales well to large datasets, and is the right choice for infinite scroll or feed-style experiences."
  - question: "What is the default and maximum limit for listRows?"
    answer: "If you omit Query.limit, Appwrite returns 25 rows by default. The maximum value you can pass to Query.limit is 5000, after which you must paginate to fetch more rows."
---

Every app that reads data from a database eventually needs to answer the same three questions: which rows do I want, in what order, and how many at a time. Appwrite's Query API is the answer to all three.

The [Query class](/docs/products/databases/queries) gives you a set of methods you pass as an array to any `listRows` (or equivalent) call. Each method generates a filter, sort directive, or pagination instruction. Multiple queries are combined with AND logic, so every condition must match. If you need OR logic, pass multiple values to the same method instead.

# Filtering rows

The most common filters are equality checks. `Query.equal('status', ['active'])` returns only rows where `status` is `active`. To match several values at once, pass them all in the array: `Query.equal('status', ['active', 'pending'])`. That single call behaves like an OR across those values, while still being ANDed with any other queries in your array.

Beyond equality, you have:

- `Query.notEqual('status', ['banned'])`: excludes specific values
- `Query.greaterThan('year', 1999)`: numeric or date comparisons
- `Query.lessThan('price', 50)`
- `Query.greaterThanEqual('score', 100)`
- `Query.lessThanEqual('age', 65)`
- `Query.contains('tags', ['javascript'])`: checks if an array column contains a value
- `Query.search('body', 'open source')`: full-text search (requires a fulltext index on the column)
- `Query.isNull('deletedAt')` / `Query.isNotNull('deletedAt')`
- `Query.startsWith('username', 'admin')` / `Query.endsWith('email', '.edu')`
- `Query.between('price', 10, 50)`: inclusive range check

A practical example combining several filters:

```js
import { Client, TablesDB, Query } from "appwrite";

const client = new Client()
  .setEndpoint("https://cloud.appwrite.io/v1")
  .setProject("<PROJECT_ID>");

const tablesDB = new TablesDB(client);

const result = await tablesDB.listRows({
  databaseId: "<DATABASE_ID>",
  tableId: "<TABLE_ID>",
  queries: [
    Query.equal("status", ["published"]),
    Query.greaterThan("year", 1999),
    Query.contains("genres", ["sci-fi"]),
  ],
});
```

All three conditions must be true for a row to appear in the result.

# Sorting results

Two methods control sort order: `Query.orderAsc('fieldName')` and `Query.orderDesc('fieldName')`. You can chain them to sort by multiple fields:

```js
const result = await tablesDB.listRows({
  databaseId: "<DATABASE_ID>",
  tableId: "<TABLE_ID>",
  queries: [
    Query.equal("status", ["published"]),
    Query.orderDesc("createdAt"),
    Query.orderAsc("title"),
  ],
});
```

This returns published rows sorted newest-first, with ties broken alphabetically by title. Sorting on a column without an index will work for small datasets but becomes slow as the table grows. Adding an index on columns you frequently sort by is covered in the [Appwrite Indexes guide](/docs/products/databases).

# Pagination: offset vs cursor

Appwrite supports two pagination strategies. Choose based on your use case.

## Offset pagination

Offset pagination uses `Query.limit(n)` and `Query.offset(n)` together:

```js
const page = 3;
const perPage = 25;

const result = await tablesDB.listRows({
  databaseId: "<DATABASE_ID>",
  tableId: "<TABLE_ID>",
  queries: [
    Query.limit(perPage),
    Query.offset(perPage * (page - 1)),
  ],
});
```

The default limit when you omit `Query.limit` is 25. The maximum is 5000.

Offset pagination is easy to reason about and supports jumping directly to any page. The downside: for large datasets, a high offset forces the database to scan and discard many rows before returning results. Page 1 is fast; page 5000 is not. It also has consistency issues if rows are inserted or deleted between requests, which can cause items to appear twice or be skipped.

Use offset pagination when:
- Your dataset is small to medium (under a few thousand rows after filtering)
- You need numbered pages or a "go to page N" feature
- Your data changes infrequently

## Cursor pagination

Cursor pagination uses `Query.cursorAfter(rowId)` or `Query.cursorBefore(rowId)` with a `Query.limit`:

```js
// First page
const firstPage = await tablesDB.listRows({
  databaseId: "<DATABASE_ID>",
  tableId: "<TABLE_ID>",
  queries: [Query.limit(25)],
});

// Get the last row ID from the first page
const lastId = firstPage.rows[firstPage.rows.length - 1].$id;

// Next page
const secondPage = await tablesDB.listRows({
  databaseId: "<DATABASE_ID>",
  tableId: "<TABLE_ID>",
  queries: [
    Query.limit(25),
    Query.cursorAfter(lastId),
  ],
});
```

For going backwards, pass the first row ID of the current page to `Query.cursorBefore`.

Cursor pagination is significantly more efficient for large datasets because the database uses the cursor row as an anchor rather than scanning from the beginning. Performance stays consistent regardless of how deep into the dataset you are. The tradeoff is that you cannot jump to an arbitrary page and must navigate sequentially.

Use cursor pagination when:
- Your dataset is large
- You are building an infinite scroll or "next page / previous page" UI
- You want consistent performance across all pages

## Mixing limit with filters and sort

`Query.limit` and pagination queries work alongside filter and sort queries. All queries in the same array apply together:

```js
const result = await tablesDB.listRows({
  databaseId: "<DATABASE_ID>",
  tableId: "<TABLE_ID>",
  queries: [
    Query.equal("status", ["published"]),
    Query.orderDesc("createdAt"),
    Query.limit(10),
    Query.cursorAfter(lastRowId),
  ],
});
```

# Performance tips

A few practical rules when working with the Query API:

- Add indexes on columns used in `Query.equal`, `Query.orderAsc`, and `Query.orderDesc`. Without an index, Appwrite performs a full table scan for every request.
- `Query.search` requires a fulltext index on the column. Calling it without one returns an error.
- Prefer cursor pagination for any list that could grow beyond a few hundred rows.
- Avoid combining a very high `Query.offset` with a wide `Query.limit`. This is the fastest way to make your database slow.
- Be selective with `Query.between` on unindexed columns. Index the column first if you use it frequently.

# Start building with Appwrite Databases

The Query API covers the full range of what you need for data retrieval: precise filtering, flexible sorting, and two pagination strategies suited to different scales. The same `Query` class works across Appwrite TablesDB APIs.

To go further:

- [Appwrite Databases queries reference](/docs/products/databases/queries)
- [Appwrite Databases pagination guide](/docs/products/databases/pagination)
- [Appwrite Databases overview](/docs/products/databases)
- [Start building on Appwrite Cloud](https://cloud.appwrite.io)

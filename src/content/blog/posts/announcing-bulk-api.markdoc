---
layout: post
title: "Announcing Bulk API: Handle heavy data workloads with ease"
description: Perform multiple database operations in a single API call. Enjoy faster writes and better performance for heavy server-side workloads.
date: 2025-07-03
lastUpdated: 2026-06-29
cover: /images/blog/announcing-bulk-api/cover.avif
timeToRead: 5
author: jake-barnby
category: announcement
featured: false
faqs:
  - question: "What is the Appwrite Bulk API?"
    answer: "The Bulk API lets you create, update, upsert, or delete many rows in a single request using `createRows`, `updateRows`, `upsertRows`, and `deleteRows`. It is much faster than sending one request per row and reduces network overhead for batch workloads."
  - question: "Why is the Bulk API restricted to server-side SDKs?"
    answer: "Bulk operations typically involve high-volume data changes that should be authorized by trusted code, not by end users. Restricting the API to server SDKs (which use API keys) prevents clients from issuing mass writes or deletes against your project."
  - question: "When should I use the Bulk API instead of single-row calls?"
    answer: "Reach for the Bulk API any time you are processing data in batches: importing CSV files, syncing from another database, applying changes from a scheduled job, or cleaning up stale records. For interactive UI writes (one row at a time), the regular methods are usually clearer."
  - question: "How does Appwrite handle partial failures in a bulk request?"
    answer: "Bulk operations are transactional at the row level, so individual row validations (such as permissions or schema mismatches) are reported alongside successes. Check the response to see which rows were processed and which failed, then retry only the failures."
  - question: "Are there limits on how many rows I can send in one bulk call?"
    answer: "Yes. There is a maximum batch size per request that depends on payload size and the underlying database engine. The [bulk operations documentation](/docs/products/databases/bulk-operations) lists the current limits and recommended batch sizes for large imports."
  - question: "Does Bulk API work with self-hosted Appwrite?"
    answer: "Yes. Bulk API is available on both Appwrite Cloud and self-hosted installations that run the supporting version. You call the same methods from your server SDK regardless of where Appwrite is hosted."
---

We're excited to introduce another Appwrite Databases feature, **Bulk API**. Explicitly designed to handle heavy write workloads, Bulk API dramatically improves performance by allowing multiple database operations in a single API call.

# Faster development with bulk actions

Previously, writing or modifying large amounts of data in Appwrite Databases required sending one request per row. This method was inefficient, slow, and resource-intensive, especially when dealing with thousands of records.

With the new Bulk API, you can create, update, or delete multiple rows in one go, vastly speeding up your workflows and reducing network overhead.

# Optimized for server-side workloads

Bulk API is crafted for server-side applications, particularly those with significant data demands. Whether you’re importing large datasets, performing batch updates, or cleaning out old records, Bulk API streamlines your workload, drastically cutting down the number of requests and enhancing your application's performance.

This will have immediate and impactful benefits such as:

- **Speed improvements**: Rapidly handle thousands of operations simultaneously.
- **Lower network overhead**: Significantly fewer API requests needed, making your application faster and more efficient.
- **Seamless integration**: Works effortlessly using Appwrite Cloud or hosting yourself.

{% info title="Please Note" %}
Bulk operations can only be performed via the server-side SDKs. The client-side SDKs do not support bulk operations.
{% /info %}

# How it works

Utilizing the Bulk API is straightforward. You can use it to:

- Create multiple rows in a single request using the `createRows` method
- Update multiple rows in a single request using the `updateRows` method
- Delete multiple rows in a single request using the `deleteRows` method
- Upsert multiple rows in a single request using the `upsertRows` method

Here is a code example for creating multiple rows in a single request:


```server-nodejs
const sdk = require('node-appwrite');

const client = new sdk.Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>')
    .setKey('<API_KEY>');

const tablesDB = new sdk.TablesDB(client);

const result = await tablesDB.createRows({
    databaseId: '<DATABASE_ID>',
    tableId: '<TABLE_ID>',
    rows: [
        {
            $id: sdk.ID.unique(),
            name: 'Row 1',
        },
        {
            $id: sdk.ID.unique(),
            name: 'Row 2',
        }
    ]
});
```


# Built for intensive data tasks

Bulk API was designed to move large volumes of data in and out of Appwrite. By simplifying and speeding up these tasks, Bulk API unlocks previously challenging use-cases, expanding what's possible with Appwrite.

With Bulk API, your high-performance data operations are faster, simpler, and more efficient.

# More resources
- [Read the documentation to get started](/docs/products/databases/bulk-operations)
- [Introducing Database Backups](/blog/post/introducing-database-backups)
- [More Database blogs](https://appwrite.io/blog?search=database)

---
layout: post
title: "Announcing Database Upsert: Simplify your database interactions"
description: A cleaner, faster, and atomic way to manage your rows in Appwrite.
date: 2025-07-08
lastUpdated: 2026-06-29
cover: /images/blog/announcing-database-upsert/cover.avif
timeToRead: 5
author: jake-barnby
category: announcement
featured: false
faqs:
  - question: "What is an upsert?"
    answer: "Upsert is a database operation that creates a row if it doesn't exist and updates it if it does. It removes the need to check for existence yourself or branch between create and update calls. The server handles both paths atomically in a single request."
  - question: "How do I use upsert in Appwrite?"
    answer: "Call `upsertRow` on the [Appwrite Databases](/docs/products/databases) SDK with a database ID, table ID, row ID, and the data you want to write. If the row with that ID exists, it's updated, otherwise it's created. This works the same across Cloud and self-hosted."
  - question: "When should I use upsert instead of create or update?"
    answer: "Use upsert when you don't know (or don't care) whether the row already exists, like syncing data from a mobile client, processing a background job, or ingesting events from IoT devices. It removes a round trip and a class of race conditions caused by checking existence before writing."
  - question: "Is upsert atomic in Appwrite?"
    answer: "Yes, [Appwrite's](/docs/products/databases) upsert is fully atomic: the existence check and the write happen in the same operation on the server. That means two clients calling upsert on the same row ID can't both succeed in creating it, avoiding the classic check-then-write race condition."
  - question: "Is upsert idempotent?"
    answer: "Calling upsert with the same row ID and payload produces the same final state regardless of how many times the request runs. That makes retries safe, which is useful for unreliable networks, background workers, or anywhere you want at-least-once delivery semantics without duplicate data."
  - question: "Can I upsert in bulk?"
    answer: "Single-row upsert is available via `upsertRow`. For larger workloads, look at [Appwrite's Bulk API](/docs/products/databases) and CSV imports, which let you write many rows in one operation. Pick the approach that matches the size and shape of your data."
---

Working with databases often involves small but repetitive decisions like checking if a row exists, choosing between creating or updating, handling errors that come from guessing wrong. These steps are not difficult on their own, but over time they add complexity to your code and friction to your workflow.

To simplify this, we introduce Database Upsert in Appwrite.

# How it works

Upsert allows you to create or update a row using a single API call. If the row does not exist, it is created. If it does, it is updated. You no longer need to write separate logic to check for existence or handle 404 responses. The server handles that for you.

This change removes the need for client-side conditionals, reduces the number of requests between your app and the database, and helps avoid potential race conditions. It is a small shift in how you interact with the database, but one that can make your code cleaner and your application logic easier to follow.

# Ideal for multi-client scenarios

Whether you synchronize mobile data, handle background worker processes, or collect data from IoT devices, Database Upsert ensures data consistency. Its fully atomic nature prevents race conditions, keeping your data accurate and consistent, no matter how many clients interact simultaneously.

This brings you immediate benefits such as:

- **Fewer network calls**: Combine GET, POST, and PATCH into a single, efficient API request.
- **Race-free writes**: Ensure atomic operations that prevent conflicts.
- **Cleaner, simpler code**: Reduce branching logic and improve readability.
- **Idempotency**: Safe and easy retries ensure consistent behaviour.

# How it works

Implementing Upsert is straightforward and intuitive:

```javascript
import { Client, TablesDB } from "appwrite";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>');

const tablesDB = new TablesDB(client);

const result = await tablesDB.upsertRow({
    databaseId: '<DATABASE_ID>',
    tableId: '<TABLE_ID>',
    rowId: '<ROW_ID>',
    data: {
        'status': 'succeeded',
        'amount': 4999,
        'currency': 'usd',
        'invoiceId': 'in_1Rd...',
        'checks': ['cvc', 'zip'],
    }
})
```

Database Upsert was developed to enhance developer productivity and satisfaction, providing a feature that matches or surpasses competitive solutions. Whether using Appwrite Cloud or a self-hosted setup, Database Upsert integrates smoothly into your development workflow.

This feature simplifies your database interactions, enhancing efficiency, reducing complexity, and empowering you to build faster.

# More resources

- [Read the documentation to get started](/docs/products/databases/rows)
- [Announcing Bulk API: Handle heavy data workloads with ease](/blog/post/announcing-bulk-api)
- [Build a personal CRM with SvelteKit and Appwrite Databases](/blog/post/build-personal-crm-sveltekit)
- [Announcing: CSV imports for Appwrite Databases](/blog/post/announcing-csv-imports)

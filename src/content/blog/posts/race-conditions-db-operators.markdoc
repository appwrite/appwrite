---
layout: post
title: Handle race conditions when running operations in Appwrite DB
description: Learn how race conditions can occur while running operations on your Appwrite database and how operators can help avoid them.
date: 2025-11-06
cover: /images/blog/race-conditions-db-operators/cover.avif
timeToRead: 5
author: atharva
category: tutorial
featured: false
faqs:
  - question: "What causes a race condition in a database update?"
    answer: "It happens when two or more requests read a value, modify it in application code, and write it back at almost the same time. Each request based its write on a stale read, so updates overwrite each other and changes are lost."
  - question: "How do DB operators in [Appwrite Databases](/docs/products/databases) prevent race conditions?"
    answer: "DB operators run atomically on the server. Instead of fetching a row, mutating it locally, and writing it back, you send an instruction like `Operator.increment(1)` directly, and Appwrite applies it without a window where another request can interfere."
  - question: "When should I use a DB operator instead of read-modify-write?"
    answer: "Use an operator for any update where the new value depends on the existing value, like counters, balances, scores, or list mutations. Read-modify-write is fine when the field is being replaced wholesale and concurrent writes are not expected."
  - question: "Do DB operators reduce bandwidth?"
    answer: "Yes. You skip the initial getRow call because the server already has the current value. On hot paths like analytics counters or rate limiting, that is one fewer round trip per update, which adds up at scale."
  - question: "Can I combine multiple operators in one update?"
    answer: "Yes. A single update can apply operators to several columns at once, and they all commit together. Combined with the operator's atomicity, this lets you safely update related fields without coordinating multiple writes."
---

A race condition is a special condition where events that are supposed to occur in sequence do not happen in sequence due to external factors, such as latency. These conditions are not ideal, as they could lead to outdated and incorrect data. Race conditions also exist in databases. If you're performing database operations through your app, it could get scary really quickly.

In this article, we will examine how race conditions can infiltrate your apps and how to prevent them using the new database operators in Appwrite.

# A look at race conditions

Let's take a look at an example of how race conditions can lead to outdated and invalid data. Concurrent requests can cause race conditions, where if many requests are initiated at a single point in time, some of them might fail to register because old values are fetched and operated upon multiple times due to concurrency.

Here's an example of how this would typically happen in an Appwrite database.

```jsx
// Get the counter value
const counter = await tablesdb.getRow({
  databaseId: "test-db",
  tableId: "counters",
  rowId: "first",
});

// Increment the counter
await tablesdb.updateRow({
  databaseId: "test-db",
  tableId: "counters",
  rowId: "first",
  data: {
    value: counter.value + 1,
  },
});
```

In the above code block, if multiple requests are sent simultaneously, there is a chance that the `counter` remains the same across multiple requests, missing an update or updating completely incorrectly. I have created a script to demonstrate this. If you want to check it out, I have published it on my [GitHub Repository](https://github.com/atharvadeosthale/db-operators-appwrite).

Running the script on a database with a row ID `first` and default `value` being 0, I got the following output.

```
======================================================================
🔴 Race Condition: Unsafe Read-Modify-Write
======================================================================

📥 Initial: 0
🚀 Running 25 concurrent increments...

──────────────────────────────────────────────────────────────────────
📈 Results
──────────────────────────────────────────────────────────────────────

✅ Expected: 25
📊 Actual:   1
💥 Lost:     24 updates
🔍 Race detected:                                                            
24 workers read value 0 simultaneously
======================================================================
```

As you can see, when 25 requests were fired simultaneously, only one request successfully registered the correct value in the database, while the others retained the old values. This can be disastrous in data-sensitive applications where every request must account towards a change in database values. 

# The solution

To combat this problem, we recently announced [a set of DB operators](/docs/products/databases/operators) that you can use, which would atomically operate on the server side, ensuring that operations in all requests are captured successfully.

Let's take a look at an example code snippet that shows an accurate representation of how you can increment the counter successfully at each request using DB operators.

```jsx
// Increment the counter
await tablesdb.updateRow({
  databaseId: "test-db",
  tableId: "counters",
  rowId: "first",
  data: {
    value: Operator.increment(1),
  },
});
```

As you can already tell from the above code snippet, we no longer need to fetch the value from the DB, which was the main cause of the race condition occurring. Instead, we pass in `Operator.increment(1)` as `value`. Removing the `getRow()` call also saves bandwidth in this case, and could be a substantial improvement if you do operations like these on scale.

This ensures the increment logic is performed atomically on the server, instead of the application layer. Now, let's run the script again, but with the DB operator in use.

```
======================================================================
🟢 Safe Increment: Using Atomic Operations
======================================================================

📥 Initial: 0
🚀 Running 25 concurrent increments...

──────────────────────────────────────────────────────────────────────
📈 Results
──────────────────────────────────────────────────────────────────────

✅ Expected: 25
📊 Actual:   25
💥 Lost:     0 updates

✅ No race condition: All increments processed atomically
======================================================================
```

As you can see, all the concurrent requests now register the increment, and the final `value` is 25, which can be double-checked in the database. Feel free to run this test yourself to verify the outcome.

# Wrapping up

While learning to code, developers often find handling increments or any such operations on the application layer much easier. However, these operations tend to break when race conditions are introduced on scale, just as we explored in this article. DB operators ensure that your operations don't break when your app reaches the masses.

**More resources**

- [Announcing DB operators: Update multiple fields without fetching the entire row](/blog/post/announcing-db-operators)
- [Announcing Atomic numeric operations: Safe, server-side increments and decrements](/blog/post/announcing-atomic-numeric-operations)
- [DB operators documentation](/docs/products/databases/operators)

---
layout: post
title: "Introducing Realtime queries: Server-side event filtering for subscriptions"
description: Pass SDK queries when subscribing to realtime channels to automatically filter events server-side, so your callbacks only receive the updates you care about.
date: 2026-02-16
cover: /images/blog/announcing-realtime-queries/cover.avif
timeToRead: 4
author: jake-barnby
category: announcement
featured: false
faqs:
  - question: "What are Realtime queries in Appwrite?"
    answer: "Realtime queries let you pass standard Appwrite SDK queries when subscribing to a channel. Events are filtered server-side based on those queries, so your callback only fires when the payload matches. This eliminates manual filtering inside subscription handlers."
  - question: "Which query operators work with Appwrite Realtime?"
    answer: "Realtime supports a focused subset of operators: comparison (`equal`, `notEqual`, `greaterThan`, `greaterThanEqual`, `lessThan`, `lessThanEqual`), null checks (`isNull`, `isNotNull`), and logical composition (`and`, `or`). These cover the most common patterns for filtering live updates."
  - question: "Why filter realtime events on the server instead of the client?"
    answer: "Server-side filtering reduces bandwidth and CPU on the client because unmatched events never cross the network. It also keeps your subscription logic declarative, so you reason about what should trigger a callback in one place rather than scattering `if` checks across handlers."
  - question: "Do Realtime queries replace Channel helpers?"
    answer: "No. Channel helpers describe which resources to subscribe to, while Realtime queries describe which payloads to deliver. They are complementary: use channel helpers to scope the subscription to a table or row pattern, then add queries to filter the matching events."
  - question: "Are Realtime queries available on all Appwrite client SDKs?"
    answer: "Yes. Realtime queries are supported on the Web, Flutter, Apple, and Android client SDKs. The API uses the same `Query` helper class you already use for database operations, so there is no new query syntax to learn."
  - question: "How do I subscribe to all changes for a specific user in a table?"
    answer: "Subscribe to the table's row channel and pass a query that filters by user, for example `Query.equal('userId', [currentUserId])`. The server only emits events on that subscription when the payload matches, so you get a focused stream without writing filter code in your callback."
---

If you've built realtime features with Appwrite, you've likely written filtering logic inside your subscription callbacks: checking payload fields, comparing values, and discarding events you don't need. While this works, it adds boilerplate to your client code and means you're still receiving and processing every event on the channel, even the ones you'll throw away.

To make realtime subscriptions more precise, Appwrite now supports **Realtime queries**: pass SDK queries when subscribing to automatically filter events server-side.

# Filter at the source, not in your callback

Realtime queries let you pass SDK queries as a parameter when subscribing to a channel. Events are filtered on the server based on your queries, so your callback only fires when the payload matches your conditions.

This means less client-side filtering logic, fewer unnecessary callback invocations, and a cleaner subscription model overall.

# How it works

Realtime queries use the same `Query` helpers you already use with Appwrite's database and other services. Pass an array of queries when subscribing, and only events matching those conditions will trigger your callback.

```javascript
import { Client, Realtime, Channel, Query } from "appwrite";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>');

const realtime = new Realtime(client);

// Subscribe to all updates on the channel
const allVotes = await realtime.subscribe(
    Channel.tablesdb('<DATABASE_ID>').table('<TABLE_ID>').row(),
    response => {
        console.log(response.payload);
    }
);

// Subscribe only to updates where person equals 'person1'
const person1Votes = await realtime.subscribe(
    Channel.tablesdb('<DATABASE_ID>').table('<TABLE_ID>').row(),
    response => {
        console.log(response.payload);
    },
    [Query.equal('person', ['person1'])]
);

// Subscribe only to updates where person is not 'person1'
const otherVotes = await realtime.subscribe(
    Channel.tablesdb('<DATABASE_ID>').table('<TABLE_ID>').row(),
    response => {
        console.log(response.payload);
    },
    [Query.notEqual('person', 'person1')]
);
```

Without queries, the first subscription receives every event on the channel. With queries, the second and third subscriptions only receive events where the payload matches the specified conditions. No manual filtering required.

# Supported queries

Realtime queries support a subset of the full SDK query methods, focused on value comparison and logical composition:

- **Comparison**: `Query.equal()`, `Query.notEqual()`, `Query.greaterThan()`, `Query.greaterThanEqual()`, `Query.lessThan()`, `Query.lessThanEqual()`
- **Null checks**: `Query.isNull()`, `Query.isNotNull()`
- **Logical**: `Query.and()`, `Query.or()`

These cover the most common filtering patterns for realtime events. You can combine multiple queries to build precise conditions for your subscriptions.

# Key benefits

- **Server-side filtering**: Events are filtered before reaching your client, reducing unnecessary processing
- **Consistent API**: Uses the same `Query` helpers from Appwrite's database APIs
- **Cleaner code**: Eliminate manual filtering logic inside subscription callbacks
- **Available across all platforms**: Supported in Web, Flutter, Apple, and Android client SDKs

# More resources

- [Read the Realtime queries documentation](/docs/apis/realtime#queries)
- [Learn about Realtime channel helpers](/docs/apis/realtime#channel-helpers)
- [View all available Realtime events](/docs/advanced/platform/events)

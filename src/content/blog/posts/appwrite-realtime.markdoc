---
layout: post
title: "How Appwrite Realtime works (and when to use it)"
description: Learn how Appwrite Realtime uses WebSockets to push live updates to clients, what channels are available, and when polling is a better fit.
date: 2026-03-23
cover: /images/blog/appwrite-realtime/cover.avif
timeToRead: 4
author: aditya-oberai
category: product, tutorial
featured: false
unlisted: true
faqs:
  - question: "How does Appwrite Realtime work under the hood?"
    answer: "Appwrite Realtime opens a single WebSocket connection per client and streams events for every channel that client subscribes to. When data changes in your project (a row is updated, a file is uploaded, a function execution completes), Appwrite emits an event and pushes it to every subscribed client over that connection."
  - question: "What channels can I subscribe to with Appwrite Realtime?"
    answer: "You can subscribe to channels for Databases (row-level changes), Storage (file events within buckets), Functions (execution status updates), Teams and memberships, and the authenticated user's account. Channels follow a hierarchical pattern, so you can subscribe broadly to all rows in a database or narrowly to a single row by ID."
  - question: "Does Realtime enforce permissions?"
    answer: "Yes. Permissions are enforced at the subscription level. A user only receives events for resources they have read access to. If a row update fires but the subscribed user does not have read permission on that row, the event is not delivered, so you do not need extra client-side filtering."
  - question: "When should I avoid using Realtime?"
    answer: "Realtime is not available for Server SDKs using API keys; for server-side reactions to events, use Appwrite Functions triggered by events instead. It is also overkill when updates are infrequent and a 30-second polling interval would suffice, when channels emit too many events for a client to keep up, or when you need offline-first sync semantics."
  - question: "How do Realtime events relate to Appwrite Functions?"
    answer: "Realtime events map to the same event system that triggers Appwrite Functions. The same event string (for example storage.*.files.*.create on a file upload) can fan out to subscribed clients via Realtime and to a Function via the event system simultaneously, which makes designing event-driven features predictable."
---

Most apps start with a simple request-response model. A user submits a form, the server responds, the page updates. That model works fine until users need to see changes made by someone else, in real time, without refreshing the page. At that point you have a choice: poll the server on a timer, or open a persistent connection and let the server push updates.

[Appwrite Realtime](/docs/apis/realtime) takes the second approach. It uses WebSocket connections to stream events from your Appwrite backend directly to subscribed clients, with latency measured in milliseconds rather than however often you remember to set a polling interval.

# How Appwrite Realtime works

When a client subscribes to a Realtime channel, Appwrite opens a single WebSocket connection for that client. All subscribed channel updates travel over that connection. When data changes in your Appwrite project (a row is updated, a file is uploaded, a function execution completes), Appwrite emits an event and pushes it to every client subscribed to the relevant channel.

Subscriptions look like this:

```js
import { Client, Realtime } from "appwrite";

const client = new Client()
    .setEndpoint("https://cloud.appwrite.io/v1")
    .setProject("<PROJECT_ID>");

const realtime = new Realtime(client);

const unsubscribe = realtime.subscribe("tablesdb.[DATABASE_ID].tables.[TABLE_ID].rows", (response) => {
    console.log(response.events, response.payload);
});

// To stop listening
unsubscribe();
```

The callback fires every time an event on the subscribed channel reaches the client. The `response` object includes the event type (such as `tablesdb.*.rows.*.create`) and the full payload of the changed resource.

Permissions are enforced at the subscription level. A user only receives events for resources they have permission to read. If a row update is pushed but the subscribed user does not have read access to that row, they do not receive the event. This means Realtime does not require you to write extra filtering logic on the client side.

# Channels you can subscribe to

Appwrite Realtime channels map directly to its services:

- **Databases:** row-level changes across any table
- **Storage:** file creation, updates, and deletions within buckets
- **Functions:** execution status updates
- **Teams and memberships:** membership changes
- **Account:** changes to the authenticated user's own account

Channels follow a hierarchical pattern. You can subscribe broadly (all rows in a database) or narrowly (a single row by ID). Subscribing to a broader channel means more events but also more filtering work on the client.

# When to use Realtime

- **Live chat and messaging.** Chat is the canonical Realtime use case. Each message is a new row in a table. Clients subscribe to that table and receive new messages as they are created, without any polling.

- **Collaborative editing and presence.** When multiple users edit the same resource, Realtime lets each client see changes from others as they happen. Presence indicators (who is online, who is typing) work the same way: write a presence row per user, subscribe to the presence table, and render the current state on every update.

- **Live dashboards and analytics.** Metrics that change frequently (order counts, active sessions, transaction totals) are a good fit for Realtime. Each update to the underlying row triggers an event that the dashboard subscribes to, keeping numbers current without polling.

- **Notifications.** Instead of polling a notifications table, clients subscribe to it. New notification rows appear in the client's UI immediately after they are created on the server.

- **Multiplayer game state.** Lightweight game state (player positions, scores, turn changes) can be synced via Realtime. This works well for turn-based games and simple real-time games where WebSocket latency is acceptable.

# When NOT to use Realtime

- **Server SDKs.** Appwrite Realtime is not available for Server SDKs using API keys. It is designed for client-side use. If you need to react to data changes on the server, use Appwrite Functions triggered by events instead. Those functions receive the same event payloads that Realtime delivers to clients.

- **Infrequent updates where polling is simpler.** If your data changes once every few minutes and you have only a handful of users, a 30-second polling interval is easier to implement, easier to debug, and carries no persistent connection overhead. Realtime shines when updates are frequent or the delay from polling is genuinely noticeable to users.

- **High-frequency writes where the client cannot keep up.** If a channel emits hundreds of events per second, the client may struggle to process them all. In those cases, consider batching writes on the server side or aggregating data before exposing it to clients.

- **Offline-first apps.** Realtime requires an active connection. If your app needs to work reliably offline and sync when reconnected, you need a dedicated offline sync strategy beyond what Realtime provides.

# Connection behavior and limits

Each call to `realtime.subscribe()` with a new channel modifies the WebSocket connection. Appwrite efficiently manages the underlying connection, but be aware that subscribing to many channels simultaneously keeps a persistent connection open. On mobile, this has battery and data implications if the subscription runs in the background without any updates.

The `unsubscribe()` function returned by `realtime.subscribe()` cleanly removes the listener and, if there are no remaining subscriptions, closes the WebSocket. Always call it when a component unmounts or a view navigates away to avoid memory leaks and unnecessary network usage.

# Realtime and Appwrite Events

Realtime events map directly to Appwrite's broader event system. The same events that trigger Appwrite Functions are the ones delivered over Realtime channels. This means you can use the events documentation to understand exactly which event strings correspond to which actions, and subscribe or trigger accordingly.

This symmetry is useful when designing event-driven features. A file upload triggers `storage.*.files.*.create`. You can subscribe to that event in a client via Realtime, trigger a Function via the event system, or both simultaneously.

# Build live features with Appwrite Realtime

Realtime removes the need to write and maintain polling loops, manage WebSocket servers, or handle reconnection logic yourself. The subscription API is a few lines of code, permissions are automatically enforced, and the connection infrastructure scales with Appwrite Cloud.

- [Appwrite Realtime docs](/docs/apis/realtime)
- [Appwrite Events reference](/docs/advanced/platform/events)
- [Sign up for Appwrite Cloud](https://cloud.appwrite.io)

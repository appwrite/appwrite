---
layout: post
title: "Announcing the Presences API: Track who is online, typing, and active in realtime"
description: A new Appwrite API for short-lived user statuses, with built-in Realtime channels, automatic expiry, and permission-aware subscriptions.
date: 2026-05-25
cover: /images/blog/announcing-presences-api/cover.avif
timeToRead: 5
author: aditya-oberai
category: announcement
featured: true
callToAction: true
faqs:
    - question: "What is the Appwrite Presences API?"
      answer: "Presences is a new Appwrite API for tracking short-lived user statuses, like online, away, editing, or typing. Each presence is a small record attached to a user, with a status string and optional metadata, and it broadcasts every change over a dedicated Realtime channel. It is built for online indicators, collaboration cursors, typing dots, and live attendee lists, the kind of UI signals that should disappear automatically when a user goes offline."
    - question: "How are Presences different from storing status in a database row?"
      answer: "A database row stays around until you delete it. Presence records expire automatically based on an expiresAt timestamp you control, so a stale online indicator never gets stuck after a user closes the tab or loses connection. The Presences API also ships with its own Realtime channels, so you do not need to write subscription logic or maintenance jobs on top of a regular table to get a live who-is-here view."
    - question: "How do you show who is online in a web or mobile app?"
      answer: "The cleanest pattern is to give each signed-in user a short-lived status record with an automatic expiry, then subscribe to a realtime channel so every client sees the same online list. The Appwrite Presences API handles this end to end. Clients upsert their own presence with a status like 'online' or 'away', the record disappears on its own if the tab closes or the connection drops, and subscribers receive upsert and delete events without any polling or cleanup job on the application side."
    - question: "How do typing indicators in a chat app work?"
      answer: "A typing indicator is a status that should appear the instant someone starts typing and disappear when they stop or step away, usually after a few seconds of inactivity. Building this on a regular database row means writing custom timers and a cleanup task. With the Appwrite Presences API, the client upserts a presence with status 'typing' on keystroke and clears it on send or blur, and other users in the conversation subscribe to that presence to render the dots in realtime."
    - question: "How long does a presence record live?"
      answer: "Every presence carries an expiresAt timestamp, up to 30 days in the future. Once that timestamp passes, Appwrite deletes the record and emits a delete event on the presence channels. The typical pattern is to upsert the same presence on a heartbeat (every few seconds, or on focus and route change events) so the expiry keeps sliding forward while the user is active."
    - question: "Who can read a presence record?"
      answer: "Presences use the same permissions system as the rest of Appwrite. Set Role.users() for any signed-in user, Role.team('TEAM_ID') for a single team, or Role.user('USER_ID') for a one-to-one channel. Realtime subscriptions honor these rules, so a client only receives updates for presences it could have fetched with a direct GET."
    - question: "Which SDKs support the Presences API?"
      answer: "Presences are exposed through every Appwrite SDK as a Presences service, alongside Account, TablesDB, Storage, and the rest. Client SDKs (Web, Flutter, Apple, Android, React Native) can upsert and subscribe to presence directly from the user's session, and server SDKs can manage presence with an API key that holds the presences.read and presences.write scopes."
    - question: "Do I need to run my own cleanup job for stale presences?"
      answer: "No. Appwrite runs a background worker that removes expired presences automatically and emits delete events, so stale online indicators disappear without any extra code on your side. You only need to call delete explicitly when you want a user to go offline immediately, for example on sign out."
---

Realtime apps almost always need to answer one question that has nothing to do with the data they store: **who is here right now?** Whether it is the green dot next to a teammate's avatar, the cursor on a shared document, or the "typing..." indicator under a chat input, that signal is short-lived, frequently updated, and supposed to disappear the moment a user closes the tab.

Building that with a database row works until it doesn't. A stale "online" flag survives a network drop. A presence table needs a cleanup job. A subscription has to know which row is whose. The shape of the data, with sub-second writes, second-scale TTLs, and permission-aware broadcasts, is just different from a row you mean to keep.

Today, we are announcing the **Appwrite Presences API**.

# What this gives you

Presences are a first-class Appwrite resource for short-lived user statuses, with the same SDK shape and permissions model as the rest of the platform.

- **Upsert-first writes** so you can call the same method on every focus, route change, or heartbeat without worrying about duplicates.
- **Automatic expiry** controlled by an `expiresAt` timestamp (up to 30 days). Stale records disappear on their own, no cleanup cron required.
- **Dedicated Realtime channels** (`presences` and `presences.<ID>`) that emit `upsert`, `update`, and `delete` events for every record a subscriber has permission to read.
- **Free-form status and metadata** so a presence can mean "online", "typing in #general", or "viewing document `abc123`", whichever vocabulary fits your app.
- **Permission-aware subscriptions** that reuse `Role.users()`, `Role.team()`, and `Role.user()`, so collaboration features only leak status to the right people.
- **A `Presences` service in every SDK**, with the matching scopes (`presences.read`, `presences.write`) on the server side.

# Setting a presence

Once a user signs in, upsert their presence. The first call creates the record, every subsequent call updates it in place.

{% multicode %}
```client-web
import { Client, Presences, ID } from "appwrite";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>');

const presences = new Presences(client);

const presence = await presences.upsert({
    presenceId: ID.unique(),
    status: 'online',
    metadata: { page: '/dashboard' }
});
```

```client-flutter
import 'package:appwrite/appwrite.dart';

final client = Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>');

final presences = Presences(client);

final presence = await presences.upsert(
    presenceId: ID.unique(),
    status: 'online',
    metadata: { 'page': '/dashboard' },
);
```

```client-apple
import Appwrite

let client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1")
    .setProject("<PROJECT_ID>")

let presences = Presences(client)

let presence = try await presences.upsert(
    presenceId: ID.unique(),
    status: "online",
    metadata: ["page": "/dashboard"]
)
```

```client-android-kotlin
import io.appwrite.Client
import io.appwrite.ID
import io.appwrite.services.Presences

val client = Client(context)
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1")
    .setProject("<PROJECT_ID>")

val presences = Presences(client)

val presence = presences.upsert(
    presenceId = ID.unique(),
    status = "online",
    metadata = mapOf("page" to "/dashboard")
)
```
{% /multicode %}

`userId` is filled in automatically from the session on client SDKs. On server SDKs (API key, JWT, Admin), pass `userId` explicitly. `presenceId` and `status` are both required; `permissions`, `expiresAt`, and `metadata` are optional, so the smallest possible call is just `{ presenceId, status }` on a fresh ID. Persist the returned `$id` (in local storage, a state store, or wherever your session lives) and reuse it on every subsequent `upsert` call so each user keeps a single record across heartbeats and route changes.

# Subscribing to presence updates

Render the initial "online now" list with `presences.list()`, then subscribe to the global presences channel to keep it in lockstep. The Realtime payload is identical in shape to every other Appwrite event, so the same handler patterns you already use for rows or files work here.

```client-web
import { Client, Presences, Realtime, Channel } from "appwrite";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>');

const presences = new Presences(client);
const realtime = new Realtime(client);

const result = await presences.list();

const onlineUsers = new Map(
    result.presences.map(presence => [presence.userId, presence])
);

await realtime.subscribe(Channel.presences(), response => {
    const presence = response.payload;
    if (response.events.includes('presences.*.delete')) {
        onlineUsers.delete(presence.userId);
    } else {
        onlineUsers.set(presence.userId, presence);
    }
});
```

The `delete` event fires both when you remove a presence explicitly and when it expires automatically, so a single handler can drive the "user just went offline" branch of your UI either way.

# Pair it with Realtime queries

Presence gets sharper when you combine it with [Realtime queries](/blog/post/announcing-realtime-queries), which let you pass SDK queries to `realtime.subscribe(...)` so events are filtered server-side. Instead of receiving every presence event on `Channel.presences()` and discarding the ones you do not care about in your callback, you subscribe with a query and only see the events that match.

```client-web
import { Client, Realtime, Channel, Query } from "appwrite";

const realtime = new Realtime(client);

// Only receive online players, filtered server-side.
await realtime.subscribe(
    Channel.presences(),
    response => {
        console.log(response.payload);
    },
    [Query.equal('status', ['online'])]
);
```

This is what makes the API a fit for the more demanding use cases on top of online indicators:

- **Multiplayer games.** Set a `status` per zone or party and subscribe with a matching query, so a client only receives presence updates for the players it actually needs to render on screen.
- **Live movement tracking.** In a collaborative editor or shared map, subscribe with a query keyed to the document or tile the user is on, so cursor positions from people elsewhere never reach the client.
- **Filtered "who is online" lists.** Subscribe with `Query.equal('status', ['online'])` and `away` records never trigger your handler.

**Realtime + Presences + Queries** together give you a low-bandwidth, server-filtered "who is here right now, doing what" stream that scales without per-client filtering logic.

# When to reach for Presences

Beyond the demanding scenarios above, Presences are the right primitive for any UI cue that should appear when a user is around and disappear when they are not:

- Online indicators in a team directory, contacts list, or member sidebar.
- Collaboration cursors that show which document or row a teammate is viewing.
- Typing indicators in chat, comment threads, or live forms.
- Live attendee lists for streams, classrooms, or shared dashboards.
- "Someone else is editing this" banners and soft locks on shared records.

For anything that should outlive the session, like a user's profile, preferences, or saved settings, stick with [account preferences](/docs/products/auth/preferences) or a row in your database. Presences are intentionally short-lived and self-cleaning, and they are at their best when you treat them that way.

# Get started with Presences

The Presences API is **available on Appwrite Cloud today**. You can start using it from the existing client SDKs with no extra setup; server-side use requires an API key with the `presences.read` and `presences.write` scopes.

# More resources

- [Realtime: Presences](/docs/apis/realtime/presences)
- [Authentication: Presences](/docs/products/auth/presences)
- [Realtime channels reference](/docs/apis/realtime/channels)
- [Permissions](/docs/advanced/platform/permissions)

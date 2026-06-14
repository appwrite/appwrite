---
layout: post
title: "Build a Snapchat clone with Presences and Realtime"
description: Build a Snapchat-style app with Appwrite's Presences API and Realtime, using TanStack Start, TablesDB, Functions, and Storage. Friends drop pins on a globe and send each other photos that expire in 24 hours.
date: 2026-05-27
cover: /images/blog/building-snapchat-clone-with-presences-and-realtime/cover.avif
timeToRead: 12
author: atharva
category: tutorial
faqs:
  - question: "What does the Appwrite Presences API store?"
    answer: "Each presence is a single row keyed by your user ID, with a status, an expiry, free-form metadata, and standard permissions. You upsert it, the server expires it after the time you set, and the row is broadcast on the realtime `presences` channel so other authenticated users see it the moment it lands."
  - question: "How does AppChat make snaps expire after 24 hours without scheduled jobs?"
    answer: "The 24-hour gate is enforced at read time. The `serve-snap` Appwrite Function compares the snap row's `$createdAt` against `now` and refuses to return the file bytes once the snap is older than 24 hours. Cleanup of expired rows and files can be handled by a separate scheduled function if you want long-term hygiene, but reads stop working immediately."
  - question: "Can the recipient open the file in Appwrite Storage directly?"
    answer: "Not as the recipient. The bucket uses file-level security, and each uploaded file grants Storage read access only to the sender. The snap row is readable by sender and recipient and contains the file ID, but recipient Storage reads are denied. The function is the recipient's delivery path because it checks the row permission, verifies the execution user is the recipient, and enforces the 24-hour age before returning bytes."
  - question: "Why route snap delivery through an Appwrite Function instead of a Storage URL?"
    answer: "Two reasons. First, you need a gate that checks both the recipient permission and the 24-hour age in one place. Second, Appwrite injects the execution user's JWT into the function, so the function can read the snap row as the caller, verify the caller is the recipient, and then use its scoped execution key to fetch the sender-restricted file."
  - question: "Do I need separate Appwrite projects for the site and the function?"
    answer: "No. AppChat ships both from one `appwrite.config.json`. The site is a `tanstack-start` Site with an SSR adapter, and the function is a Node-22 function with a `files.read` scope. A single `appwrite push` updates both."
  - question: "How does the heartbeat avoid race conditions on the user's own pin?"
    answer: "The heartbeat reads the latest pin from a React ref, and the map page treats its own presence record as locally authoritative. That lets the initial presence list and inbound realtime echoes merge cleanly while the user's pin update stays responsive."
---

The best social apps feel alive without asking the user to think about why. A friend opens the app and their pin moves. A photo lands in your inbox the moment they send it. A snap is there when you need it, then disappears when its time is up.

That is the feel AppChat is going for: a small Snapchat-style demo where friends can see each other on a globe, send photos, and open snaps that expire 24 hours after the sender hits send.

In this tutorial, you will build **AppChat** with [TanStack Start](https://tanstack.com/start) and Appwrite. Under the hood, friends drop pins on a globe via the **Presences API**, send photos through an **Appwrite Function** that enforces a 24-hour expiry, and get notified the moment a snap arrives via **Realtime channel helpers**. The friend graph lives in **TablesDB** and the photo bytes live in **Storage**.

You can try the deployed demo at [appchat-demo.appwrite.network](https://appchat-demo.appwrite.network).

{% info title="Source code" %}
The full source code lives in [`appwrite-community/appchat`](https://github.com/appwrite-community/appchat). The repository is the companion app for this post, including the Appwrite config, TanStack Start site, function, and demo seed script.
{% /info %}

# Prerequisites

- An [Appwrite Cloud](https://cloud.appwrite.io) account
- [Node.js](https://nodejs.org/) 20+ and [pnpm](https://pnpm.io) installed
- Basic familiarity with React and TanStack Start

# What you will build

AppChat has three pages: a globe, a friends tab, and a snap inbox. Every signed-in user owns one presence record that says where they are, one row for each accepted friendship, and one row for each snap they have sent or received in the last 24 hours.

The pieces fit together like this:

- **Live locations** sit in the Presences API as one presence record per user, broadcast on the `presences` channel
- **The friend graph** lives in two TablesDB tables, `friendRequests` and `friendships`
- **Snap metadata** is a row in the `snaps` table, permissioned to the sender and the recipient
- **Snap bytes** sit in a file-secured Storage bucket. The server uploads sender-readable files, and recipients receive bytes through the delivery function
- **Snap delivery** is an Appwrite Function that uses JWT delegation to enforce the 24-hour gate
- **Notifications** ride on Realtime channel helpers subscribed to TablesDB rows

Everything is configured in a single [`appwrite.config.json`](/docs/products/sites/deploy-from-cli) so that one config ships the database schema, bucket, function, and site together.

![Alice and Bob on the AppChat globe](/images/blog/building-snapchat-clone-with-presences-and-realtime/map-alice-pin.avif)

# Set up the Appwrite project

Create a new project in the Appwrite Console. Note the **project ID** and **API endpoint** from the overview page.

![The AppChat project right after creation](/images/blog/building-snapchat-clone-with-presences-and-realtime/project-overview.avif)

Add a **web platform** for `http://localhost:3000` so the client SDK can talk to the API from your dev server.

Create an **API key** with these scopes:

- `users.read`
- `users.write`
- `rows.read`
- `rows.write`
- `files.read`
- `files.write`
- `presences.write`

Save the secret as `APPWRITE_API_KEY` in three places:

- Your local `.env` file for development
- The Appwrite Site environment variables for SSR/admin server functions
- The `serve-snap` Function environment variables for protected snap delivery

Do not store this key in `appwrite.config.json`. It stays on the server and is used for cross-user writes, file uploads, session-cookie creation, and protected snap delivery.

# Create the database

The repository includes this schema in `appwrite.config.json`, so you can deploy it with the Appwrite CLI. The steps below show the manual setup in case you prefer creating the resources in the Console.

In the Console, navigate to **Databases** and create a database called **appchat-db** (ID: `appchat-db`). You will create three tables inside it.

## Snaps table

Create a table called **snaps** (ID: `snaps`) with the following columns:

| Column | Type | Required |
| --- | --- | --- |
| `senderId` | varchar(64) | Yes |
| `recipientId` | varchar(64) | Yes |
| `fileId` | varchar(64) | Yes |
| `viewedAt` | datetime | No |

Enable **row-level security** on this table. Each row will be permissioned to the sender and the recipient only, so even a permissive `listRows` query can never leak a snap to someone outside the conversation.

## friendRequests table

Create a table called **friendRequests** (ID: `friendRequests`) with the following columns:

| Column | Type | Required |
| --- | --- | --- |
| `fromUserId` | varchar(64) | Yes |
| `toUserId` | varchar(64) | Yes |
| `status` | varchar(16) | Yes |

The code writes `status` on every request, starting at `pending`, then the recipient flips it to `accepted` or `declined` via the friend page. Display names come from Auth users when the server renders requests, so this table only needs the relationship state.

## friendships table

Create a table called **friendships** (ID: `friendships`) with the following columns:

| Column | Type | Required |
| --- | --- | --- |
| `userA` | varchar(64) | Yes |
| `userB` | varchar(64) | Yes |

`userA` and `userB` are stored as a sorted pair, so a duplicate check is a single equality query rather than two-sided OR logic. The friends UI resolves display names from Auth users after reading these relation rows.

# Create the snaps bucket

Go to **Storage** and create a bucket called **snaps** (ID: `snaps`). Enable file-level security and allow image extensions: `jpg`, `jpeg`, `png`, `gif`, and `webp`. The app uploads files from a server function with the admin key and then sets `read("user:<senderId>")` on each file. Recipients do not get Storage read access to the file; they get bytes through the delivery function.

# Live locations with Presences

The [Presences API](/docs/products/auth/presences) is the simplest piece to explain. There is exactly one presence record per user, keyed by the user ID. Each record carries a status, an expiry, and a free-form metadata object. When the expiry passes, the record is gone. When the user updates it, every other authenticated client subscribed to the `presences` channel sees the new payload in realtime.

For AppChat, that means a presence record looks like this:

```ts
{
  $id: '<userId>',          // we upsert with presenceId == userId
  userId: '<userId>',
  status: 'online',
  expiresAt: '2026-05-26T08:58:37.787+00:00',
  metadata: {
    lat: -33.8688,
    lng: 151.2093,
    displayName: 'Bob',
    avatarUrl: 'http://localhost/v1/avatars/initials?name=Bob&project=appchat'
  }
}
```

Two things matter here. The metadata object is yours to shape, so the lat, lng, name, and avatar URL all travel inside the same record. The expiry is server-side, so the client keeps re-stamping the record on a heartbeat and the server clears stale pins shortly after the heartbeat stops.

The write side is one call. In AppChat, the heartbeat runs through a TanStack Start server function, so the admin client can set the right permissions. A brand-new presence created by a user does not automatically grant `update(user:<id>)` and `delete(user:<id>)` to that user:

```ts
import { Permission, Role, Presences } from 'node-appwrite';

export const upsertPresenceServer = createServerFn({ method: 'POST' })
    .inputValidator((data: { metadata: PresenceMetadata }) => data)
    .handler(async ({ data }) => {
        const { account } = createSessionClient();
        const user = await account.get();
        const { client: adminClient } = createAdminClient();
        const presences = new Presences(adminClient);
        const friendIds = await listFriendIdsForPresence(user.$id);
        await presences.upsert({
            presenceId: user.$id,
            userId: user.$id,
            status: 'online',
            metadata: data.metadata,
            expiresAt: new Date(Date.now() + 60 * 1000).toISOString(),
            permissions: [
                ...[user.$id, ...friendIds].map((id) => Permission.read(Role.user(id))),
                Permission.update(Role.user(user.$id)),
                Permission.delete(Role.user(user.$id))
            ]
        });
    });
```

On the client, the map keeps the presence stream friend-scoped:

- Read the user's accepted friends with `listMyFriends()`
- Read live presences from the Presences API
- Show only the current user and accepted friend IDs
- Treat the current user's presence record as locally authoritative after a pin move
- Merge initial presence data and realtime updates only for other users

That filtering mirrors the server permissions: each presence record is readable by the current user and accepted friends, while the map still keeps its own friend-ID filter as a UI guard.

![Alice's pin on the globe, expanded inline to reveal her name](/images/blog/building-snapchat-clone-with-presences-and-realtime/map-alice-hover.avif)

# Sending snaps without exposing the bucket

Snaps need three properties at once:

- They are sent between two specific users
- They expire after 24 hours regardless of whether the recipient opened them
- Recipient file reads pass through a code path you control

The way to do this in Appwrite is to put controlled server code on both sides of the file. The TanStack Start server function receives the selected image from the browser, uploads it with sender-only read permission, and stores the file ID on a snap row that is permissioned to the sender and recipient. The Appwrite Function checks both the row permission and the row age before returning bytes.

The browser wrapper converts the file into a server-function payload:

```ts
export async function sendSnap(recipientId: string, file: File): Promise<Snap> {
    const base64 = await fileToBase64(file);
    return sendSnapServer({
        data: {
            recipientId,
            fileName: file.name || 'snap',
            mimeType: file.type,
            base64
        }
    });
}
```

The upload and snap row creation happen together on the server:

```ts
const uploaded = await adminStorage.createFile({
    bucketId: appwrite.buckets.snaps,
    fileId: ID.unique(),
    file: InputFile.fromBuffer(bytes, data.fileName),
    permissions: [Permission.read(Role.user(me.$id))]
});

const row = await adminTables.createRow({
    databaseId: appwrite.databaseId,
    tableId: appwrite.tables.snaps,
    rowId: ID.unique(),
    data: {
        senderId: me.$id,
        recipientId: data.recipientId,
        fileId: uploaded.$id,
        viewedAt: null
    },
    permissions: [
        Permission.read(Role.user(me.$id)),
        Permission.read(Role.user(data.recipientId)),
        Permission.delete(Role.user(me.$id))
    ]
});
```

The delivery function is the interesting bit:

- It takes a snap ID in the URL
- The browser calls it with `Functions.createExecution`, using the user's active Appwrite session
- Appwrite injects `x-appwrite-user-jwt` and `x-appwrite-user-id` into the function request
- The function uses the injected JWT to read the snap row as the caller, so Appwrite performs the row permission check
- It also verifies that the execution user ID matches `snap.recipientId`
- If the caller is outside the row permissions, the row read returns 401 and the function refuses
- If the row is older than 24 hours, the function returns 410
- Otherwise it uses the scoped execution key to fetch the file bytes

```js
import { Client, TablesDB, Storage } from 'node-appwrite';

const SNAP_TTL_MS = 24 * 60 * 60 * 1000;

export default async ({ req, res, error }) => {
    const match = req.path.match(/^\/snap\/([A-Za-z0-9_-]+)\/?$/);
    if (!match || req.method !== 'GET') return res.json({ message: 'not found' }, 404);

    const snapId = match[1];
    const jwt = req.headers['x-appwrite-user-jwt'];
    if (!jwt) return res.json({ message: 'unauthorized' }, 401);

    const endpoint = process.env.APPWRITE_FUNCTION_API_ENDPOINT;
    const projectId = process.env.APPWRITE_FUNCTION_PROJECT_ID;
    const executionKey = req.headers['x-appwrite-key'];
    const apiKey = executionKey?.startsWith('ephemeral_') ? executionKey : process.env.APPWRITE_API_KEY;
    if (!apiKey) {
        error('APPWRITE_API_KEY env var is not set');
        return res.json({ message: 'internal error' }, 500);
    }

    const callerClient = new Client().setEndpoint(endpoint).setProject(projectId).setJWT(jwt);
    const callerId = process.env.APPWRITE_FUNCTION_USER_ID || req.headers['x-appwrite-user-id'];
    if (!callerId) return res.json({ message: 'unauthorized' }, 401);

    let snap;
    try {
        snap = await new TablesDB(callerClient).getRow({
            databaseId: 'appchat-db',
            tableId: 'snaps',
            rowId: snapId
        });
    } catch (e) {
        if (e.code === 401 || e.code === 404) {
            return res.json({ message: 'unauthorized' }, 401);
        }
        error(e.message);
        return res.json({ message: 'internal error' }, 500);
    }

    if (snap.recipientId !== callerId) return res.json({ message: 'unauthorized' }, 401);

    if (Date.now() - new Date(snap.$createdAt).getTime() > SNAP_TTL_MS) {
        return res.json({ message: 'snap expired' }, 410);
    }

    const adminClient = new Client().setEndpoint(endpoint).setProject(projectId).setKey(apiKey);
    const adminStorage = new Storage(adminClient);
    let file;
    let bytes;
    try {
        [file, bytes] = await Promise.all([
            adminStorage.getFile({ bucketId: 'snaps', fileId: snap.fileId }),
            adminStorage.getFileView({ bucketId: 'snaps', fileId: snap.fileId })
        ]);
    } catch (e) {
        error(e.message);
        return res.json({ message: 'internal error' }, 500);
    }

    return res.json({ contentType: file.mimeType, base64: Buffer.from(bytes).toString('base64') }, 200);
};
```

A few details that are easy to miss the first time:

- `serve-snap` declares the `files.read` scope, which lets the function fetch the protected file after the recipient check passes.
- The function returns JSON with the file MIME type and base64 bytes. The viewer turns that into a `data:` URL and renders the snap directly.
- After the image loads, a TanStack Start server function verifies the current user is the recipient and stamps `viewedAt`, so the inbox can show whether the snap is new or already opened.

The combined effect is that the recipient cannot reach the file by any path except this function, unauthorized callers are rejected by the row permission check, and the function refuses everyone once 24 hours have passed.

![Composer with a photo previewed before send](/images/blog/building-snapchat-clone-with-presences-and-realtime/app-send-preview.avif)

# Realtime inboxes and app-wide toasts

The snap row is what triggers the toast. The subscription lives in the `/app` layout, not the inbox page, so it stays active while the user is on the globe, friends page, composer, or inbox. The Appwrite Realtime [channel helpers](/docs/apis/realtime/channels) let you subscribe to a specific row scope without hand-rolling channel strings:

```ts
import { Channel } from 'appwrite';
import { realtime } from './client';

export function subscribeToMySnaps(userId: string, onNew: (snap: Snap) => void) {
    return realtime.subscribe<Snap>(
        Channel.tablesdb('appchat-db').table('snaps').row().create(),
        (msg) => {
            if (msg.payload.recipientId === userId) onNew(msg.payload);
        }
    );
}
```

`Channel.tablesdb(db).table(t).row().create()` resolves to the create event for every row in that table. The payload arrives already permission-filtered, so the recipient only sees the rows they are allowed to read. The app layout dispatches a browser event for route-specific highlights and shows the toast itself, while the check on `userId === recipientId` remains a defensive client-side filter.

The presence channel is even simpler. It is one channel for the whole app, and each event carries a single presence record in its payload. That makes it easy to dedupe by `$id` on the client.

Opening a snap invokes the Appwrite Function with the browser session, gets the base64 payload back, and returns a `data:` URL to the viewer. After the bytes load, a TanStack Start server function verifies the current user is the recipient and stamps the `viewedAt` column with an admin client, so the inbox can distinguish new snaps from ones the recipient has already opened.

![The snap viewer rendering bytes returned by the function](/images/blog/building-snapchat-clone-with-presences-and-realtime/app-snap-viewer.avif)

# Friendships in TablesDB

The friend graph fits in two tables. `friendRequests` is a row per invite with `fromUserId`, `toUserId`, and a required `status` string. `friendships` is the canonical relation, with a sorted pair `(userA, userB)` and a unique index to make duplicate detection cheap. Display names are resolved from Auth users when the server prepares the friends UI.

The whole flow runs through TanStack Start server functions because the client SDK cannot grant permissions on rows that belong to a different user. The accept handler, in particular, touches both tables in one server roundtrip:

```ts
await tablesDB.updateRow({
    databaseId: appwrite.databaseId,
    tableId: appwrite.tables.friendRequests,
    rowId: req.$id,
    data: { status: 'accepted' }
});

const [userA, userB] = sortedPair(req.fromUserId, req.toUserId);

await tablesDB.createRow({
    databaseId: appwrite.databaseId,
    tableId: appwrite.tables.friendships,
    rowId: ID.unique(),
    data: { userA, userB },
    permissions: [
        Permission.read(Role.user(userA)),
        Permission.read(Role.user(userB)),
        Permission.delete(Role.user(userA)),
        Permission.delete(Role.user(userB))
    ]
});
```

When the UI lists friends, it reads the relation rows through the session client and resolves the other user's display name through the admin `Users` service:

```ts
const rows = [...asA.rows, ...asB.rows] as FriendshipRow[];
const friends = await Promise.all(
    rows.map(async (row) => {
        const friendId = row.userA === userId ? row.userB : row.userA;
        const friend = await users.get({ userId: friendId });

        return {
            ...row,
            friendId,
            friendName: friend.name || friend.email
        };
    })
);
```

For this Snapchat clone, the whole graph is two relation tables and four operations: send, accept, decline, remove.

![Friends list after the request is accepted](/images/blog/building-snapchat-clone-with-presences-and-realtime/app-friends-accepted.avif)

# Key takeaways

- **Presences** gives you a live, server-expiring per-user state object. Once you have that, anything that wants to ask "who is online and where are they right now" stops needing a custom heartbeat table.
- **Functions** are the right tool whenever the security model is "the SDK permission check is correct but I also want a side condition." Twenty-four-hour expiry, signed previews, rate limiting, transcoding gates, all of them fit the same shape.
- **JWT delegation** lets a function act as the caller for row permission checks, while the function's scoped execution key handles the protected file read.
- **Realtime channel helpers** give you type inference on the payload and make the intent of the subscription obvious at the call site.
- **A single `appwrite.config.json`** for schema, bucket, function, and site means you stop thinking of the backend and the frontend as disconnected deployments. They version together, ship together, and roll back together.

# Next steps

You now have a working Snapchat clone with live locations, ephemeral snaps, and realtime notifications, on a stack that is one Appwrite project plus one TanStack Start app. The same patterns extend to anything where you need a presence layer plus permissioned ephemeral media: live-event apps, location-aware social tools, or time-limited file shares.

Try the deployed demo at [appchat-demo.appwrite.network](https://appchat-demo.appwrite.network), or clone the full source code from [appwrite-community/appchat](https://github.com/appwrite-community/appchat), swap in your project credentials, run `appwrite push`, and start building on top.

To go deeper into the features used in this tutorial:

- [Appwrite Presences API](/docs/products/auth/presences)
- [Appwrite Functions](/docs/products/functions)
- [Appwrite Realtime channels](/docs/apis/realtime/channels)
- [TanStack Start on Appwrite Sites](/docs/products/sites/quick-start/tanstack-start)
- [Join the Appwrite Discord](https://appwrite.io/discord)

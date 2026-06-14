---
layout: post
title: "One WebSocket, many subscriptions: smarter Realtime in Appwrite"
description: "Appwrite Realtime now keeps one persistent WebSocket and drives subscriptions with messages instead of cramming state into the connection URL. Client SDKs expose unsubscribe(), update(), and disconnect() for clearer lifecycle control."
date: 2026-04-29
cover: /images/blog/announcing-message-based-realtime-sdk/cover.avif
timeToRead: 9
author: eldad-fux
category: announcement
featured: false
faqs:
  - question: "How does Appwrite Realtime work in the new message-based model?"
    answer: "Appwrite Realtime keeps one persistent WebSocket per client and applies subscription changes as messages over that connection. Channels, queries, and updates are sent as messages instead of being encoded in the WebSocket URL. This avoids URL length limits and lets you scale up listeners and filters without reconnecting."
  - question: "Why did Appwrite move subscriptions off the WebSocket URL?"
    answer: "Encoding subscriptions in the URL hit URL length limits enforced by browsers, servers, and proxies, especially after [Realtime queries](/blog/post/announcing-realtime-queries) made query payloads larger. Moving subscriptions to messages over the established socket removes that ceiling and avoids reconnects whenever you tweak what you listen to."
  - question: "Does my existing Realtime code need to change?"
    answer: "If you use the [Appwrite client SDKs](/docs/products/databases), most code keeps working because the SDK handles the new protocol under the hood. The new APIs expose `unsubscribe()`, `update()`, and `disconnect()` for cleaner lifecycle control. Check the SDK release notes for any breaking changes specific to your platform."
  - question: "Can I subscribe to multiple channels on a single connection?"
    answer: "Yes, you create one `Realtime` instance from a `Client` and call `subscribe` for each channel you want, all on the same WebSocket. Each subscription has its own callback. This keeps connection count low even when you listen to many channels."
  - question: "How do I unsubscribe or update a subscription?"
    answer: "Each `subscribe` call returns a subscription handle with `unsubscribe()` to stop a single listener, and `update()` to change channels or filters without reconnecting. Use `disconnect()` on the `Realtime` instance to tear down the entire WebSocket. This gives clear, predictable lifecycle control."
  - question: "What kinds of events can I listen to via Realtime?"
    answer: "[Appwrite Realtime](/docs/products/databases) emits events for database changes, storage uploads and updates, account events, function executions, and team or membership changes. You subscribe by channel (for example, `files` or `account`) and receive events as messages on your callback as they happen."
---

Realtime features are where users feel your app is “alive”: collaborative edits, live dashboards, and instant feedback when data changes. That experience depends on how predictable your subscription lifecycle is. If every tweak to what you listen for forces a full reconnect, you pay in latency, battery, and mental overhead.

**Appwrite Realtime** now uses a **message-based protocol** on a **single persistent WebSocket**. The service applies subscription changes incrementally over the socket instead of treating the WebSocket URL as the source of truth for what you listen to.

Previously, subscription details were largely carried in the **query string of the Realtime WebSocket URL**. That tied you to **URL length limits** enforced by browsers, servers, and proxies, so in practice you could only combine so many channels and so much metadata before the connection string itself became a bottleneck.

That friction grew once we shipped [Realtime queries](/blog/post/announcing-realtime-queries) to filter subscription events on the server, and larger query payloads made the URL ceiling easier to hit. Channels and queries are now sent **over the established socket**, so you are not capped by query-string size when scaling up listeners or filters.

# One connection, many subscriptions

You create a `Realtime` instance from your configured `Client`, then call `subscribe` for each logical listener. The example below subscribes to two channels (`files` and `account`) on a single connection, shown across Appwrite clients in the tabs below.

{% multicode %}
```client-web
import { Client, Realtime, Channel } from "appwrite";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>');

const realtime = new Realtime(client);

const sub1 = await realtime.subscribe(Channel.files(), response => {
    console.log(response);
});

const sub2 = await realtime.subscribe(Channel.account(), response => {
    console.log(response);
});
```

```client-flutter
import 'package:appwrite/appwrite.dart';

final client = Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>');

final realtime = Realtime(client);

final sub1 = realtime.subscribe([Channel.files()]);
final sub2 = realtime.subscribe([Channel.account()]);

sub1.stream.listen((response) {
    print(response);
});

sub2.stream.listen((response) {
    print(response);
});
```

```client-apple
import Appwrite
import AppwriteModels

let client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1")
    .setProject("<PROJECT_ID>")

let realtime = Realtime(client)

let sub1 = realtime.subscribe(channels: [Channel.files()]) { response in
    print(String(describing: response))
}

let sub2 = realtime.subscribe(channels: [Channel.account()]) { response in
    print(String(describing: response))
}
```

```client-android-kotlin
import io.appwrite.Channel
import io.appwrite.Client
import io.appwrite.services.Realtime

val client = Client(context)
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1")
    .setProject("<PROJECT_ID>")

val realtime = Realtime(client)

val sub1 = realtime.subscribe(Channel.files()) {
    print(it.payload.toString())
}

val sub2 = realtime.subscribe(Channel.account()) {
    print(it.payload.toString())
}
```

```client-android-java
import io.appwrite.Client;
import io.appwrite.models.RealtimeResponseEvent;
import io.appwrite.models.RealtimeSubscription;
import io.appwrite.services.Realtime;
import kotlin.Unit;

Client client = new Client(context)
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1")
    .setProject("<PROJECT_ID>");

Realtime realtime = new Realtime(client);

RealtimeSubscription sub1 = realtime.subscribe(
    new String[] {"files"},
    (RealtimeResponseEvent<Object> response) -> {
        System.out.println("files " + response);
        return Unit.INSTANCE;
    }
);

RealtimeSubscription sub2 = realtime.subscribe(
    new String[] {"account"},
    (RealtimeResponseEvent<Object> response) -> {
        System.out.println("account " + response);
        return Unit.INSTANCE;
    }
);
```

{% /multicode %}

Unsubscribing one handle **does not** drop unrelated listeners: the Realtime service keeps the shared connection and removes only what you asked for.

Call `unsubscribe()` on a subscription handle to stop that listener, and `realtime.disconnect()` to close the socket entirely. The legacy `close()` alias remains for backwards compatibility. See the [subscribe documentation](/docs/apis/realtime/subscribe) for the full API.

{% multicode %}
```client-web
await sub1.unsubscribe(); // only sub1 stops receiving events
await sub2.unsubscribe(); // only sub2

// When your UI is done with Realtime (for example on unmount):
realtime.disconnect();
```

```client-flutter
await sub1.unsubscribe();
await sub2.unsubscribe();

await realtime.disconnect();
```

```client-apple
try await sub1.unsubscribe()
try await sub2.unsubscribe()

try await realtime.disconnect()
```

```client-android-kotlin
sub1.unsubscribe()
sub2.unsubscribe()

realtime.disconnect()
```

```client-android-java
sub1.unsubscribe();
sub2.unsubscribe();

realtime.disconnect();
```

{% /multicode %}

# In-place subscription updates

Changing channels or queries no longer requires recreating the subscription. Call **`update()`** on the subscription handle to adjust the channels or queries while the WebSocket stays open. The API is available across **Web, Flutter, Apple, and Android** SDKs (see [Subscribe](/docs/apis/realtime/subscribe#update-a-subscription)).

{% multicode %}
```client-web
import { Client, Realtime, Channel, Query } from "appwrite";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>');

const realtime = new Realtime(client);

const subscription = await realtime.subscribe(Channel.files(), response => {
    console.log(response);
});

await subscription.update({
    channels: [Channel.tablesdb('<DATABASE_ID>').table('<TABLE_ID>').row('<ROW_ID>')],
    queries: [Query.equal('title', ['todo'])],
});
```

```client-flutter
import 'package:appwrite/appwrite.dart';

final client = Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>');

final realtime = Realtime(client);

final subscription = realtime.subscribe([Channel.files()]);

subscription.stream.listen((response) {
    print(response);
});

await subscription.update(
    channels: [Channel.tablesdb('<DATABASE_ID>').table('<TABLE_ID>').row('<ROW_ID>')],
    queries: [Query.equal('title', ['todo'])],
);
```

```client-apple
import Appwrite
import AppwriteModels

let client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1")
    .setProject("<PROJECT_ID>")

let realtime = Realtime(client)

let subscription = realtime.subscribe(channels: [Channel.files()]) { response in
    print(String(describing: response))
}

try await subscription.update(RealtimeSubscriptionUpdate(
    channels: [Channel.tablesdb("<DATABASE_ID>").table("<TABLE_ID>").row("<ROW_ID>")],
    queries: [Query.equal("title", value: ["todo"])]
))
```

```client-android-kotlin
import io.appwrite.Channel
import io.appwrite.Client
import io.appwrite.Query
import io.appwrite.services.Realtime
import io.appwrite.models.RealtimeSubscriptionUpdate

val client = Client(context)
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1")
    .setProject("<PROJECT_ID>")

val realtime = Realtime(client)

val subscription = realtime.subscribe(Channel.files()) {
    print(it.payload.toString())
}

subscription.update(RealtimeSubscriptionUpdate(
    channels = listOf(Channel.tablesdb("<DATABASE_ID>").table("<TABLE_ID>").row("<ROW_ID>")),
    queries = listOf(Query.equal("title", listOf("todo"))),
))
```

```client-android-java
import io.appwrite.Client;
import io.appwrite.Query;
import io.appwrite.models.RealtimeResponseEvent;
import io.appwrite.models.RealtimeSubscription;
import io.appwrite.models.RealtimeSubscriptionUpdate;
import io.appwrite.services.Realtime;
import kotlin.Unit;

import java.util.Arrays;

Client client = new Client(context)
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1")
    .setProject("<PROJECT_ID>");

Realtime realtime = new Realtime(client);

RealtimeSubscription subscription = realtime.subscribe(
    new String[] {"files"},
    (RealtimeResponseEvent<Object> response) -> {
        System.out.println(response);
        return Unit.INSTANCE;
    }
);

subscription.update(new RealtimeSubscriptionUpdate(
    Arrays.asList("tablesdb.<DATABASE_ID>.tables.<TABLE_ID>.rows.<ROW_ID>"),
    Arrays.asList(Query.equal("title", Arrays.asList("todo")))
));
```

{% /multicode %}

# Why this matters

- **Clearer ownership**: Each subscription is its own handle with a predictable lifecycle.
- **Better performance**: Fewer full reconnects when your app state shifts.
- **Simpler UI code**: Mount paths call `subscribe` (or `update`), unmount paths call `unsubscribe` or `disconnect`.

# More resources

- [Introducing Realtime queries: Server-side event filtering for subscriptions](/blog/post/announcing-realtime-queries)
- [Realtime API overview](/docs/apis/realtime)
- [Subscribe and manage channels](/docs/apis/realtime/subscribe)
- [Realtime channels and helpers](/docs/apis/realtime/channels)

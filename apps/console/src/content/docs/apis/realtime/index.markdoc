---
layout: article
title: Realtime
description: Want to build dynamic and interactive applications with real-time data updates? Appwrite Realtime API makes it possible, get started with our intro guide.
---

Appwrite supports multiple protocols for accessing the server, including [REST](/docs/apis/rest), [GraphQL](/docs/apis/graphql), and Realtime. The Appwrite Realtime allows you to listen to any Appwrite events in realtime using the `Realtime` service.

Instead of requesting new data via HTTP, the subscription will receive new data every time it changes, any connected client receives that update within milliseconds via a WebSocket connection.

This lets you build an interactive and responsive user experience by providing information from all of Appwrite's services in realtime. The example below shows subscribing to realtime events for file uploads.

{% multicode %}
```client-web
import { Client, Realtime, Channel } from "appwrite";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>');

const realtime = new Realtime(client);

// Subscribe to files channel
const subscription = await realtime.subscribe(Channel.files(), response => {
    if(response.events.includes('buckets.*.files.*.create')) {
        // Log when a new file is uploaded
        console.log(response.payload);
    }
});
```

```client-flutter
import 'package:appwrite/appwrite.dart';

final client = Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>');

final realtime = Realtime(client);

// Subscribe to files channel
final subscription = realtime.subscribe([Channel.files()]);

subscription.stream.listen((response) {
    if(response.events.contains('buckets.*.files.*.create')) {
    // Log when a new file is uploaded
    print(response.payload);
    }
});
```

```client-apple
import Appwrite
import AppwriteModels

let client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1")
    .setProject("<PROJECT_ID>")

let realtime = Realtime(client)

// Subscribe to files channel
let subscription = realtime.subscribe(channels: [Channel.files()]) { response in
    if (response.events!.contains("buckets.*.files.*.create")) {
        // Log when a new file is uploaded
        print(String(describing: response))
    }
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

// Subscribe to files channel
val subscription = realtime.subscribe(Channel.files()) {
    if(it.events.contains("buckets.*.files.*.create")) {
        // Log when a new file is uploaded
        print(it.payload.toString());
    }
}
```

```client-android-java
import io.appwrite.Client;
import io.appwrite.models.RealtimeResponseEvent;
import io.appwrite.services.Realtime;
import kotlin.Unit;

Client client = new Client(context)
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1")
    .setProject("<PROJECT_ID>");

Realtime realtime = new Realtime(client);

// Subscribe to files channel
realtime.subscribe(new String[] {"files"}, (RealtimeResponseEvent<Object> response) -> {
    if (response.getEvents().contains("buckets.*.files.*.create")) {
        // Log when a new file is uploaded
        System.out.println(response.getPayload());
    }
    return Unit.INSTANCE;
});
```

{% /multicode %}

To subscribe to updates from different Appwrite resources, you need to specify one or more [channels](/docs/apis/realtime/channels). The channels offer a wide and powerful selection that will allow you to listen to all possible resources. This allows you to receive updates not only from the database, but from _all_ the services that Appwrite offers.

If you subscribe to a channel, you will receive callbacks for a variety of events related to the channel. The events column in the callback can be used to filter and respond to specific events in a channel.

[View a list of all available events](/docs/apis/events).

{% info title="Permissions" %}
All subscriptions are secured by the [permissions system](/docs/advanced/security/permissions) offered by Appwrite, meaning a user will only receive updates to resources they have permission to access.

Using `Role.any()` on read permissions will allow any client to receive updates.
{% /info %}

# Limitations {% #limitations %}

While the Realtime API offers robust capabilities, there are currently some limitations to keep in mind.

## Subscription changes {% #subscription-changes %}

Client SDKs use a **single WebSocket** per `Realtime` client for all subscriptions. Adding a subscription with `subscribe()`, dropping one with `subscription.unsubscribe()`, or replacing channels and queries via `subscription.update(...)` applies on the existing socket where supported — no full reconnect required. The connection is torn down when you call `realtime.disconnect()`, or when the legacy `subscription.close()` runs on the last remaining subscription.

Manage subscription handles alongside your application state so you unsubscribe or disconnect when listeners are no longer needed. See [Subscribe](/docs/apis/realtime/subscribe) for platform-specific APIs.

## Server SDKs {% #server-sdks %}

We currently are not offering access to realtime with Server SDKs and an API key.

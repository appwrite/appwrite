---
layout: article
title: Subscribe
description: Learn how to subscribe to realtime events from Appwrite services. Subscribe to single or multiple channels and manage your subscriptions.
---

The Appwrite Realtime API lets you subscribe to events from any Appwrite service through [channels](/docs/apis/realtime/channels). You can subscribe to a single channel, multiple channels at once, and unsubscribe when you no longer need updates. On supported client SDKs (including the Web SDK), multiple subscriptions share one WebSocket and can be added, **updated**, or removed without reconnecting the whole client until you call **`disconnect()`** on `Realtime`.

# Subscribe to a channel {% #subscribe-to-a-channel %}

In this example we are subscribing to all updates related to our account by using the `account` channel. This will be triggered by any update related to the authenticated user, like updating the user's name or e-mail address.

{% multicode %}
```client-web
import { Client, Realtime, Channel } from "appwrite";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>');

const realtime = new Realtime(client);

const subscription = await realtime.subscribe(Channel.account(), response => {
    // Callback will be executed on all account events.
    console.log(response);
});
```

```client-flutter
import 'package:appwrite/appwrite.dart';

final client = Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>');

final realtime = Realtime(client);

final subscription = realtime.subscribe([Channel.account()]);

subscription.stream.listen((response) {
    // Callback will be executed on all account events.
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

let subscription = realtime.subscribe(channels: [Channel.account()]) { response in
    // Callback will be executed on all account events.
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

val subscription = realtime.subscribe(Channel.account()) {
    // Callback will be executed on all account events.
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

RealtimeSubscription subscription = realtime.subscribe(
    new String[] {"account"},
    (RealtimeResponseEvent<Object> response) -> {
        // Callback will be executed on all account events.
        System.out.println(response);
        return Unit.INSTANCE;
    }
);
```

{% /multicode %}

# Subscribe to multiple channels  {% #subscribe-to-multiple-channels %}

You can also listen to multiple channels at once by passing an array of channels. This will trigger the callback for any events for all channels passed.

In this example we are listening to a specific row and all files by subscribing to `Channel.tablesdb("<DATABASE_ID>").table("<TABLE_ID>").row("<ROW_ID>")` and `Channel.files()` channels.

{% multicode %}
```client-web
import { Client, Realtime, Channel } from "appwrite";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>');

const realtime = new Realtime(client);

const subscription = await realtime.subscribe([
    Channel.tablesdb('<DATABASE_ID>').table('<TABLE_ID>').row('<ROW_ID>'),
    Channel.files()
], response => {
    // Callback will be executed on changes for the row and all files.
    console.log(response);
});
```

```client-flutter
import 'package:appwrite/appwrite.dart';

final client = Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>');

final realtime = Realtime(client);

final subscription = realtime.subscribe([
    Channel.tablesdb('<DATABASE_ID>').table('<TABLE_ID>').row('<ROW_ID>'),
    Channel.files()
]);

subscription.stream.listen((response) {
    // Callback will be executed on changes for the row and all files.
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

realtime.subscribe(channels: [
    Channel.tablesdb("<DATABASE_ID>").table("<TABLE_ID>").row("<ROW_ID>"),
    Channel.files()
]) { response in
    // Callback will be executed on changes for the row and all files.
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

realtime.subscribe(
    Channel.tablesdb("<DATABASE_ID>").table("<TABLE_ID>").row("<ROW_ID>"),
    Channel.files()
) {
    // Callback will be executed on changes for the row and all files.
    print(it.toString())
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

RealtimeSubscription subscription = realtime.subscribe(
    new String[] {
        "tablesdb.<DATABASE_ID>.tables.<TABLE_ID>.rows.<ROW_ID>",
        "files"
    },
    (RealtimeResponseEvent<Object> response) -> {
        // Callback will be executed on changes for the row and all files.
        System.out.println(response);
        return Unit.INSTANCE;
    }
);
```

{% /multicode %}

# Update channels or queries {% #update %}

Channels and queries on an active subscription can be replaced in place without recreating the WebSocket. This is useful when, for example, a user changes which row they're viewing. Swap the channel on the existing subscription instead of unsubscribing and resubscribing.

`update()` accepts either or both of `channels` and `queries`. Pass only the field you want to replace; omitted fields are left unchanged.

{% multicode %}
```client-web
import { Client, Realtime, Channel } from "appwrite";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>');

const realtime = new Realtime(client);

const subscription = await realtime.subscribe(
    Channel.tablesdb('<DATABASE_ID>').table('<TABLE_ID>').row('<ROW_ID>'),
    response => console.log(response),
);

// Switch to a different row — no reconnect required.
await subscription.update({
    channels: [Channel.tablesdb('<DATABASE_ID>').table('<TABLE_ID>').row('<NEW_ROW_ID>')],
});
```

```client-flutter
import 'package:appwrite/appwrite.dart';

final client = Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>');

final realtime = Realtime(client);

final subscription = realtime.subscribe([
    Channel.tablesdb('<DATABASE_ID>').table('<TABLE_ID>').row('<ROW_ID>'),
]);

subscription.stream.listen((response) {
    print(response);
});

// Switch to a different row — no reconnect required.
await subscription.update(
    channels: [Channel.tablesdb('<DATABASE_ID>').table('<TABLE_ID>').row('<NEW_ROW_ID>')],
);
```

```client-apple
import Appwrite
import AppwriteModels

let client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1")
    .setProject("<PROJECT_ID>")

let realtime = Realtime(client)

let subscription = realtime.subscribe(channels: [
    Channel.tablesdb("<DATABASE_ID>").table("<TABLE_ID>").row("<ROW_ID>"),
]) { response in
    print(String(describing: response))
}

// Switch to a different row — no reconnect required.
try await subscription.update(RealtimeSubscriptionUpdate(
    channels: [Channel.tablesdb("<DATABASE_ID>").table("<TABLE_ID>").row("<NEW_ROW_ID>")]
))
```

```client-android-kotlin
import io.appwrite.Channel
import io.appwrite.Client
import io.appwrite.services.Realtime
import io.appwrite.models.RealtimeSubscriptionUpdate

val client = Client(context)
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1")
    .setProject("<PROJECT_ID>")

val realtime = Realtime(client)

val subscription = realtime.subscribe(
    Channel.tablesdb("<DATABASE_ID>").table("<TABLE_ID>").row("<ROW_ID>"),
) {
    print(it.toString())
}

// Switch to a different row — no reconnect required.
subscription.update(RealtimeSubscriptionUpdate(
    channels = listOf(Channel.tablesdb("<DATABASE_ID>").table("<TABLE_ID>").row("<NEW_ROW_ID>")),
))
```

```client-android-java
import io.appwrite.Client;
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
    new String[] {"tablesdb.<DATABASE_ID>.tables.<TABLE_ID>.rows.<ROW_ID>"},
    (RealtimeResponseEvent<Object> response) -> {
        System.out.println(response);
        return Unit.INSTANCE;
    }
);

// Switch to a different row — no reconnect required.
subscription.update(new RealtimeSubscriptionUpdate(
    Arrays.asList("tablesdb.<DATABASE_ID>.tables.<TABLE_ID>.rows.<NEW_ROW_ID>"),
    null
));
```

{% /multicode %}

# Unsubscribe {% #unsubscribe %}

If you no longer want to receive updates from a particular subscription, call `unsubscribe()` on it. Other subscriptions and the underlying WebSocket connection are not affected, so callbacks for the rest of your app keep firing. To close the entire connection at once, see [Disconnect](#disconnect) below.

{% multicode %}
```client-web
import { Client, Realtime, Channel } from "appwrite";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>');

const realtime = new Realtime(client);

const subscription = await realtime.subscribe(Channel.files(), response => {
    // Callback will be executed on changes for all files.
    console.log(response);
});

// Removes only this subscription. Other subscriptions and the WebSocket stay open.
await subscription.unsubscribe();
```

```client-flutter
import 'package:appwrite/appwrite.dart';

final client = Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>');

final realtime = Realtime(client);

final subscription = realtime.subscribe([Channel.files()]);

subscription.stream.listen((response) {
    // Callback will be executed on changes for all files.
    print(response);
});

// Removes only this subscription. Other subscriptions and the WebSocket stay open.
await subscription.unsubscribe();
```

```client-apple
import Appwrite

let client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1")
    .setProject("<PROJECT_ID>")

let realtime = Realtime(client)

let subscription = realtime.subscribe(channels: [Channel.files()]) { response in
    // Callback will be executed on changes for all files.
    print(response.toString())
}

// Removes only this subscription. Other subscriptions and the WebSocket stay open.
try await subscription.unsubscribe()
```

```client-android-kotlin
import io.appwrite.Channel
import io.appwrite.Client
import io.appwrite.services.Realtime

val client = Client(context)
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1")
    .setProject("<PROJECT_ID>")

val realtime = Realtime(client)

val subscription = realtime.subscribe(Channel.files()) {
    // Callback will be executed on changes for all files.
    print(it.toString())
}

// Removes only this subscription. Other subscriptions and the WebSocket stay open.
subscription.unsubscribe()
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

RealtimeSubscription subscription = realtime.subscribe(
    new String[] {"files"},
    (RealtimeResponseEvent<Object> response) -> {
        // Callback will be executed on changes for all files.
        System.out.println(response);
        return Unit.INSTANCE;
    }
);

// Removes only this subscription. Other subscriptions and the WebSocket stay open.
subscription.unsubscribe();
```

{% /multicode %}

{% info title="Legacy close()" %}
`subscription.close()` still works for backwards compatibility. It calls `unsubscribe()` and additionally closes the WebSocket if this was the last active subscription. New code should prefer `unsubscribe()` and call `realtime.disconnect()` explicitly when full teardown is needed.
{% /info %}

# Disconnect {% #disconnect %}

Call `realtime.disconnect()` to drop **all** active subscriptions and close the WebSocket in one step. Use this at app teardown or when a user logs out.

{% multicode %}
```client-web
import { Client, Realtime } from "appwrite";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>');

const realtime = new Realtime(client);

// ... subscribe to one or more channels ...

// Drop all subscriptions and close the WebSocket.
await realtime.disconnect();
```

```client-flutter
import 'package:appwrite/appwrite.dart';

final client = Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>');

final realtime = Realtime(client);

// ... subscribe to one or more channels ...

// Drop all subscriptions and close the WebSocket.
await realtime.disconnect();
```

```client-apple
import Appwrite

let client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1")
    .setProject("<PROJECT_ID>")

let realtime = Realtime(client)

// ... subscribe to one or more channels ...

// Drop all subscriptions and close the WebSocket.
try await realtime.disconnect()
```

```client-android-kotlin
import io.appwrite.Client
import io.appwrite.services.Realtime

val client = Client(context)
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1")
    .setProject("<PROJECT_ID>")

val realtime = Realtime(client)

// ... subscribe to one or more channels ...

// Drop all subscriptions and close the WebSocket.
realtime.disconnect()
```

```client-android-java
import io.appwrite.Client;
import io.appwrite.services.Realtime;

Client client = new Client(context)
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1")
    .setProject("<PROJECT_ID>");

Realtime realtime = new Realtime(client);

// ... subscribe to one or more channels ...

// Drop all subscriptions and close the WebSocket.
realtime.disconnect();
```

{% /multicode %}

---
layout: article
title: Channels
description: Explore the available Realtime channels and learn how to use Channel helpers for type-safe subscriptions in Appwrite.
---

Channels define which Appwrite resources you want to subscribe to. When subscribing to a channel, you will receive callbacks for events related to that channel's resources. The Appwrite SDKs provide a `Channel` helper class to build type-safe channel subscriptions using a fluent API.

# Channel helpers {% #channel-helpers %}

Instead of manually writing channel strings, you can use the `Channel` helper class to build type-safe channel subscriptions. The helper provides a fluent API that makes it easier to construct channel strings and reduces errors.

{% multicode %}
```client-web
import { Client, Realtime, Channel } from "appwrite";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>');

const realtime = new Realtime(client);

// Subscribe to account channel
const subscription = await realtime.subscribe(Channel.account(), response => {
    console.log(response);
});

// Subscribe to a specific row
const rowSubscription = await realtime.subscribe(
    Channel.tablesdb('<DATABASE_ID>').table('<TABLE_ID>').row('<ROW_ID>'),
    response => {
        console.log(response);
    }
);
```

```client-flutter
import 'package:appwrite/appwrite.dart';

final client = Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>');

final realtime = Realtime(client);

// Subscribe to account channel
final subscription = realtime.subscribe([Channel.account()]);

// Subscribe to a specific row
final docSubscription = realtime.subscribe([
    Channel.tablesdb('<DATABASE_ID>').table('<TABLE_ID>').row('<ROW_ID>')
]);
```

```client-apple
import Appwrite

let client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1")
    .setProject("<PROJECT_ID>")

let realtime = Realtime(client)

// Subscribe to account channel
let subscription = realtime.subscribe(channels: [Channel.account()]) { response in
    print(String(describing: response))
}

// Subscribe to a specific row
let docSubscription = realtime.subscribe(
    channels: [Channel.tablesdb("<DATABASE_ID>").table("<TABLE_ID>").row("<ROW_ID>")]
) { response in
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

// Subscribe to account channel
val subscription = realtime.subscribe(Channel.account()) {
    print(it.toString())
}

// Subscribe to a specific row
val docSubscription = realtime.subscribe(
    Channel.tablesdb("<DATABASE_ID>").table("<TABLE_ID>").row("<ROW_ID>")
) {
    print(it.toString())
}
```

```client-android-java
import io.appwrite.Channel;
import io.appwrite.ChannelKt;
import io.appwrite.Client;
import io.appwrite.models.RealtimeResponseEvent;
import io.appwrite.models.RealtimeSubscription;
import io.appwrite.services.Realtime;
import kotlin.Unit;

Client client = new Client(context)
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1")
    .setProject("<PROJECT_ID>");

Realtime realtime = new Realtime(client);

// Subscribe to account channel
String accountChannel = Channel.Companion.account();
RealtimeSubscription subscription = realtime.subscribe(
    new String[] {accountChannel},
    (RealtimeResponseEvent<Object> response) -> {
        System.out.println(response);
        return Unit.INSTANCE;
    }
);

// Subscribe to a specific row
Channel<?> rowChannel = ChannelKt.row(
    ChannelKt.table(Channel.Companion.tablesdb("<DATABASE_ID>"), "<TABLE_ID>"),
    "<ROW_ID>"
);

RealtimeSubscription rowSubscription = realtime.subscribe(
    new Channel[] {rowChannel},
    (RealtimeResponseEvent<Object> response) -> {
        System.out.println(response);
        return Unit.INSTANCE;
    }
);
```

{% /multicode %}

The `Channel` helper supports all available channels and allows you to:
- Build channels with a fluent, chainable API
- Optionally specify resource IDs (omit IDs to subscribe to all resources)
- Add event filters like `.create()`, `.update()`, or `.delete()`

# Available channels {% #available-channels %}

A list of all channels available you can subscribe to. When using `Channel` helpers, leaving an ID blank will subscribe using `*`.

{% table %}
* Channel
* Channel Helper
* Description
---
* `account`
* `Channel.account()`
* All account related events (session create, name update...)
---
* `tablesdb.<ID>.tables.<ID>.rows`
* `Channel.tablesdb('<DATABASE_ID>').table('<TABLE_ID>').row()`
* Any create/update/delete events to any row in a table
---
* `rows`
* `Channel.rows()`
* Any create/update/delete events to any row
---
* `tablesdb.<ID>.tables.<ID>.rows.<ID>`
* `Channel.tablesdb('<DATABASE_ID>').table('<TABLE_ID>').row('<ROW_ID>')`
* Any update/delete events to a given row
---
* `files`
* `Channel.files()`
* Any create/update/delete events to any file
---
* `buckets.<ID>.files.<ID>`
* `Channel.bucket('<BUCKET_ID>').file('<FILE_ID>')`
* Any update/delete events to a given file of the given bucket
---
* `buckets.<ID>.files`
* `Channel.bucket('<BUCKET_ID>').file()`
* Any update/delete events to any file of the given bucket
---
* `teams.*`
* `Channel.teams()`
* Any create/update/delete events to any team
---
* `teams.<ID>`
* `Channel.team('<TEAM_ID>')`
* Any update/delete events to a given team
---
* `memberships`
* `Channel.memberships()`
* Any create/update/delete events to any membership
---
* `memberships.<ID>`
* `Channel.membership('<MEMBERSHIP_ID>')`
* Any update/delete events to a given membership
---
* `executions`
* `Channel.executions()`
* Any update to executions
---
* `executions.<ID>`
* `Channel.execution('<ID>')`
* Any update to a given execution
---
* `functions.<ID>`
* `Channel.function('<FUNCTION_ID>')`
* Any execution event to a given function
---
* `presences`
* `Channel.presences()`
* Any upsert, update, or delete event on any [presence](/docs/apis/realtime/presences) the subscriber can read.
---
* `presences.<ID>`
* `Channel.presence('<PRESENCE_ID>')`
* Any upsert, update, or delete event on a given presence record.

{% /table %}

# Event filters {% #event-filters %}

You can also filter events by appending event methods to the channel helpers:
- `.create()` - Listen only to create events
- `.update()` - Listen only to update events
- `.delete()` - Listen only to delete events

For example, `Channel.tablesdb('<DATABASE_ID>').table('<TABLE_ID>').row('<ROW_ID>').update()` will only trigger on row updates.

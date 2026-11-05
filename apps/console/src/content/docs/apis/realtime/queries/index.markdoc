---
layout: article
title: Queries
description: Filter realtime events using queries. Use familiar SDK query methods to receive only the updates that match your conditions.
---

You can filter realtime events by passing queries as a third parameter when subscribing. Events are filtered server-side based on your queries, so your callback only receives updates that match your conditions. This allows you to use familiar SDK queries like `Query.equal` to automatically filter events instead of filtering manually in your callback.

{% multicode %}
```client-web
import { Client, Realtime, Channel, Query } from "appwrite";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>');

const realtime = new Realtime(client);

// Subscribe to all updates
const allVotes = await realtime.subscribe(
    Channel.tablesdb('<DATABASE_ID>').table('<TABLE_ID>').row(),
    response => {
        console.log(response.payload);
    }
);

// Subscribe to updates where person equals 'person1'
const person1Votes = await realtime.subscribe(
    Channel.tablesdb('<DATABASE_ID>').table('<TABLE_ID>').row(),
    response => {
        console.log(response.payload);
    },
    [Query.equal('person', ['person1'])]
);

// Subscribe to updates where person is not 'person1'
const otherVotes = await realtime.subscribe(
    Channel.tablesdb('<DATABASE_ID>').table('<TABLE_ID>').row(),
    response => {
        console.log(response.payload);
    },
    [Query.notEqual('person', 'person1')]
);
```

```client-flutter
import 'package:appwrite/appwrite.dart';

final client = Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>');

final realtime = Realtime(client);

// Subscribe to all updates
final allVotes = realtime.subscribe(
    [Channel.tablesdb('<DATABASE_ID>').table('<TABLE_ID>').row()]
);

allVotes.stream.listen((response) {
    print(response.payload);
});

// Subscribe to updates where person equals 'person1'
final person1Votes = realtime.subscribe(
    [Channel.tablesdb('<DATABASE_ID>').table('<TABLE_ID>').row()],
    queries: [Query.equal('person', ['person1'])]
);

person1Votes.stream.listen((response) {
    print(response.payload);
});

// Subscribe to updates where person is not 'person1'
final otherVotes = realtime.subscribe(
    [Channel.tablesdb('<DATABASE_ID>').table('<TABLE_ID>').row()],
    queries: [Query.notEqual('person', 'person1')]
);

otherVotes.stream.listen((response) {
    print(response.payload);
});
```

```client-apple
import Appwrite
import AppwriteModels

let client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1")
    .setProject("<PROJECT_ID>")

let realtime = Realtime(client)

// Subscribe to all updates
let allVotes = realtime.subscribe(
    channels: [Channel.tablesdb("<DATABASE_ID>").table("<TABLE_ID>").row()]
) { response in
    print(String(describing: response.payload))
}

// Subscribe to updates where person equals 'person1'
let person1Votes = realtime.subscribe(
    channels: [Channel.tablesdb("<DATABASE_ID>").table("<TABLE_ID>").row()],
    callback: { response in
        print(String(describing: response.payload))
    },
    queries: [Query.equal("person", value: ["person1"])]
)

// Subscribe to updates where person is not 'person1'
let otherVotes = realtime.subscribe(
    channels: [Channel.tablesdb("<DATABASE_ID>").table("<TABLE_ID>").row()],
    callback: { response in
        print(String(describing: response.payload))
    },
    queries: [Query.notEqual("person", value: "person1")]
)
```

```client-android-kotlin
import io.appwrite.Channel
import io.appwrite.Client
import io.appwrite.Query
import io.appwrite.services.Realtime

val client = Client(context)
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1")
    .setProject("<PROJECT_ID>")

val realtime = Realtime(client)

// Subscribe to all updates
val allVotes = realtime.subscribe(
    Channel.tablesdb("<DATABASE_ID>").table("<TABLE_ID>").row()
) {
    print(it.payload.toString())
}

// Subscribe to updates where person equals 'person1'
val person1Votes = realtime.subscribe(
    Channel.tablesdb("<DATABASE_ID>").table("<TABLE_ID>").row(),
    payloadType = Any::class.java,
    queries = setOf(Query.equal("person", listOf("person1")))
) {
    print(it.payload.toString())
}

// Subscribe to updates where person is not 'person1'
val otherVotes = realtime.subscribe(
    Channel.tablesdb("<DATABASE_ID>").table("<TABLE_ID>").row(),
    payloadType = Any::class.java,
    queries = setOf(Query.notEqual("person", "person1"))
) {
    print(it.payload.toString())
}
```

```client-android-java
import io.appwrite.Client;
import io.appwrite.Query;
import io.appwrite.models.RealtimeResponseEvent;
import io.appwrite.models.RealtimeSubscription;
import io.appwrite.services.Realtime;
import java.util.Arrays;
import java.util.HashSet;
import kotlin.Unit;

Client client = new Client(context)
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1")
    .setProject("<PROJECT_ID>");

Realtime realtime = new Realtime(client);

// Subscribe to all updates
RealtimeSubscription allVotes = realtime.subscribe(
    new String[] {"tablesdb.<DATABASE_ID>.tables.<TABLE_ID>.rows"},
    (RealtimeResponseEvent<Object> response) -> {
        System.out.println(response.getPayload());
        return Unit.INSTANCE;
    }
);

// Subscribe to updates where person equals 'person1'
RealtimeSubscription person1Votes = realtime.subscribe(
    new String[] {"tablesdb.<DATABASE_ID>.tables.<TABLE_ID>.rows"},
    Object.class,
    new HashSet<>(Arrays.asList(Query.equal("person", Arrays.asList("person1")))),
    (RealtimeResponseEvent<Object> response) -> {
        System.out.println(response.getPayload());
        return Unit.INSTANCE;
    }
);

// Subscribe to updates where person is not 'person1'
RealtimeSubscription otherVotes = realtime.subscribe(
    new String[] {"tablesdb.<DATABASE_ID>.tables.<TABLE_ID>.rows"},
    Object.class,
    new HashSet<>(Arrays.asList(Query.notEqual("person", "person1"))),
    (RealtimeResponseEvent<Object> response) -> {
        System.out.println(response.getPayload());
        return Unit.INSTANCE;
    }
);
```

{% /multicode %}

# Supported queries {% #supported-queries %}

The following query methods are supported for realtime filtering:

{% table %}
* Category
* Queries
---
* Comparison
* `Query.equal()`, `Query.notEqual()`, `Query.greaterThan()`, `Query.greaterThanEqual()`, `Query.lessThan()`, `Query.lessThanEqual()`
---
* Null checks
* `Query.isNull()`, `Query.isNotNull()`
---
* Logical
* `Query.and()`, `Query.or()`
{% /table %}

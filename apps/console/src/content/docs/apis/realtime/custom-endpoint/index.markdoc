---
layout: article
title: Custom endpoint
description: Learn how to configure a custom WebSocket endpoint for the Appwrite Realtime API when using a custom proxy.
---

The SDK will guess the endpoint of the Realtime API when setting the endpoint of your Appwrite instance. If you are running Appwrite with a custom proxy and changed the route of the Realtime API, you can call the `setEndpointRealtime` method on the Client SDK and set your new endpoint value.

By default the endpoint is `wss://<REGION>.cloud.appwrite.io/v1/realtime`.

{% multicode %}
```client-web
import { Client } from "appwrite";
const client = new Client();

client.setEndpointRealtime('wss://<REGION>.cloud.appwrite.io/v1/realtime');
```

```client-flutter
import 'package:appwrite/appwrite.dart';

final client = Client();
client.setEndpointRealtime('wss://<REGION>.cloud.appwrite.io/v1/realtime');
```

```client-apple
import Appwrite

let client = Client()
client.setEndpointRealtime("wss://<REGION>.cloud.appwrite.io/v1/realtime")
```

```client-android-kotlin
import io.appwrite.Client

val client = Client(context)
client.setEndpointRealtime("wss://<REGION>.cloud.appwrite.io/v1/realtime")
```

```client-android-java
import io.appwrite.Client;

Client client = new Client(context);
client.setEndpointRealtime("wss://<REGION>.cloud.appwrite.io/v1/realtime");
```

{% /multicode %}

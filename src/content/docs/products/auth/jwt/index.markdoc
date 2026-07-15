---
layout: article
title: JWT login
description: Integrate Appwrite's authentication into your server-side applications. Explore server integrations, best practices, and security considerations for seamless authentication.
---

You can extend Appwrite's APIs by building backend apps using [Server SDKs](/docs/sdks#server). To secure your backend app's APIs, client apps must prove their identity against your backend app before accessing sensitive information. You can secure these APIs and enforce access permissions in your backend app by using JWT authentication.

If you are already authenticated on your client-side app and need your backend app to **act on behalf of the user**, this guide will walk you through the process.

# Proof of Identity {% #proof-of-identity %}

Before making requests to your backend APIs, your client application needs to first create a session **directly with Appwrite** using the account service. This session will act like an ID card for the user and can be used to access resources in Appwrite. The client will **only receive information accessible to the user** based on the resources' [permissions](/docs/advanced/security/permissions).

When you build backend APIs to extend Appwrite's functionality, these APIs should still **respect access permissions** to keep user data secure. Appwrite's backend SDKs allow you to securely act on behalf of a user with the same permissions by using JWT authentication.

# JWT Authentication {% #jwt %}

[JSON Web Tokens](https://jwt.io/introduction) (JWTs) are a secure means to transfer information or claims between two parties. JWTs act like temporary copies of the user's ID card that allow Appwrite's Server SDKs to access information on behalf of a user.

You need to create a session using the Client SDKs **before** generating a JWT. The JWT will be a stateless proof of claim for the identity of the authenticated user and expire after 15 minutes or when the session is deleted.

You can generate a JWT like this on a [Client SDK](/docs/sdks#client).

{% multicode %}
```client-web
import { Client, Account } from "appwrite";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<PROJECT_ID>');                 // Your project ID

const account = new Account(client);

const user = await account.createJWT();
```
```client-flutter
import 'package:appwrite/appwrite.dart';

final client = Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<PROJECT_ID>');                 // Your project ID

final account = Account(client);

final jwt = await account.createJWT();
```
```client-apple
import Appwrite

let client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<PROJECT_ID>")                  // Your project ID

let account = Account(client)

let jwt = try await account.createJWT()
```
```client-android-kotlin
import io.appwrite.Client
import io.appwrite.services.Account

val client = Client(context)
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<PROJECT_ID>")                  // Your project ID

val account = Account(client)

val jwt = account.createJWT()
```
```graphql
mutation {
    accountCreateJWT {
        jwt
    }
}
```
{% /multicode %}

Your server application can use the JWT to act on behalf of the user by creating a `Client` instance with the JWT for **each request it receives**. To keep your API secure, **discard the client object** after each request.

Use JWTs tokens like this in a [Server SDK](/docs/sdks#server).
{% multicode %}
```js
const { Client } = require('node-appwrite');

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<PROJECT_ID>')                  // Your project ID
    .setJWT('eyJJ9.eyJ...886ca');                // Your secret JSON Web Token
```

```php
use Appwrite\Client;

$client = (new Client())
    ->setEndpoint('https://<REGION>.cloud.appwrite.io/v1')            // Your API Endpoint
    ->setProject('<PROJECT_ID>')                             // Your project ID
    ->setJWT('eyJJ9.eyJ...886ca');                           // Your secret JSON Web Token
```

```python
from appwrite.client import Client

client = Client()

(client
  .set_endpoint('https://<REGION>.cloud.appwrite.io/v1') # Your API Endpoint
  .set_project('<PROJECT_ID>')                  # Your project ID
  .set_jwt('eyJJ9.eyJ...886ca')                 # Your secret JSON Web Token
)
```

```ruby
require 'appwrite'

include Appwrite

client = Client.new
    .set_endpoint('https://<REGION>.cloud.appwrite.io/v1')   # Your API Endpoint
    .set_project('<PROJECT_ID>')                    # Your project ID
    .set_jwt('eyJJ9.eyJ...886ca')                   # Your secret JSON Web Token
```

```deno
import { Client } from "npm:node-appwrite";

let client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')    // Your API Endpoint
    .setProject('<PROJECT_ID>')                     // Your project ID
    .setJWT('eyJJ9.eyJ...886ca');                   // Your secret JSON Web Token
```

```dart
import 'package:dart_appwrite/dart_appwrite.dart';

final client = Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')    // Your API Endpoint
    .setProject('<PROJECT_ID>')                     // Your project ID
    .setJWT('eyJJ9.eyJ...886ca');                   // Your secret JSON Web Token
```

```kotlin
import io.appwrite.Client

val client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1")    // Your API Endpoint
    .setProject("<PROJECT_ID>")                     // Your project ID
    .setJWT("eyJJ9.eyJ...886ca")                    // Your secret JSON Web Token
```

```swift
import Appwrite

let client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1")    // Your API Endpoint
    .setProject("<PROJECT_ID>")                     // Your project ID
    .setJWT("eyJJ9.eyJ...886ca")                    // Your secret JSON Web Token
```

```csharp
using Appwrite;

var client = new Client()
    .SetEndpoint("https://<REGION>.cloud.appwrite.io/v1")    // Your API Endpoint
    .SetProject("<PROJECT_ID>")                     // Your project ID
    .SetJWT("eyJJ9.eyJ...886ca");                   // Your secret JSON Web Token
```
{% /multicode %}


# When should I use JWTs? {% #when-to-use %}

JWT auth is useful when you need your backend app's Server SDK to be restricted by the same set of permissions.

If your backend app's Server SDK is using an [API key](/docs/partners/project/api-keys), it will fetch **all resources** regardless of permissions. This means the Server SDK might fetch files and rows your user should not be able to see, which is not helpful when you need to act on behalf of a user.

If your backend app's Server SDK is using a **JWT**, it will only fetch resources your user has permissions to access.

# Example {% #when-to-use-example %}

Here's an example table of birthdays with the following rows. Notice how they all have **different permissions**.

| $id         | name  | birthday  | $permissions          |
|-------------|-------|-----------|------------------------|
| ac5fc866ad1e| Kevin | 2012-02-03| "read(\"user:user-a\")"|
| bc7fc866ad1e| Laura | 1999-09-22| "read(\"user:user-b\")"|
| cc2fc886ad1e| Bob   | 1982-05-11| "read(\"user:user-c\")"|

If you're authenticated on the client-side as `user-a` and created a JWT `'eyJJ9.eyJ...886ca'`, you can pass this JWT to a Server SDK on the backend server to fetch only the birthdays `user-a` can read.

{% multicode %}
```js
const { Client } = require('node-appwrite');

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<PROJECT_ID>')                  // Your project ID
    .setJWT('eyJJ9.eyJ...886ca');                // Your secret JSON Web Token

const tablesDB = new sdk.TablesDB(client);

const rows = await tablesDB.listRows({
    databaseId: '642f358bf4084c662590',
    tableId: '642f3592aa5fc856ad1e'
});
```
```php
use Appwrite\Client;

$client = (new Client())
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')            // Your API Endpoint
    .setProject('<PROJECT_ID>')                           // Your project ID
    .setJWT('eyJJ9.eyJ...886ca');                          // Your secret JSON Web Tokens

$tablesDB = new TablesDB($client);

$rows = $tablesDB->listRows(
    databaseId: '642f358bf4084c662590',
    tableId: '642f3592aa5fc856ad1e'
);
```
```python
from appwrite.client import Client

client = Client()

(client
  .set_endpoint('https://<REGION>.cloud.appwrite.io/v1')              # Your API Endpoint
  .set_project('<PROJECT_ID>')                             # Your project ID
  .set_jwt('eyJJ9.eyJ...886ca')                             # Your secret JSON Web Token
)

tablesDB = TablesDB(client)

rows = tablesDB.list_rows(
    database_id='642f358bf4084c662590',
    table_id='642f3592aa5fc856ad1e'
)
```
```ruby
require 'appwrite'

include Appwrite

client = Client.new
    .set_endpoint('https://<REGION>.cloud.appwrite.io/v1')            # Your API Endpoint
    .set_project('<PROJECT_ID>')                           # Your project ID
    .set_jwt('eyJJ9.eyJ...886ca')                           # Your secret JSON Web Token

tablesDB = TablesDB.new(client)

rows = tablesDB.list_rows(
    database_id: '642f358bf4084c662590',
    table_id: '642f3592aa5fc856ad1e'
)
```
```deno
import { Client } from "npm:node-appwrite";

let client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')    // Your API Endpoint
    .setProject('<PROJECT_ID>')                     // Your project ID
    .setJWT('eyJJ9.eyJ...886ca');                   // Your secret JSON Web Token

let tablesDB = new sdk.TablesDB(client);

let rows = await tablesDB.listRows({
    databaseId: '642f358bf4084c662590',
    tableId: '642f3592aa5fc856ad1e'
});
```
```dart
import 'package:dart_appwrite/dart_appwrite.dart';

final client = Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')    // Your API Endpoint
    .setProject('<PROJECT_ID>')                     // Your project ID
    .setJWT('eyJJ9.eyJ...886ca');                   // Your secret JSON Web Token

final tablesDB = TablesDB(client);

final rows = await tablesDB.listRows(
    databaseId: '642f358bf4084c662590',
    tableId: '642f3592aa5fc856ad1e',
);
```
```kotlin
import io.appwrite.Client

val client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1")    // Your API Endpoint
    .setProject("<PROJECT_ID>")                     // Your project ID
    .setJWT("eyJJ9.eyJ...886ca")                    // Your secret JSON Web Token

val tablesDB = TablesDB(client)

val rows = tablesDB.listRows(
    databaseId = "642f358bf4084c662590",
    tableId = "642f3592aa5fc856ad1e",
)
```
```swift
import Appwrite

let client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1")    // Your API Endpoint
    .setProject("<PROJECT_ID>")                     // Your project ID
    .setJWT("eyJJ9.eyJ...886ca")                    // Your secret JSON Web Token
    
let tablesDB = TablesDB(client)

let rows = try await tablesDB.listRows(
    databaseId: "642f358bf4084c662590",
    tableId: "642f3592aa5fc856ad1e"
)
```
```csharp
using Appwrite;
using Appwrite.Services;
using Appwrite.Models;

var client = new Client()
    .SetEndpoint("https://<REGION>.cloud.appwrite.io/v1")    // Your API Endpoint
    .SetProject("<PROJECT_ID>")                     // Your project ID
    .SetJWT("eyJJ9.eyJ...886ca");                   // Your secret JSON Web Token

var tablesDB = new TablesDB(client);

var rows = await databases.listRows(
    databaseId: "642f358bf4084c662590",
    tableId: "642f3592aa5fc856ad1e");

```

{% /multicode %}

Only Kevin's birthday is returned and rows where `user-A` has no permissions to access are not returned.

```js
{
  "total": 1,
  "rows": [
    {
      "name": "Kevin",
      "birthday": "2012-02-03T00:00:00.000+00:00",
      "$id": "ac5fc866ad1e",
      "$permissions": [
        "read(\"user:user-a\")"
      ],
      "$tableId": "642f3592aa5fc856ad1e",
      "$databaseId": "642f358bf4084c662590",
      ...
    }
  ]
}
```

If the same request is made where the [Server SDK](/docs/sdks#server)'s `client` is authenticated with an API key instead of a JWT, the results returned will be different.

{% multicode %}
```js
const { Client } = require('node-appwrite');

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<PROJECT_ID>')                  // Your project ID
    .setKey('98fd4...a2ad2');                    // Your secret API key

const tablesDB = new sdk.TablesDB(client);

const rows = await tablesDB.listRows({
    databaseId: '642f358bf4084c662590',
    tableId: '642f3592aa5fc856ad1e'
});
```

```php
use Appwrite\Client;

$client = (new Client())
    ->setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    ->setProject('<PROJECT_ID>')                  // Your project ID
    ->setKey('98fd4...a2ad2');                    // Your secret API key

$tablesDB = new TablesDB($client);

$rows = $tablesDB->listRows(
    databaseId: '642f358bf4084c662590',
    tableId: '642f3592aa5fc856ad1e'
);
```

```python
from appwrite.client import Client

client = Client()
(client
    .set_endpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .set_project('<PROJECT_ID>')                  // Your project ID
    .set_key('98fd4...a2ad2')                     // Your secret API key
)

tablesDB = TablesDB(client)

rows = tablesDB.list_rows(
    database_id='642f358bf4084c662590',
    table_id='642f3592aa5fc856ad1e'
)
```
```ruby
require 'appwrite'

include Appwrite

client = Client.new
    .set_endpoint('https://<REGION>.cloud.appwrite.io/v1') # Your API Endpoint
    .set_project('<PROJECT_ID>')                  # Your project ID
    .set_key('98fd4...a2ad2')                     # Your secret API key

tablesDB = TablesDB.new(client)

rows = tablesDB.list_rows(
    database_id: '642f358bf4084c662590',
    table_id: '642f3592aa5fc856ad1e'
)
```
```deno
import { Client } from "npm:node-appwrite";

let client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<PROJECT_ID>')                  // Your project ID
    .setKey('98fd4...a2ad2');                    // Your secret API key

let tablesDB = new sdk.TablesDB(client);

let rows = await tablesDB.listRows({
    databaseId: '642f358bf4084c662590',
    tableId: '642f3592aa5fc856ad1e'
});
```
```dart
import 'package:dart_appwrite/dart_appwrite.dart';

final client = Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<PROJECT_ID>')                  // Your project ID
    .setKey('98fd4...a2ad2');                    // Your secret API key

final tablesDB = TablesDB(client);

final rows = await tablesDB.listRows(
    databaseId: '642f358bf4084c662590',
    tableId: '642f3592aa5fc856ad1e',
);
```
```kotlin
import io.appwrite.Client

val client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<PROJECT_ID>")                  // Your project ID
    .setKey('98fd4...a2ad2');                    // Your secret API key

val tablesDB = TablesDB(client)

val rows = tablesDB.listRows(
    databaseId = "642f358bf4084c662590",
    tableId = "642f3592aa5fc856ad1e",
)
```
```swift
import Appwrite

let client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<PROJECT_ID>")                  // Your project ID
    .setKey('98fd4...a2ad2');                    // Your secret API key

let tablesDB = TablesDB(client)

let rows = try await tablesDB.listRows(
    databaseId: "642f358bf4084c662590",
    tableId: "642f3592aa5fc856ad1e"
)
```
```csharp
using Appwrite;
using Appwrite.Services;
using Appwrite.Models;

var client = new Client()
    .SetEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .SetProject("<PROJECT_ID>")                  // Your project ID
    .SetKey('98fd4...a2ad2');                    // Your secret API key

var tablesDB = new TablesDB(client);

var rows = await databases.listRows(
    databaseId: "642f358bf4084c662590",
    tableId: "642f3592aa5fc856ad1e");

```
{% /multicode %}

This will return every row regardless of permissions, which could lead to privacy and security problems.

```json
{
  "total": 3,
  "rows": [
    {
      "name": "Kevin",
      "birthday": "2012-02-03T00:00:00.000+00:00",
      "$id": "ac5fc866ad1e",
      "$permissions": [
        "read(\"user:user-a\")"
      ],
      "$tableId": "642f3592aa5fc856ad1e",
      "$databaseId": "642f358bf4084c662590",
      ...
    },
    {
      "name": "Laura",
      "birthday": "1999-09-22T11:21:23.334+00:00",
      "$id": "bc7fc866ad1e",
      "$permissions": [
        "read(\"user:user-b\")"
      ],
      "$tableId": "642f3592aa5fc856ad1e",
      "$databaseId": "642f358bf4084c662590",
      ...
    },
    {
      "name": "Bob",
      "birthday": "1982-05-11T12:31:39.381+00:00",
      "$id": "cc2fc886ad1e",
      "$permissions": [
        "read(\"user:user-c\")"
      ],
      "$tableId": "642f3592aa5fc856ad1e",
      "$databaseId": "642f358bf4084c662590",
      ...
    }
  ]
}
```

If you're integrating existing backend services with Appwrite or adding backend endpoints to perform more complex logic, JWT authentication helps them behave similarly to actual Appwrite endpoints.
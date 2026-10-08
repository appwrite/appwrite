---
layout: article
title: User impersonation
description: Let trusted operators act as another user in Appwrite Auth for support, QA, and troubleshooting while keeping the flow controlled and auditable.
---

User impersonation lets a trusted operator temporarily act as another user in the same Appwrite project, without sharing credentials. The operator signs in as themselves first, then sets a single impersonation target on the client. Appwrite resolves that target and executes requests using their permissions.

This is especially useful when you need to:

- Reproduce a bug that only appears for a specific user
- Verify permissions and feature access from the user's point of view
- Help customer support teams troubleshoot account issues
- Review onboarding or upgrade flows exactly as an end user sees them

# How it works {% #how-it-works %}

Impersonation follows four steps:

1. Enable the `impersonator` capability for a trusted operator.
2. Have that operator sign in normally using any supported Appwrite Auth flow.
3. Set exactly one impersonation target on the Appwrite client.
4. Appwrite resolves the target user and evaluates the request as that user.

{% info title="User authentication is required" %}
Impersonation only works on requests that are already authenticated as a user with impersonation enabled. In server-side flows, an API key alone is not enough. You must also set a valid operator session on the client.
{% /info %}

When impersonation is active, the returned user model includes `impersonatorUserId`. Your app can use this to show a visible banner or disable risky actions while someone is acting on behalf of another user.

# Enable impersonation for an operator {% #enable-impersonation %}

You can grant a user the impersonator capability in two ways:

- In the Appwrite Console under **Auth > Users**, open the user's profile and toggle on the impersonator capability
- Via the Users API, by setting the `impersonator` field to `true` on the user

{% multicode %}
```server-nodejs
const sdk = require('node-appwrite');

const client = new sdk.Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>') // Your project ID
    .setKey('<YOUR_API_KEY>'); // Your secret API key

const users = new sdk.Users(client);

const result = await users.updateImpersonator({
    userId: '<USER_ID>',
    impersonator: true
});
```

```server-deno
import * as sdk from "npm:node-appwrite";

const client = new sdk.Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>') // Your project ID
    .setKey('<YOUR_API_KEY>'); // Your secret API key

const users = new sdk.Users(client);

const result = await users.updateImpersonator({
    userId: '<USER_ID>',
    impersonator: true
});
```

```server-python
from appwrite.client import Client
from appwrite.services.users import Users

client = Client()
client.set_endpoint('https://<REGION>.cloud.appwrite.io/v1') # Your API Endpoint
client.set_project('<YOUR_PROJECT_ID>') # Your project ID
client.set_key('<YOUR_API_KEY>') # Your secret API key

users = Users(client)

result = users.update_impersonator(
    user_id='<USER_ID>',
    impersonator=True
)
```

```server-php
use Appwrite\Client;
use Appwrite\Services\Users;

$client = new Client();
$client
    ->setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    ->setProject('<YOUR_PROJECT_ID>') // Your project ID
    ->setKey('<YOUR_API_KEY>'); // Your secret API key

$users = new Users($client);

$result = $users->updateImpersonator(
    userId: '<USER_ID>',
    impersonator: true
);
```

```server-ruby
require 'appwrite'

include Appwrite

client = Client.new
    .set_endpoint('https://<REGION>.cloud.appwrite.io/v1') # Your API Endpoint
    .set_project('<YOUR_PROJECT_ID>') # Your project ID
    .set_key('<YOUR_API_KEY>') # Your secret API key

users = Users.new(client)

result = users.update_impersonator(
    user_id: '<USER_ID>',
    impersonator: true
)
```

```server-dotnet
using Appwrite;
using Appwrite.Services;

var client = new Client()
    .SetEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .SetProject("<YOUR_PROJECT_ID>") // Your project ID
    .SetKey("<YOUR_API_KEY>"); // Your secret API key

var users = new Users(client);

var result = await users.UpdateImpersonator(
    userId: "<USER_ID>",
    impersonator: true
);
```

```server-dart
import 'package:dart_appwrite/dart_appwrite.dart';

final client = Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>') // Your project ID
    .setKey('<YOUR_API_KEY>'); // Your secret API key

final users = Users(client);

final result = await users.updateImpersonator(
    userId: '<USER_ID>',
    impersonator: true
);
```

```server-swift
import Appwrite

let client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<YOUR_PROJECT_ID>") // Your project ID
    .setKey("<YOUR_API_KEY>") // Your secret API key

let users = Users(client)

let result = try await users.updateImpersonator(
    userId: "<USER_ID>",
    impersonator: true
)
```

```server-kotlin
import io.appwrite.Client
import io.appwrite.services.Users

val client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<YOUR_PROJECT_ID>") // Your project ID
    .setKey("<YOUR_API_KEY>") // Your secret API key

val users = Users(client)

val result = users.updateImpersonator(
    userId = "<USER_ID>",
    impersonator = true
)
```

```server-java
import io.appwrite.Client;
import io.appwrite.coroutines.CoroutineCallback;
import io.appwrite.services.Users;

Client client = new Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<YOUR_PROJECT_ID>") // Your project ID
    .setKey("<YOUR_API_KEY>"); // Your secret API key

Users users = new Users(client);

users.updateImpersonator(
    "<USER_ID>",
    true,
    new CoroutineCallback<>((result, error) -> {
        if (error != null) {
            error.printStackTrace();
            return null;
        }
        System.out.println(result);
        return null;
    })
);
```

```server-rust
use appwrite::Client;
use appwrite::services::users::Users;

#[tokio::main]
async fn main() -> Result<(), Box<dyn std::error::Error>> {
    let client = Client::new()
        .set_endpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
        .set_project("<YOUR_PROJECT_ID>")                 // Your project ID
        .set_key("<YOUR_API_KEY>");                        // Your secret API key

    let users = Users::new(&client);

    let result = users.update_impersonator(
        "<USER_ID>",
        true,
    ).await?;

    println!("{:?}", result);
    Ok(())
}
```
{% /multicode %}

Only grant this capability to internal users (support agents, QA engineers, or operations staff) who need to see the app exactly as a specific end user would.

{% info title="Recommended setup" %}
Create a dedicated internal operator account for each team member instead of sharing one support account.

This makes it easier to review internal audit activity and control access over time.
{% /info %}

When a user is marked as an impersonator, Appwrite also grants them the `users.read` scope.

This allows trusted operators to list users in the project, which is especially useful when building internal admin-style tools that let support or QA teams search for a user and choose who to impersonate next.

# Initialize an impersonated client {% #initialize-an-impersonated-client %}

After the operator signs in, initialize the Appwrite client as usual and set exactly one impersonation target. Only one target can be active per request; using more than one is not supported.

{% info title="SDK support" %}
Make sure you are using an Appwrite SDK version that supports impersonation. In client SDKs, the operator's session is persisted automatically after login, so enabling impersonation is just a matter of setting the right option on the client before making requests.
{% /info %}

# By user ID {% #impersonate-by-id %}

Impersonating by user ID is the most precise option. Use it when your internal tools already store the Appwrite user ID or when an operator selected a user from your support dashboard.

{% multicode %}

```client-web
import { Client, Account } from "appwrite";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>')
    .setImpersonateUserId('<TARGET_USER_ID>');

const account = new Account(client);
const user = await account.get();

if (user.impersonatorUserId) {
    console.log(`Impersonated by ${user.impersonatorUserId}`);
}
```

```client-flutter
import 'package:appwrite/appwrite.dart';

Client client = Client()
  .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
  .setProject('<PROJECT_ID>')
  .setImpersonateUserId('<TARGET_USER_ID>');

Account account = Account(client);
final user = await account.get();
print(user.name);
```

```client-apple
import Appwrite

let client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1")
    .setProject("<PROJECT_ID>")
    .setImpersonateUserId("<TARGET_USER_ID>")

let account = Account(client)
let user = try await account.get()
print(user.name)
```

```client-android-kotlin
import io.appwrite.Client
import io.appwrite.services.Account

val client = Client(context)
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1")
    .setProject("<PROJECT_ID>")
    .setImpersonateUserId("<TARGET_USER_ID>")

val account = Account(client)
val user = account.get()
println(user.name)
```

{% /multicode %}

# By email {% #impersonate-by-email %}

Impersonating by email is useful in admin panels and support workflows where operators search for users by email address first.

{% multicode %}

```client-web
import { Client, Account } from "appwrite";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>')
    .setImpersonateUserEmail('user@example.com');

const account = new Account(client);
const user = await account.get();
console.log(user.email);
```

```client-flutter
import 'package:appwrite/appwrite.dart';

Client client = Client()
  .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
  .setProject('<PROJECT_ID>')
  .setImpersonateUserEmail('user@example.com');

Account account = Account(client);
final user = await account.get();
print(user.email);
```

```client-apple
import Appwrite

let client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1")
    .setProject("<PROJECT_ID>")
    .setImpersonateUserEmail("user@example.com")

let account = Account(client)
let user = try await account.get()
print(user.email)
```

```client-android-kotlin
import io.appwrite.Client
import io.appwrite.services.Account

val client = Client(context)
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1")
    .setProject("<PROJECT_ID>")
    .setImpersonateUserEmail("user@example.com")

val account = Account(client)
val user = account.get()
println(user.email)
```

{% /multicode %}

# By phone {% #impersonate-by-phone %}

Impersonating by phone is helpful for support flows where the phone number is the primary identifier or when your app is centered around SMS-based authentication.

{% multicode %}

```client-web
import { Client, Account } from "appwrite";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>')
    .setImpersonateUserPhone('+12065550100');

const account = new Account(client);
const user = await account.get();
console.log(user.phone);
```

```client-flutter
import 'package:appwrite/appwrite.dart';

Client client = Client()
  .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
  .setProject('<PROJECT_ID>')
  .setImpersonateUserPhone('+12065550100');

Account account = Account(client);
final user = await account.get();
print(user.phone);
```

```client-apple
import Appwrite

let client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1")
    .setProject("<PROJECT_ID>")
    .setImpersonateUserPhone("+12065550100")

let account = Account(client)
let user = try await account.get()
print(user.phone)
```

```client-android-kotlin
import io.appwrite.Client
import io.appwrite.services.Account

val client = Client(context)
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1")
    .setProject("<PROJECT_ID>")
    .setImpersonateUserPhone("+12065550100")

val account = Account(client)
val user = account.get()
println(user.phone)
```

{% /multicode %}

# Choosing the right identifier {% #choosing-the-right-identifier %}

All three options result in the same impersonated session. The difference is which identifier your operator workflow has available:

- Use **user ID** when you already have the canonical Appwrite ID
- Use **email** when support teams search by email address
- Use **phone** when your app is centered around phone login or phone-based onboarding

Only set one impersonation value at a time. If you need to switch targets, create a fresh client or replace the previous impersonation value before continuing.

# Build safe support tooling {% #build-safe-support-tooling %}

Impersonation is most useful when you wrap it in explicit operator UX:

- Use the automatically granted `users.read` scope to build a user picker or searchable support view
- Show a clear banner while impersonation is active
- Display both the operator identity and the effective user identity
- Require an explicit action to start impersonation
- Let operators stop impersonating with one click
- Limit impersonation features to internal tools and trusted roles

# Security and visibility {% #security-and-visibility %}

Keep impersonation limited to trusted operators and internal tools.

Important behavior to know:

- Impersonation must start from a real user session, not an API key by itself.
- Users with impersonation enabled are automatically granted the `users.read` scope.
- The target user's permissions are used for the impersonated request.
- The user model includes `impersonator`, which indicates whether that user can impersonate others, and `impersonatorUserId`, which is present only during an active impersonation session and identifies the operator performing the impersonation.
- Internal audit logs attribute the action to the original impersonator and include the impersonated target in internal audit payload data.

If you build an internal admin panel, use `impersonatorUserId` to make the impersonated state obvious at all times.

# More resources {% #more-resources %}

- [Manage users with the Users API](/docs/products/auth/users)
- [REST API impersonation docs](/docs/apis/rest#impersonation-headers)
- [Users API reference](/docs/references/cloud/server-nodejs/users)

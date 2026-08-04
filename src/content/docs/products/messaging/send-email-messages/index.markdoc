---
layout: article
title: Send email messages
description: Send email messages to your users using Appwrite Messaging.
---
You can send custom email messages to your app's users using Appwrite Messaging and a connected SMTP service.
This guide takes you through the implementation path of adding email messaging to your app.

# Add a provider {% #add-a-provider %}
Appwrite supports [Mailgun](/docs/products/messaging/mailgun/), [Resend](/docs/products/messaging/resend/), and [Sendgrid](/docs/products/messaging/sendgrid/) as
SMTP providers. You must configure one of them as a provider.
{% only_dark %}
![Add a SMTP provider](/images/docs/messaging/providers/mailgun/dark/add-mailgun.avif)
{% /only_dark %}
{% only_light %}
![Add a SMTP provider](/images/docs/messaging/providers/mailgun/add-mailgun.avif)
{% /only_light %}
To add a new provider navigate to **Messaging** > **Providers** > {% icon icon="plus" size="m" /%} **Add provider** > **Email**
and follow the wizard. You can find more details about configuring in the provider guides for
[Mailgun](/docs/products/messaging/mailgun#configure-provider), [Resend](/docs/products/messaging/resend#configure-provider), and [Sendgrid](/docs/products/messaging/sendgrid#configure-provider).

# Add targets {% #add-targets %}
In Appwrite Messaging, each user has **targets** like their email, phone number, and devices with your app installed.
You can deliver messages to users through their **targets**.

{% only_dark %}
![Target overview](/images/docs/messaging/targets/dark/target-overview.avif)
{% /only_dark %}
{% only_light %}
![Target overview](/images/docs/messaging/targets/target-overview.avif)
{% /only_light %}

If the user signed up with email and password, their account would already have email as a target.
During development, you can add targets to existing accounts by navigating to **Authentication** > **Users** > **Select a user** > **Targets** > **Add a subscriber**.
{% only_dark %}
![Add a target](/images/docs/messaging/targets/dark/add-targets.avif)
{% /only_dark %}
{% only_light %}
![Add a target](/images/docs/messaging/targets/add-targets.avif)
{% /only_light %}

You can also implement forms in your app to collect contact information and add it as a target with the [createTarget](/docs/references/cloud/server-nodejs/users#createTarget) endpoint.
{% multicode %}
```server-nodejs
const sdk = require('node-appwrite');

const client = new sdk.Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')  // Your API Endpoint
    .setProject('<PROJECT_ID>')                  // Your project ID
    .setKey('919c2d18fb5d4...a2ae413da83346ad2');                  // Your secret API key

const users = new sdk.Users(client);

const target = await users.createTarget({
    userId: '<USER_ID>',
    targetId: '<TARGET_ID>',
    providerType: sdk.MessagingProviderType.Email,
    identifier: '<IDENTIFIER>',
    providerId: '<PROVIDER_ID>',
    name: '<NAME>'
});
```
```deno
import * as sdk from "npm:node-appwrite";

const client = new sdk.Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')    // Your API Endpoint
    .setProject('<PROJECT_ID>')                    // Your project ID
    .setJWT('eyJhbVCJ9.eyJ...');                    // Your secret JSON Web Token

const users = new sdk.Users(client);

const target = await users.createTarget({
    userId: '<USER_ID>',
    targetId: '<TARGET_ID>',
    providerType: sdk.MessagingProviderType.Email,
    identifier: '<IDENTIFIER>',
    providerId: '<PROVIDER_ID>',
    name: '<NAME>'
});
```
```php
<?php

use Appwrite\Client;
use Appwrite\Services\Users;
use Appwrite\Enums\MessagingProviderType;

$client = (new Client())
    ->setEndpoint('https://<REGION>.cloud.appwrite.io/v1')  // Your API Endpoint
    ->setProject('<PROJECT_ID>')                  // Your project ID
    ->setKey('919c2d18fb5d4...a2ae413da83346ad2'); // Your secret API key

$users = new Users($client);

$target = $users->createTarget(
    userId: '<USER_ID>',
    targetId: '<TARGET_ID>',
    providerType: MessagingProviderType::EMAIL(),
    identifier: '<IDENTIFIER>',
    providerId: '<PROVIDER_ID>',    // optional
    name: '<NAME>'                  // optional
);
```
```python
from appwrite.client import Client
from appwrite.enums import MessagingProviderType

client = Client()
client.set_endpoint('https://<REGION>.cloud.appwrite.io/v1') # Your API Endpoint
client.set_project('<PROJECT_ID>')                 # Your project ID
client.set_key('919c2d18fb5d4...a2ae413da83346ad2') # Your secret API key

users = Users(client)

target = users.create_target(
    user_id = '<USER_ID>',
    target_id = '<TARGET_ID>',
    provider_type = MessagingProviderType.EMAIL,
    identifier = '<IDENTIFIER>',
    provider_id = '<PROVIDER_ID>', # optional
    name = '<NAME>' # optional
)
```
```ruby
require 'appwrite'

include Appwrite
include Appwrite::Enums

client = Client.new
    .set_endpoint('https://<REGION>.cloud.appwrite.io/v1')   # Your API Endpoint
    .set_project('<PROJECT_ID>')                   # Your project ID
    .set_key('919c2d18fb5d4...a2ae413da83346ad2')   # Your secret API key

users = Users.new(client)

target = users.create_target(
    user_id: '<USER_ID>',
    target_id: '<TARGET_ID>',
    provider_type: MessagingProviderType::EMAIL,
    identifier: '<IDENTIFIER>',
    provider_id: '<PROVIDER_ID>',   # optional
    name: '<NAME>'                  # optional
)

puts target.inspect
```
```csharp
using Appwrite;
using Appwrite.Services;
using Appwrite.Models;
using Appwrite.Enums;

Client client = new Client()
    .SetEndPoint("https://<REGION>.cloud.appwrite.io/v1")  // Your API Endpoint
    .SetProject("<PROJECT_ID>")                  // Your project ID
    .SetKey("919c2d18fb5d4...a2ae413da83346ad2"); // Your secret API key

Users users = new Users(client);

Target target = await users.CreateTarget(
    userId: "<USER_ID>",
    targetId: "<TARGET_ID>",
    providerType: MessagingProviderType.Email,
    identifier: "<IDENTIFIER>",
    providerId: "<PROVIDER_ID>",  // optional
    name: "<NAME>"                // optional
);
```
```dart
import 'package:dart_appwrite/dart_appwrite.dart';
import 'package:dart_appwrite/enums.dart';
import 'package:dart_appwrite/models.dart';

Client client = Client()
  .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')  // Your API Endpoint
  .setProject('<PROJECT_ID>')                  // Your project ID
  .setKey('919c2d18fb5d4...a2ae413da83346ad2'); // Your secret API key

Users users = Users(client);

final target = await users.createTarget(
  userId: '<USER_ID>',
  targetId: '<TARGET_ID>',
  providerType:  MessagingProviderType.email,
  identifier: '<IDENTIFIER>',
  providerId: '<PROVIDER_ID>',  // (optional)
  name: '<NAME>',               // (optional)
);

```
```kotlin
import io.appwrite.Client
import io.appwrite.coroutines.CoroutineCallback
import io.appwrite.services.Users
import io.appwrite.enums.MessagingProviderType

val client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<PROJECT_ID>")                 // Your project ID
    .setKey("919c2d18fb5d4...a2ae413da83346ad2") // Your secret API key

val users = Users(client)

val target = users.createTarget(
    userId = "<USER_ID>",
    targetId = "<TARGET_ID>",
    providerType =  MessagingProviderType.EMAIL,
    identifier = "<IDENTIFIER>",
    providerId = "<PROVIDER_ID>",   // optional
    name = "<NAME>"                 // optional
)

```
```java
import io.appwrite.Client;
import io.appwrite.coroutines.CoroutineCallback;
import io.appwrite.services.Users;
import io.appwrite.enums.MessagingProviderType;

Client client = new Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1")  // Your API Endpoint
    .setProject("<PROJECT_ID>")                  // Your project ID
    .setKey("919c2d18fb5d4...a2ae413da83346ad2"); // Your secret API key

Users users = new Users(client);

users.createTarget(
    "<USER_ID>",                    // userId
    "<TARGET_ID>",                  // targetId
    MessagingProviderType.EMAIL,    // providerType
    "<IDENTIFIER>",                 // identifier
    "<PROVIDER_ID>",                // providerId (optional)
    "<NAME>",                       // name (optional)
    new CoroutineCallback<>((target, error) -> {
        if (error != null) {
            error.printStackTrace();
            return;
        }

        System.out.println(target);
    })
);
```
```swift
import Appwrite
import AppwriteEnums

let client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<PROJECT_ID>")                 // Your project ID
    .setKey("919c2d18fb5d4...a2ae413da83346ad2") // Your secret API key

let users = Users(client)

let target = try await users.createTarget(
    userId: "<USER_ID>",
    targetId: "<TARGET_ID>",
    providerType: .email,
    identifier: "<IDENTIFIER>",
    providerId: "<PROVIDER_ID>", // optional
    name: "<NAME>"               // optional
)
```
```server-rust
use appwrite::Client;
use appwrite::services::users::Users;
use appwrite::enums::messaging_provider_type::MessagingProviderType;

#[tokio::main]
async fn main() -> Result<(), Box<dyn std::error::Error>> {
    let client = Client::new()
        .set_endpoint("https://<REGION>.cloud.appwrite.io/v1")
        .set_project("<PROJECT_ID>")
        .set_key("919c2d18fb5d4...a2ae413da83346ad2");

    let users = Users::new(&client);

    let target = users.create_target(
        "<USER_ID>",
        "<TARGET_ID>",
        MessagingProviderType::Email,
        "<IDENTIFIER>",
        Some("<PROVIDER_ID>"),  // optional
        Some("<NAME>"),         // optional
    ).await?;

    println!("{:?}", target);
    Ok(())
}
```
{% /multicode %}
# Create topics (optional) {% #create-topics %}
You can use topics to organize targets that should receive the same messages, so you can send emails to groups of targets instead of one at time.
This step is optional if you plan to only send emails to individual targets.

To create a topic in the Appwrite Console, navigate to **Messaging** > **Topics** > {% icon icon="plus" size="m" /%} **Create topic**.
{% only_dark %}
![Add a target](/images/docs/messaging/topics/dark/create-topics.avif)
{% /only_dark %}
{% only_light %}
![Add a target](/images/docs/messaging/topics/create-topics.avif)
{% /only_light %}

You can also create topics programmatically using an [Appwrite Server SDK](/docs/references/cloud/server-nodejs/messaging#createTopic).
{% multicode %}
```server-nodejs
const sdk = require('node-appwrite');

// Init SDK
const client = new sdk.Client();

const messaging = new sdk.Messaging(client);

client
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<PROJECT_ID>')                 // Your project ID
    .setKey('919c2d18fb5d4...a2ae413da83346ad2') // Your secret API key
;

const topic = await messaging.createTopic({
    topicId: '<TOPIC_ID>',
    name: '<NAME>'
});
```
```deno
import * as sdk from "npm:node-appwrite";

// Init SDK
let client = new sdk.Client();

let messaging = new sdk.Messaging(client);

client
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<PROJECT_ID>')                 // Your project ID
    .setKey('919c2d18fb5d4...a2ae413da83346ad2') // Your secret API key
;

const topic = await messaging.createTopic({
    topicId: '<TOPIC_ID>',
    name: '<NAME>'
});
```
```php
<?php

use Appwrite\Client;
use Appwrite\Services\Messaging;

$client = new Client();

$client
    ->setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    ->setProject('<PROJECT_ID>')                 // Your project ID
    ->setKey('919c2d18fb5d4...a2ae413da83346ad2') // Your secret API key
;

$messaging = new Messaging($client);

$result = $messaging->createTopic(
    topicId: '<TOPIC_ID>',
    name: '<NAME>'
);
```
```python
from appwrite.client import Client
from appwrite.services.messaging import Messaging

client = Client()

(client
  .set_endpoint('https://<REGION>.cloud.appwrite.io/v1') # Your API Endpoint
  .set_project('<PROJECT_ID>')                 # Your project ID
  .set_key('919c2d18fb5d4...a2ae413da83346ad2') # Your secret API key
)

messaging = Messaging(client)

result = messaging.create_topic(
    topic_id = '<TOPIC_ID>',
    name = '<NAME>'
)
```
```ruby
require 'appwrite'

include Appwrite

client = Client.new
    .set_endpoint('https://<REGION>.cloud.appwrite.io/v1') # Your API Endpoint
    .set_project('<PROJECT_ID>')                 # Your project ID
    .set_key('919c2d18fb5d4...a2ae413da83346ad2') # Your secret API key

messaging = Messaging.new(client)

response = messaging.create_topic(
    topic_id: '<TOPIC_ID>',
    name: '<NAME>'
)

puts response.inspect
```
```csharp
using Appwrite;
using Appwrite.Services;
using Appwrite.Models;

var client = new Client()
    .SetEndPoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .SetProject("<PROJECT_ID>")                  // Your project ID
    .SetKey("919c2d18fb5d4...a2ae413da83346ad2"); // Your secret API key

var messaging = new Messaging(client);

Topic result = await messaging.CreateTopic(
    topicId: "<TOPIC_ID>",
    name: "<NAME>");
```
```dart
import 'package:dart_appwrite/dart_appwrite.dart';
import 'package:dart_appwrite/enums.dart';
import 'package:dart_appwrite/models.dart';

void main() async {                                 // Init SDK
  Client client = Client();
  Messaging messaging = Messaging(client);

  client
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<PROJECT_ID>')                 // Your project ID
    .setKey('919c2d18fb5d4...a2ae413da83346ad2') // Your secret API key
  ;

  Future result = await messaging.createTopic(
    topicId: '<TOPIC_ID>',
    name: '<NAME>',
  );

  result
    .then((response) {
      print(response);
    }).catchError((error) {
      print(error.response);
  });
}
```
```kotlin
import io.appwrite.Client;
import io.appwrite.coroutines.CoroutineCallback;
import io.appwrite.services.Messaging;

Client client = new Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<PROJECT_ID>")                  // Your project ID
    .setKey("919c2d18fb5d4...a2ae413da83346ad2"); // Your secret API key

Messaging messaging = new Messaging(client);

messaging.createTopic(
    "<TOPIC_ID>",                                 // topicId
    "<NAME>"                                      // name
    new CoroutineCallback<>((result, error) -> {
        if (error != null) {
            error.printStackTrace();
            return;
        }

        System.out.println(result);
    })
);
```
```java
import io.appwrite.Client;
import io.appwrite.coroutines.CoroutineCallback;
import io.appwrite.services.Messaging;

Client client = new Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<PROJECT_ID>")                  // Your project ID
    .setKey("919c2d18fb5d4...a2ae413da83346ad2"); // Your secret API key

Messaging messaging = new Messaging(client);

messaging.createTopic(
    "<TOPIC_ID>",                                 // topicId
    "<NAME>"                                      // name
    new CoroutineCallback<>((result, error) -> {
        if (error != null) {
            error.printStackTrace();
            return;
        }

        System.out.println(result);
    })
);
```
```swift
import Appwrite

let client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<PROJECT_ID>")                 // Your project ID
    .setKey("919c2d18fb5d4...a2ae413da83346ad2") // Your secret API key

let messaging = Messaging(client)

let topic = try await messaging.createTopic(
  topicId: "<TOPIC_ID>",
  name: "<NAME>"
)
```
```server-rust
use appwrite::Client;
use appwrite::services::messaging::Messaging;

#[tokio::main]
async fn main() -> Result<(), Box<dyn std::error::Error>> {
    let client = Client::new()
        .set_endpoint("https://<REGION>.cloud.appwrite.io/v1")
        .set_project("<PROJECT_ID>")
        .set_key("919c2d18fb5d4...a2ae413da83346ad2");

    let messaging = Messaging::new(&client);

    let topic = messaging.create_topic(
        "<TOPIC_ID>",
        "<NAME>",
        None, // subscribe (optional)
    ).await?;

    println!("{:?}", topic);
    Ok(())
}
```
{% /multicode %}

# Send emails {% #send-emails %}
You can send emails using a Server SDK. 
To send an email immediately, you can call the `createEmail` endpoint with `schedule` left empty.
{% multicode %}
```server-nodejs
const sdk = require('node-appwrite');

// Init SDK
const client = new sdk.Client();

const messaging = new sdk.Messaging(client);

client
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<PROJECT_ID>')                 // Your project ID
    .setKey('919c2d18fb5d4...a2ae413da83346ad2') // Your secret API key
;

const message = await messaging.createEmail({
    messageId: '<MESSAGE_ID>',
    subject: '<SUBJECT>',
    content: '<CONTENT>',
    topics: [],
    users: [],
    targets: [],
    cc: [],
    bcc: [],
    attachments: [],
    draft: false,
    html: false,
    scheduledAt: ''
});
```
```deno
import * as sdk from "npm:node-appwrite";

// Init SDK
let client = new sdk.Client();

let messaging = new sdk.Messaging(client);

client
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<PROJECT_ID>')                 // Your project ID
    .setKey('919c2d18fb5d4...a2ae413da83346ad2') // Your secret API key
;

const message = await messaging.createEmail({
    messageId: '<MESSAGE_ID>',
    subject: '<SUBJECT>',
    content: '<CONTENT>',
    topics: [],
    users: [],
    targets: [],
    cc: [],
    bcc: [],
    attachments: [],
    draft: false,
    html: false,
    scheduledAt: ''
});
```
```php
<?php

use Appwrite\Client;
use Appwrite\Services\Messaging;

$client = new Client();

$client
    ->setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    ->setProject('<PROJECT_ID>')                 // Your project ID
    ->setKey('919c2d18fb5d4...a2ae413da83346ad2') // Your secret API key
;

$messaging = new Messaging($client);

$result = $messaging->createEmail(
    messageId: '<MESSAGE_ID>',
    subject: '<SUBJECT>',
    content: '<CONTENT>',
    topics: [],                                   // optional
    users: [],                                    // optional
    targets: [],                                  // optional
    cc: [],                                       // optional
    bcc: [],                                      // optional
    draft: false,                                 // optional
    html: false,                                  // optional
    scheduledAt: ''                               // optional
);
```
```python
from appwrite.client import Client
from appwrite.services.messaging import Messaging

client = Client()

(client
  .set_endpoint('https://<REGION>.cloud.appwrite.io/v1') # Your API Endpoint
  .set_project('<PROJECT_ID>')                 # Your project ID
  .set_key('919c2d18fb5d4...a2ae413da83346ad2') # Your secret API key
)

messaging = Messaging(client)

result = messaging.create_email(
    message_id = '<MESSAGE_ID>',
    subject = '<SUBJECT>',
    content = '<CONTENT>',
    topics = [],                                # optional
    users = [],                                 # optional
    targets = [],                               # optional
    cc = [],                                    # optional
    bcc = [],                                   # optional
    draft = False,                              # optional
    html = False,                               # optional
    scheduled_at = ''                           # optional
)
```
```ruby
require 'appwrite'

include Appwrite

client = Client.new
    .set_endpoint('https://<REGION>.cloud.appwrite.io/v1') # Your API Endpoint
    .set_project('<PROJECT_ID>')                 # Your project ID
    .set_key('919c2d18fb5d4...a2ae413da83346ad2') # Your secret API key

messaging = Messaging.new(client)

response = messaging.create_email(
    message_id: '<MESSAGE_ID>',
    subject: '<SUBJECT>',
    content: '<CONTENT>',
    topics: [],                                   # optional
    users: [],                                    # optional
    targets: [],                                  # optional
    cc: [],                                       # optional
    bcc: [],                                      # optional
    draft: false,                                 # optional
    html: false,                                  # optional
    scheduled_at: ''                              # optional
)

puts response.inspect
```
```csharp
using Appwrite;
using Appwrite.Services;
using Appwrite.Models;

var client = new Client()
    .SetEndPoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .SetProject("<PROJECT_ID>")                  // Your project ID
    .SetKey("919c2d18fb5d4...a2ae413da83346ad2"); // Your secret API key

var messaging = new Messaging(client);

Message result = await messaging.CreateEmail(
    messageId: "<MESSAGE_ID>",
    subject: "<SUBJECT>",
    content: "<CONTENT>"    
    topics: new List<string> {}                   // optional    
    users: new List<string> {}                    // optional    
    targets: new List<string> {}                  // optional    
    cc: new List<string> {}                       // optional    
    bcc: new List<string> {}                      // optional    
    draft: false                                  // optional    
    html: false                                   // optional    
    scheduledAt: "");                             // optional
```
```dart
import 'package:dart_appwrite/dart_appwrite.dart';
import 'package:dart_appwrite/enums.dart';
import 'package:dart_appwrite/models.dart';

void main() {                                    // Init SDK
  Client client = Client();
  Messaging messaging = Messaging(client);

  client
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<PROJECT_ID>')                 // Your project ID
    .setKey('919c2d18fb5d4...a2ae413da83346ad2') // Your secret API key
  ;

  Future result = messaging.createEmail(
    messageId: '<MESSAGE_ID>',
    subject: '<SUBJECT>',
    content: '<CONTENT>',
    topics: [],                                  // optional
    users: [],                                   // optional
    targets: [],                                 // optional
    cc: [],                                      // optional
    bcc: [],                                     // optional
    draft: false,                                // optional
    html: false,                                 // optional
    scheduledAt: '',                             // optional
  );

  result
    .then((response) {
      print(response);
    }).catchError((error) {
      print(error.response);
  });
}
```
```kotlin
import io.appwrite.Client;
import io.appwrite.coroutines.CoroutineCallback;
import io.appwrite.services.Messaging;

Client client = new Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<PROJECT_ID>")                  // Your project ID
    .setKey("919c2d18fb5d4...a2ae413da83346ad2"); // Your secret API key

Messaging messaging = new Messaging(client);

messaging.createEmail(
    "<MESSAGE_ID>",                               // messageId
    "<SUBJECT>",                                  // subject
    "<CONTENT>",                                  // content
    listOf(),                                     // topics (optional)
    listOf(),                                     // users (optional)
    listOf(),                                     // targets (optional)
    listOf(),                                     // cc (optional)
    listOf(),                                     // bcc (optional)
    false,                                        // draft (optional)
    false,                                        // html (optional)
    ""                                            // scheduledAt (optional)
    new CoroutineCallback<>((result, error) -> {
        if (error != null) {
            error.printStackTrace();
            return;
        }

        System.out.println(result);
    })
);
```
```java
import io.appwrite.Client;
import io.appwrite.coroutines.CoroutineCallback;
import io.appwrite.services.Messaging;

Client client = new Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1")  // Your API Endpoint
    .setProject("<PROJECT_ID>")                  // Your project ID
    .setKey("919c2d18fb5d4...a2ae413da83346ad2"); // Your secret API key

Messaging messaging = new Messaging(client);

messaging.createEmail(
    "<MESSAGE_ID>",   // messageId
    "<SUBJECT>",      // subject
    "<CONTENT>",      // content
    listOf(),         // topics (optional)
    listOf(),         // users (optional)
    listOf(),         // targets (optional)
    listOf(),         // cc (optional)
    listOf(),         // bcc (optional)
    false,            // draft (optional)
    false,            // html (optional)
    ""                // scheduledAt (optional)
    new CoroutineCallback<>((result, error) -> {
        if (error != null) {
            error.printStackTrace();
            return;
        }

        System.out.println(result);
    })
);
```
```swift
import Appwrite

let client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<PROJECT_ID>")                 // Your project ID
    .setKey("919c2d18fb5d4...a2ae413da83346ad2") // Your secret API key

let messaging = Messaging(client)

let message = try await messaging.createEmail(
  messageId: "<MESSAGE_ID>",
  subject: "<SUBJECT>",
  content: "<CONTENT>",
  topics: [],                                    // optional
  users: [],                                     // optional
  targets: [],                                   // optional
  cc: [],                                        // optional
  bcc: [],                                       // optional
  draft: false,                                  // optional
  html: xfalse,                                  // optional
  scheduledAt: ""                                // optional
)
```
```server-rust
use appwrite::Client;
use appwrite::services::messaging::Messaging;

#[tokio::main]
async fn main() -> Result<(), Box<dyn std::error::Error>> {
    let client = Client::new()
        .set_endpoint("https://<REGION>.cloud.appwrite.io/v1")
        .set_project("<PROJECT_ID>")
        .set_key("919c2d18fb5d4...a2ae413da83346ad2");

    let messaging = Messaging::new(&client);

    let message = messaging.create_email(
        "<MESSAGE_ID>",
        "<SUBJECT>",
        "<CONTENT>",
        Some(vec![]),       // topics (optional)
        Some(vec![]),       // users (optional)
        Some(vec![]),       // targets (optional)
        Some(vec![]),       // cc (optional)
        Some(vec![]),       // bcc (optional)
        None,               // attachments (optional)
        Some(false),        // draft (optional)
        Some(false),        // html (optional)
        None,               // scheduled_at (optional)
    ).await?;

    println!("{:?}", message);
    Ok(())
}
```
{% /multicode %}


# Schedule emails {% #schedule-emails %}
To send a scheduled email, you can call the `createEmail` endpoint with `status` set to `'scheduled'` and `schedule` as a ISO 8601 date time string for the scheduled time.
{% multicode %}
```server-nodejs
const sdk = require('node-appwrite');

// Init SDK
const client = new sdk.Client();

const messaging = new sdk.Messaging(client);

client
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<PROJECT_ID>')                 // Your project ID
    .setKey('919c2d18fb5d4...a2ae413da83346ad2') // Your secret API key
;

const message = await messaging.createEmail({
    messageId: '<MESSAGE_ID>',
    subject: '<SUBJECT>',
    content: '<CONTENT>',
    topics: [],
    users: [],
    targets: [],
    cc: [],
    bcc: [],
    draft: false,
    html: false,
    scheduledAt: '2025-02-13T22:01:00+0000'
});
```
```deno
import * as sdk from "npm:node-appwrite";

// Init SDK
let client = new sdk.Client();

let messaging = new sdk.Messaging(client);

client
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<PROJECT_ID>')                 // Your project ID
    .setKey('919c2d18fb5d4...a2ae413da83346ad2') // Your secret API key
;

const message = await messaging.createEmail({
    messageId: '<MESSAGE_ID>',
    subject: '<SUBJECT>',
    content: '<CONTENT>',
    topics: [],
    users: [],
    targets: [],
    cc: [],
    bcc: [],
    draft: false,
    html: false,
    scheduledAt: '2025-02-13T22:01:00+0000'
});
```
```php
<?php

use Appwrite\Client;
use Appwrite\Services\Messaging;

$client = new Client();

$client
    ->setEndpoint('https://<REGION>.cloud.appwrite.io/v1')  // Your API Endpoint
    ->setProject('<PROJECT_ID>')                  // Your project ID
    ->setKey('919c2d18fb5d4...a2ae413da83346ad2')  // Your secret API key
;

$messaging = new Messaging($client);

$result = $messaging->createEmail( 
    messageId: '<MESSAGE_ID>', 
    subject: '<SUBJECT>', 
    content: '<CONTENT>', 
    topics: [],       // optional
    users: [],        // optional
    targets: [],      // optional
    cc: [],           // optional
    bcc: [],          // optional
    draft: false,     // optional
    html: false,      // optional
    scheduledAt: '2025-02-13T22:01:00+0000'
);
```
```python
from appwrite.client import Client
from appwrite.services.messaging import Messaging

client = Client()

(client
  .set_endpoint('https://<REGION>.cloud.appwrite.io/v1') # Your API Endpoint
  .set_project('<PROJECT_ID>')                 # Your project ID
  .set_key('919c2d18fb5d4...a2ae413da83346ad2') # Your secret API key
)

messaging = Messaging(client)

result = messaging.create_email( 
    message_id = '<MESSAGE_ID>', 
    subject = '<SUBJECT>', 
    content = '<CONTENT>', 
    topics = [],                # optional
    users = [],                 # optional
    targets = [],               # optional
    cc = [],                    # optional
    bcc = [],                   # optional
    draft = False,              # optional
    html = False,               # optional
    scheduled_at = '2025-02-13T22:01:00+0000'
)
```
```ruby
require 'appwrite'

include Appwrite

client = Client.new
    .set_endpoint('https://<REGION>.cloud.appwrite.io/v1') # Your API Endpoint
    .set_project('<PROJECT_ID>')                 # Your project ID
    .set_key('919c2d18fb5d4...a2ae413da83346ad2') # Your secret API key

messaging = Messaging.new(client)

response = messaging.create_email(
    message_id: '<MESSAGE_ID>', 
    subject: '<SUBJECT>', 
    content: '<CONTENT>', 
    topics: [],        # optional
    users: [],         # optional
    targets: [],       # optional
    cc: [],            # optional
    bcc: [],           # optional
    draft: false,      # optional
    html: false,       # optional
    scheduled_at: '2025-02-13T22:01:00+0000'    
)

puts response.inspect
```
```csharp
using Appwrite;
using Appwrite.Services;
using Appwrite.Models;

var client = new Client()
    .SetEndPoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .SetProject("<PROJECT_ID>")                 // Your project ID
    .SetKey("919c2d18fb5d4...a2ae413da83346ad2");// Your secret API key

var messaging = new Messaging(client);

Message result = await messaging.CreateEmail(
    messageId: "<MESSAGE_ID>",
    subject: "<SUBJECT>",
    content: "<CONTENT>"    
    topics: new List<string> {}    // optional    
    users: new List<string> {}     // optional    
    targets: new List<string> {}   // optional    
    cc: new List<string> {}        // optional    
    bcc: new List<string> {}       // optional    
    draft: false                   // optional    
    html: false                    // optional    
    scheduledAt: "2025-02-13T22:01:00+0000");     // optional
```
```dart
import 'package:dart_appwrite/dart_appwrite.dart';
import 'package:dart_appwrite/enums.dart';
import 'package:dart_appwrite/models.dart';

void main() {                                          // Init SDK
  Client client = Client();
  Messaging messaging = Messaging(client);

  client
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<PROJECT_ID>')                 // Your project ID
    .setKey('919c2d18fb5d4...a2ae413da83346ad2') // Your secret API key
  ;

  Future result = messaging.createEmail(
    messageId: '<MESSAGE_ID>',
    subject: '<SUBJECT>',
    content: '<CONTENT>',
    topics: [],         // optional
    users: [],          // optional
    targets: [],        // optional
    cc: [],             // optional
    bcc: [],            // optional
    draft: false,       // optional
    html: false,        // optional
    scheduledAt: '2025-02-13T22:01:00+0000',     
  );

  result
    .then((response) {
      print(response);
    }).catchError((error) {
      print(error.response);
  });
}
```
```kotlin
import io.appwrite.Client;
import io.appwrite.coroutines.CoroutineCallback;
import io.appwrite.services.Messaging;

Client client = new Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<PROJECT_ID>")                       // Your project ID
    .setKey("919c2d18fb5d4...a2ae413da83346ad2");      // Your secret API key

Messaging messaging = new Messaging(client);

messaging.createEmail(
    "<MESSAGE_ID>",  // messageId
    "<SUBJECT>",     // subject
    "<CONTENT>",     // content
    listOf(),        // topics (optional)
    listOf(),        // users (optional)
    listOf(),        // targets (optional)
    listOf(),        // cc (optional)
    listOf(),        // bcc (optional)
    false,           // draft (optional)
    false,           // html (optional)
    "2025-02-13T22:01:00+0000"      // scheduledAt (optional)
    new CoroutineCallback<>((result, error) -> {
        if (error != null) {
            error.printStackTrace();
            return;
        }

        System.out.println(result);
    })
);
```
```java
import io.appwrite.Client;
import io.appwrite.coroutines.CoroutineCallback;
import io.appwrite.services.Messaging;

Client client = new Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<PROJECT_ID>")                 // Your project ID
    .setKey("919c2d18fb5d4...a2ae413da83346ad2");// Your secret API key

Messaging messaging = new Messaging(client);

messaging.createEmail(
    "<MESSAGE_ID>",    // messageId
    "<SUBJECT>",       // subject
    "<CONTENT>",       // content
    listOf(),          // topics (optional)
    listOf(),          // users (optional)
    listOf(),          // targets (optional)
    listOf(),          // cc (optional)
    listOf(),          // bcc (optional)
    false,             // draft (optional)
    false,             // html (optional)
    "2025-02-13T22:01:00+0000"                         
    new CoroutineCallback<>((result, error) -> {
        if (error != null) {
            error.printStackTrace();
            return;
        }

        System.out.println(result);
    })
);
```
```swift
import Appwrite

let client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<PROJECT_ID>")                 // Your project ID
    .setKey("919c2d18fb5d4...a2ae413da83346ad2") // Your secret API key

let messaging = Messaging(client)

let message = try await messaging.createEmail(
  messageId: "<MESSAGE_ID>",
  subject: "<SUBJECT>",
  content: "<CONTENT>",
  topics: [],           // optional
  users: [],            // optional
  targets: [],          // optional
  cc: [],               // optional
  bcc: [],              // optional
  draft: false,         // optional
  html: xfalse,         // optional
  scheduledAt: "2025-02-13T22:01:00+0000"
)
```
```server-rust
use appwrite::Client;
use appwrite::services::messaging::Messaging;

#[tokio::main]
async fn main() -> Result<(), Box<dyn std::error::Error>> {
    let client = Client::new()
        .set_endpoint("https://<REGION>.cloud.appwrite.io/v1")
        .set_project("<PROJECT_ID>")
        .set_key("919c2d18fb5d4...a2ae413da83346ad2");

    let messaging = Messaging::new(&client);

    let message = messaging.create_email(
        "<MESSAGE_ID>",
        "<SUBJECT>",
        "<CONTENT>",
        Some(vec![]),                          // topics (optional)
        Some(vec![]),                          // users (optional)
        Some(vec![]),                          // targets (optional)
        Some(vec![]),                          // cc (optional)
        Some(vec![]),                          // bcc (optional)
        None,                                  // attachments (optional)
        Some(false),                           // draft (optional)
        Some(false),                           // html (optional)
        Some("2025-02-13T22:01:00+0000"),      // scheduled_at (optional)
    ).await?;

    println!("{:?}", message);
    Ok(())
}
```
{% /multicode %}

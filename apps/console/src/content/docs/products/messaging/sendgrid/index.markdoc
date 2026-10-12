---
layout: article
title: SendGrid
description: Send emails to your Appwrite users using SendGrid and Appwrite Messaging.
back: /docs/
---
SendGrid lets you send customized email messages to your users.
These emails can be sent immediately or scheduled.
You can send emails for purposes like reminders, promotions, announcements, and even custom authentication flows.

{% section #add-provider step=1 title="Add provider" %}

To add SendGrid as a provider, navigate to **Messaging** > **Providers** > {% icon icon="plus" size="m" /%} **Add provider** > **Email**.
{% only_dark %}
![Add a SMTP provider](/images/docs/messaging/providers/sendgrid/dark/add-sendgrid.avif)
{% /only_dark %}
{% only_light %}
![Add a SMTP provider](/images/docs/messaging/providers/sendgrid/add-sendgrid.avif)
{% /only_light %}

Give your provider a name > choose **SendGrid** > click **Save and continue**.
The provider will be saved to your project, but not enabled until you complete its configuration.
{% /section %}
{% section #configure-provider step=2 title="Configure provider" %}

In the **Configure** step, you will need to provide details from your SendGrid dashboard to connect your Appwrite project.

{% only_dark %}
![Configure SMTP provider](/images/docs/messaging/providers/sendgrid/dark/configure-sendgrid.avif)
{% /only_dark %}
{% only_light %}
![Configure SMTP provider](/images/docs/messaging/providers/sendgrid/configure-sendgrid.avif)
{% /only_light %}
You will need to provide the following information from your **SendGrid dashboard**.

{% table %}
* Field name
*
---
* API key
* Head to Settings -> API Keys -> Create API Key.
---
* Sender email
* The provider sends emails from this sender email. The sender email must either be an email under an [authenticated domain](https://www.twilio.com/docs/sendgrid/ui/account-and-settings/how-to-set-up-domain-authentication) or a [verified sender identity](https://www.twilio.com/docs/sendgrid/ui/sending-email/sender-verification).
---
* Sender name
* The sender name that appears in the emails sent from this provider.
---
* Reply-to email
* The reply-to email that appears in the emails sent from this provider. The reply-to email must either be an email under an authenticated domain or a verified sender identity.
---
* Reply-to name
* The reply-to name that appears in the emails sent from this provider.
{% /table %}

After adding the following details, click **Save and continue** to enable the provider.
{% /section %}

{% section #test-provider step=3 title="Test provider" %}
Before sending your first message,
make sure you've configured [a topic](/docs/products/messaging/topics) and [a target](/docs/products/messaging/targets) to send messages to.
{% tabs %}
{% tabsitem #console title="Console" %}
To send a test message, navigate to **Messaging** > **Messages** > {% icon icon="plus" size="m" /%} **Create message** > **Email**.
{% only_dark %}
![Create email message](/images/docs/messaging/messages/dark/create-email-message.avif)
{% /only_dark %}
{% only_light %}
![Create email message](/images/docs/messaging/messages/create-email-message.avif)
{% /only_light %}

Add your message and in the targets step, select one of your test targets. Set the schedule to **Now** and click **Send**.

Verify that you can receive the message in your inbox. If not, check for logs in the Appwrite Console or in your provider's logs.
{% /tabsitem %}

{% tabsitem #server-sdk title="Server SDK" %}
To send a message programmatically, use an [Appwrite Server SDK](/docs/sdks#server).
{% multicode %}
```server-nodejs
const sdk = require('node-appwrite');

// Init SDK
const client = new sdk.Client();

const messaging = new sdk.Messaging(client);

client
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<PROJECT_ID>') // Your project ID
    .setKey('<API_KEY>') // Your secret API key
;

const message = await messaging.createEmail({
  messageId: '<MESSAGE_ID>',
  subject: '<SUBJECT>',
  content: '<CONTENT>'
});
```
```deno
import * as sdk from "npm:node-appwrite";

// Init SDK
let client = new sdk.Client();

let messaging = new sdk.Messaging(client);

client
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<PROJECT_ID>') // Your project ID
    .setKey('<API_KEY>') // Your secret API key
;


const message = await messaging.createEmail({
  messageId: '<MESSAGE_ID>',
  subject: '<SUBJECT>',
  content: '<CONTENT>'
});
```
```php
<?php

use Appwrite\Client;
use Appwrite\Services\Messaging;

$client = new Client();

$client
    ->setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    ->setProject('<PROJECT_ID>') // Your project ID
    ->setKey('<API_KEY>') // Your secret API key
;

$messaging = new Messaging($client);

$result = $messaging->createEmail('<MESSAGE_ID>', '<SUBJECT>', '<CONTENT>');
```
```python
from appwrite.client import Client

client = Client()

(client
  .set_endpoint('https://<REGION>.cloud.appwrite.io/v1') # Your API Endpoint
  .set_project('<PROJECT_ID>') # Your project ID
  .set_key('<API_KEY>') # Your secret API key
)

messaging = Messaging(client)

result = messaging.create_email(
    message_id='<MESSAGE_ID>',
    subject='<SUBJECT>',
    content='<CONTENT>'
)
```
```ruby
require 'appwrite'

include Appwrite

client = Client.new
    .set_endpoint('https://<REGION>.cloud.appwrite.io/v1') # Your API Endpoint
    .set_project('<PROJECT_ID>') # Your project ID
    .set_key('<API_KEY>') # Your secret API key

messaging = Messaging.new(client)

response = messaging.create_email(message_id: '<MESSAGE_ID>', subject: '<SUBJECT>', content: '<CONTENT>')

puts response.inspect
```
```csharp
using Appwrite;
using Appwrite.Services;
using Appwrite.Models;
using Appwrite.Enums;
using Appwrite.Enums;
using Appwrite.Enums;

var client = new Client()
    .SetEndPoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .SetProject("<PROJECT_ID>") // Your project ID
    .SetKey("<API_KEY>"); // Your secret API key

var messaging = new Messaging(client);

Message result = await messaging.CreateEmail(
    messageId: "<MESSAGE_ID>",
    subject: "<SUBJECT>",
    content: "<CONTENT>");
```
```dart
import 'package:dart_appwrite/dart_appwrite.dart';
import 'package:dart_appwrite/enums.dart';
import 'package:dart_appwrite/models.dart';

void main() async { // Init SDK
  Client client = Client();
  Messaging messaging = Messaging(client);

  client
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<PROJECT_ID>') // Your project ID
    .setKey('<API_KEY>') // Your secret API key
  ;

  Future result = await messaging.createEmail(
    messageId:'<MESSAGE_ID>' ,
    subject:'<SUBJECT>' ,
    content:'<CONTENT>' ,
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
import io.appwrite.Client
import io.appwrite.coroutines.CoroutineCallback
import io.appwrite.services.Messaging

val client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<PROJECT_ID>") // Your project ID
    .setKey("<API_KEY>") // Your secret API key

val messaging = Messaging(client)

val response = messaging.createEmail(
    messageId = "<MESSAGE_ID>",
    subject = "<SUBJECT>",
    content = "<CONTENT>",
)
```
```java
import io.appwrite.Client;
import io.appwrite.coroutines.CoroutineCallback;
import io.appwrite.services.Messaging;

Client client = new Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<PROJECT_ID>") // Your project ID
    .setKey("<API_KEY>"); // Your secret API key

Messaging messaging = new Messaging(client);

messaging.createEmail(
    "<MESSAGE_ID>",
    "<SUBJECT>",
    "<CONTENT>",
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
    .setProject("<PROJECT_ID>") // Your project ID
    .setKey("<API_KEY>") // Your secret API key

let messaging = Messaging(client)

let message = try await messaging.createEmail(
    messageId: "<MESSAGE_ID>",
    subject: "<SUBJECT>",
    content: "<CONTENT>"
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
        .set_key("<API_KEY>");

    let messaging = Messaging::new(&client);

    let message = messaging.create_email(
        "<MESSAGE_ID>",                              // messageId
        "<SUBJECT>",                                 // subject
        "<CONTENT>",                                 // content
        None,                                        // topics (optional)
        None,                                        // users (optional)
        None,                                        // targets (optional)
        None,                                        // cc (optional)
        None,                                        // bcc (optional)
        None,                                        // attachments (optional)
        None,                                        // draft (optional)
        None,                                        // html (optional)
        None,                                        // scheduledAt (optional)
    ).await?;

    println!("{:?}", message);
    Ok(())
}
```
{% /multicode %}
{% /tabsitem %}
{% /tabs %}

You can follow the [Send email messages](/docs/products/messaging/send-email-messages) guide to send your first push notification and test your provider.
{% /section %}

{% section #manage-provider step=4 title="Manage provider" %}
{% tabs %}
{% tabsitem #console title="Console" %}
You can update or delete a provider in the Appwrite Console.

Navigate to **Messaging** > **Providers** > click your provider.
In the settings, you can update a provider's configuration or delete the provider.
{% /tabsitem %}

{% tabsitem #server-sdk title="Server SDK" %}
To update or delete providers programmatically, use an [Appwrite Server SDK](/docs/sdks#server).
{% multicode %}
```server-nodejs
const sdk = require('node-appwrite');

// Init SDK
const client = new sdk.Client();

const messaging = new sdk.Messaging(client);

client
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<PROJECT_ID>') // Your project ID
    .setKey('<YOUR_API_KEY>')        // Your secret API key
;

const provider = await messaging.updateSendgridProvider({
    providerId: '<PROVIDER_ID>',
    name: '<NAME>',
    enabled: false,
    apiKey: '<API_KEY>',
    fromName: '<FROM_NAME>',
    fromEmail: 'email@example.com',
    replyToName: '<REPLY_TO_NAME>',
    replyToEmail: '<REPLY_TO_EMAIL>'
});
```
```deno
import * as sdk from "npm:node-appwrite";

// Init SDK
let client = new sdk.Client();

let messaging = new sdk.Messaging(client);

client
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<PROJECT_ID>') // Your project ID
    .setKey('<YOUR_API_KEY>')        // Your secret API key
;

const provider = await messaging.updateSendgridProvider({
    providerId: '<PROVIDER_ID>',
    name: '<NAME>',
    enabled: false,
    apiKey: '<API_KEY>',
    fromName: '<FROM_NAME>',
    fromEmail: 'email@example.com',
    replyToName: '<REPLY_TO_NAME>',
    replyToEmail: '<REPLY_TO_EMAIL>'
});
```
```php
<?php

use Appwrite\Client;
use Appwrite\Services\Messaging;

$client = new Client();

$client
    ->setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    ->setProject('<PROJECT_ID>') // Your project ID
    ->setKey('<YOUR_API_KEY>')        // Your secret API key
;

$messaging = new Messaging($client);

$result = $messaging->updateSendgridProvider(
    providerId: '<PROVIDER_ID>',
    name: '<NAME>',                   // optional
    apiKey: '<API_KEY>',              // optional
    enabled: false,                   // optional
    fromName: '<FROM_NAME>',          // optional
    fromEmail: 'email@example.com',   // optional
    replyToName: '<REPLY_TO_NAME>',   // optional
    replyToEmail: '<REPLY_TO_EMAIL>'  // optional
);
```
```python
from appwrite.client import Client
from appwrite.services.messaging import Messaging

client = Client()

(client
  .set_endpoint('https://<REGION>.cloud.appwrite.io/v1') # Your API Endpoint
  .set_project('<PROJECT_ID>')     # Your project ID
  .set_key('<YOUR_API_KEY>')            # Your secret API key
)

messaging = Messaging(client)

result = messaging.update_sendgrid_provider(
    provider_id = '<PROVIDER_ID>',
    name = '<NAME>',                    # optional
    api_key = '<API_KEY>',              # optional
    enabled = False,                    # optional
    from_name = '<FROM_NAME>',          # optional
    from_email = 'email@example.com',   # optional
    reply_to_name = '<REPLY_TO_NAME>',  # optional
    reply_to_email = '<REPLY_TO_EMAIL>' # optional
)
```
```ruby
require 'appwrite'

include Appwrite

client = Client.new
    .set_endpoint('https://<REGION>.cloud.appwrite.io/v1') # Your API Endpoint
    .set_project('<PROJECT_ID>')  # Your project ID
    .set_key('<YOUR_API_KEY>')         # Your secret API key

messaging = Messaging.new(client)

response = messaging.update_sendgrid_provider(
    provider_id: '<PROVIDER_ID>',
    name: '<NAME>',                    # optional
    api_key: '<API_KEY>',              # optional
    enabled: false,                    # optional
    from_name: '<FROM_NAME>',          # optional
    from_email: 'email@example.com',   # optional
    reply_to_name: '<REPLY_TO_NAME>',  # optional
    reply_to_email: '<REPLY_TO_EMAIL>' # optional
)

puts response.inspect
```
```csharp
using Appwrite;
using Appwrite.Services;
using Appwrite.Models;

var client = new Client()
    .SetEndPoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .SetProject("<PROJECT_ID>")   // Your project ID
    .SetKey("<YOUR_API_KEY>");         // Your secret API key

var messaging = new Messaging(client);

Provider result = await messaging.UpdateSendgridProvider(
    providerId: "<PROVIDER_ID>"
    name: "<NAME>"                     // optional
    apiKey: "<API_KEY>"                // optional
    enabled: false                     // optional
    fromName: "<FROM_NAME>"            // optional
    fromEmail: "email@example.com"     // optional
    replyToName: "<REPLY_TO_NAME>"     // optional
    replyToEmail: "<REPLY_TO_EMAIL>"); // optional
```
```dart
import 'package:dart_appwrite/dart_appwrite.dart';
import 'package:dart_appwrite/enums.dart';
import 'package:dart_appwrite/models.dart';

void main() {                         // Init SDK
  Client client = Client();
  Messaging messaging = Messaging(client);

  client
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<PROJECT_ID>')  // Your project ID
    .setKey('<YOUR_API_KEY>')         // Your secret API key
  ;

  Future result = messaging.updateSendgridProvider(
    providerId: '<PROVIDER_ID>',
    name: '<NAME>',                   // optional
    apiKey: '<API_KEY>',              // optional
    enabled: false,                   // optional
    fromName: '<FROM_NAME>',          // optional
    fromEmail: 'email@example.com',   // optional
    replyToName: '<REPLY_TO_NAME>',   // optional
    replyToEmail: '<REPLY_TO_EMAIL>', // optional
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
    .setProject("<PROJECT_ID>") // Your project ID
    .setKey("<YOUR_API_KEY>");       // Your secret API key

Messaging messaging = new Messaging(client);

messaging.updateSendgridProvider(
    "<PROVIDER_ID>",                 // providerId
    "<NAME>",                        // name (optional)
    "<API_KEY>",                     // apiKey (optional)
    false,                           // enabled (optional)
    "<FROM_NAME>",                   // fromName (optional)
    "email@example.com",             // fromEmail (optional)
    "<REPLY_TO_NAME>",               // replyToName (optional)
    "<REPLY_TO_EMAIL>"               // replyToEmail (optional)
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
    .setProject("<PROJECT_ID>") // Your project ID
    .setKey("<YOUR_API_KEY>");       // Your secret API key

Messaging messaging = new Messaging(client);

messaging.updateSendgridProvider(
    "<PROVIDER_ID>",                 // providerId
    "<NAME>",                        // name (optional)
    "<API_KEY>",                     // apiKey (optional)
    false,                           // enabled (optional)
    "<FROM_NAME>",                   // fromName (optional)
    "email@example.com",             // fromEmail (optional)
    "<REPLY_TO_NAME>",               // replyToName (optional)
    "<REPLY_TO_EMAIL>"               // replyToEmail (optional)
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
    .setProject("<PROJECT_ID>") // Your project ID
    .setKey("<YOUR_API_KEY>")        // Your secret API key

let messaging = Messaging(client)

let provider = try await messaging.updateSendgridProvider(
  providerId: "<PROVIDER_ID>",
  name: "<NAME>",                    // optional
  apiKey: "<API_KEY>",               // optional
  enabled: xfalse,                   // optional
  fromName: "<FROM_NAME>",           // optional
  fromEmail: "email@example.com",    // optional
  replyToName: "<REPLY_TO_NAME>",    // optional
  replyToEmail: "<REPLY_TO_EMAIL>"   // optional
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
        .set_key("<YOUR_API_KEY>");

    let messaging = Messaging::new(&client);

    let provider = messaging.update_sendgrid_provider(
        "<PROVIDER_ID>",                             // providerId
        Some("<NAME>"),                              // name (optional)
        Some(false),                                 // enabled (optional)
        Some("<API_KEY>"),                           // apiKey (optional)
        Some("<FROM_NAME>"),                         // fromName (optional)
        Some("email@example.com"),                   // fromEmail (optional)
        Some("<REPLY_TO_NAME>"),                     // replyToName (optional)
        Some("<REPLY_TO_EMAIL>"),                    // replyToEmail (optional)
    ).await?;

    println!("{:?}", provider);
    Ok(())
}
```
{% /multicode %}
{% /tabsitem %}
{% /tabs %}
{% /section %}

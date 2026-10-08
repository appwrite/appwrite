---
layout: article
title: Firebase Cloud Messaging
description: Send push notifications to Android, Apple, or Web app with Firebase Cloud Messaging (FCM).
back: /docs/
---
Firebase Cloud Messaging (FCM) lets you send push notifications to your iOS, Android, and web apps through Appwrite Messaging.
Before you can deliver messages, you must connect to a messaging provider.

{% section #add-provider step=1 title="Add provider" %}

To add FCM as a provider, navigate to **Messaging** > **Providers** > {% icon icon="plus" size="m" /%} **Add provider** > **Push notification**.

{% only_dark %}
![Add a FCM provider](/images/docs/messaging/providers/fcm/dark/provider.avif)
{% /only_dark %}
{% only_light %}
![Add a FCM provider](/images/docs/messaging/providers/fcm/provider.avif)
{% /only_light %}

Give your provider a name > choose **FCM** > click **Save and continue**.
The provider will be saved to your project, but not enabled until you complete its configuration.
{% /section %}
{% section #configure-provider step=2 title="Configure provider" %}

In the **Configure** step, you will need to provide details from your Firebase console to connect your Appwrite project.

You will need to provide the following information from the **Firebase console**.

{% info title="Enable FCM" %}
FCM must be enabled on your Firebase project.

Head to Firebase console -> Settings -> Project settings -> Cloud Messaging.
If FCM is disabled, click the three-dots menu and open the link. On the following page, click **Enable** (it might take a few minutes for the action to complete).
{% /info %}

Head to **Project settings** > **Service accounts** > **Generate new private key**.

{% only_dark %}
![FCM admin key](/images/docs/messaging/providers/fcm/dark/admin-key.avif)
{% /only_dark %}
{% only_light %}
![FCM admin key](/images/docs/messaging/providers/fcm/admin-key.avif)
{% /only_light %}

After all the relevant details are provided, you can enable the provider.
{% /section %}

{% section #configure-app step=3 title="Configure app" %}
Some additional configuration is required to enable push notifications in your mobile app.

{% tabs %}
{% tabsitem #fcm-android title="Android with FCM" %}
1. Install the `com.google.firebase:firebase-messaging` Firebase SDK.
1. In your Firebase console, navigate to **Settings** > **General** > **Your apps** > add an **Android** app.
1. Register and download your `google-services.json` config file.
1. Add `google-services.json` at the root of your project.
1. Add Google Services class path to your app-level Gradle dependencies block `"com.google.gms:google-services:4.4.0"`.
1. Add Google Services plugin to your app-level Gradle in the plugins block as `"com.google.gms.google-services"`.
1. Add notification handler service to `AndroidManifest.xml` inside the application tag, alongside other activities. Find an example of this service in the [Send push notification](/docs/products/messaging/send-push-notifications#add-targets) guide.
```xml
<service android:name="<YOUR_NOTIFICATION_HANDLER_SERVICE>" android:exported="false">
    <intent-filter>
        <action android:name="com.google.firebase.MESSAGING_EVENT" />
    </intent-filter>
</service>
```
{% /tabsitem %}
{% tabsitem #fcm-ios title="iOS with FCM" %}
1. In your Firebase console, navigate to **Settings** > **General** > **Your apps** > add an **iOS** app.
1. Register and download your `GoogleService-Info.plist` and add it to the root of your project.
1. Head to **Apple Developer Member Center** > **Program resources** > **Certificates, Identifiers & Profiles** > **Keys**. The key needs **Apple Push Notification Service** enabled.
1. Create a new key, note down the key ID and download your key.
1. In Firebase console, go to *Settings** > **Cloud Messaging** > **APNs authentication key** > click **Upload**. Upload your key here.
1. Add push notification capability to your app by clicking your root-level app in XCode > **Signing & Capabilities** > {% icon icon="plus" size="m" /%} Capabilities > Search for **Push Notifications**.
1. If using SwiftUI, disable swizzling by setting `FirebaseAppDelegateProxyEnabled` to `NO` in your `Info.plist`.
{% /tabsitem %}
{% tabsitem #fcm-flutter title="Flutter with FCM" %}
1. Install the [Firebase CLI](https://firebase.google.com/docs/cli) and [FlutterFire CLI](https://pub.dev/packages/flutterfire_cli).
1. From your Flutter project directory, configure Firebase by running `flutterfire configure`.
1. Add the Firebase messaging plugin to your Flutter project with `flutter pub add firebase_messaging`.
1. Add the Firebase core plugin if not already added with `flutter pub add firebase_core`.
1. Initialize Firebase in your `lib/main.dart` file:
```dart
import 'package:flutter/widgets.dart';
import 'package:firebase_core/firebase_core.dart';
import 'firebase_options.dart';

void main() async {
  WidgetsFlutterBinding.ensureInitialized();
  await Firebase.initializeApp(
    options: DefaultFirebaseOptions.currentPlatform,
  );
  runApp(MyApp());
}
```
1. **For iOS**:
   - Enable push notifications and background modes in XCode by opening `ios/Runner.xcworkspace` and adding the **Push Notifications** capability and **Background Modes** (Background fetch and Remote notifications)
   - Upload your APNs authentication key to Firebase console under **Cloud Messaging** settings
   - Request notification permission at runtime using `FirebaseMessaging.instance.requestPermission()`
1. **For Android**: FCM requires devices running Android 5.0 or higher with Google Play services installed.
1. **For Web**: Add a `firebase-messaging-sw.js` file in your `web/` directory that imports the Firebase messaging SDK and handles background messages.
{% /tabsitem %}
{% /tabs %}

{% /section %}

{% section #test-provider step=4 title="Test provider" %}
Push notification requires special handling on the client side. Follow the [Send push notification](/docs/products/messaging/send-push-notifications) flow to test your provider.
{% /section %}


{% section #manage-provider step=5 title="Manage provider" %}
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
    .setProject('<PROJECT_ID>')                 // Your project ID
    .setKey('919c2d18fb5d4...a2ae413da83346ad2') // Your secret API key
;

const provider = await messaging.updateFCMProvider({
    providerId: '<PROVIDER_ID>',
    name: '<NAME>',
    enabled: false,
    serviceAccountJSON: {}
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

const provider = await messaging.updateFCMProvider({
    providerId: '<PROVIDER_ID>',
    name: '<NAME>',
    enabled: false,
    serviceAccountJSON: {}
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

$result = $messaging->updateFCMProvider(
    providerId: '<PROVIDER_ID>',
    name: '<NAME>',                               // optional
    enabled: false,                               // optional
    serviceAccountJSON: []                        // optional
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

result = messaging.update_fcm_provider(
    provider_id = '<PROVIDER_ID>',
    name = '<NAME>',                            # optional
    enabled = False,                            # optional
    service_account_json = {}                   # optional
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

response = messaging.update_fcm_provider(
    provider_id: '<PROVIDER_ID>',
    name: '<NAME>',                               # optional
    enabled: false,                               # optional
    service_account_json: {}                      # optional
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

Provider result = await messaging.UpdateFCMProvider(
    providerId: "<PROVIDER_ID>"
    name: "<NAME>"                                // optional
    enabled: false                                // optional
    serviceAccountJSON: [object]);                // optional
```
```dart
import 'package:dart_appwrite/dart_appwrite.dart';
import 'package:dart_appwrite/enums.dart';
import 'package:dart_appwrite/models.dart';

void main() async {                               // Init SDK
  Client client = Client();
  Messaging messaging = Messaging(client);

  client
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<PROJECT_ID>')                 // Your project ID
    .setKey('919c2d18fb5d4...a2ae413da83346ad2') // Your secret API key
  ;

  Future result = await messaging.updateFCMProvider(
    providerId: '<PROVIDER_ID>',
    name: '<NAME>',                              // optional
    enabled: false,                              // optional
    serviceAccountJSON: {},                      // optional
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

messaging.updateFCMProvider(
    "<PROVIDER_ID>",                              // providerId
    "<NAME>",                                     // name (optional)
    false,                                        // enabled (optional)
    mapOf( "a" to "b" )                           // serviceAccountJSON (optional)
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

messaging.updateFCMProvider(
    "<PROVIDER_ID>",                              // providerId
    "<NAME>",                                     // name (optional)
    false,                                        // enabled (optional)
    mapOf( "a" to "b" )                           // serviceAccountJSON (optional)
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

let provider = try await messaging.updateFCMProvider(
  providerId: "<PROVIDER_ID>",
  name: "<NAME>",                                // optional
  enabled: xfalse,                               // optional
  serviceAccountJSON: [:]                        // optional
)
```
```server-rust
use appwrite::Client;
use appwrite::services::messaging::Messaging;
use serde_json::json;

#[tokio::main]
async fn main() -> Result<(), Box<dyn std::error::Error>> {
    let client = Client::new()
        .set_endpoint("https://<REGION>.cloud.appwrite.io/v1")
        .set_project("<PROJECT_ID>")
        .set_key("919c2d18fb5d4...a2ae413da83346ad2");

    let messaging = Messaging::new(&client);

    let provider = messaging.update_fcm_provider(
        "<PROVIDER_ID>",                             // providerId
        Some("<NAME>"),                              // name (optional)
        Some(false),                                 // enabled (optional)
        Some(json!({})),                             // serviceAccountJSON (optional)
    ).await?;

    println!("{:?}", provider);
    Ok(())
}
```
{% /multicode %}
{% /tabsitem %}
{% /tabs %}
{% /section %}

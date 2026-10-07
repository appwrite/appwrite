---
layout: article
title: Send push notification
description: Send push notification to your users using Appwrite Messaging.
---
You can send, schedule, and manage push notifications to your apps using Appwrite Messaging.
Push notifications can be used to deliver new message notifications, app updates, promotional offers,
and other messages straight to your user's devices.

{% section #add-provider step=1 title="Add provider" %}
Push notifications must be sent through third-party providers, like Apple Push Notification service and Firebase Cloud Messaging.
The push notification APIs for Apple and Android devices can only be accessed through these services.

You must configure these services before you can send your first push notification.

{% cards %}
{% cards_item href="/docs/products/messaging/apns" title="APNS" icon="icon-apple" %}
Configure APNs for push notification to Apple devices.
{% /cards_item %}
{% cards_item href="/docs/products/messaging/fcm" title="FCM" icon="web-icon-firebase" %}
Configure FCM for push notification to Android and Apple devices.
{% /cards_item %}
{% /cards %}

{% /section %}
{% section #add-targets step=2 title="Add targets" %}
Before sending your first push notification, your application must register itself for push notification,
then provide the device token to Appwrite.

{% tabs %}
{% tabsitem #apple-apns title="APNs for Apple" %}
First, enable push notification in your app.
Add push notification capability to your app by clicking your root-level app in XCode > **Signing & Capabilities** > {% icon icon="plus" size="m" /%} Capabilities > Search for **Push Notifications**.

{% only_dark %} ![Authentication Key](/images/docs/messaging/providers/apns/dark/xcode-enable-pn.avif) {% /only_dark %} {% only_light %} ![Authentication Key](/images/docs/messaging/providers/apns/xcode-enable-pn.avif) {% /only_light %}

First, register for remote notifications in your app delegate's `application(_:didFinishLaunchingWithOptions:)` method.

```swift
func application(
    _ application: UIApplication,
    didFinishLaunchingWithOptions launchOptions: [UIApplication.LaunchOptionsKey: Any]? = nil
) -> Bool {
    UNUserNotificationCenter.current().delegate = self
    UNUserNotificationCenter.current().requestAuthorization(options: [.alert, .badge, .sound]) { granted, _ in
        if granted {
            DispatchQueue.main.async {
                application.registerForRemoteNotifications()
            }
        }
    }
    return true
}
```

Next, create a handler for when the app receives the push notification device token.
```swift
func application(
    _ application: UIApplication,
    didRegisterForRemoteNotificationsWithDeviceToken deviceToken: Data
) {
    /* store this `token` */
    let token = deviceToken.map { String(format: "%.2hhx", $0) }.joined()
}
```

Since the token is saved in `UserDefaults`, you can access it from anywhere in your app.
With this saved `apnsToken`, you can create a push target with Appwrite when the user logs in.
Each push target is associated with an account, heres an example with an email password login.
The same logic applies to all types of login methods.
```swift
func login() async {
    do {
        let session = try await account.createEmailPasswordSession(email: username, password: password)

        let token = /* Retrieve the stored push token */

        try await account.createPushTarget({
            targetId: ID.unique(),
            identifier: token
        })
    } catch {
        print("Login failed: \(error.localizedDescription)")
    }
}
```
{% /tabsitem %}

{% tabsitem #android-fcm title="FCM for Android" %}
Before you can send push notifications using FCM, make sure you'd followed the steps to
[Add Firebase to your Android project](https://firebase.google.com/docs/android/setup).

After adding Firebase to your Android project and adding the `google-services.json` to your project,
initialize Firebase in your main activity and fetch the FCM registration token.

```kotlin
class MainActivity : AppCompatActivity() {
    override fun onCreate() {
        // Initialize Firebase
        FirebaseApp.initializeApp(this)

        // Set the FCM token
        FirebaseMessaging.getInstance().token.addOnCompleteListener(OnCompleteListener { task ->
            if (task.isSuccessful) {
                /* store this `token` */
                val token = task.result
            }
        })
    }
}
```

Appwrite's push targets are associated with accounts.
Typically, you would create a push target when the user logs in.

For example, when the user logs in with email and password, your app
can register itself as a target after handling the login.

```kotlin
fun login(email: String, password: String) {
    viewModelScope.launch {
        try {
            val session = account.createEmailPasswordSession(email, password)

            let token = /* Retrieve the stored push token */

            /* store the `target.id` */
            val target = account.createPushTarget(
                targetId = ID.unique(),
                identifier = token
            )
        } catch (e: AppwriteException) {
            Log.e("Login", "Failed: ${e.message}")
        }
    }
}
```

Lastly, because FCM push tokens can change, we need to add a service to handle FCM token
refreshes and update the target with Appwrite Messaging.

Create a new service that extends `FirebaseMessagingService` which handles the event where
the FCM token is updated.

```kotlin
class MessagingService : FirebaseMessagingService() {
    override fun onNewToken(token: String) {
        super.onNewToken(token)

        /* store the `token` */
        /* If the user is logged in, update the push target */
        runBlocking {
            account?.updatePushTarget(/* retrieve saved `target.id` */, token)
        }
    }
}
```

In your `AndroidManifest.xml`, register this new service.
```xml
<service android:name="<YOUR_NOTIFICATION_HANDLER_SERVICE>" android:exported="false">
    <intent-filter>
        <action android:name="com.google.firebase.MESSAGING_EVENT" />
    </intent-filter>
</service>
```
{% /tabsitem %}

{% tabsitem #apple-fcm title="FCM for Apple" %}
Before you can send push notifications using FCM, make sure you'd followed the steps to
[Add Firebase to your iOS project](https://firebase.google.com/docs/ios/setup).

After adding Firebase to your iOS project and adding the `GoogleService-Info.plist` to the root of your project.


Next, add your APNs key to Firebase.
1. Head to **Apple Developer Member Center** > **Program resources** > **Certificates, Identifiers & Profiles** > **Keys**. The key needs **Apple Push Notification Service** enabled.
1. Create a new key, note down the key ID and download your key.
1. In Firebase console, go to *Settings** > **Cloud Messaging** > **APNs authentication key** > click **Upload**. Upload your key here.
1. Add push notification capability to your app by clicking your root-level app in XCode > **Signing & Capabilities** > {% icon icon="plus" size="m" /%} Capabilities > Search for **Push Notifications**.
1. If using SwiftUI, disable swizzling by setting `FirebaseAppDelegateProxyEnabled` to `NO` in your `Info.plist`.

Initialize Firebase in your app delegate's `application(_:didFinishLaunchingWithOptions:)` method,
implement the messaging delegate protocol, and register for remote notifications.

```swift
func application(
    _ application: UIApplication,
    didFinishLaunchingWithOptions launchOptions: [UIApplication.LaunchOptionsKey: Any]? = nil
) -> Bool {
    FirebaseApp.configure()
    Messaging.messaging().delegate = self
    UNUserNotificationCenter.current().delegate = self

    UNUserNotificationCenter.current().requestAuthorization(options: [.alert, .badge, .sound]) { granted, _ in
        if granted {
            DispatchQueue.main.async {
                application.registerForRemoteNotifications()
            }
        }
    }
    return true
}
```
Your APNS token can change, so you need to handle the token refresh event and update the target with Appwrite Messaging.
Implement `didReceiveRegistrationToken`, which is called when the FCM token is updated.
```swift
func messaging(_ messaging: Messaging, didReceiveRegistrationToken fcmToken: String?) {
    /* store the fcmToken */
    guard let fcmToken = fcmToken else { return }

    Task {
        do {
            _ = try await account.get()
            try await account.createPushTarget(targetId: ID.unique(), identifier: fcmToken)
        } catch {
            print("Failed to create push target: \(error.localizedDescription)")
        }
    }
}
```
Since the token is saved in `UserDefaults`, you can access it from anywhere in your app.
With this saved `fcmToken`, you can create a push target with Appwrite when the user logs in.
Each push target is associated with an account, here's an example with an email password login.
The same logic applies to all types of login methods.
```swift
func login() async {
    do {
        let session = try await account.createEmailPasswordSession(email: username, password: password)

        let token = /* Retrieve stored push token */

        let target = try await account.createPushTarget(targetId: ID.unique(), identifier: token)
    } catch {
        print("Login failed: \(error.localizedDescription)")
    }
}

```

If you have disabled method swizzling, or you are building a SwiftUI app,
you'll need to explicitly map your APNs token to the FCM registration token.
Implement the `didRegisterForRemoteNotificationsWithDeviceToken` method to get the device token and save it to FCM.
```swift
func application(
    _ application: UIApplication,
    didRegisterForRemoteNotificationsWithDeviceToken deviceToken: Data
) {
    Messaging.messaging().apnsToken = deviceToken
}
```
{% /tabsitem %}
{% /tabs %}
{% /section %}

{% section #request-permissions step=3 title="Request permissions" %}
Your app must ask for permission to receive push notification from the user.

{% tabs %}
{% tabsitem #apple-apns title="Apple with APNs" %}
Before your app can receive push notifications, you need to request the user for permissions.
Appwrite provides a utility to help request permissions to display notificaitons.

You can learn more about requesting permissions from the
[Apple Developer Documentation](https://developer.apple.com/documentation/usernotifications/asking-permission-to-use-notifications).
{% /tabsitem %}

{% tabsitem #android-fcm title="FCM for Android" %}
First, add `POST_NOTIFICATIONS` to your `AndroidManifest.xml`.

```xml
<?xml version="1.0" encoding="utf-8"?>
<manifest xmlns:android="http://schemas.android.com/apk/res/android"
    package="YOUR_PACKAGE">
    <uses-permission android:name="android.permission.POST_NOTIFICATIONS"/>
    <!-- ... rest of your manifest -->
```

Then, you'll also need to request [runtime permissions](https://developer.android.com/training/permissions/requesting)
from your users using the `android.permission.POST_NOTIFICATIONS` permission.
{% /tabsitem %}

{% tabsitem #apple-fcm title="FCM for Apple" %}
Before your app can receive push notifications, you need to request the user for permissions.
Appwrite provides a utility to help request permissions to display notificaitons.

You can learn more about requesting permissions from the
[Apple Developer Documentation](https://developer.apple.com/documentation/usernotifications/asking-permission-to-use-notifications).

When an FCM registration token is generated, the library uploads the identifier and configuration data to Firebase.
If you wish to give your users the ability to explicitly opt out of sending data to Firebase,
you can disable automatic initialization and manually initialize the library when the user grants permission.

Disable auto-initialization by setting `FirebaseMessagingAutoInitEnabled` to `NO` in your `Info.plist`.
```text
FirebaseMessagingAutoInitEnabled = NO
```

Then, manually initialize the library when the user grants permission.
``` swift
Messaging.messaging().autoInitEnabled = true
```
{% /tabsitem %}
{% /tabs %}

{% /section %}

{% section #send-message step=4 title="Send message" %}
You can send messages in both the Appwrite Console and programmatically using the Appwrite Server SDK.

{% info title="Sandbox" %}
If you enabled **Sandbox** on your APNs provider, Appwrite will send push notifications to the development APNs environment.
This requires you to use a **Development profile** in XCode.

If XCode is not default to a development profile,
click your root-level app in XCode > **Signing & Capabilities** > {% icon icon="plus" size="m" /%} Capabilities > uncheck **Automatically manage signing**.
Then manually select a **Provisioning profile** that is a **Distribution profile**.
{% /info %}

{% tabs %}
{% tabsitem #console title="Console" %}
To send a test message, navigate to **Messaging** > **Messages** > {% icon icon="plus" size="m" /%} **Create message** > **Push notification**.
{% only_dark %}
![Create email message](/images/docs/messaging/messages/dark/create-push-notification.avif)
{% /only_dark %}
{% only_light %}
![Create email message](/images/docs/messaging/messages/create-push-notification.avif)
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
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')    // Your API Endpoint
    .setProject('<PROJECT_ID>')                    // Your project ID
    .setKey('919c2d18fb5d4...a2ae413da83346ad2')    // Your secret API key
;

const message = await messaging.createPush({
        messageId: '<MESSAGE_ID>',
        title: '<TITLE>',
        body: '<BODY>',
        topics: [],             // optional
        users: [],              // optional
        targets: [],            // optional
        data: {},               // optional
        action: '<ACTION>',     // optional
        icon: '<ICON>',         // optional
        sound: '<SOUND>',       // optional
        color: '<COLOR>',       // optional
        tag: '<TAG>',           // optional
        badge: '<BADGE>',       // optional
        draft: false,           // optional
        scheduledAt: ''         // optional
    });
```
```deno
import * as sdk from "npm:node-appwrite";

// Init SDK
let client = new sdk.Client();

let messaging = new sdk.Messaging(client);

client
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')    // Your API Endpoint
    .setProject('<PROJECT_ID>')                    // Your project ID
    .setKey('919c2d18fb5d4...a2ae413da83346ad2')    // Your secret API key
;

const message = await messaging.createPush({
        messageId: '<MESSAGE_ID>',
        title: '<TITLE>',
        body: '<BODY>',
        topics: [],             // optional
        users: [],              // optional
        targets: [],            // optional
        data: {},               // optional
        action: '<ACTION>',     // optional
        icon: '<ICON>',         // optional
        sound: '<SOUND>',       // optional
        color: '<COLOR>',       // optional
        tag: '<TAG>',           // optional
        badge: '<BADGE>',       // optional
        draft: false,           // optional
        scheduledAt: ''         // optional
    });
```
```php
<?php

use Appwrite\Client;
use Appwrite\Services\Messaging;

$client = new Client();

$client
    ->setEndpoint('https://<REGION>.cloud.appwrite.io/v1')   // Your API Endpoint
    ->setProject('<PROJECT_ID>')                   // Your project ID
    ->setKey('919c2d18fb5d4...a2ae413da83346ad2')   // Your secret API key
;

$messaging = new Messaging($client);

$result = $messaging->createPush(
    messageId: '<MESSAGE_ID>',
    title: '<TITLE>',
    body: '<BODY>',
    topics: [],         // optional
    users: [],          // optional
    targets: [],        // optional
    data: [],           // optional
    action: '<ACTION>', // optional
    icon: '<ICON>',     // optional
    sound: '<SOUND>',   // optional
    color: '<COLOR>',   // optional
    tag: '<TAG>',       // optional
    badge: '<BADGE>',   // optional
    draft: false,       // optional
    scheduledAt: ''     // optional
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

result = messaging.create_push(
    message_id = '<MESSAGE_ID>',
    title = '<TITLE>',
    body = '<BODY>',
    topics = [],        # optional
    users = [],         # optional
    targets = [],       # optional
    data = {},          # optional
    action = '<ACTION>',# optional
    icon = '<ICON>',    # optional
    sound = '<SOUND>',  # optional
    color = '<COLOR>',  # optional
    tag = '<TAG>',      # optional
    badge = '<BADGE>',  # optional
    draft = False,      # optional
    scheduled_at = ''   # optional
)
```
```ruby
require 'appwrite'

include Appwrite

client = Client.new
    .set_endpoint('https://<REGION>.cloud.appwrite.io/v1')   # Your API Endpoint
    .set_project('<PROJECT_ID>')                   # Your project ID
    .set_key('919c2d18fb5d4...a2ae413da83346ad2')   # Your secret API key

messaging = Messaging.new(client)

response = messaging.create_push(
    message_id: '<MESSAGE_ID>',
    title: '<TITLE>',
    body: '<BODY>',
    topics: [],         # optional
    users: [],          # optional
    targets: [],        # optional
    data: {},           # optional
    action: '<ACTION>', # optional
    icon: '<ICON>',     # optional
    sound: '<SOUND>',   # optional
    color: '<COLOR>',   # optional
    tag: '<TAG>',       # optional
    badge: '<BADGE>',   # optional
    draft: false,       # optional
    scheduled_at: ''    # optional
)

puts response.inspect
```
```csharp
using Appwrite;
using Appwrite.Services;
using Appwrite.Models;

var client = new Client()
    .SetEndPoint("https://<REGION>.cloud.appwrite.io/v1")    // Your API Endpoint
    .SetProject("<PROJECT_ID>")                    // Your project ID
    .SetKey("919c2d18fb5d4...a2ae413da83346ad2");   // Your secret API key

var messaging = new Messaging(client);

Message result = await messaging.CreatePush(
    messageId: "[MESSAGE_ID]",
    title: "[TITLE]",
    body: "[BODY]"
    topics: new List<string> {}     // optional
    users: new List<string> {}      // optional
    targets: new List<string> {}    // optional
    data: [object]      // optional
    action: "[ACTION]"  // optional
    icon: "[ICON]"      // optional
    sound: "[SOUND]"    // optional
    color: "[COLOR]"    // optional
    tag: "[TAG]"        // optional
    badge: "[BADGE]"    // optional
    draft: false        // optional
    scheduledAt: "");   // optional
```
```dart
import 'package:dart_appwrite/dart_appwrite.dart';
import 'package:dart_appwrite/enums.dart';
import 'package:dart_appwrite/models.dart';

void main() async {   // Init SDK
  Client client = Client();
  Messaging messaging = Messaging(client);

  client
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')    // Your API Endpoint
    .setProject('<PROJECT_ID>')                    // Your project ID
    .setKey('919c2d18fb5d4...a2ae413da83346ad2')    // Your secret API key
  ;

  Future result = await messaging.createPush(
    messageId: '<MESSAGE_ID>',
    title: '<TITLE>',
    body: '<BODY>',
    topics: [],         // optional
    users: [],          // optional
    targets: [],        // optional
    data: {},           // optional
    action: '<ACTION>', // optional
    icon: '<ICON>',     // optional
    sound: '<SOUND>',   // optional
    color: '<COLOR>',   // optional
    tag: '<TAG>',       // optional
    badge: '<BADGE>',   // optional
    draft: false,       // optional
    scheduledAt: '',    // optional
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
import io.appwrite.services.Messaging

val client = Client(context)
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1")    // Your API Endpoint
    .setProject("<PROJECT_ID>")                    // Your project ID
    .setKey("919c2d18fb5d4...a2ae413da83346ad2")    // Your secret API key

val messaging = Messaging(client)

val response = messaging.createPush(
    messageId = "<MESSAGE_ID>",
    title = "<TITLE>",
    body = "<BODY>",
    topics = listOf(),          // optional
    users = listOf(),           // optional
    targets = listOf(),         // optional
    data = mapOf( "a" to "b" ), // optional
    action = "<ACTION>",        // optional
    icon = "<ICON>",            // optional
    sound = "<SOUND>",          // optional
    color = "<COLOR>",          // optional
    tag = "<TAG>",              // optional
    badge = "<BADGE>",          // optional
    draft = false,              // optional
    scheduledAt = ""            // optional
)
```
```java
import io.appwrite.Client;
import io.appwrite.coroutines.CoroutineCallback;
import io.appwrite.services.Messaging;

Client client = new Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1")    // Your API Endpoint
    .setProject("<PROJECT_ID>")                    // Your project ID
    .setKey("919c2d18fb5d4...a2ae413da83346ad2");   // Your secret API key

Messaging messaging = new Messaging(client);

messaging.createPush(
    "<MESSAGE_ID>",      // messageId
    "<TITLE>",           // title
    "<BODY>",            // body
    listOf(),            // topics (optional)
    listOf(),            // users (optional)
    listOf(),            // targets (optional)
    mapOf( "a" to "b" ), // data (optional)
    "<ACTION>",          // action (optional)
    "<ICON>",            // icon (optional)
    "<SOUND>",           // sound (optional)
    "<COLOR>",           // color (optional)
    "<TAG>",             // tag (optional)
    "<BADGE>",           // badge (optional)
    false,               // draft (optional)
    ""                   // scheduledAt (optional)
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
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1")  // Your API Endpoint
    .setProject("<PROJECT_ID>")                  // Your project ID
    .setKey("919c2d18fb5d4...a2ae413da83346ad2")  // Your secret API key

let messaging = Messaging(client)

let message = try await messaging.createPush(
  messageId: "<MESSAGE_ID>",
  title: "<TITLE>",
  body: "<BODY>",
  topics: [],         // optional
  users: [],          // optional
  targets: [],        // optional
  data: [:],          // optional
  action: "<ACTION>", // optional
  icon: "<ICON>",     // optional
  sound: "<SOUND>",   // optional
  color: "<COLOR>",   // optional
  tag: "<TAG>",       // optional
  badge: "<BADGE>",   // optional
  draft: false,       // optional
  scheduledAt: ""     // optional
)
```
```server-rust
use appwrite::Client;
use appwrite::services::messaging::Messaging;
use serde_json::json;

#[tokio::main]
async fn main() -> Result<(), Box<dyn std::error::Error>> {
    let client = Client::new()
        .set_endpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
        .set_project("<PROJECT_ID>")                 // Your project ID
        .set_key("919c2d18fb5d4...a2ae413da83346ad2"); // Your secret API key

    let messaging = Messaging::new(&client);

    let message = messaging.create_push(
        "<MESSAGE_ID>",
        Some("<TITLE>"),                    // title (optional)
        Some("<BODY>"),                     // body (optional)
        Some(vec![]),                       // topics (optional)
        Some(vec![]),                       // users (optional)
        Some(vec![]),                       // targets (optional)
        Some(json!({})),                    // data (optional)
        Some("<ACTION>"),                   // action (optional)
        Some("<IMAGE>"),                    // image (optional)
        Some("<ICON>"),                     // icon (optional)
        Some("<SOUND>"),                    // sound (optional)
        Some("<COLOR>"),                    // color (optional)
        Some("<TAG>"),                      // tag (optional)
        Some(1),                            // badge (optional)
        Some(false),                        // draft (optional)
        None,                               // scheduled_at (optional)
        None,                               // content_available (optional)
        None,                               // critical (optional)
        None,                               // priority (optional)
    ).await?;

    println!("{:?}", message);
    Ok(())
}
```
{% /multicode %}
{% /tabsitem %}
{% /tabs %}
{% /section %}

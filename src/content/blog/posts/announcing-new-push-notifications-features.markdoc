---
layout: post
title: Announcing new features for Push Notifications
description: 'Appwrite Messaging now supports background updates, critical alerts, and priority controls for push notifications.'
date: 2025-01-22
cover: /images/blog/announcing-new-push-notifications-features/cover.avif
timeToRead: 5
author: jake-barnby
category: product
callToAction: true
faqs:
  - question: "How do I send push notifications with Appwrite?"
    answer: "Use [Appwrite Messaging](/docs/products/messaging) with the `createPush` endpoint, targeting users, topics, or specific device tokens. Appwrite handles the underlying delivery through APNs for Apple devices and FCM for Android devices, so you only need to configure providers once."
  - question: "What is a silent push notification on iOS?"
    answer: "A silent or background push is a notification with `content-available` set to true and no visible alert. iOS wakes the app in the background to process the payload, which is useful for data syncs and content prefetching. Apple limits these to roughly 2 to 3 per hour to preserve battery."
  - question: "What are iOS critical alerts?"
    answer: "Critical alerts are notifications that can break through Do Not Disturb and silent mode. They require a special entitlement from Apple and are intended for safety, health, or security use cases like medical alerts, security warnings, or emergency notifications."
  - question: "What is the difference between normal and high priority push notifications?"
    answer: "Normal priority notifications can be delayed or grouped by the operating system to save battery, which is good for non-urgent updates. High priority notifications are delivered immediately and should be reserved for time-sensitive content like chat messages or alerts."
  - question: "Do I need separate code for iOS and Android push notifications in Appwrite?"
    answer: "No. Appwrite Messaging exposes a single API that maps onto APNs for Apple and FCM for Android. You set parameters once and Appwrite forwards them to the appropriate provider, so you only need to handle platform-specific concerns when a feature only exists on one platform."
  - question: "Can I clear the app icon badge count from Appwrite?"
    answer: "Yes. Set the `badge` field on the push payload to the desired count, or pass `0` to clear an existing badge. This works with iOS devices and is delivered through the standard `createPush` and `updatePush` endpoints."
---

We're excited to introduce new additions to Appwrite Messaging that give you greater control over how you send and handle push notifications in your app.
The push notification API now affords you finer control over both how and when your notifications are delivered.
You can run background updates silently, send critical alerts that bypass Do Not Disturb, and manage delivery priorities.
These changes enable you to handle common scenarios - such as data syncs, urgent alerts, and battery-conscious updates more effectively.

Here's a quick overview of the new features:

# Background updates (iOS)

We're now supporting Apple's background notifications through the `contentAvailable` parameter. This lets your iOS app process updates in the background without displaying notifications to users. Background notifications are useful for data syncs, content updates, and state changes that don't require user interaction.

When you set `contentAvailable`, your app wakes up in the background to handle the notification payload. This works well for messaging apps that need to fetch new messages, news apps refreshing content, or any app that needs to update data periodically. Since no UI is shown to users during background updates, you can omit both the title and body.

A few things to keep in mind when using background notifications:

-   You'll need to configure your app to handle background processing using [Background Modes](https://developer.apple.com/documentation/xcode/configuring-background-execution-modes) in Xcode.
-   Set the notification priority to normal to ensure proper background delivery.
-   Apple limits background notifications to about 2-3 per hour to preserve battery life.
-   For Android users, you can achieve similar functionality by sending a data-only notification (just omit the title and body).

To implement background notifications, set `contentAvailable` to true and handle the [application:didReceiveRemoteNotification:fetchCompletionHandler:](https://developer.apple.com/documentation/uikit/uiapplicationdelegate/1623013-application) method in your app.

# Critical alerts (iOS)

Sometimes, users need to see a notification even when their phone is on **Do Not Disturb**. The new `critical` parameter attempts to mark the notification as critical, which can bypass silent and do not disturb settings when approved. To use critical alerts, you'll need to request the critical alert entitlement from Apple through your developer account - just visit [Apple's developer portal](https://developer.apple.com/contact/request/notifications-critical-alerts-entitlement/) and fill out a brief form explaining your use case.

Critical alerts are essential for apps that handle time-sensitive or safety-related notifications, such as:

-   Healthcare apps sending urgent medical alerts
-   Home security apps warning about break-ins
-   Connected device apps alerting about smoke or carbon monoxide detection
-   Emergency response apps sending evacuation notices
-   Financial apps warning about suspicious transactions

# Priority controls

You can now set notifications as either normal or high priority:

-   **Normal priority:** The system delivers these at convenient times based on battery life and may group notifications together.
-   **High priority:** These go out right away - useful for time-sensitive updates.

# Other updates

-   **Badge numbers (iOS):** Set a number to display on your app icon to show pending notifications. Set it to 0 to clear existing badges. Must be an integer.
-   **Data payload:** Both title and body are now optional fields. This gives you more flexibility, especially for background updates.

# Technical details

These new parameters work with our existing `createPush` and `updatePush` endpoints. We pass these parameters directly to the underlying push notification services (APNs for Apple and FCM for Android) - you just need to set the parameters you want.

Here are some examples to get you started:

## Example 1: Background update for fetching new messages

{% multicode %}

```dart
Future result = await messaging.createPush(
  messageId: 'background-sync-1',
  title: null,                    // Optional for background updates
  body: null,                     // Optional for background updates
  topics: [],                     // Optional: Send to specific topics
  users: [],                      // Optional: Send to specific users
  targets: [],                    // Optional: Send to specific devices
  data: {                         // Optional: Custom data payload
    'type': 'message_sync',
    'lastSyncId': '123'
  },
  contentAvailable: true,         // iOS background updates
  priority: 'normal',             // Required for background updates
);
```

```js
const result = await messaging.createPush({
    messageId: 'background-sync-1',
    data: {
        type: 'message_sync',
        lastSyncId: '123'
    },
    badge: 0,
    contentAvailable: true,
    priority: 'normal'
});
```

{% /multicode %}

## Example 2: Critical alert for security breach

{% multicode %}

```dart
Future result = await messaging.createPush(
  messageId: 'security-alert-1',
  title: 'Security Alert',
  body: 'Unusual activity detected at front door',
  topics: [],                     // Optional: Send to specific topics
  users: [],                      // Optional: Send to specific users
  targets: [],                    // Optional: Send to specific devices
  data: {                         // Optional: Custom data payload
    'type': 'security_alert',
    'deviceId': 'front_door_cam'
  },
  badge: 1,                       // Must be an integer
  critical: true,                 // iOS-only parameter
  priority: 'high',              // High priority for critical alerts
);
```

```js
const result = await messaging.createPush({
    messageId: 'security-alert-1',
    title: 'Security Alert',
    body: 'Unusual activity detected at front door',
    data: {
        type: 'security_alert',
        deviceId: 'front_door_cam'
    },
    badge: 1,
    critical: true,
    priority: 'high'
});
```

{% /multicode %}

## Example 3: Normal notification for all platforms

{% multicode %}

```dart
Future result = await messaging.createPush(
  messageId: 'chat-message-1',
  title: 'New Message',
  body: 'Sarah sent you a photo',
  topics: [],                     // Optional: Send to specific topics
  users: [],                      // Optional: Send to specific users
  targets: [],                    // Optional: Send to specific devices
  data: {                         // Optional: Custom data payload
    'chatId': '456',
    'messageType': 'photo'
  },
  badge: 3,                       // Integer for iOS, ignored on Android
  priority: 'normal',            // Normal priority delivery
);
```

```js
const result = await messaging.createPush({
    messageId: 'chat-message-1',
    title: 'New Message',
    body: 'Sarah sent you a photo',
    data: {
        chatId: '456',
        messageType: 'photo'
    },
    badge: 3,
    priority: 'normal'
});
```

{% /multicode %}

Each example shows different combinations of the new parameters based on common use cases. Note that some parameters like `critical` and `badge` are iOS-specific, while others work across platforms. You can mix and match these parameters based on your needs.

# Documentation and resources

-   See the full implementation details in our [docs](https://appwrite.io/docs/products/messaging/messages)
-   Check out our best practices for sending [push notifications](https://appwrite.io/blog/post/push-notifications-best-practices)
-   Watch the [feature walkthrough](https://www.youtube.com/watch?v=QdDgPeuBZ1I)
-   Learn more about [Messaging](https://appwrite.io/products/messaging)

If you run into any issues or have questions about implementing these features, check the documentation or reach out to us through our support channels.

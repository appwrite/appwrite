---
layout: post
title: "Using Appwrite Messaging for push notifications and email"
description: Learn how to set up Appwrite Messaging to send push notifications and emails, when to use each channel, and how to target users and topics effectively.
date: 2026-03-26
cover: /images/blog/appwrite-messaging-push-email/cover.avif
timeToRead: 5
author: aditya-oberai
category: product, tutorial
featured: false
unlisted: true
faqs:
  - question: "What channels does Appwrite Messaging support?"
    answer: "Appwrite Messaging handles push notifications, email, and SMS from a single API. You configure providers once, attach targets to users, and send or schedule messages to individuals, groups, or topics, without integrating a separate SDK for each channel."
  - question: "How do I set up push notifications with Appwrite?"
    answer: "Add a push provider in the Console under Messaging, Providers, Add provider, choosing APNs for Apple devices or FCM for Android devices and supplying the matching credentials. Then in your app, register a push target for each logged-in user by calling createPushTarget with the device token returned by APNs or FCM. FCM tokens can rotate, so call updatePushTarget when the token refreshes."
  - question: "What email providers does Appwrite Messaging work with?"
    answer: "Email in Appwrite Messaging is sent through an SMTP-based provider. The Console currently supports Mailgun and SendGrid out of the box. You add the provider once with your API credentials and then send messages with createEmail."
  - question: "When should I use push notifications versus email?"
    answer: "Use push for time-sensitive content the user should see immediately, like chat messages, delivery updates, or security alerts. Use email for content with longer shelf life or more detail, like receipts, invoices, newsletters, and password resets. SMS sits between the two and is well suited to one-time passcodes or notifications that need to reach users without the app installed."
  - question: "Can I schedule messages with Appwrite Messaging?"
    answer: "Yes. The same APIs that send messages immediately also accept a scheduled time, so a single call can handle send-now and send-later cases identically. This keeps your targeting and templating logic in one place rather than across separate scheduling services."
---

Most apps eventually need to reach users outside the app itself. A background sync completes, a purchase is confirmed, a security alert fires. The question is not whether you need messaging, it is which channel to use and how to wire it up without managing separate services for each one.

[Appwrite Messaging](/docs/products/messaging) handles push notifications, email, and SMS from a single API. You configure providers once, define targets per user, and send or schedule messages to individuals, groups, or topics. This post covers the two highest-traffic channels: push notifications and email.

# Why keeping messaging in one place matters

The alternative is common and painful: a push library from Firebase, a transactional email service with its own SDK, an SMS provider on top of that. Each has separate authentication, separate dashboards, and separate retry logic to maintain.

When everything lives in Appwrite Messaging, your targeting logic (which user, which channel, which topic) is written once. You can also schedule messages from the same API, so a single call handles "send now" and "send in 48 hours" identically.

# Choosing the right channel

Before writing any code, match the message to the channel.

**Push notifications** are best for time-sensitive information that the user should see quickly: a new chat message, a delivery update, a security alert. They appear on the lock screen and interrupt whatever the user is doing, so reserve them for content that earns that interruption.

**Email** is better for content with longer shelf life or more detail: receipts, invoices, newsletters, password resets, weekly digests. Users expect to find email later; they do not expect a push notification from three days ago to still be relevant.

**SMS** sits between the two. It is high-visibility like push, but more appropriate for one-time passcodes and delivery notifications where the user may not have the app installed.

# Setting up push notifications

Push notifications on mobile require a provider. Apple devices use APNs (Apple Push Notification service); Android devices use FCM (Firebase Cloud Messaging). Appwrite supports both.

Start by adding a provider in the Appwrite Console under **Messaging** > **Providers** > **Add provider** > **Push notification**. Select APNs or FCM and supply your credentials. For APNs, you need an authentication key from your Apple Developer account. For FCM, you need a service account JSON from the Firebase Console.

Once the provider is configured, your app needs to register a push target for each logged-in user. A push target links a device token to an Appwrite account.

For Android with FCM, fetch the registration token after Firebase initializes and create a push target when the user logs in:

```kotlin
val session = account.createEmailPasswordSession(email, password)
val target = account.createPushTarget(
    targetId = ID.unique(),
    identifier = fcmToken
)
```

For iOS with APNs, register for remote notifications in your app delegate, capture the device token, and create the push target the same way:

```swift
let target = try await account.createPushTarget(
    targetId: ID.unique(),
    identifier: apnsToken
)
```

FCM tokens can rotate, so you also need to handle the refresh event and call `updatePushTarget` with the new token to keep targeting accurate.

## Sending a push notification

With a provider and targets set up, you can send from the Console or via the Server SDK. Here is a Node.js example:

```js
const message = await messaging.createPush({
    messageId: ID.unique(),
    title: 'Your order has shipped',
    body: 'Estimated delivery: tomorrow by 5pm.',
    users: ['user-id-here'],
});
```

The `users` array accepts Appwrite user IDs. You can also pass `targets` (specific device tokens) or `topics` (groups of subscribers) depending on how broadly you want to reach.

# Setting up email

Email in Appwrite Messaging requires an SMTP provider. Appwrite supports Mailgun and SendGrid. Add one under **Messaging** > **Providers** > **Add provider** > **Email**, then follow the configuration wizard.

Users who signed up with email and password already have an email target attached to their account. For users who signed up another way, you can add an email target programmatically:

```js
const target = await users.createTarget({
    userId,
    targetId: ID.unique(),
    providerType: sdk.MessagingProviderType.Email,
    identifier: 'user@example.com',
    providerId
});
```

## Sending an email

```js
const message = await messaging.createEmail({
    messageId: ID.unique(),
    subject: 'Your invoice from Acme',
    content: '<h1>Invoice #1042</h1><p>Due: April 1, 2026</p>',
    users: ['user-id-here'],
});
```

For HTML email, pass the full markup in the `content` field. If you are sending plain text, leave out HTML tags. Appwrite handles delivery through the configured SMTP provider.

# Targeting with topics

For broadcast scenarios, like pushing a feature announcement to all users on a specific plan, topics are the right tool. Create a topic in the Console or via the API, subscribe users or targets to it, then send to the topic ID instead of individual user IDs:

```js
const message = await messaging.createPush({
    messageId: ID.unique(),
    title: 'New feature: dark mode',
    body: 'Dark mode is now available in settings.',
    topics: ['pro-plan-users'],
});
```

Appwrite resolves the topic to all subscribed targets automatically. You do not need to paginate through user lists or build your own fanout logic.

# Scheduling messages

Both push and email support scheduled delivery. Add a `scheduledAt` field with an ISO 8601 timestamp and Appwrite handles the rest:

```js
const message = await messaging.createEmail({
    messageId: ID.unique(),
    subject: 'Weekly digest',
    content: digest,
    topics: ['all-users'],
    scheduledAt: '2026-04-01T09:00:00+00:00',
});
```

This is useful for newsletters, reminder sequences, and any situation where you want to queue messages in advance.

# Get started with Appwrite Messaging

- [Appwrite Messaging overview](/docs/products/messaging)
- [Send push notifications](/docs/products/messaging/send-push-notifications)
- [Send email messages](/docs/products/messaging/send-email-messages)
- [Sign up for Appwrite Cloud](https://cloud.appwrite.io)

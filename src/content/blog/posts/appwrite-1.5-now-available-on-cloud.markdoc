---
layout: post
title: Appwrite 1.5 is now available on Cloud
description: This release was highly anticipated by the community, and we're very happy to finally share the great news.
date: 2024-04-29
lastUpdated: 2026-06-29
cover: /images/blog/appwrite-1.5-now-available-on-cloud/cloud15.avif
timeToRead: 8
author: eldad-fux
category: product, announcement
featured: false
faqs:
  - question: "What was new in Appwrite 1.5 on Cloud?"
    answer: "Appwrite 1.5 on Cloud brought the products announced at Init to the managed platform, including [Messaging](/docs/products/messaging) for SMS, email, and push notifications, support for many new OAuth providers in [Auth](/docs/products/auth), and product improvements across [Databases](/docs/products/databases), [Storage](/docs/products/storage), and [Functions](/docs/products/functions)."
  - question: "What does Appwrite Messaging support?"
    answer: "[Messaging](/docs/products/messaging) lets you send SMS, email, and push notifications through a single API. It integrates with providers like Twilio, APNS, Firebase Cloud Messaging, Vonage, Sendgrid, and Mailgun, so you can pick the providers that match your existing stack."
  - question: "Can I send push notifications to iOS and Android with Appwrite?"
    answer: "Yes. Push delivery is routed through Apple Push Notification service for iOS and Firebase Cloud Messaging for Android. You configure each provider in the Console, then call the messaging API from a Server SDK to send notifications to your users."
  - question: "Do I need to self-host Appwrite to use new features?"
    answer: "No. Once a release lands on Appwrite Cloud, new products and features are available to Cloud projects without any work on your end. Self-hosting remains an option if you want to run Appwrite on your own infrastructure."
  - question: "What is the difference between Appwrite Cloud and self-hosted Appwrite?"
    answer: "Appwrite Cloud is the managed platform run by the Appwrite team, with built-in scaling, monitoring, and updates. Self-hosted Appwrite is the same open-source product that you run on your own servers, where you control versions, infrastructure, and operations."
---

After announcing many new products and features during [Init](/init) as part of the 1.5 release, we saw great excitement within the community to get started with the newly presented Appwrite 1.5. Although already available on self-hosted, the new Appwrite products and features were yet to be released to Cloud. Today, we are excited to say the wait is finally over as we announce the release of Appwrite 1.5 to the Cloud.

To refresh your mind, here is an overview of all products and features released to Cloud and how you can get started.

# Messaging

With just a few lines of backend code, you can now set up a full-functioning messaging service for your application that covers three significant communication channels under one unified API using Cloud. You can send SMS, email, and push notifications through popular third-party providers like [Twilio](/blog/post/simplify-messaging-twilio), APNS, Firebase Cloud Messaging, Vonage, Sendgrid, and Mailgun.

## Highlights

- Unified platform for SMS, email, and push notifications.
- Integration with leading third-party messaging services.
- Simplified backend code for messaging services.
- A fully functional Messaging Console for organizing messages, recipients, and providers.

## Emails

With Appwrite Messaging you can send custom email messages to your app's users. Appwrite supports [Mailgun](/docs/products/messaging/mailgun/) and [Sendgrid](/docs/products/messaging/sendgrid/) as SMTP providers. You must configure one of them as a provider before you can get started.

Once you have finished the setup, you can send emails using a Server SDK. To send an email immediately, you can call the `createEmail` endpoint with the schedule left empty.

Here is what this would look like using Swift.

```swift
import Appwrite

let client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<PROJECT_ID>")                 // Your project ID
    .setKey("919c2d18fb5d4...a2ae413da83346ad2") // Your secret API key

let messaging = Messaging(client)

let message = try await messaging.createEmail(
    messageId: ID.unique(),
    subject: "April's Newsletter",
    content: newsletterContent,
    topics: ["news-letter"]
    draft: xfalse,
    html: xtrue,
    scheduledAt: "2024-04-29T20:55:41+0000"
)
```

Learn how to get started with [email in our documentation](/docs/products/messaging/send-email-messages).

## SMS

To send SMS messages to you users you will need to add SMPT provider. Appwrite supports [Twilio](/docs/products/messaging/twilio/), [MSG91](/docs/products/messaging/msg91/), [Telesign](/docs/products/messaging/telesign/), [Textmagic](/docs/products/messaging/textmagic/), and [Vonage](/docs/products/messaging/vonage/).

You can send SMS messages using a Server SDK. To send SMS messages immediately, you can call the `createSms` endpoint without passing either the draft or `scheduled` parameters.

```swift
import Appwrite

let client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1")  // Your API Endpoint
    .setProject("<PROJECT_ID>")                  // Your project ID
    .setKey("919c2d18fb5d4...a2ae413da83346ad2")  // Your secret API key

let messaging = Messaging(client)

let message = try await messaging.createSms(
    messageId: ID.unique(),
    content: "Don't forget your Wednesday meeting at 10am!",
    users: ["413da83346a"]
)
```

Learn how to get started with [SMS in our documentation](/docs/products/messaging/send-sms-messages).

## Push notifications

If you're building a mobile app, note that push notifications must be sent through third-party providers, like Apple Push Notification service and Firebase Cloud Messaging. The push notification APIs for Apple and Android devices can only be accessed through these services.

You must configure these services before you can send your first push notification.

To send with APNS, you must first register for remote notifications in your app delegate's application using the (_:didFinishLaunchingWithOptions:) method.

```swift
func application(
    _ application: UIApplication,
    didFinishLaunchingWithOptions launchOptions: [UIApplication.LaunchOptionsKey : Any]? = nil
) -> Bool {
    // Register for remote notifications
    UNUserNotificationCenter.current().delegate = self

    let authOptions: UNAuthorizationOptions = [.alert, .badge, .sound]
    UNUserNotificationCenter.current().requestAuthorization(
        options: authOptions,
        completionHandler: { granted, error in
            DispatchQueue.main.async {
                if granted {
                    application.registerForRemoteNotifications()
                }
            }
        }
    )

    return true
}
```

Learn how to set up push notifications for both Apple and Android in our docs.

{% cards %}
{% cards_item href="/docs/products/messaging/apns" title="APNS" icon="icon-apple" %}
Configure APNs for push notification to Apple devices.
{% /cards_item %}
{% cards_item href="/docs/products/messaging/fcm" title="FCM" icon="web-icon-firebase" %}
Configure FCM for push notification to Android and Apple devices.
{% /cards_item %}
{% /cards %}

Read the [Messaging announcement](/blog/post/announcing-appwrite-messaging) for a full overview of capabilities.

# Improved support for Server-Side Rendering (SSR)

Appwrite Cloud 1.5 brings enhanced support for SSR authentication patterns, optimizing the platform for use with frameworks like [NextJS](/docs/tutorials/nextjs-ssr-auth/step-1), [SvelteKit](/docs/tutorials/sveltekit-ssr-auth/step-1), and [Nuxt](/docs/tutorials/nuxt-ssr-auth/step-1). This update introduces official methods for generating and accessing sessions server-side, allowing for seamless session cookies management and authorized requests, thus bridging the gap between client and server-side rendering.

## Highlights

- Enhanced SSR authentication support.
- Improved session management for SSR frameworks.
- Official methods for session generation and access.

The big change is that Appwrite now allows you to generate and access sessions server side to set session cookies and use these sessions to authorize future requests.

```tsx
const session = await account.createEmailPasswordSession({
  email,
  password
})
console.log(session.secret) // Output: 'eyJpZCI...sdfahfkjjy'
```

Using the new `setSession` method, we can now retrieve a session secret from our cookies and authorize users to perform authenticated request to our server.

```tsx
client.setSession(session.secret)
const currentUser = await account.get()
```

Read the [SSR announcement](/blog/post/introducing-support-for-server-side-rendering) to get a full overview of capabilities or visit our documentation to get started.

# Two-Factor Authentication (2FA)

Security takes a front seat with the addition of Two-Factor Authentication (2FA). This new feature enables an additional layer of security by requiring a second form of authentication. You can easily implement 2FA using Appwrite's straightforward methods for challenge creation and solution, offering users the option to authenticate via phone, email, or TOTP with authenticator apps.

## Highlights

- Enhanced security with Two-Factor Authentication.
- Easy implementation of 2FA challenges.
- Support for multiple authentication methods, including phone, email, and TOTP.
- Can be used in conjunction with any existing authentication method you have implemented using Appwrite Auth.

## Enabling 2FA

To use 2FA, it needs to be enabled on a user's account. This can be achieved by calling account.updateMFA().

```js
import { Client, Account } from "appwrite";

// Init SDK
const client = new Client();

client
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<PROJECT_ID>'); // Your project ID

const account = new Account(client);

// Include any account creation/management steps

const mfa = await account.updateMFA({
    enabled: true
}); // Enables 2FA
```

Read the [2FA announcement](/blog/post/announcing-two-factor-authentication) for a full overview of capabilities, or visit our [documentation](/docs/products/auth/mfa) to get started.

# New Database operators

New Database operators `contains` and `or`, providing greater control and flexibility for database queries. These operators allow for partial text matches, array element matching, and logical OR queries, significantly enhancing the capability to handle complex data retrieval and manipulation tasks.

## Highlights

- New `contains` and `or` database operators.
- Enhanced query flexibility and control.
- Support for complex data retrieval and manipulation.

## Adding operators

The contains operator is a great addition to the existing text search operators such as startsWith & endsWith, and can be used in combination with these two.

```js
tablesDB.listRows({
    databaseId: '<DATABASE_ID>',
    tableId: '<TABLE_ID>',
    queries: [
        Query.contains('content', ['happy', 'love'])
    ]
});
```

To use the OR operator pass Query.or([...]) to the queries array and provide at least two queries within the nested array.

```js
tablesDB.listRows({
    databaseId: '<DATABASE_ID>',
    tableId: '<TABLE_ID>',
    queries: [
        Query.or([
            Query.contains('name','ivy'),
            Query.greaterThan('age',30)
        ])
    ]
});
```

Read the [Database operators announcement](/blog/post/introducing-new-database-operators) for a full overview of capabilities, or visit our [documentation](/docs/products/databases/queries) to get started.

# Enum SDK Support

Enum SDK support expands the versatility and usability of Appwrite's SDKs across different programming scenarios.

## Highlights

- Enum support improves type safety and code readability.

## Enums and OAuth

One of the most common examples of SDK Enums is OAuth providers. To log in with Apple, you must pass the Apple string as the provider. With enums, you'll be able to pass OAuthProvider.Apple instead.

```js
import { Client, Account, OAuthProvider } from "appwrite";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>');

const account = new Account(client);

account.createOAuth2Session({
    provider: OAuthProvider.Apple
});
```

Read the [Enum SDK support](/blog/post/introducing-enum-sdk-support) announcements for a full overview of capabilities, and visit our [Enum](/docs/sdks#enums) documentation to get started.

# Custom token login

One feature that was not announced during Init but was recently released is custom token login. Tokens are short-lived secrets created by an [Appwrite Server SDK](/docs/sdks#server) that can be exchanged for a session by a [Client SDK](/docs/sdks#client) to log in users. You may already be familiar with tokens if you checked out [Magic URL login](/docs/products/auth/magic-url), [Email OTP login](/docs/products/auth/email-otp), or [Phone (SMS) login](/docs/products/auth/phone-sms).

Custom token allows you to use a [Server SDK](/docs/sdks#server) to generate tokens for your own auth implementations. This allows you to code your own authentication methods using Appwrite Functions or your own backend. You could implement username and password sign-in, captcha-protected authentication, phone call auth, and much more. Custom tokens also allow you to  integrate Appwrite with external authentication providers such as Auth0, TypingDNA, or any provider trusted by your users.

## Creating your custom token

Once you have prepared your own login flow in an Appwrite Function or a server integration, you can use the [Create token](/docs/references/1.5.x/server-nodejs/users#createToken) endpoint of the [Users API](/docs/products/auth/users) to generate a token.

```swift
// Server-side code
let users = Users(client)

let token = try await users.createToken(userId: "<USER_ID>")
let secret = token.secret
```

Then, pass this token to the client through email, magic links, texts, or other methods so they can login on their client app.

```swift
// Client-side code
let account = Account(client);

let session = try await account.createSession(
    userId: "<USER_ID>",
    secret: "<SECRET>");
```

Visit our [documentation](/docs/products/auth/custom-token) to learn more.

# Get started with Appwrite 1.5 on Cloud

This release brings you more flexibility, security, and safety when building with the Cloud. With the introduction of Messaging, we deliver a highly requested product and fulfill developer needs. As we continue to evolve Appwrite, we strive to deliver the best backend for your product, no matter your needs. We thank you for your support and look forward to building the future together.

To get started, visit our documentation, YouTube channel, or get support on Discord.

- [Documentation](/docs)
- [Discord](https://appwrite.io/discord)
- [YouTube](https://www.youtube.com/channel/UCtBJ1v69gm8NgbCju_03Fiw)

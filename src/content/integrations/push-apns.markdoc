---
layout: integration
title: Push notifications with APNs
description: Integrate Apple Push Notification Service (APNs) with Appwrite Messaging to send remote notifications to your users' Apple devices.
date: 2024-07-30
featured: false
isPartner: true
isNew: false
cover: /images/integrations/push-apns/cover.avif
category: messaging
product: 
    avatar: '/images/integrations/avatars/apple.avif'
    vendor: Apple
    description: 'Apple Push Notification Service is an Apple platform that enables developers to send notifications to iOS devices, macOS computers, and other Apple devices.'
platform: 
    - 'Self-hosted'
    - 'Cloud'
images: 
    - /images/integrations/push-apns/cover.avif
    - /images/integrations/push-apns/apple-developer-program.avif
    - /images/integrations/push-apns/provider.avif
---

Apple Push Notification Service (APNs) is an Apple platform that enables developers to send notifications to iOS devices, macOS computers, and other Apple devices. It allows app developers to send remote notifications, alerting users to new content, messages, updates, and app-specific information.

# How does the integration work?

You can use the APNs provider in Appwrite Messaging to send push notifications to your users’ Apple devices. APNs is a best-effort service and will attempt to deliver messages to your device when it's online and available again. APNs will save the last message for 30 days or less and attempt delivery as soon as it's online.

# How to implement

To implement the APNs provider in Appwrite Messaging, there are several steps you must complete:

## Step 1: Sign up on Apple Developer

First, [sign up for the Apple Developer program](https://developer.apple.com/programs).

![Apple developer program](/images/integrations/push-apns/apple-developer-program.avif)

Before you configure any other details, you must create an **App ID** for our app. To do that, go to XCode and open the `ios` folder in your source code. Navigate to the **Signing & Capabilities** tab, click the **+ Capability** button, search for `Push Notifications`, and double-click the result to add it. Then, modify the **Bundle Identifier** to a unique string by prefixing it with any letters of your choice and then change the **Team** to your Apple Developer team. This will automatically create an **App ID** for your app.

Head to the Apple Developer account and save the following details:

| Field name |  |
| --- | --- |
| Bundle ID | Head to **Apple Developer account** > **Program resources** > **Certificates, Identifiers & Profiles** > **Identifiers** |
| Team ID | Head to **Apple Developer account** > **Membership details** > **Team ID** |
| Authentication key (.p8 file) | Head to **Apple Developer account** > **Program resources** > **Certificates, Identifiers & Profiles** > **Keys**. Create a key and give it a name. Enable the **Apple Push Notifications service (APNs)**, and register your key. |
| Authentication key ID | Head to **Apple Developer account** > **Program resources** > **Certificates, Identifiers & Profiles** > **Keys**. Click on your key to view details. |

## Step 2: Add APNs provider to your Appwrite project

For this step, you must [create an account on Appwrite Cloud](https://cloud.appwrite.io/register) or [self-host Appwrite](https://appwrite.io/docs/advanced/self-hosting) if you haven’t already. In your Appwrite project, head over to the **Messaging** page, click on the **Providers** tab, and **create a new push notifications provider**. 

Enter the **team ID**, **bundle ID**, **authentication key ID**, and **authentication key (.p8 file)** that you saved from your Apple Developer account. In case your app is signed with a development provisioning profile, enable **Sandbox** mode.

![Apple provider](/images/integrations/push-apns/provider.avif)

## Step 3: Test the provider

Follow the [Send push notifications](https://appwrite.io/docs/products/messaging/send-push-notifications) flow to test your provider.

# Read more about APNs and Appwrite Messaging

If you would like to learn more about APNs and Appwrite Messaging, we have some resources that you should visit:

- [Set up the APNs provider in Appwrite Messaging](https://appwrite.io/docs/products/messaging/apns)
- [Send push notifications through Appwrite Messaging](https://appwrite.io/docs/products/messaging/send-push-notifications)
- [Best practices for sending push notifications](https://appwrite.io/blog/post/push-notifications-best-practices)
- [Appwrite Messaging API reference](https://appwrite.io/docs/references/cloud/server-nodejs/messaging)
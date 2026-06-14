---
layout: post
title: Best practices for sending push notifications
description: Learn about the different types of push notifications and best practices for sending them.
date: 2024-02-26
cover: /images/blog/push-notifications-best-practices.avif
timeToRead: 6
author: aditya-oberai
category: product
featured: false
faqs:
  - question: "What is the difference between a push notification and an in-app notification?"
    answer: "A push notification is delivered by the operating system and appears even when the app is closed, using services like FCM or APNS. An in-app notification only shows while the user has the app open and is handled by your application code directly."
  - question: "How many push notifications per week is too many?"
    answer: "Research from Helplama showed that 2 to 5 push notifications per week already pushes 43% of users to disable notifications, and 6 to 10 per week leads to 30% uninstalling the app. Aim for fewer, well-timed, high-value sends rather than frequent broadcasts."
  - question: "How does Appwrite Messaging send push notifications?"
    answer: "[Appwrite Messaging](/docs/products/messaging) ships with adapters for Firebase Cloud Messaging (FCM) and Apple Push Notification Service (APNS). You configure the provider once, then send pushes to users, topics, or targets through a single API."
  - question: "When should I use a silent push notification?"
    answer: "Silent pushes wake the app in the background to refresh data or sync state without showing anything to the user. They are best for keeping content up to date or triggering background work, not for messages the user should see."
  - question: "What is the best way to ask users for push notification permission?"
    answer: "Ask in context, right after the user takes an action that benefits from notifications, instead of on first launch. Explain the value in one sentence, and always provide an in-app way to opt back in or out later."
---

Push notifications are messages that appear on a mobile device or computer from an app or website. They serve various purposes, such as alerts, promotions, reminders, and more.

They are essential for delivering real-time updates and alerts, enhancing the overall user experience. Push notifications can also be used to increase engagement and retention and even for security purposes.

# Different types of push notifications

Before we dive right into the best practices of sending a push notification, let us learn about the different types and the purpose they serve:

- **Standard push notifications**: These are the basic form of push notifications, often used for general purposes such as updates, news, or alerts. They typically include a message and can sometimes have a call to action.
- **Rich push notifications**: These notifications are enhanced with media like images, videos, or sound. Rich push notifications are more engaging and can be used for marketing campaigns, feature highlights, or to provide a more immersive user experience.
- **Interactive push notifications**: These notifications allow users to interact directly from the notification itself without opening the app. Actions can include replying to a message, liking a post, or snoozing an alarm.
- **Geotargeted push notifications**: Triggered when a user enters or leaves a specific geographic location. These are particularly useful for retail apps, offering special deals when a user is near a store, or for travel apps providing location-specific information.
- **Segmented notifications**: These are targeted to specific segments of your user base based on factors like user behavior, preferences, or demographics. This segmentation ensures that users receive relevant and personalized content.
- **Behavioral notifications**: Triggered by specific user actions within the app, like abandoning a shopping cart or completing a level in a game. These notifications are personalized based on the user's behavior.
- **Transactional notifications**: Used for sending information about transactions a user has performed, such as purchase confirmations, shipping updates, or banking alerts.
- **Reminder notifications**: Sent to remind users about an upcoming event or an incomplete task or to re-engage users who haven’t used the app for a specific period.
- **Silent notifications**: These do not alert the user or appear on the device's lock screen. Instead, they quietly deliver data to the app, which can trigger an app update or content refresh.
- **Persistent notifications**: These remain on the user's screen until they are acted upon and are typically used for critical alerts that require immediate attention.

# Best practices for sending push notifications

[Helplama](https://helpdesk.helplama.com/what-do-consumers-think-about-push-notifications/) discovered that sending 2-5 push notifications per week results in 43% of the app users disabling push notifications. On the other hand, sending 6-10 push notifications per week leads to 30% of users dropping off the app entirely.

The content of the messages plays a crucial role. Here are some best practices to retain users:

- **Prioritize user consent and preferences**: Always seek user consent for notifications and provide clear options for users to manage their preferences. Respecting user choices builds trust and enhances user experience.
- **Personalize and segment**: Tailor notifications based on user behavior, preferences, and demographics. Segmenting your audience ensures that messages are relevant and engaging for each user group.
- **Craft clear and concise messages**: Ensure that your messages are straightforward, easy to understand, and convey the core message quickly.
- **Utilize rich media wisely**: Incorporate images, videos, or sounds to enrich notifications, but do so sensibly to avoid overwhelming users.
- **Time notifications appropriately**: Send notifications at times when users are most likely to be receptive. Consider time zones and user routines, and avoid disruptive hours.
- **Maintain a balanced frequency**: Overloading users with notifications can lead to annoyance and app uninstalls. Find a balance that keeps users informed without overwhelming them.
- **Implement A/B testing**: Regularly test different versions of notifications to see what works best in terms of content, timing, and frequency.
- **Incorporate a strong Call to Action (CTA)**: Encourage user engagement with a clear and compelling CTA.
- **Measure and analyze performance**: Use analytics to monitor the performance of your notifications. This data is invaluable for optimizing strategy and improving engagement.
- **Ensure relevance and value**: Notifications should always offer something of value to the user, whether it's informative, entertaining, or promotional.
- **Adapt to platform differences**: Tailor your approach to fit the guidelines and best practices of different platforms (iOS, Android, web, etc.).
- **Use notifications for important alerts only**: Reserve push notifications for important or urgent messages to maintain their effectiveness.
- **Provide an easy opt-out option**: Users should be able to easily opt out of receiving notifications, which respects their preferences and improves overall satisfaction.

# Enabling push notifications for your app using Appwrite

The newly released Appwrite Messaging substantially simplifies your process of sending out push notifications to your application’s users. We have added simple-to-use adapters for Firebase Cloud Messaging (FCM) and Apple Push Notification Service (APNS) to let you send push notifications to let you send push notifications to both Android and iOS users.

```server-nodejs
const sdk = require('node-appwrite');

// Init SDK
const client = new sdk.Client();

const messaging = new sdk.Messaging(client);

client
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')    // Your API Endpoint
    .setProject('<PROJECT_ID>')                    // Your project ID
    .setKey('919c2d18fb5d4...a2ae413da83346ad2');   // Your secret API key

const message = await messaging.createPush({
    messageId: sdk.ID.unique(),
    title: 'Init_ Day 0',
    body: 'Appwrite Messaging is here!',
    topics: ['init-day-0']
});
```

# Resources

Visit our documentation to learn more about push notifications, join us on Discord to be part of the discussion, view our blog and YouTube channel, or visit our GitHub repository to see our open-source code.

- [Docs](/docs/products/messaging/send-push-notifications)
- [Discord](https://appwrite.io/discord)
- [Blog](/blog)
- [YouTube](https://www.youtube.com/channel/UCtBJ1v69gm8NgbCju_03Fiw)
- [GitHub](https://github.com/appwrite/appwrite)

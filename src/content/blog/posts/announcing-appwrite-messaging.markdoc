---
layout: post
title: "Announcing Messaging: Push, Email and SMS"
description: You can now send emails, SMS and push notifications with Appwrite’s easy-to-use APIs.
date: 2024-02-26
lastUpdated: 2026-06-29
cover: /images/blog/messaging-announcement.avif
timeToRead: 6
author: eldad-fux
category: init, announcement, product
featured: false
faqs:
  - question: "What is Appwrite Messaging?"
    answer: "[Appwrite Messaging](/docs/products/messaging) is a unified API for sending emails, SMS, and push notifications from your Appwrite project. You configure third-party providers (like SendGrid or Twilio) once, then send messages to topics, users, or individual targets without writing separate code for each channel."
  - question: "Which providers does Appwrite Messaging support?"
    answer: "For email: Mailgun, SendGrid, and any SMTP server. For SMS: Twilio, MSG91, Telesign, Textmagic, and Vonage. For push notifications: APNs for Apple devices and FCM for Android (and Apple) devices. You can mix providers per project if needed."
  - question: "What is the difference between topics, users, and targets in Messaging?"
    answer: "A target is a specific destination (an email address, phone number, or device push token). A user holds all the targets associated with a single Appwrite account. A topic is a named group of targets that you can publish a message to in one call. Newsletters typically use topics, alerts use users, and location-specific notifications use individual targets."
  - question: "Can I send a message from an Appwrite Function?"
    answer: "Yes. You can call any Messaging method from a server SDK inside an [Appwrite Function](/docs/products/functions). Combine this with event triggers (for example, on user creation or row update) to send automated welcome emails, login alerts, order confirmations, and other transactional messages."
  - question: "Can I schedule messages for later?"
    answer: "Yes. Each Messaging API call accepts a scheduled send time, and you can also pick a date in the Appwrite Console when drafting a message. This is useful for appointment reminders, time-zoned promos, and announcements that should land at a specific moment."
  - question: "Does Messaging work the same on Appwrite Cloud and self-hosted?"
    answer: "Yes. The Messaging API and Console are the same on Cloud and self-hosted. You still need to bring your own provider credentials (Twilio account, SendGrid API key, and so on), since Appwrite does not act as the underlying delivery network."
---

It has been requested over and over again by Appwrite developers. It's a product that is hard to build from scratch and much needed for you and your application to have effective communication with your users. Therefore, we're excited to present you with **Messaging**. With just a few lines of backend code, you can set up a full-functioning messaging service for your application that covers three significant communication channels under one unified API.

# The power of communication

Effective communication with your users is key to high user engagement and retention. Consistent but judicious use of emails, SMS, and push notifications is the key to a highly engaged and loyal user base.

Timely and personalized messages delivered to the users’ devices and inboxes help them stay informed and connected, even when they’re not actively using your app. This is where Messaging comes in, a product built to help you streamline email, SMS, and push notifications.

# All your messages in one place

As mentioned, Messaging covers three communication channels under one unified API, allowing you to send email, SMS, and push notifications from your Appwrite project. It connects with a variety of third-party providers to deliver you messages. See this table to get an overview of what message types you can send with each provider.


## Push Notifications
{% cards %}
{% cards_item href="/docs/products/messaging/apns" title="APNS" icon="icon-apple" %}
Use APNs for push notification to Apple devices.
{% /cards_item %}
{% cards_item href="/docs/products/messaging/fcm" title="FCM" icon="web-icon-firebase" %}
Use FCM for push notification to Android and Apple devices.
{% /cards_item %}
{% /cards %}

## Email
{% cards %}
{% cards_item href="/docs/products/messaging/mailgun" title="Mailgun" icon="web-icon-mailgun" %}
Use Mailgun to send emails
{% /cards_item %}
{% cards_item href="/docs/products/messaging/sendgrid" title="Sendgrid" icon="web-icon-sendgrid" %}
Use Sendgrid to send emails
{% /cards_item %}
{% cards_item href="/docs/products/messaging/smtp" title="SMTP" icon="icon-mail" %}
Use SMTP to send emails
{% /cards_item %}
{% /cards %}

## SMS
{% cards %}
{% cards_item href="/docs/products/messaging/twilio" title="Twilio" icon="icon-twilio" %}
Use Twilio for SMS notifications to mobile devices.
{% /cards_item %}
{% cards_item href="/docs/products/messaging/msg91" title="MSG91" icon="icon-msg91" %}
Use MSG91 for SMS notifications to mobile devices.
{% /cards_item %}
{% cards_item href="/docs/products/messaging/telesign" title="Telesign" icon="icon-telesign" %}
Use Telesign for SMS notifications to mobile devices.
{% /cards_item %}
{% cards_item href="/docs/products/messaging/textmagic" title="Textmagic" icon="web-icon-textmagic" %}
Use Textmagic for SMS notifications to mobile devices.
{% /cards_item %}
{% cards_item href="/docs/products/messaging/vonage" title="Vonage" icon="icon-vonage" %}
Use Vonage for SMS notifications to mobile devices.
{% /cards_item %}
{% /cards %}

# Message your existing users

With Messaging, you can deliver messages to groups of users through topics or address individual users' email, phone, or devices through targets. Messaging deeply integrates with integrated existing accounts in Appwrite out of the box. You can immediately start messaging your existing users and integrate deeply with other Appwrite features.

You can address recipients of messages at three levels:

- Topics, which hold a group of targets.
- Users, which holds all targets associated with their account.
- Targets, which are individual emails, phone numbers, and channels.

You can publish announcements and newsletters through topics, deliver user-specific promotions and alerts to all user's available targets, and address individual targets for location-based or real-time alerts.

![Creating a message from the Console](/images/blog/message-overview.avif)

# Deliver messages your way

Draft, schedule, and send your messages in the Appwrite Console, or use Appwrite SDKs to send messages through code.

Drafting directly in the Appwrite Console is great for promo messages, newsletters, and other announcements. You can see a preview of what your message will look like right in the console and control how your message will look before sending.

For real-time alerts, you can programmatically send messages to your users and integrate with Appwrite Functions triggered by platform events. This allows you to deliver automated messages like invoices, login alerts, order confirmations, and new message alerts.

You can send messages immediately or schedule them for future delivery. The Appwrite Console allows you to set dates and times from the UI easily. This is a great addition for use cases like appointment reminders, alerts, promotional messages, and any time you need to deliver a message at a specific time.

# Get started with Messaging

To get started with messaging, you connect a provider, add topics and targets, and send your first message today for your project.

We will be adding more adopters over time and are open to contributions. Visit our [community page](/community) to learn more about contributing or add your issues directly to [GitHub](https://github.com/appwrite/appwrite/issues).

Visit our documentation to learn more about Messaging, join us on Discord to be part of the discussion, view our blog and YouTube channel, or visit our GitHub repository to see our open-source code.

- [Docs](/docs/products/messaging)
- [Discord](https://appwrite.io/discord)
- [Blog](/blog)
- [YouTube](https://www.youtube.com/channel/UCtBJ1v69gm8NgbCju_03Fiw)
- [GitHub](https://github.com/appwrite/appwrite)

Messaging will be available as part of the Appwrite 1.5 release on [GitHub](https://github.com/appwrite/appwrite) and [Cloud](https://cloud.appwrite.io/register) in March 2024.

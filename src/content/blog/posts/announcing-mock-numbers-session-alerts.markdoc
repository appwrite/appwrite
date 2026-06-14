---
layout: post
title: "Introducing mock numbers and session alerts in Auth"
description: In response to popular demand, we're thrilled to announce two significant improvements to Appwrite Auth.
date: 2024-08-23
cover: /images/blog/init-day4/cover.avif
timeToRead: 4
author: luke-silver
category: init, announcement, product
featured: false
faqs:
  - question: "What are mock numbers in Appwrite Auth?"
    answer: "Mock numbers let you configure phone numbers that authenticate with a fixed, predefined verification code instead of receiving a real SMS. They're meant for testing and app review scenarios, where sending real OTPs is impractical or expensive. The mock number behaves like a normal phone in your [Appwrite Auth](/docs/products/auth) flow."
  - question: "How do I configure mock phone numbers?"
    answer: "Open the Appwrite Console, go to your project, and pick Auth, then Security. Add the phone numbers you want to mock along with their fixed verification codes. When those numbers are used in `createPhoneToken` and `createSessionWithPhone`, no SMS is sent and the predefined OTP is accepted."
  - question: "Are mock numbers safe to use in production?"
    answer: "Mock numbers are intentionally fake and should only be used for testing or controlled app-review flows. Don't expose them to real users and remove or rotate them before launch. Treat the predefined codes as test credentials, not production secrets."
  - question: "What are session alerts?"
    answer: "Session alerts send an email to a user whenever a new session is created on their account, with details about the sign-in. This lets users notice unauthorized access quickly and revoke the session. Alerts are enabled by default on [Appwrite Cloud](/docs/products/auth) and can be toggled per project in Auth, then Security."
  - question: "Do session alerts work for users who haven't set an email?"
    answer: "No, the alert email requires a verified email address on the user account. First-time sign-ins without an email on file also skip the alert. Encourage users to add an email and verify it so they get the benefit of the security notification."
  - question: "Where can I enable session alerts for my project?"
    answer: "In the Appwrite Console, go to Auth, then Security, and toggle session alerts on. From that point, [Appwrite Auth](/docs/products/auth) emails users with session details when new sessions are created. The same screen also exposes other security settings like mock numbers and password policies."
---
We've listened to your feedback and are introducing two new features designed to simplify phone authentication testing and bolster account security.

Mock numbers allow you to set up a list of phone numbers with a predefined, static verification code, which is perfect for app reviews and testing. Session alerts will help your users maintain their account security.

Let's dive in!

# Mock numbers

Mock numbers allow you to configure a list of phone numbers that can sign in with specific, unchanging verification codes. No SMS is actually sent, making this perfect for testing environments and app review scenarios. 

Ready to implement mock numbers in your project? Here's how to get started:

First, configure your mock numbers. You can do it with the Appwrite console (go to your project, and then **Auth > Security**)

![Mock-numbers](/images/blog/init-day4/1.avif)

Now, in your Flutter app, you can use these mock numbers for authentication:

```dart
import 'package:appwrite/appwrite.dart';

Client client = Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>');

Account account = Account(client);

// Create account with phone number
final userId = ID.unique();
await account.createPhoneToken(
    userId: userId,
    phone: '+1655513432',
);

// Login with phone number and OTP
final session = await account.createSessionWithPhone(
    userId: userId,
    secret: '123456',
);
```

The mock numbers feature will be available for Pro users in 1.6.

# Session alerts

When you sign into your Appwrite Cloud account, you will receive an email telling you about the sign-in, including details about the session. 

End-users won't receive an email if it's their first time signing in, or if they haven't set up an email address.

![Email-session-alert](/images/blog/init-day4/2.avif)

This is a security feature that allows you to quickly secure your account if compromised. It's enabled by default for Cloud, and you can enable it for your Appwrite project in the **Auth > Security** menu:

![Session-alert](/images/blog/init-day4/3.avif)

# Get started

Mock numbers help you streamline testing and development by providing a controlled environment for phone authentication. By using predefined phone numbers and verification codes, you can rapidly iterate your apps without relying on real phone numbers. 

Session alerts empower users to protect their accounts and help prevent unauthorized access.

If you'd like to try these features out in your application, here are a few resources to get you started:

- [Read the mock numbers documentation](/docs/products/auth/phone-sms)
- [Join our Discord community](https://discord.gg/appwrite)
- [Learn about Appwrite Init](https://www.appwrite.io/init)

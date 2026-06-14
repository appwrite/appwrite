---
layout: post
title: "Introducing Enum SDK support: enhanced DX across SDKs"
description: A new feature that will reduce friction and improve your experience.
date: 2024-02-28
cover: /images/blog/enum-announcement.avif
timeToRead: 4
author: jake-barnby
category: product, announcement
featured: false
faqs:
  - question: "What are enums in the Appwrite SDKs?"
    answer: "Enums are named constants that the Appwrite SDKs expose for parameters with a fixed set of valid values, like OAuth providers, runtimes, and image formats. Instead of typing the raw string, you reference a typed value such as OAuthProvider.Apple."
  - question: "Why use enums instead of strings?"
    answer: "Enums give you autocomplete in your editor, compile time errors when you pass an invalid value, and self documenting code. They eliminate a class of typos that the old string based parameters used to hide."
  - question: "Which SDKs got enum support?"
    answer: "Enum support landed across all client and server SDKs as part of Appwrite 1.5. That includes Web, React Native, Flutter, Android, Apple, Node.js, Python, PHP, Ruby, Deno, Dart, .NET, Kotlin, Swift, and Go."
  - question: "Do I have to migrate my existing code to use enums?"
    answer: "No. The string values still work, so existing code keeps running. Enums are a quality of life improvement you can adopt gradually, starting with the parameters where you have hit typos in the past."
  - question: "Will using enums break older Appwrite servers?"
    answer: "No. Enums are a client side convenience that resolve to the same strings the API has always accepted. A 1.5 SDK still sends the same wire format to an Appwrite instance that understands those values."
  - question: "Where can I see the full list of supported enums?"
    answer: "Each SDK reference lists the enums it exposes. The SDKs page in the [Appwrite docs](/docs) has the per language references, and the changelog for each SDK release calls out new enum additions."
---
We are excited to announce a significant enhancement to the development experience across all Appwrite client and server-side SDKs, Enums SDK Support.

This enhancement is designed to streamline the development process, improve code quality, and facilitate seamless integration with Appwrite's backend services.

# What are Enums?

Enums, or enumerations, are a powerful programming construct that enables developers to define a set of named constants. These constants make code more readable and less error-prone by providing meaningful names for values rather than using numbers or strings directly. Enums are widely used for representing a set of possible values for a variable, making code easier to understand, maintain, and debug.

# Introducing Enum SDK support in Appwrite

Currently, when you want to use an endpoint with a predefined set of allowed values, you must look up the exact value in the documentation. This can be time-consuming and error-prone.

This new feature, however, ensures you can use more readable and self-documenting code instead of having to remember and type out raw values.

Bringing along benefits such as massively reducing friction when using routes that have predefined parameters. One of the most common examples of SDK Enums is OAuth providers. To log in with Apple, you must pass the `apple` string as the provider. With enums, you'll be able to pass `OAuthProvider.Apple` instead.

```client-web
import { Client, Account, OAuthProvider } from "appwrite";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>');

const account = new Account(client);

account.createOAuth2Session({
    provider: OAuthProvider.Apple,
    success: '<LINK_ON_SUCCESS>',
    failure: '<LINK_ON_FAILURE>',
    scopes: ['email', 'profile']
});
```

This new feature will improve the experience of building with Appwrite and lower the barrier for new developers to start. As always, we aim to make life easier, not harder, and we look forward to your feedback.

# Resources

Visit our documentation to learn more about Enums SDK support, join us on Discord to be part of the discussion, view our blog and YouTube channel, or visit our GitHub repository to see our source code.

- [Docs](/docs/sdks#enums)
- [Discord](https://appwrite.io/discord)
- [Blog](/blog)
- [YouTube](https://www.youtube.com/channel/UCtBJ1v69gm8NgbCju_03Fiw)
- [GitHub](https://github.com/appwrite/appwrite)

Enums will be available as part of the Appwrite 1.5 release on [GitHub](https://github.com/appwrite/appwrite) and [Cloud](https://cloud.appwrite.io/register) in March 2024.

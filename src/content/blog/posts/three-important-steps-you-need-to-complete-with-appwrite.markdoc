---
layout: post
title: Three important steps you need to complete before setting up your first Appwrite project
description: Are you getting errors when setting up your first project in Appwrite? Take a look at these steps.
date: 2024-02-15
lastUpdated: 2026-06-29
cover: /images/blog/three-important-steps-you-need-to-complete-with-appwrite/3-important-steps.avif
timeToRead: 3
author: dennis-ivy
category: product
faqs:
  - question: "What are the three steps to complete before using a new Appwrite project?"
    answer: "Add a platform (or generate an API key with scopes for server SDKs), add a hostname if you are using the Web SDK, and set proper permissions on your database tables and storage buckets. Skipping any of these usually leads to errors when making your first request."
  - question: "Why do I need to add a platform in Appwrite?"
    answer: "Platforms tell Appwrite which clients are allowed to connect to your project. You add a platform from the project overview to register a Web, Flutter, Android, Apple, or server SDK. Without it, your requests are rejected for security."
  - question: "Why am I getting a CORS error when calling Appwrite from my web app?"
    answer: "Most CORS errors happen because you have not added the right hostname to your web platform settings in Appwrite. Add the exact origin you are connecting from (including localhost during development) to fix the error. There is a deeper walkthrough in the [CORS error post](/blog/post/cors-error)."
  - question: "How do permissions work in Appwrite?"
    answer: "Permissions control who can read or write data in [Databases](/docs/products/databases) tables and [Storage](/docs/products/storage) buckets. You set them at the table, row, bucket, or file level. Without the right permissions, even authenticated users see errors or empty data."
  - question: "What is the difference between client and server SDKs in Appwrite?"
    answer: "Client SDKs run in your app and act on behalf of end users, so they use sessions and respect permissions. Server SDKs run in trusted environments and use API keys with explicit scopes. Pick the SDK that matches where your code runs and what level of access it needs."
  - question: "What scopes should I give my Appwrite API key?"
    answer: "Give the API key only the scopes it needs for the operations it performs, like `databases.read` or `users.write`. Avoid creating broad keys, especially for production. You can rotate keys and create separate ones for different services to limit blast radius."
---

When creating a new project, there are three important things you need to do to ensure you have things set up properly and can connect to your Appwrite backend.

Forgetting to complete one of these steps will result in errors later when trying to make your first request, so let’s jump in and see what they are so things are set up properly for your next project.

# 1 - Add a platform

After creating a new project in the Appwrite console, you’ll need to add a platform in order to connect an SDK. You can add a platform from the “overview” tab under the “Add a platform” section. From here, you’ll need to choose between one of the four [client-side SDK’s](/docs/sdks#client) or generate an API key with a [server-side SDK](/docs/sdks#server).

If you’re adding an API key to use one of the server SDK’s, you’ll be asked to set scopes for the key. This sets permissions, allowing us to dictate what permissions this API key has over certain parts of our application.

![Appwrite Console adding a platform](/images/blog/three-important-steps-you-need-to-complete-with-appwrite/add-platform.avif)

# 2 - Add a hostname

When using the Web SDK, you’ll need to set a hostname. This just tells Appwrite what origin you’ll be connecting from. Failing to set the right hostname will result in CORS error later, so be sure you pay attention when setting this. I just put out a full video and [an article about handling CORS errors](/blog/post/cors-error), so be sure to check that out if you have questions or face any problems.

![Hostname](/images/blog/three-important-steps-you-need-to-complete-with-appwrite/hostname.avif)

# 3 - Permissions

Before you can read and write data from an Appwrite database table or storage bucket, you’ll need to ensure you have the proper permissions set. This can be set from the “Settings” tab under the “Permissions” section inside a table or storage bucket.

![Permissions overview](/images/blog/three-important-steps-you-need-to-complete-with-appwrite/permissions.avif)

With these three steps to complete, you should be set to connect to an Appwrite backend from either a client or server SDK.

# Resources
Visit our documentation to get started, join us on Discord to be part of the discussion, or visit our blog and YouTube channel to learn more!

- [Docs](/docs)
- [Discord](https://appwrite.io/discord)
- [Blog](/blog)
- [YouTube](https://www.youtube.com/channel/UCtBJ1v69gm8NgbCju_03Fiw)

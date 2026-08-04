---
layout: post
title: "July product update: New Database features, Sites live, and Console improvements"
description: Get a full recap of products, features and programs we released in the month of July.
date: 2025-07-31
lastUpdated: 2026-06-29
cover: /images/blog/product-update-july-2025/cover.avif
timeToRead: 3
author: dennis-ivy
category: product
featured: false
callToAction: true
faqs:
  - question: "What major features did Appwrite ship in July 2025?"
    answer: "July included the general availability of [Appwrite Sites](/docs/products/sites), CSV import for Databases, the Bulk API, Database Upsert, encrypted text column support, and auto-increment columns. The release also included Console and deployment improvements plus richer OTP email previews."
  - question: "What is the Bulk API in Appwrite Databases?"
    answer: "The Bulk API lets you create, update, delete, or upsert many rows in a single request instead of looping through individual calls. It is useful for migrations, seeding, and syncing data between systems."
  - question: "What is a database upsert and when should I use it?"
    answer: "Upsert is a single operation that creates a row if it does not exist or updates it if it does. It removes the need to do a read followed by a conditional write, which simplifies idempotent sync flows and reduces round trips."
  - question: "What does CSV import do in [Appwrite Databases](/docs/products/databases)?"
    answer: "CSV import lets you bring a CSV file directly into an Appwrite table through the migration APIs. It is built for seeding tables, onboarding legacy data, and bulk loading without writing a custom script."
  - question: "How does encrypted text column support work?"
    answer: "Appwrite can encrypt text columns at rest in [Databases](/docs/products/databases) and [Storage](/docs/products/storage), so you do not have to implement encryption logic in your application code. You enable encryption on the column from the Console."
  - question: "What changed with OTP email previews?"
    answer: "Appwrite now surfaces the OTP code in the email preview, so end users can read the code from the notification without opening the message. This reduces friction on phones and improves login completion rates."
---

Welcome back to the July Product Update. This month, we have made many, many great releases!

Here’s what to expect:

* Appwrite Sites is now available to all
* Many new Databases features
* Console improvements
* Improved notification previews

Let’s dive in.

# Appwrite Sites live for all

![Sites live for all](/images/changelog/2025-07-24.avif)

Here’s an update many of you’ve been waiting for: Appwrite Sites is now live for all!

You can now host your applications directly from the Appwrite console, making Appwrite an all-in-one development platform to develop, deploy, and scale your apps.

[Read the announcement](/blog/post/announcing-appwrite-sites)

# CSV import

![CSV imports](/images/blog/announcing-csv-imports/cover.avif)

We started July with CSV import. Built on top of Appwrite's migration APIs, this Databases feature makes it easy to bring in large datasets, seed tables, or migrate structured data using only a CSV file.

[Learn more](/blog/post/announcing-csv-imports)

# Bulk API

![Bulk API](/images/blog/announcing-bulk-api/cover.avif)

You can now handle heavy data workloads with greater ease. With Bulk API, you can create, update, delete, or upsert multiple rows in a single API call.

This is especially useful for managing large datasets or syncing data between systems without multiple API calls.

[Learn more](/blog/post/announcing-bulk-api)

# Database Upsert

![Database Upsert](/images/blog/announcing-database-upsert/cover.avif)

Database Upsert lets you create or update a row with a single API call. If the row exists, it’s updated; if not, it’s created. This removes the need for manual existence checks, reduces extra requests, and simplifies your logic.

[Learn more](/blog/post/announcing-database-upsert)

# Encrypted text column support

![Encrypted ](/images/blog/announcing-encrypted-string-attributes/cover.avif)

We’ve added an extra layer of security to Appwrite Databases and Storage. This feature allows you to encrypt text columns at rest directly from the Appwrite console, without needing to implement manual encryption.

[Learn more](/blog/post/announcing-encrypted-string-attributes)

# Auto increment support

![Auto-increment](/images/blog/announcing-auto-increment-support/cover.avif)

Appwrite Databases now support auto-increment fields. A `$sequence` column is automatically managed in your table, increasing with each new row. This helps keep your data ordered without requiring manual handling.

[Learn more](/blog/post/announcing-auto-increment-support)

# Console and deployment improvements

![Console](/images/blog/product-update-july-2025/console.avif)

We've shipped several improvements to fix broken flows and clean up the developer experience across Sites, Functions, and the Appwrite Console.

A few of them include:

- Site screenshots now use the Storage API preview route
- The Git deployment modal now auto-fills your production branch
- Domain validation status now updates in real-time

And many more. View the full changelog for more details

[Go to Changelog](/changelog/entry/2025-07-29)

# Smarter notifications

![OTP](/images/blog/product-update-july-2025/otp.avif)

We’ve just added new previews for all Appwrite OTP emails. End users can now view the code/OTP without opening the message.

Here’s what improved:

- Key info is shown at the top
- Notifications are cleaner and easier to read
- Clearer copy makes actions quicker and reduces confusion

[Learn more](/docs/products/messaging)

# Community recognitions

![community.png](/images/blog/product-update-july-2025/community.avif)

This month, we’re excited to feature [**Idea Tracker**](https://idea-tracker-v2.appwrite.network/) as part of our Monthly Community recognitions.

**Idea Tracker** is an application that helps you save and track project ideas you plan to build.

A big shout-out to [Abhi Varde](https://x.com/varde_abhi).

If you'd like to participate in next month's Community Recognitions, [join our Discord server](https://appwrite.io/discord) to showcase your project.

# Engineering resources

**Read**

- [Building with Appwrite Sites templates](/blog/post/building-with-sites-templates)
- [Using $sequence to track row order in Appwrite](/blog/post/track-document-order-with-sequence)
- [Secure sensitive database fields with encrypted columns](/blog/post/encrypted-attributes-for-sensitive-fields)

**Watch**

- [Build a full stack food delivery app in React Native](https://www.youtube.com/watch?v=LKrX390fJMw)
- [Importing CSV files into an Appwrite database](https://youtu.be/7UfWaHNghas?si=ggKDtrhclOQXllS2)
- [Bulk operations with Appwrites database](https://youtu.be/lliJLad3e9s?si=mdV8QIplgdxPOFKY)
- [Type generation for Appwrite DB Schema](https://youtu.be/ip2CXH2KRl8?si=9EoOCraYd-hOCYoX)
- [Announcing Database Upserts](https://youtu.be/Pt9ZttS3KiA?si=TWlRi5qFzZbHbzAP)
- [Auto-increment support for numeric sequencing](https://youtu.be/yy-L6QmEWe8?si=BjXzFna1C54IDXZd)

# What’s to come

That’s a wrap for July. Next month, we will have more database updates, including good news for developers working with SQL, and some very big news!

Stay tuned!

Follow us on [X](https://x.com/appwrite) and check our [Changelog](https://appwrite.io/changelog) regularly, as we will release more information in the coming weeks.

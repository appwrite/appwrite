---
layout: post
title: "Appwrite 1.8.0: The most powerful self-hosted release yet"
description: Appwrite 1.8.0 brings powerful database features, new runtimes, and key performance updates to make your self-hosted setup faster, safer, and more scalable.
cover: /images/blog/appwrite-1-8-0-self-hosted-release/cover.avif
date: 2025-10-31
lastUpdated: 2026-06-29
timeToRead: 5
author: steven
category: announcement
faqs:
  - question: "What is new in Appwrite 1.8.0 for self-hosted users?"
    answer: "Appwrite 1.8.0 introduces TablesDB, a Transactions API, spatial columns, CSV import, a Bulk API, Database Upsert, auto-increment support, opt-in relationship loading, and new time-based and inversion queries for [Databases](/docs/products/databases). It also adds new runtimes and performance improvements across the self-hosted stack."
  - question: "What is TablesDB in Appwrite?"
    answer: "TablesDB is a relational-style API on top of Appwrite [Databases](/docs/products/databases). It introduces tables, columns, and rows alongside the existing collections and documents model, with endpoints like createTable, createTextColumn, and createRow for developers who prefer a structured schema."
  - question: "How do Appwrite database transactions work?"
    answer: "You stage multiple operations across one or more tables and only commit them when you are ready. If every step succeeds, Appwrite commits atomically. If anything fails (permissions, conflicts, validation), Appwrite rolls back the entire transaction so your data stays consistent."
  - question: "Can I use Appwrite to store and query geographic data?"
    answer: "Yes. The new spatial columns API lets you store points, lines, and polygons in [Databases](/docs/products/databases), index them efficiently, and run geo queries to check how shapes interact. This unlocks maps, logistics, mobility, and location-aware use cases without a separate geo database."
  - question: "How do I upgrade my self-hosted Appwrite instance to 1.8.0?"
    answer: "Follow the upgrade steps in the self-hosting documentation, which cover backing up your data, pulling the new image, and running the migration. Always test the upgrade on a staging environment first because 1.8.0 includes significant database changes."
  - question: "What is Database Upsert and when should I use it?"
    answer: "Upsert creates a row if it does not exist and updates it if it does, in a single API call. Use it when you want to avoid writing your own check-then-write logic and handling 404 responses just to decide between insert and update."
---
After weeks of testing and multiple release candidates, **Appwrite 1.8.0** for self-hosted environments is now available.

This release brings major improvements across runtime support, performance, and data capabilities, making Appwrite even more powerful for teams running their own infrastructure.

Appwrite has always been built for developers who want complete control. With this release, we're expanding what's possible in your environment.

Here's everything we've released in **Appwrite 1.8.0**, our most powerful self-hosted version yet.

# Powerful Database features

Over the past two months, we've been rolling out a series of major database upgrades, all focused on giving you more power, flexibility, and reliability when managing data at scale.

## Introducing TablesDB

Meet **TablesDB.** A major step forward in how Appwrite handles structured data. It introduces familiar **relational concepts** like tables, columns, and rows, along with a new **TablesDB API** that aligns with standard CRUD patterns such as `createTable`, `createRow`, and `createTextColumn`.

Built for developers who prefer a more structured model, TablesDB offers the clarity of relational databases while remaining fully compatible with Appwrite's existing collections and documents. It's ideal for analytics, structured datasets, and complex relationships, giving you more control and flexibility when modelling data at scale.

[Learn more about TablesDB](/blog/post/announcing-appwrite-databases-new-ui)

## Transactions API

Appwrite now supports Database Transactions. With this feature, you can stage multiple operations across one or more tables, and commit them only when you're ready. 

- If every operation checks out, Appwrite commits them in one atomic action.
- If anything fails, whether due to permissions, conflicts, validations, or other reasons, Appwrite automatically rolls everything back.

[Learn more about Transactions API](/blog/post/announcing-transactions-api)

## API for spatial columns

If you're building maps, logistics, travel, mobility, real estate, or safety and compliance features, this unlocks the geo foundation you've been waiting for. 

With this new API, you can store points, lines, and polygons directly in Appwrite Database, index them efficiently, and query how they interact with each other with first-class geo primitives and queries.

[Learn more about Spatial columns](/blog/post/announcing-spatial-columns)

## CSV import

CSV import is a new way to populate your Appwrite databases from CSV files. Built on top of Appwrite's migration APIs, this feature makes it easy to bring in large datasets, seed tables, or migrate structured data using only a CSV file.

[Learn more about CSV import](/blog/post/announcing-csv-imports)

## Bulk API

Explicitly designed to handle heavy write workloads, with the new Bulk API, you can create, update, or delete multiple rows in one go, vastly speeding up your workflows and reducing network overhead.

[Learn more about Bulk API](/blog/post/announcing-bulk-api)

## Database Upsert

Database Upsert allows you to create or update a row using a single API call. 

- If the row does not exist, it is created.
- If it does, it is updated.

You no longer need to write separate logic to check for existence or handle 404 responses. The server handles that for you.

[Learn more about Database Upsert](/blog/post/announcing-database-upsert)

## Auto increment support

Previously, maintaining consistent insertion order and generating numeric identifiers required either manual increments, complex logic, or dependence on timestamps, which weren't always accurate or reliable. 

Appwrite's new Auto-increment support feature solves these issues seamlessly, providing a built-in numeric identifier that increases predictably with every new row added.

[Learn more about Auto increment support](/blog/post/announcing-auto-increment-support)

## Opt-in relationship loading

This powerful enhancement empowers developers with explicit, granular control over exactly which related rows they fetch from the database. 

With Opt-in relationship loading, rows now only return their immediate fields by default. You explicitly specify exactly which relationships should be included in your queries. This eliminates unintended data fetching, drastically reduces payload sizes, and ensures you load only what matters.

[Learn more about Opt-in relationship loading](/blog/post/announcing-opt-in-relationship-loading)

## New set of queries

Appwrite Databases now have smarter queries. We've added two sets of new queries that let you handle time-based filtering and exclusions directly in your queries.

[Time Helper Queries](/docs/products/databases/queries#time-helpers)

`createdBefore`, `createdAfter`, `updatedBefore`, `updatedAfter`

[Inversion Queries](/docs/products/databases/queries)

`notSearch`, `notContains`, `notBetween`, `notStartsWith`, `notEndsWith`

Together, these new queries make your code cleaner, reduce the need for workarounds, and give you more control over your data.

## Atomic numeric operations

Before this feature, updating a number meant fetching the entire row, modifying it on the client, and writing it back, a process prone to race conditions, unnecessary bandwidth use, and extra logic to handle edge cases.

With **Atomic numeric operations,** you simply send a delta (like `+1` or `-3`), and Appwrite applies the update atomically on the server. 

[Learn more about Atomic numeric operations](/blog/post/announcing-atomic-numeric-operations)

## Real-time support for Bulk API

The **Bulk API** now supports **real-time events**, ensuring all connected clients stay in sync during multi-record operations. It's perfect for collaborative dashboards, analytics tools, and live data systems.

# Runtimes & SDK updates

## Flutter 3.32 and Dart 3.8 support

Appwrite 1.8.x introduces **Flutter 3.32** and **Dart 3.8** runtimes for [Sites](/docs/products/sites) and [Functions](/docs/products/functions), ensuring full compatibility with the latest SDKs. These updates bring faster build times, improved stability, and access to new language features, helping you ship high-performance apps across web, mobile, and desktop while staying aligned with the latest Flutter ecosystem advancements.

## Expanded Runtime compatibility

This release also brings updates and optimizations to other runtimes, improving startup speed, dependency resolution, and logging reliability for Node.js, Python, PHP, and Deno environments.

## Improved SDK Consistency

SDKs across platforms have been updated for greater parity and more predictable behaviour. This includes refined error handling, better type safety, and smoother developer ergonomics across the board.

# Auth and security improvements

## Native Sign in with Apple

Add **“Sign in with Apple”** to your apps in minutes using the new built-in function template. It integrates seamlessly with Appwrite's Auth and session management to simplify native Apple authentication across platforms.

## React Native scheme handling

Appwrite now natively handles **React Native URL schemes**, improving the reliability of authentication callbacks and deep-link flows across iOS and Android.

## Origin validation for web extensions

Appwrite 1.8.0 also introduces **support for web extensions**, allowing them to connect and interact with Appwrite resources without being blocked by origin validation. This update makes it easier to build browser-based tools and integrations that rely on Appwrite APIs.

# Performance and delivery improvements

## Improved email delivery

Appwrite's SMTP system now uses **BCC-only emails**, improving privacy and deliverability, especially for large user bases or bulk notifications.

## Core optimizations

Behind the scenes, Appwrite 1.8.0 includes a series of **core performance and memory optimizations**, reducing startup times and improving reliability for self-hosted deployments at scale.

# Built for developers who self-host

Appwrite 1.8.0 represents the most capable self-hosted Appwrite yet, combining modern runtimes, advanced Database capabilities, stronger security, and real-time improvements into one release.

Upgrade today, and experience more speed, reliability, and control. All on your own infrastructure.

To see the complete list of updates and improvements, head over to the complete [release notes](https://github.com/appwrite/appwrite/releases).

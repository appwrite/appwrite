---
layout: post
title: "Announcing Opt-in relationship loading: Granular control for smarter data fetching"
description: Gain full control over which related rows to fetch and drastically reduce payload sizes.
date: 2025-08-28
cover: /images/blog/announcing-opt-in-relationship-loading/cover.avif
timeToRead: 5
author: darshan-pandya
category: announcement
featured: false
faqs:
  - question: "What is opt-in relationship loading in Appwrite Databases?"
    answer: "Opt-in relationship loading means related rows are no longer fetched automatically when you query a table. You explicitly specify which relationships to include in each query, so payloads stay lean and you avoid pulling data you do not need. See the [Appwrite Databases relationships documentation](/docs/products/databases/relationships) for details."
  - question: "What is the N+1 query problem?"
    answer: "The N+1 query problem happens when fetching a list of N items triggers one extra query per item to load related data, resulting in N+1 total database calls. It is a common source of slow API responses. Opt-in relationship loading helps avoid it by letting you fetch only the relationships you actually need in a single query."
  - question: "Will opt-in relationship loading break my existing Appwrite app?"
    answer: "Old SDK versions continue to behave as before for backwards compatibility. New SDK versions return only immediate fields by default and require you to opt in to relationships. If you upgrade SDKs, review queries that previously relied on automatic relationship hydration and add explicit selections where needed."
  - question: "How do I reduce payload sizes in API responses?"
    answer: "Return only the fields and related objects the client actually needs. In Appwrite Databases this means using selection queries to choose specific columns, opting in to relationships you need, and avoiding nested fetches that pull unrelated data. Smaller payloads improve both server response time and client rendering speed."
  - question: "Are relationship queries supported in Appwrite Databases?"
    answer: "Yes. Appwrite Databases supports filter queries across relationship columns using dot notation (for example `author.name`). Combined with opt-in loading, this lets you fetch precisely the related data you need without overfetching. See the [databases documentation](/docs/products/databases) for query syntax."
  - question: "Is opt-in relationship loading available on self-hosted Appwrite?"
    answer: "Yes. The feature is available on both Appwrite Cloud and self-hosted installations. Make sure your server and SDK versions are up to date so you can use the new query syntax and benefit from the smaller default payloads."
---

Being able to move fast is crucial for any developer, and nothing disrupts productivity more than unnecessary waiting. Long loading times, especially when fetching data, are not only frustrating but also slow down your development cycle and degrade user experience in production. Excessive or unintended data loading can lead to bloated JSON payloads, increased latency, and unnecessary consumption of network and compute resources.

To tackle this challenge head-on, we're introducing **Opt-in relationship loading** for Appwrite Databases.

This powerful enhancement empowers developers with explicit, granular control over exactly which related rows they fetch from the database. By selectively loading only essential relationships, you dramatically optimize performance, speed up application response times, and significantly reduce bandwidth usage.

# Explicit control, leaner data

Previously, Appwrite automatically fetched related rows whenever a query was made. This often resulted in large JSON payloads containing data you didn't necessarily need, negatively impacting load times and overall app performance.

With Opt-in relationship loading, rows now only return their immediate fields by default. You explicitly specify exactly which relationships should be included in your queries. This eliminates unintended data fetching, drastically reduces payload sizes, and ensures you load only what matters.

# Optimize your database queries

Opt-in relationship loading addresses the common performance pitfalls associated with "N + 1" query problems. Developers can now precisely tailor queries to fetch related rows efficiently, significantly reducing latency. Typical query times, often slowed by unnecessary data overhead, become noticeably shorter, enabling smoother, faster, and more predictable performance across your application.

# Tailored for performance-sensitive apps

This feature provides fine-grained control over data retrieval. Ideal for backend developers and full-stack teams, and especially critical for developers building mobile or bandwidth-sensitive applications, it minimizes unnecessary data transfers, dramatically improving your app's responsiveness and efficiency. This makes it easier to deliver a seamless user experience even in constrained network environments.

# Key performance benefits

- **Faster response times**: Queries execute quickly because only explicitly requested data is fetched.
- **Smaller JSON payloads**: Avoid the overhead of unrequested data, decreasing payload sizes significantly.
- **Reduced bandwidth usage**: Lower your app's network footprint by transferring only essential data.
- **Fine-grained control**: Precisely select related fields with intuitive, developer-friendly syntax.
- **Backwards compatibility**: Existing queries continue to function but return leaner data by default, easing your transition.

# Improved experience

Adopting this new feature is seamless and requires no additional code changes for your existing setups. Old SDK versions maintain their existing behavior, ensuring full backward compatibility. Meanwhile, new queries and projects immediately benefit from optimized loading, achieving maximum efficiency without hassle.

Available on both Appwrite Cloud and self-hosted installations, this enhancement streamlines your data fetching experience, significantly reducing compute and bandwidth overhead while maximizing your application's performance and responsiveness.

# More resources

- [Read the documentation to get started](/docs/products/databases/relationships)
- [How Appwrite streamlines database operations using hooks](/blog/post/hooks-appwrite-databases)
- [Enhance your developer experience with dev keys](/blog/post/improve-devex-dev-keys)
- [Introducing Database Backups](/blog/post/introducing-database-backups)

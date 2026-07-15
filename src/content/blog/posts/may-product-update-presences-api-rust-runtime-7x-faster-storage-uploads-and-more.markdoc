---
layout: post
title: "May product update: Presences API, Rust runtime, 7x faster Storage uploads, and more"
description: Get a full recap of everything shipped across Appwrite in May, including Presences API, Rust runtime, 7x faster Storage uploads, database improvements, Auth email policies, deployment updates, and AI tooling.
date: 2026-06-11
cover: /images/blog/may-product-update-presences-api-rust-runtime-7x-faster-storage-uploads-and-more/cover.avif
timeToRead: 5
author: aishwari
category: product
featured: false
faqs:
  - question: What is Appwrite Presences API used for?
    answer: The Presences API lets developers track short-lived user activity states such as online, away, typing, editing, or viewing. It can be used to build online indicators, typing states, collaborative editors, multiplayer features, live dashboards, and activity feeds.
  - question: Does Appwrite support Rust functions?
    answer: Yes. Appwrite Functions now supports Rust as a first-class runtime. Developers can write and deploy backend functions in Rust and use them for workloads such as webhook verification, image processing, payment flows, and data transformation.
  - question: How fast are Appwrite Storage uploads now?
    answer: Appwrite SDKs now support parallel chunk uploads for Storage. In a Node SDK benchmark, a 1.28 GB file upload improved from 4 minutes 44 seconds to under 40 seconds, reaching a 7.10x improvement with no API changes needed.
  - question: Are Appwrite database relationships production-ready?
    answer: Yes. Database relationships are officially out of beta and production-ready across Appwrite Cloud and self-hosted deployments. They help developers model connected data, filter across related records, and load related data more reliably.
  - question: Does Appwrite support OpenAI Codex?
    answer: Yes. The Appwrite plugin for OpenAI Codex includes the Appwrite Docs MCP server and agent skills for the Appwrite CLI, major SDKs, and deployment workflows for Sites and Functions.
---
Welcome back to the product update.

May was packed with releases across Appwrite. We shipped new tools for realtime collaboration, faster file uploads, better deployment workflows, new runtimes, Auth controls, and database improvements to help you build faster and scale with more confidence.

Here is a quick overview of what we shipped:

* Presences API for online, typing, and activity states
* Rust runtime for Appwrite Functions
* Database relationships are officially out of beta
* BigInt columns for Appwrite Databases
* Up to 7x faster Storage uploads with parallel chunks
* Git deployment triggers for Functions and Sites
* Deployment retention for Functions and Sites
* Email policies for Appwrite Auth
* Bun and Deno build runtimes for Sites
* Dart 3.12 for Functions and Flutter 3.44 for Sites
* Appwrite plugin for OpenAI Codex

Let's dive in.

# Track who is online, typing, and active with the Presences API

![Presences API](/images/blog/may-product-update-presences-api-rust-runtime-7x-faster-storage-uploads-and-more/presences.avif)

Appwrite now includes a Presences API for short-lived user statuses like online, away, typing, editing, or viewing.

You can use it to build online indicators, typing states, multiplayer presence, collaborative editors, live dashboards, and activity feeds.

[Read the announcement](/blog/post/announcing-presences-api)

# Introducing the Rust runtime for Appwrite Functions

![Rust runtime](/images/blog/may-product-update-presences-api-rust-runtime-7x-faster-storage-uploads-and-more/rust-runtime.avif)

Appwrite Functions now supports Rust as a first-class runtime.

You can write and deploy functions in Rust, pair them with the official Appwrite Rust SDK, and use them for performance-sensitive backend workloads like webhook verification, image processing, payment flows, and data transformation.

[Read the announcement](/blog/post/announcing-rust-runtime)

# Database relationships are officially out of beta

![Database relationships](/images/blog/may-product-update-presences-api-rust-runtime-7x-faster-storage-uploads-and-more/relationships.avif)

Database relationships in Appwrite are now production-ready across Appwrite Cloud and self-hosted deployments.

You can model connected data more easily, filter across related records, and build apps with more reliable relationship loading and improved performance.

[Read the announcement](/blog/post/relationships-are-out-of-beta)

# Introducing BigInt columns

![BigInt columns](/images/blog/may-product-update-presences-api-rust-runtime-7x-faster-storage-uploads-and-more/bigint.avif)

Appwrite Databases now supports 64-bit signed integers with the new BigInt column type.

BigInt is useful for large counters, external IDs, high-resolution timestamps, financial values, and any data that needs integer precision without floating-point rounding.

[Read the announcement](/blog/post/announcing-bigint-columns)

# Up to 7x faster Storage uploads with parallel chunks

![Faster Storage uploads](/images/blog/may-product-update-presences-api-rust-runtime-7x-faster-storage-uploads-and-more/storage.avif)

Appwrite SDKs now upload Storage file chunks in parallel.

In our Node SDK benchmark, a 1.28 GB file upload dropped from 4 minutes 44 seconds to under 40 seconds. That is a 7.10x improvement, with no API changes needed.

[Read the announcement](/blog/post/faster-storage-uploads-parallel-chunks)

# Git deployment triggers for Functions and Sites

![Git deployment triggers](/images/blog/may-product-update-presences-api-rust-runtime-7x-faster-storage-uploads-and-more/deployment-triggers.avif)

Git deployment triggers give you more control over which changes create automatic deployments.

Use branch filters for production, staging, and preview workflows, or path filters so each Function or Site only deploys when relevant files change.

[Read more](/blog/post/announcing-git-deployment-triggers)

# Deployment retention for Functions and Sites

![Deployment retention](/images/blog/may-product-update-presences-api-rust-runtime-7x-faster-storage-uploads-and-more/deployment-retention.avif)

Functions and Sites now support deployment retention.

You can choose how long Appwrite keeps non-active deployments before deleting them automatically. Active deployments are never deleted.

[Read the announcement](/blog/post/announcing-deployment-retention)

# Email policies for Appwrite Auth

![Email policies](/images/blog/may-product-update-presences-api-rust-runtime-7x-faster-storage-uploads-and-more/email-policies.avif)

Appwrite Auth now supports email policies.

You can block free providers, aliased emails, disposable inboxes, or enforce provider-specific domains for user creation and email updates.

[Read more](/blog/post/announcing-email-policies)

# Bun and Deno build runtimes for Sites

![Bun and Deno build runtimes](/images/blog/may-product-update-presences-api-rust-runtime-7x-faster-storage-uploads-and-more/Bun_and_Deno.avif)

Appwrite Sites now supports Bun and Deno as build runtimes for Node-based frameworks.

You can switch the build runtime per Site from Runtime settings and apply the change on the next deployment.

[Read the announcement](/blog/post/announcing-bun-deno-runtimes)

# Dart 3.12 for Functions and Flutter 3.44 for Sites

![Dart and Flutter runtimes](/images/blog/may-product-update-presences-api-rust-runtime-7x-faster-storage-uploads-and-more/cover-4.avif)

Appwrite now supports Dart 3.12 as a Functions runtime and Flutter 3.44 as a build runtime for Sites.

Flutter teams can now build mobile apps, web apps, and backend functions with a more consistent Dart-based workflow.

[Read the announcement](/blog/post/announcing-dart-flutter-runtimes)

# Appwrite plugin for OpenAI Codex

![Appwrite plugin for OpenAI Codex](/images/blog/announcing-appwrite-codex-plugin/cover.avif)

The Appwrite plugin is now available for OpenAI Codex.

It includes the Appwrite Docs MCP server and agent skills for the Appwrite CLI, major SDKs, and deployment workflows for Sites and Functions.

[Learn more](/blog/post/announcing-appwrite-codex-plugin)

# Community Recognitions

![Community Recognitions](/images/blog/may-product-update-presences-api-rust-runtime-7x-faster-storage-uploads-and-more/community-recognitions.avif)

We are excited to feature Ibaraki Douji as part of our monthly Community Recognitions for May 2026. Ibaraki Douji built a full, up-to-date [Helm chart for self-hosting Appwrite on Kubernetes](https://gitlab.ibaraki.app/helm/appwrite) that deploys the entire stack, including the API, Console, Realtime, background workers, schedulers, database, Redis, and Kubernetes-native ingress with TLS. It lets you scale each component independently, run clean upgrades, and fits GitOps workflows with tools like ArgoCD and Flux. It has been running in production since Appwrite 1.7.4. Keep in mind that this is a community-backed project and is not maintained by Appwrite.

If you would like to participate in next month's Community Recognitions, [join our Discord server](https://appwrite.io/discord) and showcase your project.

# Engineering Resources

* [3 things you can build with the Rust runtime](/blog/post/3-things-you-can-build-with-rust-runtime)
* [Building a Snapchat clone with Presences and Realtime](/blog/post/building-snapchat-clone-with-presences-and-realtime)
* [Kimi K2.6 lands on Appwrite Arena: the May 2026 leaderboard update](/blog/post/kimi-k2-6-arena-leaderboard-refresh)

# What's to come

We are continuing to improve Appwrite across realtime collaboration, deployments, performance, databases, Auth, and AI-assisted workflows.

Follow us on [X](https://x.com/appwrite) and check our [Changelog](/changelog) regularly, as we will release more information in the coming weeks.

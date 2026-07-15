---
layout: post
title: Announcing Appwrite 1.9.5 for self-hosted deployments
description: Appwrite 1.9.5 brings the Presences API, BigInt columns, the Rust runtime, X OAuth, Bun and Deno build runtimes, Git deployment triggers, faster Storage uploads, and broader migrations to self-hosted deployments.
cover: /images/blog/appwrite-1-9-5-self-hosted-release/cover.avif
date: 2026-06-30
timeToRead: 6
author: chirag-aggarwal
category: announcement
featured: false
callToAction: true
faqs:
  - question: "What is new in Appwrite 1.9.5 for self-hosted deployments?"
    answer: "Appwrite 1.9.5 brings the Presences API, BigInt columns for [Databases](/docs/products/databases), the Rust runtime for [Functions](/docs/products/functions), X OAuth for [Auth](/docs/products/auth), Bun and Deno build runtimes and Git deployment triggers for [Sites](/docs/products/sites), faster Storage uploads with parallel chunks, and broader migration coverage."
  - question: "How do I self-host Appwrite 1.9.5?"
    answer: "Run the Docker installer with the 1.9.5 image, then open the setup wizard on port 20080 to finish configuration. See the [self-hosting installation guide](/docs/advanced/self-hosting/installation) for the full command and system requirements."
  - question: "How do I upgrade my self-hosted instance to 1.9.5?"
    answer: "Back up your data, pull the 1.9.5 image, run the upgrade command, and run the migration. Always test the upgrade on a staging environment first."
  - question: "What is the Presences API used for?"
    answer: "The Presences API tracks short-lived user states such as online, away, typing, editing, or viewing. You can use it to build online indicators, typing states, collaborative editors, multiplayer features, and live activity feeds without managing presence channels yourself."
  - question: "What are BigInt columns in Appwrite?"
    answer: "BigInt columns store 64-bit signed integers in [Databases](/docs/products/databases). They are suited to large counters, external IDs, high-resolution timestamps, and financial values that need integer precision without floating-point rounding."
  - question: "Does Appwrite Functions support Rust?"
    answer: "Yes. Appwrite 1.9.5 adds Rust as a first-class [Functions](/docs/products/functions) runtime, so you can write and deploy functions in Rust and pair them with the official Appwrite Rust SDK."
  - question: "Can I build my Appwrite Sites with Bun or Deno?"
    answer: "Yes. Appwrite [Sites](/docs/products/sites) can now build Node-based frameworks with Bun or Deno. You can switch the build runtime per Site from its Runtime settings, and the change applies on the next deployment."
  - question: "How much faster are Storage uploads in 1.9.5?"
    answer: "Appwrite SDKs now upload Storage file chunks in parallel. In our Node SDK benchmark, a 1.28 GB upload dropped from 4 minutes 44 seconds to under 40 seconds, a 7.10x improvement, with no API changes required."
---

**Appwrite 1.9.5** is now available for self-hosted deployments.

Over the past few months, we have shipped a steady stream of features across Appwrite Cloud. This release brings them to teams running Appwrite on their own infrastructure.

Here is what is new in Appwrite 1.9.5.

# Presences API

The Presences API tracks short-lived user states such as online, away, typing, editing, and viewing. It gives you a managed way to build online indicators, typing states, multiplayer presence, collaborative editors, and live activity feeds, without wiring up presence channels by hand. Entries carry custom metadata and expire automatically, so stale sessions clear themselves.

{% arrow_link href="/blog/post/announcing-presences-api" %}
Read the announcement
{% /arrow_link %}

# BigInt columns

Appwrite Databases now support 64-bit signed integers through the BigInt column type. BigInt is the right choice for large counters, external identifiers, high-resolution timestamps, and financial values that need integer precision without floating-point rounding.

{% arrow_link href="/blog/post/announcing-bigint-columns" %}
Read the announcement
{% /arrow_link %}

# Rust runtime for Functions

Appwrite Functions now supports Rust as a first-class runtime. You can write and deploy functions in Rust, pair them with the official Appwrite Rust SDK, and use them for performance-sensitive workloads such as webhook verification, image processing, and data transformation.

{% arrow_link href="/blog/post/announcing-rust-runtime" %}
Read the announcement
{% /arrow_link %}

# Bun and Deno build runtimes for Sites

Appwrite Sites can now build Node-based frameworks with Bun or Deno. You can switch the build runtime per Site from its Runtime settings, and the change applies on the next deployment.

{% arrow_link href="/blog/post/announcing-bun-deno-runtimes" %}
Read the announcement
{% /arrow_link %}

# Git deployment triggers

Git deployment triggers give you control over which changes create a deployment. Use branch filters to separate production, staging, and preview workflows, and path filters so each Function or Site only deploys when relevant files change.

{% arrow_link href="/blog/post/announcing-git-deployment-triggers" %}
Read the announcement
{% /arrow_link %}

# X OAuth provider

Appwrite Auth now supports signing in with X (formerly Twitter) through OAuth 2.0 with PKCE. You can enable it from the Console by adding your X app credentials, with no additional configuration in your application code.

{% arrow_link href="/blog/post/x-oauth2-appwrite" %}
Read the announcement
{% /arrow_link %}

# Faster uploads with parallel chunks

Appwrite SDKs now upload Storage file chunks in parallel. In our Node SDK benchmark, a 1.28 GB upload dropped from 4 minutes 44 seconds to under 40 seconds, a 7.10x improvement, with no API changes required.

{% arrow_link href="/blog/post/faster-storage-uploads-parallel-chunks" %}
Read the announcement
{% /arrow_link %}

# Improved migrations

Migrations in 1.9.5 carry over far more than your data. Project configuration now transfers alongside it, including API keys, project variables, webhooks, OAuth providers, SMTP settings, email templates, custom domains, and security policies. Moving a project between Appwrite instances, such as from Appwrite Cloud to a self-hosted instance, now reproduces its setup instead of just its records.

# Self-host Appwrite 1.9.5

If you are setting up Appwrite for the first time, all you need is Docker installed. Run the installer with the 1.9.5 image, then open `http://localhost:20080` to complete the setup wizard.

```bash
docker run -it --rm \
    --publish 20080:20080 \
    --volume /var/run/docker.sock:/var/run/docker.sock \
    --volume "$(pwd)"/appwrite:/usr/src/code/appwrite:rw \
    --entrypoint="install" \
    appwrite/appwrite:1.9.5
```

For system requirements and the full walkthrough, see the [installation guide](/docs/advanced/self-hosting/installation).

# Upgrade your self-hosted instance

If you are already running Appwrite, back up your data first, then run the upgrade command:

```bash
docker run -it --rm \
    --publish 20080:20080 \
    --volume /var/run/docker.sock:/var/run/docker.sock \
    --volume "$(pwd)"/appwrite:/usr/src/code/appwrite:rw \
    --entrypoint="upgrade" \
    appwrite/appwrite:1.9.5
```

After the upgrade is complete, run the migration:

```sh
cd appwrite/
docker compose exec appwrite migrate
```

To view the complete list of changes, fixes, and contributions, check out the full [release notes](https://github.com/appwrite/appwrite/releases/tag/1.9.5).

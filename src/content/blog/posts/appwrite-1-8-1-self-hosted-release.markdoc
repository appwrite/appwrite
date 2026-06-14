---
layout: post
title: Announcing Appwrite 1.8.1 for self-hosted deployments
description: Appwrite 1.8.1 brings improved stability, modern web framework support, enhanced database operators, and more to self-hosted deployments.
cover: /images/blog/appwrite-1-8-1-self-hosted-release/cover.avif
date: 2025-12-31
timeToRead: 7
author: steven
category: announcement
featured: false
callToAction: true
faqs:
  - question: "What is in Appwrite 1.8.1 for self-hosted deployments?"
    answer: "1.8.1 is a focused stability and compatibility release on top of 1.8.0. It adds TanStack Start and Next.js standalone support for [Sites](/docs/products/sites), updates the default Flutter runtime to 3.35, introduces bucket-level image transformation controls, ships a Resend email integration, and brings database operators along with reliability fixes."
  - question: "What are database operators in Appwrite?"
    answer: "Database operators let you describe an inline update (increment, append, replace, adjust) instead of sending a new value. Appwrite applies the change atomically inside [Databases](/docs/products/databases), so you avoid the usual read-modify-write cycle and the race conditions that come with it."
  - question: "Does Appwrite 1.8.1 support Next.js standalone output?"
    answer: "Yes. [Sites](/docs/products/sites) can now deploy Next.js apps using standalone output, which produces smaller container images, faster startup, and tighter control over dependencies. This is especially useful for self-hosted environments where build size matters."
  - question: "Can I disable image transformations on a specific bucket?"
    answer: "Yes. Bucket-level controls in [Storage](/docs/products/storage) let you turn image transformations on or off per bucket. This is useful when you want a bucket to serve only original files or when you want to avoid unexpected transformation costs."
  - question: "How do I upgrade from 1.8.0 to 1.8.1?"
    answer: "Follow the standard self-hosted upgrade flow: back up your data, pull the 1.8.1 image, and run the upgrade. Because 1.8.1 is a patch release on top of 1.8.0, the upgrade is smaller in scope than a major version bump, but you should still test on staging first."
---

Appwrite 1.8.1 delivers a focused set of improvements and fixes that further stabilize and extend Appwrite 1.8.0 on the self-hosted offering.

This release benefits teams running Appwrite on their own infrastructure who want smoother deployments, better compatibility with modern frontend frameworks, and more control across Sites, Functions, Databases, and Storage.

Here’s what’s new in Appwrite 1.8.1.

# Sites & frontend compatibility

Modern frontend frameworks continue to evolve rapidly, and 1.8.1 ensures Appwrite Sites stays up to date.

## TanStack Start support

This release introduces support for TanStack Start when deploying Sites, enabling auto-detection of framework and a smoother deployment experience with TanStack’s full-stack tooling.

{% arrow_link href="/blog/post/tanstack-start-support-in-appwrite-sites" %}
Read the official announcement
{% /arrow_link %}

## Next.js standalone support

Appwrite 1.8.1 introduces support for Next.js standalone output, enabling easier deployment of optimized builds in self-hosted environments where container size, startup time, and dependency control are crucial.

{% arrow_link href="/blog/post/nextjs-output-modes" %}
Learn why Next.js standalone mode matters
{% /arrow_link %}

## ElevenLabs text-to-speech site template

Appwrite 1.8.1 also includes the ElevenLabs text-to-speech site template for self-hosted Sites. This integration lets developers quickly deploy an AI-powered voice experience that converts text into realistic speech using the ElevenLabs API.

{% arrow_link href="/integrations/ai-elevenlabs-text-to-speech" %}
Learn how to deploy the template
{% /arrow_link %}

# Functions & runtimes

## Updated Flutter default runtime

The default Flutter build runtime has been updated to Flutter 3.35, ensuring better compatibility with the latest Flutter tooling and SDK improvements.

This update brings improved stability, faster builds, and alignment with the current Flutter ecosystem for self-hosted Sites and Functions.

# Storage & image handling

## Bucket-level image transformation controls

You can now enable or disable image transformations per bucket, giving you precise control over how files are handled.

This is particularly useful when you need to restrict buckets to serve only original files or want to avoid unexpected transformation charges.

{% arrow_link href="/changelog/entry/2025-11-21" %}
Read the changelog
{% /arrow_link %}

# Email & integrations

## Resend integration

Appwrite 1.8.1 introduces support for Resend, expanding your options for transactional and application email delivery in self-hosted environments.

This provides teams with more flexibility in selecting email providers that best suit their specific infrastructure needs.

# Database improvements & fixes

## Database operators support

Appwrite 1.8.1 introduces database operators to self-hosted users. Database operators allow developers to perform inline, atomic updates to existing data without needing to go through the usual read–modify–write cycle. Instead of sending new values, you describe the action: increment, append, replace, or adjust. Appwrite applies that instruction directly in the database, performing the change in a single atomic step and keeping your data consistent without extra logic in your application code.

{% arrow_link href="/blog/post/announcing-db-operators" %}
Learn more about database operators
{% /arrow_link %}

## Additional database fixes

- Improved handling of duplicate row creation during file uploads
- Fixes for batch write edge cases
- Better validation for nullable fields
- Memory leak fixes in stats tracking

Together, these updates improve consistency and reliability for applications running at scale.

# Stability, security, and reliability fixes

Appwrite 1.8.1 focuses on reliability, observability, and compatibility improvements for developers managing their own environments. As a patch release, it delivers a number of critical fixes that improve day-to-day operations for self-hosted users, including:

- Fixed external deployment authorization errors
- Improved WebP image library handling
- Resolved file token expiry edge cases
- Fixed issues with password updates and MFA challenges
- Improved Next.js compatibility and runtime stability

# Upgrade your self-hosted instance

As with all Appwrite upgrades:

- Back up your data before upgrading
- Install Appwrite 1.8.1 using the following [Docker command](/docs/advanced/self-hosting/production/updates#install-next-version)
    
    ```bash
    docker run -it --rm \
        --volume /var/run/docker.sock:/var/run/docker.sock \
        --volume "$(pwd)"/appwrite:/usr/src/code/appwrite:rw \
        --entrypoint="upgrade" \
        appwrite/appwrite:1.8.1
    ```
    
- [Run migrations](/docs/advanced/self-hosting/production/updates#running-the-migration), even if you’re upgrading from 1.8.0

To view the complete list of changes, fixes, and contributions, check out the full [release notes](https://github.com/appwrite/appwrite/releases/tag/1.8.1).
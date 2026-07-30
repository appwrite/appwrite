---
layout: post
title: "February and March product update: Realtime queries, Appwrite Skills, and new database features"
description: Get a full recap of features, improvements, and tools we shipped across Databases, Realtime, and AI in February and March.
date: 2026-04-24
lastUpdated: 2026-06-29
cover: /images/blog/february-and-march-product-update-realtime-queries-appwrite-skills-and-new-database-features/cover.avif
timeToRead: 5
author: aishwari
category: product
featured: false
faqs:
  - question: "What is new in Appwrite for February and March 2026?"
    answer: "Major updates across Databases, Realtime, and AI tooling: relationship queries with dot notation, new text column types (`varchar`, `text`, `mediumtext`, `longtext`), Realtime channel helpers, Realtime queries with server-side filtering, Appwrite Skills for AI coding agents, and Appwrite Arena, an open-source benchmark for AI model performance on Appwrite."
  - question: "How do relationship queries work in Appwrite Databases?"
    answer: "[Appwrite Databases](/docs/products/databases) now lets you filter directly on relationship columns using dot notation, so you can query related rows directly. All comparison operators work across the relationship, and the underlying engine was rewritten to be 12 to 18 times faster on relationship-heavy queries. It is available on both Cloud and self-hosted."
  - question: "What are the new text column types in Appwrite Databases?"
    answer: "Appwrite Databases supports four text column types: `varchar`, `text`, `mediumtext`, and `longtext`. Each comes with different storage characteristics, so you can match the column type to actual data size. Existing legacy string schemas continue to work without changes."
  - question: "What are Realtime queries in Appwrite?"
    answer: "Realtime queries let you attach query filters when subscribing to a channel, so the server only sends events that match your conditions. You can combine filters with `Query.and()` and `Query.or()`, and subscribe to the same channel multiple times with different filters. It works across Web, Flutter, React Native, Apple, and Android SDKs."
  - question: "What are Appwrite Skills?"
    answer: "Appwrite Skills are open-source Markdown files that teach AI coding agents like Claude Code, Cursor, and Windsurf how to use Appwrite's SDKs and CLI accurately. Instead of pasting docs into every prompt, you point the agent at the Skills repository and it generates code that actually compiles."
  - question: "What is Appwrite Arena?"
    answer: "Appwrite Arena is an open-source benchmark that scores how well AI models understand Appwrite's products, SDKs, and APIs across 191 questions in 9 service categories. All results are transparent and open source. Recent runs showed GPT-4.1 leading with Skills enabled, Claude Opus 4.6 leading without, and DeepSeek and MiniMax offering strong cost-to-intelligence ratios."
---

Welcome back to the product update. This time, we have not one, but two months to update you on. February and March were packed, we shipped improvements across Databases, Realtime, the CLI, the Console, and Sites, and introduced new tools built for the AI coding era.

Here is a quick overview of what we shipped:

- Relationship queries in Appwrite Databases
- New text column types for Appwrite Databases
- Realtime Channel helpers
- Realtime queries with server-side event filtering
- Appwrite Skills
- Appwrite Arena

Let's dive in.

![Relationship queries.png](/images/blog/february-and-march-product-update-realtime-queries-appwrite-skills-and-new-database-features/Relationship_queries.avif)

# Relationship queries in Appwrite Databases

You can now filter directly on relationship columns in Appwrite Databases using dot notation, no more fetching everything and filtering client-side. All comparison operators are supported, and as a bonus, we overhauled relationship internals for 12-18x faster performance across the board. Available on Appwrite Cloud and self-hosted.

[Read the announcement](https://appwrite.io/blog/post/announcing-relationship-queries)

![Introducing new text column types for Appwrite Databases.png](/images/blog/february-and-march-product-update-realtime-queries-appwrite-skills-and-new-database-features/Introducing_new_string_column_types_for_Appwrite_Databases.avif)

# New text column types for Appwrite Databases

Appwrite Databases now supports four explicit text column types: `varchar`, `text`, `mediumtext`, and `longtext`. The new types make storage trade-offs clear for both developers and AI agents. Existing legacy string schemas continue to work with full backward support. Live on Appwrite Cloud.

[Learn more](https://appwrite.io/blog/post/new-string-types)

![Announcing Realtime Channel helpers_ Type-safe subscriptions made simple.png](/images/blog/february-and-march-product-update-realtime-queries-appwrite-skills-and-new-database-features/Announcing_Realtime_Channel_helpers__Type-safe_subscriptions_made_simple.avif)

# Realtime channel helpers

Appwrite Realtime now includes channel helpers, a fluent type-safe API for building channel subscriptions. Instead of writing channel strings by hand, use a chainable API with IDE autocomplete and compile-time validation. Existing string-based subscriptions continue to work. Available across Web, Flutter, React Native, Apple, and Android SDKs.

[Read the full announcement](https://appwrite.io/blog/post/announcing-realtime-channel-helpers)

![Introducing Realtime queries.png](/images/blog/february-and-march-product-update-realtime-queries-appwrite-skills-and-new-database-features/Introducing_Realtime_queries.avif)

# Realtime queries: Server-side event filtering

Appwrite Realtime now supports queries when subscribing to channels. Events are filtered server-side, so your callbacks only receive updates that match your conditions. Combine filters with `Query.and()` and `Query.or()`, and subscribe to the same channel multiple times with different filters. Available across Web, Flutter, React Native, Apple, and Android SDKs.

[Learn more](https://appwrite.io/blog/post/announcing-realtime-queries)

![Announcing Appwrite Skills_ Give your AI agents Appwrite expertise.png](/images/blog/february-and-march-product-update-realtime-queries-appwrite-skills-and-new-database-features/Announcing_Appwrite_Skills__Give_your_AI_agents_Appwrite_expertise.avif)

# Introducing Appwrite Skills

Appwrite Skills are open-source Markdown files that give AI coding agents like Claude Code, Cursor, and Windsurf deep, language-specific knowledge of Appwrite's SDKs, so they produce accurate code without you having to paste docs into every prompt. Available for the CLI and all major SDKs.

[Read the full announcement](https://appwrite.io/blog/post/announcing-appwrite-skills)

![Introducing Appwrite Arena_ Which AI model knows Appwrite best_.png](/images/blog/february-and-march-product-update-realtime-queries-appwrite-skills-and-new-database-features/Introducing_Appwrite_Arena__Which_AI_model_knows_Appwrite_best_.avif)

# Introducing Appwrite Arena

Appwrite Arena is an open-source benchmark that tests how well AI models understand Appwrite's services, SDKs, and APIs, 191 questions across 9 service categories, fully transparent scoring, all results open source. GPT-4.1 ranks best with Skills, Claude Opus 4.6 leads without, and DeepSeek and MiniMax offer the best balance between intelligence and cost.

[Explore](https://arena.appwrite.io/)

![comm recoggg.png](/images/blog/february-and-march-product-update-realtime-queries-appwrite-skills-and-new-database-features/comm_recoggg.avif)

# Community recognitions

We are excited to feature Shivam as part of our monthly community recognitions for February 2026. Shivam created [NotesBee](https://notesbee.appwrite.network/), an Android notes app that also securely stores WhatsApp exports so only you can see them.

For March, a community member created [Skincare Buddy](https://skincare-buddy.appwrite.network/), a skincare tracking app that helps you manage your routine and share curated data with AI tools for personalized insights.

If you would like to participate in next month's Community Recognitions, [join our Discord server](https://discord.gg/3nfbe6NhkE) and showcase your project.

# Engineering Resources

- [Solving the headaches of screenshot automation (and why an API-First approach works better)](https://appwrite.io/blog/post/solving-the-headaches-of-screenshot-automation-and-why-an-api-first-approach-works-better)
- [Managing your endless website assets on your repo has a price and it's called cold start](https://appwrite.io/blog/post/managing-website-assets-repo-cold-start)
- [Common Appwrite mistakes (and how to avoid them)](https://appwrite.io/blog/post/common-appwrite-mistakes-and-how-to-avoid-them)
- [The fastest way to launch your next side project](https://appwrite.io/blog/post/the-fastest-way-to-launch-your-next-side-project)
- [Introducing user impersonation for Appwrite Auth](https://appwrite.io/blog/post/announcing-user-impersonation)

# What's to come

We have a lot more to come, including new features and a big partnership announcement we are very excited to share.

Follow us on [X](https://x.com/appwrite) and check our [Changelog](https://appwrite.io/changelog) regularly, as we will release more information in the coming weeks.

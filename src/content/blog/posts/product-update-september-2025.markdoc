---
layout: post
title: "Appwrite September product update: Docs MCP server, new Database features, and queries"
description: Get a full recap of products and new features we released in the month of September.
date: 2025-10-08
cover: /images/blog/product-update-september-2025/cover.avif
timeToRead: 7
author: dennis-ivy
category: product
featured: false
faqs:
  - question: "What new queries shipped in September 2025?"
    answer: "Time helper queries (`createdBefore`, `createdAfter`, `updatedBefore`, `updatedAfter`) make time-based filtering a one-liner, and inversion queries (`notSearch`, `notContains`, `notBetween`, `notStartsWith`, `notEndsWith`) let you exclude matches directly. Together they remove most of the workarounds people used to write client side."
  - question: "What does the Appwrite Docs MCP server do?"
    answer: "It exposes the [Appwrite documentation](/docs) to AI assistants through the Model Context Protocol. Assistants can search the docs, fetch specific guides, and generate code snippets using the current APIs so suggestions stay accurate."
  - question: "What are spatial columns in [Appwrite Databases](/docs/products/databases)?"
    answer: "Spatial columns add native support for points, lines, and polygons, along with spatial indexes and twelve new operators. You can store and query geo data without bolting on a separate database."
  - question: "Does [Appwrite Sites](/docs/products/sites) support Turbopack?"
    answer: "Yes. Next.js apps built with Turbopack deploy on Appwrite Sites without modification, with faster builds and consistent behavior between local and production. There is no separate configuration to enable it."
  - question: "How much faster are function cold starts in Frankfurt?"
    answer: "Function cold starts in the Frankfurt (FRA) region are about 58% faster after the September performance work, with more consistent start times across executions. Static sites also moved to edge proxies, so they skip the runtime entirely."
---
Welcome back to the September Product Update. This month, we have some serious Database upgrades with a new set of queries and features. Also, get ready for one more exciting hackathon news.

So, here’s what to expect:

- New set of queries
- Appwrite Docs MCP server
- API for Spatial columns
- Hacktoberfest Hackathon
- And more!

Let’s dive in!

# Appwrite's Hacktoberfest Hackathon

![Hacktoberfest Hackathon](/images/blog/product-update-september-2025/hackathon.avif)

It's that time of year again: Hacktoberfest has started! It's the biggest celebration of open-source technology, and we're excited to be back with yet another month-long hackathon. We invite you to join the fun and build something amazing and open-source.

[Join now](https://hacktoberfest.appwrite.network/)

# New set of queries

![New queries](/images/blog/product-update-september-2025/queries.avif)

Appwrite Databases now have smarter queries. We recently added two sets of new queries that let you handle time-based filtering and exclusions directly in your queries.

[Time Helper queries](/docs/products/databases/queries)

`createdBefore`, `createdAfter`, `updatedBefore`, `updatedAfter`

[Inversion queries](/docs/products/databases/queries)

`notSearch`, `notContains`, `notBetween`, `notStartsWith`, `notEndsWith`

Together, these new queries make your code cleaner, reduce the need for workarounds, and give you more control over your data.

# Appwrite Docs MCP server

![MCP server](/images/blog/product-update-september-2025/mcp.avif)

We also launched a dedicated MCP server for Appwrite docs that enables AI assistants to access and search through Appwrite's documentation directly.

That means, AI assistants can now:

- Search through Appwrite documentation
- Retrieve specific guides and references
- Generate code snippets using the latest APIs
- Provide contextual help for Appwrite features

[Visit docs](/docs/tooling/mcp/docs)

# API for Spatial columns

![API for Spatial columns](/images/blog/product-update-september-2025/spatial-columns.avif)

You can now build scalable geo workflows right out of the box. With this new API, you can store points, lines, and polygons, add spatial indexes, and run geo queries all natively in Appwrite.

What’s new:

- Point column
- Line column
- Polygon column
- Spatial index
- 12 new operators

With these new capabilities, it’s simple to model and query location-based data.

[Read the announcement to learn more](/blog/post/announcing-spatial-columns)

# Turbopack support for Appwrite Sites

![Turbopack support](/images/blog/product-update-september-2025/turbopack.avif)

Appwrite Sites now supports Next.js applications built with Turbopack, with faster builds and better compatibility for your deployments.

With native support, your builds run without modification, deploy faster, and stay consistent between local and production. This update strengthens compatibility between Appwrite Sites and the modern Next.js toolchain.

[Read the announcement to learn more](/blog/post/turbopack-support-appwrite-sites)

# Performance improvements for Functions and static sites

We've shipped significant performance improvements across functions and static sites.

Function cold starts are now 58% faster in the Frankfurt (FRA) region with more consistent start times across executions.

Static sites are now served directly through our edge proxies, eliminating the need for dedicated runtimes.

This means:

- No more cold starts for static sites
- Automatic caching for all static site assets
- Over 99% of requests served locally from 120+ global PoPs

# Community recognitions

We’re excited to feature [MediaKit](https://usemediakit.app/) in our Monthly Community recognitions this month.

MediaKit is a privacy-first, browser-based toolkit launched with a powerful Image Compressor. Appwrite handled the entire auth flow, including sign-ups, email verification, password recovery, and secure login.

A big shout-out to [Gurjeet](https://www.linkedin.com/in/gurjeet-singh-virdee-25a476199/).

If you'd like to participate in next month's Community Recognitions, join our Discord server to showcase your project.

# Engineering resources

**Read**

- [Make the best use of Appwrite’s MCP server](/blog/post/make-best-use-appwrite-mcp)
- [Setting up “Sign in with Google” in your app](/blog/post/setting-up-google-signin)
- [Client vs Server Components in React](/blog/post/client-vs-server-components-react)
- [Implementing Google OAuth with Expo Router](/blog/post/google-oauth-expo)
- [Comparing the best vibe coding tools](/blog/post/comparing-vibe-coding-tools)
- [Build a “delivery store locator” using Spatial Columns in Appwrite Databases](/blog/post/build-delivery-store-locator-spatial-columns)
- [How Radar scaled media curation while saving $1M with Appwrite Cloud](/blog/post/customer-story-radar)

**Watch**

- [We made relationship queries flexible](https://youtu.be/PFQv5Gvg2Io?si=AlxmWfTxj-gfRod7)
- [We launched an MCP server for our documentation](https://youtu.be/tIUGYvirdS4?si=p68ElsbciNRQG7ug)
- [Collect payments using Stripe](https://www.youtube.com/watch?v=BXsVXkemjv0)
- [Build a store locator using Spatial Columns in Appwrite Database](https://youtu.be/eAc9mqW6yEU?si=mR_qMAYsGTBvEgfs)

# What’s to come

That’s a wrap. This month, we have a lot more exciting news! If you're a big fan of self-hosting, or vibe coding...well, it's going to be a great few weeks for you!

Plus, we have more database and region updates pending:

- New databases partner
- Multiple database features
- Brand new cloud region

Follow us on [X](https://x.com/appwrite) and check our [Changelog](https://appwrite.io/changelog) regularly, as we will release more information in the coming weeks.


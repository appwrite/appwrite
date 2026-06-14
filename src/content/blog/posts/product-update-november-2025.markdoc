---
layout: post
title: "November product update: Database AI suggestions and ElevenLabs template"
description: Get a full recap of products, features and programs we released in the month of November.
date: 2025-12-09
cover: /images/blog/product-update-november-2025/cover.avif
timeToRead: 8
author: dennis-ivy
category: product
featured: false
callToAction: true
faqs:
  - question: "What are DB operators in Appwrite?"
    answer: "DB operators are inline, atomic instructions you can send inside an update or upsert call, like incrementing a counter or appending to an array. They run server side, so you avoid race conditions and skip the read-then-write pattern that normally requires fetching the row first."
  - question: "What do Database AI suggestions do?"
    answer: "When you create a new table in [Appwrite Databases](/docs/products/databases), the Console can suggest columns and indexes based on the table name. You can accept, edit, or skip the suggestions, which is faster than designing the schema from scratch."
  - question: "How does skipping total counts speed up list queries?"
    answer: "List endpoints normally compute the total number of matching rows alongside the page. Passing `total=false` tells Appwrite to skip that count, which removes an extra database operation and returns the page faster, especially on large tables."
  - question: "What is Next.js standalone mode and why does it matter on [Appwrite Sites](/docs/products/sites)?"
    answer: "Standalone mode produces a slimmer Next.js build that only includes the files needed to run in production. Deploying in standalone mode on Appwrite Sites reduces artifact size and improves cold starts compared to shipping the full project."
  - question: "Why would I disable image transformations on a bucket?"
    answer: "If a bucket only stores raw assets or files that should never be resized or re-encoded, turning transformations off prevents accidental processing and the associated cost. It is a defensive setting for buckets that do not need the feature."
  - question: "Where can I read the Appwrite open letter on the developers' cloud?"
    answer: "The letter is published on the [Appwrite blog](/blog/post/the-developers-cloud) under the title \"the developers' cloud.\" It explains how Appwrite is evolving from a backend platform into a full cloud that covers build, deploy, observe, and protect."
---
Welcome back to the November product update. This month is different. If you caught our Open Letter, you already know where we're heading: Appwrite is no longer just a backend. With Appwrite Sites, we're taking a real step toward the most complete cloud platform to date.

And on top of that, we've shipped a stack of upgrades across Databases, plus a new ElevenLabs template to add voice to your apps in minutes.

So, here's what to expect:

- DB operators
- Database AI suggestions
- Skipping total counts for faster list queries
- Disable image transformations for buckets
- Next.js standalone builds support

Let's dive in.

![An open letter to Appwrite Community](/images/blog/product-update-november-2025/open-letter.avif)

# An open letter to Appwrite Community

This month, we shared something bigger than a feature release: our vision for where Appwrite is heading. In our **Open Letter**, Eldad breaks down how Appwrite is evolving from a backend platform into a full **developers' cloud** that supports the entire product journey: imagine → build → deploy → observe → protect.

If you want the full story behind why Sites matters and where the platform is going next, you should definitely give it a read.

[Read the full open letter](/blog/post/the-developers-cloud)

![Database AI suggestions](/images/blog/product-update-november-2025/ai-suggestions.avif)

# Database AI suggestions

Starting with an empty table can be slow, repetitive, and time-consuming. With Database AI suggestions, you get the option to use suggested columns and indexes the moment you name your table.

That means:

- No more blank schemas - Appwrite suggests sensible columns you can use.
- Faster setup - Go from table name to schema in seconds.
- You're in control - Review, tweak, or skip the suggestions.

[Read the announcement](/blog/post/announcing-database-ai-suggestions)

![DB operators](/images/blog/product-update-november-2025/db-operators.avif)

# DB operators

Now you don't have to fetch an entire row just to bump a number or tweak a tag list.

With DB Operators, you can now send inline, atomic update instructions directly in your Update or Upsert call. No race conditions. No bulky payloads. Now live on Appwrite Cloud.

[Read the announcement](/blog/post/announcing-db-operators)

![ElevenLabs Text-to-Speech template](/images/blog/product-update-november-2025/elevenlabs.avif)

# ElevenLabs Text-to-Speech template

You can now add a real, human-like AI voice to your apps using Appwrite Sites with our new ElevenLabs integration.

And the best part? We've shipped a ready-to-use Appwrite Sites template built with ElevenLabs Text-to-Speech API.

[Check out the integration](/integrations/ai-elevenlabs-text-to-speech)

![Skip total counts](/images/blog/product-update-november-2025/skip-totals.avif)

# Skip total counts for faster list queries

A new way to optimize your Appwrite Databases. You can now skip total counting on list endpoints with a simple `total=false` flag and get faster, lighter list responses without the extra database work.

[Learn more from the documentation](/docs/products/databases/pagination#skip-totals)

![Next.js standalone builds](/images/blog/product-update-november-2025/nextjs.avif)

# Next.js standalone builds are now supported on Appwrite Sites

You can now deploy Next.js apps built in [standalone mode](https://nextjs.org/docs/app/api-reference/config/next-config-js/output) on Appwrite Sites, with full support for Next.js 16. In standalone mode, the build process creates a smaller package that includes only the files your app needs to run in production, leaving out development tools and unused parts of the framework.

[Read the announcement](/blog/post/nextjs-standalone-support-in-appwrite-sites)

# Disable image transformations for buckets

Image transformations allow actions like resizing, cropping, and format conversion through the Appwrite Storage API. With this new update, you have full control to turn off these operations when they're not needed, reducing the chance of unintentional processing or costs.

![Community recognitions](/images/blog/product-update-november-2025/community.avif)

# Community recognitions

We're excited to feature [Appwrite ORM](https://appwrite-orm.online/) as part of our Monthly Community recognitions this month.

A powerful TypeScript ORM that adds type safety, automatic migrations, and smooth developer workflows on top of Appwrite.

A big shout-out to [Ori Raisfeld](https://www.linkedin.com/in/ori-raisfeld-422392264/).

If you'd like to participate in next month's Community Recognitions, [join our Discord server](https://appwrite.io/discord) to showcase your project.

# Engineering resources

Read
- [Introducing the developers' cloud](/blog/post/the-developers-cloud)
- [Announcing Database AI suggestions: from table name to schema in one click](/blog/post/announcing-database-ai-suggestions)
- [What's new in Node.js v25.2: Web Storage, V8 14.1, permissions and more](/blog/post/nodejs-v25-whats-new)
- [Remix 3: what's changing and why it matters](/blog/post/remix-3-whats-changing-and-why-it-matters)
- [Next.js standalone builds now supported on Appwrite Sites](/blog/post/nextjs-standalone-support-in-appwrite-sites)
- [Announcing DB operators: Update multiple fields without fetching the entire row](/blog/post/announcing-db-operators)

# Conclusion

That's a wrap. We've been building up the hype for a while now, and it's almost here. If you're into vibe coding or building AI applications, then we've got some massive news for you in the coming weeks. Stay tuned!

Follow us on [X](https://x.com/appwrite) and check our [Changelog](/changelog) regularly, as we will release more information in the coming weeks.

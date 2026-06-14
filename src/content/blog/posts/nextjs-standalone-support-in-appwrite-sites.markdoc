---
layout: post
title: Next.js standalone builds now supported on Appwrite Sites
description: Deploy Next.js apps in standalone mode on Appwrite Sites with full Next.js 16 support, smaller builds, faster cold starts, and lighter deployments.
date: 2025-11-12
cover: /images/blog/nextjs-standalone-builds/cover.avif
timeToRead: 4
author: ebenezer-don
category: announcement
faqs:
  - question: "How do I enable Next.js standalone mode on Appwrite Sites?"
    answer: "Add output: 'standalone' to your next.config.js and build the project as usual. [Appwrite Sites](/docs/products/sites) detects the standalone output automatically, so no further configuration is required. You can then deploy with git push or the Appwrite CLI."
  - question: "What benefits does standalone mode bring?"
    answer: "In Appwrite's benchmarks, standalone builds were about 6x smaller and started about 2x faster than default builds. The reduced size also means quicker deployments and lower storage usage per build. This translates to faster iteration and a better user experience after deploys."
  - question: "Does standalone mode work with Next.js 16?"
    answer: "Yes, Appwrite Sites supports Next.js 16 with standalone builds. The work that enabled standalone support came out of community feedback on Next.js 16 build behavior. If you are upgrading to Next.js 16, standalone mode is a strong default to consider."
  - question: "Do I need to change my deployment workflow to use standalone mode?"
    answer: "No, the workflow is the same. You push to your Git repo or use the Appwrite CLI, and Appwrite Sites detects the standalone output. Only your next.config.js needs the output: 'standalone' line."
  - question: "Does standalone mode affect SSR, API routes, or middleware?"
    answer: "No, standalone mode preserves full Next.js functionality including SSR, API routes, middleware, and server actions. It is just a different bundling strategy for the same app. You keep all the features of Next.js while shipping a smaller artifact."
  - question: "Is Appwrite Sites a good alternative to Vercel for Next.js?"
    answer: "Yes, [Appwrite Sites](/docs/products/sites) runs Next.js on containers without serverless constraints, so most apps work without workarounds. It also integrates with the rest of Appwrite Cloud for backend features like databases, auth, and storage. See the [Vercel migration guide](/docs/products/sites/migrations/vercel) for a walkthrough."
---

You can now deploy Next.js apps built in [standalone mode](https://nextjs.org/docs/app/api-reference/config/next-config-js/output) on Appwrite Sites, with full support for Next.js 16. In standalone mode, the build process creates a smaller package that includes only the files your app needs to run in production, leaving out development tools and unused parts of the framework.

# Why standalone mode?

In our benchmarks, build size dropped by about six times on average, and cold start times were 2x faster. Deployments are lighter with faster build times, and sites start responding more quickly after going live.

This work began with a report by a community member, [Warfin](https://github.com/WarFiN123), about build issues in Next.js 16. Those discussions led to deeper improvements in how Appwrite handles builds, which made standalone support possible.

# How to enable Next.js standalone mode

To enable standalone mode, open your `next.config.js` file and add `output: 'standalone'` to your existing configuration:

```js
/** @type {import('next').NextConfig} */
module.exports = {
  output: 'standalone',
};
```

Then build your project as usual. Appwrite Sites now recognizes that output automatically, so you can deploy it directly with no further changes.

This update also lays the groundwork for broader improvements as we better understand how we can apply the same benefits to all Next.js builds, and eventually to all SSR builds.

We're excited to see what you ship next!


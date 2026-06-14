---
layout: post
title: "Everything new in Next.js 16"
description: Next.js 16 is now available, bringing Cache Components, Turbopack, and refined caching, now fully supported on Appwrite Sites.
date: 2025-10-30
cover: /images/blog/everything-new-in-nextjs16/cover.avif
timeToRead: 5
author: matej-baco
category: tutorial
faqs:
  - question: "What is new in Next.js 16?"
    answer: "Next.js 16 ships Cache Components for explicit caching, Turbopack as the default bundler, a renamed `proxy.ts` (previously `middleware.ts`), better build and dev logs, stable React Compiler support, smarter routing and prefetching, refined caching APIs, React 19.2, and the new Next.js DevTools MCP. The release focuses on fundamentals over big rewrites."
  - question: "Does Appwrite Sites support Next.js 16?"
    answer: "Yes, Next.js 16 is fully supported on [Appwrite Sites](/docs/products/sites) from day one. You can build, test, and host Next.js 16 projects with SSR, RSC, and the new Cache Components without extra configuration."
  - question: "What are Cache Components in Next.js 16?"
    answer: "Cache Components introduce an explicit caching model that replaces the framework's implicit guesses about what to cache. You decide which parts of your render tree are cached and when to revalidate them. Combined with Partial Pre-rendering, this makes static and dynamic boundaries predictable inside the same page."
  - question: "Why is middleware now called proxy in Next.js 16?"
    answer: "Middleware in Next.js was never really middleware in the Express sense. It runs at the edge and blocks the initial response, so heavy network calls inside it can stall page loads. The rename to `proxy.ts` clarifies the intent: lightweight request handling like redirects, header rewrites, and auth cookie checks, not arbitrary business logic."
  - question: "Should I switch to Turbopack now?"
    answer: "Yes, especially for new projects. Turbopack is now the default bundler in Next.js 16, with 2 to 5 times faster builds and up to 10 times faster Fast Refresh. Filesystem caching is still in beta but already cuts startup times significantly on large projects. For existing apps, test the migration before flipping the switch in production."
  - question: "What are the new system requirements for Next.js 16?"
    answer: "Next.js 16 requires Node.js 20.9 or higher, TypeScript 5.1 or higher, and modern browsers. AMP support and the `next lint` command are removed, so projects depending on those need to migrate before upgrading."
---
Next.js 16 is here, and it brings one of the most polished releases the framework has seen in a while. Instead of chasing big rewrites, this version focuses on the fundamentals, faster builds, predictable caching, smarter routing, and better developer visibility.

And if you’re deploying on **Appwrite Sites**, you can start using Next.js 16 today. The latest release is fully supported, so you can build, test, and host your Next.js projects seamlessly on Appwrite’s open-source infrastructure.

Many of the features introduced in earlier betas are now stable and production-ready, giving developers more control and consistency across their workflows.

Here’s what’s new, what’s changed, and why it matters for anyone building with Next.js today.

# Cache components

Caching in Next.js used to be a bit of a mystery. Sometimes your data was cached when you didn’t want it to be; other times it wasn’t cached at all. You’d deploy, see stale data, and wonder if the problem was in your code or in the framework.

Next.js 16 fixes that by introducing **Cache Components:** A new, explicit caching model. Instead of Next.js deciding what to cache, you tell it what should be cached and when to revalidate.

It’s built on top of **Partial Pre-rendering (PPR)**, which already blurred the line between static and dynamic pages. Together, they make rendering predictable and flexible.

# Next.js DevTools MCP

One of the more forward-looking additions in this release is **Next.js DevTools MCP**.

It hooks into the **Model Context Protocol (MCP)**, which lets AI tools and agents understand your project’s context, your routing, caching, and rendering behaviour.

Why does that matter? Because AI that actually knows how your app works can give better debugging help, clearer explanations, and smarter suggestions. Instead of just dumping stack traces, it can reason about what’s happening under the hood.

It’s still early, but it points toward a future where your dev tools don’t just react to errors, they understand them.

# proxy.ts

The old `middleware.ts` file is now `proxy.ts`. That’s it. Same behaviour, better name.

There has been a lot of confusion around how middlewares work in Next.js. These middlewares are not your typical middlewares. You’re at the mercy of any network calls you make in these middlewares, as a single slow network call may block the initial load of your entire webpage, which is not ideal. Middlewares in Next.js are used to do something lightweight, for example, redirecting users based on the authentication cookies stored. Since the term *middleware* continued to cause issues, the Next.js team finally decided to rename it to proxy so that the purpose of using it is clear.

# Logging improvements

Next.js 16 also improves logs, and not in a superficial “we added colours” way.

Build and dev logs now show where time is spent, breaking down compilation, rendering, and optimization steps. So if your build suddenly feels slower, you can immediately tell which parts are to blame.

It’s a quiet but practical improvement that makes day-to-day development just a little less frustrating.

# Turbopack update

**Turbopack** has officially graduated from beta and is now the default bundler for all new Next.js projects.

It’s fast, like **2–5×** faster builds and up to 10× faster Fast Refresh. For large projects, filesystem caching (currently in beta) further cuts down on startup times by reusing compiled artifacts between sessions.

You’ll notice the difference most if you’re in that constant build, refresh–iterate loop all day. It’s less about benchmarks and more about getting back into flow faster.

# Build Adapters API

If you’ve ever had to deploy a Next.js app in an unusual environment, say, outside Vercel, this one’s for you.

**Build Adapters**, now in alpha, let you hook into the build process and modify it without forking the framework. It’s especially handy for teams self-hosting or building custom pipelines.

This update shows that Next.js is starting to take flexibility seriously for developers running the framework in different environments.

# React compiler support

Next.js 16 now includes stable support for the **React Compiler**, which automatically memoizes components to cut down on unnecessary re-renders.

You don’t have to sprinkle `useMemo` or `useCallback` everywhere. The compiler handles it. There’s a small tradeoff in build time since it uses Babel, but the performance gains in rendering make it worthwhile for complex UI-heavy apps.

# Routing and Prefetching

Routing has seen a major under-the-hood upgrade.

Prefetching is now more efficient thanks to layout deduplication. Shared layouts download once, even if you prefetch dozens of links. And incremental prefetching ensures that only what's missing gets fetched instead of entire pages.

It even cancels prefetch requests when links leave the viewport. You won’t have to change your code to benefit. Everything just feels snappier.

# Refined caching APIs

Caching APIs have been cleaned up and made more explicit.

The updated `revalidateTag()` now takes a cache-life profile, letting you fine-tune how data revalidates. The new `updateTag()` API adds “read-your-writes” behavior, so when users make changes, they see them reflected right away. And `refresh()` handles refreshing uncached data like live counts or notifications.

Together, these updates turn caching into a transparent system that behaves exactly how you expect, not how the framework guesses you want it to.

# React 19.2 and Core Updates

Next.js 16 ships with **React 19.2**, which brings some nice quality-of-life improvements:

- `useEffectEvent()` for cleaner effect logic
- View Transitions for smoother navigation
- The new `<Activity />` component for managing background UI states

The release also updates core requirements. You’ll need Node.js 20.9+, TypeScript 5.1+, and modern browsers. Old features like AMP support and the `next lint` command are gone, making the framework leaner overall.

# Wrapping up

Next.js 16 isn’t a release that changes how you build. It’s one that changes how your build *feels*. Caching is now predictable. Builds are faster. Routing is leaner. Logs are clearer.

And with [Appwrite Sites now supporting Next.js 16](/docs/products/sites/quick-start/nextjs), you can deploy and host your latest projects right away, from development to production, on fully open-source infrastructure. Whether you’re experimenting with Cache Components, testing Turbopack, or building production apps, everything runs smoothly on Appwrite Sites.

# More resources
- [Deploy a Next.js app to Appwrite Sites](/docs/products/sites/quick-start/nextjs)
- [Learn more about Appwrite Sites](/docs/products/sites)

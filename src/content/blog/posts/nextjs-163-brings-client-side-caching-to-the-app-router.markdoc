---
layout: post
title: Next.js 16.3 brings client-side caching to the App Router
description: Next.js 16.3 lands with client-side caching, up to 90% less dev server memory, disk cached builds, and opt-in Instant Navigations. Here is how to upgrade.
date: 2026-08-05
cover: /images/blog/nextjs-163-brings-client-side-caching-to-the-app-router/cover.avif
timeToRead: 5
author: aditya-oberai
category: news
featured: false
faqs:
  - question: What is new in Next.js 16.3?
    answer: Next.js 16.3 introduces Instant Navigations with client-side caching for the App Router, Partial Prefetching, Instant Insights, Navigation Inspector, Root Params, catchError error boundaries, and performance improvements including up to 90% lower dev server memory usage and faster builds.
  - question: Does Next.js 16.3 have breaking changes?
    answer: "No. Next.js 16.3 is a minor release with no breaking changes. Existing applications can upgrade safely while enabling new features like Instant Navigations only when they're ready."
  - question: What is Instant Navigations in Next.js 16.3?
    answer: Instant Navigations is an opt-in feature that uses client-side caching, Suspense, and Partial Prefetching to make App Router page transitions feel instant while keeping Server Components and server rendering.
  - question: How do I upgrade to Next.js 16.3?
    answer: Upgrade by installing the latest version of Next.js with your package manager, then run your existing test suite. Most performance improvements work immediately without requiring code changes.
---
[Next.js 16.3](https://nextjs.org/blog/next-16-3) is out, and the headline change is not a new API. It is that the App Router finally has a client-side cache.

Since Server Components landed, Next.js apps have been good at fetching data and rendering complete pages, and bad at feeling responsive. You click a link, the browser waits on the server, and the user stares at the old page until something arrives. The `'use cache'` directive introduced in [Next.js 16](/blog/post/everything-new-in-nextjs16) solved the server half of that problem. Next.js 16.3 extends the same primitive to the client.

The release also ships a set of performance wins that apply to every existing app with no code changes: up to 90% less dev server memory, cached production builds, and 22% more requests handled under load.

Here is what actually changed in Next.js 16.3, what is opt-in, and whether you should upgrade.

# What's new in Next.js 16.3 at a glance

**Short answer:** Next.js 16.3 is a minor release with two halves. The first half improves every existing app with zero code changes. The second half is **Instant Navigations**, an opt-in suite built on `'use cache'` that brings client-side caching and SPA-style navigation to the App Router.

| Change                                        | Availability | What you have to do        |
| --------------------------------------------- | ------------ | -------------------------- |
| Turbopack memory eviction in dev              | Default      | Nothing                    |
| Turbopack FileSystem Cache for `next build`   | Default      | Nothing                    |
| Native Node.js streams in App Router SSR      | Default      | Nothing                    |
| Prefetch inlining                             | Default      | Nothing                    |
| Versioned docs for AI coding agents           | Default      | Run `next dev`             |
| TypeScript 7 type checking                    | Opt-in       | Bump `typescript` to `^7`  |
| Root params, `catchError`, `import.meta.glob` | New APIs     | Adopt where useful         |
| Instant Navigations                           | Opt-in       | Two `next.config.ts` flags |
| Rust React Compiler, network resilience       | Experimental | Config flags               |

Nothing here is a breaking change, which is why the Next.js team recommends every app upgrade.

# Next.js 16.3 performance improvements you get on upgrade

These four changes require no application code changes at all. They are the reason to run `npm install next@latest` today even if you never touch Instant Navigations.

## Up to 90% less dev server memory

Long `next dev` sessions used to balloon. Turbopack now combines disk caching for dev (shipped in 16.1) with memory eviction, and both are on by default in 16.3.

| App                  | Memory after compiling 50 routes (before) | After  | Reduction |
| -------------------- | ----------------------------------------- | ------ | --------- |
| vercel.com dashboard | 21.5 GB                                   | 2 GB   | \~90%     |
| nextjs.org           | 4,600 MB                                  | 840 MB | \~82%     |

If you have been restarting your dev server every hour on a large app, this is the fix.

## Faster builds with the Turbopack FileSystem Cache

The same disk cache now works with `next build`, so repeat builds read unchanged artifacts instead of recompiling them. Vercel has been running this in production for months.

| Project          | Cold build | Cached build | Speedup |
| ---------------- | ---------- | ------------ | ------- |
| nextjs.org       | 21s        | 9.2s         | \~2.3x  |
| vercel.com/home  | 66s        | 46s          | \~1.4x  |
| vercel.com/geist | 30s        | 5.5s         | \~5.5x  |

The gain depends entirely on how much of your dependency graph is stable between builds. A CI runner that starts from a clean checkout every time gets nothing unless you persist the cache directory between runs.

## Faster type checking with TypeScript 7

[TypeScript 7](https://devblogs.microsoft.com/typescript/announcing-typescript-7-0/), the native port of the compiler, is roughly 10x faster at type checking. `next build` can use it as soon as you bump the dependency:

```bash
pnpm add -D typescript@^7
```

This is opt-in and independent of everything else in the release, so it is the cheapest single win available.

## Faster server-side rendering

The App Router rendering layer now uses native Node.js streams instead of web streams, removing the conversion overhead on every render. In Vercel's benchmarks, apps handle **up to 22% more requests under load** with no code changes. The [implementation PR](https://github.com/vercel/next.js/pull/94311) has the details.

For anyone running SSR on their own infrastructure, more requests per instance means fewer instances for the same traffic.

# Client-side caching in the App Router with 'use cache'

This is the conceptual change worth understanding, because everything else in Instant Navigations builds on it.

Before Next.js 16, caching in the App Router was implicit. The framework guessed what to cache, and you spent your time discovering the guesses. `'use cache'` inverted that: you mark what should be cached, and the rest is dynamic by default.

In 16.3, that directive now also caches on the client. Combined with Suspense, it means a route's UI can be split into three kinds of content:

* **Prerenderable UI** marked with `'use cache'`, which can be prefetched and shown instantly
* **Dynamic UI** wrapped in `<Suspense>`, which streams in at request time
* **Inline loading states** that render immediately while dynamic content is in flight

Next.js can extract the first and third categories, ship them to the client before a navigation happens, and render them the moment a link is clicked. That is what makes a server-driven app feel like an SPA without giving up server rendering. If you are still deciding where each piece of your UI belongs, our breakdown of [client vs server components in React](/blog/post/client-vs-server-components-react) is a useful companion.

# Instant Navigations in Next.js 16.3

Instant Navigations is opt-in behind two flags:

```ts
// next.config.ts
import type { NextConfig } from 'next';

const nextConfig: NextConfig = {
  cacheComponents: true,
  partialPrefetching: true,
};

export default nextConfig;
```

The Next.js team has said these behaviors will become the default in a future major version, so adopting now is also a way to avoid a larger migration later.

## Partial Prefetching

Prefetching used to be all or nothing. You either defined a reusable loading shell in `loading.tsx` or opted into aggressive full-page prefetching with `<Link prefetch={true}>`.

Partial Prefetching removes that choice. Next.js extracts a reusable loading shell from any route's UI, and each link decides how much of the target page to include. You get instant feedback on click without prefetching entire pages your users may never visit.

## Instant Insights

The easiest way to ship a slow navigation was to forget a `loading.tsx` file. Instant Insights is a new DevTools panel that flags any navigation you hit in development that was not instant, and includes a prompt you can hand to a coding agent to apply the fix.

## Better ISR for partially prerendered routes

If you prerender only some pages of a route with `generateStaticParams`, the remaining pages used to force a tradeoff: show a loading shell and never get prerendered, or block the first visitor entirely.

In 16.3 a non-prerendered page serves an instant loading shell on its first visit, upgrades to the fully prerendered page in the background, and serves cached content to everyone after that. This matters most for large catalogs and personalized dashboards where prerendering every path at build time is not realistic.

## Navigation Inspector

Because prefetching is disabled in development, it is hard to see what a user actually sees mid-navigation. The Navigation Inspector pauses page loads and client-side navigations at the shell so you can inspect the exact loading state.

## Playwright test helper

An instant navigation stays instant until someone adds a `cookies()` call to a shared header. The new `instant()` helper from `@next/playwright` lets you assert what must be visible before any network work completes, so the test fails when a refactor de-opts the route:

```ts
// e2e/instant-navigation.spec.ts
import { expect, test } from '@playwright/test';
import { instant } from '@next/playwright';

test('product title is available immediately', async ({ page }) => {
  await page.goto('/products/shoes');

  await instant(page, async () => {
    await page.click('a[href="/products/hats"]');
    await expect(page.locator('h1')).toContainText('Baseball Cap');
    await expect(page.getByText('Checking inventory...')).toBeVisible();
  });

  await expect(page.getByText('12 in stock')).toBeVisible();
});
```


# New Next.js 16.3 APIs worth adopting

## Root params

Params defined above the root layout, like `[lang]`, are effectively global, but the only way to read them was prop drilling. `next/root-params` exposes them directly to any Server Component:

```tsx
// app/[lang]/posts/[slug]/page.tsx
import { lang } from 'next/root-params';

export default async function PostPage(
  props: PageProps<'/[lang]/posts/[slug]'>,
) {
  const { slug } = await props.params;
  const language = await lang();

  return (
    <article>
      <p>Language: {language}</p>
      <p>Post: {slug}</p>
    </article>
  );
}
```

Root params also work inside `use cache` scopes, which makes internationalization considerably less painful. Support for route handlers and Server Actions is planned for a future release.

## Custom error boundaries with catchError

React error boundaries in Next.js used to interfere with `notFound` and `redirect`, and could only reset client state. The new `catchError` export from `next/error` defines a boundary that leaves those functions alone and hands your fallback a `retry()` function that refetches the boundary's children, including any Server Components that failed to render.

That last part is the meaningful change. A failed data fetch on the server is now recoverable without a full page reload.

## Glob imports in Turbopack

Turbopack now supports the Vite-compatible `import.meta.glob` API, so Server Components that read local files get hot module reloading:

```tsx
const posts = import.meta.glob('./posts/*.md', { eager: true });
```

Anyone maintaining a file-based content directory has been writing `fs.readdir` wrappers for this.

# Experimental features in Next.js 16.3

Two features are behind experimental flags and worth watching rather than shipping:

* **Rust-based React Compiler** (`experimental.turbopackRustReactCompiler`) runs the compiler inside Turbopack instead of Babel. On large apps like v0, time from `next dev` to a ready page dropped 34% on cold builds and 46% on warm ones. The Rust React Compiler currently targets projects that are not using Babel.
* **Network resilience** (`experimental.useOffline`) keeps navigations, fetches, and Server Actions pending instead of throwing when the connection drops, then retries on reconnect. A `useOffline` hook lets you surface that state to users.

Feedback goes in the [Next.js 16.3 discussion](https://github.com/vercel/next.js/discussions/95130).

# Should you upgrade to Next.js 16.3?

**Short answer:** yes, upgrade now for the default improvements. Adopt Instant Navigations deliberately, one route at a time.

| Your situation                         | Recommendation                                                                   |
| -------------------------------------- | -------------------------------------------------------------------------------- |
| On Next.js 16.x                        | Upgrade now. No breaking changes, immediate build and dev wins.                  |
| Slow navigations are a known complaint | Enable both Instant Navigations flags and start with your highest-traffic route. |
| Still on the Pages Router              | Upgrade for the SSR and build gains. Instant Navigations is App Router only.     |
| Large `generateStaticParams` catalog   | The new ISR behavior is the single biggest reason to adopt Cache Components.     |
| Heavy Babel usage                      | Skip the Rust React Compiler until you can drop Babel.                           |

For most projects, upgrading starts with:

```bash
npm install next@latest
```

Existing projects moving to Cache Components can follow the official migration guide, and the migration is designed to be run incrementally rather than in one pass.

# Deploying Next.js 16.3 without rewriting your app for serverless

A Next.js minor release is only cheap to adopt if your host keeps up with it. This is where some teams run into friction: features like streaming SSR, Server Actions, and now client-side caching depend on the full Next.js server, and some hosting platforms rely on adapters to support newer Next.js capabilities.

[Appwrite Sites](/docs/products/sites) runs Next.js in a container-based Node.js runtime rather than translating it into serverless functions, so [SSR](/docs/products/sites/rendering/ssr), API routes, middleware, and Server Actions work without the OpenNext adapter or platform-specific configuration. Upgrading to 16.3 is a dependency bump and a [Git push](/docs/products/sites/deploy-from-git).

To be fair about the tradeoff: Vercel ships Next.js features on day one because it builds the framework, and a container runtime has different cold start and scaling characteristics than edge functions. What you get in exchange is a deployment target where a Next.js upgrade does not turn into a hosting investigation, plus the rest of Appwrite's backend in the same project. If you are evaluating the move, the [Vercel migration guide](/docs/products/sites/migrations/vercel) covers the specifics, and [Next.js output modes](/blog/post/nextjs-output-modes) explains why standalone builds are the better default for self-managed hosting.

# Getting started with Next.js 16.3 on Appwrite Sites

Upgrade your `next` dependency, run your test suite, and deploy. If you want to try Instant Navigations without touching a production app, spin up a fresh Next.js project, enable `cacheComponents` and `partialPrefetching`, and deploy it to Appwrite Sites to see how the loading shells behave over a real network instead of localhost. The [Next.js quick start](/docs/products/sites/quick-start/nextjs) gets you from `create-next-app` to a live URL in a few minutes, and Appwrite's free tier covers side projects.

# Resources

* [Deploy a Next.js app to Appwrite Sites](/docs/products/sites/quick-start/nextjs)
* [Appwrite Sites documentation](/docs/products/sites)
* [Server-side rendering on Appwrite Sites](/docs/products/sites/rendering/ssr)
* [Migrate from Vercel to Appwrite Sites](/docs/products/sites/migrations/vercel)
* [Everything new in Next.js 16](/blog/post/everything-new-in-nextjs16)
* [Next.js standalone builds on Appwrite Sites](/blog/post/nextjs-standalone-support-in-appwrite-sites)
* [Join the Appwrite Discord](https://appwrite.io/discord)
---
layout: post
title: "How to host SSR web apps on Appwrite Sites"
description: Delivering the right content at the right time is essential for modern web apps, especially when speed and personalization are priorities. In this post, we’ll walk you through how to deploy server-rendered applications with Appwrite Sites, giving you full control over dynamic rendering and framework-specific setups.
date: 2025-05-19
cover: /images/blog/host-ssr-web-cover.avif
timeToRead: 6
author: ebenezer-don
category: tutorial
featured: false
callToAction: true
faqs:
  - question: "What is SSR and when should I use it instead of static rendering?"
    answer: "Server-Side Rendering generates pages on the server at request time, so each user gets dynamic content based on their session, location, or live data. Use it when pages depend on per-request state (auth, personalization, real-time data) or need SEO-friendly dynamic content. For pages that don't change between users, static generation is faster and cheaper."
  - question: "Does [Appwrite Sites](/docs/products/sites) support SSR?"
    answer: "Yes. Appwrite Sites supports SSR alongside Client-Side Rendering and Static Site Generation. It runs your app on a Node.js server runtime where SSR is needed, and you configure the build settings to match your framework."
  - question: "I'm migrating from Vercel. What do I need to change?"
    answer: "Most frameworks ship Vercel-specific adapters that won't run on Appwrite's Node.js runtime. For SvelteKit, switch from `@sveltejs/adapter-vercel` to `@sveltejs/adapter-node`. For Next.js, remove the `output` field from `next.config.js` so Next.js uses its default behavior. Nuxt usually works without changes."
  - question: "What output directory should I use for Next.js on Appwrite Sites?"
    answer: "Keep the output directory as `./.next`, which is the default Next.js build location. Do not set the `output` field in `next.config.js`, since that's a Vercel-specific setting and interferes with how Appwrite handles the build."
  - question: "Can I import environment variables from an existing .env file?"
    answer: "Yes. In the deployment configuration page, you can define environment variables manually or import them from an existing .env file. These are made available at both build time and runtime depending on framework conventions."
  - question: "Does Appwrite Sites auto-deploy on every Git push?"
    answer: "Yes. Once you connect a GitHub repository and pick a production branch, Appwrite pulls new commits, rebuilds, and deploys automatically. You can enable silent mode if you don't want it to add comments on GitHub PRs."
---

When you're building a modern web app, how you serve your content matters. Some pages need to be pre-rendered ahead of time for speed. Others need to be generated dynamically on the server for personalization or real-time data. This process is called Server-Side Rendering (SSR), and Appwrite Sites supports SSR, just like it supports Client-Side Rendering (CSR) and Static Site Generation (SSG).

If you've used platforms like Vercel before, this will feel familiar. You can connect your GitHub repository, choose a branch, and Appwrite will build your app and deploy it to a server.

In this post, we'll show you exactly how to host an SSR-capable web app using [Appwrite Sites](/products/sites), from repository connection to framework-specific configurations. Whether you're migrating from Vercel or starting from scratch, this guide will walk you through every step.

# What is SSR, and why does it matter?

Server-Side Rendering (SSR) means your web app's pages are rendered on the server at request time. Unlike pre-rendered static pages, SSR allows your app to respond to each request with dynamic content that's fully rendered before it reaches the browser. It's a powerful approach for apps that need authentication, personalization, real-time data, or SEO-friendly dynamic content.

Appwrite Sites supports SSR by allowing you to configure your framework's build settings and output behavior, and by running your app on a server runtime (Node.js) where needed.

# Hosting an SSR app on Appwrite Sites

Let's walk through the process step by step.

## 1. Open the Appwrite Console

Log in to your Appwrite Console. In the left sidebar, you'll see a section labeled **Sites**. Click on it. This will take you to the Sites dashboard, where you can manage existing sites or deploy a new one.

Click the **Create Site** button to get started.

![Setup Sites on the Console](/images/blog/ssr-how-to-create-sites.avif)

At this point, you have two options:

- **Clone a template** , If you're starting fresh, this is a quick way to scaffold a new site.
- **Connect a repository** , If you already have a working app in GitHub, this is what you'll want.

Choose **Connect a repository**. Appwrite will walk you through selecting a GitHub repository and giving the necessary permissions if you haven't already.

## 2. Configure your deployment settings

Once your repository is connected, you'll land on the deployment configuration page. Here's what you'll see:

- **Name**: A name for your site, usually taken from your repository.
- **Branch**: The branch you want to use for production (typically `main` or `master`).
- **Root directory**: Leave this as `./` unless your app lives in a subfolder.
- **Silent mode**: This prevents Appwrite from adding GitHub comments when changes are pushed.
- **Framework**: Optional, but recommended. Selecting your framework here helps Appwrite apply sensible defaults and display SSR instructions specific to your setup.

Now, scroll down to the **Build settings** section. This is where you define how your app gets built:

- **Install command**: The default `npm install` usually works unless your app uses a different package manager or custom setup.
- **Build command**: For most frameworks, this is `npm run build`.
- **Output directory**: For Next.js, this would typically be `./.next`. Different frameworks may use different output paths.

If your app needs environment variables, like API keys, secrets, or runtime flags, you can define them in the **Environment variables** section. You can also import them from an existing `.env` file.

When you're done, click **Deploy**. Appwrite will pull your code, run your build steps, and deploy your site.

# Framework-specific SSR tips

While Appwrite Sites supports SSR, not every framework behaves the same out of the box. If your app was previously deployed on Vercel, there's a good chance it was configured with a Vercel-specific adapter, especially if you used Next.js or SvelteKit.

These adapters are tailored for Vercel's environment and won't run properly on Appwrite, which expects Node.js as the runtime. To make SSR work on Appwrite, you'll need to switch to the appropriate Node.js adapter.

Let's go through a few popular frameworks and what to look out for:

## Next.js

If you're using Next.js, SSR is supported. In your `next.config.js` file, **make sure you do not set the `output` field**. That's specific to Vercel and can interfere with the way Appwrite handles the build output.

Just let Next.js use its default behavior, and in your site settings, keep the output directory as `./.next`.

## SvelteKit

SvelteKit sites hosted on Vercel typically use `@sveltejs/adapter-vercel`. That won't work on Appwrite. To fix this, open your `svelte.config.js` file and switch to the Node.js adapter:

```tsx
import adapter from '@sveltejs/adapter-node'

export default {
  kit: {
    adapter: adapter(),
  },
}
```

Once that's set, rebuild and redeploy your site.

## Nuxt

Nuxt works quite seamlessly on Appwrite. In your site settings, make sure the build command is set to:

```bash
npm run build
```

SSR is supported out of the box with no additional adapter configuration needed.

## Angular (with SSR)

If you've enabled SSR in your Angular app, make sure your `src/server.ts` file is using the `@angular/ssr/node` package. This enables Angular Universal to run properly in a Node.js environment.

## Astro

For Astro apps, you'll want to install and configure the Node adapter. In `astro.config.mjs`, update your adapter:

```tsx
import node from '@astrojs/node'

export default {
  output: 'server',
  adapter: node(),
}
```

This tells Astro to render pages server-side using Node.js.

## Remix

Remix apps should be configured to use `@remix-run/node` in the server entry file. Make sure your `entry.server.tsx` file imports from:

```tsx
import { createRequestHandler } from '@remix-run/node'
```

This ensures the server runtime is correctly set for Node environments like Appwrite.

## Analog (Angular meta-framework)

Analog supports SSR via Vite. To enable it, set the `ssr` property to `true` inside your `vite.config.ts` file:

```tsx
export default defineConfig({
  plugins: [
    analog({
      ssr: true,
    }),
  ],
})

```

This activates server-side rendering in your build pipeline.

# How to check or change framework instructions

If you're not sure what to do for your specific framework, you can find help directly in the Appwrite Console.

Just open your deployed site from the Sites dashboard, click on **Settings**, and scroll down to the **Build settings** section. When you select a framework from the dropdown, Appwrite will show instructions for SSR configuration, specific to your chosen stack.

That way, you'll know exactly what needs to change and how.

# Final thoughts

Hosting SSR apps with Appwrite Sites gives you a lot of flexibility. You get a Git-powered workflow, server runtime support, and integration with frameworks you already use. But more importantly, you're in control. You're not locked into a proprietary environment, and you can configure the exact build behavior you need.

If you're switching from Vercel, just remember to revisit your framework's adapter and build setup. Most of the time, it's just a matter of switching to a Node.js adapter and updating a couple of config files.

Once you've set that up, Appwrite Sites takes care of the rest, from cloning your repo to deploying server-rendered content.

Try it out, and let us know how it works for your stack.

# More resources
- [Appwrite Sites docs](/docs/products/sites)
- [Building with Sites templates](/blog/post/building-with-sites-templates)
- [SSR vs CSR with Next.js](/blog/post/csr-vs-ssr-with-nextjs)
- [Appwrite SSR Authentication](https://www.youtube.com/watch?v=7LN05c-ov_0)
- [Here's how you do auth with 100% SSR in Next.js](https://www.youtube.com/watch?v=ENnG7GusuO4)
- [Appwrite Discord server](/discord)

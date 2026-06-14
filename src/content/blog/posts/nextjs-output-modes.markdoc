---
layout: post
title: "Next.js output modes: Standalone vs Default build"
description: Learn about different output modes in Next.js and find out which of them would be the best for you.
date: 2025-12-12
cover: /images/blog/nextjs-output-modes/cover.avif
timeToRead: 5
author: atharva
category: tutorial
featured: false
faqs:
  - question: "What are Next.js output modes?"
    answer: "Next.js output modes control how your app is bundled and served. The main options are default (relies on node_modules and .next at runtime), standalone (bundles only required files into a self contained output), and export (produces a static site). Each mode has different tradeoffs around size, performance, and compatibility."
  - question: "What is Next.js standalone mode?"
    answer: "Standalone mode produces a self contained build under .next/standalone that includes only the dependencies your app actually uses. It does not require node_modules at runtime, which makes deployments smaller and cold starts faster. You opt in by setting output: 'standalone' in next.config.js."
  - question: "When should I use standalone mode over default?"
    answer: "Use standalone mode when build size and cold start time matter, which is most production deployments. It is especially useful when shipping containers or running on platforms that bill by deployment size. Stick with default mode if you rely on dynamic imports or monorepo patterns that the tracer cannot resolve."
  - question: "Does Appwrite Sites support Next.js standalone mode?"
    answer: "Yes, [Appwrite Sites](/docs/products/sites) fully supports Next.js standalone mode. Add output: 'standalone' to next.config.js and deploy as usual. Appwrite Sites detects the standalone build automatically, no extra configuration required."
  - question: "What is the difference between standalone and export modes?"
    answer: "Standalone mode produces a self contained server bundle that still runs Node.js at runtime, so SSR, API routes, and middleware all work. Export mode produces purely static HTML and assets with no server logic, suitable for sites without dynamic features. Pick standalone for full Next.js capabilities and export for fully static sites."
  - question: "Why is standalone mode not the Next.js default?"
    answer: "Standalone relies on static analysis to trace which files are needed, so dynamic imports, monorepo packages, or unusual runtime patterns can break the build. These edge cases mean Vercel and the Next.js team keep default mode as the safest option. Try standalone first and fall back to default if you hit a tracing issue."
---

Next.js is a very popular React framework. People choose it because it blends in with the server very well, using both client-side and server-side capabilities to provide a better user experience. Next.js is packed with cutting-edge React features like RSC. However, such features also bring complexity when deploying your app.

Appwrite Sites enables you to deploy your Next.js app seamlessly without any hassle of configuration. Appwrite handles all the complexity involved in deploying such apps, so that you can focus on the core logic of your app.

However, we also allow you to fiddle with the behaviour of how deployments work with Appwrite Sites. Output modes allow you to decide how your Next.js app is bundled. Each mode has its own pros and cons.

In this article, we will talk about the different Next.js output modes, what would be better for you, and what we have observed when using different output modes with Appwrite Sites.

# The "default" mode

The default mode relies on your dependencies stored in your `node_modules` and the build bundle stored in `.next` folder after running `next build`. To run your app in this output mode, you should run `next start` to serve your app in production.

It's important to note that if you remove `node_modules`, which usually is the biggest chunk of your app, your app won't run anymore, as your builds also rely on `node_modules` to work properly. So, when counting the size of your app, you should also count in `node_modules` folder as well.

The default mode is typically deployed by running `next start`. This is convenient, but gives you less control over the underlying server process than approaches where you own the server entrypoint (e.g., injecting custom server logic/handlers as part of your runtime).

You might already think that shipping `node_modules` takes a big hit on storage, and while it's true, but not always a bad thing. We will later look at why.

# The "export" mode

Even though we won't be comparing this mode in our benchmarks later in the article, we will still talk about it.

The "export" mode is a mode that allows you to export your app as a static site. This mode is useful if you want to deploy your app as a static site. 

To use this mode, configure `output: 'export'` in `next.config.js` and run `next build`. This will create an `out` folder that you can serve with any static hosting provider.

# Standalone mode

When building your app in standalone mode, Next.js traces all the files that are required by your app from your `node_modules`. This means that only the functionality your app uses will be bundled into your Next.js build. In this case, you can exclude `node_modules` when calculating sizes, as the build doesn't rely on `node_modules` as it already bundles all necessary code.

This results in bigger `.next/standalone` folder, but we also have to factor in the fact that `node_modules` are essential for the default mode, and not the standalone mode.

The `.next/standalone` folder does not contain any static assets, and Next.js recommends serving them through a CDN. For a minimal setup, you can run a few commands to copy static assets into the standalone directory.

Even with all the benefits, there's a very good reason for this mode not being the default yet.

# Our observations

We prepared a [benchmark](https://github.com/appwrite-community/nextjs-outputs-bench) where we ran the `next build` command for four Next.js apps to check how large their build sizes were:

1. Default mode, no dependencies
2. Standalone mode, no dependencies
3. Default mode, very heavy dependencies
4. Standalone mode, very heavy dependencies

Then, we compared the sizes of these builds and observed the following:

```
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
  📈 Summary & Comparisons
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

  🔹 No Dependencies - Default vs Standalone
     Default:      431.42 MB (.next + node_modules)
     Standalone:   57.84 MB
     📉 Difference:  373.58 MB smaller

  🔹 Heavy Dependencies - Default vs Standalone
     Default:      712.48 MB (.next + node_modules)
     Standalone:   57.84 MB
     📉 Difference:  654.64 MB smaller

  🔹 Impact of Heavy Dependencies
     Default mode:    +281.06 MB (65.1% increase)
     Standalone mode: +0 KB (0.0% increase)
```

The result was clear: standalone mode consistently showed lower build sizes. Interestingly, even when we installed heavy dependencies in the app, we observed that the build size for the apps with standalone mode remained unchanged. Because those dependencies were not being imported and used in the app, they were not bundled, which is ideal. Lower build sizes also result in faster cold starts for your app, which creates a better user experience.

# Which is the best for me?

Standalone mode seems to be the clear winner based on the results. That would make you think: why is it not the default? Because it's not the most reliable. There are some very unclear cases where your standalone build would break or cause problems in production:

- If you use monorepos and import packages from it, you might need to configure Next.js to be able to trace those packages.
- If you dynamically import packages in your app, standalone mode cannot help you here, as only the code that is used and can be traced is bundled.
- There could be many weird edge cases here and there.

**So what's the best for you?** Try out standalone. If something goes wrong, you can switch back to default mode. If you're building a very basic Next.js app, you will not go wrong with standalone mode.

# Wrapping up

Next.js standalone output is very powerful and could help you a lot when deploying your app. Standalone mode is fully supported on Appwrite Sites, so you can reap the benefits of it now!

- [Next.js standalone builds now supported on Appwrite Sites](/blog/post/nextjs-standalone-support-in-appwrite-sites)
- [Everything new in Next.js 16](/blog/post/everything-new-in-nextjs16)

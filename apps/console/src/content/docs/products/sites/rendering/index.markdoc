---
layout: article
title: Rendering
description: Explore how sites are rendered on Appwrite Sites.
---

Rendering refers to how your web application's content is processed and delivered to users. Appwrite Sites supports two primary rendering strategies, each with its own advantages and use cases. Understanding these strategies will help you choose the right approach for your project and optimize for performance, SEO, and user experience.

{% cards %}

{% cards_item href="/docs/products/sites/rendering/static" title="Static" %}
Host a static site or SPA
{% /cards_item %}

{% cards_item href="/docs/products/sites/rendering/ssr" title="Server Side Rendering" %}
Host an SSR site
{% /cards_item %}

{% /cards %}

# Differences

There are several differences between how static hosting and SSR work on Appwrite Sites.

| Static/SPA/PWA | SSR |
|---|---|
| Pages are rendered at build time only | Pages are rendered every time a request is made to the server |
| All `console.log` and `console.error` outputs at run-time are be displayed in the browser console | `console.log` and `console.error` outputs on server-side functions in the web app will be displayed in the response logs on Appwrite |
| The default `404` error page is Appwrite-branded (can be updated) | The default `404` error page is taken from the framework |
| Faster cold starts | Slower cold starts |
| Can access environment variables only during build-time | Can access environment variables during build-time and run-time |
| Supported for all frameworks | Limited support for certain frameworks (learn more on [Frameworks page](/docs/products/sites/frameworks)) |

# Choosing the right approach

When deciding between static and SSR for your Appwrite Sites project, consider these factors:

## Use static hosting when:
- Your content doesn't change frequently
- You want maximum performance with minimal server load
- Your site is primarily client-side with limited data requirements
- SEO is important but your content is relatively stable
- You need compatibility with any web framework

## Use SSR when:
- Your content changes frequently or is user-specific
- You need server-side access to data before rendering
- SEO is critical for dynamic, frequently changing content
- You want to reduce client-side JavaScript load
- You're using a supported SSR framework (Next.js, Nuxt, etc.)

Many modern applications use a hybrid approach, leveraging static generation for stable content and SSR for dynamic pages. Some frameworks (like Next.js, Nuxt, and SvelteKit support) both approaches within the same application. The SSR rendering strategy in Appwrite Sites supports such applications.
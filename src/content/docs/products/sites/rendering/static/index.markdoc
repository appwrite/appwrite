---
layout: article
title: Static
description: Learn how to host static web apps on Appwrite Sites.
---

Static apps, also known as static websites, consist of pre-built HTML, CSS, and JavaScript files that are served to users without any backend processing. These apps do not execute server-side code on each request, meaning the content remains the same until manually updated or rebuilt. 

Since the pages are pre-generated, static apps offer incredibly fast load times. However, they lack dynamic interactivity and are best suited for use cases like personal portfolios, documentation sites, and landing pages.

All static content served from Appwrite's [CDN](/docs/products/network/cdn) is optimized using advanced compression algorithms to reduce data transfer sizes and then served from your user's nearest edge location.

# Configuring your Appwrite Site to use static hosting

When Appwrite builds your site for the first time, it scans your project's configuration files to determine whether the website should be rendered as static pages or using SSR.

If you need to manually update these settings, here are the steps to do so:

1. Navigate to your site in the Appwrite Console and head to the **Settings** tab > **Build settings** section
2. Select the **Static site** checkbox (you may need to update your project codebase depending on your framework option)
3. Confirm that the appropriate install command, build command, and output directory are set
4. Click on the **Update** button and redeploy your site

{% only_dark %}
![Rendering strategy](/images/docs/sites/dark/build-settings-rendering-static.avif)
{% /only_dark %}
{% only_light %}
![Rendering strategy](/images/docs/sites/build-settings-rendering-static.avif)
{% /only_light %}

## Enabling static builds on your web app {% #enabling-static-builds %}

To enable static builds for your web app, you may need to make some additional updates in case of the following frameworks:

{% tabs %}

{% tabsitem #analog title="Analog" %}
Set `static: true` in `analog` plugin in the `vite.config.ts` file.
{% /tabsitem %}

{% tabsitem #nextjs title="Next.js" %}
Set `output: export` in the `next.config.js`.
{% /tabsitem %}

{% tabsitem #nuxt title="Nuxt" %}
Set build command to `npm run generate` in site settings.
{% /tabsitem %}

{% tabsitem #sveltekit title="SvelteKit" %}
Use `@sveltejs/adapter-static` adapter in the `svelte.config.js` file.
{% /tabsitem %}

{% tabsitem #astro title="Astro" %}
Ensure you don't set `adapter` in the `astro.config.mjs` file.
{% /tabsitem %}

{% tabsitem #remix title="Remix" %}
Set `ssr: false` in `remix` plugin in the `vite.config.ts` file.
{% /tabsitem %}

{% tabsitem #tanstack-start title="TanStack Start" %}
Add [`prerender` option](https://tanstack.com/start/latest/docs/framework/react/guide/static-prerendering#prerendering) with the `enabled: true` option set in the `tanstackStart` configuration in the `vite.config.ts` file.
{% /tabsitem %}

{% /tabs %}

# Running SPAs on Appwrite Sites

Single Page Applications (or SPAs) are web applications that load a single HTML page and dynamically update content using JavaScript, typically leveraging frameworks like React, Vue, or Angular. Unlike traditional websites, SPAs do not require full-page reloads; instead, they use client-side routing to modify the URL and fetch new content asynchronously from APIs. This results in a smoother, more app-like user experience.

Appwrite Sites allows hosting SPAs through the static hosting method. Instead of returning all pre-rendered HTML pages, it allows your client to download relevant JavaScript files, which can then run in the browser.

## Configuring an Appwrite Site to run as an SPA

To configure an Appwrite Site to run as an SPA, you must do the following:

1. Navigate to your site in the Appwrite Console and head to the **Settings** tab > **Build settings** section
2. Select the **Static site** checkbox (you may need to update your project codebase depending on your framework option)
3. Confirm that the appropriate install command, build command, and output directory are set
4. Provide a fallback file for advanced routing and proper page handling.
5. Click on the **Update** button and redeploy your site.
---
layout: post
title: "Announcing Appwrite Sites: The open source Vercel alternative"
description: This is Appwrite's biggest release to date, and it will change the way you build with Appwrite forever. Develop, deploy, and scale your applications directly from Appwrite. Your all-in-one cloud platform.
date: 2025-05-19
cover: /images/blog/sites-announcement/sites-cover.avif
timeToRead: 6
author: matej-baco
category: product,init
featured: false
callToAction: true
faqs:
  - question: "What is Appwrite Sites?"
    answer: "[Appwrite Sites](/docs/products/sites) is a hosting product that lets you deploy static sites and server-rendered web apps directly from your Appwrite project. It supports static hosting, SSR, Git-based deployments, preview URLs per pull request, a global CDN, and the [Appwrite Network](/docs/products/network) for low-latency delivery."
  - question: "Which frameworks does Appwrite Sites support?"
    answer: "Sites supports static output from any toolchain and SSR for frameworks including Next.js, Nuxt, SvelteKit, Astro, Remix, Vue.js, React, Angular, Flutter web, and vanilla JavaScript. Quick start guides exist for the most common ones in the [Sites documentation](/docs/products/sites)."
  - question: "How do I deploy a site from a GitHub repository?"
    answer: "Open the **Sites** section of the Appwrite Console, click **Create site**, and connect your GitHub repository. Pick the framework, confirm the install and build commands, add any environment variables, and Appwrite will build and deploy on every push. Pull requests automatically get [preview deployments](/docs/products/sites/deploy-from-git)."
  - question: "Is Appwrite Sites really open source?"
    answer: "Yes. Sites ships as part of the open source Appwrite Server, so you can self-host it alongside the rest of Appwrite. The [Appwrite repository](https://github.com/appwrite/appwrite) contains the code, and the [self-hosting documentation](/docs/advanced/self-hosting) covers running the full platform yourself."
  - question: "Can I use a custom domain with Appwrite Sites?"
    answer: "Yes. You can attach a custom domain and manage DNS through [Appwrite DNS](/docs/products/network/dns) using the `appwrite.zone` nameservers, or point your existing DNS provider at Appwrite. The Console walks you through DNS records and SSL is provisioned automatically."
  - question: "How does Appwrite Sites compare to Vercel or Netlify?"
    answer: "Sites covers the same core use cases (Git-based deploys, previews, SSR, CDN), but it lives inside the same platform as your backend, so [Auth](/docs/products/auth), [Databases](/docs/products/databases), [Storage](/docs/products/storage), and [Functions](/docs/products/functions) work without extra services. It is also fully self-hostable, unlike Vercel and Netlify."
---

You love using Appwrite to power your backend, but when it's time to actually *ship* your website, you're bouncing between tools, platforms, and extra accounts. That ends today.

Introducing **Appwrite Sites**.

A new Appwrite product that lets you deploy and host your websites and web apps *right inside Appwrite*. No more juggling services. No more gluing things together. No more multiple subscriptions. Just build, deploy, and go live. All in one place, and it's 100% open source, the kind that lets you (really) self-host and (really) own your data.

# The all-in-one cloud platform

Appwrite has always been about giving you the tools you need to build fast, secure, and modern apps. However, while Appwrite has always worked hard to deliver a great backend experience, one big piece was missing: web hosting.

Until now, you had to rely on external platforms like Vercel or Netlify to get your web app live. That meant extra configs, more integrations, and one more invoice to worry about. With Sites, that gap is gone.

The best part: **Appwrite is a fully open-source platform to offer both frontend hosting and your entire backend. All under one roof.** From static sites and SSR apps to databases, authentication, storage, messaging and serverless functions, you can now build, deploy, and scale your entire app stack using just Appwrite.

![Sites on console](/images/blog/sites-announcement/sites-console.avif)

# Important Sites features

Building Sites as part of the Appwrite ecosystem was a deliberate choice to deliver a seamless experience from crafting your backend to deploying your web apps and websites. To ensure your usage of the platform feels consistent and robust, we set out to match the high standards of other Appwrite products when developing Sites over the past year.

We've introduced several critical features to elevate the hosting experience. Helping you scale efficiently, keep data secure, and deliver lightning-fast performance.

## What's part of Sites?

- **Static hosting**: Ideal for single-page applications (SPAs), landing pages, documentation sites, and any project that compiles down to static files.
- **Server-side rendering (SSR)**: Full support for frameworks like Flutter, React, Next.js, Nuxt, SvelteKit, Astro, Remix, and more right out of the box.
- **[Git integrations](/docs/products/sites/deploy-from-git)**: Connect your GitHub repository to enable automatic deployments on every push.
- **Deployment previews**: Get a unique preview URL for each pull request, then review, test, and merge with confidence.
- **Global CDN**: Distribute your content worldwide with a powerful content delivery network to ensure low-latency access from anywhere.
- **DDoS protection**: Built-in protection mechanisms to help safeguard your apps from denial-of-service attacks.
- **[The Appwrite Network](/docs/products/network)**: Take advantage of a growing number of cloud regions, Points of Presence (PoPs), and edge network capabilities, reducing latency and enhancing performance globally.
- **[The Appwrite DNS](/docs/products/network/dns):** Appwrite provides a dedicated DNS (Domain Name System) service through its `appwrite.zone` nameservers to help you manage domain records for your applications.

All this is managed from your Appwrite Console or CLI, and deployable in both [**Cloud**](https://cloud.appwrite.io/) and **self-hosted** environments.

# Sites templates: One-Click websites

![site-templates](/images/blog/sites-announcement/sites-templates-add.avif)

To make building your website even easier, we created ready-to-use customizable templates that you can deploy with one click, directly from Appwrite. Because not every project needs custom design, and not every team has the time to build from scratch.

We partnered with open-source maintainers like Docusaurus, ReactAdmin, and many more to bring you diverse templates. We will continue to add more templates to ensure we have everything you need.

From polished landing pages to waitlist forms and simple promo sites, Sites templates let you go live faster. Read more on how to get started with [Sites templates in our docs](/docs/products/sites/templates).

# How to get started with Sites

![create-first-site](/images/blog/sites-announcement/create-first-site.avif)

## Cloud

Getting started with Sites Templates takes just a few clicks:

1. In the Appwrite Console's sidebar, click **Sites**.
2. Click on the **Create site** button.
3. After clicking on **Connect Git repository**, select your repository.
4. After connecting to GitHub, (optionally) add a name and site ID.
5. Verify that the correct framework is selected.
6. Confirm the install command, build command, and output directory in the build settings. To learn more, visit your preferred [framework quick-start](/docs/products/sites/quick-start#framework-quick-starts).
7. Add any environment variables required by the site.
8. The site will be created, and a build will begin. Once the build is completed, you'll have created your first site. You can use your site's **domain** to access the deployment.

We have added quick starts for popular frameworks to help you set up faster with your preferred framework, with more to come. As of today you can follow quick starts for [Flutter](/docs/products/sites/quick-start/flutter), [Nuxt](/docs/products/sites/quick-start/sveltekit), [Next.js](/docs/products/sites/quick-start/nextjs), Angular, [SvelteKit](/docs/products/sites/quick-start/sveltekit), [Remix](/docs/products/sites/quick-start/remix), [Astro](/docs/products/sites/quick-start/sveltekit), [Vue.js](/docs/products/sites/quick-start/sveltekit), [React](/docs/products/sites/quick-start/sveltekit), and [Vanilla.JS](/docs/products/sites/quick-start/sveltekit).

## Self-hosted

If you prefer to use the open-source version of Appwrite Sites, you can visit the [self-hosting documentation](https://appwrite.io/docs/advanced/self-hosting) and review the [Appwrite repository](https://github.com/appwrite/appwrite).

# Appwrite Sites pricing

Appwrite Sites is free to use until August 1st, 2025. We will inform you before introducing pricing so that you know well beforehand and have no surprises.

# One platform to build, host, scale

Like many developer tools, we are here to make you more productive. By bringing hosting into Appwrite, you spend less time on setup and more time on what matters: building. Fewer moving parts means fewer things to break, and everything works seamlessly with your existing Appwrite services like Databases, Functions, Storage, and Auth. It's Appwrite's goal to improve your time to production. We make you move faster with [Sites](/products/sites).

Sites will be available on both **Appwrite Cloud** and **self-hosted** deployments.

Spin up your first site from the Console or [CLI](/docs/tooling/command-line/sites) and go live in minutes. No more stitching platforms together, no more waiting for deploys, just fast, integrated shipping.

# More resources

- [Appwrite Sites docs](/docs/products/sites)
- [Appwrite compared to Vercel](/blog/post/open-source-vercel-alternative)
- [Appwrite Sites product tour](https://youtu.be/VtDe6hDw91k)
- [Appwrite Sites video announcement](https://youtu.be/0cERQxFjTW4)
- [Appwrite Discord server](/discord)

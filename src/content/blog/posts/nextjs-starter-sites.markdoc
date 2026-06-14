---
layout: post
title: How to setup the Next.js starter template on Appwrite Sites
description: Learn the process of deploying the Next.js starter template on Appwrite Sites.
date: 2025-05-20
cover: /images/blog/nextjs-starter-sites/cover.avif
timeToRead: 5
author: aditya-oberai
category: tutorial
featured: false
callToAction: true
faqs:
  - question: "How do I deploy a Next.js app to Appwrite Sites?"
    answer: "Create a project in Appwrite Cloud, open the Sites page, click Create site, and choose Clone a template. Search for the Next.js starter, connect your GitHub repository if you want continuous deployment, and click Deploy. See the [Appwrite Sites docs](/docs/products/sites) for additional deployment options."
  - question: "What does the Appwrite Next.js starter template include?"
    answer: "The starter ships with a clean single page UI, an integration with the Appwrite SDK, and preset deployment settings tuned for SSR on Appwrite Sites. It is intended as a baseline you can extend for your own app. You get a working deployment in minutes without writing configuration files."
  - question: "Can I deploy Appwrite Sites from the CLI?"
    answer: "Yes, run appwrite init sites to scaffold a new site interactively, picking Next.js as the framework. Then run appwrite push sites to deploy. The [CLI deploy docs](/docs/products/sites/quick-start/nextjs) explain the available flags and configuration options."
  - question: "What frameworks have Appwrite Sites starter templates?"
    answer: "Appwrite Sites offers starter templates for Next.js, React, Vue, Nuxt, Angular, SvelteKit, and Flutter. You can browse and clone any of them from the templates section in the Sites Console. New templates are added over time, so check the listing for the latest options."
  - question: "Do I need to self host Appwrite to use Sites?"
    answer: "No, [Appwrite Sites](/docs/products/sites) is available on Appwrite Cloud. You can also self host Appwrite 1.7 or later if you prefer to run the entire platform yourself. Both options support the same site deployment workflow."
  - question: "How do I connect a custom domain to my Appwrite Site?"
    answer: "From the deployed site dashboard, open the Domains tab and add your domain. Appwrite gives you DNS records to point your domain to the platform. Once DNS propagates, your site serves traffic on the custom domain alongside the default Appwrite subdomain."
---

Building web applications requires both front-end expertise and back-end infrastructure. Appwrite Sites simplifies this process by providing a platform for deploying, hosting, and scaling web applications.

To ease this process even further, Appwrite Sites offers a variety of starter kits for popular frameworks like Next.js, React, Vue, Nuxt, Angular, SvelteKit, and Flutter. In this blog, you will learn how to set up the Next.js starter template and deploy it to [Appwrite Sites](/products/sites).

# Overview of the starter template

Next.js is a React framework that enables developers to build fast, scalable web applications with features like server-side rendering, static site generation, and API routes out of the box.

Appwrite's Next.js starter template includes:

- A clean, single-page UI
- Integration with Appwrite's SDK
- Pre-configured deployment settings for Appwrite Sites' SSR rendering strategy

![Deployed app](/images/blog/nextjs-starter-sites/deployed.avif)

# Deploy the starter template on Appwrite

Firstly, you must head to Appwrite Cloud and [create an account](https://cloud.appwrite.io/console/register) if you haven't already (or [self-host Appwrite 1.7](/docs/advanced/self-hosting)). Next, create your first project, which will lead you to the project overview page.

![Add platform](/images/blog/nextjs-starter-sites/add-platform.avif)

Head to the **Sites** page from the left sidebar, click on the **Create site** button, and select the **Clone a template** option. This will take you to the Appwrite Sites templates listing, where you should search `Next.js starter` and click on the template.

![Starter template](/images/blog/nextjs-starter-sites/template.avif)

After selecting the template, you can choose to connect a GitHub repository now or at a later time. If you choose to connect a repository, ensure you select a production branch (leave the root directory as is). Then, review the preset environment variables, update the domain name if you want, and click on the **Deploy** button. You can watch the deployment logs as the site is built.

![Deployment logs](/images/blog/nextjs-starter-sites/deployment-logs.avif)

{% info title="Alternative method to deploy starter template" %}

As an alternative to the Appwrite console, you can create and deploy websites using the [Appwrite CLI](/docs/products/sites/deploy-manually#cli). Create your Next.js starter using the following shell command and configuration:

```bash
appwrite init sites
? What would you like to name your site? Next.js starter
? What ID would you like to have for your site? unique()
? What framework would you like to use? Next.js (nextjs)
? What specification would you like to use? 0.5 CPU, 512MB RAM
```

You can then make any edits to the website and deploy it using the following command:

```bash
appwrite push sites
```

{% /info %}

# Test the starter template

After your site has been successfully deployed, Appwrite will show you a **Congratulations** page. You can then either choose to view the site by clicking on the **Visit site** button or view the site configuration (deployments, logs, domains, usage, and settings) by clicking on the **Go to dashboard** button.

![Congratulations](/images/blog/nextjs-starter-sites/congrats.avif)

# Next steps

And with that, the Next.js starter kit is deployed to Appwrite Sites. You can explore other templates or deploy any other websites you'd like. 

For more information about Appwrite Sites:

- [Appwrite Sites product docs](/docs/products/sites)
- [Quick start to deploy any Next.js app](/docs/products/sites/quick-start/nextjs)
- [Appwrite Discord server](/discord)

---
layout: post
title: How to setup the SvelteKit starter template on Appwrite Sites
description: Learn the process of deploying the SvelteKit starter template on Appwrite Sites.
date: 2025-05-20
cover: /images/blog/sveltekit-starter-sites/cover.avif
timeToRead: 5
author: aditya-oberai
category: tutorial
featured: false
callToAction: true
faqs:
  - question: "What is Appwrite Sites?"
    answer: "[Appwrite Sites](/docs/products/sites) is a platform for deploying, hosting, and scaling web applications directly inside Appwrite. It supports popular frameworks like SvelteKit, Next.js, Nuxt, React, Vue, Angular, and Flutter, with both SSR and static rendering options."
  - question: "How do I deploy a SvelteKit app to Appwrite Sites?"
    answer: "Go to the Sites page in your Appwrite project, click Create site, and choose the Svelte starter template or connect your own GitHub repo. Pick a production branch, review environment variables, and click Deploy. You can also use the Appwrite CLI with `appwrite init sites` and `appwrite push sites`."
  - question: "What is included in the SvelteKit starter template?"
    answer: "The starter ships with a clean single-page UI, the Appwrite SDK pre-installed, and deployment settings tuned for SvelteKit's SSR rendering strategy on Appwrite Sites. It is a working baseline you can extend with your own routes and data."
  - question: "Can I deploy a SvelteKit app to Appwrite Sites using the CLI?"
    answer: "Yes. Run `appwrite init sites` to scaffold a project, pick SvelteKit as the framework, and choose your specification. Once you have made changes, run `appwrite push sites` to deploy. The CLI workflow is documented in the [deploy manually](/docs/products/sites/deploy-manually) guide."
  - question: "Do I need to self-host Appwrite to use Sites?"
    answer: "No. You can use Appwrite Sites on Appwrite Cloud after creating a free account, or you can self-host Appwrite 1.7 or later. Both paths support the same starter templates and deployment flows."
  - question: "What is SvelteKit?"
    answer: "SvelteKit is a web framework built on top of Svelte that supports server-rendered and client-enhanced apps. It includes routing, data loading, and adapters for different deployment targets, making it well suited for full stack apps deployed to platforms like Appwrite Sites."
---

Building web applications requires both front-end expertise and back-end infrastructure. [Appwrite Sites](/products/sites) simplifies this process by providing a platform for deploying, hosting, and scaling web applications.

To ease this process even further, Appwrite Sites offers a variety of starter kits for popular frameworks like Next.js, React, Vue, Nuxt, Angular, SvelteKit, and Flutter. In this blog, you will learn how to set up the SvelteKit starter template and deploy it to Appwrite Sites.

# Overview of the starter template

[SvelteKit](https://kit.svelte.dev/) is a modern web framework built on top of Svelte that enables fast, server-rendered and client-enhanced web applications with powerful routing, data loading, and deployment features out of the box.

Appwrite's SvelteKit starter template includes:

- A clean, single-page UI
- Integration with Appwrite's SDK
- Pre-configured deployment settings for Appwrite Sites' SSR rendering strategy

![Deployed app](/images/blog/sveltekit-starter-sites/deployed.avif)

# Deploy the starter template on Appwrite

Firstly, you must head to Appwrite Cloud and [create an account](https://cloud.appwrite.io/console/register) if you haven't already (or [self-host Appwrite 1.7](/docs/advanced/self-hosting)). Next, create your first project, which will lead you to the project overview page.

![Add platform](/images/blog/sveltekit-starter-sites/add-platform.avif)

Head to the **Sites** page from the left sidebar, click on the **Create site** button, and select the **Clone a template** option. This will take you to the Appwrite Sites templates listing, where you should search `Svelte starter` and click on the template.

![Starter template](/images/blog/sveltekit-starter-sites/template.avif)

After selecting the template, you can choose to connect a GitHub repository now or at a later time. If you choose to connect a repository, ensure you select a production branch (leave the root directory as is). Then, review the preset environment variables, update the domain name if you want, and click on the **Deploy** button. You can watch the deployment logs as the site is built.

![Deployment logs](/images/blog/sveltekit-starter-sites/deployment-logs.avif)

{% info title="Alternative method to deploy starter template" %}

As an alternative to the Appwrite console, you can create and deploy websites using the [Appwrite CLI](/docs/products/sites/deploy-manually#cli). Create your SvelteKit starter using the following shell command and configuration:

```bash
appwrite init sites
? What would you like to name your site? Svelte starter
? What ID would you like to have for your site? unique()
? What framework would you like to use? SvelteKit (sveltekit)
? What specification would you like to use? 0.5 CPU, 512MB RAM
```

You can then make any edits to the website and deploy it using the following command:

```bash
appwrite push sites
```

{% /info %}

# Test the starter template

After your site has been successfully deployed, Appwrite will show you a **Congratulations** page. You can then either choose to view the site by clicking on the **Visit site** button or view the site configuration (deployments, logs, domains, usage, and settings) by clicking on the **Go to dashboard** button.

![Congratulations](/images/blog/sveltekit-starter-sites/congrats.avif)

# Next steps

And with that, the SvelteKit starter kit is deployed to Appwrite Sites. You can explore other templates or deploy any other websites you'd like. 

For more information about Appwrite Sites:

- [Appwrite Sites product docs](/docs/products/sites)
- [Quick start to deploy any SvelteKit app](/docs/products/sites/quick-start/sveltekit)
- [Appwrite Discord server](/discord)

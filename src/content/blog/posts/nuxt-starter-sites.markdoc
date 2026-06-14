---
layout: post
title: How to setup the Nuxt starter template on Appwrite Sites
description: Learn the process of deploying the Nuxt starter template on Appwrite Sites.
date: 2025-05-20
cover: /images/blog/nuxt-starter-sites/cover.avif
timeToRead: 5
author: aditya-oberai
category: tutorial
featured: false
callToAction: true
faqs:
  - question: "What is the Nuxt starter template for Appwrite Sites?"
    answer: "The Nuxt starter template is a pre-configured Nuxt project that already includes the Appwrite SDK and deployment settings for [Appwrite Sites](/docs/products/sites). It gives you a working SSR Nuxt app in a few clicks, so you can focus on your features instead of wiring up boilerplate."
  - question: "Does Appwrite Sites support SSR for Nuxt?"
    answer: "Yes. Appwrite Sites supports both static and SSR rendering for Nuxt apps. The starter template is configured for SSR by default, but you can switch to static if your app does not need server rendering."
  - question: "Can I deploy the Nuxt starter without using the console?"
    answer: "Yes. You can deploy with the Appwrite CLI using `appwrite init sites` and `appwrite push sites`. The CLI is the option to use if you prefer scripted workflows or want to integrate deploys into CI/CD."
  - question: "Do I need to connect a GitHub repository to deploy?"
    answer: "No, connecting a Git repository is optional. You can skip it during template setup and deploy manually, or connect a repo later to get automatic deployments on every push to your production branch."
  - question: "Can I self-host Appwrite to use Sites?"
    answer: "Yes. Appwrite Sites is available on Appwrite Cloud and on self-hosted Appwrite 1.7 and above. Both routes give you the same template catalog and deployment workflows."
  - question: "What other starter templates does Appwrite Sites offer?"
    answer: "Appwrite Sites includes starter templates for Next.js, React, Vue, Nuxt, Angular, SvelteKit, and Flutter. You can browse the full list from the Create site flow inside your Appwrite project."
---

Building web applications requires both front-end expertise and back-end infrastructure. [Appwrite Sites](/products/sites) simplifies this process by providing a platform for deploying, hosting, and scaling web applications.

To ease this process even further, Appwrite Sites offers a variety of starter kits for popular frameworks like Next.js, React, Vue, Nuxt, Angular, SvelteKit, and Flutter. In this blog, you will learn how to set up the Nuxt starter template and deploy it to Appwrite Sites.

# Overview of the starter template

Nuxt is a framework built on top of Vue.js that makes it easy to develop server-rendered, statically generated, or single-page applications with features like routing, SEO optimization, and modular architecture out of the box.

Appwrite's Nuxt starter template includes:

- A clean, single-page UI
- Integration with Appwrite's SDK
- Pre-configured deployment settings for Appwrite Sites' SSR rendering strategy

![Deployed app](/images/blog/nuxt-starter-sites/deployed.avif)

# Deploy the starter template on Appwrite

Firstly, you must head to Appwrite Cloud and [create an account](https://cloud.appwrite.io/console/register) if you haven't already (or [self-host Appwrite 1.7](/docs/advanced/self-hosting)). Next, create your first project, which will lead you to the project overview page.

![Add platform](/images/blog/nuxt-starter-sites/add-platform.avif)

Head to the **Sites** page from the left sidebar, click on the **Create site** button, and select the **Clone a template** option. This will take you to the Appwrite Sites templates listing, where you should search `Nuxt starter` and click on the template.

![Starter template](/images/blog/nuxt-starter-sites/template.avif)

After selecting the template, you can choose to connect a GitHub repository now or at a later time. If you choose to connect a repository, ensure you select a production branch (leave the root directory as is). Then, review the preset environment variables, update the domain name if you want, and click on the **Deploy** button. You can watch the deployment logs as the site is built.

![Deployment logs](/images/blog/nuxt-starter-sites/deployment-logs.avif)

{% info title="Alternative method to deploy starter template" %}

As an alternative to the Appwrite console, you can create and deploy websites using the [Appwrite CLI](/docs/products/sites/deploy-manually#cli). Create your Nuxt starter using the following shell command and configuration:

```bash
appwrite init sites
? What would you like to name your site? Nuxt starter
? What ID would you like to have for your site? unique()
? What framework would you like to use? Nuxt (nuxt)
? What specification would you like to use? 0.5 CPU, 512MB RAM
```

You can then make any edits to the website and deploy it using the following command:

```bash
appwrite push sites
```

{% /info %}

# Test the starter template

After your site has been successfully deployed, Appwrite will show you a **Congratulations** page. You can then either choose to view the site by clicking on the **Visit site** button or view the site configuration (deployments, logs, domains, usage, and settings) by clicking on the **Go to dashboard** button.

![Congratulations](/images/blog/nuxt-starter-sites/congrats.avif)

# Next steps

And with that, the Nuxt starter kit is deployed to Appwrite Sites. You can explore other templates or deploy any other websites you'd like. 

For more information about Appwrite Sites:

- [Appwrite Sites product docs](/docs/products/sites)
- [Quick start to deploy any Nuxt app](/docs/products/sites/quick-start/nuxt)
- [Appwrite Discord server](/discord)

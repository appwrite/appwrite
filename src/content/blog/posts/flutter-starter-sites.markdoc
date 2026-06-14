---
layout: post
title: How to setup the Flutter starter template on Appwrite Sites
description: Learn the process of deploying the Flutter starter template on Appwrite Sites.
date: 2025-05-20
cover: /images/blog/flutter-starter-sites/cover.avif
timeToRead: 5
author: aditya-oberai
category: tutorial
featured: false
callToAction: true
faqs:
  - question: "Can I host Flutter Web on Appwrite Sites?"
    answer: "Yes, [Appwrite Sites](/docs/products/sites) supports Flutter Web natively, no custom configuration needed. The Flutter framework is detected automatically when you connect a Flutter repo, and Sites handles building and serving the compiled web bundle."
  - question: "What is the Flutter starter template for Appwrite Sites?"
    answer: "It is a ready-to-deploy Flutter Web project that ships with the Appwrite Dart SDK integrated and pre-configured build settings for Static rendering on Sites. Use it as a starting point and replace the demo UI with your own pages, routes, and Appwrite calls."
  - question: "How do I deploy the Flutter starter template?"
    answer: "In the Appwrite Console, go to Sites, click Create site, pick Clone a template, search for `Flutter starter`, and select it. You can optionally connect a GitHub repository, then review the environment variables, choose a domain, and click Deploy. The deployment logs stream live as the site builds."
  - question: "Can I deploy Flutter sites from the Appwrite CLI?"
    answer: "Yes. Run `appwrite init sites`, pick Flutter as the framework, and configure the resource specification. Then run `appwrite push sites` to deploy. The CLI route is useful for automation or when you do not want to leave the terminal. See the [CLI docs](/docs/products/sites/deploy-manually#cli) for full details."
  - question: "What other frameworks does Appwrite Sites support?"
    answer: "Appwrite Sites supports Next.js, React, Vue, Nuxt, Angular, SvelteKit, TanStack Start, Astro, Flutter Web, and more, with both static and SSR rendering strategies. The full quick-start list lives in the [Sites docs](/docs/products/sites/quick-start)."
  - question: "Do I need an Appwrite Cloud account to use Sites?"
    answer: "You can use Sites on [Appwrite Cloud](https://cloud.appwrite.io) for the easiest setup, or self-host Appwrite 1.7 or later and run Sites on your own infrastructure. Both routes give you the same templates, CLI tooling, and deployment workflow."
---

Most web hosting platforms don't support Flutter Web out of the box, often forcing developers to jump through hoops just to get their apps online. This lack of native support can make deploying Flutter Web projects unnecessarily complex and time-consuming.

Appwrite Sites changes that by offering built-in support for Flutter Web, making it easy to host and scale your applications. Alongside Flutter, Appwrite also provides starter kits for popular frameworks like Next.js, React, Vue, Nuxt, Angular, and SvelteKit. In this blog, you'll learn how to set up the Flutter starter template and deploy it to [Appwrite Sites](/products/sites).

# Overview of the starter template

Flutter Web is a part of the Flutter framework that allows developers to build responsive, high-performance web applications using a single Dart codebase.

Appwrite's Flutter starter template includes:

- A clean, single-page UI
- Integration with Appwrite's SDK
- Pre-configured deployment settings for Appwrite Sites' Static rendering strategy

![Deployed app](/images/blog/flutter-starter-sites/deployed.avif)

# Deploy the starter template on Appwrite

Firstly, you must head to Appwrite Cloud and [create an account](https://cloud.appwrite.io/console/register) if you haven't already (or [self-host Appwrite 1.7](https://appwrite.io/docs/advanced/self-hosting)). Next, create your first project, which will lead you to the project overview page.

![Add platform](/images/blog/flutter-starter-sites/add-platform.avif)

Head to the **Sites** page from the left sidebar, click on the **Create site** button, and select the **Clone a template** option. This will take you to the Appwrite Sites templates listing, where you should search `Flutter starter` and click on the template.

![Starter template](/images/blog/flutter-starter-sites/template.avif)

After selecting the template, you can choose to connect a GitHub repository now or at a later time. If you choose to connect a repository, ensure you select a production branch (leave the root directory as is). Then, review the preset environment variables, update the domain name if you want, and click on the **Deploy** button. You can watch the deployment logs as the site is built.

{% info title="Alternative method to deploy starter template" %}

As an alternative to the Appwrite console, you can create and deploy websites using the [Appwrite CLI](/docs/products/sites/deploy-manually#cli). Create your Flutter starter using the following shell command and configuration:

```bash
appwrite init sites
? What would you like to name your site? Flutter starter
? What ID would you like to have for your site? unique()
? What framework would you like to use? Flutter (flutter)
? What specification would you like to use? 0.5 CPU, 512MB RAM
```

You can then make any edits to the website and deploy it using the following command:

```bash
appwrite push sites
```

{% /info %}

# Test the starter template

After your site has been successfully deployed, Appwrite will show you a **Congratulations** page. You can then either choose to view the site by clicking on the **Visit site** button or view the site configuration (deployments, logs, domains, usage, and settings) by clicking on the **Go to dashboard** button.

![Congratulations](/images/blog/flutter-starter-sites/congrats.avif)

# Next steps

And with that, the Flutter starter kit is deployed to Appwrite Sites. You can explore other templates or deploy any other websites you'd like. 

For more information about Appwrite Sites:

- [Appwrite Sites product docs](/docs/products/sites)
- [Quick start to deploy any Flutter Web app](/docs/products/sites/quick-start/flutter)
- [Appwrite Discord server](/discord)

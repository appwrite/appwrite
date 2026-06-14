---
layout: post
title: "Building with Appwrite Sites templates"
description: To make it easier, and a lot faster to build a website, we have added templates. From e-commerce stores, to documentation, you can get started with a couple of clicks.
date: 2025-05-19
cover: /images/blog/sites-templates/templates-cover.avif
timeToRead: 9
author: ebenezer-don
category: tutorial
featured: false
callToAction: true
faqs:
  - question: "What is Appwrite Sites?"
    answer: "Appwrite Sites is a hosting product that deploys static and server-rendered websites directly from your Appwrite Console. It supports popular frameworks like Next.js, Svelte, Astro, and others, and handles version control, environment configuration, and global delivery. See [Appwrite Sites](/docs/products/sites) for the full feature list."
  - question: "How do Appwrite Sites templates work?"
    answer: "Templates are pre-built projects for common use cases like ecommerce, blogs, documentation, portfolios, and starters. You pick one in the Console, give it a site name and ID, and Appwrite clones the repo and deploys it. From there it behaves like any other site you connect to Appwrite, with full Git integration."
  - question: "Can I connect my own GitHub repository to Appwrite Sites?"
    answer: "Yes. Instead of cloning a template, you can choose Connect a repository when creating a site and link your GitHub account. Pushes to the connected branch trigger new deployments automatically. This works the same way as template-based sites once the repo is connected."
  - question: "What frameworks does Appwrite Sites support?"
    answer: "Appwrite Sites supports a growing list of frameworks including Next.js, SvelteKit, Astro, Nuxt, and other popular Node-based stacks. Each framework has its own build settings that Appwrite detects, but you can override them in the site configuration. Check [Appwrite Sites](/docs/products/sites) for the current list."
  - question: "Do I need a separate hosting provider if I use Appwrite Sites?"
    answer: "No. Appwrite Sites deploys to Appwrite's edge network, so your site is served globally without an external host. You can still use a custom domain by pointing DNS records at Appwrite, which keeps your branding intact."
  - question: "How do environment variables work for Appwrite Sites?"
    answer: "Each site has its own environment variables defined in the Console, separate from your project's Functions and other resources. Build-time variables are injected during deployment, and runtime variables are available to server-rendered routes. This is also the right place to put any third-party API keys your site depends on."
---

Your web application only provides value when it is live and accessible to users. Appwrite Sites simplifies deployment, allowing you to launch modern, production-ready websites directly from your Appwrite console. With built-in templates, you can quickly deploy complete websites that include integrated version control, environment configuration, and secure global delivery, all without complicated setup or infrastructure.

In this guide, we will walk through the process of deploying your website using one of Appwrite's pre-built templates. We'll build an ecommerce store for this example, but the process is the same for any other type of site.

# Opening the Sites page

After signing into your Appwrite console, look at the sidebar on the left. Under the **Deploy** section, you will find the **Sites** button.

Clicking it opens the Sites page. Here, you will see the **Sites** tab selected by default. Click **Create site** to start the deployment process.

![Sites Concole](/images/blog/sites-templates/sites-templates1.avif)

At this point, Appwrite offers two options: **Clone a template** or **Connect a repository**. For this guide, we will focus on cloning a template, which lets you quickly set up a site based on a pre-built project.

![Clone or connect Git](/images/blog/sites-templates/sites-templates2.avif)

# Choosing a template

When you select **Clone a template**, Appwrite displays a library of available templates. You can browse through them by scrolling, or you can narrow down the selection using the filters on the left side of the screen.

Templates can be sorted by framework. If you are looking for something specific, you can check one or more framework options like Next.js, Svelte, Astro, and others.

Templates can also be sorted by use case. The available categories include:

- Blog
- Documentation
- Ecommerce
- Events
- Portfolio
- Starter

You can also use the search bar above the list to find a template by name. For this walkthrough, we'll use the **Store template** under the Ecommerce category, so if you don't see it right away, you can search for it.

![Search templates](/images/blog/sites-templates/sites-templates3.avif)

Clicking the template will open a new page where you can configure your new site.

## Setting up site details

Once you have selected the template, you will be prompted to enter basic details for your site.

You will need to provide a **Site name** and a **Site ID**. The Site ID becomes part of your site's URL, so it should be lowercase and use hyphens if necessary.

![set details](/images/blog/sites-templates/sites-templates4.avif)

At this stage, you can choose whether to connect your repository now or later.

If you choose **Connect your repository**, you will be able to either create a new repository based on the template or link it to an existing one.
If you prefer to skip this step for now, you can choose **Connect later** and connect your version control later using the CLI or through the settings page.

If you opt to connect a repository immediately, you will see the following options:

- Select whether to create a new repository or connect to an existing one
- (Optional) Choose a GitHub organization if you have access to multiple organizations
- Name your repository if you are creating a new one
- Decide whether to keep the repository private by ticking the checkbox

Once your settings are ready, click **Create** to move to the next step.

# Configuring deployment settings

After creating your site, you will be taken to the deployment configuration screen.

Here, you will select your **Production branch**. This is the branch that Appwrite Sites will use for deployments.

You can optionally set a **Root directory** if your production build is inside a specific folder within your repository.

There is also a **Silent mode** option. If enabled, Appwrite will not automatically create comments when pushing changes to the connected repository.

Next, you will configure your **Environment variables**. Two variables will already be present:

- `PUBLIC_APPWRITE_ENDPOINT`
- `PUBLIC_APPWRITE_PROJECT_ID`

You can edit these values if needed, and you can add more environment variables if your application requires them.

Additionally, you can define the domain for your site, using the Appwrite Sites domain.

![Configure](/images/blog/sites-templates/sites-templates5.avif)

After reviewing your configuration, click **Deploy**.

# Monitoring the deployment

Once you initiate the deployment, you will be taken to a progress page where you can monitor the build and deployment in real time.

The logs will show you the different stages of the build process.
Depending on the size of your template and the dependencies involved, this process can take anywhere from a few seconds to a few minutes.

![Deploy](/images/blog/sites-templates/sites-templates6.avif)

When deployment is complete, Appwrite will display a success screen showing:

- A preview image of your live site
- The domain name
- The size of the deployment
- Global CDN status (should show **Connected**)
- DDoS protection status (should show **Connected**)

![Build](/images/blog/sites-templates/sites-templates7.avif)

You will also see a **Visit site** button that you can click to view your newly deployed website.

# After deployment: Next steps

After successfully deploying your site, Appwrite provides a few options to help you continue setting up or expanding your project.

You can **Add domain**, connecting an existing custom domain you own or registering a new one through the console.

You can **Share site**, allowing you to collaborate with your team by sharing access to your project.

You can also **Open on mobile**, previewing your live site directly from a mobile or tablet device to ensure everything looks and functions as expected across different screen sizes.

# Wrapping up

Deploying a site using templates on Appwrite Sites provides a straightforward path from project setup to a live deployment. By using a template, you can quickly spin up a professional-quality application without needing to configure hosting infrastructure manually. Connecting a repository, setting environment variables, and defining your site domain are all built directly into the console, allowing you to move from idea to launch with clarity.

Once your site is deployed, you can continue to customize it, link it to a custom domain, or expand it as your project grows. If you have any questions, don’t hesitate to reach out to us via [Discord](https://appwrite.io/discord).

# More resources

- [Appwrite Sites docs](/docs/products/sites)
- [Appwrite compared to Vercel](/blog/post/open-source-vercel-alternative)
- [Appwrite Sites product tour](https://youtu.be/VtDe6hDw91k)
- [Appwrite Sites video announcement](https://youtu.be/0cERQxFjTW4)
- [Appwrite Discord server](/discord)

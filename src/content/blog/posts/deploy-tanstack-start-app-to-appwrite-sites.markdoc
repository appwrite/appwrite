---
layout: post
title: Deploy a TanStack Start app to Appwrite Sites
description: Learn how to deploy a TanStack Start app to Appwrite Sites.
date: 2025-10-31
cover: /images/blog/deploy-tanstack-start-app-to-appwrite-sites/cover.avif
timeToRead: 5
author: atharva
category: tutorial
featured: false
faqs:
  - question: "Does Appwrite Sites support TanStack Start with SSR?"
    answer: "Yes, [Appwrite Sites](/docs/products/sites) officially supports TanStack Start with full server-side rendering. The framework is detected from your repository, so you can deploy directly from GitHub without custom build configuration."
  - question: "How do I structure environment variables for TanStack Start?"
    answer: "TanStack Start uses Vite, so any public environment variable must be prefixed with `VITE_` to be available in the browser. Keep API keys and secrets in unprefixed variables so they only live on the server side. Add them in the Environment variables section when creating your site."
  - question: "How do I deploy a TanStack Start app from a monorepo?"
    answer: "Set the root directory manually to the folder containing your TanStack Start config. For a Turborepo project the path is usually something like `./apps/web`. Once the root is correct, Sites picks up the rest of the build settings."
  - question: "What is TanStack Start and how does it compare to Next.js or Remix?"
    answer: "TanStack Start is a full-stack React framework built on top of TanStack Router, focused on type-safe routing, server functions, and SSR. Compared to Next.js or Remix, it leans harder on TanStack Query and Router primitives and gives you more direct control over server boundaries."
  - question: "Can I add a custom domain to my deployed TanStack Start site?"
    answer: "Yes. Deploy first using the Appwrite Network subdomain, then add a custom domain from the site settings. The full process is covered in the [custom domains guide](/blog/post/custom-domains-with-sites)."
  - question: "Why does my build fail locally but succeed on Appwrite Sites (or vice versa)?"
    answer: "The most common cause is a difference in Node.js version, environment variables, or lockfile state. Match the Node version Sites uses in the build logs, commit your lockfile, and double-check that every environment variable referenced in code is set on the site. If a local build fails, the deploy will fail too, so fix it locally first."
---

TanStack Start is growing in popularity and developers are choosing to use it more over other alternatives. To empower these developers, we just announced support for TanStack Start in Appwrite Sites.

In this article, you will learn how to deploy your TanStack Start app completely free of cost. We will use Appwrite Sites to achieve that. Appwrite is an all-in-one open-source BaaS (backend as a service) that handles the backend part of your application so that you can focus on the app's core logic rather than unnecessary technical details. 

Appwrite Sites is a frontend hosting service that can help you deploy your TanStack Start (or any web framework) app in minutes with full SSR (server-side rendering) functionality.

# Testing your TanStack Start build

Before you deploy your TanStack Start app, it's essential to see if it builds successfully. Building is a process where the framework bundles your app into files that can be run on a server and a browser, depending on your usage of SSR (server-side rendering).

To build your TanStack Start app, run the following command in your terminal:

```bash
npm run build
```

If your build succeeds, you can deploy your TanStack Start application to the internet. If your build fails, you must fix all the errors in your application. When deploying a TanStack Start app, the deployment provider builds your app on their servers, and cannot deploy if the build fails.

# Configuring your Site on Appwrite

This article assumes you have your TanStack Start app code stored in a GitHub repository. If not, please do so, as it is needed for deployment. Deploying through code stored on GitHub also introduces advantages such as automatic deployments, preview deployments, etc.

Now, let's link your GitHub repository to Appwrite Sites.

- Go to [Appwrite Console](https://cloud.appwrite.io) and sign in using your preferred authentication method.
- Create a new project, or use an existing one.
- Click on the **Sites** option on the sidebar.
- Click on **Create Site**.
- Click on **Connect a GitHub repository**.

Now, you should be able to see the most recent GitHub repositories based on activity.

![Selecting the repository](/images/blog/deploy-tanstack-start-app-to-appwrite-sites/repository-selection.avif)

Click **Connect** against the desired GitHub repository. The TanStack logo should be the icon of any valid TanStack Start app.

Once you connect your GitHub repository, you will be presented with options for deployment.

![Configuring the site](/images/blog/deploy-tanstack-start-app-to-appwrite-sites/configuring-site.avif)

Fill in the details as desired. Although there are some things you must know before moving forward.

- If you're using a different branch for your intended production website, choose the specific branch.
- If you're using a monorepo, Sites might not automatically detect your application. In such cases, manually provide the root directory for your TanStack Start app that contains the configuration file. For example, if you're using Turborepo, your app might be located inside one of the folders in the `apps` folder, so your root directory should be something like `./apps/web`.
- Most users don't need to change the **Build settings**, although if you want to run different commands for installation and builds, you can configure those in this section. If you don't know anything about this, it's worth skipping this section altogether.
- If your app uses environment variables, add them in the **Environment variables** section. If your app uses sensitive API keys, you are likely using environment variables.
    - In TanStack Start, public environment variables start with `VITE_`. These environment variables are accessible in the browser environment, so don't store your sensitive API keys in a variable that starts with that prefix.
- Configure an Appwrite Network subdomain to get started. Don't worry, you can configure a custom domain once your app is deployed.

Now, hit the **Deploy** button, and the deployment process will begin. You can see the build logs as your website gets deployed.

# Next steps

![Site successfully deployed](/images/blog/deploy-tanstack-start-app-to-appwrite-sites/deployed.avif)

Once your TanStack Start application is deployed, you can view it on the provided domains on the deployment success splash screen or in the Sites section of the Appwrite console.

Now, you can perform further steps to level up your experience with Appwrite Sites:

- [Custom domains with Appwrite Sites](/blog/post/custom-domains-with-sites)
- [How to host SSR web apps on Appwrite Sites](/blog/post/host-ssr-web-apps-sites)

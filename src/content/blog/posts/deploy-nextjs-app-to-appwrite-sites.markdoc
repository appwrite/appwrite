---
layout: post
title: Deploy a Next.js app to Appwrite Sites
description: Learn how to deploy a Next.js app to Appwrite Sites.
date: 2025-08-28
cover: /images/blog/deploy-nextjs-app-to-appwrite-sites/cover.avif
timeToRead: 5
author: atharva
category: tutorial
faqs:
  - question: "Does Appwrite Sites support Next.js SSR and React Server Components?"
    answer: "Yes, [Appwrite Sites](/docs/products/sites) supports the full Next.js feature set, including SSR and React Server Components, not just static export. You can deploy Next.js apps the same way you would on any modern host, with build settings detected automatically."
  - question: "What build and output settings does Next.js use on Appwrite Sites?"
    answer: "The default install command is `npm install`, the build command is `npm run build`, and the output directory is `./.next`. Sites detects these automatically for most Next.js projects, so you only need to change them if you use a custom setup like pnpm, Yarn, or a non-default output folder."
  - question: "How do I deploy a Next.js app from a monorepo?"
    answer: "Appwrite Sites might not auto-detect the app, so you need to set the root directory manually to the folder containing your Next.js config. For a Turborepo project that might be `./apps/web`. Once the root is set, the rest of the build settings work as normal."
  - question: "How do environment variables work in Next.js on Appwrite Sites?"
    answer: "Add environment variables in the Environment variables section when creating or editing the site. Variables prefixed with `NEXT_PUBLIC_` are exposed to the browser, so keep secrets in unprefixed variables that only run server-side."
  - question: "What if my Next.js build fails when I try to deploy?"
    answer: "Appwrite Sites builds your app the same way `npm run build` does locally, so a failing local build will fail on deploy too. Run `npm run build` on your machine first, fix any errors, then push the changes. Build logs are streamed live in the Appwrite Console so you can debug in place."
  - question: "Can I use a custom domain with my Next.js site?"
    answer: "Yes. Start with the free Appwrite Network subdomain to verify everything works, then add a custom domain from the site settings. Walk through the steps in the [custom domains guide](/blog/post/custom-domains-with-sites)."
---

You just built your Next.js app with all the killer features you wanted. Now you want to put it out for the world to see. But you’re confused: “how do I do that?”. If you’re that person, this article is for you.

In this article, you will learn how to deploy your Next.js app completely free of cost. We will use Appwrite Sites to achieve that.

Appwrite is an open-source, all-in-one cloud development platform. Use built-in backend infrastructure and web hosting, all from a single place. You can build your entire backend within minutes and deploy effortlessly, adding Authentication, Databases, Functions, Storage, Messaging, and more to your projects using the frameworks and languages of your choice.

Appwrite Sites is a frontend hosting service that can help you deploy your Next.js (or any web framework) app in minutes with full RSC (React Server Components) and SSR (server‑side rendering) functionality.

# Testing your Next.js build

Before you deploy your Next.js app, it’s essential to see if it builds successfully. Building is a process where Next.js bundles your app into files that can be run on a server and a browser, depending on your usage of SSR (server-side rendering).

To build your Next.js app, run the following command in your terminal:

```bash
npm run build
```

If your build succeeds, you can deploy your Next.js application to the internet. If your build fails, you must fix all the errors in your application. When deploying a Next.js app, the deployment provider builds your app on their servers, and cannot deploy if the build fails.

# Configuring your Site on Appwrite

This article assumes you have your Next.js app code stored in a GitHub repository. If not, please do so, as it is needed for deployment. Deploying through code stored on GitHub also introduces advantages such as automatic deployments, preview deployments, etc.

Now, let’s link your GitHub repository to Appwrite Sites.

- Go to [Appwrite Console](https://cloud.appwrite.io) and sign in using your preferred authentication method.
- Create a new project, or use an existing one.
- Click on the **Sites** option on the sidebar.
- Click on **Create Site**.
- Click on **Connect a GitHub repository**.

Now, you should be able to see the most recent GitHub repositories based on activity.

![Connecting your GitHub repository to Appwrite Sites](/images/blog/deploy-nextjs-app-to-appwrite-sites/connect-repo.avif)

Click **Connect** against the desired GitHub repository. The Next.js logo should be the icon of any valid Next.js app.

Once you connect your GitHub repository, you will be presented with options for deployment.

![Configuring your Site on Appwrite](/images/blog/deploy-nextjs-app-to-appwrite-sites/site-config.avif)

Fill in the details as desired. Although there are some things you must know before moving forward.

- If you’re using a different branch for your intended production website, choose the specific branch.
- If you’re using a monorepo, Sites might not automatically detect your application. In such cases, manually provide the root directory for your Next.js app that contains the Next.js configuration file. For example, if you’re using Turborepo, your Next.js app might be located inside one of the folders in the `apps` folder, so your root directory should be something like `./apps/web`.
- Most users don’t need to change the **Build settings**, although if you want to run different commands for installation and builds, you can configure those in this section. If you don’t know anything about this, it’s worth skipping this section altogether.
    - The default build command for Next.js is `npm run build`.
    - The default install command for Next.js is `npm install`.
    - The default output directory for Next.js is `./.next`.
- If your app uses environment variables, add them in the **Environment variables** section. If your app uses sensitive API keys, you are likely using environment variables.
- Configure an Appwrite Network subdomain to get started. You can add a custom domain or use a new Appwrite Network domain once your app is deployed.

{% info title="Note on environment variables" %}
In Next.js, public environment variables start with `NEXT_PUBLIC_`. These environment variables are accessible in the browser environment, so don’t store your sensitive API keys in a variable that starts with that prefix.
{% /info %}

Now, hit the **Deploy** button, and the deployment process will begin. You can see the build logs as your website gets deployed.

# Next steps

![Deployment successful screen](/images/blog/deploy-nextjs-app-to-appwrite-sites/site-deployed.avif)

Once your Next.js application is deployed, you can view it on the provided domains on the deployment success splash screen or in the Sites section of the Appwrite console.

Now, you can perform further steps to level up your experience with Appwrite Sites:

- [Custom domains with Appwrite Sites](/blog/post/custom-domains-with-sites)
- [How to host SSR web apps on Appwrite Sites](/blog/post/host-ssr-web-apps-sites)
- [Build and deploy a personal portfolio on Appwrite Sites](/blog/post/portfolio-template-sites)
- [Appwrite Sites quick-starts](/docs/products/sites/quick-start)
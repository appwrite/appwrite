---
layout: post
title: Announcing Turbopack support for Appwrite Sites
description: Appwrite Sites now supports Next.js applications built with Turbopack, with faster builds and better compatibility for your deployments.
date: 2025-09-17
cover: /images/blog/turbopack-support-appwrite-sites/cover.avif
timeToRead: 3
author: ebenezer-don
category: announcement
faqs:
  - question: "What is Turbopack?"
    answer: "Turbopack is a Rust based bundler developed by Vercel as a successor to webpack. It is designed for faster incremental updates and large project performance, and it is the default development bundler in recent versions of Next.js."
  - question: "Do I need to change my build command to deploy a Turbopack Next.js app on Appwrite Sites?"
    answer: "No. With native Turbopack support, you can deploy Next.js apps that use Turbopack on [Appwrite Sites](/docs/products/sites) without modifying the build command. The --turbopack flag is preserved end to end."
  - question: "My existing site shows 404 errors after the update, what do I do?"
    answer: "Open your site settings on Appwrite Sites, switch the framework adapter to Server side rendering under Build settings, and redeploy. This rebuilds the project with the updated runtime that supports Turbopack output."
  - question: "Is Turbopack support available on self hosted Appwrite?"
    answer: "Turbopack support landed first on Appwrite Cloud. Self hosted support is rolling out in an upcoming release, so check the changelog for your version before deploying."
  - question: "Does Appwrite Sites support Next.js features beyond Turbopack?"
    answer: "Yes. Appwrite Sites supports Next.js with server side rendering, static generation, and the App Router. See the [Sites docs](/docs/products/sites) for the full feature matrix."
  - question: "Will my webpack based Next.js app still work?"
    answer: "Yes. webpack builds continue to work without changes. The Turbopack update is additive and does not remove or alter the existing webpack path."
---

Appwrite Sites now supports Next.js applications built with Turbopack, Vercel's bundler. This update fixes build compatibility issues and makes your Next.js deployments faster.

# Why Turbopack matters

Turbopack is built in Rust to fix webpack's performance issues. It delivers faster updates, up to 700x faster than webpack and up to 10x faster than Vite for large applications.

Previously, deploying Next.js applications that used Turbopack on Appwrite Sites required manual intervention. Developers had to remove the `--turbopack` flag from their build commands, forcing their applications back to webpack. This created friction for teams using Turbopack in their development workflow.

# What this means for developers

With native Turbopack support, you can now:

- **Deploy without modification**: Applications using Turbopack now work on Appwrite Sites without build command changes
- **Experience faster builds**: Turbopack's speed improvements reduce deployment times
- **Maintain consistency**: Your local development environment matches your deployment environment

This change benefits teams using Next.js 13+ where Turbopack is the recommended bundler for development mode and often used in production builds.

# Improved Next.js compatibility

This update shows our commitment to Next.js compatibility. By supporting the tools and workflows that Next.js developers expect, we're removing barriers that prevented seamless deployment.

The compatibility improvements go beyond just Turbopack. They ensure that modern Next.js features work as expected, reducing friction between local development and production deployment.

# Getting started

For new projects:

1. Create your Next.js application with Turbopack enabled
2. Connect your repository to Appwrite Sites
3. Deploy. No build configuration changes needed

**For existing broken sites:** If your site shows 404 errors due to missing Turbopack support, go to your site settings, select **Server side rendering** in the **Build settings** section, and redeploy.

![Build settings in Appwrite Sites](/images/blog/turbopack-support-appwrite-sites/build-settings.avif)

This feature is live on Appwrite Cloud. Self-hosted support will be included in an upcoming release.

We're working to make Appwrite Sites the best platform for deploying modern web applications. Turbopack support is one step in that direction.
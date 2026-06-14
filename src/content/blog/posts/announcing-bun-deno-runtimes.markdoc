---
layout: post
title: "Announcing Bun and Deno build runtimes for Appwrite Sites"
description: Appwrite Sites now supports Bun and Deno as build runtimes for every Node-based framework, with Bun 1.0 through 1.3 and Deno 1.40 through 2.6 available in the build runtime dropdown.
date: 2026-05-19
cover: /images/blog/announcing-bun-deno-runtimes/cover.avif
timeToRead: 4
author: atharva
category: announcement
featured: false
callToAction: true
faqs:
  - question: "What changed for Appwrite Sites build runtimes?"
    answer: "[Appwrite Sites](/docs/products/sites) now lets you pick Bun or Deno as the build runtime for any Node-based framework. The runtime selector includes Bun versions 1.0 through 1.3 and Deno versions 1.40 through 2.6, alongside the existing Node options."
  - question: "Do I need to change my project to switch to Bun or Deno?"
    answer: "No. The runtime selector only changes which JavaScript runtime Appwrite uses to install dependencies and run your build command. Your `package.json`, framework configuration, and source code stay the same. Pick a runtime, redeploy, and the new runtime takes over from the next build."
  - question: "Why might I pick Bun over Node for builds?"
    answer: "Bun installs dependencies and runs scripts noticeably faster than Node in most projects, which shortens deploy times. The Bun 1.x release line continues to improve npm compatibility and ships fixes that matter for modern framework toolchains, so most Node-targeted projects work without changes."
  - question: "What does Deno bring as a Sites build runtime?"
    answer: "Recent Deno releases are fully npm compatible, support monorepos, and ship a built-in formatter, linter, and test runner. If your team already uses Deno locally, you can now build your Site with the same runtime instead of switching to Node just for deployment."
  - question: "How do I change the build runtime for an existing site?"
    answer: "Open the site in the Appwrite Console, go to **Site settings**, then **Runtime settings**, and pick the runtime version from the dropdown. The change takes effect on the next deployment; your currently active deployment continues to serve traffic until you redeploy."
  - question: "Does the build runtime affect what runs in production?"
    answer: "For static sites the build runtime only affects how your files are produced; the output is served as static assets afterward. For SSR sites, the request handler runs on Appwrite's serverless infrastructure, separate from the build runtime selection, so changing build runtimes does not change runtime semantics for your app."
---

Today, we are happy to announce that **Bun and Deno are now available as build runtimes for Appwrite Sites**. You can pick any supported version, from **Bun 1.0** up to the latest **Bun 1.3**, and from **Deno 1.40** up to the latest **Deno 2.6**, in the Console build runtime dropdown for any Node-based framework, and your existing site will build without any changes to your project, your `package.json`, or your build command.

# Why Bun and Deno on Sites

Sites already supports Node as a build runtime, and Node is going to keep being a great default. Adding Bun and Deno is about giving you a choice that matches how your team actually develops:

- **Bun is fast where it counts for sites.** Dependency installs, lockfile resolution, and script execution are noticeably quicker than equivalent Node tooling, which makes it well suited to the install and build phase of a deploy. The Bun 1.x release line continues to improve npm compatibility and ships fixes that matter for modern framework toolchains.
- **Deno brings a modern, secure JavaScript runtime.** Recent Deno releases are fully npm compatible, support monorepos, and ship a strong built-in toolchain including a formatter, linter, and test runner. If you already use Deno locally, you can now build your Appwrite Site with the same runtime instead of switching to Node for deploys.
- **You can stay in your stack.** This is not a new framework or a new way to structure your project. The build runtime selector simply tells Appwrite which JavaScript runtime to use when installing dependencies and running your build command.

# Available versions

The build runtime dropdown for Node-based Sites frameworks now includes:

- **Bun**: 1.0, 1.1, 1.2, 1.3
- **Deno**: 1.40, 1.46, 2.0, 2.5, 2.6
- **Node**: 14.5, 16.0, 18.0, 19.0, 20.0, 21.0, 22, 23, 24, 25

Older Bun and Deno versions are included so that existing projects pinned to a specific runtime can keep deploying without an upgrade step. New sites should pick the latest version on each line, Bun 1.3 or Deno 2.6, unless a project has a reason to stay back.

# Picking a build runtime in the Console

The build runtime is configured per site under **Site settings**, in the **Runtime settings** section. Selecting a Bun or Deno version changes which runtime Appwrite uses to install dependencies and run your build command on the next deployment. Your active deployment is not affected until you redeploy.

![Build runtime selector showing Bun 1.3 selected on an Appwrite Site](/images/blog/announcing-bun-deno-runtimes/runtime-bun.avif)

# Getting started

Bun and Deno build runtimes for Sites are **available on Appwrite Cloud today**.

# More resources

- [Appwrite Sites documentation](/docs/products/sites)
- [Sites framework support](/docs/products/sites/frameworks)
- [Appwrite Cloud](https://cloud.appwrite.io)
- [Join the Appwrite Community on Discord](https://appwrite.io/discord)

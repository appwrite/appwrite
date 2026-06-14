---
layout: post
title: "Announcing Dev Keys: faster local development without rate limits"
description: This new feature helps you build without interruptions. Designed to keep you productive.
date: 2025-05-21
cover: /images/blog/dev-keys.avif
timeToRead: 6
author: chirag-aggarwal
category: product
featured: false
faqs:
  - question: "What are Appwrite Dev Keys?"
    answer: "Dev Keys are special keys for local development that let you bypass rate limits, CORS restrictions, and hostname validation while you're building and testing. They're not meant for production traffic. You add them when initializing the Appwrite client and they apply only to that environment."
  - question: "How are Dev Keys different from API Keys?"
    answer: "[API Keys](/docs/products/auth) are server-side credentials used to call Appwrite from trusted backends, and they don't change rate limits or CORS behavior. Dev Keys exist specifically to remove development-time friction (rate limits, CORS, hostname checks) and are scoped to expire after a chosen window. They're complementary, not interchangeable."
  - question: "Do Dev Keys work with Appwrite SDKs?"
    answer: "Yes, Dev Keys integrate with all Appwrite SDKs and can also be passed via headers in raw API requests. You set the key on the client during initialization in your local or CI environment, and Appwrite recognizes it for the duration the key is valid."
  - question: "How do I create a Dev Key?"
    answer: "In the Appwrite Console, go to Overview, then Integrations, then Dev keys, and click Create Dev key. Pick an expiry (a day, a week, or a month) and copy the generated key into your local client initialization. See the [Dev Keys docs](/docs/advanced/platform/dev-keys) for the full setup."
  - question: "Will Dev Keys help with CORS errors in cloud dev environments?"
    answer: "Yes, Dev Keys let any host through during development, so you don't need to whitelist random preview URLs from GitHub Codespaces, Gitpod, or similar tools. That removes a common source of CORS errors when working on client-side code from ephemeral cloud environments."
  - question: "Are Dev Keys safe to use in production?"
    answer: "No, Dev Keys are explicitly for development and CI environments. They bypass rate limits and CORS protections that your production app actually needs. Keep them out of your production builds, set short expiries, and rotate them like you would any other credential."
---

You’re building, testing, tweaking. You’re in the zone. Then, bam, rate limit.

We’ve all been there. Development flow interrupted by API limits meant for production traffic. But that ends now.

Introducing **Dev Keys.**

A new Appwrite feature that lets you bypass rate limits *during local development*. No more slowdowns. No more artificial blockers. Just fast, uninterrupted building.

And they’re built specifically for you.

# Built for development

Appwrite has always aimed to give you the best tools to build secure, modern apps. But testing locally with production-level rate limits? That’s been a pain point for too long.

Dev Keys fix that. They let you skip rate limits while developing locally, whether building APIs, wiring up the frontend, or running automated tests.

It’s a simple but powerful shift resulting in fewer errors, faster feedback, and a better overall experience.

And they’re not just about rate limits. Dev Keys are also a great solution for **bypassing CORS restrictions** and **hostname validation errors** during development.

# Unlimited sign-in testing

Building a login screen often requires testing it over and over, tweaking error messages, checking redirects, fixing small UI bugs, etc.

Appwrite’s purposefully low rate limit of 10 calls per hour would make this process slow.

Dev Keys lift that limit up, allowing you to test the sign-in flow as much as you need and perfect your authentication-no interruptions.

# Goodbye auto-refresh rate limit

Let’s say you are working on magic-link sign-in, and using a framework like Next.js, Nuxt, or SvelteKit. These frameworks would refresh the page every time you save a file, making you quickly exhaust your magic-url sign-in request rate limit.

Dev Keys solves this issue, letting you auto-refresh and make requests unlimited times so you can build, tweak, and test your flows without ever running into rate limit errors.

# CORS debugging made effortless

CORS errors can be really annoying to deal with, especially during development in cloud-based environments like GitHub Codespaces, Gitpods, or any other platform that gives your app a random domain.

But with Dev Keys, that’s not an issue anymore. Appwrite would temporarily let any host through, so you can build and test with no more CORS headaches. No more whitelisting of random domains in the console. Just plug in the Dev Key, and get back to building.

# Important features

- **Rate limit bypass**: Make unlimited API requests in local development or CI/CD tests without triggering limits.
- **CORS and hostname validation bypass**: Skip restrictive CORS checks and hostname errors that usually block client-side development.
- **Configurable expiry**: Set Dev Keys to automatically expire after a day, a week or a month.
- **Easy integration**: Works seamlessly with Appwrite SDKs and via headers in API requests.

No production misuse. No long-term security risk. Just a better development experience.

# Why it matters

Hitting a rate limit while testing locally isn’t just annoying, it’s disruptive. It slows development, complicates debugging, and wastes time.

On top of that, during deployment **CORS issues and hostname validation errors** can halt progress unexpectedly, especially during client-side testing. Dev Keys remove those blockers so you can test and ship without interruption.

Dev Keys solve this by allowing you to test at full speed, without compromising your architecture or hitting quotas meant for live apps. Your team ships faster, more confidently, and with fewer friction points.

# Built with you in mind

Dev Keys are designed to allow you to iterate quickly and test thoroughly. Dev Keys keep you moving, whether you’re building a new feature, integrating with an SDK, or automating test cases.

And because they’re scoped and temporary by design, they stay secure and manageable in any workflow.

# How to get started

To create a new Dev Key, navigate to Overview > Integrations > Dev keys and click Create Dev key. You can then implement the Dev Key while initializing the Appwrite client in your app.

[Visit the docs](/docs/advanced/platform/dev-keys) to learn more.

# Build without limits

We built Dev Keys to make your experience with Appwrite even better. Because great development tools shouldn’t get in your way, they should get out of it.

Try Dev Keys today in your next project and see the difference.

Available now on **Appwrite Cloud** and **self-hosted** deployments.

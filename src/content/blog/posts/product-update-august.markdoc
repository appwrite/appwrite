---
layout: post
title: "August product update: Init | 1.6 release to Cloud and Self-hosted"
description: Get a full recap of last month's Init and everything else that happened in August.
date: 2024-09-02
cover: /images/blog/init-recap-august/product-update.avif
timeToRead: 7
author: dennis-ivy
category: product
featured: false
faqs:
  - question: "What is new in Appwrite 1.6?"
    answer: "Appwrite 1.6 shipped local development for Functions, a rewritten CLI, Go as a runtime and SDK, faster cold starts, dynamic API keys, delayed and binary executions, mock phone numbers for testing, and session alerts. It is one of the larger releases in the project's history."
  - question: "What is local development for Functions?"
    answer: "Local development lets you run and test Appwrite [Functions](/docs/products/functions) on your machine before deploying. You get a faster feedback loop and can debug without pushing every change to the cloud."
  - question: "What does the new Appwrite CLI do differently?"
    answer: "The new CLI adds local function testing, non-destructive data sync between your machine and Appwrite, and headless login for CI/CD pipelines. The previous CLI overwrote remote data on every push, which the new one fixes."
  - question: "Does Appwrite Functions support Go?"
    answer: "Yes. Go is supported as both a Functions runtime and an SDK. The Go runtime offers up to 3x faster cold starts and sub-millisecond base execution times, which makes it a good choice for CPU bound work."
  - question: "What are mock phone numbers used for?"
    answer: "Mock numbers let you set up a list of phone numbers with predefined verification codes. This is helpful when submitting apps for store review or running automated tests on phone authentication flows, without sending real SMS."
  - question: "What do session alerts notify users about?"
    answer: "Session alerts email your users when a new session starts on their account. It is a low effort way to give users visibility into suspicious activity and is enabled per project from the Auth settings."
---

Great news for all of you who cannot wait to get started with all the newly announced products and features: 1.6 is now available on Cloud and Self-hosted. Go to the Init page to find all relevant content and documentation. Or keep reading to learn more about what has been released.

[Visit Init](https://appwrite.io/init)

![1.6 release](/images/blog/init-recap-august/localdev.avif)

# Local Development

On day 0, we released Local Development, which allows you to develop and test functions on your local machine.

Running functions locally has many benefits. Local development allows faster debugging, a quicker feedback loop, and a better overall experience. When running your function locally during development, you are more likely to test it every step of the way instead of shipping multiple big changes all at once.

Watch the product tour for more, or visit our [documentation](https://appwrite.io/docs/products/functions/develop-locally) to get started.

[Watch product tour](https://youtu.be/GMwrHds4Oa8)

![1.6 release](/images/blog/init-recap-august/newcli.avif)

# New Appwrite CLI

A good, easy-to-use CLI makes all the difference in your experience, so we're really excited to introduce a new version of the Appwrite CLI.

It allows you to test your functions locally, easily migrate databases, and more.

The new CLI’s main features are:

- **Local development**: Test your Appwrite function locally
- **Data sync**: Push and pull data between Appwrite and your CLI in a non-destructive way.
- **Headless login**: Login to your Appwrite account in a non-interactive manner for CI/CD pipelines

Besides those features, this release includes many bug fixes and optimizations.

Watch the product tour for more, or visit our [documentation](https://appwrite.io/docs/tooling/command-line/installation) to get started.

[Watch product tour](https://youtu.be/nlzFl3AHlog)

![1.6 release](/images/blog/init-recap-august/functionseco.avif)

# Functions ecosystem

Appwrite loves Functions, so it's with great pleasure that we're introducing a host of new updates to the Functions ecosystem.

- Faster Functions cold-starts
- Dynamic API keys
- Delayed executions
- Binary executions
- Execution and deployment filtering

Functions are now faster, smoother, better, and more secure.

Watch the product tour for more.

[Watch product tour](https://youtu.be/G2UBTOBumII)

![1.6 release](/images/blog/init-recap-august/go.avif)

# Go support

We've added a Go SDK and Function runtime to allow you to take advantage of Go’s speed to handle complex tasks like calculations, statistics, or file transformations more efficiently. The new runtime allowed for up to 3x faster cold starts and a base execution time of less than 1 millisecond. That means your functions will start running much quicker, and once they're up, they'll fly.

## What can you build with Go?

Are you excited to start building with Go? Here are a few use cases to get you started:

- **Create APIs:** Build RESTful APIs quickly and efficiently.
- **Process data:** Handle complex data transformations and analysis.
- **Image processing:** Resize, crop, or filter images on the fly.
- **File conversion:** Convert files between different formats.
- **Machine learning:** Deploy machine learning models for real-time predictions.

[Read announcement](https://appwrite.io/blog/post/announcing-go-support)

![1.6 release](/images/blog/init-recap-august/mock.avif)

# Mock numbers & Session alerts

We've listened to your feedback and are introducing two new features to simplify phone authentication testing and bolster account security.

Mock numbers allow you to set up a list of phone numbers with a predefined, static verification code, which is perfect for app reviews and testing. Session alerts will help your users maintain their account security.

[Read announcement](https://appwrite.io/blog/post/announcing-mock-numbers-session-alerts)

# Community recognitions

This month, we’re excited to feature Ayush Kumar as part of our Monthly Community Recognitions.

The Next.js boilerplate with Appwrite integration helps you launch your SAAS faster with **+10 pre-built** features and several components.

Visit https://spiralsaas.dev/ to learn more.

If you'd like to participate in next month's Community Recognitions, [join our Discord server](https://appwrite.io/discord) to showcase your project.

# Engineering resources

- [Read](https://appwrite.io/blog/post/functions-local-development-guide): Local serverless function development with the new Appwrite CLI
- [Read](https://appwrite.io/blog/post/serverless-functions-best-practices): Serverless Functions 101: Best practices
- [Read](https://appwrite.io/blog/post/ci-cd-examples-in-appwrite): CI/CD examples in Appwrite CLI
- [Read](https://appwrite.io/blog/post/appwrite-vs-firebase-vs-supabase-functions-comparison): Comparing serverless functions: Appwrite vs. Supabase vs. Firebase
- [Read](https://appwrite.io/blog/post/how-to-leverage-dynamic-api-keys-for-better-security): How to leverage Appwrite Dynamic Keys for enhanced security
- [Read](https://appwrite.io/blog/post/3-things-you-can-build-with-go-runtime): 3 things you can build with the Go runtime
- [Read](https://appwrite.io/blog/post/what-is-golang): Why should you use Golang in your app?
- [Read](https://appwrite.io/blog/post/mock-numbers-best-practices): Mock numbers for phone sign-in: Use cases and best practices
- [Read](https://appwrite.io/blog/post/designing-init-event-logo): Designing Init: Event logo and theme
- [Read](https://appwrite.io/blog/post/open-source-contributors-16): Celebrating the open source contributors for Appwrite 1.6
- [Read](https://appwrite.io/blog/post/swift-101-build-a-library-with-swift-package-manager): Swift 101: how to build a library with Swift Package Manager
- [Read](https://appwrite.io/blog/post/best-pagination-technique): Best database pagination technique
- [Read](https://appwrite.io/blog/post/image-classification): A technical deep dive into image classification
- [Read](https://appwrite.io/blog/post/how-to-back-up-your-appwrite-data): How to back up and restore your Appwrite data
- [Watch](https://www.youtube.com/watch?v=ENnG7GusuO4): Here's how you do auth with 100% SSR in Next.js

# What's to come

Thank you all for making Init another week to remember. Here is a sneak peek of what we will be doing in the next weeks:

- We are adding new regions and backups to Cloud
- A new Student Program in collaboration with GitHub
- The Scale plan will be released soon with supporting features

Follow us on [X](https://x.com/appwrite) and check our [Changelog](https://appwrite.io/changelog) regularly, as we will release more information in the coming weeks.

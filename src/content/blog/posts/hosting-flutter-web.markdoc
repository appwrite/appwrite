---
layout: post
title: "Announcing hosting for Flutter web: deploy your Flutter web apps with Appwrite"
description: Appwrite Sites now supports Flutter web apps. Deploy your Flutter applications directly from your Appwrite project with seamless backend integration.
date: 2025-05-20
cover: /images/blog/hosting-flutter-web/cover.avif
timeToRead: 5
author: steven
category: product, init
featured: false
faqs:
  - question: "Can I deploy a Flutter web app on [Appwrite Sites](/docs/products/sites)?"
    answer: "Yes. Appwrite Sites natively supports Flutter web as a framework. It auto-detects Flutter projects, applies sensible defaults for the install and build commands, and serves the static output from `./build/web`. No manual platform-specific configuration is required."
  - question: "What are the default build settings for a Flutter web site on Appwrite?"
    answer: "Leave the install command empty, set the build command to `flutter build web`, and use `./build/web` as the output directory. Connect your GitHub repo and Appwrite handles the rest, including redeploys on every push."
  - question: "Why deploy a Flutter web app instead of an Android or iOS app?"
    answer: "Web deployment skips the store approval process, the \\$25 Android and \\$99/year Apple developer accounts, and platform-specific review delays. Users can access the app from any browser on any device the moment you push, and you keep the same Dart codebase."
  - question: "Can I use Flutter web for auth callbacks and email verification pages?"
    answer: "Yes, and this is a common pattern even when your primary app is mobile. You can build the email verification, password reset, or payment confirmation pages as a small Flutter web app and host them on Sites, keeping everything in one Dart codebase instead of stitching together separate HTML pages."
  - question: "Does Flutter web hosting work on self-hosted Appwrite?"
    answer: "Yes. Sites for Flutter works on both Appwrite Cloud and self-hosted Appwrite. The same auto-detection, GitHub integration, and build pipeline are available regardless of where Appwrite is running."
  - question: "How does Flutter web compare to mobile Flutter for performance?"
    answer: "Flutter web ships a larger initial bundle than a native mobile app because it includes the rendering engine, so first-load times can be slower on poor connections. Once loaded, runtime performance is usually fine for most apps. If first-load matters (marketing sites, etc.), a native web framework may still be a better fit."
---

Appwrite has long been a powerful backend platform for Flutter developers building mobile applications. Today, we’re bringing that same seamless experience to the web. With full support for Flutter in [Appwrite Sites](/products/sites), you can now deploy Flutter web apps directly from your Appwrite project. No extra configuration, no added complexity.

This means you can use your existing Dart and Flutter knowledge to create fast, responsive web apps without needing to learn HTML, CSS, or JavaScript. Build once with Flutter and deploy to mobile, desktop, and web. All from a single codebase, all hosted on Appwrite.

# Why native Flutter web support matters

While most web environments are made for Node, Deno, or Bun runtimes, only a few are tailored for the needs of Flutter developers. Appwrite Sites is different. It’s designed with full Flutter web support in mind,  not just as an afterthought. That means native framework detection, optimized build settings, and direct integration with your Appwrite backend products like databases, authentication, storage, and functions.

More importantly, it eliminates the common friction points of mobile app deployment. You don’t need to pay for developer accounts ($25 for Android, $99/year for Apple), wait days for store approvals, or navigate restrictive platform policies. With web deployment, your app is live the moment you push it.

And because web apps run anywhere with a browser, you’re no longer limited to iOS or Android. Your Flutter app can be accessed from a desktop, tablet, mobile phone, or even a smart fridge. The web is universal, and Appwrite makes it easy to reach your users.

# Not just for full web apps

Even if you're not targeting the web as your primary platform, Appwrite Sites still solves real needs for Flutter developers.

Many apps include flows that rely on external links, such as email verification, password reset, or payments. These typically direct users to a browser. With Sites, you can now create and host those supporting pages using the same Flutter tooling you're already familiar with.

Instead of stitching together HTML pages or managing another stack, you can build these "satellite" pages as lightweight Flutter web apps,  and deploy them effortlessly with Appwrite. It's a way you leverage your existing knowledge and a more consistent way to build, with fewer moving parts and one unified codebase.

# Key features

- **Full Flutter framework support**: Sites now recognizes and supports Flutter as a framework during setup.
- **Auto-detection**: Flutter web projects are automatically identified.  No manual config required.
- **Optimized static hosting**: Deployed like any other static site, but with Flutter-first optimizations.
- **GitHub integration**: Automatically deploy on every push from your GitHub repository.
- **Works on Cloud and Self-Hosted**: Whether using Appwrite Cloud or Self-hosting, Sites for Flutter works everywhere.

# How to get started

## Step 1: Create Flutter web app

First, you must either create a Flutter Web app or set up the [Flutter Web starter template](https://github.com/appwrite/templates-for-sites).

Open your terminal, and run the following command.

```bash
flutter create my_app
```

In case you have an existing Flutter app and want to add web support to it, you need to run the following command in your project directory:

```bash
flutter create . --platforms web
```

Push this project to a [GitHub repository](https://github.com/new).

## Step 2: Create Appwrite project

Head to the [Appwrite Console](https://cloud.appwrite.io/).

If this is your first time using Appwrite, create an account and create your first project.

## Step 3: Create site

Head to the **Sites** page in your Appwrite project, click on the **Create site** button, and select **Connect a repository**.

Connect your GitHub account and select the repository you intend to deploy (or allow all repositories, for future ease).

1. Select the **production branch** and **root directory** from your repo.
2. Verify that the **correct framework** is selected. In case an incorrect framework is visible, pick **Flutter Web** from the drop-down list.
3. Confirm the **install command**, **build command**, and **output directory** in the build settings. The default build settings for Flutter Web are:
    - **Install command:** `N/A` (leave empty)
    - **Build command:** `flutter build web`
    - **Output directory:** `./build/web`
4. Add any **environment variables** required by the site. This is not necessary if you're deploying the starter app.

Click on the **Deploy** button.

## Step 4: Visit site

After successful deployment, click on the **Visit site** button.

# Simplifying development

Sites for Flutter is part of Appwrite’s larger mission to simplify the developer experience. With support for backend and frontend services, including static hosting, databases, functions, and auth, Appwrite is the all-in-one platform to build any application.

Try it today on **Appwrite Cloud** or **self-hosted,** and you can take your Flutter app live in just a few clicks.

# More resources

- [Appwrite Sites docs](/docs/products/sites)
- [Appwrite compared to Vercel](/blog/post/open-source-vercel-alternative)
- [Appwrite Sites product tour](https://youtu.be/VtDe6hDw91k)
- [Appwrite Sites video announcement](https://youtu.be/0cERQxFjTW4)
- [Appwrite Discord server](/discord)

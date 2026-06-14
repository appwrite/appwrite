---
layout: post
title: "Announcing Dart 3.12 for Functions and Flutter 3.44 for Sites"
description: Appwrite now ships Dart 3.12 as a Functions runtime and Flutter 3.44 as a build runtime for Sites, bringing faster cold starts, smaller Wasm web bundles, and a single Dart codebase across mobile, web, and backend.
date: 2026-05-26
cover: /images/blog/announcing-dart-flutter-runtimes/cover.avif
timeToRead: 5
author: damodar-lohani
category: announcement
featured: false
callToAction: true
faqs:
  - question: "What changed for Dart and Flutter on Appwrite?"
    answer: "[Appwrite Functions](/docs/products/functions) now offers Dart 3.12 in the runtime dropdown, and [Appwrite Sites](/docs/products/sites) now offers Flutter 3.44 as a build runtime for Flutter Web projects. Both are available on Appwrite Cloud today."
  - question: "Do I need to change my code to move to Dart 3.12 or Flutter 3.44?"
    answer: "No. The runtime selector only changes which Dart or Flutter version Appwrite uses to build and run your project. Your `pubspec.yaml`, dependencies, and source code stay the same. Pick the new version, redeploy, and the next build uses it."
  - question: "What does Dart 3.12 bring to Appwrite Functions?"
    answer: "Dart 3.12 stabilises macros for static metaprogramming, adds `pub workspaces` for cleaner monorepos, and continues the language ergonomics work from Dart 3 (records, patterns, switch expressions, sound null safety). For Functions, that means less boilerplate around request and response models and easier sharing of code with your Flutter app."
  - question: "Why pick Flutter 3.44 as a build runtime for Sites?"
    answer: "Flutter 3.44 makes WebAssembly the recommended build for production Flutter Web, with a more mature Skwasm renderer, smaller bundles, and better web semantics for accessibility and SEO. Combined with the Appwrite Network, your Flutter Web site loads faster and feels closer to a native app in the browser."
  - question: "How do I change the runtime for an existing Function or Site?"
    answer: "For Functions, open the function in the Appwrite Console and pick **Dart 3.12** in the runtime dropdown. For Sites, open the site, go to **Site settings**, then **Runtime settings**, and pick **Flutter 3.44**. The change takes effect on the next deployment; your currently active deployment continues to serve traffic until you redeploy."
  - question: "Can I share code between my Flutter app and my Appwrite Function?"
    answer: "Yes. Because both run Dart, you can keep models, validation, and shared logic in one package and consume it from both your Flutter client and your Appwrite Function. Dart 3.12's `pub workspaces` make this especially clean when everything lives in one repository."
  - question: "Where do I host my Dart backend?"
    answer: "Deploy it as an [Appwrite Function](/docs/products/functions) with the Dart 3.12 runtime. You get AOT-compiled cold starts in the millisecond range, autoscaling, and direct access to Appwrite's databases, auth, storage, and messaging from the same project."
  - question: "Where do I deploy my Flutter Web app?"
    answer: "Deploy it on [Appwrite Sites](/docs/products/sites) with the Flutter 3.44 build runtime. Appwrite runs `flutter pub get` and `flutter build web --release` on every deployment and serves the output through the Appwrite Network."
---

Today, we are happy to announce two new runtimes for Dart developers on Appwrite:

- **Dart 3.12** as a Functions runtime.
- **Flutter 3.44** as a build runtime for Appwrite Sites.

Together, they let you write your mobile app, your web app, and your backend in the same language, ship them from the same repository, and run them on the same platform.

# Dart 3.12 for Appwrite Functions

Dart has been one of the fastest runtimes on Appwrite Functions since day one. Because Dart compiles to ahead-of-time (AOT) native binaries on deploy, there is no interpreter to spin up and no JIT to warm at request time, which is why Dart functions wake up in milliseconds and stay snappy under load. That fast-start property comes from Dart being AOT-compiled in general, not from any specific point release. What Dart 3.12 adds is on the language and tooling side.

- **Macros are stable.** Static metaprogramming with macros lets libraries generate serialization, data classes, and adapters at compile time, with no extra build step. For Functions, that means less boilerplate around request and response models and fewer runtime errors.
- **Pub workspaces for monorepos.** If your Appwrite Function lives next to your Flutter app in the same repo, `pub workspaces` lets them share dependencies cleanly and resolve once for the whole workspace. Less drift between your client and your backend.
- **Sound null safety, records, patterns, switch expressions.** The features that made Dart 3.x feel like a modern language are all here, and they pay off especially well in functions that integrate with third-party APIs or untrusted input.

Dart 3.12 is selectable in the runtime dropdown when you create or update a Function, alongside earlier Dart versions for projects that need to stay pinned.

# Flutter 3.44 for Appwrite Sites

Appwrite Sites already supports Flutter Web as a first-class framework. Flutter 3.44 raises the ceiling for what a Flutter Web deployment can deliver on Sites.

- **WebAssembly is the default.** Flutter Web compiled to Wasm produces faster, smoother UIs than the legacy JS output, especially on animation-heavy screens. With Flutter 3.44, the Wasm pipeline is mature enough to be the recommended build for production sites.
- **Smaller, faster initial loads.** Tree shaking, font subsetting, and improvements in the Skwasm renderer cut down what the browser has to download before the first frame paints. On a Sites deployment served through the Appwrite Network, which compounds: smaller artifacts and globally cached delivery.
- **Better web semantics and accessibility.** The web semantics tree is more accurate and more performant in 3.44. Screen readers, keyboard navigation, and search engines see your Flutter app more like a real HTML document.
- **Hot reload, even on the web.** Iteration on the web build feels closer to mobile development now, which makes Flutter Web a more pleasant target to actually maintain, not just deploy.

Selecting Flutter 3.44 happens per site under **Site settings**, in the **Runtime settings** section. Appwrite runs `flutter pub get` and `flutter build web --release` for you on every deployment, and serves the output from the same global edge that powers the rest of Sites.

# One language across your stack

The reason these two releases ship together is that they unlock something bigger than either one alone. With Dart 3.12 on Functions and Flutter 3.44 on Sites, you can:

- Build your mobile app with Flutter.
- Build your web app with the same Flutter codebase and deploy it to Appwrite Sites.
- Write your serverless functions in Dart and share models, validation, and types with your client.
- Ship all of it from one repository, one CI pipeline, and one platform.

For teams that picked Dart and Flutter to avoid context switching between frontend and backend, this closes the loop.

# Getting started

Dart 3.12 and Flutter 3.44 runtimes are **available on Appwrite Cloud today**.

- Pick **Dart 3.12** in the runtime dropdown when creating or updating an Appwrite Function.
- Pick **Flutter 3.44** in the build runtime selector under your Site’s **Runtime settings**, then redeploy.

Existing deployments keep running on whichever runtime version they were last deployed with. The change applies to the next deployment.

# More resources

- [Appwrite Functions documentation](/docs/products/functions)
- [Functions runtimes](/docs/products/functions/runtimes)
- [Deploy a Flutter Web app to Appwrite Sites](/docs/products/sites/quick-start/flutter)
- [Appwrite Cloud](https://cloud.appwrite.io)
- [Join the Appwrite Community on Discord](https://appwrite.io/discord)

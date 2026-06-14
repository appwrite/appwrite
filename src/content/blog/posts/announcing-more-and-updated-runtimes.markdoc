---
layout: post
title: New and Updated Runtimes in the Appwrite Ecosystem
description: More runtimes, more flexibility. We're happy to add more and updated runtimes to allow you to build with your preferred tech stack.
date: 2024-03-01
cover: /images/blog/runtimes.avif
timeToRead: 6
author: vincent-ge
category: product, announcement
faqs:
  - question: "Which runtimes does Appwrite Functions support?"
    answer: "Appwrite Functions supports a wide range of runtimes including Node.js, Bun, Deno, Python, PHP, Ruby, Dart, Kotlin, Java, Swift, and Rust. New versions are added regularly to keep pace with language updates. Check the [Appwrite Functions documentation](/docs/products/functions) for the full list of supported versions."
  - question: "What is the difference between Bun and Node.js?"
    answer: "Bun is a JavaScript runtime built around the JavaScriptCore engine, while Node.js uses V8. Bun bundles a dependency manager, test runner, and web server into one binary, and includes built-in TypeScript and JSX support. Benchmarks generally show Bun running faster than Node.js for many workloads, though Node.js has a larger ecosystem and longer track record."
  - question: "What is sound null safety in Dart?"
    answer: "Sound null safety means a variable cannot hold `null` unless its type explicitly allows it. The compiler enforces this at build time, so you catch potential null dereferences before your code runs. Dart 3 enforces sound null safety by default, which reduces runtime errors when integrating with third-party APIs or handling user input."
  - question: "How do I use a specific runtime in Appwrite Functions?"
    answer: "When creating a function in the Appwrite Console or via the CLI, select the runtime and version you want. Each function is pinned to a specific runtime version so updates to the platform do not break your code. You can update the runtime version later from the function settings."
  - question: "What is YJIT in Ruby 3.3?"
    answer: "YJIT is a Just-In-Time compiler shipped with Ruby that compiles hot Ruby code to machine code at runtime. It improves execution speed for many real-world Rails workloads without requiring changes to your application. Ruby 3.3 includes performance and stability improvements to YJIT over earlier releases."
  - question: "Can I deploy an Appwrite Function from a Git repository?"
    answer: "Yes. You can connect a function to a GitHub repository and Appwrite will deploy automatically on every push to the configured branch. This works with all supported runtimes and also lets you use function templates to scaffold a project in one click."
---

Previously, we completely reimagined [Functions](https://dev.to/appwrite/serverless-your-way-unleashing-appwrite-functions-true-potential-2l4f) to be more flexible and innovative yet familiar to developers. Now, Appwrite expands and updates the runtime ecosystem available on Appwrite with Bun 1.0.29, Node 21, Ruby 3.3, Deno 1.40, PHP 8.3, Python 3.12, Kotlin 1.9, Java 18, Swift 5.9 and Dart 3.3 support.

We’re excited to have updated versions so you can build with your preferred tech stack and Appwrite. Let’s dive into some of these runtimes and why they are a great addition to the runtime ecosystem.

# Bun, blazing fast and built for web

[Bun](https://bun.sh/) is an amazingly fast runtime, dependency manager, test runner, and web server for JavaScript with unique features that make it a perfect fit for Appwrite Functions. Bun is fast, like really fast. The runtime performance, dependency manager, and test runners are noticeably faster than its many Node.js counterparts. This is a topic well discussed in [benchmarks](https://medium.com/deno-the-complete-reference/is-bun-really-much-faster-than-node-js-e5b15942a8e8).

Bun also boasts a robust standard library. It enables TypeScript and JSX out of the box without a build step, implements standard Web APIs like `fetch()`, and comes with a built-in test runner. All this reduces build times and dependencies needed. Our Bun 1.0.29 runtime brings improved compatibility with the NPM ecosystem and countless bug fixes.

# Dart 3.3, sound null safety

Dart is one of Appwrite’s [fastest runtimes](https://dev.to/appwrite/take-your-serverless-functions-to-new-speeds-with-appwrite-013-5868) being a compiled language, with impressive cold-start performance and millisecond response times when warm. For Flutter developers, you can write your app and Appwrite Functions code in Dart, track them in the same repository, and deploy your app and function together when you PR to your production branch.

For Dart developers, Dart 3 introduces many exciting features like records for structured data, patterns, more verbose OOP concepts, and more. For Appwrite Functions developers, however, sound null safety is a stand-out.

Sound null safety means unless you specify a variable can be null, it will **never be null**. At compile time, not accessing nullable values safely or assigning potentially null values to non-nullable variables will throw errors.

This means you’ll see fewer runtime errors in your Appwrite Functions, especially when Appwrite Functions often integrate with third-party APIs you don’t control or receive user input.

```jsx
The getter 'age' was called on null.
Receiver: null
Tried calling: type
```

# Ruby 3.3, faster, leaner, and safer

Ruby 3.3 brings some noteworthy upgrades to the table. First off, there’s YJIT, a brand-new Just-In-Time compiler that’s all about boosting Ruby's speed for certain tasks. This means your Ruby apps could run smoother and faster, which is always a win. Then, there's the garbage collection game, which got more precise, helping to reduce memory usage and keep things running efficiently. On the security front, Ruby 3.3 has beefed up with updated gems and libraries, plus clearer error messages to make debugging a breeze. In short, Ruby 3.3 is making Ruby faster, leaner, and safer, making it a solid update.

# Updated runtimes for Appwrite

For better dependency compatibility and to reduce vulnerabilities, we've also updated many other runtimes with new versions:

- Node 21
- Deno 1.40
- PHP 8.3
- Python 3.12
- Kotlin 1.9
- Java 18
- Swift 5.8

# Try the new runtimes

Try the new runtimes on Appwrite with zero lines of new code using function templates. Appwrite will clone a template repository to your GitHub profile and set up automatic deployments so you can start building with these runtimes immediately.

![Get started with Functions and runtimes](/images/blog/runtime-functions.avif)

Visit our documentation to learn more about the runtimes, join us on Discord to be part of the discussion, view our blog and YouTube channel, or visit our GitHub repository to see our open-source code.

- [Docs](/docs/products/functions/runtimes)
- [Discord](https://appwrite.io/discord)
- [Blog](/blog)
- [YouTube](https://www.youtube.com/channel/UCtBJ1v69gm8NgbCju_03Fiw)
- [GitHub](https://github.com/appwrite/appwrite)

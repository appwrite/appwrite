---
layout: post
title: Announcing Deno support on Appwrite Cloud
description: Appwrite expands the Cloud Function ecosystem with Deno, a runtime that offers developers simplicity and security.
date: 2024-10-09
cover: /images/blog/deno-runtime-announcment/cover.avif
timeToRead: 3
author: laura-du-ry
category: announcement
faqs:
  - question: "Does Appwrite Cloud support Deno?"
    answer: "Yes. Deno is a supported runtime for [Appwrite Functions](/docs/products/functions). You can create a new function using the Deno quick start, connect a GitHub repository, and deploy TypeScript or JavaScript code that runs on the Deno runtime."
  - question: "Why use Deno on Appwrite Functions?"
    answer: "Deno offers faster cold starts, native TypeScript, a strict permission model, and built-in tooling (linter, formatter, tester). For serverless functions where latency and security matter, those are meaningful gains over Node.js without changing how you write JavaScript."
  - question: "How do I create a Deno function on Appwrite?"
    answer: "In the Appwrite Console, create a new function and pick the Deno runtime. Connect a GitHub repository (or upload code directly) and visit your function's domain to invoke it. See the [Appwrite Functions quick start](/docs/products/functions) for details."
  - question: "Can I use npm packages in Deno on Appwrite?"
    answer: "Yes. Deno 2.0 supports npm packages via the npm: specifier and CommonJS modules. This means you can reuse most of the Node.js ecosystem in your Appwrite Functions running on the Deno runtime."
  - question: "Is Deno faster than Node.js on Appwrite Functions?"
    answer: "In general, Deno offers lower cold starts and higher HTTP throughput than Node.js, particularly for TypeScript-heavy workloads where Node.js requires transpilation. Actual numbers depend on your specific function, so benchmark with your own code."
  - question: "What permissions do Deno functions need on Appwrite?"
    answer: "When running locally with Deno you pass --allow-net, --allow-read, etc. On [Appwrite Functions](/docs/products/functions), the runtime handles permission setup based on what your function needs (network access, environment variables, etc.). You write standard Deno code and Appwrite handles the rest."
---



Today, Appwrite expands the Cloud Function ecosystem with a new, powerful runtime that offers developers simplicity and security: introducing Deno support. With [Deno](https://deno.com/), you can write modern, fast, and secure cloud functions in JavaScript and TypeScript, which includes all-in-one tooling such as a [linter](https://docs.deno.com/runtime/reference/cli/linter/), [test runner](https://docs.deno.com/runtime/reference/cli/test/), [formatter](https://docs.deno.com/runtime/reference/cli/formatter/) and more, as well as [web standards API support](https://docs.deno.com/runtime/reference/web_platform_apis/).

# **A fast, secure, and easy way to write JavaScript**

Deno is built with a specific goal in mind: to simplify web development by providing a modern, all-in-one toolchain for programming secure and performant JavaScript and TypeScript.

Deno's built-in permissions model ensures that your functions are securely isolated, only allowing access to specific files, network resources, or environment variables that you explicitly grant. This model makes Deno particularly well-suited for environments where security is a top priority, such as handling sensitive user data or working with external APIs.

Additionally, Deno is fast. The new `deno install` command can install uncached dependencies faster than Bun and Node, execute npm modules almost twice as fast as Node and Bun, and offer lower cold starts on average compared to Node and Bun.

# **Speed and efficiency for Appwrite developers**

With the addition of Deno, developers using Appwrite can now leverage the full power of a runtime that is designed for modern needs. Deno offers faster cold start times and higher HTTP throughput compared to Node and Deno, as well as a highly optimized execution, making it an ideal choice for cloud functions that need to be responsive and scalable. Whether you're building HTTP endpoints, processing data for import/export, or automating backend jobs, Deno's performance will help you reduce latency and increase efficiency.

Deno also supports web standard APIs, so if you're familiar with web development, you can get started with Deno immediately. When using Deno, you aren't learning new platform specific APIs or functionalities, but rather you are investing in your knowledge of the largest, and most important platform in the world: the web.

The runtime also benefits from Deno’s comprehensive toolset, including its built-in linter, formatter, tester, dependency inspector, [and more](https://docs.deno.com/runtime/reference/cli/all_commands/). Reducing reliance on third-party tooling streamlines workflow and minimizes external dependencies. This means fewer headaches when it comes to maintenance and more focus on writing the code that matters.

# **The Deno 2.0 advantage**

Our support for Deno comes at an especially exciting time with the recent release of **Deno 2.0**. This version introduces substantial improvements, making the integration even more powerful for Appwrite developers:

- **Improved Node.js compatibility**: Deno 2.0 introduces the process global variable, which allows for better interoperability with existing Node.js codebases.
- **Enhanced dependency management**: Deno 2.0 is fully compatible with NPM, and introduces monorepo support to fit needs of large codebases.
- **Upgraded TypeScript support**: With TypeScript 5.6 included, Deno 2.0 ensures you’re working with the latest features and optimizations in the language.
- **Upgraded security**: The updated permission system in Deno 2.0 adds more granular control over application access, including the introduction of Deno.errors.NotCapable to distinguish between system and Deno-specific issues, providing a clearer security model for your projects.
- **Better web framework support**: Deno 2.0 now works with well-known meta frameworks such as Next.js, Remix, Svelte, Qwik, and more. Support for those, as well as typical web toolings like Vite, Parcel, and Rollup, all make Deno 2.0 a great candidate for building modern web applications.

# **How to get started with Deno on Appwrite Cloud**

It’s easy to begin building with Deno on Appwrite Cloud. Here’s how:

1. **Create a new function** in your Appwrite Cloud project using Deno Quick Start. Here's our Functions [quickstart guide](https://appwrite.io/docs/products/functions/quick-start).
2. **Connect GitHub repository**, or choose to connect later if you just want to see it in action.
3. **Visit your function’s domain** to make your first execution.
4. **Implement your function** and benefit from Deno’s amazing performance and tooling features, all within the powerful Appwrite ecosystem.

Supporting Deno opens up new possibilities for Appwrite developers, empowering you to build faster and more securely while taking advantage of Deno’s cutting-edge features. And with the recent release of Deno 2.0, now is the perfect time to explore what this exciting runtime has to offer.

We’re excited to see what you’ll create with Deno on Appwrite Cloud.

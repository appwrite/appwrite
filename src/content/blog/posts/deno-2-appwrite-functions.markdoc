---
layout: post
title: Deno 2.0 and what it means for Appwrite Functions
description: Explore Deno 2.0's key features, what they mean for Appwrite Functions, and why you should consider using it.
date: 2024-10-09
cover: /images/blog/deno-2-appwrite-functions/cover.avif
timeToRead: 6
author: ebenezer-don
category: product
faqs:
  - question: "What is Deno 2.0?"
    answer: "Deno 2.0 is the latest major release of the Deno runtime, created by Node.js author Ryan Dahl. It is a secure-by-default, modern JavaScript and TypeScript runtime with built-in tooling (linter, formatter, test runner), improved Node.js compatibility, and a refined permission model."
  - question: "Can I use Deno 2.0 in Appwrite Functions?"
    answer: "Yes. Deno 2.0 is supported as a runtime for [Appwrite Functions](/docs/products/functions). You can write your function handlers in TypeScript or JavaScript and rely on Deno's built-in tools rather than configuring Babel or ts-node."
  - question: "How does Deno's permission model work?"
    answer: "Deno requires explicit permission flags for file system access, network calls, environment variables, and other capabilities (--allow-net, --allow-read, --allow-write, etc.). Without the flag, the runtime throws a Deno.errors.NotCapable error. This protects you from malicious dependencies."
  - question: "Does Deno work with npm packages?"
    answer: "Yes. Deno 2.0 has full npm compatibility. You can import npm packages directly using the npm: specifier, and CommonJS modules are supported. This makes it easier to reuse existing Node.js libraries in your Deno code."
  - question: "What is the difference between Deno and Node.js for serverless functions?"
    answer: "Deno has native TypeScript, stricter security, and faster cold starts than Node.js, which makes it well suited for serverless environments. Node.js has a larger ecosystem and is the more familiar choice. [Appwrite Functions](/docs/products/functions) supports both, so you can pick per function."
  - question: "Why doesn't Deno use node_modules?"
    answer: "Deno uses URL-based imports (or npm: specifiers) and a global cache instead of a project-local node_modules directory. This avoids dependency duplication, reduces project size, and makes deployments smaller, which matters for cloud and serverless workloads."
---

Deno 2.0 is the latest version of the secure, modern runtime created by Ryan Dahl, the same developer behind Node.js. For years, Node.js has been the go-to runtime for building server-side JavaScript applications. It's familiar, it's powerful, and it has a huge ecosystem of libraries. But like any technology, Node.js isn't without its flaws.

Deno was built to address most of Node.js' issues, especially around security, dependency management, and TypeScript support. Deno is designed to be a more secure and modern alternative to Node.js, with a focus on server-side applications, which makes it a good choice for Appwrite Functions.

This article will give you an overview of Deno 2.0, its benefits, key features, and how it compares to Node.js in Appwrite.

# Key features of Deno 2.0

Deno 2.0 builds on the foundation laid by the earlier versions, introducing several new features and improvements. Here are some of the key features of Deno 2.0:

## 1. Secure by default

Deno has always prioritized security, but version 2.0 refines its permission system even further. One of the major distinctions between Deno and Node.js is how they handle permissions. In Node.js, your code can access the file system, environment variables, or network without any restrictions, posing potential security risks, particularly in cloud environments. Deno, on the other hand, requires explicit permission for each of these actions. When running a Deno program, developers must specify flags to allow access to specific resources.

For example, if your code needs to make an API call over the network, you need to provide the `--allow-net` flag:

```bash
deno run --allow-net app.ts

```

Similarly, file read or write permissions need to be granted through the `--allow-read` and `--allow-write` flags:

```bash
deno run --allow-read --allow-write app.ts

```

In Deno 2.0, the permission system is more refined. The error handling has been updated, introducing the `Deno.errors.Notcapable` error. This makes it easier to distinguish between Deno-specific permission issues and those originating from the operating system, providing clearer feedback for debugging. Additionally, certain permissions, such as those for accessing the main module (`Deno.mainModule`), have been relaxed.

## 2. Native TypeScript support

Deno's built-in TypeScript support continues to be a core strength, eliminating the need for additional tools like Babel or `ts-node`. This direct support simplifies the development process, allowing developers to write TypeScript without worrying about setup or transpiling configurations. For TypeScript-heavy projects, this is a key advantage.

Fo example, you can write TypeScript code directly in Deno:

```tsx
const message: string = 'Hello from Deno!'
console.log(message)

```

In JavaScript, you would need to transpile this code to run it in Node.js. However, Deno can execute TypeScript code directly.

In addition, Deno 2.0 ships with TypeScript 5.6, giving you the latest TypeScript features. This out-of-the-box TypeScript support is one of Deno's primary differentiators from Node.js, which requires more complex setups to achieve the same functionality.

## 3. Modern module system

Deno avoids the traditional `node_modules` directory by using a URL-based module system. Rather than relying on npm for package management, Deno imports dependencies directly from URLs. This modern approach minimizes the risk of dependency conflicts and reduces the overall size of the project, which is especially important for cloud deployments and serverless environments like those in Appwrite.

For example, importing a module in Deno looks like this:

```tsx
import { v4 } from 'https://deno.land/std@0.114.0/uuid/mod.ts'
console.log(v4.generate())

```

With Deno 2.0, dependency management has been further enhanced. New commands like `deno add` and `deno remove` simplify the process of managing dependencies. Additionally, Deno 2.0 introduces a new lockfile format (v4) that minimizes differences in dependency updates and ensures reproducible builds.

## 4. Node.js compatibility

One of the early challenges for Deno was its limited compatibility with Node.js modules. Deno 2.0 addresses this by introducing better support for Node.js's `process` global variable, allowing developers to run more Node.js code without modification. This is a major step forward for those looking to migrate projects from Node.js to Deno or work in environments that require both.

For example, using a Node.js module in Deno 2.0 is straightforward:

```tsx
console.log('Node.js Process:', process.env.APPWRITE_API_KEY)

```

Deno 2.0 also improves its support for CommonJS modules, which expands the range of Node.js libraries that can be used within Deno without significant rewrites.

## 5. Better dependency management

Deno 2.0 simplifies how developers manage dependencies. New tools like `deno add` allow you to add specific modules with ease, while `deno remove` can quickly remove unwanted dependencies. These commands streamline project workflows, making it easier to manage external libraries. Additionally, Deno 2.0's updated lockfile format ensures smaller diffs during updates and makes builds more consistent across environments.

## 6. Deprecation and removal of legacy commands

Deno 2.0 also removes or deprecates certain commands that developers found underwhelming in earlier versions. For instance, the `deno bundle` and `deno vendor` commands have been removed. These commands didn't provide the level of flexibility developers expected, and future versions of Deno are likely to introduce more powerful replacements.

## 7. Stabilization of APIs and WebGPU

Deno 2.0 has stabilized several key APIs, including the **WebGPU API**, which previously required an `unstable` flag. You can now use WebGPU and other previously unstable APIs in production without needing additional flags. This move toward stabilization reflects Deno's goal of providing a more mature and reliable development environment.

# Things to consider before starting your Deno project

While Deno 2.0 offers many advantages, there are still some challenges to consider:

1. **Smaller ecosystem**: Compared to Node.js, Deno has a smaller ecosystem. Node.js's npm ecosystem is large, with many mature packages. While Deno's Node.js compatibility is much better, it still doesn't match the number of libraries available on npm.
2. **Adoption**: Despite its potential, Deno is not yet as widely used as Node.js. Node.js has had a significant head start and will likely remain the dominant runtime for server-side JavaScript for now. This means fewer community resources, libraries, and tools will be available for Deno, compared to Node.js.
3. **Learning curve**: While this won't be a significant issue if you're already familiar with Node.js and TypeScript, using Deno for the first time may require some adjustment. The differences in module management, permissions, and runtime behavior can be challenging for newcomers.
4. **Migration**: Migrating existing Node.js projects to Deno can be complex, especially for large codebases with many dependencies. While Deno 2.0 has improved compatibility with Node.js, the process of migrating code can still be time-consuming and error-prone.

Despite these challenges, Deno 2.0 looks compelling and will likely gain more traction as more developers become familiar with its features and benefits.

# Appwrite's support for Deno 2.0

Deno 2.0 just released today, and Appwrite is already ahead of the curve in supporting it. In Appwrite, you can now build serverless functions using Deno, and take advantage of its security features, TypeScript support, and modern module management. To get started with Deno in Appwrite, head over to the [Appwrite console](https://cloud.appwrite.io/), and just like you would with Node.js when creating a new function, select Deno as the runtime.

We will release a detailed tutorial on building serverless functions with Deno and Appwrite soon, so stay connected with us on [Discord](https://appwrite.io/discord) and [Twitter](https://twitter.com/appwrite) to see it first.

# Conclusion

With Deno 2.0, the runtime is maturing rapidly and addressing many of the pain points that developers faced with Node.js and Deno 1.x. The improved security, TypeScript support, and modern module system make Deno a compelling choice for building server-side applications.

Further reading:

- [Appwrite Deno Documentation](https://appwrite.io/docs/quick-starts/deno)
- [Local serverless function development with the new Appwrite CLI](https://appwrite.io/blog/post/functions-local-development-guide)
- [Building a chat app with Appwrite and Google Gemini](https://www.appwrite.io/blog/post/build-a-chat-app-with-appwrite-and-gemini)
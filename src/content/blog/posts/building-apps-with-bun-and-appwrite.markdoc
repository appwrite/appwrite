---
layout: post
title: Building apps with Bun and Appwrite
description: Learn what Bun really is, how it compares with Node.js and Deno, and how you can build apps with Bun and Appwrite.
date: 2024-01-10
cover: /images/blog/building-apps-with-bun-and-appwrite/bun-cover.avif
timeToRead: 9
author: aditya-oberai
category: tutorial
faqs:
  - question: "What is Bun and how is it different from Node.js?"
    answer: "Bun is an all-in-one JavaScript runtime and toolkit that includes a bundler, test runner, and Node.js-compatible package manager. It is written in Zig and aims to be significantly faster than Node.js for installing packages, running scripts, and serving HTTP traffic. Bun is also Node.js-compatible for most APIs, so existing projects can often migrate without major changes."
  - question: "Can I use Bun with Appwrite Functions?"
    answer: "Yes. Appwrite Functions supports Bun as a first-class runtime, so you can deploy Bun code and have it executed on demand or on a schedule. See [Appwrite Functions](/docs/products/functions) for runtime options and deployment details."
  - question: "Does Bun support TypeScript out of the box?"
    answer: "Yes. Bun has native TypeScript support, which means you can run `.ts` and `.tsx` files directly without a separate build step. It also handles `.js`, `.cjs`, `.mjs`, and `.jsx` files, which makes it easy to mix and match across an existing project."
  - question: "Why is Bun's package manager faster than npm?"
    answer: "Bun skips a few network checks that npm performs by default, caches aggressively, and uses optimizations like labeled pointer lists to reduce overhead. The `@latest` tag is effectively ignored on install, which avoids an extra round trip. Many teams adopt Bun only for `bun install` while keeping Node.js as their runtime."
  - question: "How do I connect a Bun app to Appwrite?"
    answer: "Install the Appwrite Node.js SDK with `bun add node-appwrite`, then initialize the client with your endpoint, project ID, and an API key. From there, you can call any Appwrite service the same way you would in Node.js. The [Appwrite Functions](/docs/products/functions) docs cover server SDK usage in detail."
  - question: "Should I switch all my projects from Node.js to Bun?"
    answer: "Not necessarily. Bun is production-ready since 1.0, but some Node.js libraries still hit edge cases under Bun. A safe path is to start with greenfield projects or scripts, validate the performance gains, then migrate larger services once you trust the runtime in your environment."
---

If you are a developer, your definition of `bun` must have recently changed. From what we knew to be a round piece of bread, it is now a new runtime in JavaScript, and as Bun claims, it is faster than the rest! In this article, we will find out what Bun really is, how it compares with Node.js and Deno, and how you can build apps with Bun and Appwrite.

# What is Bun?

Bun is an all-in-one JavaScript runtime & toolkit designed for speed, complete with a bundler, [test runner](https://bun.sh/docs/cli/test), and Node.js-compatible [package manager](https://bun.sh/package-manager). 
To install Bun, follow the instructions [here](https://bun.sh/).
It is built from scratch to serve the modern JavaScript ecosystem. It has three major design goals:

- **Speed**
- **Elegant APIs**
- **Cohesive Developer Experience**

You may think, what is all the hype about? Building with JavaScript is now faster with Bun, which is production-ready with its latest version 1.0 release.

Bun lets you read environment variables from a **`.env`** file and utilize the familiar **`fetch()`** method, enabling you to access and handle data from external sources within your application.

Bun also natively supports TypeScript out of the box. Other than that, it also supports `.js`, `.cjs`, `.mjs`, `.jsx`, and `.tsx` files.

*Bonus: Bun package manager is known for its speed, offering faster package management as one of its key features. Even if you don't use Bun as a runtime, you can use Bun's built-in package manager, that can significantly speed up your development workflow.* 

Try it for yourself:

```bash
bun install
bun add <package> [--dev|--production|--peer]
bun remove <package>
bun update <package>
```

The powers of Bun are endless. If you are interested to know more about the capabilities, read the [release announcement](https://bun.sh/blog/bun-v1.0).

**How is Bun so fast?**

> Bun made some bold decisions to make this happen! It is not because they are using Zig or not using V8 nor because it's machine code. It is because of the mindset to make everything as highly performant as possible.
> 

Other than that, there are things like: While installing a package, Bun doesn’t do a network check to see if it is downloading in the latest version. `@latest` tags are effectively ignored in Bun. 

Also, making small adjustments, such as creating a list of labeled pointers instead of keeping the function pointers separately, greatly improved the speed.

# Introduction to Appwrite

Explore the capabilities of Bun with [Appwrite](https://appwrite.io/) - a backend platform to help you minimize time to create value. Appwrite abstracts away the complexities and repetitiveness of building a modern application so you can jump straight to the fun parts, building impactful features. 

Using Appwrite, you can quickly deploy and scale your Bun/JavaScript code base with ease. To achieve that, you can use Appwrite Functions.

Appwrite Functions is available in multiple languages and runtimes (and Bun is one of them). This gives developers the ability to plug and play with Appwrite in the language of their choice.

![Bun start](/images/blog/building-apps-with-bun-and-appwrite/bun-start.avif)

Appwrite functions are capable of doing more. In our latest release, we have added `Function templates`, which means you’ll be able to add Functions engineered by the Appwrite team and community to your Appwrite project. Functions also have their own `domain`, either custom or generated by Appwrite. This lets you write Appwrite Functions that act like typical REST endpoints to handle webhooks, custom integrations, or even serve HTML content. Appwrite Functions will now also fit into your existing workflow right alongside the rest of your code as you can deploy them directly from `Git`. 

# Integrating Bun in Appwrite

Now that you have been introduced to Bun and Appwrite, it is time to test them out! To use Bun in Appwrite, you need to use the latest version of Appwrite. 

Installing a self-hosted version of Appwrite is pretty straight-forward, all you need to do is:

1. Have Docker installed
2. Run the command:

```bash
"\t\t\t\t\tcontent: 'docker run -it --rm \\\n" +
    --volume /var/run/docker.sock:/var/run/docker.sock \
    --volume "$(pwd)"/appwrite:/usr/src/code/appwrite:rw \
    --entrypoint="install" \
    appwrite/appwrite:1.8.1
```

For one-click setups, check out the [installation docs](https://appwrite.io/docs/self-hosting). 

To use Bun in Appwrite, you need to add it to `_APP_FUNCTIONS_RUNTIMES` in the `.env` file and restart your Appwrite instance with `docker compose up -d`.

![Bun functions](/images/blog/building-apps-with-bun-and-appwrite/bun-functions.avif)

Next, go ahead and create a Bun function using the Appwrite CLI by running appwrite init function. 

Now that your function is set up, we can try some examples:

**Example 1:** 

Bun aims to provide a consistent and predictable module resolution system that just works. The specialty of Bun is that you can use `import` or `require` in the same file; they both work all the time.

Use the following code in your function to test the above statement:

```jsx
import lodash from "lodash";
const _ = require("underscore");

export default async ({ res }) => {
  const numbers = [5, 5, 2, 5, 7];

  return res.json({
    withLodash: lodash.without(numbers, 5),
    withUnderscore: _.without(numbers, 5),
  });
};
```

The output will be:

![Bun output](/images/blog/building-apps-with-bun-and-appwrite/bun-output.avif)

**Example 2:**

Bun supports `.jsx` and `.tsx` files out of the box. Bun's internal transpiler converts JSX syntax into vanilla JavaScript before execution.

Let us test with the following code: 

```jsx
import { renderToString } from "react-dom/server";

export default async ({ res }) => {
  const html = (
    <>
      <h1>Hello World!</h1>
      <p>Current time is {new Date().toLocaleTimeString()}</p>
    </>
  );

  return res.text(renderToString(html), 200, {
    "Content-Type": "text/html",
  });
};
```

*Note:  For the code to work, you need to run `bun install react react-dom`*

When the function successfully executes, you will get the following output:

![Bun output two](/images/blog/building-apps-with-bun-and-appwrite/bun-output-two.avif)

# Takeaways

In this article, we learned about Bun and using it with Appwrite. While we support Node.js, Deno, and Bun within Appwrite, it is up to you to choose which one you want to build with. 

While comparing the performance of these three runtimes with Appwrite, we have come up with some interesting results:

- Build time - Bun is almost 3x faster than Node.js when installing un-cached dependencies for a Next.js project
- Cold-start - Bun is almost 2x faster than Node.js, and 3x faster than Deno
- Warm-start - All runtimes in similar ranges with differences ranging between 1-3ms
- Load testing - All in similar ranges, with Bun and Node.js being the most stable

> Benchmarks above are related to runtime used in Appwrite Functions.
> 

Here are some resources to get started:

- [Appwrite Function Docs](https://appwrite.io/docs/functions)
- [Bun Docs](https://bun.sh/docs)
- [Appwrite Discord](https://discord.com/invite/appwrite)

With the benchmarks shared above and having the flexibility within Appwrite to build with any runtime, what would be your choice? 

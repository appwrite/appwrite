---
layout: post
title: Why you need to try the new Bun function runtime
description: Why Bun's features are perfect for building Appwrite Functions.
date: 2024-03-01
cover: /images/blog/why-you-need-to-try-the-new-bun-runtime/cover.avif
timeToRead: 15
author: vincent-ge
category: product
featured: false
faqs:
  - question: "What is Bun and how is it different from Node.js?"
    answer: "Bun is a JavaScript runtime, package manager, bundler, and test runner in a single binary. It runs JavaScript and TypeScript natively, implements many web standards (fetch, FormData, WebSocket), and is built around speed. Node.js is a runtime only, with separate tools for the rest of the stack."
  - question: "Why use Bun as an Appwrite Function runtime?"
    answer: "Bun cuts deploy and build time significantly because it installs dependencies and runs TypeScript without a separate transpile step. For Appwrite Functions, that means faster iteration loops when you deploy and test code multiple times an hour. See [Appwrite Functions](/docs/products/functions) for the full runtime list."
  - question: "Does Bun support all npm packages?"
    answer: "Most popular npm packages work with Bun, especially anything that targets standard JavaScript. Some native modules and Node-specific APIs may still have edge cases, so test critical dependencies before committing to Bun for a production workload."
  - question: "Do I need to rewrite my Node.js code to run on Bun?"
    answer: "Usually not. Bun aims for Node.js compatibility for most common APIs, so existing handlers, fetch calls, and TypeScript code often run unchanged. Edge cases tend to involve native bindings or very specific Node internals."
  - question: "Can I write Appwrite Functions in TypeScript with Bun?"
    answer: "Yes. Bun runs TypeScript natively, so you do not need a separate tsc step or a populated dist folder. You can deploy your .ts source directly to an Appwrite Function and Bun handles the rest."
  - question: "Is Bun production-ready?"
    answer: "Bun reached 1.0 in late 2023 and is now used in production by teams running APIs, scripts, and serverless functions. As with any newer runtime, validate your specific dependencies and workload patterns, but the core runtime has been stable for long enough to trust for most use cases."
---

When Bun announced their 1.0 release, marking Bun stable and production-ready, we excitedly began working on a Bun runtime for [Appwrite Functions](/docs/products/functions).

We loved the idea of Bun, because it’s more than just another Node runtime but a whole set of tools. Bun does everything from dependency management to testing and beyond with consistent DX and light-speed performance. This idea of being simple, reducing distractions, and giving developers all the tools in one place to just build stuff resonated with Appwrite’s product philosophy.

Here’s a small selection of cool features that make it particularly useful as an Appwrite Function runtime.

# Dependency management

Bun has its own [dependency management tool](https://bun.sh/package-manager), which is just `bun install`. You can still use `npm` or `yarn` or `pnpm`, but we like the idea that Bun just gives you something so you don’t need to make one more decision.

Bun is also fast, like really fast. I ran package installs on fresh containers for a `create svelte@latest` project, here are the numbers.

Good ol’ NPM.

```bash
$ npm create svelte@latest my-app-node
$ cd my-app-node && npm install

npm install

added 111 packages, and audited 112 packages in 6s

11 packages are looking for funding
    run `npm fund` for details

found 0 vulnerabilities
```

Bun install.

```bash
$ bun create svelte@latest my-app-bun
$ cd my-app-bun && bun install

bun install v1.0.23 (83f2432d)

    + @fontsource/fira-mono@4.5.10 (v5.0.8 available)
    + @neoconfetti/svelte@1.0.0 (v2.2.1 available)
    + @sveltejs/adapter-auto@3.1.0
    + @sveltejs/kit@2.3.3
    + @sveltejs/vite-plugin-svelte@3.0.1
    + svelte@4.2.8
    + svelte-check@3.6.3
    + typescript@5.3.3
    + vite@5.0.11

warn: @sveltejs/kit's postinstall script took 1.2s

    111 packages installed [1336.00ms]
```

This is really important to Appwrite Function developers during development because it shaves off seconds of time each time you deploy code to Appwrite Cloud. These seconds add up to minutes if you’re deploying and testing code 10 times in an hour. Try `bun install` on your projects and see how much time it can save for you.

# Typescript out of the box

You don’t need to run `tsc` to transpile Typescript using Bun. This is convenient, saves time, and avoids a messy `dist/` folder. It just works.

Again, in the context of an Appwrite Function, the build time you can save is pretty significant. Here are the deploy times for a simple typescript [Starter Function](https://github.com/appwrite/templates) template in Bun and Node.js

**Bun** build time

![Bun build time](/images/blog/why-you-need-to-try-the-new-bun-runtime/bun-buildtime.avif)

**Typescript** build time

![Typescript build time](/images/blog/why-you-need-to-try-the-new-bun-runtime/ts-buildtime.avif)

# Implements standard Web APIs

Bun implements many web standards, like `fetch`, `FormData`, `WebSocket`, and other useful tools. This means you no longer need to install [node-fetch](https://www.npmjs.com/package/node-fetch), [formdata-node](https://www.npmjs.com/package/formdata-node), and dozens of other libraries in your projects just to do simple things like making an HTTP request.

For example, I can write this Appwrite Function to fetch random Capybara with 0 dependencies.

```js
export default async ({ req, res, log, error }) => {
    if(req.method !== 'GET') {
        return res.text('Not found', 404);
    }

    const response = await fetch(`https://api.giphy.com/v1/gifs/random?api_key=${process.env["GIPHY_API_KEY"]}&tag=capybara`);
    const data = await response.json();
    const gifUrl = data.data.images.fixed_height.url;
    return res.json({
        capybara: gifUrl,
        message: 'Capybara of the day!'
    });
};
```

Fewer dependencies, fewer decisions to make, and more focus on building cool stuff.

**JSX out of the box**

Bun will happily let you mix `tsx` and `jsx` files into your code. Bun will just transpile your [JSX into vanilla JS before execution](https://bun.sh/docs/runtime/jsx).

This is perfect for Appwrite Functions! Appwrite Functions exposes HTTPS endpoints for each function, which lets you build simple web forms for collecting feedback, gathering contact information, or displaying a random GIF of a capybara.

Write some simple JSX and render it to a readable stream with `react-dom`.

Then we can return this in our Appwrite Function.

```jsx
import { renderToReadableStream } from "react-dom/server";

const styles = `
body {
    font-family: sans-serif;
    text-align: center;
}
div {
    max-width: 600px;
    margin: 0 auto;
}
img {
    width: 100%;
    height: auto;
}`;

const GIPHY_API = "https://api.giphy.com/v1/gifs/random";

export async function fetchGif(tag: string) {
    const response = await fetch(
    `${GIPHY_API}?api_key=${process.env.GIPHY_API_KEY}&tag=${tag}`
    );
    const { data } = (await response.json()) as any;
    return data.images.fixed_height.url;
}

export default async function handler({ req, res, log, error }: any) {
    if (!process.env.GIPHY_API_KEY) {
    throw new Error("GIPHY_API_KEY is not set.");
    }
    if (req.method !== "GET") {
    return res.json(
        {
        message: "Method not allowed.",
        },
        405
    );
    }

    const gifUrl = await fetchGif("Cute capybara");

    // We're returning JSX!
    const html = await renderToReadableStream(
    <>
        <style dangerouslySetInnerHTML={{__html: styles }}/>
        <div>
        <img src={gifUrl} alt="Random capybara" /> 
        <h1>Capybara of the day!</h1>
        </div>
    </>
    );

    return res.text(html, 200, {
        "Content-Type": "text/html; charset=utf-8",
    });
}
```

You can get your daily Capybara GIF served straight from a function: [https://65a6fc2c08488a5e7b0d.appwrite.global/](https://65a6fc2c08488a5e7b0d.appwrite.global/)

# Run tests

Bun comes packaged with a test runner. Running `bun test` will find all files with format `*.test.*` and just run them. Simple as that.

It’s lightweight, no configuration is needed, and it is perfect for Appwrite Functions. For example, we can run tests to check if the API key we configured is valid and see if the `getGif` utility function works. The test runner is lightning fast an we can run them as a part of the Appwrite Function’s build process.

For example, we can define these tests.

```js
import { describe, expect, test, beforeAll } from "bun:test";
// import some files to test
import { getGif } from "../src/getGif";

describe("getGifs", () => {
    test("API key exists", () => {
    expect('GIPHY_API_KEY' in process.env).toBe(true);
    expect(typeof process.env['GIPHY_API_KEY']).toBe('string');
    });

    test("getGif returns a valid URL", async () => {
    const gifUrl = await getGif(process.env['GIPHY_API_KEY']);
    expect(gifUrl).toMatch(/^(http|https):\/\/[^ "]+$/);
    });

    // Sanity check to make sure it will make my builds fail.
    // test("This will always fail", async () => {
    //   expect(true).toBe(false);
    // });
});
```

Each time we deploy, we can configure Appwrite Functions to run the tests after build. For this example, we use the build commands:

```bash
bun install
bun test
```

This will install dependencies and run tests before activating a deployment.

```bash
Preparing for build ...
Building ...
bun install v1.0.0 (822a00c4)
Resolving dependencies
Resolved, downloaded and extracted [2]
Saved lockfile

- @types/react-dom@18.2.18
- prettier@3.2.2
- node-appwrite@latest
- react@18.2.0
- react-dom@18.2.0

21 packages installed [284.00ms]
bun test v1.0.0 (822a00c4)

test/capy.test.ts:
[0m[32m(pass)[0m getGifs > API key exists [0.12ms]
[0m[32m(pass)[0m getGifs > getGif returns a valid URL [155.40ms]

2 pass
0 fail
3 expect() calls
Ran 2 tests across 1 files. [167.00ms]
Packing build ...
Build finished.
```

If the tests don’t pass, the build will not be deployed on Appwrite Functions.

# Performance

Bun is fast. In most cases, it is noticeably faster than Node.js. You can find a lot of great benchmarks online, like ["Is Bun really much faster than Node.js?" by Mayank Choubey](https://medium.com/deno-the-complete-reference/is-bun-really-much-faster-than-node-js-e5b15942a8e8). This is largely because Bun uses **[Zig](https://ziglang.org/)**, a fast and robust programming language. It’s been covered a lot so that we won’t go into detail here.

This increased performance is particularly noticeable when you write long-running Appwrite Functions, especially those that are I/O bound and handle lots of requests. Developers often choose Appwrite Functions as a way to extend Appwrite with custom endpoints, build integrations, and implement server-side logic. This performance increase is particularly attractive.

# More to explore

There’s a lot to love about [Bun](https://bun.sh/), and we only explored a few exciting differences that can be leveraged in the Bun Appwrite Function runtime. Bun also has a built-in web server, high-performance file I/O, snapshot testing, and much more to offer.

If you’re curious, you can also try Bun in an Appwrite Function by creating your first [Appwrite Cloud](https://cloud.appwrite.io/) project. The free plan gives you access to three Functions, which lets you compare and contrast Node.js, Deno, and Bun in identical containerized environments.

You can try your first Bun, Node.js, or Deno function on Appwrite Cloud without writing a single line of code through Appwrite Function templates. Appwrite will clone template functions to your GitHub profile and set up automatic deployments so you can start building immediately.

# Resources

Visit our documentation to learn more about the Bun, join us on Discord to be part of the discussion, view our blog and YouTube channel, or visit our GitHub repository to see our open-source code.

- [Docs](/docs/products/functions/runtimes)
- [Discord](https://appwrite.io/discord)
- [Blog](/blog)
- [YouTube](https://www.youtube.com/channel/UCtBJ1v69gm8NgbCju_03Fiw)
- [GitHub](https://github.com/appwrite/appwrite)

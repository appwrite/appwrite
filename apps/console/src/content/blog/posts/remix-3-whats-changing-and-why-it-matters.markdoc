---
layout: post
title: "Remix 3: what's changing and why it matters"
description: "A developer-focused deep dive into Remix 3's principles, architecture, and why it moves beyond React toward web standards."
date: 2025-11-18
cover: /images/blog/remix-3-whats-changing-and-why-it-matters/cover.avif
timeToRead: 8
author: ebenezer-don
category: news
faqs:
  - question: "Does Remix 3 still use React?"
    answer: "No. Remix 3 drops React entirely and ships its own component model built on web standards. You still write JSX, but there is no virtual DOM and updates are explicit instead of triggered by hooks like `useState`."
  - question: "What runtimes will Remix 3 support?"
    answer: "Remix 3 runs on the Fetch API using standard `Request` and `Response` objects, so it works on Node, Deno, Bun, and any other runtime that implements Fetch. There are no runtime-specific adapters required."
  - question: "What are Frames in Remix 3?"
    answer: "Frames are sections of a page that can refresh independently with a server-rendered HTML fragment. They give you partial updates without JSON APIs or client-side state libraries, similar to the model used by HTMX."
  - question: "Are loaders and actions still part of Remix 3?"
    answer: "Yes. Routes still export handlers for each HTTP method, with `GET` for loading data and `POST`, `PUT`, or `DELETE` for mutations. The framework re-runs the relevant loaders after a mutation so the UI stays in sync."
  - question: "When can I use Remix 3 in production?"
    answer: "The first stable release is targeted for early 2026. Previews shown at Remix Jam 2025 are usable for experiments, but the API is still moving and not recommended for production until the official release."
  - question: "How is Remix 3 different from Next.js?"
    answer: "Remix 3 is intentionally smaller and closer to web standards, with no React, no virtual DOM, and no build-time magic. Next.js leans into React Server Components and an opinionated build pipeline, while Remix 3 favors composable primitives that work on any JavaScript runtime."
---

For a while, it seemed like Remix had reached a natural end. By late 2024, almost everything that made Remix unique, its loaders, actions, and nested routing, had been merged into React Router 7. The framework's creators, Ryan Florence and Michael Jackson, even joked that Remix 2 had become "React Router, the Framework." Many developers assumed Remix would fade away.

But in May 2025, the team [announced Remix 3](https://remix.run/blog/wake-up-remix), an unexpected rebuild from the ground up, meant to rethink how web apps are built. They showed early previews at [Remix Jam 2025](https://remix.run/blog/remix-jam-2025-recap), and while the first version is targeted for early 2026, the goals are already clear. Remix 3 breaks away from React entirely and rebuilds around web standards.

It's a big step. The framework now runs directly on the Fetch API (using standard `Request` and `Response` objects), instead of Node's proprietary `req` and `res` system. This means it can run on any JavaScript runtime; Node, Deno, or Bun, without adapters. It still supports the same patterns developers liked in Remix 2, but without depending on React's lifecycle or its virtual DOM. The team calls this a move toward being "closer to the web itself."

# Why the Remix team chose to rebuild in version 3

When Remix started, React was the natural foundation. Its component model, rendering flow, and community gave Remix an instant head start. But over time, that foundation began to limit what the team could do.

By 2024, the React ecosystem had grown complex. Developers had to manage new patterns like Server Components, `use server` and `use client` boundaries, Suspense, and a flood of new hooks. Next.js, the most visible React framework, kept evolving quickly, but often at the cost of simplicity.

Remix 2, meanwhile, had thinned out. Most of its core logic had moved into React Router, and the extra layer Remix added on top didn't feel worth maintaining. The team decided to stop chasing React's roadmap and instead design something simpler that would work the same way anywhere JavaScript runs.

This rebuild also reflects a return to the web's own strengths. Browsers already know how to handle navigation, forms, and requests. The Fetch API, URL, Request, and Response objects are now available on both the server and client. Remix 3 builds directly on these features instead of wrapping them in extra abstractions. The goal is to make web development less about "framework rules" and more about the platform that's already there.

# The six principles guiding Remix 3

The team described six principles that define Remix 3's direction:

1. Model-first development. Write code that's easy for both people and tools to understand. Each route's intent should be clear about what data it loads, what actions it performs, and how it fits into the overall app. This makes it easier to maintain and to reason about, whether by a developer or an AI tool assisting with code.
2. Build on web APIs. Use standards like Fetch, Request, Response, and FormData directly. This means a Remix 3 app written for Node can also run on Deno or other JavaScript runtimes without code changes.
3. Religious about runtime. Avoid build-time "magic." Features should work at runtime, without a custom bundler or code generator. You can still use TypeScript or JSX, but what you write is close to what actually runs.
4. Avoid heavy dependencies. Remix 3 aims to depend on as little third-party code as possible. Instead of relying on frameworks like React or Express, it implements small, focused modules that the team can control and evolve.
5. Demand composition. Every piece of Remix 3 is built to work on its own. The router, server utilities, and data parsers can each be used separately or as part of the full framework. This modular philosophy is similar to [TanStack](https://tanstack.com/), where each tool is independently useful but works well together.
6. Distribute as one. Even though it's modular inside, Remix 3 will ship as a single package. Developers won't need to assemble ten different pieces; the framework will present a unified API.

Together, these principles explain why Remix 3 looks so different. The team wants a system that's modular and flexible internally, but simple and predictable on the surface.

# A new architecture built on web standards

Remix 3's architecture centers on the Fetch API. Every incoming request, whether from a browser, a test, or an edge worker, is a standard `Request` object. Every response the framework sends is a `Response` object.

At the heart of the system is a new router called `@remix-run/fetch-router`. It's type-safe, explicit, and doesn't depend on file naming conventions. You define routes in code, often in a `routes.ts` file, where each route has a name and a path. For example:

```ts
export const routes = route({
  books: {
    index: "/books",
    show: "/books/:slug"
  }
});
```

From there, Remix can infer types and generate helpers like `routes.books.show.href({ slug: "gatsby" })`. This keeps links type-safe and prevents mistakes. It also lets the router generate correct URLs for any environment.

# The new rendering model

The biggest change is that Remix 3 no longer uses React. You still write JSX, but it's handled by a custom component model built specifically for Remix. There's no virtual DOM. When something changes, you update state directly and call `this.update()` to refresh that part of the UI. Events use the browser's native event system instead of React's synthetic one.

This makes the rendering model closer to plain JavaScript. Developers can see exactly what's happening without guessing at hidden framework behavior. The trade-off is that you need to be more deliberate about updates, there's no `useState` hook to trigger automatic re-renders. But it also means fewer performance surprises and much less overhead.

To handle partial updates, Remix 3 introduces a feature called Frames. A Frame is a section of the page that can load and update independently. When a Frame refreshes, the server sends back a small HTML fragment, and Remix patches it into the DOM. This allows streaming updates without extra JavaScript or JSON APIs. This approach is similar to [HTMX](https://htmx.org/), where the server sends HTML instead of JSON, keeping the logic server-side while maintaining interactivity.

For example, a comments section could live inside a Frame. When a user submits a new comment, the server renders updated HTML for that frame and sends it down. The rest of the page stays untouched. It feels like client-side interactivity, but it's still server-driven and progressively enhanced.

# Data loading and actions

The loader and action pattern that defined Remix 2 is still here. Each route can export handlers for different HTTP methods. A `GET` handler fetches data to render; a `POST` (or `PUT`, `DELETE`) handler performs a mutation.

Here's a simple example:

```ts
export const handlers = {
  async GET({ params }) {
    const post = await getPost(params.slug);
    if (!post) return render(<NotFound />, { status: 404 });
    return render(<PostPage post={post} />);
  },

  async POST({ request, params }) {
    const form = await request.formData();
    const comment = form.get("comment");
    await saveComment(params.slug, comment);
    return redirect(routes.posts.show.href({ slug: params.slug }));
  }
};
```

In this setup, the `GET` handler runs on the server and renders HTML for the initial page. The `POST` handler processes a submitted `<form>`, updates data, and redirects. Remix then re-runs the necessary loaders to refresh the UI.

This is a key advantage: Remix runs JavaScript on the server but leverages existing browser capabilities on the client side. This makes apps more accessible to parsers, devices with limited JavaScript support, and browsers with JavaScript disabled. The browser handles POST and redirect using standard HTML forms, which works even without JavaScript.

This pattern keeps the app predictable. Data always comes from one place, the loader for that route, and every action leads to a clear revalidation path. No client-side fetching hooks or state libraries are needed.

# Error handling

Error handling in Remix 3 continues the route-based model from Remix 2. Each route can define its own error boundary so that failures stay local. If a child route fails to load or render, only that section shows an error message, while the rest of the page remains intact.

When a loader throws a `Response` with an error status, Remix automatically triggers the matching error boundary. For example, if a loader throws `new Response("Not found", { status: 404 })`, Remix will show the Not Found UI for that route but keep parent layouts mounted.

This approach prevents a single broken component from blanking the entire page (a common problem in many SPAs) and helps developers handle network or data errors cleanly.

# TypeScript and developer experience

Remix 3 is written in TypeScript from the start, so every route, loader, and action is strongly typed. Route parameters and return types are inferred automatically. For example, if `routes.books.show` has a `:slug` parameter, TypeScript will know that `params.slug` is a string inside its handlers. You can pair this with schema validation libraries like [Zod](https://zod.dev/) to validate form data and API inputs at runtime.

Because Remix avoids code generation, you don't need a separate typegen step. The framework infers everything from the code you already write. The same runtime-first philosophy also means you can run your app directly in `ts-node` during development, without waiting for a full build.

The development workflow is simpler, too. The [preview demos](https://remix.run/blog/remix-jam-2025-recap) show a single `remix dev` command that starts a local server, watches files, and reloads automatically, no webpack config or complex bundler setup required. While Remix 3 is still in preview, you can follow the [official blog](https://remix.run/blog) and [GitHub repository](https://github.com/remix-run/remix) for early access and updates.

# Deployment and environment support

Remix 3's platform independence is one of its biggest selling points. Since it's built on the Fetch API, the same app can run on Node, Deno, or Bun with almost no changes.

The router handles incoming requests as `Request` objects and produces `Response` objects, so deployment often comes down to a few lines:

```ts
import { createRequestListener } from "@remix-run/serve";
import { router } from "./router";

export default createRequestListener({ router });
```

That pattern works in Node, [Appwrite Sites](https://appwrite.io/sites), Netlify, Vercel or on edge workers. Because the framework doesn't rely on Node-specific APIs, there's nothing to rewrite when switching platforms.

This also makes Remix 3 easier to scale. You can deploy it as serverless functions, edge workers, or a long-running Node process depending on your needs. The framework doesn't force a specific host or vendor. That independence is deliberate; the team wants Remix 3 apps to run anywhere, without lock-in.

# Trade-offs and what to expect

Rebuilding a framework from scratch comes with trade-offs. Dropping React means losing direct access to its huge ecosystem. Libraries like Material UI or React Query won't plug in directly. Developers will either use plain web components, small JS libraries, or future Remix-specific packages.

There's also a learning curve. Without React's hooks or automatic rendering, you'll manage updates yourself. That's simpler in concept but different in habit. It's a shift from "declare and forget" to "update when needed."

Still, the potential benefits are clear: less complexity, less JavaScript on the client, and better performance out of the box. The approach brings Remix closer to the strengths of the web, fast first loads, resilient forms, and consistent server logic, while keeping the conveniences of a single framework.

# Looking ahead

Remix 3 is still in preview, but it's already one of the most interesting moves in web development right now. It reflects a broader shift in how developers think about the web: away from heavy client frameworks and back toward simplicity, standards, and server-driven logic.

For teams using Remix 2 or React Router 7 today, nothing breaks overnight. Those tools remain stable and supported. Remix 3 is the next step, a framework rebuilt on what the web already does well.

As development continues through 2025 and 2026, the framework will gain polish and documentation. When it reaches a stable release, developers can expect a system that feels lighter, easier to deploy, and closer to the way the web naturally works.

Remix 3 isn't finished, but its direction is clear: a return to fundamentals, built for a web that doesn't need to be reinvented.

---
layout: post
title: Client vs Server Components in React
description: Learn how to choose between client and server components in React.
date: 2025-09-10
cover: /images/blog/client-vs-server-components-react/cover.avif
timeToRead: 5
author: atharva
category: tutorial
faqs:
  - question: "What is the difference between client and server components in React?"
    answer: "Client components run on the user's browser using JavaScript and can use hooks like `useState`, `useEffect`, and browser APIs. Server components run on the server, return HTML, and have no client-side JavaScript by default. Server components are great for data fetching and SEO; client components are needed wherever you have interactivity."
  - question: "When should I use server components?"
    answer: "Use server components for pages that fetch data, render markdown or static content, or need to be indexed by search engines. They reduce the JavaScript shipped to the browser, which speeds up the first load. Anything that needs `useState`, event handlers, or browser APIs should be a client component instead."
  - question: "Do I need to use server components if I'm using React?"
    answer: "No. Server components only exist in frameworks that support React Server Components (RSC), like Next.js App Router or React Router with the new server features. If your framework does not support RSC, every component is implicitly a client component and you do not need to mark them."
  - question: "How does `\"use client\"` work?"
    answer: "The `\"use client\"` directive at the top of a file tells the bundler that the file should run in the browser, not on the server. All components and imports inside that file become client-side. You only need it in RSC-enabled frameworks; everywhere else it has no effect."
  - question: "Can server components call Appwrite?"
    answer: "Yes. From a server component you can use the [Appwrite](/docs) server SDK with an API key to fetch data securely. Since the component runs on the server, the API key never reaches the browser. For user-scoped queries, pass the user session cookie to the SDK so permissions are respected."
  - question: "How do server components affect SEO?"
    answer: "Server components are SEO-friendly out of the box because the server returns fully rendered HTML, including metadata, so crawlers see the content immediately. Pure client-side React apps require the bundle to load before metadata is set, which can hurt indexing. If SEO matters, server components or SSR are the right default."
---

The world of React is evolving. It started as a library that compiles into browser-readable JS and has evolved into full-fledged frameworks built around it that also run on the server. Things that you need to know before using React have also changed.

This article will examine React's client and server components, how they differ, and when to use them.

# History of component types in React

For the longest time, React has only supported client components. Frameworks like Next.js picked it up and wrapped React around a server to support SSR (server-side rendering), allowing developers to fetch server data and then pass it down to the React tree for rendering. This was particularly good for SEO, as the page metadata was automatically determined on the server-side or during the build time. Search crawlers didn’t have to wait for React to set the page’s metadata.

Meanwhile, Meta (formerly Facebook), which primarily maintains React, adopted a different server architecture for its apps. It has been running its own version of a server runtime, which is built upon PHP and patched using its own magical logic. It also uses React on the front end. Meta developed its own internal architecture that efficiently fetched data and rendered React on the server.

Meta decided to implement this functionality into React, so they introduced RSC (React Server Components). These look like any normal React component, although they are rendered on the server, and the browser gets regular HTML rather than a JS script constructing the HTML. Popular frameworks such as Next.js and React Router are adopting this, and we will now understand the things you need to know before making a decision about whether to choose client components or server components.

# Client Components

These components are rendered purely on the client-side on the user's browser using JavaScript. When you build your app and create a JS bundle, it can be loaded into the user’s browser when the page is requested. When this happens:

- If SSR is being used, the server fetches data on the server-side and passes it to the client
- The browser starts to parse and execute the JS bundle
- The React virtual DOM gets initialized
- Any external data (from API calls or similar) is fetched, and any existing SSR data is immediately available
- Data starts appearing on the page

{% info title="Note on client components" %}
If you're using a framework that **doesn't** support RSC, you don't need to do anything out of the box to mark components as client components. However, if you're using a framework that **does** support RSC, you need to have `"use client";` directive at the top of the file.
{% /info %}

## Advantages of using client components

- It lets you have a lot of client-side DOM interactivity since the component is mounted on the client side on the user’s browser.
- For a pure client-side app with no SSR, you don't need a server for data fetching, which reduces the server load and runs the code on the client side instead.
- Client components are usually very handy if you want to quickly show a loading screen to the user without waiting for the server to fetch data and then sending it to the browser, as in RSC.
- It allows you to access client-side state easily, such as LocalStorage, and state from state managers like Zustand and Redux.
- It has been around for a while, so massive community support is available, and many libraries are built for client-side usage.

## Disadvantages of using client components

- For complex apps, bundle size could get very high and cause the first load of the page to be extremely slow. In this case, you'd typically want a setup where you use client components nested inside server components.
- SEO could be a massive problem for non-SSR setups as page metadata isn't available until the React bundle is loaded.

## Example code snippet

The following is an example of a client component.

```jsx
"use client";

import { useState, useEffect } from "react";

export default function Component() {
	const [response, setResponse] = useState();
	
	async function fetchName() {
		const resp = await fetch("<SOME_API>");
		const data = await resp.json();
		setResponse(data);
	}
	
	useEffect(() => {
		fetchName();
	}, [])

	return <div>{response?.name || "Loading..."}</div>
}
```

In a traditional client component, if you want to fetch from an API on page load, you have to use the `useEffect()` hook, triggering a fetch request to your API and re-rendering the page to show the results.

# Server Components

These components are rendered purely on the server-side. The browser gets the final HTML with the data embedded into it. If done correctly, this sometimes might result in ZERO JavaScript being shipped to the browser. This also means that your data will be fetched on the server, and since this fetching is done on a *server*, you can use database calls safely directly inside a server component. RSCs are asynchronous, so your components must be `async`. When the user visits a page using RSC:

- The server fetches all the required data by calling APIs, database calls, etc.
- The server renders the React code into plain HTML
- If the RSC contains any client components nested in it, it sends instructions to the browser to render them
- First page load happens when the data immediately appears without loading screens

Different frameworks use RSC differently. For example, in Next.js, server components are the default unless explicitly defined otherwise using a directive that tells Next.js that the component is a client component and must be included in the client bundle. 

Developers must be careful about this, as making database calls in client-side components will most definitely leak their database credentials, which is not ideal. To counter this, different frameworks have different ways to manage secrets. For example, in Next.js, any environment variable that clients can access should have the `NEXT_PUBLIC_` prefix. If not, it will be a server environment variable, and accessing it on the client side will return `undefined`, saving your database credentials from leaking.

Also note that client-side interactivity is not possible in server components. So any hooks like `useState()`, `useEffect()`, etc., are not allowed to be used in server components.

## Advantages of using server components

- Less loading screens: data is fetched from the server, so you can skip showing any loading screens
- Better for SEO as you can set page metadata on the server
- You can make sensitive API calls or database calls directly from server components safely
- It can help reduce bloat by only making components that need interactivity marked as client components

## Disadvantages of using server components

- Zero interactivity. Since no JS is shipped to the browser for these components, you cannot set even basic events like `onClick`.
- Cannot use hooks. Hooks are integral to the React ecosystem, but you cannot run them on the server.
- Sometimes, using server components could backfire severely if you’re not fetching data responsibly. If your API or database is too slow, your page will take ages to load. This can be solved using something like PPR (partial pre-rendering) in Next.js, where you can use suspense boundaries to indicate which parts of your server components are loading. Data will eventually be streamed in from the server without using a lot of JS.
- Since it is relatively new, few developers have adopted it, so community support and supported packages might be lower comparatively.

## Example code snippet

The following is an example of a server component.

```jsx
export default async function Component() {
	const response = await fetch("<SOME_API>");
	const data = await response.json();

	return <div>{data.name}</div>
}
```

A server component cannot use hooks and is asynchronous, so you can directly run fetch calls and show the response without any additional re-renders. The rendered HTML will be sent to the browser directly.

# When to use client & server components?

If you need a lot of browser interactivity, use **client components**. 

If you need to show a very generic component with data and no browser interactivity, use **server components** and PPR if your data source is slow.

However, you will implement a mix of both by nesting client components inside server components. The best way to use server components is to find the best mix by fetching only the most crucial data on the server that the user needs to see immediately when the page loads. Everything else can be fetched on the client-side.

Or you can stick with just using client components + SSR if you don't want to participate in this complexity. Server components don't make client components outdated. There are use cases that might be well-suited to using a combination of client components + SSR.

# Conclusion

This article covered the history of components in React, client components, and server components, as well as when to use these types of components. 

Appwrite provides frontend hosting (and much more) with full SSR and RSC support. If you want to deploy your frontend seamlessly, feel free to check out [Appwrite Sites](/sites).

Read more about Appwrite Sites:

- [How to setup the Next.js starter template on Appwrite Sites](/blog/post/nextjs-starter-sites)
- [Announcing Appwrite Sites: The open source Vercel alternative](/blog/post/announcing-appwrite-sites)
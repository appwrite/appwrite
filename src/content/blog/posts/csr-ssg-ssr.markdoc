---
layout: post
title: "CSR vs SSG vs SSR: what they are and how to choose"
description: Choosing how your web app renders content, on the client, at build time, or on the server, can have a big impact on performance and user experience. In this post, we'll break down CSR, SSG, and SSR to help you understand how they work and when to use each one effectively.
date: 2025-05-19
cover: /images/blog/csr-ssg-ssr.avif
timeToRead: 10
author: ebenezer-don
category: product,init
featured: true
callToAction: true
faqs:
  - question: "What is the difference between CSR, SSG, and SSR?"
    answer: "CSR (client-side rendering) builds the page in the browser after the initial load. SSG (static site generation) builds pages at build time and serves them as static files. SSR (server-side rendering) builds pages on the server on every request. The main differences are when HTML is generated and where the work happens."
  - question: "Which rendering strategy is best for SEO?"
    answer: "SSG and SSR are both strong for SEO because they send fully formed HTML to crawlers. CSR is the weakest for SEO, since search engines may not reliably execute the JavaScript needed to see your content. If SEO matters, prefer SSG for static content and SSR for dynamic content."
  - question: "When should I use SSG instead of SSR?"
    answer: "Use SSG when content does not change per request and updates infrequently, like blogs, documentation, or marketing pages. Use SSR when content is personalized, depends on the current user, or needs to be fresh on every visit, like dashboards or product listings with live inventory."
  - question: "Can I mix CSR, SSG, and SSR in the same app?"
    answer: "Yes. Modern frameworks like Next.js, Nuxt, and SvelteKit let you pick a rendering strategy per page or per route. A typical setup uses SSG for marketing pages, SSR for product pages, and CSR for authenticated dashboards."
  - question: "How does Appwrite work with different rendering strategies?"
    answer: "Appwrite supports all three. Use the [web SDK](/docs/sdks) for CSR apps. For SSR, use a server SDK with session cookies so you can authenticate users on the server. [Appwrite Auth](/docs/products/auth) supports both client-side sessions and server-side session secrets."
  - question: "Is SSR slower than CSR?"
    answer: "SSR has a higher initial server cost because the server renders HTML for every request. But the time to first meaningful paint is usually faster than CSR because the browser does not have to download and run JavaScript before seeing content. Caching and edge rendering close most of the gap."
---

In modern web development, especially when using frameworks like React, Next.js, Vue, or Svelte, you'll come across terms like **CSR**, **SSG**, and **SSR**. These terms represent the fundamental decisions about how and when the content on your web page is generated and delivered to the user.

If you're trying to figure out which approach to use for your site, or you simply want to understand the strengths and trade-offs of each method, this guide is for you. We'll take a look at what each of these rendering strategies really means, and when it's worth using them.

# What does CSR, SSG, and SSR mean?

Let’s start with the basics. CSR, SSG, and SSR are different **rendering strategies**. Rendering in this context means turning code into something the user can actually see and interact with, like HTML, CSS, and JavaScript that appear in their browser.

The key difference between these approaches is **when** and **where** that HTML is generated.

- With **Client-side rendering (CSR)**, the content is built in the browser after the page loads.
- With **Static site generation (SSG)**, the content is built ahead of time during the app’s build process and served as static files.
- With **Server-side rendering (SSR)**, the content is built on the server every time someone visits the page.

Each of these options has strengths, weaknesses, and specific use cases where they shine. Let’s walk through each of them.

# Client-side rendering (CSR)

In CSR, your browser receives an almost empty HTML page. The real content arrives later through JavaScript, which fetches data and builds the interface. Libraries like React and Vue often default to this approach when you're not using a framework like Next.js or Nuxt.

You might notice this method more in apps that behave more like software than websites, like dashboards or productivity tools.

## Why would you use CSR?

CSR works well when you need instant navigation between pages in your site, and your content doesn’t need to be indexed by search engines or shared with immediate previews. It's often used for applications behind login walls,places where SEO doesn’t matter and interactivity is king.

It also helps keep your server load light. Since the browser is doing the heavy lifting, your server just serves static files.

## What should you watch out for?

The main downside is the **initial loading experience**. Because all the data fetching and rendering happens in the browser, users might have to wait a few milliseconds more (or sometimes seconds) before they see your app's content on initial load. Although this is becoming less of an issue as browsers improve, and it can be worth the trade-off for a more responsive and interactive experience.

Still, CSR can be **problematic for SEO**. Search engines are better than they used to be at crawling JavaScript, but it’s not always reliable or fast, and some content may be missed entirely. It's also not easy to share a preview of your site on social media or other platforms. This is because when crawlers see your site, they see a blank page and might not have the ability to render the JavaScript which would be needed to see important content like your page title, description and og:image tags.

# Static site generation (SSG)

Static site generation allows you to generate your entire site ahead of time, before any user visits it.

With SSG, your pages are built during the deployment process. For example, if you're building a blog, the HTML for each post is created when you run a build command. Then, when someone visits your site, the server (or CDN) just sends them a pre-built HTML file. It’s instant, SEO-friendly, and doesn’t require any computation at request time.

## When is SSG the right choice?

SSG is perfect for content that doesn’t change often. Documentation sites, blogs, landing pages, and marketing sites can benefit from this approach.

It's also a great way to **scale** your website. Since everything is pre-rendered, you can serve it from a content delivery network (CDN), which reduces server strain and improves loading times globally.

## What’s the catch?

The biggest limitation is that content can **become stale**. If your site changes frequently, or if it needs to show user-specific content (like a personalized dashboard), SSG alone won’t cut it. Some frameworks like Next.js offer features like *Incremental Static Regeneration* (ISR), which allow you to update individual pages without rebuilding the whole site, but it still doesn’t match the flexibility of full server-side rendering.

Also, for very large sites, build times can become long and complex to manage.

# Server-side rendering (SSR)

Now for the middle ground: Server-side rendering.

In this model, your pages are generated on the server every time someone visits. That means your app can always show the most up-to-date content, tailored to the individual user if needed.

Here’s how it works: A user requests a page, the server fetches data, builds the HTML, and sends it back. Only after that does the browser load and attach the JavaScript to make the page interactive (this process is called **hydration**).

## What are the benefits of SSR?

SSR, in a way, can be seen as a middle ground between CSR and SSG. You get **fresh, dynamic content**, which is great for things like news articles, product listings, or personalized content. You also get good **SEO**, because search engines receive a fully-formed HTML page.

And with modern frameworks, pages in an SSR app are pre-fetched on the server and cached for a short period of time, so the page is rendered very quickly when a user requests it. For example, if a user is on a page that includes links to other pages, the links will be rendered on the server and sent to the client so that when the user clicks on them, they don't have to wait for the page to load. This might not be as snappy as CSR, but it's still very fast.

SSR also works well when the page needs to reflect real-time data. Unlike SSG, there’s no delay between content updates and what users see.

## What are the trade-offs?

The most obvious downside is **performance under load**. Because the server has to render a page every time it's requested, it’s doing more work, especially when traffic spikes. This can make hosting more expensive or require caching strategies to keep things fast.

Also, SSR adds **infrastructure complexity**. You need a server or a serverless function that can handle your rendering logic, which means it might not always be as plug-and-play as static hosting.

# What about mixing them?

With modern frameworks, the good news is that you don’t have to pick just one.

Frameworks like **Next.js**, **Nuxt**, and **SvelteKit** support all three strategies, and let you choose per page.

For example:

- Use **SSG** for your homepage and documentation
- Use **SSR** for your blog’s comment section or product pages with inventory data
- Use **CSR** for user dashboards where real-time interactivity matters more than SEO

This hybrid approach lets you optimize performance, SEO, and content freshness based on what each page really needs.

# How do you decide what to use?

Ask yourself a few questions:

1. **Is your content public or behind a login?**
    - Public? SEO matters. Use SSG or SSR.
    - Private? CSR might be just fine.
2. **Does the content change often?**
    - Rarely? Use SSG.
    - Frequently? SSR allows you to get fresh data.
3. **Is SEO important?**
    - If yes, avoid pure CSR unless you supplement it with pre-rendering.
4. **How interactive is the page?**
    - For highly dynamic interfaces, CSR or SSR with client-side hydration is better.
5. **Do you want fast initial-load performance?**
    - SSG offers the best speed.
    - SSR can be fast with proper caching.
    - CSR may be slower unless you heavily optimize.

# Can you host SSR on Appwrite Sites?

Yes, Appwrite Sites supports server-side rendering (SSR), alongside client-side rendering (CSR) and static site generation (SSG). This means you can host dynamic apps that render content on the server in response to each request.

If you're migrating from another platform, all it usually takes is switching the adapter and updating your config files. Appwrite then handles the rest, from cloning your repo to building and deploying your app on a server runtime.

For a full walkthrough with framework-specific setup tips, check out [How to host SSR web apps on Appwrite Sites.](https://appwrite.io/blog/post/how-to-host-ssr-on-sites)

# Final thoughts

At the end of the day, CSR, SSG, and SSR are just different ways to solve the same problem: getting the right content to the user at the right time.

There’s no one-size-fits-all answer. Some sites need to have a very fast initial load and work well for SEO. Others need to show up-to-date data or user-specific content. And sometimes, especially in web apps where users are logged in and moving around a lot, CSR is all you really need.

What matters most is understanding how these strategies work, so you can make decisions that actually serve the people using your site.

And the great thing is, if you're using platforms like Appwrite Sites and hybrid frameworks, switching between these modes is pretty painless. You can pick what works for each page and adjust as your needs evolve.

So if you’re just starting out, don’t stress about choosing the “perfect” rendering mode. Start with the defaults of your framework, and learn when to override them based on what your app needs.

# More resources
- [Appwrite Sites docs](/docs/products/sites)
- [Appwrite Sites prodcuts page](/products/sites)
- [How to host SSR web apps on Appwrite Sites](blog/post/host-ssr-web-apps-sites)
- [SSR vs CSR with Next.js](/blog/post/csr-vs-ssr-with-nextjs)
- [Appwrite SSR Authentication](https://www.youtube.com/watch?v=7LN05c-ov_0)
- [Here's how you do auth with 100% SSR in Next.js](https://www.youtube.com/watch?v=ENnG7GusuO4)
- [Appwrite Discord server](/discord)

---
layout: post
title: " What is a content delivery network (CDN)?"
description: "Explore the basics of CDNs, their benefits, and why they're essential for fast and secure content delivery to websites and applications"
cover: /images/blog/what-is-cdn/cover.avif
timeToRead: 5
date: 2025-09-23
author: veeresh-mulge
callToAction: true
unlisted: true
category: tutorial
faqs:
  - question: "What is a CDN in plain terms?"
    answer: "A content delivery network is a fleet of servers placed in cities around the world that cache copies of your content close to your users. When someone visits your site, they connect to the nearest edge server instead of the origin, which means lower latency and less load on your origin."
  - question: "What is the difference between a CDN and a web host?"
    answer: "A web host runs your origin server, where your app and data actually live. A CDN sits in front of that origin and serves cached copies of static (and sometimes dynamic) responses from many global locations. You almost always want both."
  - question: "Does Appwrite include a CDN?"
    answer: "Yes. Every site you deploy on [Appwrite Sites](/docs/products/sites) is served through Appwrite's global CDN with 120+ points of presence, free TLS, and automatic edge caching. There is no separate CDN to set up. See the [Appwrite Network docs](/docs/products/network) for details."
  - question: "Can a CDN cache API responses, not just static files?"
    answer: "Yes. Modern CDNs (including the Appwrite CDN) cache API responses based on URL, headers, and cache control directives. This is most useful for read heavy endpoints with shared responses, such as product listings or public content feeds."
  - question: "Will a CDN improve security as well as speed?"
    answer: "Usually yes. CDNs sit in front of your origin and absorb traffic spikes and DDoS attacks, terminate TLS at the edge, and often include a web application firewall. They are part of a layered defense, not a replacement for application level security."
  - question: "How is cached content kept up to date?"
    answer: "Each cached object has a time to live (TTL). When it expires, the next request triggers a fresh fetch from the origin. You can also force invalidation explicitly when you push new content, so users do not have to wait for the TTL to expire."
---
Ever clicked on a website and been surprised at how quickly, or painfully slowly, it loads? That speed isn't just about how powerful the server is; it's about where the server is. If your app lives in New York but your users are in Tokyo, every request must cross an ocean before seeing anything. Not ideal.

That's where a **content delivery network (CDN)** comes in. Think of it as a network of pit stops scattered around the globe, ready to serve your content from the closest possible location. Images, scripts, videos, or even entire pages, CDNs make sure they get to your users faster, with fewer delays and less strain on your main server.

In this blog, we'll break down the basics of CDNs, their benefits, and how Appwrite developers can leverage them.

# How Does a CDN Work?

At its core, a **content delivery network (CDN)** is a team of servers spread out across the globe, working together to get content to your users as quickly as possible. Instead of everyone knocking on the door of your origin server, they're routed to the closest “edge” server.

**Here's how CDN works**:

1. A user makes a request (say, loading an image or a script).
2. The CDN checks its nearest edge server.
    - If the content is already cached there, then it's delivered instantly.
    - If not, the CDN grabs it from your origin server, stores a copy locally, and serves it up.
3. The next user nearby gets it instantly, and there is no waiting around.

This system, called [**edge caching**](/docs/products/network/caching#edge-level), keeps things fast, light, and reliable. Since the CDN handles repeat requests, your origin server can breathe a little easier, too.

# Benefits of using a CDN

So why go through the trouble of adding a CDN in front of your app? Here's what you actually get out of it:

- **Speed that feels instant:** Content gets served from the closest edge server, not the other side of the planet. Less travel time means faster load times.
- **More reliable apps:** If one server goes down, the CDN automatically reroutes requests to the next available one. No single point of failure.
- **Lower costs:** CDNs take the load off your origin server by caching static assets and help cut bandwidth bills.
- **Built-in security:** Many CDNs offer DDoS protection and SSL/TLS encryption out of the box, keeping your app safer at scale.
- **A CDN handles traffic spikes with ease:** When there is a sudden surge in users, the CDN spreads the load across its network so performance doesn't tank.

In short, CDNs make your app faster, sturdier, safer, and more cost-efficient to operate.

# Example: Without vs. with a CDN

- **Without a CDN**, a user in Tokyo accessing an app hosted in New York may face noticeable latency because every request travels across the globe.
- **With a CDN**, that same request is routed to the nearest CDN edge server in Tokyo, making the app feel instantly responsive.

# Using a CDN with Appwrite

If you're building on Appwrite, you've already got a head start:

- **Appwrite Sites** – Every site you deploy has a built-in CDN, so your static assets are automatically cached and served closer to your users with no extra setup.
- **The Appwrite CDN** – With 120+ points of presence (PoPs) worldwide, Appwrite's CDN ensures your content reaches users quickly and reliably, regardless of location. It handles static and dynamic content, integrates directly with Appwrite's regions and edges, and uses advanced compression and smart caching to keep delivery fast and efficient. Plus, everything runs over **TLS**, so security is baked in by default.
    
For APIs, storage, or self-hosted projects, you can still put a CDN in front of your Appwrite deployment to amplify performance even further. Check out the [Appwrite Network docs](/docs/products/network) for more details.

# Conclusion

Modern CDNs go far beyond caching static files. With edge computing, intelligent routing, and compression, they also accelerate **dynamic content, APIs, and real-time workloads**. For developers, this means lower latency, higher availability, and stronger security baked into the delivery layer.

And if you're working with Appwrite, you already have these capabilities through [Appwrite Sites](/docs/products/sites) and the [Appwrite CDN](/docs/products/network/cdn). Together, they help you build apps that scale seamlessly while delivering the instant, always-on experience users now expect.

# More resources

- [Appwrite Sites docs](/docs/products/sites)
- [The Appwrite CDN](/docs/products/network/cdn)
- [Why multi-cloud is taking over](/blog/post/why-multi-cloud-is-taking-over)

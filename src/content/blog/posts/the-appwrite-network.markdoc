---
layout: post
title: "Announcing the Appwrite Network: Appwrite’s vision for a global cloud infrastructure"
description: Announcing more regions for Cloud, and many more to come with the introduction of the Appwrite Network.
date: 2025-04-16
cover: /images/blog/the-appwrite-network/network-cover-image.avif
timeToRead: 05
author: christy-jacob
category: product
featured: false
callToAction: true
faqs:
  - question: "What is the Appwrite Network?"
    answer: "The [Appwrite Network](/docs/products/network) is a global network of cloud regions, edge locations, and Points of Presence (PoPs) that powers Appwrite Cloud. It improves availability, reduces latency, and helps with compliance for local data regulations."
  - question: "Which regions does Appwrite Cloud support?"
    answer: "At launch, the Appwrite Network is operational in Frankfurt (FRA), Sydney (SYD), New York City (NYC), Singapore (SGP), and Toronto (TOR). More regions like San Francisco, Bangalore, Amsterdam, and London are planned. Pro users can pick from available regions when creating a project."
  - question: "What is the difference between a region, an edge, and a PoP?"
    answer: "Regions host your core data and services like Auth, Databases, Functions, Messaging, and Storage. Edges run lightweight compute close to your users using geo-routing. PoPs are CDN locations that cache and deliver content. Together, they bring data, compute, and delivery closer to end users."
  - question: "Does Appwrite Cloud include a CDN and DDoS protection?"
    answer: "Yes. All Appwrite Cloud projects are served through a built-in global CDN with strategically placed PoPs, plus integrated DDoS protection that combines traffic analysis, rate limiting, and filtering. This applies to all plans without extra configuration."
  - question: "Can I control where my project's data is stored?"
    answer: "Yes. When you create a project, you can choose the region where your data lives. The data stays in that region for compliance, while edges and the CDN make it accessible to users worldwide with low latency."
  - question: "Does Appwrite offer a Web Application Firewall?"
    answer: "Yes. The [WAF](/docs/products/network/waf) is available to enterprise customers and inspects HTTP and HTTPS traffic at OSI Layer 7. It blocks common attacks like SQL injection, XSS, and CSRF, and includes customizable rulesets managed through a dedicated success manager."
---

We are happy to announce the launch of the Appwrite Network, a network of cloud regions and edge locations (edges) to improve Appwrite Cloud availability, performance, and compliance with local regulations. This will provide Appwrite developers and teams with the best tools and infrastructure to build, deploy, and scale your applications. This brings us closer to the Appwrite mission of making software development more accessible and enjoyable for all developers.

Starting today, all Pro users have access to three regions of choice, including our two new regions in **New York City** ('NYC'), and **Sydney** ('SYD') that are joining our first region in **Frankfurt** ('FRA'). In the next few weeks, this will be available to all Cloud users, with more regions to come.

![Overview of all the regions](/images/blog/the-appwrite-network/cloud-regions.avif)

# The plan

The Appwrite Cloud beta journey began with a single region in Frankfurt, which allowed us to focus on delivering the highest quality and performance on a smaller scale. As we approach the General Availability (GA) of Appwrite Cloud, and with a growing number of developers, projects, and organizations on the platform, we are excited to share the plans to expand the network to include additional regions and edges across the globe.

| Location              | Status                | Region | Edge | Timeline      |
|---------------------|-----------------------|--------|------|---------------|
| Frankfurt (`FRA`)     | Ready and operational | Yes    | Yes  | Available Now |
| Sydney (`SYD`)        | Ready and operational | Yes    | Yes  | Available Now |
| New York City (`NYC`) | Ready and operational | Yes    | Yes  | Available Now |
| Singapore (`SGP`)     | Ready and operational | Yes    | Yes  | Available Now |
| Toronto (`TOR`)       | Ready and operational | Yes    | Yes  | Available Now |
| San Francisco (`SFO`) | In work               | Yes    | Yes  | Q4 2025       |
| Bangalore (`BLR`)     | In plans              | Yes    | Yes  | TBD           |
| Amsterdam (`AMS`)     | In plans              | Yes    | Yes  | TBD           |
| London (`LON`)        | In plans              | Yes    | Yes  | TBD           |


# Addressing the latency problem

Latency is a critical factor in the performance of both web and mobile applications. High latency can lead to slow response times, poor user experience, and ultimately, loss of revenue. There are several primary causes of latency:

- **Physical distance:** The further the data has to travel, the longer it takes. This is known as propagation delay.
- **Network congestion:** Overloaded networks can slow down data transmission.
- **Routing and switching:** Each hop a packet takes through the network can add delays.
- **Server processing time:** The time it takes for a server to process a request and send a response.

Establishing a global network of regions and edges can significantly reduce latency. For example, without a global network, a request from a user in Sydney to a server in New York could experience latency upwards of 250 milliseconds. With a global network, routing that same request through an edge in Singapore and a region in Frankfurt can reduce latency to under 100 milliseconds.

A global network allows us to distribute data and processing power closer to the end-users, minimizing the distance that data needs to travel and thus reducing the time it takes for requests and responses to be processed. This results in faster load times, improved performance, and a better overall user experience.

# Why build the Appwrite Network?

Building a fully fledged network from scratch is not a decision we take lightly. Here are the key reasons behind this approach:

**1. Innovation and quality**: By owning the network, we can innovate and optimize in ways that would be impossible with off-the-shelf solutions. This control allows us to deliver a superior developer experience explicitly tailored to your needs.

**2. Security and trust**: Maintaining control over the infrastructure to ensure the highest standards of security and privacy for Appwrite developers. This helps us ensure you always own and control your data.

**3. Flexibility and customization**: Having a network allows us to be more agile and responsive to the evolving needs of Appwrite developers. We can quickly adapt and introduce new features and improvements without being constrained by third-party limitations.

**4. Cost efficiency**: By building and managing the network, we can reduce costs and pass those savings on to Appwrite developers. By maintaining independence, we can ensure predictable costs for compute, and this helps us make Appwrite more affordable for teams of all sizes.

**5. It’s fun**: At the core of Appwrite, we are geeks who love technology and building stuff from scratch, so you don’t have to. Building a network is not just a business decision but also a passion. It's an opportunity to push the boundaries of what's possible and share that excitement with the Appwrite community of developers.

# Regions vs Edges vs PoPs

![Regions vs Edges vs PoPs](/images/blog/the-appwrite-network/regions-edges-pops.avif)

In Appwrite, Regions are where all your core data and services live. This includes your databases, auth, functions, messaging, and storage. Regions are the source of truth, handling heavy workloads and ensuring your application runs reliably while keeping your data compliant with local regulations.

Edges are about speed. They process requests closer to your users using smart geo-routing, reducing latency by handling compute tasks at the nearest edge location. Edges are perfect for serving cached content, executing lightweight computations, and optimizing user interactions.

Our global CDN leverages strategically positioned Points of Presence (PoPs) to cache and deliver your content from locations nearest to your users, ensuring rapid load times and enhanced performance.

In short, a region hosts your data, and the edge executes your functions close to your users. Both regions and edges take advantage of the Appwrite CDN to optimize delivery and security.

# Data storage and global availability

One of the key features of the Appwrite Network is the ability to choose where your project's data is stored. You can select your preferred region, ensuring compliance with local data regulations and optimizing performance for your primary user base. However, thanks to the global network of edges, your data will be accessible worldwide on the Appwrite network, ensuring fast and reliable access for users regardless of their location.

Each region will also function as an edge for other regions, enhancing network coverage and reducing latency. This interconnected approach ensures that we can deliver the best possible performance and reliability for all Appwrite developers and your end-users.

# Global CDN with integrated DDoS protection

All Appwrite’s Cloud projects are served by Appwrite’s built-in CDN. Our new global CDN is designed to serve your content rapidly and reliably worldwide. By leveraging a network of strategically located points of presence (PoPs), the CDN ensures that every user request is delivered from the closest possible location, drastically reducing latency and enhancing the overall user experience.

Moreover, the CDN includes integrated DDoS protection that combines standard traffic analysis, rate limiting, and filtering techniques to help mitigate potential threats in real time. This balanced approach helps maintain solid performance while providing a reliable level of security for your applications.

# Web application firewall (WAF)

Our [Web Application Firewall](/docs/products/network/waf) (WAF) is now available exclusively to enterprise customers, providing a crucial protective layer for your applications. Operating at OSI Layer 7, the WAF inspects and filters all HTTP/HTTPS traffic in real time, effectively blocking common web vulnerabilities such as SQL injection, XSS, and CSRF, while also mitigating application-level DDoS attacks.

Customizable rulesets allow you to tailor the WAF to your application's specific needs, with detailed analysis of request headers, payloads, and query strings to identify and neutralize threats before they reach your infrastructure. Setup and configuration are managed through your dedicated Appwrite success manager, ensuring that the WAF adapts to evolving security challenges and compliance requirements. To learn more about our enterprise plan, you can [contact us](/contact-us/enterprise).

# The road ahead

We welcome you to visit the docs to learn more about the [Appwrite Network](/docs/products/network) and how Appwrite handle's other topics like [secure transportation](/docs/products/network/tls), [compression](/docs/products/network/compression), [caching](/docs/products/network/caching) and more.

We are excited about the future of the Appwrite network and the endless possibilities it will unlock for developers worldwide. Stay tuned for more updates as we continue to build and expand the network. Thank you for being part of the Appwrite community and for your ongoing support. Together, we are building the future of cloud platforms designed for developers.


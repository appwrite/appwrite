---
layout: post
title: Empowering Shopify merchants with real-time store monitoring using StoreAlert
description: Learn how Devkind improved store monitoring for merchants while reducing development time, lowering server costs, and enhancing app performance using Appwrite Cloud.
date: 2025-02-20
cover: /images/blog/customer-story-storealert/cover.avif
timeToRead: 6
author: aditya-oberai
category: customer-stories
featured: false
callToAction: true
faqs:
  - question: "What is StoreAlert?"
    answer: "StoreAlert is a Shopify app built by Devkind that gives merchants real-time notifications and a complete event history for product, order, inventory, and price changes. It is live on the Shopify App Store and supports email, SMS, and push channels, with smart hourly digests to prevent notification overload."
  - question: "How does StoreAlert use Appwrite?"
    answer: "StoreAlert uses [Appwrite Functions](/docs/products/functions) for Shopify OAuth verification, event sync, and forwarding alerts to Gadget.dev. [Appwrite Databases](/docs/products/databases) store access tokens and event logs. CRON-scheduled functions handle recurring tasks like polling for new events and refreshing tokens."
  - question: "Why did Devkind pick Appwrite over Firebase or Supabase?"
    answer: "Appwrite's pricing model and the limits on Functions and Database operations fit their workload better than Firebase or Supabase. The combination of managed Functions, Databases, and Auth in one platform let them ship without stitching together separate services."
  - question: "Can Appwrite be used as a Shopify app backend?"
    answer: "Yes. Use [Appwrite Functions](/docs/products/functions) to handle Shopify OAuth, webhook ingestion, and API polling. Store access tokens and event data in [Appwrite Databases](/docs/products/databases). Schedule recurring polling with CRON triggers on Functions to fetch new events from the Shopify API."
  - question: "How can I store Shopify access tokens securely?"
    answer: "Store them in [Appwrite Databases](/docs/products/databases) with permissions set so only the relevant user (and your server-side functions via API key) can read them. Never expose access tokens to the client. Refresh tokens on a CRON schedule using [Appwrite Functions](/docs/products/functions)."
  - question: "How much faster can I ship a SaaS using a BaaS like Appwrite?"
    answer: "Devkind reported a 60% reduction in development time and 40% lower server costs after picking Appwrite for StoreAlert. The biggest wins come from skipping auth, database, storage, and scheduled-task infrastructure that you would otherwise have to build and operate yourself."
---

In 2023, a client of [Devkind](https://www.devkind.com.au/), a software development and marketing agency from Melbourne, Australia, faced a major setback with several products disappearing from their Shopify store without any trace of when or how it happened. Without an event log, tracking the issue was impossible. This incident highlighted a glaring gap in e-commerce store management: the lack of real-time visibility into key store events.

Seeing the critical need for better event tracking, Kazim Ali, Co-Founder of Devkind, envisioned a powerful solution: a tool designed to notify merchants in real time of important store events while maintaining a detailed event history. This tool would eliminate uncertainty by providing store owners with the transparency they need to stay in control and solving headaches across various departments, including marketing, auditing, logistics, and more, by streamlining event tracking and ensuring operational clarity.

## Discovering the problem

The Devkind team engaged directly with e-commerce merchants to understand their biggest challenges in store management and identified a recurring issue: store owners lacked a reliable way to track real-time changes in their stores.

Managing an online store isn’t just about listing products; it’s a constant cycle of updates, adjustments, and optimizations. Merchants must juggle multiple tasks, from launching new products and adjusting prices to managing inventory and processing orders. Without an efficient tracking system, they face several key challenges:

- **Missed crucial events:** Without real-time tracking, important updates and changes can go unnoticed.
- **Delayed responses to issues:** A slow reaction to inventory shortages, pricing errors, or order issues can result in lost revenue.
- **Overwhelming manual management:** For larger stores with extensive catalogs, manually tracking constant updates becomes impractical and time-consuming.
- **Increased risk of unnoticed errors:** Small mistakes in pricing, inventory levels, or product listings can lead to bigger problems if they aren’t caught in time.

Recognizing this gap, Devkind set out to develop a solution designed to give merchants real-time visibility into their store activities, ensuring they’re always in control (and they did!)

## How StoreAlert solves the problem

[StoreAlert](https://storealert.io/) is a powerful event notification system designed to give Shopify merchants real-time insights into their store operations. It provides essential features that help businesses stay informed and efficient:

- **Real-time notifications:** Instantly receive alerts for crucial store events, including
    - Product additions, updates, and deletions
    - Order modifications and status changes
    - Price adjustments and inventory updates
- **Smart digest emails:** Prevents notification overload by consolidating up to 50+ alerts into a single hourly digest email, ensuring merchants stay informed without excessive inbox clutter.
- **Comprehensive event tracking:** Logs and monitors every store activity, from product launches to blog updates, providing a complete event history for easy reference and tracking.
- **Customizable notification flows:** Allows merchants to tailor notifications through multiple channels, including email, SMS, and push notifications, ensuring updates arrive in the most convenient way.
- **Efficient inventory and catalog management:** Streamlines tracking for stores managing thousands of products, ensuring stock levels are up-to-date and reducing the risk of overselling or stockouts.
- **Seamless Shopify integration:** Connects directly to Shopify, enabling real-time notifications with a quick and simple installation; no complicated setup required.

{% call_to_action title="All-in-one development platform" description="Use built-in backend infrastructure and web hosting, all from a single place." point1="Start for free"  point2="Open source" point3="Support for over 13 SDKs" point4="Managed cloud solution" cta="Start building" url="https://cloud.appwrite.io/" /%}

## **How Devkind used Appwrite to develop StoreAlert**

> "Our clients rely on us to deliver solutions that not only address issues but also proactively prevent them from occurring."  
> ⠀  
> Saad Bhutto, Co-Founder at Devkind

To turn StoreAlert into reality, Devkind needed a robust backend solution that could handle Shopify authentication, synchronize event data, and automate recurring tasks efficiently. They explored various Backend-as-a-Service solutions, including Firebase and Supabase. However, the **reasonable pricing model** and **extensive Functions and Database limits** convinced them to choose Appwrite for the same.

Appwrite was leveraged at the core of StoreAlert’s backend infrastructure in the following ways:

- **User-store verification and access token management**
    - Appwrite communicates server-to-server (through an Appwrite Function) to verify users and their Shopify store IDs, ensuring only authorized users can consume the application.
    - Post-authentication, the Appwrite Function retrieves and stores access tokens correlated to Shopify stores in the Appwrite Database, allowing StoreAlert to interact securely with the Shopify API.
- **Event synchronization and data store**
    - Key events related to the Shopify store, including product updates, deletions, and order changes, are fetched from Shopify’s API and stored in an Appwrite Database for easier referencing.
    - All key event data stored in the Appwrite Database can be used to generate notifications and maintain detailed logs.
- **Notification synchronization with Gadget.dev**
    - An Appwrite Function processes key event data and logs notifications, which are forwarded to [Gadget.dev](https://gadget.dev/use-cases/shopify-app-development) to send real-time notifications to merchants using the app.
- **Automated background tasks**
    - Appwrite Functions triggered by CRON schedules automate recurring tasks, such as:
        - Fetching new key events from Shopify’s API
        - Generating notification entries for recently fetched events
        - Refreshing stored access tokens for the Shopify stores

## **StoreAlert today**

As per the feedback from the Devkind, Appwrite helped

- **Reduce development time by 60%**, reducing time-to-market significantly
- **Lower server costs by 40%**, optimizing infrastructure expenses and budgets
- **Improve response time by 30%**, ensuring better performance and user experience
- **10x easier data scaling**, preparing StoreAlert for future growth

> "A special thanks to Appwrite for shouldering the heavy lifting behind StoreAlert, providing robust features and seamless functionality that allow us to focus on what matters most: empowering merchants with real-time insights and control over their store operations."  
> ⠀  
> Hassan Ahmed, Full Stack Developer at Devkind
 

StoreAlert is now [live on the Shopify App Store](https://apps.shopify.com/storealert), and the team at [Devkind](https://www.devkind.com.au/) is committed to continuous improvements and feature enhancements to make it even more powerful and user-friendly. We look forward to their future endeavors and wish them the very best.

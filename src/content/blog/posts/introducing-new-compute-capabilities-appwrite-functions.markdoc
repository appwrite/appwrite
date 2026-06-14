---
layout: post
title: Introducing new compute capabilities for Appwrite Functions
description: Learn more about the new compute capabilities for Appwrite Functions. 
date: 2025-01-07
lastUpdated: 2026-05-22
cover: /images/blog/gb-hours.avif
timeToRead: 6
author: eldad-fux
category: product, announcement
featured: true
callToAction: true
faqs:
  - question: "What CPU and memory configurations are available for Appwrite Functions?"
    answer: "The Pro and Enterprise plans can pick from 0.5 CPU/512MB, 1 CPU/512MB, 1 CPU/1GB, 1 CPU/2GB, 2 CPU/4GB, and 4 CPU/4GB. Free plan functions use the standard 0.5 CPU and 512MB configuration. See [Appwrite Functions](/docs/products/functions)."
  - question: "What is a GB hour?"
    answer: "A GB hour is the product of CPU cores, memory in GB, and time in hours. A 1 CPU function with 1GB of RAM running for 2 hours uses 2 GB hours. Appwrite uses it as the unit for both execution and build time."
  - question: "How much execution time do the Free and Pro plans include?"
    answer: "Free plan accounts get up to 100 GB hours per month. Pro accounts get 1,000 GB hours per month, with additional usage charged at $0.06 per GB hour. Enterprise has custom limits."
  - question: "How do I avoid surprise bills when my functions get popular?"
    answer: "Set a budget cap on your organization. When you hit it, Appwrite pauses additional usage so you can decide whether to raise the limit or optimize before more charges accrue."
  - question: "When should I increase memory or CPU for a function?"
    answer: "Increase memory when you're hitting out of memory errors or processing large payloads. Increase CPU when execution time is bottlenecking on computation rather than network I/O. Profile your function first so you only pay for resources you actually need."
  - question: "Can I customize compute specs per function?"
    answer: "Yes. Each function has its own specification, so a lightweight webhook handler can stay at 0.5 CPU/512MB while a video transcoder runs at 4 CPU/4GB in the same project."
---

At Appwrite, we're always working to make our serverless platform better for developers like you. Our latest update to Appwrite Functions brings some exciting improvements, especially when it comes to customizing CPU and memory settings for your runtimes. This means better performance, more flexibility, and the chance to explore new and innovative use cases. We've also updated our pricing model to keep things sustainable and fair for everyone. Keep reading to learn more about these updates and how they can benefit your projects.

Starting today, Appwrite developers have the ability to customize runtime specifications, allowing for tailored performance and resource allocation. Previously, all functions operated with a standard configuration of 0.5 CPU and 512MB memory. Now, Pro and Enterprise users can select from a range of configurations to suit their application needs better:

- 0.5 CPU & 512MB RAM
- 1 CPU & 512MB RAM
- 1 CPU & 1GB RAM
- 1 CPU & 2GB RAM
- 2 CPU & 4GB RAM
- 4 CPU & 4GB RAM

These options enable greater performance and flexibility, allowing developers to optimize their functions based on specific requirements. For instance, resource-intensive tasks such as real-time data processing or complex computational operations can now be executed more efficiently. Additionally, enhanced memory configurations support larger datasets and more demanding applications, broadening the scope of what can be achieved with Appwrite Functions.

# Pricing

Effective **March 1st,** Appwrite is introducing changes to our [pricing](https://appwrite.io/pricing) structure to accommodate the enhanced compute capabilities and ensure sustainable platform growth. Central to this update is the **GB-hours** metric, which measures usage based on the compute resources consumed over time.

## Understanding GB-hours

GB-hours is calculated by multiplying the build and execution time of your functions by the amount of CPU and memory they utilize. For example:

**Example**: If you run a function with 1 CPU and 1024MB memory for 2 hours, the GB-hours consumed would be:

`GB-hours = CPU cores × Memory (GB) × Time (hours) = 1 × 1 × 2 = 2 GB-hours`

## Allocation and pricing plans

- Free plan users will have up to 100GB of execution and build time per month.
- Pro users will have up to 1,000GB of execution and build time per month. Additional usage is available at a rate of $0.06 per GB-hour. Enterprise has custom limits.

Once the monthly GB-hours limit is reached, additional usage will automatically apply add-ons to your Pro account. To prevent unexpected charges, we strongly recommend setting a **budget cap**. The budget cap is designed to safeguard your organization by pausing services once the budget is reached for additional usage, allowing you to adjust your usage or modify your budget as needed.

If you or your organization expect to use these new features extensively, you can contact our [sales team](https://appwrite.io/contact-us/enterprise) to learn more about the custom offerings available for our enterprise customers.

# Ensuring sustainability

Introducing the GB-hours metric is an important step in maintaining a sustainable and fair platform for all users. By accurately tracking resource consumption, we can better manage extensive usage and ensure that our infrastructure remains robust and reliable. This approach allows us to effectively support both small-scale projects and large enterprises, fostering a balanced ecosystem.

## Future possibilities

Looking ahead, the customization of runtime specifications paves the way for even more advanced features. One exciting prospect is the potential to support heavy compute runtimes that were previously only feasible through self-hosting, such as **Swift** and **Java** runtimes. This expansion will provide Appwrite developers greater versatility in choosing the best tools and languages for their applications, meeting developers where they need and feel most comfortable.

---
layout: post
title: "How we reduced cold start times on Appwrite Sites"
description: Learn how we improved cold-start performance for Appwrite Sites, delivering faster load times for your web applications.
date: 2026-04-24
cover: /images/blog/reducing-cold-starts-appwrite-sites/cover.avif
timeToRead: 5
author: levi-van-noort
category: product
faqs:
  - question: "What is a cold start on [Appwrite Sites](/docs/products/sites)?"
    answer: "A cold start is the extra time the first request takes when a new instance has to be spun up before serving traffic. For SSR sites, it includes downloading the build artifact, extracting it, and initializing the runtime."
  - question: "How much did Appwrite reduce cold start times?"
    answer: "Cold start duration dropped roughly 30 to 50% across the board. The download and extraction phases went from multi-second spikes to 100 to 400 milliseconds, which moved the bottleneck onto runtime initialization."
  - question: "What is Node file tracing and why does it help?"
    answer: "Node file tracing statically analyzes a Node.js app to figure out exactly which files are needed at runtime. Stripping out unused dependencies shrank build outputs by up to 98.8% on Appwrite's own templates, which means less to compress, transfer, and extract on every cold start."
  - question: "Why use igzip instead of gzip?"
    answer: "igzip is an optimized gzip implementation from Intel's Intelligent Storage Acceleration Library. Swapping gzip for igzip improved extraction times by 50 to 100% with consistent gains across build sizes, which is why Appwrite picked it over alternatives like zstd that had more variable performance."
  - question: "Do I need to change my project to benefit from the new cold start improvements?"
    answer: "No code changes are required. Redeploy your active deployment on [Appwrite Sites](/docs/products/sites) and the updated build pipeline applies the new compression and tracing automatically."
---

If you've ever deployed a site and noticed that first request taking just a bit too long, you've experienced a cold start. It's one of those things that's easy to overlook during development but quickly becomes noticeable in production, especially when your users are the ones waiting.

At Appwrite, improving the performance of [Sites](/products/sites) (and every other product) has always been a high priority. We wanted that first request to feel just as fast as every request after it. So we dug into the problem, ran some experiments, and made a series of changes that significantly brought down cold-start times.

In this blog, we will walk you through the cause of the slowdown, the approach we took to fix it, and the results we have seen since.

# Adding observability

Before implementing any fixes, we needed to understand what was actually happening. We started by adding observability across the full request lifecycle, instrumenting every step from the moment a request hits our edge to when the response is sent back. This gave us a clear breakdown of where time was being spent (and where it was being wasted). Without that visibility, we would have been guessing, and in performance work, guessing is how you end up optimizing the wrong thing.

# Compression and decompression

With better visibility into the request lifecycle, one pattern stood out immediately: compression and decompression of the build output accounted for a significant share of cold-start time. Build artifacts were being compressed with tar and extracted with gzip. This worked fine functionally, but the extraction step was eating up a noticeable chunk of every first request.

We tested a drop-in replacement of gzip with [igzip](https://github.com/intel/isa-l), an optimized implementation from Intel's Intelligent Storage Acceleration Library. The results were immediate: extraction times improved by 50 to 100%, making it one of the highest-impact changes we shipped. We also evaluated [zstd](https://github.com/facebook/zstd) as an alternative, but its performance was more variable and only showed gains at certain build output sizes, so we stuck with igzip for its consistent wins across the board. For very small build outputs, we skipped compression entirely and used plain tar, avoiding any decompression cost on the download path.

# Node file tracing

Faster decompression was a big win, but it raised an obvious next question: why were we extracting so much in the first place? Many build outputs included dependencies and files that were never touched at runtime. To address this, we implemented [Node file tracing](https://github.com/vercel/nft), which statically analyzes a Node.js application to determine exactly which files are required to run it.

The impact was dramatic. Smaller artifacts meant less to compress, less to transfer, and less to extract, compounding the gains we had already made on the decompression side.

Here are some examples of size reductions across our templates:

| Templates | Before | After | Reduction |
| --------- | ------ | ----- | --------- |
| Nuxt Playground | 235 MB | 46 MB | 80.4% |
| Analog Playground | 858 MB | 10 MB  | 98.8% |
| TanStack Starter | 323 MB | 49 MB  | 84.8% |
| Astro Playground | 169 MB | 95 MB  | 43.8% |
| Remix Playground | 234 MB | 7 MB |  97.0% |
| Svelte Starter | 103 MB | 13 MB | 87.4% |
| Store Template | 185 MB | 3.5 MB | 98.1% |

The reduction in build output size also showed up clearly in our observability data:

![build-output-sizes](/images/blog/reducing-cold-starts-appwrite-sites/build-output-sizes-non-nft.avif "contain")

![build-output-sizes](/images/blog/reducing-cold-starts-appwrite-sites/build-output-sizes-nft.avif "contain")

# Cold starts

Those smaller artifacts translated directly into faster cold starts. With build outputs reduced by up to 98.8%, the download phase, previously one of the more costly steps, dropped from multiple seconds to just 100 to 200 milliseconds. Extraction saw a similar improvement, going from regular 4 to 7 second spikes down to around 200 to 400 milliseconds. Together, these two phases went from dominating the cold-start timeline to being barely noticeable.

The overall effect was a roughly 30 to 50% reduction in cold-start duration across the board. The remaining time is now largely spent on runtime initialization rather than transferring and unpacking artifacts. We've shifted the bottleneck to a fundamentally different part of the stack, and that's exactly where we want to focus next. With targeted work there, we expect to bring P95 timings below what P50 used to be.

# Benefit from this change

If you're running an SSR-based site on Appwrite Sites, all you need to do is redeploy your latest active deployment. The updated build process will automatically apply the optimizations described above, reducing your build output size and improving cold-start times, with no code changes required.

# What's next

These improvements are just the beginning. We're actively working on the next round of optimizations. Performance is not a one-time fix; it's an ongoing effort, and we're committed to making every deploy on Appwrite Sites feel instant. [Stay tuned](https://appwrite.io/discord) for more updates as we continue to push cold start times even lower.

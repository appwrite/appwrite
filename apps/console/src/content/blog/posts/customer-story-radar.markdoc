---
layout: post
title: How Radar scaled media curation while saving $1M with Appwrite Cloud
description: Discover how the team at Paradox used Appwrite Cloud to save 10,000+ engineering hours and $1M+ in overhead costs to build Radar.
date: 2025-09-25
cover: /images/blog/customer-story-radar/cover.avif
timeToRead: 6
author: aditya-oberai
category: customer-stories
featured: false
callToAction: true
faqs:
  - question: "What is Radar?"
    answer: "Radar is a social curation app for tracking media recommendations across five categories (movies, TV shows, books, audiobooks, video games). Built by Paradox as a native iOS app using SwiftUI, SwiftData, and Swift 6 concurrency, it features a personal library, discovery feed, and social following."
  - question: "How does Radar use Appwrite?"
    answer: "Radar uses [Appwrite Functions](/docs/products/functions) (mostly Deno 2.0, with one Node.js function for Sign in with Apple) to power offline-first syncing, [Appwrite Databases](/docs/products/databases) for library data, and [Appwrite Sites](/docs/products/sites) to host the Svelte 5 marketing website."
  - question: "How much did Appwrite save Paradox compared to alternatives?"
    answer: "Paradox estimates Appwrite saved them around 10,000 engineering hours and nearly $1,000,000 across engineering, testing, and operations. They estimate Supabase would have cost over 3x more with a more confusing developer experience."
  - question: "How does Radar handle offline-first sync?"
    answer: "Radar uses [Appwrite Functions](/docs/products/functions) (in Deno 2.0) to mediate sync between the iOS client and Appwrite Databases. Atomic increment/decrement operations on counts and bulk APIs make it possible to apply many local changes efficiently when a device reconnects."
  - question: "Can I use Appwrite Functions with Deno?"
    answer: "Yes. [Appwrite Functions](/docs/products/functions) supports multiple runtimes including Deno (Radar uses Deno 2.0), Node.js, Python, Go, PHP, Ruby, and more. You can pick the right runtime per function based on the libraries you need."
  - question: "How do I implement Sign in with Apple in an Appwrite app?"
    answer: "Use a server-side Function (Radar uses Node.js) to verify the Apple identity token, then create or retrieve a user in [Appwrite Auth](/docs/products/auth) and return a session. This keeps your Apple credentials and verification logic on the server instead of in the client."
---

For any avid consumer of media, keeping track of every movie, book, or game recommended by friends can be a real challenge. Matt Martino, former Apple employee, experienced this frustration firsthand thanks to one simple shortcoming: **a bad memory**. 

> Anytime someone gives me a recommendation for a movie or a book or a game or show, whatever it is, it just vanishes into the ether and I never remember it again.

This pain, compounded by the hassle of juggling eight separate apps to track different types of media, led Martino (now founder at [Paradox](https://paradoxlabs.co/)) to a clear conclusion: **there had to be a better way.**

And so, **Radar** was born.

# The challenge

Martino and his team set out to replace a scattered system of lists with a single, beautifully designed hub for all recommendations. Their challenge was two-fold:

- **Everything in one place:** Radar needed to capture *all* forms of recommendations, from movies and TV shows to books, audiobooks, and video games, in one place.
- **Speed and simplicity:** The app had to be lightning-fast and effortless to use. In Martino's words, *"If you're pitching a tracking app and it doesn't take less than three seconds to put something in the app, you've done something wrong."*

By focusing on these goals, the Radar team aimed to create an app that's not just useful but a joy to use.

# What is Radar?

[Radar](https://justuseradar.com/) is an all-in-one social curation app that allows users to keep track of media recommendations across five categories: movies, TV shows, video games, books, and audiobooks.

![Discover Radar](/images/blog/customer-story-radar/radar.avif)

Key highlights include:

- **Personal Library:** Organize recommendations with statuses, ratings, reviews, notes, and tags.
- **Discover:** Browse curated suggestions and trending content.
- **Social Feed:** Follow friends to see what they are recommending.

A "Places" category for location-based recommendations like restaurants and hiking trails will also be coming soon.

# Building Radar with Appwrite

To deliver the fast, polished experience they envisioned, the team built Radar natively for iOS using SwiftUI, SwiftData, and Swift 6 concurrency. This native-first approach ensures Radar feels tightly integrated with Apple's ecosystem and sets the foundation for future expansions like a Vision Pro app that could display your recommendations as a digital bookshelf.

On the backend, Radar runs entirely on Appwrite. Early prototypes with PocketBase proved unsustainable, Firebase felt too proprietary, and Supabase was dismissed for its confusing developer experience and high costs. **Appwrite stood out for its thoughtful design, open-source foundation, and supportive community.**

> I 100% guarantee that someone who's never built an app before can figure it out. You understand a database is a big thing, and then tables go inside databases. There's structure there.

Radar relies heavily on **Appwrite Functions** to power its offline-first syncing, written primarily in Deno 2.0 with a Node.js function handling Sign in with Apple. Other Appwrite features play key roles:

- **Atomic increment/decrement API** for library counts to power social and synchronization features
- **Bulk API** and **CRON schedules** for the scheduled cleanup of deleted accounts and relevant data
- **Dynamic API keys** (within Appwrite Functions) for simplified server logic
- **Appwrite Sites** for hosting the Radar website, built in Svelte 5

> Building a product like Radar with a team our size wouldn't be possible without Appwrite. Alternatives like Supabase would cost over 3x more with a user experience that is often confusing and over-abstracted. In addition, Appwrite has already saved Paradox an estimated 10,000 engineering hours and nearly $1,000,000 across engineering, testing, and operations.

Martino credits not only the technology but also **Appwrite's community**. The Discord server became a reliable support channel, where answers and advice helped his team move quickly. **"The way you take care of your customers is very appreciated,"** he says.

# The result and what's next

With Appwrite powering the backend and SwiftUI driving the frontend, **Radar quickly moved from concept to a polished product.** The beta rolled out to 70 testers on TestFlight, giving the team invaluable feedback ahead of its App Store launch in August 2025. Early users praised the simplicity of having all their lists in one place and the ease of sharing recommendations with friends. Now, the team is focused on **scaling and refinement**. A web app is planned to broaden access beyond iOS, with Android also on the roadmap.

For Martino, building Radar has proven that a small team can deliver a product that feels as polished as something from a tech giant.

> The barrier to entry is zero with Appwrite. And I think that's really special.

## Support Radar on Product Hunt!

Radar has launched on [Product Hunt](https://www.producthunt.com/products/radar-6), and we encourage our community to support them by trying out the product, sharing their feedback, and upvoting the launch.

![Support Radar on Product Hunt!](/images/blog/customer-story-radar/product-hunt.avif)
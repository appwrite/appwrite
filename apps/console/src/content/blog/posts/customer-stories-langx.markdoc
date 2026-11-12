---
layout: post
title: Building the future of language learning for over 5000 users worldwide with LangX
description: Learn how LangX used Appwrite to develop an open-source language exchange platform similar to Tandem and support thousands of learners all around the world.
date: 2024-05-23
cover: /images/blog/case-study-langx/cover.avif
timeToRead: 5
author: aditya-oberai
category: customer-stories
faqs:
  - question: "What is LangX?"
    answer: "LangX is an open-source language exchange platform similar to Tandem. It pairs users who want to learn a language with native speakers who can teach it, with one-on-one chat using text, images, and audio. It has over 5000 registered users and is built with Ionic Angular and Appwrite."
  - question: "How does LangX use Appwrite?"
    answer: "LangX uses [Appwrite Databases](/docs/products/databases) for users, chat rooms, languages, messages, and reports, [Appwrite Realtime](/docs/products/databases) for synchronous chat and online status, [Appwrite Storage](/docs/products/storage) for images and audio, and [Appwrite Functions](/docs/products/functions) for streaks and push notifications."
  - question: "Why did LangX choose Appwrite over Supabase?"
    answer: "The LangX founder evaluated both. He preferred Appwrite's user experience, but the deciding factor was the active community. He posted a challenge on Appwrite's Discord and got a resolution within 24 hours, which gave him confidence that he would not be stuck on his own."
  - question: "Can I build a real-time chat app with Appwrite?"
    answer: "Yes. [Appwrite Realtime](/docs/products/databases) lets you subscribe to database events and broadcast changes to connected clients. Combined with [Appwrite Storage](/docs/products/storage) for media and [Appwrite Functions](/docs/products/functions) for notifications, you have the building blocks for a full chat app."
  - question: "What is the benefit of using Appwrite for a cross-platform app?"
    answer: "Appwrite ships SDKs for web, mobile (Flutter, React Native, iOS, Android), and server runtimes with consistent APIs. A team using Ionic Angular (like LangX) gets the same Appwrite API in their hybrid app that a web team would, so business logic stays portable."
  - question: "Does Appwrite support push notifications?"
    answer: "Yes. [Appwrite Messaging](/docs/products/messaging) supports push notifications, SMS, and email. LangX initially used Appwrite Functions to forward notifications to Firebase Cloud Messaging and plans to migrate to Appwrite Messaging for first-class delivery."
---

Born in Istanbul, Turkey, Xue never needed to prioritize learning English as a language until he pursued further education at Boğaziçi University, where his Mechanical Engineering coursework was delivered in English. After graduating and working as an IT manager at a multinational import-export company, Xue moved to Canada and founded his own tech consulting firm. By now, he had started exploring various language exchange platforms such as Tandem as a learner; however, he soon realized there was no perfect platform for his needs. 

Thus, the idea behind **LangX** was born.

# Understanding the product

LangX is an open-source language exchange platform that allows users to learn new languages by interacting and chatting with users from different parts of the world. It allows you to:

- **Sign up:** Create an account and pick the languages you can teach and the languages you want to learn.
- **Find partners:** Browse through their community of language learners to find the perfect learner-teacher match.
- **Start exchanging:** Start your language exchange journey by teaching your native language and learning a new one from folks around the world.

Around this simple philosophy, the LangX team has developed various features to gain the best possible language-learning experience, which include (but are not limited to) the following:

- A **custom-built community** that allows users to discover other members of the platform
- **Simplified chat experience** that allows users to interact one-on-one using text, images, and audio
- **Connection preferences** that offer filtering options to find the most suitable language exchange partners
- **Profile insights** to let users get a better understanding of their learning habits and progress
- **Badges** that are given for specific achievements and can be featured on the user’s profile

{% call_to_action title="All-in-one development platform" description="Use built-in backend infrastructure and web hosting, all from a single place." point1="Start for free"  point2="Open source" point3="Support for over 13 SDKs" point4="Managed cloud solution" cta="Start building" url="https://cloud.appwrite.io/" /%}

# Building the solution

Currently, LangX has been built using Ionic Angular, a cross-platform app framework that allows developers to create web and mobile apps via a single codebase. To accelerate the development process, Xue evaluated various Backend-as-a-Service platforms, including Supabase and Appwrite. Xue preferred Appwrite’s user experience to other options; however, it was Appwrite’s highly active and supportive community that convinced him. He remembers posting a challenge he faced on Appwrite’s Discord server and gaining a resolution in less than 24 hours, solidifying his decision.

LangX has been built-up and driven by Appwrite from Day 1. The team leveraged **Appwrite Databases** to store all the app’s data, including user information, chat rooms, languages, messages, reports, and visits. They leveraged **Appwrite Realtime** to enable synchronous chatting between users and track their online status. Any binary files, such as audio and images in the chats and on user profiles, are stored and managed via **Appwrite Storage**. The team has also developed multiple **Appwrite Functions** to manage unseen messages between users, track daily streaks to feature on user profiles, and send push notifications via Firebase Cloud Messaging, which they intend to migrate to **[Appwrite Messaging](https://appwrite.io/docs/products/messaging)** soon.

Some of the benefits of Appwrite that the LangX team appreciates are:

- **Simple product experience:** Appwrite’s easy-to-use interface allowed their team to set up and begin using the platform without much hassle.
- **Comprehensive suite of offerings:** Appwrite’s wide range of products, including Databases, Auth, Storage, Functions, and Realtime, fulfilled the majority of the team’s backend requirements.
- **Active community:** Appwrite’s community on Discord and GitHub has been a massive boon to the team, allowing them to collaborate more easily with the Appwrite team and quickly resolve any issues.

In Xue’s own words,

> When exploring options for the backend of my project, I sought a solution that would offer flexibility without unexpected costs. My search led me to consider open-source self-hosted alternatives, ultimately bringing me to Appwrite. With its comprehensive suite of services including authentication, database management, storage, and serverless functions, Appwrite emerged as an ideal choice for my needs. What truly impressed me was not just its robust features, but also the supportive community behind it. The Appwrite team and its community, easily accessible through Discord, promptly addressed all my inquiries, regardless of their complexity.

# What’s next for LangX

So far, LangX has been steadily growing, having garnered

- **5000+ registered users**
- **12,500 unique visitors** in the last 30 days
- **324 GB bandwidth** used on their Appwrite project in the last 90 days
- **7 Million requests** on their Appwrite project in the last 90 days

With that said, the team has a number of features in the works, which include:

- Giving and receiving **rating evaluations** from learning exchange partners to help improve the quality of interactions in the community
- A [Language Copilot](https://docs.langx.io/litepaper/library/language-copilot) to provide personalized feedback and language suggestions when interacting with other users via AI *(this will be a paid feature)*
- A new [LangX token](https://docs.langx.io/litepaper/token/token) to create a structured reward system for users, incentivizing learning on the platform

Xue and LangX have had an inspiring journey (he has grown to become a polyglot, too), and we appreciate how they have leveraged Appwrite to empower language learners around the globe. Learn more about their work and team by visiting their [website](https://langx.io/) and [documentation](https://docs.langx.io/litepaper). You can also contribute to the development of LangX by visiting their [GitHub organization](https://github.com/langx) and [Discord community](https://discord.langx.io).

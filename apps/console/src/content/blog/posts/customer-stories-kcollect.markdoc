---
layout: post
title: "K-Collect: Building a platform for 50,000 K-Pop fans"
description: Learn how Appwrite helped K-Collect develop a scalable SaaS platform for K-pop photo card management and grow their audience to over 50,000 consumers while decreasing infrastructure costs by 700%.
date: 2023-12-02
cover: /images/blog/kcollect.avif
timeToRead: 5
author: aditya-oberai
category: customer-stories
faqs:
  - question: "What is K-Collect?"
    answer: "K-Collect is a SaaS web platform that helps K-pop fans track, manage, and share their collectible photo cards. It was founded by Ryan O'Connor and grew to over 50,000 users. The platform is built on Next.js with Appwrite handling the backend."
  - question: "How did K-Collect reduce infrastructure costs with Appwrite?"
    answer: "K-Collect's previous proprietary backend (with database replication and sharding) cost about £2000 per month and still hit bottlenecks. After switching to Appwrite, total infrastructure costs dropped to £200 to £300 per month, roughly a 700% decrease, while tripling concurrent load capacity."
  - question: "Which Appwrite products does K-Collect use?"
    answer: "K-Collect uses [Appwrite Auth](/docs/products/auth) for user identity, [Appwrite Databases](/docs/products/databases) for album, artist, and photo card metadata, [Appwrite Storage](/docs/products/storage) for the photo card images, and [Appwrite Functions](/docs/products/functions) for third-party integrations like sending emails."
  - question: "Why did K-Collect move from native mobile apps to a web platform?"
    answer: "The founder is a web developer by background. The native mobile stack created problems with state management, cache invalidation, and backend scaling. Moving to a Next.js web platform unified the codebase in JavaScript and aligned with his existing skills, which sped up shipping new features."
  - question: "Why did K-Collect choose Appwrite over Firebase?"
    answer: "Firebase's pricing scaled poorly for a pre-revenue product, and the lack of transparency in the underlying code was a concern. Appwrite is open source, has predictable pricing, and offers consistent SDKs across web and Node.js, which made the migration straightforward."
  - question: "Can Appwrite handle high-traffic apps like K-Collect?"
    answer: "Yes. K-Collect serves around 3.5TB of bandwidth and 28 million requests per month on Appwrite Cloud across more than 50,000 users. Appwrite handles authentication, storage, databases, and functions at that scale without the team needing to manage servers themselves."
---

In 2019, Ryan O’Connor was a mere university student when he started exploring the world of Korean popular music, or K-pop. One of the areas within the K-pop fan ecosystem that caught his interest was the concept of photo cards. For those new to the K-pop community, K-pop photo cards are artist-specific collectible cards that are possessed and traded similarly to sports or Pokemon trading cards. These photo cards are produced and distributed by K-pop record labels and have developed a substantial interest and following over the last few years. 

As Ryan immersed himself more in this ecosystem, he observed that the K-pop community needed a platform that helped them track, manage, and share their photo cards. Being a developer, Ryan decided to take on this challenge and thus began the journey of his product for K-pop photo card management, [K-Collect](https://kcollect.net/). 


# Challenges faced with product development

In 2019, the first iteration of K-Collect, an application that lets you find new artists, organize music collections, track orders, and show off K-Pop photo cards, was developed. However, K-Collect’s choice of technology stack led them to face multiple challenges.

## Knowledge gaps around building mobile apps and backend

Initially, K-Collect built native mobile applications for their product's client-side apps and a Node.js + Express API for the server-side app. The decision to develop mobile apps was made due to the high number of mobile app users in the K-pop ecosystem. However, Ryan happened to be a web developer by skills and trade. His lack of experience with native mobile applications and server-side apps led to several problems, such as:  

  * State management on Android and iOS,  
  * Cache handling and invalidation in mobile apps,  
  * Sustaining high concurrency in Node.js APIs,  
  * Managing database bottlenecks and overloading,  
  * Backend API security, etc.  

## Cost of managing application infrastructure

Since K-Collect was building a proprietary server-side solution, it had to maintain and manage its infrastructure, featuring servers for its API, storage, and database. The various moving parts of the application led to the necessity of costly infrastructure to sustain even a small mass of consumers. This created a substantial financial burden for the product in the pre-revenue stage.

To decrease these costs and remove the burden of building an efficient and secure backend, K-Collect started exploring Backend-as-a-Service (BaaS) platforms to implement a solution. They tried various options, including established BaaS platforms like Firebase; however, expensive pricing structures and the lack of transparency in the underlying code prevented them from implementing the same.

{% call_to_action title="All-in-one development platform" description="Use built-in backend infrastructure and web hosting, all from a single place." point1="Start for free"  point2="Open source" point3="Support for over 13 SDKs" point4="Managed cloud solution" cta="Start building" url="https://cloud.appwrite.io/" /%}

# The power of an entire team

Eventually, to increase K-Collect users' access to desktop-based clients and enable them to leverage their existing skill set, they pivoted to developing a SaaS-like web platform built with Next.js in January 2023. It was at this point that Appwrite was discovered. Adopting Appwrite provided several benefits:

## Abundance of features impacting the product

One of the most important aspects of any BaaS platform is the features and offerings it provides a developer. Appwrite was a boon for K-Collect, as it offered several features for their product. Currently, K-Collect is leveraging Appwrite Authentication to manage identity (and relevant functionalities) for the entire web platform. All information related to K-pop albums, stars, and photo card metadata that K-Collect uses is stored in Appwrite Databases. The photo cards themselves are stored and consumed through Appwrite Storage. K-Collect also implements Appwrite Functions to integrate external 3rd-party services (such as sending emails) and other event-driven tasks related to the other Appwrite offerings being used.

At the moment, Ryan is developing an internal trading platform that will allow users to communicate with each other and buy/sell photo cards. For these features, Ryan will be returning to Appwrite Functions for transaction management with a payments gateway and using a combination of Appwrite Databases and Appwrite Realtime for an internal chat platform.

## Improved developer productivity due to easy SDK access

Transitioning to Next.js made the K-Collect codebase consistent with one primary programming language, JavaScript. Since Appwrite offers SDKs for both JavaScript/TypeScript-based web applications and Node.js-based server-side applications with consistent interfaces, Ryan swiftly and smoothly transitioned his application from native mobile apps to a web platform.

Additionally, having to not focus on developing a proprietary backend allowed Ryan to focus on new application functionality (such as the upcoming trading platform), improving the application’s user interface and experience, as well as building a team of 30 contributors who help with research and aggregation of upcoming K-pop music albums to maintain a release calendar.


## Decrease infrastructure costs by ~700%

In an internal meeting with the Appwrite team, Ryan shared that initially, with K-Collect’s initial proprietary solution, even after having implemented database replication and sharding with read-only nodes for a more reliable and scalable architecture, K-Collect’s infrastructure was costing them £2000 per month while still running into bottlenecks, which was unsustainable for him as the sole developer. Since switching to Appwrite, including costs for storage, bandwidth (3.5TB of bandwidth served per month and 28 million monthly requests), and web app hosting, K-Collect’s infrastructural costs have decreased to £200-£300, which is approximately a 700% decrease in costs, while being able to triple their load-managing in terms of active users and helping scale total users to over 50,000.


# Conclusion

Overall, Appwrite has helped substantially improve software performance for K-Collect while simplifying its application architecture, increasing cost savings, and helping the K-Collect team scale to over 50,000 users from scratch since January 2023. Ryan and K-Collect strongly recommend [Appwrite Cloud](https://cloud.appwrite.io) to other developers and teams looking for a simple-to-implement, robust, cost-effective Backend-as-a-Service solution.  

In Ryan's own words,  

> "The switch to using Appwrite brought infinite value that I'm still discovering today, but a major impact that it made was the amount of time and stress that it saved me as it simply just works. There's no struggling with writing backend code and working with databases, as that's already taken care of."  

Learn more about K-Collect by visiting their [website](https://kcollect.net/), and connect with their founder, Ryan O’Connor, on [Twitter](https://twitter.com/oconrdev) and [GitHub](https://github.com/oconr).

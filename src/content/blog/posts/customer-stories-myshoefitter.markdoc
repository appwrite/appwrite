---
layout: post
title: "mySHOEFITTER: Solving 75% of online shoe order returns with AI automation"
description: Learn how mySHOEFITTER utilized Appwrite Cloud and AI to enable over 12,000 customers to get their shoe size with an accuracy of 2 mm, solving a massive supply chain problem in the shoe industry.
date: 2024-03-04
cover: /images/blog/case-study-myshoefitter.avif
timeToRead: 5
author: aditya-oberai
category: customer-stories
faqs:
  - question: "What is mySHOEFITTER?"
    answer: "mySHOEFITTER is an AI tool that determines a person's shoe size from a photo of their foot placed on an A4 sheet. It captures foot length and width with millimeter precision, returning the correct size in under a minute. The technology was developed with the Fraunhofer Institute and used by over 12,000 customers."
  - question: "How does mySHOEFITTER use Appwrite?"
    answer: "mySHOEFITTER uses [Appwrite Auth](/docs/products/auth) for anonymous user accounts (which helps with GDPR compliance), [Appwrite Storage](/docs/products/storage) for foot images, [Appwrite Functions](/docs/products/functions) to call their AI and compute shoe size, and [Appwrite Databases](/docs/products/databases) for results."
  - question: "Why does mySHOEFITTER use anonymous Appwrite accounts?"
    answer: "Anonymous accounts let users start using the app without signing up, which keeps the experience frictionless and helps with GDPR compliance (no personal data is collected by default). Appwrite Auth supports creating anonymous sessions that can be upgraded to full accounts later if needed."
  - question: "Can I use Appwrite with an AI or ML pipeline?"
    answer: "Yes. [Appwrite Functions](/docs/products/functions) can call any external API, including custom ML model endpoints. Store inputs in [Appwrite Storage](/docs/products/storage), trigger a Function on upload, send the data to your model, and write the results back to [Appwrite Databases](/docs/products/databases)."
  - question: "Why did mySHOEFITTER choose Appwrite over Directus or Strapi?"
    answer: "Directus and Strapi worked well out of the box but were not easy to extend without instability. Appwrite Cloud gave the team a managed BaaS that they did not have to host themselves, plus Functions to extend the platform with custom logic for AI integration."
  - question: "What kind of return rate reduction is possible with size-fitting tools?"
    answer: "Over 30% of online shoe orders are returned, and about 75% of those returns are caused by wrong size selection. mySHOEFITTER reported a 98.8% success rate (correct sizes) in a Bayerische Landesbank pilot, meaning the right-size selection problem can be largely eliminated with accurate measurement."
---

> “Appwrite has been a tremendous asset in implementing our IT infrastructure. Not only is the software an absolute game-changer, but the team is always there when you need them. The integrated user authentication and the ease of creating data structures have undoubtedly saved us several weeks' worth of time. For me, Appwrite is the perfect backend solution. All you have to do is sign up, and your backend is ready to go. I have never seen such an innovative and easy-to-understand backend solution before!” \
>  \
> Marius Bolik,  \
> CTO, mySHOEFITTER 

As co-founder of one of the largest European e-commerce platforms for running and tennis equipment called Jogging Point and Tennis Point, Markus Piebrock has always been concerned by the very high return rates in the shoe sector. **Over 30% of all online shoe orders were returned, out of which they discovered that approximately 75% were primarily caused by customers selecting the wrong shoe size.** The problem is not uncommon. For most people, picking the correct shoe size for online purchases is a challenging experience, only made more difficult with complex instructions, wrong measurement methods, inaccurate size suggestions, and other issues.

With improvements in AI over the last few years, Markus realized that he could create a more straightforward experience for people and substantially reduce operational costs for companies by preventing size-based shoe returns. 

And thus, the idea for mySHOEFITTER was born.

# Understanding the product

mySHOEFITTER is a tool that allows people to find their shoe size accurately. It lets a person click a picture of a foot (on top of an A4 sheet) and then uses AI to capture the length and width of the foot and determine shoe size with millimeter precision. The tool also provides an ID for the foot captured, which can be reused in any platform using mySHOEFITTER to determine shoe size. The AI has been developed in close collaboration with the [Fraunhofer Institute for Applied Information Technology FIT](https://www.fit.fraunhofer.de/de/geschaeftsfelder/digitale-gesundheit/fraunhofer-anwendungszentrum-symila/projekt-highlights/biometrische-fussvermessungstechnologie-fuer-mobile-geraete.html).

mySHOEFITTER has a straightforward user flow that gets you your shoe size in less than a minute:

1. **Choose a shoe** in the online store and **launch the integrated web app** for mySHOEFITTER.
2. Place a **DIN A4 sheet** on your floor (must be a flat surface).
3. **Place your bare foot** on the sheet of paper and **click a picture** through the app.
4. **Perfectly fitting shoe size is displayed**, which you can order directly through the store.

The product not only solves the largely avoidable returns problem, saving substantial operational costs for the company, but also provides a better customer experience when using online stores. Additionally, it also enables a green supply chain, reducing carbon footprint and waste, thereby helping the environment, too.

# Building their solution

To understand more about how mySHOEFITTER was built, we had the opportunity to interact with Marius Bolik, mySHOEFITTER’s CTO. mySHOEFITTER has a web-first experience that has been enabled by the usage of Astro and Ionic for the client application. This way, Marius and the team could shift all the processing and business logic to their backend. To ensure a robust and performant backend, they decided to use a well-tested Backend-as-a-Service (BaaS) platform. Choosing the right BaaS, however, had its own challenges.

## Challenges the team faced with their backend

In the initial stages, the team explored many options for their backend. The first options they considered were Directus and Strapi since Marius had experience with both. While both tools performed well in their standard out-of-the-box configurations, they were not very extensible, and creating deeper integrations and customizations caused instability and crashes.

Marius came across Appwrite while looking for a more stable Backend-as-a-Service solution. He also had a chance to compare Appwrite with other alternatives, such as Supabase. However, he felt more inclined to pick Appwrite over Supabase due to its cleaner and simpler user interface and experience, where, in his words, the passion and love for the product felt more apparent.

{% call_to_action title="All-in-one development platform" description="Use built-in backend infrastructure and web hosting, all from a single place." point1="Start for free"  point2="Open source" point3="Support for over 13 SDKs" point4="Managed cloud solution" cta="Start building" url="https://cloud.appwrite.io/" /%}

## How Appwrite fit in their solution

In 2023, Marius joined Appwrite Cloud as an early consumer since he prefers managed solutions over self-hostable ones as a developer. His experience with Appwrite allowed him to recommend and push for adoption at mySHOEFITTER, which they did well at. 

Currently, mySHOEFITTER uses multiple Appwrite products to achieve a well-functioning solution. They use **Appwrite Authentication** to set up anonymous accounts for each user when they start using their app for the first time. This allows them to comply with data privacy regulations such as the GDPR. All feet images are stored in **Appwrite Storage** when they are captured for processing by their AI. For communication with their AI and computation of the exact shoe size, based on responses from their AI, they use Appwrite Functions. All the information received from their AI is stored in **Appwrite Databases**.

Some of the benefits of Appwrite that the mySHOEFITTER team appreciates are:

* **Clean product experience:** Appwrite’s simple-to-use interface and experience allowed their team to onboard to the platform a lot more quickly and without less hassle.
* **Availability of managed solution:** Because our managed solution, Appwrite Cloud, is available to the public, mySHOEFITTER saved a lot of time, effort, and money required to set up, manage, and scale their own infrastructure.
* **Extensibility:** Appwrite is easily extensible through our Functions product, so it is easy to integrate with external APIs, tools, and platforms.

# What’s next for mySHOEFITTER

While mySHOEFITTER has been operating at a pre-revenue stage, they have **raised  €600,000 in funding** from various investors, including the **Fraunhofer Tech Fond** and **NRW Bank**. Currently, they are focusing on customer development and have partnered with various shoe manufacturers and fashion labels. 

One of their biggest customers includes the **Weltbild D2C Group**, which has integrated mySHOEFITTER in all their fashion and shoe brands, including **Groundies**, one of the largest German fashion brands for barefoot shoes, and **Orbisana**, a well-known retailer of healthcare products. Another one of mySHOEFITTER’s current customers is **Sweetwater Marketing**, one of Germany’s largest agencies for employer branding and brand partnerships. They had a major success story during Christmas of 2023, where Sweetwater worked with **Bayerische Landesbank** to help gift 1000 of their employees a pair of white Adidas sneakers. The employees used mySHOEFITTER to find out their exact shoe size for this model and saw a **success rate of 98.8%** (i.e., 988 employees received the right shoe size).

The team at mySHOEFITTER appreciates how Appwrite Cloud enabled them to rapidly develop their core product by offering a managed Backend-as-a-Service solution that provides a quality experience and improves team productivity through its developer experience.

mySHOEFITTER has been featured on the German television channels **SWR** and **WDR** as well as the German startup magazine **[Startup Valley](https://startupvalley.news/de/aufzug-des-grauens/)** for their work and are, at the moment, planning to tackle the international markets and seeking early adopters from around the world. Over 12,000 people have already used the mySHOEFITTER web application to find their shoe sizes accurately. Learn more about their work and team by visiting their [website](https://en.myshoefitter.com/?utm_source=appwrite&utm_medium=blog&utm_campaign=case-study).

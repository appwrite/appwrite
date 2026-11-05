---
layout: post
title: "Data dilemma and cost efficiency solved: Open Mind's journey to scalable education"
description: Learn how App Innovators used Appwrite Cloud to decrease costs and deliver education about psychoactive substances to over 12,500 consumers via the Open Mind app.
date: 2024-04-12
cover: /images/blog/case-study-open-mind/cover.avif
timeToRead: 5
author: aditya-oberai
category: customer-stories
faqs:
  - question: "What is the Open Mind app?"
    answer: "Open Mind is a harm-reduction app that educates users about psychoactive substances, lets them check risky combinations, log consumption, and access emergency instructions. It was built by App Innovators in collaboration with German YouTuber OpenMind3000 (540,000+ subscribers)."
  - question: "How does Open Mind use Appwrite?"
    answer: "Open Mind hosts JSON files containing substance data on [Appwrite Storage](/docs/products/storage), one file per language. The Flutter app downloads these files via the Appwrite Flutter SDK, which supports chunked downloads and file compression out of the box."
  - question: "Why did Open Mind switch from Firebase to Appwrite?"
    answer: "Firebase's storage and bandwidth costs grew quickly as their user base scaled. Appwrite Cloud had a more predictable, lower-cost pricing model that let them serve thousands of users without breaking the bank. The Flutter SDK also made the switch quick."
  - question: "Can I use Appwrite to serve static content like JSON files?"
    answer: "Yes. [Appwrite Storage](/docs/products/storage) can host any file type, including JSON, and serve it through a public URL. This is a common pattern for distributing localized content, config files, or precomputed data to a mobile or web app."
  - question: "Does Appwrite work well for Flutter apps?"
    answer: "Yes. Appwrite has a first-party Flutter SDK with idiomatic Dart APIs across [Auth](/docs/products/auth), [Databases](/docs/products/databases), [Storage](/docs/products/storage), [Functions](/docs/products/functions), and Realtime. Flutter is a popular choice for Appwrite customers shipping cross-platform mobile apps."
  - question: "How can a small team build a multi-platform app without managing a backend?"
    answer: "Use Flutter (or another cross-platform framework) for the client and Appwrite for the backend. You get auth, databases, storage, functions, and messaging as managed services. App Innovators used this exact combination to ship Open Mind to 11,500+ Android downloads and 1000+ TestFlight users."
---

While still at school, David Forster noticed a substantial increase in the usage of narcotic substances by his peers. He saw that the consumption of narcotic substances led to a decline in the mental and physical health of these folks. However, at that time, the only educational forums on this topic that were accessible to people were Wiki pages with information that was too complex to understand. A lack of simple educational tools prevented David from helping his peers break out of a substance habit.

Therefore, as Germany closes in toward the legalization of cannabis, David and his software development agency, [App Innovators](https://app-innovators.de/), developed an app that helps people understand the different psychoactive substances available in the market, track their consumption, deal with emergencies, and more. With this, the Open Mind application was born!

# What is Open Mind?

Open Mind is an application that helps create better awareness of psychoactive substances and helps teach people how they can consume substances responsibly. It was developed in collaboration with a **prominent German YouTuber,** [OpenMind3000](https://www.youtube.com/@OpenMind3000), who regularly caters content on how different drugs work and mix, and how people can decrease risks to life when consuming such substances to an audience of **over 540,000 subscribers**.

The [Open Mind app](https://openmindapp.de/) offers the following features:

- **Information about psychoactive substances**: Descriptive information on a vast range of substances, including what they are, safe quantities to consume, and how long they remain effective.
- **Substance combination checker**: A tool that verifies how risky a combination of different substances can be to a person's health and any possible side-effects of consuming such a cocktail.
- **Trip games**: Two games (a reaction test and a memory cards game) that allow a user to evaluate their sobriety after consuming any substance(s).
- **Emergency instructions**: A set of instructions for different harmful (or risky) situations a user might experience after consuming any substance(s).
- **Consumption history log**: A self-logging tool that lets users track their consumption history, including the type of drug, quantity of intake, method of consumption, start and end of the effect, and any additional notes.

# Developing the application

The first iteration of Open Mind was designed as a mobile application. The team at App Innovators chose to use Flutter to allow them to build Android and iOS applications simultaneously at a rapid pace. While most user data is retained on each user's local device, they decided to host all data related to substances on an external platform, allowing them easier access to maintain, update, and distribute the information. They decided it was simplest to achieve the same by maintaining JSON files (one file per language they support, including German and English) with all this data that would be hosted at an external storage service.

Initially, due to their past experience, the team explored Firebase to host these files. However, Firebase's high storage and bandwidth costs forced them to explore other backend-as-a-service solutions. At this point, the team at Open Mind discovered Appwrite. The availability of **Appwrite's Flutter SDK** meant that the team could quickly adopt **Appwrite Storage**, with features such as chunked downloads and file compression available out of the box. The **plug-and-play architecture** of Appwrite's SDK would allow them to use their existing application architecture without much change. Additionally, Appwrite Cloud's more **economical pricing** allowed them to serve thousands of users regularly without breaking the bank.

In David's own words,

> We really loved working with Appwrite for launching our bootstrapped "Open Mind" App. It was saving us a lot of money in comparison to Firebase since the amount of users grew quite fast and we needed a quick switch. I am still surprised how easy the implementation into Flutter was.

{% call_to_action title="All-in-one development platform" description="Use built-in backend infrastructure and web hosting, all from a single place." point1="Start for free"  point2="Open source" point3="Support for over 13 SDKs" point4="Managed cloud solution" cta="Start building" url="https://cloud.appwrite.io/" /%}

# The journey so far

The App Innovators team made the Open Mind app available publicly on the [Google Play Store](https://play.google.com/store/apps/details?id=de.app.innovators.open_mind&hl=en&gl=US) and through Testflight for beta users on the Apple App Store.

Although localized to regions within Europe, the Android app has amassed:

- Over 11,500 downloads
- 3600+ monthly active users
- 350-500 daily active users, with the majority coming from Germany, Austria, and Switzerland

In Testflight, the Apple app amassed over 1000 beta users; however, changes in the App Store policies prevented them from further updating and promoting the Apple app. In recent times, however, the team has taken a new direction and is converting the Open Mind mobile app into a web app using Flutter Web. The current version of the web application is available at [openmindapp.de](https://openmindapp.de/) and is quickly gaining traction and users.

As the Open Mind application grows, we wish David and the App Innovators team the best of luck and look forward to their future ventures. You can learn more about them by visiting their [website](https://app-innovators.de/).

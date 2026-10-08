---
layout: post
title: "Majik Kids: Building an audio-based content platform for children"
description: Learn how Appwrite Cloud simplified their development process and accelerated productivity for Majik Kids as they worked 100s of artists, including musicians, voice artists, and illustrators, to build an alternative content platform with a focus on audio storytelling, creating a healthier learning experience for children.
date: 2024-01-19
cover: /images/blog/majik-kids.avif
timeToRead: 7
author: aditya-oberai
category: customer-stories
faqs:
  - question: "What is Majik Kids?"
    answer: "Majik Kids is an audio-first content platform for children, featuring audio stories, kids' songs across 14 genres, and meditation. It has a Kid Mode (designed for minimal screen time and easy navigation) and a Grown Up Mode (pin-protected, with parental tools and a parents' podcast)."
  - question: "How does Majik Kids use Appwrite?"
    answer: "The Flutter app uses [Appwrite Storage](/docs/products/storage) for MP3 audio, cover art, and PDF activity books, [Appwrite Databases](/docs/products/databases) for story and artist metadata, [Appwrite Teams](/docs/products/auth) to separate artists from regular users, and [Appwrite Functions](/docs/products/functions) for revenue share calculations."
  - question: "Can I build a mobile app with Appwrite?"
    answer: "Yes. Appwrite ships SDKs for Flutter, React Native, Android (Kotlin and Java), and iOS (Swift). The Flutter SDK was the key reason Majik Kids chose Appwrite, since it let them ship both iOS and Android from a single codebase with the same backend."
  - question: "How do I automate content uploads to Appwrite Storage?"
    answer: "Use the [Appwrite server SDK](/docs/sdks) in your language of choice (for example, Python, Node.js, Go) to script uploads. Majik Kids built a Python script that pulls files from Google Drive, verifies and converts them, and uploads to Appwrite Storage automatically."
  - question: "What is Appwrite Teams used for?"
    answer: "[Appwrite Teams](/docs/products/auth) groups users together and assigns permissions at the team level. Majik Kids uses Teams to separate artists from regular users, which makes it easy to give artists free access to their own content while tracking metrics for both groups independently."
  - question: "Can Appwrite handle revenue share or payouts logic?"
    answer: "Appwrite does not handle payouts directly, but [Appwrite Functions](/docs/products/functions) can run any custom logic on a schedule or in response to events. Majik Kids uses Functions to calculate revenue share for each artist based on content consumption metrics stored in Appwrite Databases."
---

# Ideating an alternative content platform for children

Being an entrepreneur in the entertainment-based education space for over 15 years and a father of a child of age 7, Bradley T. Morris from Salt Spring Island, Canada, recognized the harmful effects that excessive screen time and screen addiction can have on the nervous system of his child. He and his wife had discovered already that engaging their son, Sauryn, with audio stories while allowing them to participate in activities such as drawing and painting, did not cause similar withdrawal symptoms that screen addiction causes. 

Having observed the challenges of mainstream publishing through his existing company Majik Media, for the last 2 years, Bradley has been collaborating with local creators to produce a growing library of audio stories, kids' songs across various musical genres, and kids' meditation lessons. Although Bradley had initially envisioned that this would lead to a book publishing company, his son, Sauryn, changed his perspective about how children needed a mobile application with no more than a few buttons to interact with. Thus, the inspiration behind the Majik Kids app came to be.

# Planning their product

In 2022, the team at Majik Media began planning the application and its experience. The driving force behind their idea was the thought that their audio stories were “movies for the imagination.” To develop an application focused on children, the team had to develop an experience that enables a simple enough experience for children to engage with the application while also allowing better parental insight and support. They came up with two modes for both:

## Kid Mode

The Kid Mode is designed for the children who interact with Majik Kids. It primarily features 3 offerings:

* Audio Stories: stories that the children can listen to
* Music Library: kids’ songs ranging across 14 different genres
* Meditation: kids’ meditation to help bolster their mental health

The Kid Mode prioritizes ease of use, as recommended by Sauryn. All the offerings lead to their individual libraries of content, which directly launch the in-app music player, so children have very limited screen time while engaging with the content. 

## Grown Up Mode

The Grown Up Mode is designed for parents to use. Access to it is protected by a pin, and the mode expands on all the features in Kid Mode. All the pieces of content in the Kid Mode are available with extra information. For example, each audio story, aside from the content, features a description, information about the artists, and activity books that must be downloaded and facilitated by the parents. Additionally, it features a Parents Podcast hosted by Bradley and his wife, where they share guidance for parents while also inviting thought leaders in the parenting guidance space and an adult meditation library developed by Bradley over the last decade.

# Building like a team of hundreds

In August 2022, Bradley reached out to Phil McCluskey, the eventual Development Lead behind the Majik Kids app, to discuss the product idea. Considering the application idea, he saw Appwrite Cloud as the perfect fit for the backend.

{% call_to_action title="All-in-one development platform" description="Use built-in backend infrastructure and web hosting, all from a single place." point1="Start for free"  point2="Open source" point3="Support for over 13 SDKs" point4="Managed cloud solution" cta="Start building" url="https://cloud.appwrite.io/" /%}

## Why Appwrite was the perfect backend

There were various reasons for him to choose Appwrite: 

* **Improved productivity:** Using Appwrite meant that the engineering team would not have to build their backend or manage their infrastructure, saving a lot of development time.
* **Support for various languages:** Since Majik Kids is a mobile app, the team chose to build with Flutter to be able to cater to both iOS and Android users. Availability of Appwrite’s Flutter SDK enabled a better developer experience for them.
* **Reliability:** Phil had been aware of Appwrite in the past and used it in other projects. He had already developed confidence in Appwrite’s ability to support his application functionalities.
* **Minimal learning curve:** The team discovered that Appwrite fit their software design pattern very well out-of-the-box, and any minor challenges his team faced in working with Appwrite were covered with little effort.
* **Scalability:** With Appwrite Cloud, one of the biggest worries of the team, scaling their backend infrastructure with growing usage of the application, was instantly diminished as they would no longer have to manage their own infrastructure.


## Leveraging different Appwrite products

Keeping Appwrite as their backend, the engineering team started developing the Majik Kids app. Since the application primarily featured audio content in numerous manners, they were required to use a substantial number of binary files, such as MP3 files for the audio, image files for content covers, and PDF files for activity books. **Appwrite Storage** allowed them to quickly host all of these files, set permissions, and leverage the file URLs to consume the content easily.

Phil also developed a sustainable workflow to quickly add any artist’s works (audio story, cover artwork, etc.) to the application, thanks to Appwrite. Since Appwrite offers **SDKs for both client and server-side languages**, including Python, Phil was able to develop a custom Python script that downloads the files from the artist’s shared Google Drive, verifies the integrity of the files, makes any necessary file type conversions, and uses the Appwrite Python SDK to upload all the files to Appwrite Storage.

Any information about the works and artists themselves was stored in an **Appwrite Database**. Within the Flutter app, the team used the querying facility in Appwrite Databases to display content appropriate to the selected mode. The team leveraged **Appwrite Teams** to separate artists and regular users for more accurate tracking of metrics and enable different permissions for both (to let artists access their content for free). They also developed **Appwrite Functions** to track and manage the revenue share for the artists based on the consumption metrics of their content.

In Phil McClusky’s own words, 

> “Just like a Swiss Army Knife, you can choose and use the tools that you need with Appwrite.”

# The journey so far

As Majik Kids grows to build the first-ever Fair Pay Publishing Company for artists, i.e., all the artists receive 50% of the revenue generated from their works, they have already hit several milestones since their soft launch in December 2023. 

So far, they have

* Signed **over 100 artists**, including voice actors, producers, musicians, illustrators, etc.
* Created **20 audio stories** and homeschooling/classroom learning activities with each story
* Recorded **300+ kids’ songs** across 14 genres 
* Developed **40+ kids’ meditations** for their mental wellness

The team at Majik Kids appreciates how Appwrite Cloud **simplified their development process** and **accelerated productivity** by offering a scalable and reliable managed Backend-as-a-Service solution with significant cost savings.

Learn more about Majik Kids by visiting their [website](https://majikkids.com/).

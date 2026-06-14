---
layout: post
title: Don’t blame the readers, write the documentation they need
description: Unveiling Appwrite's new documentation. More details, just as simple, and more welcoming to contributors.
date: 2023-10-10
cover: /images/blog/dont-blame-the-readers-write-the-docs-they-need/cover.avif
timeToRead: 10
author: vincent-ge
category: design, brand
faqs:
  - question: "Why did Appwrite redesign its documentation?"
    answer: "The old docs were minimal and worked well for early open-source adopters, but newer learners needed more guidance and Appwrite's surface area kept growing. The team rebuilt the docs around the full developer journey: discover, evaluate, learn, build, scale, while keeping the original quick-start brevity for experienced developers."
  - question: "What feedback drove the documentation changes?"
    answer: "Input came from the State of Appwrite survey, the [Discord community](/discord), GitHub issues on the docs repo, Office Hours, developer interviews, and UX research sessions. The team also took notes at events like DevRelCon and Refactor DX to evaluate the design against current industry practice."
  - question: "How are the new Appwrite docs structured?"
    answer: "Each product section starts with a one-page quick start for veterans, followed by deeper conceptual docs, step-by-step tutorials, and reference material further down the navigation. The structure mirrors a typical developer journey from first read to scaling in production."
  - question: "What tools power the Appwrite docs?"
    answer: "The docs are written in an extended Markdown flavor powered by Markdoc and SvelteKit (via [svelte-markdoc-preprocess](https://github.com/TorstenDittmann/svelte-markdoc-preprocess)). Custom syntax like the multi-code selector is implemented as Markdoc tags, which keeps contributions easy while supporting rich features."
  - question: "How can the community contribute to Appwrite's documentation?"
    answer: "Docs are open source on the [Appwrite docs repo](https://github.com/appwrite/docs), written in extended Markdown. You can open issues, suggest changes, or submit pull requests. Markdown lowers the contribution bar so domain experts and product users can edit content without learning a custom DSL."
  - question: "What makes good developer documentation?"
    answer: "Good docs cover the entire developer journey rather than just API reference: helping readers discover, evaluate, learn, build, and scale with the product. They balance brevity for experts with step-by-step tutorials for newcomers, support fast search, and treat the docs themselves as a product that responds to feedback."
---

You’ve seen this exact conversation in support threads if you're active in any developer community.

"Have you read the docs?"

"No"

"Did reading the docs help?"

"Yes"

It’s convenient to just blame the developer for *not reading carefully*, but it’s also an opportunity to improve your developer experience.
Over the past year, we’ve noticed conversations like this in Appwrite communities. We took it as an opportunity to grow.

"Why don’t they read the docs?" We wanted to find out.

# Identifying the issues

The Appwrite team has always valued documentation as a core part of the product. After all, most adoption begins at the documentation. The old documentation was minimalistic and to the point and reflects our product philosophy. You can skim through it in one sitting and learn how to start building with Appwrite.

The old Appwrite docs are good but designed for a different audience and a different product. As Appwrite’s capabilities grow, so does the amount of documentation.
More features simply need more words to explain, and the single-side navigation becomes more bloated with more information than we ever intended.

Appwrite also gained popularity. While Appwrite’s early adopters are experienced open-source tinkerers who are comfortable operating with less guidance and enjoy the old documentation’s brevity, the growing number of learners and students need more help. You can expect experienced developers to understand the purpose of a tool intuitively, but learners need to know how and when to use Appwrite.

Put simply, Appwrite’s documentation has to adapt to the changing needs of Appwrite’s growing capabilities and evolving audience.

# Getting feedback

We view the Appwrite documentation as a product.
So when we notice problems, we apply the same product design processes to address them.
The Appwrite documentation needed an upgrade to adapt to Appwrite developers’ evolving needs.

Unlike many other projects, we already know what the problems are.
We receive feedback from the "State of Appwrite" surveys, the Appwrite [Discord community](https://appwrite.io/discord),
issues on the [docs repo](https://github.com/appwrite/docs), Office Hours questions, developer interviews, UX research activities,
[this discussion](https://github.com/appwrite/appwrite/discussions/5692), and more. The open-source community’s feedback gave us a head start.

# Planning the changes

First, we laid out the requirements for the new documentation.
We identified key friction points: lack of a search bar, insufficient navigation depth to abstract complex concepts,
difficult for the community to contribute, and incomplete coverage of the discover, evaluate, learn, build, and scale stages of a typical developer journey.

Then, we looked at other docs sites for inspiration.
We liked various aspects of [Stripe](https://stripe.com/docs), [React](https://react.dev/learn), [Vue](https://vuejs.org/guide/essentials/reactivity-fundamentals.html),
and [Datadog](https://docs.datadoghq.com/) docs, which inspired our design process.
We notice how their approach to documentation avoids or generates friction points.
We took what we liked, and left out what didn’t work for us.

We also attend conferences like DevRelCon and Refactor DX to learn about the latest trends in developer experience, technical writing, and accessibility to evaluate our design's strengths and shortcomings.
A bonus is the fresh eyes we pull aside every conference for in-person feedback, especially rare for a fully remote team like us.

# Building the new docs

The new Appwrite docs need to cover the entire developer journey. This means it needs to help the developers discover Appwrite, evaluate its capabilities, learn to use Appwrite, build something useful, and scale it to support product growth.

Appwrite’s new documentation has a navigation flow that aims to follow this journey. We introduced overview pages that introduce Appwrite’s capabilities, platform pages to help developers evaluate Appwrite’s design, more quick starts for frameworks to shorten the learning stage and build confidence, and expanded tutorials that help developers build and scale.

![Overview of Appwrite's new documentation](/images/blog/dont-blame-the-readers-write-the-docs-they-need/overview.avif)

Developers loved the simplicity of the old Appwrite documentation, and we didn’t want to lose that part of our identity.
Each deep dive section still begins with the same one-page quick start, which may be enough information for veterans.
More comprehensive documentation and suggested usage are available, but further down the navigation.

![Quick start in the Authentication docs page](/images/blog/dont-blame-the-readers-write-the-docs-they-need/quickstart.avif)

For learners and students, we added step by step tutorials to help you get to a working app faster and build confidence.

![A complete tutorial in the new docs](/images/blog/dont-blame-the-readers-write-the-docs-they-need/tutorials.avif)

With all the new content, the new Appwrite docs also introduce **search**. Search helps keep Appwrite docs easy to navigate.

![Search feature in new documentation](/images/blog/dont-blame-the-readers-write-the-docs-they-need/search.avif)

The new docs are written using an extended flavor of markdown made with [Markdoc and Svelte](https://github.com/TorstenDittmann/svelte-markdoc-preprocess), which makes community contributions easier. Markdown is easy to read and we can extend it with custom syntax like this multi-code selector.

``````
{% multicode %}
```js
console.log("I'm javascript");
```
```py
print("I'm python")
```
{% /multicode %}
``````

Which renders multi-select code fences like you find all over our docs.

{% multicode %}
```js
console.log("I'm javascript");
```
```py
print("I'm python")
```
{% /multicode %}

The documentation source is easy for contributors to read and allows us to extend its syntax with powerful features.

# Moving forward

The most important part of these changes is to give us *room to grow*. There is more room for content, especially community-contributed tutorials, quick starts, and integration guides. Truth is, while we build the Appwrite Platform, the community has built more successful and production-ready projects.

Even weeks after the launch of the new documentation, we’re already receiving a ton of help from the community through issues and PRs. With the power of open source, the new knowledge base for Appwrite developers will grow faster than ever before.

Checkout the [Appwrite documentation on GitHub](https://github.com/appwrite/website)
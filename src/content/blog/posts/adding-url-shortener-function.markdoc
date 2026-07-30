---
layout: post
title: Add a URL shortener function to your application
description: Learning how to add the URL shortener function template to your application.
cover: /images/blog/adding-url-shortener/cover.avif
timeToRead: 5
date: 2023-11-08
lastUpdated: 2026-06-29
author: haimantika-mitra
category: tutorial
featured: false
faqs:
  - question: "What does the URL Shortener function template do?"
    answer: "It deploys an [Appwrite Function](/docs/products/functions) that maps a long URL to a short alias, stores the mapping, and redirects on access. You configure `APPWRITE_API_KEY` and `SHORT_BASE_URL`, connect a Git repo, and you have a working URL shortener you can extend."
  - question: "How do I create a custom domain for my URL shortener function?"
    answer: "Use the Domains tab of the function to attach a custom domain like `s.example.com`. Once configured, all generated short URLs will use that domain instead of the default Appwrite Function URL, which is what most production shorteners need."
  - question: "Can I add analytics or click tracking?"
    answer: "Yes. Extend the template so that on each redirect it writes a row to a `clicks` table with the timestamp, IP, referrer, and user agent. You can then query that table (or wire it into a dashboard) for click-through rates and geographic data. The original function template intentionally keeps this minimal."
  - question: "Where are the shortened URLs stored?"
    answer: "The function template stores them as rows in an [Appwrite Databases](/docs/products/databases) table in your project. That gives you a query-able list of all generated short URLs with their target destinations and creation timestamps."
  - question: "Is the URL shortener function open source?"
    answer: "Yes, the template source code is available in the [Appwrite templates repo](https://github.com/appwrite/templates/tree/main/node/url-shortener). You can fork it, customize the slug generator, add authentication, or rewrite it in another runtime."
  - question: "Can I limit who can create short URLs?"
    answer: "Yes. By default the function endpoint is open, but you can require an Appwrite session by reading the user context from the function execution. Combine that with permission rules on the underlying table so only authenticated users can create entries."
---

Appwrite Functions are user-defined functions that can start small and scale big, deploying automatically from source control. With the introduction of function templates, you can quickly add new integrations into your app without writing additional code or managing infrastructure. Function templates are pre-built Appwrite Functions that can be integrated into your Appwrite project with just a few clicks. 

A URL shortener takes a long or complex URL and generates a shorter, more concise version. The primary purpose of a URL shortener function is to make links easier to share, manage, and remember.
For e.g https://dev.to/appwrite/introducing-appwrite-migrations-effortless-data-migration-from-your-platforms-5dhh to http://tinyurl.com/bd5usb3n 

In this blog, we’ll learn to build a URL shortener using Appwrite Functions template. The template benefits from the built-in scalability, reliability, and security of Appwrite Functions.

You can find the source code on our [templates GitHub repository](https://github.com/appwrite/templates/tree/main/node/url-shortener).

# Setting up the Template
To get started, you need to navigate to the functions page on the **[Appwrite](https://cloud.appwrite.io/)** console. From there, we will select the **Templates** tab, search for and select the **URL Shortener** function template.

![Function template](/images/blog/adding-url-shortener/functions.avif)

The function requires two variables:
- `APPWRITE_API_KEY`
- `SHORT_BASE_URL`

![Required variables](/images/blog/adding-url-shortener/variables.avif)

Once you have populated those, you can go straight to the **Connect** step.

![Connecting repository](/images/blog/adding-url-shortener/connect.avif)

Select **Create a new repository** (this will generate a GitHub repository for you with the function), and leave the production branch and root settings as default to create this function.

# Using the Function

Visit the **Domains** tab on the function page and copy the domain URL to test the function.

![Domains](/images/blog/adding-url-shortener/shortener.avif)

Go to the function URL in your web browser, and you'll see a short url like the one shown below.

# Next steps

URL shorteners often offer additional features such as tracking click-through rates, providing analytics on link usage, and allowing users to customize the shortened URL to some extent.This Function Template can be extended to perform a lot of functionalities. Some examples are:

1. **Link Analytics and Tracking:** Enhance the URL shortener to track click-through rates, geographical location of users, referral sources, and other analytics data. This could provide valuable insights into the popularity and effectiveness of shared links.
2. **Link Management Dashboard:** Create a user-friendly dashboard where users can manage their shortened links, view statistics, and perform various actions on their links.

Be sure to check out the other available Function Templates. We’ve created many that could be of use in your projects. You can find the [templates GitHub repository here](https://github.com/appwrite/templates).

For more information about Appwrite and Appwrite Functions:

1. **[Appwrite Function Docs](/docs/products/functions)**: Learn more about how to use Appwrite Functions.
2. **[Functions Announcement](https://dev.to/appwrite/serverless-your-way-unleashing-appwrite-functions-true-potential-2l4f)**: Read the full announcement on Functions 1.4.
3. **[Appwrite Discord](/discord)**: Connect with other developers and the Appwrite team for discussion, questions, and collaboration.

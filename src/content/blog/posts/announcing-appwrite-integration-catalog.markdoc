---
layout: post
title: "Announcing Appwrite's new Integrations Catalog"
description: We are beyond excited to announce the launch of our brand-new Appwrite Integrations Catalog. 
date: 2024-07-29
lastUpdated: 2026-06-29
cover: /images/blog/integrations-catalog.avif
timeToRead: 12
author: aditya-oberai
category: integrations, announcement
featured: false
faqs:
  - question: "What is the Appwrite Integrations Catalog?"
    answer: "The [Integrations Catalog](https://appwrite.io/integrations) is a directory of all the third-party providers that plug into Appwrite, organized by category like Authentication, Messaging, Storage, Payments, and AI. Each entry links to setup docs so you can wire the integration into your project without hunting through the wider documentation."
  - question: "Do integrations work with both Appwrite Cloud and self-hosted?"
    answer: "Most integrations work in both environments, but a few (for example, S3 as a storage adapter) are most useful in self-hosted deployments. Each integration's documentation page calls out where it applies and what configuration it needs."
  - question: "Which messaging providers can I use with Appwrite Messaging?"
    answer: "[Appwrite Messaging](/docs/products/messaging) supports providers including Twilio, MSG91, Telesign, Textmagic, and Vonage for SMS, Mailgun, SendGrid, and SMTP for email, and APNs and FCM for push notifications. You configure providers in the Console and then send messages through the unified Messaging API."
  - question: "How does the GitHub integration with Appwrite Functions work?"
    answer: "The GitHub integration connects an Appwrite project to a GitHub repository so that pushes to a branch automatically deploy your [Function](/docs/products/functions). It also powers function templates: you can pick a template, and Appwrite forks the source repo into your GitHub account before deploying."
  - question: "Can I use Stripe or Lemon Squeezy for subscriptions with Appwrite?"
    answer: "Yes. Stripe and Lemon Squeezy both have step-by-step integration guides that use [Appwrite Functions](/docs/products/functions) to handle webhooks and a database table to store subscription state. Functions sit between your client and the payment provider, so you do not have to run a separate backend."
  - question: "How are OAuth2 providers added to a project?"
    answer: "OAuth2 providers are configured per project in the Appwrite Console under [Auth](/docs/products/auth). You add your client ID and secret from the provider (Google, GitHub, Discord, and so on), enable the provider, and then call `createOAuth2Session` from a client SDK to start the login flow."
---

Our new Integrations Catalog is designed to empower you by providing a seamless way to integrate your favorite tools, libraries, and services with Appwrite, making your development process even more efficient and enjoyable.

# One-stop shop for all your integrations

You’re building your next big project on top of Appwrite; your backend is all set up and ready to go to production. But then you realize you need a way to sell subscriptions and send emails, and you’ve decided at the last minute you want to add more OAuth2 providers. Imagine you had to build all these integrations for your app from scratch in a short space of time. With the new [Integrations Catalog](https://appwrite.io/integrations), we have done the heavy lifting for you. You’ll find integrations for both Cloud and Self-hosted that cater to every aspect of your development needs, allowing you to build your app exactly as you intended.

# What’s inside the Appwrite Integrations Catalog?

We’ve bundled all integrations into different categories. Here are a few notable providers (with many more to come!). Check out the full catalog on the Integrations page. 

## Authentication

- [Phone auth](https://appwrite.io/docs/products/auth/phone-sms)

Phone auth allows you to authenticate users using One-Time Passwords (OTPs) sent to their mobile phones via SMS, making it a highly-convenient passwordless alternative to email-password authentication. You can use Twilio to enable phone authentication for your apps.

- [OAuth2](https://appwrite.io/docs/products/auth/oauth2)

You can set up OAuth2 with over 30 providers. OAuth is a way for apps to request limited access to a user's account on another service without needing the user's password. This keeps the user’s account more secure while making signing up and logging in all the more convenient. 

Some of Appwrite’s OAuth integrations include Amazon, Appwrite, Discord, Google, and Notion.

## Deployments

- [GitHub](https://appwrite.io/docs/advanced/self-hosting/functions)

We've integrated GitHub to simplify your development workflow with Appwrite Functions. This integration enables the generation of repositories for function templates and manages automatic deployments for any Appwrite Functions through GitHub.

## Messaging

- [Twilio](https://appwrite.io/docs/products/messaging/twilio) for SMS

You can use the Twilio provider in Appwrite Messaging to send customized SMS messages to your users. Whether you need to send them instantly or schedule them for later, these messages can serve a variety of purposes like reminders, promotions, announcements, and even custom authentication flows.

- [Sendgrid](https://appwrite.io/docs/products/messaging/sendgrid) for Email

Send custom emails to your users with Sendgrid, either instantly or on a schedule. Use it to deliver reminders, promotions, and announcements, keeping your audience engaged and informed.

- [APNs and FCM](https://appwrite.io/docs/products/messaging/send-push-notifications) for Push notifications

Use Apple Push Notifications service (APNs) or Firebase Cloud Messaging to send in-app or push notifications. Deliver app updates, promotional offers, and other messages straight to your users’ devices.

- [Vonage](https://appwrite.io/docs/products/messaging/vonage) for WhatsApp messages

Going one step further? Use Vonage to send WhatsApp messages and connect with your users in a modern, user-friendly way.

## Storage

- Amazon S3

Store your files on self-hosted instances with Amazon S3. Amazon S3 provides scalable object storage that allows you to manage and access your data seamlessly, ensuring high availability and durability.

## Logging

Monitor your application's performance by tracing errors across your Appwrite instance and fixing them faster. These tools help you proactively manage issues and deliver a more stable experience: 

- Sentry
- Raygun
- AppSignal

## AI

- Hugging Face and Generative AI

Enhance your app with AI through Appwrite's integrations. Build [with our AI function templates](https://appwrite.io/blog/post/building-with-ai-function-templates) from popular tools like Hugging Face, OpenAI, Perplexity, and more. Imagine creating [your very own AI chatbot](https://appwrite.io/blog/post/personal-chatbot-gpt-4o) with OpenAI’s GPT-4o; it's easier than you think!

## Payments

- Stripe

Use Stripe to set up app [subscriptions](https://appwrite.io/docs/tutorials/subscriptions-with-stripe/step-1) and payments. It's designed to be straightforward so you can smoothly manage your transactions.

- Lemon Squeezy

Lemon Squeezy lets you set up payments and subscriptions quickly, so you can focus on your app’s functionality. Check out [this video for a step-by-step guide](https://www.youtube.com/watch?v=dHo7lvpU6gs).

## Databases

- MongoDB Atlas and Upstash Vector

Easily manage your data using MongoDB Atlas and Upstash Vector integration. MongoDB Atlas offers a flexible database solution, and Upstash Vector improves query capabilities for fast and reliable data access. Together, they help you manage data efficiently and boost your app’s performance.

## Search

- Algolia

Improve your app’s search functionality with the Algolia integration. Algolia makes it straightforward to integrate practical search features, helping users easily find the information they need without hassle.

# Designed with your experience in mind

As with everything we do here at Appwrite, we heavily focus on the developer experience. Our main goal with the Integration Catalog is to meet your needs when integrating X with Appwrite. We focused on a set of principles that helped us deliver on our promise:

1. Appwrite developers need quick access to integration options, including everything provided in the console and future integrations.
2. Categories should group integrations, and a consistent hierarchy of information should exist on every page.
3. Finding new categories should be logical, intuitive, and fast.
4. The content should be presented in an easy-to-digest manner and help you find what you’re looking for.

Missing anything? Have feedback? Let us know on [Discord](https://appwrite.io/discord)!

# Become a Technology Partner

With the launch of our Integrations Catalog, we’re also inviting developers and companies to join us as **Technology Partners**. This is your chance to integrate your solutions with Appwrite, reach a wider audience, and add value to the developer community.

## Why become a Technology Partner?

- **Improve dev experience:** Improve the experience by collaborating on an integration.
- **Increased exposure**: Feature your integration in front of our growing community of developers.
- **Collaborative marketing**: Partner with us on co-marketing efforts to highlight your integration.
- **Dedicated developer support**: Get assistance from our team to ensure your integration is top-notch.
- **Exclusive developer tools**: Access our API documentation, SDKs, and best practices to make your integration seamless.

# How to apply

Do you think your tool plays well with Appwrite? Visit our [Integrations Catalog Technology partners page](https://appwrite.io/integrations/technology-partner) and fill out the submission form. Tell us about your company, the integration you’re proposing, and how it benefits developers using Appwrite. Our team will review your submission and get back to you with the next steps.

# More to come

We believe the Appwrite [Integrations Catalog](https://appwrite.io/integrations) will open up new possibilities for developers, making it easier than ever to build, connect, and deploy applications with Appwrite. Watch for updates on our changelog and socials as we add more integrations. 

# Resources

- [Join us on discord](https://appwrite.io/discord)
- [Visit our documentation](https://appwrite.io/docs)
- [Get started with Appwrite](https://cloud.appwrite.io/)

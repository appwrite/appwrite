---
layout: post
title: Email your users using Resend and Appwrite Messaging
description: Learn what Resend is and how to integrate it using the SMTP provider in Appwrite Messaging.
date: 2025-01-23
cover: /images/blog/integrate-resend-smtp/cover.avif
timeToRead: 4
author: aditya-oberai
category: integrations
callToAction: true
faqs:
  - question: "What is Resend?"
    answer: "Resend is an email API provider built for developers, with a clean REST API, custom domain support, and SDKs for popular languages. It also exposes SMTP credentials, which makes it easy to plug into existing email tooling."
  - question: "How does Appwrite Messaging integrate with Resend?"
    answer: "Appwrite Messaging supports a generic SMTP provider, which you point at Resend's SMTP host using your Resend API key as the password. After that, [Appwrite Messaging](/docs/products/messaging) handles delivery, scheduling, and per user targeting on top of Resend."
  - question: "What SMTP settings should I use for Resend?"
    answer: "Use smtp.resend.com on port 587 with the username 'resend' and your Resend API key as the password. Enable TLS, and set the sender email to an address on a domain you have verified in Resend."
  - question: "Do I need to verify a domain before sending email?"
    answer: "Yes. Resend requires you to verify a domain by adding DKIM and SPF DNS records. DMARC is optional but recommended for deliverability. Without verification, your emails will be rejected by most inbox providers."
  - question: "Can I use Resend for transactional and marketing emails?"
    answer: "Resend supports both, but you should split them by domain or subdomain so that promotional sends don't damage the reputation of transactional traffic. Configure separate SMTP providers in Appwrite if you want to keep the topics isolated."
  - question: "What other SMTP providers does Appwrite Messaging work with?"
    answer: "The SMTP provider is generic, so any host that exposes SMTP credentials works. Common options include Mailgun, SendGrid, Postmark, Amazon SES, and your own self hosted Postfix or Exim server."
---

Pretty much every app we develop or consume requires a medium of communication with its users, whether that is to send notifications and reminders, execute marketing campaigns and newsletters, or share security-related information like one-time passwords. Such messages are often sent by apps via email. Lately, we have noticed that **Resend** has become one of the most popular email API providers among the developer community.

Therefore, in this blog, we will explore what Resend is and how you can integrate it with Appwrite Messaging to send emails to your app’s users.

# What is Resend?

**Resend** is a modern API service for sending emails. Unlike traditional email-sending services, Resend emphasizes clean design, ease of use, and powerful integrations, enabling developers to send, track, and manage emails with minimal friction.

Some of its **key features** include:

1. **API-first design**: Resend provides a straightforward REST API, making it easy to integrate with modern applications.
2. **Custom domains**: Easily configure and send emails from your custom domain to enhance brand credibility and improve deliverability.
3. **Developer-focused**: Features like detailed logging, delivery metrics, and a clean API for easy integration align with developer needs.
4. **Support for modern languages**: Resend offers SDKs for various modern programming languages such as JavaScript, Python, Go, Rust, and more.
5. **SMTP support**: In addition to their APIs, Resend supports sending emails via SMTP, allowing seamless integration with traditional email clients and legacy systems.

# Integrating Appwrite Messaging and Resend

Appwrite Messaging offers an [SMTP provider](https://appwrite.io/docs/products/messaging/smtp) that lets you send emails through a variety of email providers, which is also what we shall use to integrate Resend. There are several steps we must follow to achieve this:

1. Verify your domain on Resend
2. Create a Resend API key
3. Create SMTP provider on Appwrite Messaging (with Resend authentication details)
4. Test the provider

> Create an account on [Resend](https://resend.com) and [Appwrite Cloud](https://cloud.appwrite.io) to proceed further with this tutorial.

## Step 1: Verify your domain on Resend

To send emails via Resend, you must own and [verify a domain](https://resend.com/docs/dashboard/domains/introduction). To do so, visit the [Domains page](https://resend.com/domains) on Resend and add your domain. Resend will then share with you a set of entries that you must add to your domain's DNS configuration. Those entries will be of three types:

- **DKIM**: list of IP addresses authorized to send email on behalf of your domain
- **SPF**: public key used to verify email authenticity
- **DMARC**: builds additional trust with mailbox providers

Adding the **DKIM** and **SPF** DNS entries is mandatory; however, the **DMARC** DNS entry is optional to add.

> Note: Resend recommends using subdomains instead of the root domain, which you can learn more about in their [docs](https://resend.com/docs/knowledge-base/is-it-better-to-send-emails-from-a-subdomain-or-the-root-domain).

![Verify your domain](/images/blog/integrate-resend-smtp/domain.avif)

Once you add the records, Resend will verify the domain and notify you on the same page.

## Step 2: Create a Resend API key

Next, you must create an API key to send emails using Resend. To do so, visit the [API Keys page](https://resend.com/api-keys) on Resend and create one. Ensure you save your key once it is created as Resend will not let you view it again for security purposes. 

![Create a Resend API key](/images/blog/integrate-resend-smtp/api-key.avif)

## Step 3: Create SMTP provider on Appwrite Messaging

Once we have our verified domain and Resend API key, we must create an SMTP provider on Appwrite to send emails to our app’s users. To do so, create a project on Appwrite Cloud, go to the **Messaging** page from the left sidebar, click on the **Providers** tab, and create a new **Email** provider. In the Email provider setup wizard, select the **SMTP** provider.

![Add email provider](/images/blog/integrate-resend-smtp/provider.avif)

The SMTP provider will require you to mandatorily add the following details:

| **Field name** |  |
| --- | --- |
| **Server host** | `smtp.resend.com` |
| **Server port** | `587` |
| **Username** | `resend` |
| **Password** | Enter your **Resend API key** |
| **Encryption** | **TLS** *(enable the Auto TLS checkbox)* |
| **Sender email** | Enter the **email address** in the format `<NAME>@<RESEND_VERIFIED_DOMAIN>` |

![Add SMTP info](/images/blog/integrate-resend-smtp/smtp.avif)

With these details, Appwrite Messaging will now use Resend to send email messages to your users.

## Step 4: Test the provider

> For this step, you must either have a [topic](https://appwrite.io/docs/products/messaging/topics) or a [target](https://appwrite.io/docs/products/messaging/targets) ready on Appwrite.

To test the provider via the Appwrite console, you must go to the **Messaging** page on your Appwrite project, click on the **Messages** tab, and create an **Email** message. You can create a test message, add a topic or target, and set the schedule option to `Now` to send the message immediately. Once the message is sent, you will be able to find it on both the **Messaging** page in your Appwrite project and the [Emails page](https://resend.com/emails) on Resend.

![Send an email](/images/blog/integrate-resend-smtp/email.avif)

You can also [send emails programmatically](https://appwrite.io/docs/products/messaging/send-email-messages#send-emails) via Appwrite’s server-side SDKs.

# More resources

And with that, you can now send emails to your users using Resend and Appwrite Messaging. If you would like to learn more about both, we have some resources that you should visit:

- [Resend’s developer documentation](https://resend.com/docs/introduction)
- [Appwrite Messaging explained](https://appwrite.io/blog/post/messaging-explained)
- [Send email messages through Appwrite Messaging](https://appwrite.io/docs/products/messaging/send-email-messages)
- [Appwrite Messaging API reference](https://appwrite.io/docs/references/cloud/server-nodejs/messaging)
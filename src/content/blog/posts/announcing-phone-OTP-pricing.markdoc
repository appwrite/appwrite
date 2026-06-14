---
layout: post
title: Announcing phone OTP login pricing change
description: To keep Appwrite Cloud sustainable, we are introducing phone OTP login pricing.
date: 2025-01-10
cover: /images/blog/announcing-phone-OTP-pricing/cover.avif
timeToRead: 4
author: eldad-fux
category: product, announcement
featured: false
callToAction: true
faqs:
  - question: "How much does phone OTP login cost on Appwrite Cloud?"
    answer: "Phone OTP rates vary by destination country because SMS costs differ widely across telecom providers. The full breakdown by region is published on the [Appwrite rates page](/docs/advanced/platform/phone-otp#rates). Pricing is per delivered message, so usage scales linearly with the number of OTP attempts you send."
  - question: "Where can I see my phone OTP usage in Appwrite?"
    answer: "Open your organization or project usage page in the Appwrite Console to see SMS usage broken down by month and destination. This helps you forecast spend before the billing cycle and identify any unexpected spikes in OTP volume."
  - question: "How does phone OTP login work?"
    answer: "When a user requests login, the server generates a short, time-limited code and sends it to the user's phone via SMS. The user enters that code in your app to prove possession of the phone number, and the server validates it before issuing a session. The code typically expires within a few minutes."
  - question: "How can I reduce SMS OTP costs?"
    answer: "Combine SMS OTP with other authentication factors so you only fall back to SMS when necessary. Options include email OTP, magic URLs, OAuth providers, passkeys, or [TOTP-based 2FA](/docs/products/auth). You can also enforce rate limits and CAPTCHA on the OTP endpoint to discourage abuse that drives up costs."
  - question: "What authentication methods does Appwrite Auth support besides SMS OTP?"
    answer: "Appwrite Auth supports email and password, magic URLs, email OTP, phone OTP, anonymous sessions, JWT, OAuth across 30+ providers, and TOTP-based two-factor authentication. See [Appwrite Auth](/docs/products/auth) for the full list and integration guides."
  - question: "Is phone OTP login secure?"
    answer: "SMS-based OTPs are stronger than passwords alone because they require possession of the user's phone, but they are not immune to SIM swapping or interception attacks. For sensitive accounts, pair SMS OTP with a second factor such as TOTP or use TOTP as the primary method instead."
---

One-time password (OTP) logins with SMS provide a secure and convenient way for users to authenticate themselves, ensuring that only authorized individuals can access accounts. By sending a temporary, time-sensitive code via SMS, phone OTP logins offer a strong layer of security, helping prevent unauthorized access and protecting user data. This service has become a crucial part of many applications, and we've worked hard to make it as easy and scalable as possible.

When we launched Appwrite Cloud in beta, we focused on enabling the platform and providing you with as much value as possible. We intentionally didn't charge for every service, even in cases where we were absorbing significant costs ourselves - like phone OTP logins, where sending SMS messages through various telecom companies incurred a considerable expense.

In the last few months, we've worked tirelessly with various vendors, navigating complex regulations and bureaucracy in multiple countries to ensure excellent phone OTP message delivery. Our goal was always to integrate phone OTP logins as simply as possible while providing a scalable and reliable service.

It's been incredibly rewarding to see teams successfully implement phone OTP logins, using Appwrite to authenticate hundreds of thousands of users every month securely. Seeing this impact reinforces the importance of continuing to build a secure, accessible, and sustainable platform.

# Pricing update

As Appwrite continues to grow, we need to ensure that the platform remains sustainable and that we can provide the best possible service to all our users. Starting February 10th, we will begin charging for phone OTP login attempts. This change will help cover the costs associated with providing this valuable service and allow us to keep improving and expanding the platform.

Please refer to our [rates page](/docs/advanced/platform/phone-otp#rates) for a breakdown of the new pricing, including rates by region and specific SMS costs.

To help prepare for these changes, each team can visit their organization's usage page or the usage section of a specific project to review their current phone OTP usage. This will help you better understand your usage patterns and plan accordingly.

![console image](/images/blog/announcing-phone-OTP-pricing/cover.avif)

# We're here to help

If you have any questions about this change or need assistance with adjusting your application, please don't hesitate to contact us at [billing@appwrite.io](mailto:billing@appwrite.io).

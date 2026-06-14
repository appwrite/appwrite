---
layout: post
title: "How Appwrite's Magic Link auth improves user experience"
description: Learn how Appwrite's Magic Link authentication works, how to implement the two-step flow, and when to use it over passwords or email OTP for better UX.
date: 2026-03-27
cover: /images/blog/appwrite-magic-link/cover.avif
timeToRead: 4
author: aditya-oberai
category: product, tutorial, security
featured: false
unlisted: true
faqs:
  - question: "What is Magic Link authentication in Appwrite?"
    answer: "Magic Link is a passwordless auth method where the user enters their email, receives a short-lived link, and clicks it to log in. Appwrite generates a token, sends it embedded in a URL you control, and your app exchanges the token for a session when the user lands on the verification page. There is no password to choose, remember, or reset."
  - question: "How do I implement Magic Link with the Appwrite SDK?"
    answer: "It is a two-step flow. First, call account.createMagicURLToken with a unique userId, the user's email, and a redirect URL in your app. Appwrite appends userId and secret query parameters to that URL and emails it to the user. On your verification page, read those parameters and call account.createSession with userId and secret to log the user in."
  - question: "What happens if the email is already attached to an Appwrite account?"
    answer: "Appwrite ignores the userId you pass to createMagicURLToken and sends the magic link to the existing account owner. If the email is new, Appwrite creates a new account with the userId you supplied. This means the same flow handles both signup and login without branching logic in your app."
  - question: "Is the magic link token reusable?"
    answer: "No. The token is single-use and short-lived. Once a session has been created with createSession, replaying the same URL will fail. Tokens also expire on their own, which limits the window an intercepted link could be exploited."
  - question: "When should I use Magic Link instead of passwords or email OTP?"
    answer: "Magic Link is a strong fit for tools where users check email regularly, for products that want fast onboarding, and for cases where reducing password reset support tickets is valuable. Consider passwords or OTP when users may be on devices without easy email access, or when your audience expects to share credentials with a password manager workflow."
---

Password-based authentication adds friction at every step. Users forget passwords, pick weak ones, or reuse them across sites. Your app pays the cost in support tickets, reset flows, and abandoned signups.

Magic Link authentication removes the password entirely. A user enters their email address, gets a link, clicks it, and is logged in. No password to create, no password to remember, no reset flow to build.

Appwrite supports [Magic Link](/docs/products/auth/magic-url) authentication out of the box, with a clean two-step implementation that integrates with your existing auth setup.

# How Magic Link authentication works

The flow has two phases: sending the magic link, and creating the session after the user clicks it.

When a user requests a magic link, Appwrite generates a short-lived token and sends it to their email address embedded in a URL. The URL points to a page you control in your app. When the user clicks the link, your app reads the token and user ID from the query parameters and exchanges them for a session.

That is the complete flow. There are no cookies to manage on the initial request, no passwords to hash, and no password reset infrastructure to maintain separately.

# Implementing the two-step flow

## Step 1: Request the magic link

Call `account.createMagicURLToken` with a unique user ID, the user's email address, and the URL in your app where you want to handle verification:

```js
import { Client, Account, ID } from "appwrite";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>');

const account = new Account(client);

const token = await account.createMagicURLToken({
    userId: ID.unique(),
    email: 'user@example.com',
    url: 'https://yourapp.com/verify'
});
```

Appwrite appends `secret` and `userId` as query parameters to the redirect URL before sending it in the email. The user receives a message with a link like:

```
https://yourapp.com/verify?userId=abc123&secret=xyz789
```

If the email address is new, Appwrite creates a new account using the `userId` you supplied. If the email is already attached to an existing account, Appwrite ignores the `userId` you provided and sends the magic link to the existing account owner.

## Step 2: Create the session

Your verification page reads the query parameters and calls `account.createSession`:

```js
import { Client, Account } from "appwrite";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>');

const account = new Account(client);

const urlParams = new URLSearchParams(window.location.search);
const secret = urlParams.get('secret');
const userId = urlParams.get('userId');

const session = await account.createSession({ userId, secret });
```

After this call succeeds, the user is authenticated. Appwrite sets the session cookie and the user can access protected resources. The token is single-use and expires, so replaying the URL after the session is created will fail.

# UX benefits that compound over time

Removing passwords is not just a convenience improvement. It affects multiple points in the user journey:

**Onboarding** is faster. New users do not need to choose and confirm a password before they can see the app. The signup and login flows merge into a single action.

**Support load drops.** Password reset is one of the most common support requests for any app with user accounts. Magic Link eliminates it entirely for users who choose that method.

**Security improves by default.** There is no password database to breach, no weak passwords to crack, and no credential stuffing attacks against your users. The only attack surface is the user's email inbox, which is already a trust anchor for most authentication flows anyway.

# When to use Magic Link vs other methods

Magic Link is not the right choice for every app or every user.

**Use Magic Link when:**
- Your users check email regularly, typically on desktop or in a professional context.
- You want to reduce support tickets from forgotten passwords.
- You are building a tool where the email inbox is already part of the workflow.
- You want fast onboarding for infrequent users who would otherwise forget their password between sessions.

**Consider other methods when:**
- Your users are on mobile and may not have easy access to their email client during the login flow.
- Low latency matters, such as in gaming or real-time collaboration apps. Waiting for an email introduces friction that passwords do not.
- Users are offline or in environments where email delivery is unreliable.

For mobile-first apps or users who want a faster in-app experience, email OTP is a close alternative. Instead of clicking a link, the user receives a short numeric code they enter directly in your app. The authentication logic is similar, but the UX fits mobile better and does not require leaving the app to check email.

# Magic Link vs email OTP

Both Magic Link and Email OTP are passwordless and use the user's email as a trust anchor. The implementation is also similar: both involve a token that expires after a short window.

The difference is user experience. Magic Link sends users out of your app and back, which works well on desktop where switching to an email client is a single click. Email OTP keeps the user in the app, which is smoother on mobile.

Appwrite supports both. You can offer Magic Link for desktop users and Email OTP for mobile users, or let users choose their preferred method. Neither requires maintaining separate token infrastructure since Appwrite handles generation, delivery, and validation for both.

# Build passwordless authentication with Appwrite

- [Magic URL authentication docs](/docs/products/auth/magic-url)
- [Email OTP authentication docs](/docs/products/auth/email-otp)
- [Appwrite Authentication overview](/docs/products/auth)
- [Sign up for Appwrite Cloud](https://cloud.appwrite.io)

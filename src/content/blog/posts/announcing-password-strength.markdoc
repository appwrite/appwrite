---
layout: post
title: "Announcing Password strength: minimum length and character requirements"
description: Appwrite Auth now lets you enforce password strength with a minimum length and required character types, so users set stronger passwords at sign-up.
date: 2026-06-09
cover: /images/blog/announcing-password-strength/cover.avif
timeToRead: 4
author: harsh-mahajan
category: announcement
featured: false
faqs:
  - question: "What is password strength in Appwrite Auth?"
    answer: "Password strength is a security setting on the Auth service that defines the minimum requirements a password must meet. You can set a minimum length and require any combination of an uppercase letter, a lowercase letter, a number, and a special character. The rules are checked when a user signs up and whenever they change their password."
  - question: "Which character requirements can I enforce?"
    answer: "You can require an uppercase letter, a lowercase letter, a number, and a special character. Each requirement is an independent toggle, so you can turn on as few or as many as your app needs, on top of the minimum length you set."
  - question: "Does password strength apply to existing users?"
    answer: "It does not invalidate passwords that were already set. Existing users keep their current password and can still sign in, but the new requirements apply the next time they create or change a password. This is the same approach Appwrite takes with email policies."
  - question: "How do I configure password strength?"
    answer: "Open your project in the Appwrite Console, go to Auth in the sidebar, open the Security tab, and find the Password strength card. Set the minimum length, toggle the character requirements you want, and click Update. See the [Security documentation](/docs/products/auth/security#password-strength) for details."
  - question: "How does password strength work with password history and dictionary?"
    answer: "They stack. Password strength enforces composition rules like length and character types, [password history](/docs/products/auth/security#password-history) blocks reuse of recent passwords, and the [password dictionary](/docs/products/auth/security#password-dictionary) rejects the 10,000 most common passwords. Together they push users toward passwords that are both unique and hard to guess."
  - question: "What makes a password strong?"
    answer: "A strong password is long and hard to predict. Length has the biggest impact, since each additional character multiplies the number of combinations an attacker has to try. A mix of uppercase letters, lowercase letters, numbers, and special characters raises that count further, and avoiding common words, predictable patterns, and personal information keeps the password from being guessed quickly."
  - question: "How long should a password be?"
    answer: "Most modern guidance, including NIST SP 800-63B, recommends a minimum of at least 8 characters and support for much longer passwords, often 64 characters or more. Longer is generally stronger, and a passphrase made of several words is usually both harder to crack and easier to remember than a short password packed with symbols."
  - question: "Do password character requirements actually improve security?"
    answer: "Character requirements increase the effort needed to brute-force a password, but on their own they can nudge users toward predictable patterns like Password1! that technically pass the rules. They work best alongside a solid minimum length and a check against common or breached passwords, rather than as the only safeguard."
---

Appwrite Auth already helps users pick safer passwords. You can block the reuse of recent passwords with password history, reject the most common passwords with the password dictionary, and stop users from putting their name, email, or phone number in a password. One basic control was missing: enforcing how a password is actually built, like a minimum length or requiring a number.

Today, we are announcing **Password strength**, a new setting in the Auth service that lets you enforce a minimum length and required character types on every password.

# What password strength gives you

Password strength adds composition rules to the password checks Appwrite already runs at sign-up and on password changes:

- **Minimum length**: set the smallest number of characters a password is allowed to have.
- **Character requirements**: require an uppercase letter, a lowercase letter, a number, and a special character. Each one is an independent toggle, so you decide which apply.

The rules are evaluated when a user creates an account and whenever they change their password. A password that does not meet the configured requirements is rejected, so weak passwords never make it into your project in the first place.

# Configure it from the Console

Password strength is configured per project from the Appwrite Console.

1. Open your project in the Appwrite Console.
2. Navigate to **Auth** in the sidebar.
3. Open the **Security** tab.
4. In the **Password strength** card, set the **Minimum length** and toggle the **Character requirements** you want to enforce.
5. Click **Update** to apply the changes.

![The Password strength settings in the Appwrite Console's Auth Security tab](/images/blog/announcing-password-strength/password-strength.avif)

From that point on, every new sign-up and password change in your project is held to the rules you set.

# When to reach for it

Reach for Password strength when your app needs a baseline you can guarantee rather than a suggestion you hope users follow. A few common cases:

- **Compliance and enterprise requirements**: many security policies mandate a minimum length and a mix of character types. A minimum length of 8 or more with at least one number and one special character is a typical starting point.
- **Reducing brute-force risk**: longer passwords with a larger character set are exponentially harder to guess. Combined with [rate limits](/docs/advanced/platform/rate-limits), this meaningfully shrinks the attack surface.
- **Layering with existing checks**: Password strength sets the shape of a password, while [password history](/docs/products/auth/security#password-history), the [password dictionary](/docs/products/auth/security#password-dictionary), and the personal data check filter out passwords that are weak for other reasons. Turning them on together gives you defense in depth.

Existing users are not locked out when you tighten the rules. Their current password keeps working, and the new requirements take effect the next time they set or change a password.

# Get started with Password strength

Password strength is available in the **Security** tab of the Auth service. Open your project, set a minimum length and the character requirements that fit your app, and click **Update**.

- [Auth security settings](/docs/products/auth/security#password-strength)
- [Email and password login](/docs/products/auth/email-password)
- [Rate limits](/docs/advanced/platform/rate-limits)
- [Create a free project on Appwrite Cloud](https://cloud.appwrite.io)

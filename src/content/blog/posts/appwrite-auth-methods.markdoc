---
layout: post
title: "Appwrite Auth explained: every auth method, compared"
description: A complete comparison of every Appwrite auth method, from email/password to OAuth2, magic URLs, OTP, and MFA, with guidance on when to use each.
date: 2026-03-23
cover: /images/blog/appwrite-auth-methods/cover.avif
timeToRead: 5
author: aditya-oberai
category: product, tutorial, security
featured: false
unlisted: true
lastUpdated: 2026-05-20
faqs:
  - question: "How many authentication methods does Appwrite support?"
    answer: "Appwrite supports ten authentication methods out of the box: email and password, phone SMS, magic URL, email OTP, OAuth2, anonymous sessions, JWT, SSR auth, custom tokens, and MFA. Each fits a different use case, from passwordless flows to server-rendered apps to migrating users from existing systems."
  - question: "How does Appwrite store and protect passwords?"
    answer: "Appwrite hashes passwords with Argon2, which is widely considered the gold standard for password hashing today and is resistant to brute-force attacks. The platform also rate-limits login attempts, but you still need to enforce HTTPS and prompt users to verify their email after registration."
  - question: "When should I use magic URL versus email OTP?"
    answer: "Magic URL sends a one-time link the user clicks to sign in, which works well on desktop where switching to email and back is low friction. Email OTP sends a numeric code that the user types into your app, which is often less friction on mobile because it avoids the link round-trip between email and app."
  - question: "Does Appwrite support multi-factor authentication?"
    answer: "Yes. Appwrite MFA adds a second verification step on top of any primary auth method and supports TOTP-based authenticator apps and email OTP as factors. MFA is recommended for any app handling sensitive data, financial information, or accounts with elevated privileges, and is often required for B2B SaaS enterprise customers."
  - question: "What is the difference between authentication and authorization in Appwrite?"
    answer: "Authentication proves identity, while authorization decides what that identity can do. Appwrite separates them cleanly: auth methods establish a session, while teams and labels handle authorization. Teams group users with roles and labels tag individual users with arbitrary strings, both of which can be referenced from permission rules."
  - question: "When should I use custom tokens?"
    answer: "Custom tokens let your backend issue a short-lived token that a client exchanges for an Appwrite session. They are useful when migrating users from an existing auth system without forcing a password reset, or when integrating auth flows not covered by the built-in methods (such as biometrics or proprietary SSO)."
---

Picking the wrong auth method early in a project creates real pain later. You end up bolt-on patching SMS flows onto a system built only for email, or adding OAuth2 to an app that was never designed for external identity providers. Getting the choice right from the start saves you that work.

[Appwrite](/docs/products/auth) supports ten authentication methods out of the box: email/password, phone SMS, magic URL, email OTP, OAuth2, anonymous sessions, JWT, SSR auth, custom tokens, and MFA. Each one fits a different use case. This post breaks them all down so you can choose with confidence.

# Email and password

Email and password is the baseline for most apps. Appwrite stores passwords hashed with Argon2, which is resistant to brute-force attacks and is widely considered the gold standard for password hashing today.

**When to use it:** Any app where users are comfortable managing their own credentials. It works for most B2C and B2B products, especially when you want full control over the login experience without depending on a third-party identity provider.

**Trade-offs:** You take on responsibility for the password reset flow, email verification, and account recovery. Users also have to remember yet another password, which drives adoption of weak credentials. Pairing email/password with MFA significantly mitigates this.

**Security note:** Appwrite handles the Argon2 hashing and rate-limit login attempts, but you still need to enforce HTTPS and prompt users to verify their email address after registration. You can also configure [email policies](/docs/products/auth/email-policies) to block sign-ups from free, aliased, or disposable email providers.

[Read the Email/password auth docs](/docs/products/auth/email-password).

# Phone SMS

Phone authentication sends a one-time code via SMS. The user enters the code to confirm ownership of the phone number and complete sign-in.

**When to use it:** Apps where phone number is the primary identifier, such as ride-sharing, food delivery, or any product that needs to tie an account to a real-world person. Also useful when users are unlikely to have or remember an email address.

**Trade-offs:** SMS delivery depends on carrier reliability. Codes can be intercepted via SIM-swapping attacks, which makes SMS OTP less secure than TOTP-based MFA. You also pay per SMS message, so high sign-in frequency adds up.

**Security note:** SMS auth is stronger than no second factor but weaker than authenticator app-based MFA. For high-security applications, layer it with additional verification.

[Read the Phone SMS auth docs](/docs/products/auth/phone-sms).

# Magic URL

Magic URL sends a one-time link to the user's email address. Clicking the link signs them in without a password.

**When to use it:** Apps where friction at login directly hurts retention. SaaS tools, newsletters, developer tools, and internal dashboards all benefit from passwordless auth. If your users reliably check email and you do not want them to manage passwords, magic URL is a strong choice.

**Trade-offs:** Login depends entirely on email access. If a user loses access to their inbox, they lose access to your app. The user also has to switch to their email client to complete login, which adds one extra step compared to entering a password.

**Security note:** Magic links are single-use and expire. Keep expiry times short (under 15 minutes) to reduce the window for link interception.

[Read the Magic URL auth docs](/docs/products/auth/magic-url).

# Email OTP

Email OTP works like magic URL but sends a numeric code instead of a link. The user enters the code in your app to complete sign-in.

**When to use it:** Environments where clicking a link is inconvenient, such as mobile apps where switching to email then returning to the app creates friction. A 6-digit code is faster to transcribe than navigating back from a browser.

**Trade-offs:** Users have to switch to their email app and read the code. This is marginally more friction than magic URL on desktop but often less friction on mobile. Code expiry and single-use constraints are the same as magic URL.

[Read the Email OTP auth docs](/docs/products/auth/email-otp).

# OAuth2

OAuth2 lets users sign in with an existing account from a third-party provider. Appwrite supports over 30 OAuth2 providers, including Google, GitHub, Apple, Microsoft, and Discord.

**When to use it:** Any app where reducing sign-up friction matters. OAuth2 removes the need for users to create and remember new credentials. It also gives you a verified email address and, depending on the provider, additional profile data without asking the user to fill out a form.

**Trade-offs:** You depend on the provider. If Google or GitHub has an outage, users cannot log in. Provider account deletion also means loss of access to your app. Apple Sign-In obfuscates email addresses by default, which complicates email-based communication.

**Security note:** Validate the OAuth2 state parameter to prevent CSRF. Let Appwrite handle the token exchange; do not expose your OAuth2 client secret in client-side code.

[Read the OAuth2 auth docs](/docs/products/auth/oauth2).

# Anonymous sessions

Anonymous sessions create an account without any credentials. Users can interact with your app before committing to registration.

**When to use it:** E-commerce carts, games, or any flow where you want users to start before they sign up. Anonymous sessions lower the barrier to entry significantly.

**Trade-offs:** Anonymous accounts are not recoverable if the session is lost. You need to handle the conversion flow from anonymous to a named account, prompting the user to add credentials at the right moment before they lose data.

[Read the Anonymous auth docs](/docs/products/auth/anonymous).

# JWT auth

JWT auth lets you attach a signed JSON Web Token to requests from your backend. Appwrite validates the token and executes the request in the context of the associated user.

**When to use it:** Server-rendered apps or custom backends that need to make Appwrite requests on behalf of a specific user. JWTs bridge your existing auth layer with Appwrite when you are not using Appwrite's own session management.

**Trade-offs:** JWTs carry an expiry risk. A compromised token remains valid until expiry unless you implement a revocation mechanism. Keep JWT lifetimes short and store them securely.

[Read the JWT auth docs](/docs/products/auth/jwt).

# SSR auth

SSR auth is designed for server-side rendering frameworks like Next.js, SvelteKit, and Nuxt. It allows you to read and write session cookies on the server, keeping session management consistent between server and client renders.

**When to use it:** Any SSR or hybrid app where session cookies need to be available during the initial server render. Without SSR auth, server-rendered pages do not have access to the client's session, causing hydration mismatches and requiring extra client-side fetches.

[Read the SSR auth docs](/docs/products/auth/server-side-rendering).

# Custom tokens

Custom tokens let your backend generate a short-lived token that a client can exchange for a full Appwrite session. You control who gets a token and under what conditions.

**When to use it:** Migrating users from an existing auth system without forcing a password reset. Also useful for auth flows not covered by the built-in methods, such as biometrics handled outside Appwrite or a proprietary SSO system.

**Trade-offs:** You are building and maintaining the token issuance logic. Security is your responsibility on the issuance side.

[Read the Custom token auth docs](/docs/products/auth/custom-token).

# Multi-factor authentication (MFA)

MFA adds a second verification step on top of any primary auth method. Appwrite supports TOTP-based authenticator apps and email OTP as MFA factors.

**When to use it:** Any app handling sensitive data, financial information, or user accounts with elevated privileges. For B2B SaaS, offering MFA is often a requirement for enterprise customers.

**Trade-offs:** MFA adds a step to the login flow. Some users will find it annoying. Making MFA optional but encouraged (rather than mandatory) often works better for consumer apps. For admin accounts, mandatory MFA is worth the friction.

[Read the MFA auth docs](/docs/products/auth/mfa).

# Teams and labels for access control

Authentication is about proving identity. Authorization is about what that identity can do. Appwrite separates the two cleanly.

Teams let you group users and assign roles within the group. A user can belong to multiple teams with different roles in each. Labels let you tag individual users with arbitrary strings and then use those tags in permission rules. Together, teams and labels give you a flexible access control layer without writing custom middleware.

[Read the Teams docs](/docs/products/auth/teams). [Read the Labels docs](/docs/products/auth/labels).

# Choosing the right method

Here is a quick reference:

- **Email/password:** Solid default for most apps. Add MFA for sensitive accounts.
- **Phone SMS:** Best when phone number is the primary identifier.
- **Magic URL:** Low-friction desktop web flows where users check email.
- **Email OTP:** Mobile apps where switching to a browser link is inconvenient.
- **OAuth2:** Fastest sign-up experience. Use when third-party provider dependency is acceptable.
- **Anonymous sessions:** Pre-registration flows, shopping carts, demos.
- **JWT:** Server-to-Appwrite requests on behalf of a user.
- **SSR auth:** Server-rendered frameworks requiring session access on first render.
- **Custom tokens:** Auth migrations and external SSO integrations.
- **MFA:** Second layer for any method, strongly recommended for sensitive data.

# Start building with Appwrite Auth

Appwrite Auth handles the complexity of credential storage, session management, and provider integrations so you can focus on your product. Every method described here is available in Appwrite Cloud with no additional setup.

- [Appwrite Auth overview](/docs/products/auth)
- [Sign up for Appwrite Cloud](https://cloud.appwrite.io)

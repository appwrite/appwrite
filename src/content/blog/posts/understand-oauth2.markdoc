---
layout: post
title: "Understanding OAuth2: The backbone of modern authorization"
description: A quick guide to OAuth2, its flows, and when to use each one.
date: 2025-06-12
cover: /images/blog/understand-oauth2/cover.avif
timeToRead: 06
author: laura-du-ry
callToAction: true
unlisted: true
category: product
faqs:
  - question: "What is OAuth2 in simple terms?"
    answer: "OAuth2 is an authorization standard that lets a user grant an app limited access to their data on another service without handing over their password. Instead, the app receives a scoped access token that it uses to call the resource server on the user's behalf."
  - question: "What is the difference between OAuth2 and OpenID Connect?"
    answer: "OAuth2 handles authorization (what an app is allowed to do), while OpenID Connect (OIDC) is a thin layer on top of OAuth2 that adds authentication (who the user is) via an ID token. If you need to log a user in, you generally want OIDC, not raw OAuth2."
  - question: "Which OAuth2 flow should I use?"
    answer: "Use the Authorization Code flow with PKCE for SPAs and mobile apps, the Authorization Code flow for traditional server side web apps, and the Client Credentials flow for machine to machine API calls. Avoid the Implicit and Resource Owner Password flows in new code, they are now considered legacy."
  - question: "Does Appwrite support OAuth2 login?"
    answer: "Yes. [Appwrite Auth](/docs/products/auth) supports OAuth2 sign-in with major providers (Google, GitHub, Apple, and many others) out of the box. You configure the provider in the console and call the OAuth2 method in your SDK to start the flow."
  - question: "What is PKCE and when do I need it?"
    answer: "PKCE (Proof Key for Code Exchange) is an extension to the Authorization Code flow that prevents intercepted authorization codes from being exchanged by an attacker. You need it for any public client, including SPAs and mobile apps, where you cannot safely store a client secret."
  - question: "What is the difference between an access token and a refresh token?"
    answer: "An access token is short lived and used to call the resource server. A refresh token is longer lived and used only to get a new access token when the old one expires. Treat refresh tokens like credentials and store them securely server side whenever possible."
---

Modern applications rarely operate in isolation. Whether it's logging in with Google or sharing data with a third-party service, users demand interoperability and security. That’s where OAuth2 steps in: a powerful protocol designed to delegate access without compromising user credentials.

This guide explains OAuth2, how it works, the different flows available, and when to use each one, helping you build secure, scalable authorization experiences.

# What is OAuth2?

OAuth2 is an open standard for authorization. It allows users to grant limited access to their resources on one service to another service without sharing credentials.

Rather than handing out a username and password, users authorize apps to act on their behalf using access tokens. OAuth2 ensures that:

- Apps never directly handle user credentials.
- Users retain control over what permissions they grant.
- Access can be easily revoked.

# Core components of OAuth2

Before diving into the flows, it's important to understand the key players:

- **Resource owner**: The user who authorizes access to their data.
- **Client**: The application requesting access.
- **Authorization server**: Issues access tokens after authenticating the user.
- **Resource server**: Hosts the protected resources.

These components work together to ensure secure authorization across systems.

Refer to the OAuth2 [documentation](/docs/product/auth/oauth2) for complete technical details.

# How OAuth2 works: A simple flow

1. **Authorization request**: The client asks the resource owner for permission.
2. **Authorization grant**: If the user consents, the server issues a grant (authorization code, token, etc.).
3. **Token request**: The client exchanges the grant for an access [token](/docs/products/auth/tokens).
4. **Resource access**: The client uses the token to access protected resources.

Tokens are typically short-lived and scoped, meaning they only allow the operations the user approved.

# Major OAuth2 flows

OAuth2 offers different "flows" to accommodate various scenarios. Here's a breakdown of the major ones:

## 1. Authorization code flow

**Best for**: Server-side applications

- User authenticates via browser.
- Client receives an authorization code.
- Server exchanges the code for an access token.

**Advantages**:

- Highly secure (authorization code exchanged server-side).
- Supports refresh tokens.

**Typical use cases**:

- Web apps with secure backend servers.

{% call_to_action title="Customer identity without the hassle" description="Add secure authentication for your users in just a couple of minutes." point1="Multiple OAuth providers" point2="Built-in security" point3="Custom roles and permissions" point4="Integrates with your favourite SDK" cta="Get started" url="https://cloud.appwrite.io/" /%}


## 2. Authorization code flow with PKCE (Proof Key for Code Exchange)

**Best for**: Mobile and SPA (Single Page Applications)

- Similar to Authorization Code Flow, but with an added security layer (PKCE).
- Prevents interception attacks.

**Advantages**:

- Stronger protection for public clients.

**Typical use cases**:

- Mobile apps, SPAs.

## 3. Client credentials flow

**Best for**: Machine-to-machine (M2M) communication

- No user interaction.
- Client authenticates itself to obtain an access token.

**Advantages**:

- Efficient for service-to-service communication.

**Typical use cases**:

- APIs accessed by backend services.

## 4. Implicit Flow (Legacy)

**Best for**: SPAs (historically)

- Tokens returned directly in browser URL.
- Faster but less secure.

**Note**: Now largely replaced by Authorization Code Flow with PKCE due to security risks.

## 5. Device authorization flow

**Best for**: Devices without browsers/keyboards

- User authenticates on a separate device.
- Device polls authorization server for approval.

**Typical use cases**:

- Smart TVs, IoT devices.

[Appwrite Auth](/products/auth) supports all major OAuth2 flows, making it easy to integrate secure authentication into any app

# OAuth2 Tokens: Access and refresh

OAuth2 commonly uses two types of tokens:

- **Access Token**: Grants access to protected resources.
- **Refresh Token**: Used to obtain new access tokens without re-authenticating the user.

Tokens are often JWTs (JSON Web Tokens) containing claims about the user and the permissions granted.

# When to Use OAuth2

- **Third-party integrations**: Allowing users to connect external services securely.
- **APIs**: Protecting APIs from unauthorized access.
- **Mobile and web Apps**: Enabling secure login and data access without managing credentials.
- **B2B applications**: Secure service-to-service communication.

# Common OAuth2 pitfalls

- **Over-scoped tokens**: Granting too many permissions.
- **Insecure storage**: Storing tokens in insecure locations (e.g., localStorage without encryption).
- **Ignoring token expiration**: Failing to handle token refresh flows.
- **Misusing Implicit Flow**: Using legacy flows where better options (PKCE) are available.

# OAuth2: A key enabler of modern security

OAuth2 powers secure, flexible authorization across the modern internet. Understanding its core flows and best practices helps developers build safer, more user-friendly apps.

Choosing the proper OAuth2 flow based on your application's architecture and user needs is critical to balancing security, usability, and scalability.

Ready to explore OAuth2 more deeply? Check

- [Appwrite Authentication docs](/docs/products/auth)
- [Overview of all the OAuth providers](/integrations#auth)
- [Appwrite Authentication overview](/products/auth)

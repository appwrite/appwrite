---
layout: post
title: Introducing support for server-side rendering
description: Server-side rendering (SSR) is now fully supported with Appwrite allowing more flexibility to build how you want.
date: 2024-02-27
cover: /images/blog/ssr-announcement.avif
timeToRead: 7
author: dennis-ivy
category: product, announcement
featured: false
faqs:
  - question: "What is server side rendering (SSR)?"
    answer: "SSR is rendering pages on the server and sending HTML to the browser, rather than letting the client fetch data and assemble the page. Frameworks like Next.js, Nuxt, SvelteKit, Astro, and Remix all support SSR for better SEO, faster first paint, and easier protection of private routes."
  - question: "How does Appwrite support SSR authentication?"
    answer: "The server SDK methods that create a session (createEmailPasswordSession, createAnonymousSession, createSession) now return a `secret`. You store that secret in a server side cookie and reuse it with `client.setSession(secret)` on subsequent requests. See [Appwrite Auth](/docs/products/auth)."
  - question: "What's the difference between an admin client and a session client?"
    answer: "An admin client uses an API key and bypasses rate limits, used for server side admin tasks. A session client uses an end user's session secret and acts on behalf of that user. You construct both from the same `node-appwrite` SDK, just with different credentials."
  - question: "Should I instantiate a new Appwrite client for every request?"
    answer: "Yes. Reusing a single client across requests can leak the previous user's session into a different user's request. Create a fresh client per request inside your route handler or middleware to keep them isolated."
  - question: "Which SDK do I use on the server?"
    answer: "Use a Server SDK, for example `node-appwrite` in Node.js based frameworks. Avoid installing the client SDK alongside it in the same project to prevent mixing client only and server only methods."
  - question: "How do I protect API routes with SSR?"
    answer: "Read the session cookie inside your route, call `client.setSession(cookie)`, then use `account.get()` to verify the user. If it throws, redirect to login. This pattern works across Next.js route handlers, SvelteKit endpoints, and Nuxt server routes."
---

We're excited to introduce support for server-side rendering (SSR) authentication patterns. This change enhances the developer experience when building with popular frameworks that provide SSR as an option, such as Next.js, SvelteKit, Nuxt, Astro, Remix, and more.

Until now, Appwrite’s authentication system has been optimized for client-side rendering, which worked well with single page applications (SPAs), but had limitations on how SSR could be implemented. While it was possible to implement SSR authentication, it was hacky and undocumented. In the latest release, we are officially introducing support for SSR with new methods and workflows to the server SDK's.

# Understanding past limitations

To understand the new changes and workflows, let's first take a step back and address the challenges we aimed to solve in this new release.

When building applications with SSR, we need a way to generate and store a session secret server-side to protect API routes and pages and a way to make authenticated requests.

The problem we faced was that there was no way to access a session secret when using authentication methods. When using methods such as `createEmailPasswordSession`, Appwrite’s web SDK automatically stores the session in the browser's cookies and does not make it available to you. For client-side rendering, this is a non-issue since we don't need to access the session manually. However, when it comes to SSR we need a way to access a session and set it in the server's cookies for subsequent requests.

# Getting started with SSR

As you get started with SSR it’s important to note that all methods we will be working with are based on Appwrite’s server SDKs. In the examples below, we will use the [Node JS SDK.](/docs/sdks#server)

It’s also recommended that as you follow along with this article, you do not install any client SDK’s as that may lead to confusion. All examples use `node-appwrite`.

```bash
npm install node-appwrite
```

## Create sessions server-side

To solve this issue, all existing server SDK methods that create a session now return a `secret` attribute. The following methods are:

- `account.createEmailPasswordSession({email, password})`
- `account.createAnonymousSession()`
- `account.createSession({userId, secret})`

You can use these methods now to create a session, and to set a session cookie on your domain.

```jsx
const session = account.createEmailPasswordSession({
  email,
  password
})

console.log(session.secret) // Output: 'eyJpZCI...sdfahfkjjy'
```

## Using session secrets

With a session cookie set, we can now authenticate users and protect routes.

When using the Appwrite SDK, you can use the new `setSession` method to authenticate a user for any request.

```jsx
client.setSession(session.secret)
const currentUser = await account.get()
```

{% info title="Appwrite client security" %}
It’s important to note that the client instance should be re-created for every request. Failure to do so on your server SDK could result in leaked/shared session cookies between request
{% /info %}

## Admin and Session Clients

With the new authentication patterns for SSR, you’ll need to create two different types of instances of an Appwrite client when initializing the SDK. An admin client for performing admin request, and a session client for performing authenticated request on behalf of an end user.

### Admin Client

The admin client will need to be initialized with an API key in order to bypass rate limits when performing unauthenticated request or when we need to perform actions that bypass permissions. Without an API key our server will be rate limited when trying to make request to certain endpoints.

```jsx
import { Client } from "node-appwrite"

const adminClient = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>')
    .setKey('<YOUR_API_KEY>')
```

### Session Client

A session client will allow us to make requests as an authenticated end-user with the `setSession` helper method.

```jsx
const sessionClient = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>')

const session = req.cookies.session

if (session) {
    sessionClient.setSession(session)
}

const user = await account.get()
```

# Seeing it in action

Using Next.js, let’s see how we can create an application with two endpoints:

- `/api/signin`  - creates a session and sets a session cookie
- `/api/user` - retrieves session from cookies and authenticates the request

We'll use an admin client to create sessions and a session client to perform authenticated requests.

## Creating admin and session client

First, we’ll need to configure our SDK and create two methods to initiate our clients in `/src/appwrite.js`. We'll use environment variables to store our Appwrite endpoint, project ID, and API key.

```jsx
import { Client, Account } from "node-appwrite"
import { parseCookie } from "next/dist/compiled/@edge-runtime/cookies";

export function createAdminClient() => {
    const client = new Client()
        .setEndpoint(process.env.PUBLIC_APPWRITE_ENDPOINT)
        .setProject(process.env.PUBLIC_APPWRITE_PROJECT)
        .setKey(process.env.APPWRITE_API_KEY)

    return {
        get account() {
            return new Account(client)
        }
    }
}

export function  createSessionClient(headers) {
    const client = new Client()
        .setEndpoint(process.env.PUBLIC_APPWRITE_ENDPOINT)
        .setProject(process.env.PUBLIC_APPWRITE_PROJECT)

    const cookies = parseCookie(headers.get('cookie') ?? '')
    const session = cookies.get('my-session-cookie') ?? ''
    if (session) {
        client.setSession(session.secret)
    }

    return {
		get account() {
			return new Account(client)
		}
	}
}
```

### **Creating a session**

Now, in the `/signin` route, we can use the admin client to generate a session and set a cookie.

```jsx
import { createAdminClient } from "@/lib/appwrite"
import { cookies } from 'next/headers'

export async function POST(request){
    const { account } = await  createAdminClient()

    const { email, password } = await request.json()
    const session = await account.createEmailPasswordSession({
      email,
      password
    })

    cookies().set('session', session.secret, {
        httpOnly: true,
        secure: true,
        sameSite: 'strict',
        expires: new Date(session.expire),
        path: '/',
    })

    return Response.redirect('/api/user')
}
```

### Authenticating requests

With a session set, we can now use the session client to authenticate a user and return the user object. This session client can now be used on all requests that require an authenticated user.

```jsx
import { createSessionClient } from "@/lib/appwrite"
import { headers } from 'next/headers'

export async function GET(request){
    const { account } = await createSessionClient(headers)
    try {
        const user = await account.get()
        return Response.json(user)
    } catch (error) {
        return Response.json(error)
    }
}
```

# Resources

Visit our documentation to learn more about SSR, join us on Discord to be part of the discussion, visit our blog and YouTube channel to learn more, or visit our GitHub repository to see our source code.

- [Docs](/docs/products/auth/server-side-rendering)
- [Discord](https://appwrite.io/discord)
- [Blog](/blog)
- [YouTube](https://www.youtube.com/channel/UCtBJ1v69gm8NgbCju_03Fiw)
- [GitHub repository](https://github.com/appwrite/appwrite)

SSR will be available as part of the Appwrite 1.5 release on [GitHub](https://github.com/appwrite/appwrite) and [Cloud](https://cloud.appwrite.io/register) in March 2024.

[Go back to Init](/init)

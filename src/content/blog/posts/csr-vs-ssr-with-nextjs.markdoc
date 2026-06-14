---
layout: post
title: SSR vs CSR with Next.js
description: Learn the differences between SSR and CSR and how Appwrite Authentication can be leveraged with both in Next.js.
date: 2024-02-27
cover: /images/blog/ssr-vs-csr/cover.avif
timeToRead: 10
author: dennis-ivy
category: product
featured: false
faqs:
  - question: "What is the difference between SSR and CSR in Next.js?"
    answer: "SSR (server-side rendering) generates HTML on the server for every request, so the browser receives a fully rendered page. CSR (client-side rendering) sends a minimal HTML shell and uses JavaScript in the browser to fetch data and build the UI. Next.js supports both."
  - question: "How does Appwrite handle authentication in SSR Next.js apps?"
    answer: "For SSR, you use the Appwrite Node.js SDK with two clients: an admin client (configured with an API key) to create sessions, and a session client (configured with the user's session secret) to make authenticated requests. The session secret is stored in a server-side cookie. See [Appwrite Auth](/docs/products/auth)."
  - question: "Why can't I use the Appwrite web SDK for SSR auth?"
    answer: "The web SDK manages sessions automatically in the browser, so the session secret is never exposed to your server code. For SSR, you need access to that secret to set a cookie and authenticate requests on the server. Use the Node.js SDK and set the session manually with client.setSession."
  - question: "What scopes does my Appwrite API key need for SSR auth?"
    answer: "At a minimum, the admin client needs the sessions.write scope to create sessions for users. Add additional scopes (users.read, databases.read, etc.) based on what your server actions need to do on behalf of users or as an admin."
  - question: "Should I use SSR or CSR for my Next.js app?"
    answer: "Use SSR when you need SEO, fresh content per request, or personalized server-rendered pages. Use CSR for highly interactive apps behind a login wall where SEO does not matter. Many Next.js apps mix both: SSR for public pages and CSR for authenticated dashboards."
  - question: "Where should I store the Appwrite session secret in an SSR app?"
    answer: "Store the session secret in an httpOnly, secure cookie set from your server. Never expose it to client-side JavaScript. Read it back from the cookie on each request and pass it to your session client via client.setSession."
---

With modern web development frameworks, the age-old debate around server-side rendering (SSR) and client-side rendering (CSR), which rendering method is more effective, has returned to the general tech community. Since Appwrite aims to enable all developers, regardless of their preferences, in this debate, we decided to research and extend our support for both paradigms. This article will explore the differences between SSR and CSR and how Appwrite Authentication can be leveraged with both in Next.js.

# How server-side and client-side rendering differ

Before we jump further into this article, it is necessary to understand what SSR and CSR are. **Server-side rendering** allows the content of a web page to be generated on the server rather than in the browser. In **client-side rendering**, the rendering of a web page is performed in the client's browser. Unlike SSR, where the server generates the full page's HTML for each request, in CSR, the server sends a minimal HTML document with a JavaScript bundle to the client. The browser executes the JavaScript, which typically fetches data from the server and generates the HTML content dynamically on the client.

Here are some of the strengths and drawbacks of both methods:

|  | SSR | CSR |
| --- | --- | --- |
| Rendering location | The HTML is fully rendered on the server, and a complete page is sent to the client. | The browser receives minimal HTML, and JavaScript renders the content on the client-side. |
| Initial load time | Generally offers faster initial page load times because the browser can display the page as soon as it receives the HTML. | May have slower initial load times since the browser needs to load, parse, and execute JavaScript before rendering the content. |
| SEO Optimization | More SEO-friendly as search engines can crawl the fully rendered HTML content. | Less SEO-friendly out of the box since search engines might struggle to index content that is rendered asynchronously via JavaScript. |
| Resource utilization | Increases the load on the server since it has to render content for each request. | Offloads rendering to the client, reducing server load but increasing the client's processing requirements. |
| User experience | Can provide a better initial user experience with faster-perceived load times. | Might enhance the user experience in dynamic applications with smooth transitions and interactions after the initial load. |
| Development Complexity | Can be more complex to implement, especially for highly dynamic sites, due to the need for server infrastructure and handling of the initial rendering. | Simplifies initial development since the rendering is handled in the browser, but managing state and interactivity can become complex. |
| Caching | Easier to cache at the server level or through a CDN as the content is static and pre-rendered. | Caching strategies can be more complex due to dynamic content rendering, often requiring sophisticated service worker setups. |
| Data fetching | The server pre-fetches data before rendering the page, which can improve performance for the initial load. | Data is fetched on the client-side, which can delay content rendering until after the data is retrieved. |

# Understanding auth patterns for CSR and SSR in Next.js

When it comes to implementing authentication in Next.js, Appwrite supports different authentication patterns between SSR and CSR apps. The key difference is in how Appwrite handles a user's session secret. Let's explore both patterns to understand how they are supposed to be implemented.

## CSR authentication pattern

With single page applications (SPAs) that are rendered on the client-side, the Appwrite web SDK handles the session for us, so we never need to manually access it. After creating a user session with `account.createEmailPasswordSession` (or any other authentication method Appwrite offers), we can directly retrieve the current user with `account.get()` and process any other authentication checks or move forward to our main app.

```jsx
account.createEmailPasswordSession({
  email: '[email]',
  password: '[password]'
})
const user = await account.get()
```

## SSR authentication pattern

With SSR apps, we need a way to access the session secret after creation to set a session cookie on the server and then return to the client's browser (the Web SDK cannot achieve this by default). Therefore, with SSR, we must manually handle how we set the session after creation and retrieve it on subsequent requests.

For this purpose, we rely on Appwrite's server SDKs for Next.js apps, we use our Node.js SDK. The first step of this process is installing the `node-appwrite` NPM package.

## Admin and Session clients

The first step of understanding how we initialize the client in SSR apps. In this scenario, we need to create two types of Appwrite clients:

- Admin client: performs admin requests using an API key.
- Session client: performs authenticated requests on behalf of a user.

## Generating a session secret

The admin client must be set with an API key that has the necessary scopes in order to perform all necessary tasks on your Appwrite project. For example, we need an admin client to create a session, and this client must have an API key with the auth scope `session.write`, enabled at the very least.

```jsx
const adminClient = new Client()
  .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
  .setProject('<PROJECT_ID>')
  .setKey('<API_KEY>')
```

We set this session in the response cookies of a server action or an API route (we'll use the latter for our examples).

```jsx
import { cookies } from 'next/headers'

export async function POST(request) {
    // initiate an admin client and account here
    // ...

    const { email, password } = await request.json()
    const session = await account.createEmailPasswordSession({
      email,
      password
    })

    cookies().set('my-session-cookie', session.secret)

    return Response.redirect('/api/user')
}
```

## Using session secrets

The session client accepts the generated session secret and sets it using the `client.setSession` method. This session secret will typically be retrieved from a session cookie.

```jsx
const sessionClient = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>')
```

We use the session secret to authenticate a user from an API route. This can also be done at the page component level.

```jsx
export async function GET(request){
    // initiate a session client and account here
    // ...

    const session = request.cookies.get('my-session-cookie')
    if (session) {
        client.setSession(session.value)
    }

    try {
        const user = await account.get()
        return Response.json(user)
    } catch(error){
        return Response.json(error)
    }
}
```

# Resources
Visit our documentation to learn more about push notifications, join us on Discord to be part of the discussion, view our blog and YouTube channel, or visit our GitHub repository to see our open-source code.

- [Docs](/docs/products/auth/server-side-rendering)
- [Discord](https://appwrite.io/discord)
- [Blog](/blog)
- [YouTube](https://www.youtube.com/channel/UCtBJ1v69gm8NgbCju_03Fiw)
- [GitHub](https://github.com/appwrite/appwrite)

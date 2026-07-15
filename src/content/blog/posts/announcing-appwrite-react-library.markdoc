---
layout: post
title: "Announcing the Appwrite React library"
description: A small set of React hooks and SSR adapters that take the busywork out of wiring Appwrite Auth into Vite, Next.js, and TanStack Start apps.
date: 2026-06-26
cover: /images/blog/announcing-appwrite-react-library/cover.avif
timeToRead: 5
author: atharva
category: announcement, authentication
faqs:
    - question: 'What is an authentication library?'
      answer: 'An authentication library is a package that handles the common pieces of user sign-up, sign-in, sign-out, session management, and OAuth on your behalf, so you do not have to wire each one up from scratch in every project.'
    - question: 'Why use an authentication library instead of building auth yourself?'
      answer: 'Auth touches sessions, cookies, password handling, OAuth callbacks, and a handful of edge cases that are easy to get subtly wrong. A library gives you a tested implementation of those pieces and a small, consistent API on top, so you spend your time on product features instead of re-implementing the same flows.'
    - question: 'Which React versions are supported?'
      answer: 'React 18 and React 19, on both client-rendered apps and SSR frameworks. Next.js 15+ and TanStack Start are supported out of the box.'
    - question: 'Is the source code open?'
      answer: 'Yes. The library lives in the [appwrite/sdk-for-react](https://github.com/appwrite/sdk-for-react) repository on GitHub under the BSD-3-Clause license, like the rest of the Appwrite SDKs.'
    - question: 'Does it support magic URL, phone, or other authentication methods?'
      answer: 'The current hooks cover email and password sign-in, sign-up, sign-out, and OAuth. For magic URL, phone, anonymous, or JWT flows, drop down to the Web SDK via `useAppwrite().account` and call the method directly. More dedicated hooks are planned.'
    - question: 'Where do I start?'
      answer: 'Pick the framework you are using: [Vite React](/docs/quick-starts/react), [Next.js](/docs/quick-starts/nextjs), or [TanStack Start](/docs/quick-starts/tanstack-start). Each quickstart walks through install, configuration, and a working sign-up flow.'
---

Every React developer who has wired Appwrite into a project knows the drill. You install the Web SDK, write a small `appwrite.ts` file with a `Client` and an `Account`, and then build the same `useUser` hook and sign-in form you have built for every other project. For client-only apps that is fine, but the moment SSR enters the picture (`cookies()` in Next.js, server functions in TanStack Start) you also start hand-rolling session cookies, server helpers, and the `setSession` plumbing that hydrates the client from the request.

Today, we are releasing the [Appwrite React library](/docs/products/auth/react), an official package that gives you that whole layer out of the box.

# The provider and hooks

The library exposes a single `AppwriteProvider` and five auth hooks:

- `useAuth` for the combined user, sign-in, sign-up, and sign-out surface
- `useUser` for the current authenticated user
- `useSignIn` for email/password and OAuth sign-in
- `useSignUp` for account creation
- `useSignOut` for ending the current session

It is built on top of the existing [Appwrite Web SDK](/docs/sdks) and uses TanStack Query underneath, so cached user state stays in sync across components automatically.

What makes it different from a hand-rolled `useUser` is what happens on the server. The library ships dedicated entrypoints for Next.js App Router and TanStack Start that handle the cookie, hydration, and admin-client wiring for you, so the hooks behave the same in a Vite SPA as they do in a server-rendered page.

# Client-side setup

For a Vite app, setup is two imports. Wrap the tree with the provider, then call `useAuth` wherever you need it.

```tsx
// main.tsx
import { AppwriteProvider } from "@appwrite.io/react";

<AppwriteProvider
  endpoint={import.meta.env.VITE_APPWRITE_ENDPOINT}
  projectId={import.meta.env.VITE_APPWRITE_PROJECT_ID}
>
  <App />
</AppwriteProvider>;
```

With the provider in place, every component can pull the current user, sign-in, sign-up, and sign-out methods from a single hook.

```tsx
// App.tsx
import { useAuth } from "@appwrite.io/react";

function App() {
  const { user, signIn, signOut } = useAuth();
  // render auth UI
}
```

That is the entire client setup. The library handles the service file, the initial account fetching on mount, and the auth context that you would otherwise write by hand. The full walkthrough lives in the [Vite React quickstart](/docs/quick-starts/react).

# Server-side rendering

For server-rendered React, the package adds three pieces:

- A handler route that processes sign-in, sign-up, sign-out, and OAuth callback requests on your server.
- Server helpers that resolve the current user, session, or session-scoped client from the request cookie.
- An SSR mode on the provider that hydrates the client with the server-side session, so the first paint already knows the user.

Concretely, in a Next.js layout, you read the cookie server-side, pass the value through the provider, and the client hooks pick it up.

```tsx
// app/layout.tsx
const helpers = createNextServerHelpers(appwrite);
const session = await helpers.readSessionCookie();

return <Providers session={session}>{children}</Providers>;
```

The same helpers can be called inside any server component to fetch the user before the page is sent to the browser.

```tsx
// app/page.tsx
const user = await helpers.getLoggedInUser();
```

The same flow on TanStack Start runs through `createServerFn` and `Route.useLoaderData()`. The hooks themselves do not change.

# Session client

For per-request operations on behalf of the signed-in user, the framework helpers expose `createSessionClient`. It returns a `node-appwrite` client already authenticated with the request cookie, so server components and loaders can act as the user without re-implementing session handling.

```ts
const helpers = createNextServerHelpers(appwrite);
const session = await helpers.createSessionClient();

if (session) {
  const user = await session.account.get();
}
```

# Admin client

For privileged operations, pass an `apiKey` into the framework helper and call `createAdminClient()` on it. The returned object exposes every `node-appwrite` service (`users`, `tablesDB`, `storage`, `teams`, `functions`, and so on), so the rest of your server code keeps using the official Node SDK.

```ts
const helpers = createNextServerHelpers({
  endpoint: process.env.NEXT_PUBLIC_APPWRITE_ENDPOINT!,
  projectId: process.env.NEXT_PUBLIC_APPWRITE_PROJECT_ID!,
  apiKey: process.env.APPWRITE_API_KEY!,
});

const admin = helpers.createAdminClient();
const users = await admin.users.list();
```

# Where to start

Three quickstarts ship alongside the library:

- [Vite React](/docs/quick-starts/react) for client-rendered apps
- [Next.js App Router](/docs/quick-starts/nextjs) for server-rendered React on Next.js
- [TanStack Start](/docs/quick-starts/tanstack-start) for server-rendered React on TanStack Start

Each one ends with a working sign-up, sign-in, and sign-out flow. From there, the [React library docs](/docs/products/auth/react) cover the hooks, the server helpers, OAuth, and the handler configuration in depth.

We would love to hear what you build with it. Drop into the [Appwrite Discord](https://appwrite.io/discord) and tell us how the SSR story feels in your framework of choice.

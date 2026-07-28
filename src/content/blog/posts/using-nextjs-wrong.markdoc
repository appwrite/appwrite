---
layout: post
title: "You're probably using Next.js wrong"
description: Next.js isn't just React with extra steps. Learn when server-side rendering actually matters and how to use it properly with Appwrite.
date: 2026-01-22
lastUpdated: 2026-06-29
cover: /images/blog/using-nextjs-wrong/cover.avif
timeToRead: 8
author: atharva
category: tutorial
featured: false
faqs:
  - question: "When should I use Next.js instead of plain React with Vite?"
    answer: "Use Next.js when you need server side rendering, static generation, or server components for SEO, fast initial loads, or smaller client bundles. For internal dashboards and admin tools behind a login, plain React with Vite is usually simpler and lighter."
  - question: "What is the difference between server and client components?"
    answer: "Server components run on the server, can access databases and secrets directly, and only send HTML to the browser. Client components ship JavaScript that hydrates and runs in the browser. You add the 'use client' directive at the top of a file to opt into a client component."
  - question: "Why is fetching data in useEffect a bad pattern in Next.js?"
    answer: "Fetching in useEffect means the page renders empty first, then JavaScript loads, then the request fires, then the UI updates. Users see a loading spinner instead of content. Server components fetch on the server before the page is sent, so the user gets HTML with real data immediately."
  - question: "Which Appwrite SDK do I use in a server component?"
    answer: "Use the Node SDK (node-appwrite) in server components and the Web SDK (appwrite) in client components. Server components typically authenticate with an [Appwrite](/docs/products/auth) API key, while client components use the user's session."
  - question: "Can I mix server and client components in the same route?"
    answer: "Yes. The recommended pattern is to start every component as a server component and only mark the smaller interactive subtrees as client components. You can pass serialisable props from server components down into client components, but not the other way around."
  - question: "Will server side rendering slow down my pages?"
    answer: "On the contrary, SSR usually improves perceived performance because the user sees content immediately instead of a spinner. The trade off is that your server does more work per request, so cache aggressively (with revalidate or ISR) for high traffic pages."
---

Next.js has become the default choice for new React projects. But here's the problem: most developers treat it like React with a fancy router. They spin up a Next.js app, slap `'use client'` on every component, fetch data in `useEffect`, and wonder why when they face problems.

If that sounds familiar, you're shipping bloat for no reason.

# Next.js is not React

React is a UI library. It renders components and manages state. Routing, data fetching, and server logic are your problems.

Next.js is a full-stack framework that uses React for its UI layer. It gives you server-side rendering, static generation, API routes, and server components out of the box. These features exist for specific reasons, and if you're not using them, you're just adding complexity without benefit.

# Does SEO matter?

This is the deciding factor. If search engines need to index your content, you need server-side rendering. Client-rendered pages ship JavaScript that builds the DOM after load. Search crawlers can technically execute JavaScript, but they're inconsistent at it. Your e-commerce product pages, blog posts, and landing pages should render on the server or should be statically pre-rendered.

If you're building an internal dashboard or admin panel that lives behind a login, SEO is irrelevant. Client-side rendering is fine. You could use plain React with Vite and skip the Next.js overhead entirely.

# What server components actually solve

Server components aren't just about SEO. They solve three problems:

## Initial page load

Client components ship JavaScript to the browser, which then fetches data and renders. Users see a loading spinner. Server components fetch data and render HTML before anything reaches the browser. Users see content immediately.

## Bundle size

Every library you import in a client component ends up in your JavaScript bundle. Server components run on the server only. That heavy markdown parser or date library never touches the browser.

## Security

Server components can access databases and secrets directly. They are also capable of accessing environment variables without the `NEXT_PUBLIC_` prefix, since these components are run exclusively on the server.

# Converting a client component to a server component

Here's a typical client-side pattern:

```jsx
"use client";

import { useState, useEffect } from "react";
import { tablesDB } from "@/lib/appwrite";

export default function Products() {
  const [products, setProducts] = useState([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    tablesDB
      .listRows({
        databaseId: DATABASE_ID,
        tableId: TABLE_ID,
        queries: [Query.equal("status", "active")],
      })
      .then((res) => setProducts(res.rows))
      .finally(() => setLoading(false));
  }, []);

  if (loading) return <div>Loading...</div>;
  return <ProductGrid products={products} />;
}
```

Here's the server component equivalent (without `'use client'`):

```jsx
import { createAdminClient } from "@/lib/appwrite/server";

export default async function Products() {
  const { tablesDB } = await createAdminClient();
  const { rows } = await tablesDB.listRows({
    databaseId: DATABASE_ID,
    tableId: TABLE_ID,
    queries: [Query.equal("status", "active")],
  });

  return <ProductGrid products={rows} />;
}
```

# Setting up Appwrite for server-side rendering

The Appwrite Web SDK runs in browsers. For server components, you need the Node SDK:

```bash
npm install node-appwrite
```

Create two client factories. The admin client uses an API key for public data:

```ts
// lib/appwrite/server.ts
import { Client, TablesDB, Account } from "node-appwrite";

export async function createAdminClient() {
  const client = new Client()
    .setEndpoint(process.env.APPWRITE_ENDPOINT)
    .setProject(process.env.APPWRITE_PROJECT_ID)
    .setKey(process.env.APPWRITE_API_KEY);

  return {
    tablesDB: new TablesDB(client),
    account: new Account(client),
  };
}
```

The session client uses the logged-in user's session for protected data:

```ts
import { Client, TablesDB, Account } from "node-appwrite";
import { cookies } from "next/headers";

export async function createSessionClient() {
  const client = new Client()
    .setEndpoint(process.env.APPWRITE_ENDPOINT)
    .setProject(process.env.APPWRITE_PROJECT_ID);

  const session = (await cookies()).get("session");
  if (session) {
    client.setSession(session.value);
  }

  return {
    tablesDB: new TablesDB(client),
    account: new Account(client),
  };
}
```

The difference matters. Admin client queries return all rows matching your query. Session client queries return only rows the user has permission to access.

# Handling authentication

Login with a server action:

```tsx
// app/login/page.tsx
import { cookies } from "next/headers";
import { redirect } from "next/navigation";
import { createAdminClient } from "@/lib/appwrite/server";

export default function LoginPage() {
  async function login(formData: FormData) {
    "use server";
    const { account } = await createAdminClient();
    const session = await account.createEmailPasswordSession(
      {
        email: formData.get("email") as string,
        password: formData.get("password") as string
    }
    );

    (await cookies()).set("session", session.secret, {
      httpOnly: true,
      secure: true,
      sameSite: "strict",
      expires: new Date(session.expire),
    });

    redirect("/");
  }

  return (
    <form action={login}>
      <input name="email" type="email" required />
      <input name="password" type="password" required />
      <button type="submit">Log in</button>
    </form>
  );
}
```

Protect routes with a layout:

```tsx
// app/(protected)/layout.tsx
import { redirect } from "next/navigation";
import { createSessionClient } from "@/lib/appwrite/server";

export default async function ProtectedLayout({ children }) {
  try {
    const { account } = await createSessionClient();
    await account.get();
  } catch {
    redirect("/login");
  }

  return children;
}
```

# When to skip all of this

Use plain React when:

- Your app lives behind authentication
- Search engines don't need to index it
- You prefer client-side data fetching patterns
- You're building a very simple app that doesn't need server-capabilities

There's nothing wrong with client-side rendering. The mistake is using Next.js and not leveraging what makes it useful.

# Resources

- [Appwrite SSR documentation](/docs/products/auth/server-side-rendering)
- [Next.js App Router documentation](https://nextjs.org/docs/app)

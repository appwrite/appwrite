---
layout: article
title: React library
description: Add authentication to React apps with Appwrite's official React library. Supports client-side React, Next.js, and TanStack Start with a single provider and a small set of hooks.
---

The Appwrite React library is a thin layer over the [Web SDK](/docs/sdks) that exposes a provider and a small set of hooks for authentication operations and current user state. It works in both client-rendered React apps and server-rendered apps on Next.js and TanStack Start.

# Why use it {% #why %}

- **SSR auth without boilerplate.** Drop in one handler route per framework and skip the days normally spent writing cookie logic, session sync, and server/client hydration.
- **Consistent user state across server and client.** Server components, loaders, and client hooks return the same authenticated user, removing the need to reconcile auth state across the render boundary.
- **Server-side access when you need it.** Read the current user, create per-request session clients, or reach for an admin client without hand-wiring the Node SDK on every route.

# Install {% #install %}

Install the library along with the **Appwrite Web SDK** and **TanStack Query** packages.

```sh
npm install @appwrite.io/react appwrite @tanstack/react-query
```

For SSR apps on Next.js or TanStack Start, also install `node-appwrite`. The SSR handlers create sessions with a server API key.

```sh
npm install @appwrite.io/react appwrite node-appwrite @tanstack/react-query
```

# Pick your framework {% #frameworks %}

{% cards %}
{% cards_item href="/docs/quick-starts/react" title="Client-side React" %}
For Vite and other client-rendered React apps.
{% /cards_item %}
{% cards_item href="/docs/quick-starts/nextjs" title="Next.js" %}
For server-rendered apps on the Next.js App Router.
{% /cards_item %}
{% cards_item href="/docs/quick-starts/tanstack-start" title="TanStack Start" %}
For server-rendered apps on TanStack Start.
{% /cards_item %}
{% /cards %}

# The provider {% #provider %}

Every app starts with an `AppwriteProvider`. In a CSR app, only `endpoint` and `projectId` are required.

```tsx
import { AppwriteProvider } from "@appwrite.io/react";

<AppwriteProvider
  endpoint={import.meta.env.VITE_APPWRITE_ENDPOINT}
  projectId={import.meta.env.VITE_APPWRITE_PROJECT_ID}
>
  <App />
</AppwriteProvider>;
```

In an SSR app, pass an `ssr` prop with the session secret read from the server-side cookie and the path your handlers are mounted at.

```tsx
<AppwriteProvider
  endpoint={endpoint}
  projectId={projectId}
  ssr={{ session, basePath: "/api/appwrite" }}
>
  {children}
</AppwriteProvider>
```

When `ssr` is set, sign-in, sign-up, and sign-out mutations route through your handler. The underlying Web SDK is hydrated with the session secret on first render, so authenticated reads do not need a round trip.

# Hooks {% #hooks %}

The library exports one combined hook and four focused mutation hooks. All hooks share the same TanStack Query cache, so updating user state from any of them reflects everywhere.

## useAuth {% #use-auth %}

The primary hook for most apps. It bundles the current user, loading and error state, and handles for sign-in, sign-up, and sign-out into a single return value.

```tsx
import { useAuth } from "@appwrite.io/react";

const { user, isLoading, error, refresh, signIn, signUp, signOut } = useAuth();
```

| Field | Type | Description |
| --- | --- | --- |
| `user` | `Models.User \| null \| undefined` | The current user, or `null` if signed out. `undefined` while loading. |
| `isLoading` | `boolean` | True while the initial user fetch is in flight. |
| `error` | `Error \| null` | First error from the user query or any mutation. |
| `refresh` | `() => Promise<User \| null>` | Refetch the current user and return the resolved value. |
| `signIn` | `ReturnType<typeof useSignIn>` | Sign-in mutation handle. |
| `signUp` | `ReturnType<typeof useSignUp>` | Sign-up mutation handle. |
| `signOut` | `ReturnType<typeof useSignOut>` | Sign-out mutation handle. |

## useUser {% #use-user %}

Read-only access to the current authenticated user. Use it in components that only need to display user state and do not trigger auth mutations.

```tsx
import { useUser } from "@appwrite.io/react";

const { user, isLoading, error, refresh } = useUser();
```

| Field | Type | Description |
| --- | --- | --- |
| `user` | `Models.User \| null \| undefined` | The current user, or `null` if signed out. `undefined` while loading. |
| `isLoading` | `boolean` | True while the user is being fetched. |
| `error` | `Error \| null` | Error from the fetch, if one occurred. |
| `refresh` | `() => Promise<User \| null>` | Refetch the current user and return the resolved value. |

## useSignIn {% #use-sign-in %}

Email and password and OAuth sign-in, with pending and error state scoped to the email and password flow.

```tsx
const { emailPassword, oAuth, isPending, error } = useSignIn();
```

| Field | Type | Description |
| --- | --- | --- |
| `emailPassword` | `function` | Trigger an email and password sign-in. |
| `oAuth` | `function` | Trigger an OAuth sign-in. |
| `isPending` | `boolean` | True while an email and password sign-in is in flight. |
| `error` | `Error \| null` | Error from the last email and password sign-in attempt. |

{% tabs %}
{% tabsitem #use-sign-in-email-password title="Email and password" %}
```tsx
emailPassword({ email, password, onSuccess, onError });
```

| Parameter | Required | Description |
| --- | --- | --- |
| `email` | Yes | User's email address. |
| `password` | Yes | User's password. |
| `onSuccess` | No | Callback invoked with the signed-in user. |
| `onError` | No | Callback invoked with the thrown error. |
{% /tabsitem %}

{% tabsitem #use-sign-in-oauth title="OAuth" %}
```tsx
oAuth({ provider: OAuthProvider.Google, successUrl, failureUrl, scopes });
```

| Parameter | Required | Description |
| --- | --- | --- |
| `provider` | Yes | An `OAuthProvider` value re-exported by the library. |
| `successUrl` | No | URL to redirect to after successful login (CSR only). |
| `failureUrl` | No | URL to redirect to after a failed attempt. |
| `scopes` | No | Provider-specific OAuth scopes to request. |
| `onError` | No | Callback invoked if the request fails before redirect. |

In SSR mode, the success URL is fixed to the handler callback. The final redirect after that callback is controlled by the `redirects.success` config on the handler.
{% /tabsitem %}
{% /tabs %}

## useSignUp {% #use-sign-up %}

Create an account and start a session in one call. The hook also surfaces pending and error state for the in-flight request.

```tsx
const { emailPassword, isPending, error } = useSignUp();
```

| Field | Type | Description |
| --- | --- | --- |
| `emailPassword` | `function` | Create an account with email and password, then sign the user in. |
| `isPending` | `boolean` | True while the sign-up is in flight. |
| `error` | `Error \| null` | Error from the last sign-up attempt. |

### Email and password {% #use-sign-up-email-password %}

```tsx
emailPassword({ email, password, name, userId, onSuccess, onError });
```

| Parameter | Required | Description |
| --- | --- | --- |
| `email` | Yes | New user's email address. |
| `password` | Yes | New user's password. |
| `name` | No | Display name for the user. |
| `userId` | No | Custom user ID. Defaults to `ID.unique()`. |
| `onSuccess` | No | Callback invoked with the created user. |
| `onError` | No | Callback invoked with the thrown error. |

## useSignOut {% #use-sign-out %}

End the current session, drop cached auth queries, and reset the local Web SDK client.

```tsx
const { signOut, isPending, error } = useSignOut();
```

| Field | Type | Description |
| --- | --- | --- |
| `signOut` | `function` | End the current user's session. |
| `isPending` | `boolean` | True while the sign-out is in flight. |
| `error` | `Error \| null` | Error from the last sign-out attempt. |

### Sign out {% #use-sign-out-method %}

```tsx
signOut({ onSuccess, onError });
```

| Parameter | Required | Description |
| --- | --- | --- |
| `onSuccess` | No | Callback invoked after the session is destroyed. |
| `onError` | No | Callback invoked with the thrown error. |

After a successful sign-out, all cached auth queries are dropped and the local Web SDK client is reset. On Next.js, call `router.refresh()` from `onSuccess` to re-render server components with the cleared cookie. On TanStack Start, call `router.invalidate()`.

## useAppwrite {% #use-appwrite %}

Escape hatch to the underlying provider context. Use it when you need direct access to a Web SDK service the higher-level hooks do not wrap (`tablesDB`, `storage`, `messaging`, `realtime`, and so on).

```tsx
import { useAppwrite } from "@appwrite.io/react";

const { client, account, tablesDB, storage, teams, ssr } = useAppwrite();
```

| Field | Type | Description |
| --- | --- | --- |
| `client` | `Client` | The Appwrite Web SDK `Client` configured by the provider. |
| `account` | `Account` | Web SDK `Account` service. |
| `avatars` | `Avatars` | Web SDK `Avatars` service. |
| `functions` | `Functions` | Web SDK `Functions` service. |
| `graphql` | `Graphql` | Web SDK `Graphql` service. |
| `locale` | `Locale` | Web SDK `Locale` service. |
| `messaging` | `Messaging` | Web SDK `Messaging` service. |
| `presences` | `Presences` | Web SDK `Presences` service. |
| `realtime` | `Realtime` | Web SDK `Realtime` service. |
| `storage` | `Storage` | Web SDK `Storage` service. |
| `tablesDB` | `TablesDB` | Web SDK `TablesDB` service. |
| `teams` | `Teams` | Web SDK `Teams` service. |
| `authenticated` | `boolean` | Whether the provider currently considers a user signed in. |
| `setAuthenticated` | `Dispatch<SetStateAction<boolean>>` | Update the authenticated flag manually. |
| `ssr.enabled` | `boolean` | Whether the provider was mounted with the `ssr` prop. |
| `ssr.basePath` | `string` | Mount path of the handler routes. |
| `ssr.session` | `string \| null` | Session secret read from the server cookie. |

Throws if called outside an `AppwriteProvider`.

# Server helpers {% #server-helpers %}

The library ships server entrypoints scoped per framework. Each helper exposes the same surface, differing only in how it reads the request cookie.

```ts
// Next.js (App Router)
import { createNextServerHelpers } from "@appwrite.io/react/server/next";

// TanStack Start
import { createTanStackServerHelpers } from "@appwrite.io/react/server/tanstack";
```

Both return an object with:

| Method | Returns | Use it for |
| --- | --- | --- |
| `readSessionCookie()` | `string \| undefined` (sync on TanStack, async on Next.js) | The session secret. Pass it into `AppwriteProvider`'s `ssr.session` prop. |
| `getLoggedInUser()` | `Models.User \| null` | The current user, fetched via the cookie. Returns `null` on 401. |
| `getSession()` | `Models.Session \| null` | The current session row. |
| `createSessionClient()` | `NodeSessionServer \| null` | A `node-appwrite` client authenticated as the cookie's user, or `null` if no cookie. |
| `createAdminClient()` | `AdminServer` | A `node-appwrite` admin client authenticated with the API key passed to the helper. Only available when `apiKey` is set in the helper config. |

## Session client {% #session-client %}

For per-request operations scoped to the current user (reading their data, calling APIs on their behalf), call `createSessionClient` from the framework helper. It returns a `node-appwrite` client already authenticated with the session cookie, ready to use in server components, loaders, and server functions.

```ts
import { createNextServerHelpers } from "@appwrite.io/react/server/next";

const helpers = createNextServerHelpers({
  endpoint: process.env.NEXT_PUBLIC_APPWRITE_ENDPOINT!,
  projectId: process.env.NEXT_PUBLIC_APPWRITE_PROJECT_ID!,
});

const session = await helpers.createSessionClient();

if (session) {
  const user = await session.account.get();
}
```

## Admin client {% #admin-client %}

For privileged operations (creating users, listing sessions, managing teams), call `createAdminClient` on the framework helper. Pass `apiKey` in the helper config to enable it, then call the method anywhere on the server. The returned object exposes every `node-appwrite` service: `account`, `users`, `tablesDB`, `storage`, `teams`, `functions`, `messaging`, and more.

```ts
import { createNextServerHelpers } from "@appwrite.io/react/server/next";

const helpers = createNextServerHelpers({
  endpoint: process.env.NEXT_PUBLIC_APPWRITE_ENDPOINT!,
  projectId: process.env.NEXT_PUBLIC_APPWRITE_PROJECT_ID!,
  apiKey: process.env.APPWRITE_API_KEY!,
});

const admin = helpers.createAdminClient();
const users = await admin.users.list();
```

If you would rather not pass the API key into the helper, the library also exports a standalone `createAdminClient` from `@appwrite.io/react/server` that takes the key directly.

```ts
import { createAdminClient } from "@appwrite.io/react/server";

const admin = createAdminClient({
  endpoint: process.env.NEXT_PUBLIC_APPWRITE_ENDPOINT!,
  projectId: process.env.NEXT_PUBLIC_APPWRITE_PROJECT_ID!,
  apiKey: process.env.APPWRITE_API_KEY!,
});

const users = await admin.users.list();
```

Never import any `@appwrite.io/react/server/*` module from client code: the entrypoints are marked `server-only` and will throw if bundled into the browser.

# Handler routes {% #handlers %}

The SSR mutations (`sign-in`, `sign-up`, `sign-out`, `oauth/callback`, `oauth/failure`) live behind a handler route you mount once per app. The handler reads the request, talks to Appwrite with the server API key, and writes the session cookie back.

{% tabs %}
{% tabsitem #handler-next title="Next.js" %}
```ts
// app/api/appwrite/[...appwrite]/route.ts
import { createAppwriteHandlers } from "@appwrite.io/react/handlers/next";

export const { GET, POST } = createAppwriteHandlers({
  endpoint: process.env.NEXT_PUBLIC_APPWRITE_ENDPOINT!,
  projectId: process.env.NEXT_PUBLIC_APPWRITE_PROJECT_ID!,
  apiKey: process.env.APPWRITE_API_KEY!,
  basePath: "/api/appwrite",
});
```
{% /tabsitem %}

{% tabsitem #handler-tanstack title="TanStack Start" %}
```ts
// src/routes/api/appwrite/$.ts
import { createFileRoute } from "@tanstack/react-router";
import { createAppwriteHandlers } from "@appwrite.io/react/handlers/tanstack";

export const Route = createFileRoute("/api/appwrite/$")({
  server: {
    handlers: createAppwriteHandlers({
      endpoint: import.meta.env.VITE_APPWRITE_ENDPOINT,
      projectId: import.meta.env.VITE_APPWRITE_PROJECT_ID,
      apiKey: process.env.APPWRITE_API_KEY!,
      basePath: "/api/appwrite",
    }),
  },
});
```
{% /tabsitem %}
{% /tabs %}

## Cookie and redirect options {% #handler-options %}

Customize how sessions persist in the browser and where users land after an OAuth round trip.

```ts
createAppwriteHandlers({
  endpoint: process.env.NEXT_PUBLIC_APPWRITE_ENDPOINT!,
  projectId: process.env.NEXT_PUBLIC_APPWRITE_PROJECT_ID!,
  apiKey: process.env.APPWRITE_API_KEY!,
  basePath: "/api/appwrite",
  cookieName: "my-app-session",
  cookieOptions: {
    sameSite: "strict",
    domain: ".example.com",
  },
  redirects: {
    success: "/dashboard",
    failure: "/login?error=oauth",
  },
});
```

| Option | Default | Description |
| --- | --- | --- |
| `cookieName` | `appwrite-session-<projectId>` | Name of the HTTP-only session cookie. |
| `cookieOptions.secure` | `true` | Set the `Secure` flag on the cookie. Modern browsers accept this on `localhost` over HTTP. |
| `cookieOptions.sameSite` | `"lax"` | SameSite policy. |
| `cookieOptions.httpOnly` | `true` | HttpOnly flag. |
| `cookieOptions.path` | `"/"` | Cookie path. |
| `cookieOptions.domain` | unset | Restrict cookie to a domain. |
| `redirects.success` | `"/"` | URL to redirect to after a successful OAuth login. |
| `redirects.failure` | `"/"` | URL to redirect to after a failed OAuth attempt. |

# Required scopes {% #scopes %}

Your server API key needs three scopes for the SSR handler to function:

- `users.write` (sign-up creates a user)
- `users.read` (sign-up confirms the created user)
- `sessions.write` (sign-in, sign-out, and OAuth callbacks create or delete sessions)

# Next steps {% #next-steps %}

- [Start with React](/docs/quick-starts/react)
- [Start with Next.js](/docs/quick-starts/nextjs)
- [Start with TanStack Start](/docs/quick-starts/tanstack-start)
- [Account API reference](/docs/references/cloud/client-web/account)

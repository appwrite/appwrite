---
layout: tutorial
title: OAuth authentication with SSR
description: Add authentication to a SvelteKit project using Appwrite.
step: 7
---

To support the OAuth flow, we first redirect the user to the OAuth provider, and then handle the callback from the OAuth provider.

To redirect, add a button to our sign up page that redirects the user to the OAuth provider.

```svelte
<!-- src/routes/signup/+page.svelte -->

<!-- ... existing sign up form -->

<form action="?/oauth" method="post">
  <button type="submit">Sign up with GitHub</button>
</form>
```

Add a new server route to handle the redirect.

```js
// src/routes/signup/+page.server.js

import { SESSION_COOKIE, createAdminClient, createSessionClient } from "$lib/server/appwrite.js";
import { redirect } from "@sveltejs/kit";
import { ID, OAuthProvider } from "node-appwrite";

export const actions = {
  // ... existing email sign up action
  oauth: async (event) => {
    const { account } = createAdminClient();

    const redirectUrl = await account.createOAuth2Token({
      provider: OAuthProvider.Github,
      success: `${event.url.origin}/oauth`,
      failure: `${event.url.origin}/signup`
    });

    throw redirect(302, redirectUrl);
  },
};

```

The `createOAuth2Token` method redirects the user to the OAuth provider, and then the OAuth provider redirects the user back to the `/oauth` route with the `userId` and `secret` URL query parameters.

Handle the callback and create a session for the user. Create a new server route at `src/routes/oauth/+server.js`:

```js
// src/routes/oauth/+server.js

import { SESSION_COOKIE, createAdminClient } from "$lib/server/appwrite";

export async function GET(event) {
  // We should have a `userId` and `secret` query parameters in the URL
  const userId = event.url.searchParams.get("userId");
  const secret = event.url.searchParams.get("secret");

  if (!userId || !secret) {
    return new Response("Missing `userId` or `secret` query parameters", {
      status: 400,
    });
  }

  // Exchange the token `userId` and `secret` for a session
  const { account } = createAdminClient();
  const session = await account.createSession({
    userId,
    secret
  });

  // Redirect to the account page, and set the session cookie
  const headers = new Headers({
    location: "/account",
    "set-cookie": event.cookies.serialize(SESSION_COOKIE, session.secret, {
      sameSite: "strict",
      expires: new Date(session.expire),
      secure: true,
      path: "/",
    }),
  });

  return new Response(null, { status: 302, headers });
}
```

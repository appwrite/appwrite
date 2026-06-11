---
layout: tutorial
title: OAuth authentication with SSR
description: Add authentication to a Nuxt project using Appwrite.
step: 7
---

To support the OAuth flow, we first redirect the user to the OAuth provider, and then handle the callback from the OAuth provider.

To redirect, add a button to our sign up page that redirects the user to the OAuth provider.

```vue
<!-- pages/signup.vue -->

<!-- ... existing sign up form -->

<form action="/api/oauth" method="post">
  <input type="hidden" name="provider" value="github" />
  <button type="submit">Sign up with GitHub</button>
</form>
```

Add a new server route to handle the redirect.

```js
// server/routes/api/oauth.post.js
import { createAdminClient } from "~/server/lib/appwrite";
import { OAuthProvider } from "node-appwrite";

export default defineEventHandler(async (event) => {
  const config = useRuntimeConfig(event);
  const { account } = createAdminClient();
  const endpoint = config.public.appwriteEndpoint;

  const redirectUrl = await account.createOAuth2Token({
    provider: OAuthProvider.Github,
    success: `${endpoint}/api/oauth`,
    failure: `${endpoint}/signup`
  });

  // Redirect the user to the OAuth provider authorization page
  await sendRedirect(event, redirectUrl);
});
```

The `createOAuth2Token` method redirects the user to the OAuth provider, and then the OAuth provider redirects the user back to the `/oauth` route with the `userId` and `secret` URL query parameters.

Handle the callback and create a session for the user. Create a new server route at `server/routes/api/oauth.get.js`.

```js
// server/routes/api/oauth.get.js
import { SESSION_COOKIE, createAdminClient } from "~/server/lib/appwrite";

export default defineEventHandler(async (event) => {
  // Extract the userId and secret from the URL query parameters
  const { userId, secret } = getQuery(event);
  if (!userId || !secret) {
    return sendRedirect(event, "/signup");
  }

  // Create the Appwrite client
  const { account } = createAdminClient();

  // Exchange the token userId and secret for a session
  const session = await account.createSession({
    userId,
    secret
  });

  // Set the session cookie
  setCookie(event, SESSION_COOKIE, session.secret, {
    expires: new Date(session.expire),
    path: "/",
    httpOnly: true,
    secure: true,
    sameSite: "strict",
  });

  // Redirect the user to the account page
  await sendRedirect(event, "/account");
});
```

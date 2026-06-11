---
layout: tutorial
title: OAuth authentication with SSR
description: Add authentication to a Astro project using Appwrite.
step: 7
---

To support the OAuth flow, we first redirect the user to the OAuth provider, and then handle the callback from the OAuth provider.

To redirect, add a button to our sign up page that redirects the user to the OAuth provider.

```js
<!-- src/pages/signup.astro -->

<!-- ... existing sign up form -->

<form action="/oauth" method="post">
  <button type="submit">Sign up with GitHub</button>
</form>
```

Add a new `POST` route to handle the redirect.

```js
// src/pages/oauth.js

import { createAdminClient } from "../server/appwrite";
import { OAuthProvider } from "node-appwrite";

export const POST = async ({ redirect, url }) => {
  // Create the Appwrite client
  const { account } = createAdminClient();

  // Create an OAuth token
  const redirectUrl = await account.createOAuth2Token({
    provider: OAuthProvider.Github,
    success: `${url.origin}/oauth`,
    failure: `${url.origin}/signup`
  });

  // Redirect the end-user to the OAuth2 provider authentication
  return redirect(redirectUrl);
};
```

The `createOAuth2Token` method returns a URL to the OAuth provider. After authentication the OAuth provider redirects the user back to the `/oauth` route with the `userId` and `secret` URL query parameters.

Create a new `GET` route to handle the callback and create a session for the user.

```js
// src/pages/oauth.js

import { createAdminClient, SESSION_COOKIE } from "../server/appwrite";
import { OAuthProvider } from "node-appwrite";

// ... existing POST handler

export const GET = async ({ redirect, cookies, url }) => {
  // Get the user ID and secret from the URL
  const userId = url.searchParams.get("userId");
  const secret = url.searchParams.get("secret");

  // Create the Appwrite client
  const { account } = createAdminClient();

  // Exchange the token for a session
  const session = await account.createSession({
    userId,
    secret
  });

  // Set the session cookie
  cookies.set(SESSION_COOKIE, session.secret, {
    sameSite: "lax",
    expires: new Date(session.expire),
    secure: true,
    httpOnly: true,
    path: "/",
  });

  // Redirect the logged in user to the account page
  return redirect("/account");
};
```

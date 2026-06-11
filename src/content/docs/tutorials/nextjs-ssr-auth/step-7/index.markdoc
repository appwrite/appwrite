---
layout: tutorial
title: OAuth authentication with SSR
description: Add authentication to a Next.js project using Appwrite.
step: 7
---

## Enable OAuth provider {% #enable-oauth-provider %}

To enable the GitHub OAuth provider, navigate to your Appwrite Console > Auth > Settings > OAuth2 Providers > GitHub

To support the OAuth flow, we first redirect the user to the OAuth provider, and then handle the callback from the OAuth provider.

## OAuth server action {% #oauth-server-action %}

Add a new server action. Navigate to `src/lib/server` and create a new file `oauth.js`:

```js
// src/lib/server/oauth.js
"use server";

import { createAdminClient } from "@/lib/server/appwrite";
import { redirect } from "next/navigation";
import { headers } from "next/headers";
import { OAuthProvider } from "node-appwrite";

export async function signUpWithGithub() {
	const { account } = await createAdminClient();

  const origin = headers().get("origin");

	const redirectUrl = await account.createOAuth2Token({
		provider: OAuthProvider.Github,
		success: `${origin}/oauth`,
		failure: `${origin}/signup`,
	});

	return redirect(redirectUrl);
};
```

The `createOAuth2Token` method redirects the user to the OAuth provider, and then the OAuth provider redirects the user back to the `/OAuth` route with the `userId` and `secret` URL query parameters.

## OAuth form {% #oauth-form %}

To redirect, add a button to our sign up page that redirects the user to the OAuth provider.

```jsx
// src/app/signup/page.jsx

// ... existing imports
import { signUpWithGithub } from "@/lib/server/oauth";

export default async function SignUpPage() {
  const user = await getLoggedInUser();
  if (user) redirect("/account");

  return (
    <>
      {/* ... existing form */}
      <form action={signUpWithGithub}>
        <button type="submit">Sign up with GitHub</button>
      </form>
    </>
  );
}

```

## OAuth callback {% #oauth-callback %}

Handle the callback and create a session for the user. Create a new Next.js server route at `src/app/oauth/route.js`:

```js
// src/app/oauth/route.js

import { createAdminClient } from "@/lib/server/appwrite";
import { cookies } from "next/headers";
import { NextResponse } from "next/server";

export async function GET(request) {
  const userId = request.nextUrl.searchParams.get("userId");
  const secret = request.nextUrl.searchParams.get("secret");

  const { account } = await createAdminClient();
  const session = await account.createSession({
    userId,
    secret
  });

  cookies().set("my-custom-session", session.secret, {
    path: "/",
    httpOnly: true,
    sameSite: "strict",
    secure: true,
  });

  return NextResponse.redirect(`${request.nextUrl.origin}/account`);
}
```
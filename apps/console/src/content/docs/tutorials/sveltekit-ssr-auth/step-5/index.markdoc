---
layout: tutorial
title: Create sign up page
description: Add authentication to a SvelteKit project using Appwrite.
step: 5
---

We can now implement our sign up page. Create a `+page.svelte` file in the `src/routes/signup` directory:

```svelte
<!-- src/routes/signup/+page.svelte -->
<form action="?/signup" method="post">
  <input id="email" name="email" placeholder="Email" type="email" />
  <input id="password" name="password" placeholder="Password" type="password" />
  <input id="name" name="name" placeholder="Name" type="text" />
  <button type="submit">Sign up</button>
</form>

```

This is an HTML form with an email and password input. When the form is submitted, we want to send the email and password to Appwrite to authenticate the user. To use SvelteKit form actions we create a `+page.server.js` file in the same directory:

```javascript
// src/routes/signup/+page.server.js

import { SESSION_COOKIE, createAdminClient } from "$lib/server/appwrite.js";
import { redirect } from "@sveltejs/kit";
import { ID, OAuthProvider } from "node-appwrite";

export const actions = {
  signup: async ({ request, cookies }) => {
    // Extract the form data.
    const form = await request.formData();
    const email = form.get("email");
    const password = form.get("password");
    const name = form.get("name");

    // Create the Appwrite client.
    const { account } = createAdminClient();

    // Create the session using the client
    await account.create({
        userId: ID.unique(),
        email,
        password,
        name
    });
    const session = await account.createEmailPasswordSession({
        email,
        password
    });

    // Set the session cookie with the secret
    cookies.set(SESSION_COOKIE, session.secret, {
      sameSite: "strict",
      expires: new Date(session.expire),
      secure: true,
      path: "/",
    });

    // Redirect to the account page.
    redirect(302, "/account");
  },
};
```
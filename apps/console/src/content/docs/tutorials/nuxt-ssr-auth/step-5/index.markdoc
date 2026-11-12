---
layout: tutorial
title: Create sign up page
description: Add authentication to a Nuxt project using Appwrite.
step: 5
---

We can now implement our sign up page. Create a `signup.vue` file in the `pages` directory.

```vue
<!-- pages/signup.vue -->
<template>
  <form method="post" action="/api/signup">
    <input id="email" name="email" placeholder="Email" type="email" />
    <input
      id="password"
      name="password"
      placeholder="Password"
      type="password"
    />
    <button type="submit">Sign up</button>
  </form>
</template>
```

This is an HTML form with an email and password input. When the form is submitted, we want to send the email and password to Appwrite to authenticate the user. To use Nuxt form actions we create a `signup.post.js` file in the `server/api` directory:

```javascript
// server/api/signup.post.js
import { ID } from "node-appwrite";
import { SESSION_COOKIE, createAdminClient } from "~/server/lib/appwrite";

export default defineEventHandler(async (event) => {
  // Extract the form data
  const formData = await readFormData(event);
  const email = formData.get("email");
  const password = formData.get("password");

  // Create the Appwrite client.
  const { account } = createAdminClient(event);

  // Create a new user with email and password
  await account.create({
    userId: ID.unique(),
    email,
    password
  });

  // Create the session using the client
  const session = await account.createEmailPasswordSession({
    email,
    password
  });

  // Set the session cookie with the secret
  setCookie(event, SESSION_COOKIE, session.secret, {
    expires: new Date(session.expire),
    path: "/",
    httpOnly: true,
    secure: true,
    sameSite: "strict",
  });

  // Redirect to the account page.
  await sendRedirect(event, "/account");
});
```

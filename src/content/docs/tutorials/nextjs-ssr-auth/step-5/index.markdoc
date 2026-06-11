---
layout: tutorial
title: Create sign up page
description: Add authentication to a Next.js project using Appwrite.
step: 5
---

We can now implement our sign up page. Create a `page.jsx` file in the `src/app/signup` directory:

```jsx
// src/app/signup/page.jsx

import {
  getLoggedInUser
} from "@/lib/server/appwrite";

export default async function SignUpPage() {
  const user = await getLoggedInUser();
  if (user) redirect("/account");

  return (
    <>
      <form action={signUpWithEmail}>
        <input
          id="email"
          name="email"
          placeholder="Email"
          type="email"
        />
        <input
          id="password"
          name="password"
          placeholder="Password"
          minLength={8}
          type="password"
        />
        <input
          id="name"
          name="name"
          placeholder="Name"
          type="text"
        />
        <button type="submit">Sign up</button>
      </form>
    </>
  );
}

```

This is an HTML form with an email and password input. When the form is submitted, we want to send the email and password to Appwrite to authenticate the user. To use Next.js form actions we create the `signUpWithEmail` function in the same file:

```jsx
// src/app/signup/page.jsx

// previous imports ...

import { ID } from "node-appwrite";
import { createAdminClient } from "@/lib/server/appwrite";
import { cookies } from "next/headers";
import { redirect } from "next/navigation";

async function signUpWithEmail(formData) {
  "use server";

  const email = formData.get("email");
  const password = formData.get("password");
  const name = formData.get("name");

  const { account } = await createAdminClient();

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

  cookies().set("my-custom-session", session.secret, {
    path: "/",
    httpOnly: true,
    sameSite: "strict",
    secure: true,
  });

  redirect("/account");
}

// the SignUpPage component ...
```

The `signUpWithEmail` function is an async function that takes the form data as an argument. It uses the `createAdminClient` function to create an admin Appwrite client and then calls the `createEmailPasswordSession` method on the `account` object. This method takes the email and password as arguments and returns a session object. We then set the session secret in a cookie and redirect the user to the account page.
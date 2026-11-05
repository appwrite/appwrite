---
layout: tutorial
title: Create sign up page
description: Add authentication to a Astro project using Appwrite.
step: 5
---

We can now implement our sign up page. Create a `signin.astro` file in the `src/pages` directory:

```js
---
const { user } = Astro.locals;
if (user) {
  return Astro.redirect("/account");
}
---

<form method="POST">
  <input id="email" name="email" placeholder="Email" type="email" />
  <input id="password" name="password" placeholder="Password" type="password" />
  <input id="name" name="name" placeholder="Name" type="text" />
  <button type="submit"> Sign up</button>
</form>
```

This is an HTML form with an email and password input.
When the form is submitted, we want to send the email and password to Appwrite to authenticate the user.
To use Astro form actions, add an `if (Astro.request.method === "POST")` statement to the server-side javascript.

In the same file, implement the following.

```js
---
import { SESSION_COOKIE, createAdminClient } from "../server/appwrite";
import { ID } from "node-appwrite";

// ... existing javascript

if (Astro.request.method === "POST") {
  // Extract the form data
  const data = await Astro.request.formData();
  const email = data.get("email");
  const password = data.get("password");
  const name = data.get("name");

  // Create the admin client
  const { account } = createAdminClient();

  // Create the email password session
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

  // Set the session cookie
  Astro.cookies.set(SESSION_COOKIE, session.secret, {
    path: "/",
    expires: new Date(session.expire),
    sameSite: "strict",
    secure: true,
    httpOnly: true,
  });

  // Redirect to the account page
  return Astro.redirect("/account");
}
---
<!--- ... rest of existing form --->
```
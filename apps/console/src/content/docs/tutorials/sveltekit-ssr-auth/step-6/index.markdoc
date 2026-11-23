---
layout: tutorial
title: Create account page
description: Add authentication to a SvelteKit project using Appwrite.
step: 6
---

Now the end-user is able to sign up, we can create the account page. This page will display basic information about the user, and allow the user to log out. Create a new file in the `src/routes/account` directory called `+page.server.js` and add the following code:

```js
// src/routes/account/+page.server.js

import { SESSION_COOKIE, createSessionClient } from "$lib/server/appwrite.js";
import { redirect } from "@sveltejs/kit";

export async function load({ locals }) {
  // Logged out users can't access this page.
  if (!locals.user) redirect(302, "/signup");

  // Pass the stored user local to the page.
  return {
    user: locals.user,
  };
}

// Define our log out endpoint/server action.
export const actions = {
  default: async (event) => {
    // Create the Appwrite client.
    const { account } = createSessionClient(event);

    // Delete the session on Appwrite, and delete the session cookie.
    await account.deleteSession({ sessionId: "current" });
    event.cookies.delete(SESSION_COOKIE, { path: "/" });

    // Redirect to the sign up page.
    redirect(302, "/signup");
  },
};
```

Create a new file in the `src/routes/account` directory called `+page.svelte` and add the following code:

```svelte
<script>
  export let data;

  const { user } = data
</script>

<ul>
  <li>
    <strong>Email:</strong> {user.email}
  </li>
  <li>
      <strong>Name:</strong> {user.name}
  </li>
  <li>
      <strong>ID: </strong> {user.$id}
  </li>
</ul>

<form method="post">
  <button type="submit">Log out</button>
</form>
```

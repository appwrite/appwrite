---
layout: tutorial
title: Create account page
description: Add authentication to a Astro project using Appwrite.
step: 6
---

Now the end-user is able to sign up, we can create the account page. This page will display basic information about the user, and allow the user to log out. Create a new file in the `src/pages` directory called `account.astro` and add the following code:

```js
---
import { SESSION_COOKIE, createSessionClient } from "../server/appwrite";

// Redirect the user if not signed in
const { user } = Astro.locals;
if (!user) {
  return Astro.redirect("/signup");
}

// Handle form action
if (Astro.request.method === "POST") {
  // Create session client
  const { account } = createSessionClient(Astro.request);

  // Delete the Appwrite session
  await account.deleteSession({ sessionId: 'current' });

  // Delete the session cookie
  Astro.cookies.delete(SESSION_COOKIE);

  // Redirect the user to sign up page
  return Astro.redirect("/signup");
}
---
<ul>
	<li>
		<strong>Email:</strong> {user.email}
	</li>
	<li>
		<strong>Name:</strong> {user.name}
	</li>
	<li>
		<strong>ID:</strong> {user.$id}
	</li>
</ul>

<form method="POST">
  <button type="submit">Sign out</button>
</form>
```
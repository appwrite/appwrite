---
layout: tutorial
title: Create account page
description: Add authentication to a Nuxt project using Appwrite.
step: 6
---

Now the end-user is able to sign up, we can create the account page. This page will display basic information about the user, and allow the user to log out.

Before creating the account page, the route should fetch the user data. Create a new `user.get.js` file in the `server/routes/api` directory and add the following code:

```js
// server/routes/api/user.get.js
export default defineEventHandler(async (event) => {
    const user = event.context.user;

    if (!user) {
        return false;
    }

    return user;
})
```

Create a new file in the `pages` directory called `account.vue` and add the following code:

```vue
<!-- pages/account.vue -->
<script setup>
// Fetch user data from your API endpoint
const { data: user } = await useFetch('/api/user')

if (user.value === false) {
  await navigateTo('/signup')
}
</script>

<template>
  <div v-if="user">
    <ul>
      <li>
        <strong>Email:</strong> {{ user.email }}
      </li>
      <li>
        <strong>Name:</strong> {{ user.name }}
      </li>
      <li>
        <strong>ID: </strong> {{ user.$id }}
      </li>
    </ul>
    <form method="post" action="/api/signout">
      <button type="submit">Log out</button>
    </form>
  </div>
</template>
```

This page will display the user's email, name, and ID. It also contains a form that will log the user out when submitted. The form will send a `POST` request to the `/api/signout` endpoint. We need to create this endpoint in the server. Create a new file in the `server/routes/api` directory called `signout.post.js` and add the following code:

```javascript
// server/routes/api/signout.post.js
import { SESSION_COOKIE, createSessionClient } from "~/server/lib/appwrite";

export default defineEventHandler(async (event) => {
  const { account } = createSessionClient(event);

  await account.deleteSession({ sessionId: "current" });
  deleteCookie(event, SESSION_COOKIE);

  await sendRedirect(event, "/signup");
});
```
---
layout: tutorial
title: Add server middleware
description: Add authentication to a Nuxt project using Appwrite.
step: 4
---

Nuxt server middle are functions that run on the server before a route is displayed to the user. Nuxt context allows you to store data for the lifecycle of the current request. We can use this to store the user's account data, so that it is available to all pages.

Create a new file in the `server/middleware` directory called `auth.js`.
```js
// server/middleware/auth.js
import { createSessionClient } from "../lib/appwrite";

export default defineEventHandler(async (event) => {
  const { account } = createSessionClient(event);

  try {
    event.context.user = await account.get();
  } catch (error) {}
});
```

To ensure the `context` object is typed correctly, we can add a type definition for it in the `env.d.ts` file:

```ts
import type { Models } from "node-appwrite";

declare module "h3" {
  interface H3EventContext {
    user?: Models.User<Models.Preferences<any>>;
  }
}
```

Now, use the `context` object in the home page to redirect based on the user's login status. Create a new file in the `server/routes` directory called `index.js`:

```js
export default defineEventHandler(async (event) => {
  if (event.context.user) {
    await sendRedirect(event, "/account");
  }

  await sendRedirect(event, "/signup");
});
```

When a user visits the home page, they will be redirected to the sign up page if they are not logged in, or to the account page if they are logged in.
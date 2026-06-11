---
layout: tutorial
title: Get the logged in user
description: Add authentication to a Next.js project using Appwrite.
step: 4
---

Build a utility function to get the logged in user from Appwrite. This function will be used in our components and routes to check if a user is logged in, and access the user's details.

Edit the `src/lib/server/appwrite.js` file to create a new function called `getLoggedInUser`.
```js
// ... your initilization functions

export async function getLoggedInUser() {
  try {
    const { account } = await createSessionClient();
    return await account.get();
  } catch (error) {
    return null;
  }
}
```

Now, use the `getLoggedInUser` function in the home page to redirect based on the user's login status. Create a new file in the `app` directory called `page.jsx`.

```js
// src/app/page.jsx

import { getLoggedInUser } from "@/lib/server/appwrite";
import { redirect } from "next/navigation";

export default async function Home() {
  const user = await getLoggedInUser();

  if (!user) redirect("/signup");

  redirect("/account");
}
```

The user will be redirected to the sign up page if they are not logged in, or to the account page if they are logged in.

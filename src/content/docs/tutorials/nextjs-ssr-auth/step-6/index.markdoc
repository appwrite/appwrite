---
layout: tutorial
title: Create account page
description: Add authentication to a Next.js project using Appwrite.
step: 6
---

Now the end-user is able to sign up, we can create the account page. This page will display basic information about the user, and allow the user to log out. Create a new file in the `src/app/account` directory called `page.jsx` and add the following code:

```jsx
// src/app/account/page.jsx

import {
  createSessionClient,
  getLoggedInUser,
} from "@/lib/server/appwrite";
import { redirect } from "next/navigation";
import { cookies } from "next/headers";

async function signOut() {
  "use server";

  const { account } = await createSessionClient();

  cookies().delete("my-custom-session");
  await account.deleteSession({ sessionId: "current" });

  redirect("/signup");
}

export default async function HomePage() {
  const user = await getLoggedInUser();
  if (!user) redirect("/signup");

  return (
    <>
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

      <form action={signOut}>
        <button type="submit">Sign out</button>
      </form>
    </>
  );
}
```

This code is similar to the `signup` page, but it uses the `getLoggedInUser` function to get the user's information. If the user is not logged in, the page will redirect to the sign-in page. Again, we use Next.js form actions to execute Appwrite code on the server. This time, the `signOut` function deletes the session cookie and redirect the user to the sign-in page.

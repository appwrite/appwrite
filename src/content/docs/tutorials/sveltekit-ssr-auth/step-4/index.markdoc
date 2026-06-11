---
layout: tutorial
title: Add a server hook
description: Add authentication to a SvelteKit project using Appwrite.
step: 4
---

SvelteKit hooks which are functions that run on the server before a page is displayed to the user. SvelteKit locals are a way to store data that is specific to the current request. We can use this to store the user's account data, so that it is available to all pages.

Create a new file in the `src` directory called `hooks.server.js`:

```js
// src/hooks.server.js
import { createSessionClient } from '$lib/server/appwrite';

export async function handle({ event, resolve }) {
  try {
    // Use our helper function to create the Appwrite client.
    const { account } = createSessionClient(event);
    // Store the current logged in user in locals,
    // for easy access in our other routes.
    event.locals.user = await account.get();
  } catch {}
  
  // Continue with the request.
  return resolve(event);
}
```

To ensure the `locals` object is typed correctly, we can add a type definition for it in the `env.d.ts` file:

```ts
// env.d.ts

import type { Models } from "node-appwrite";

// See https://kit.svelte.dev/docs/types#app
// for information about these interfaces
declare global {
  namespace App {
    // interface Error {}
    interface Locals {
      user: Models.User<Models.Preferences> | undefined;
    }
    // interface PageData {}
    // interface Platform {}
  }
}

export {};
```

Now, use the `locals` object in the home page to redirect based on the user's login status. 
Create a new file in the `src/routes` directory called `+page.server.js`:

```js
// src/routes/+page.server.js

import { redirect } from '@sveltejs/kit';

export async function load({ locals }) {
  // Access our user from locals.
  if (!locals.user) {
    // If no user is logged in, redirect to the sign up page.
    redirect(301, '/signup');
  }
  
  // If the user is logged in, redirect to the account page.
  redirect(301, '/account');
}
```

When a user visits the home page, they will be redirected to the sign up page if they are not logged in, or to the account page if they are logged in.

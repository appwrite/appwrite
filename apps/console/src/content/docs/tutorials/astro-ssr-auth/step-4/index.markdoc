---
layout: tutorial
title: Add a server hook
description: Add authentication to a Astro project using Appwrite.
step: 4
---

Astro middleware are functions that run on the server before a page is displayed to the user. Astro locals are a way to store data that is specific to the current request. We can use these features to store the user's account data, so that it is available to all pages.

Create a new file in the `src/` directory called `middleware.js`:

```js
// src/middleware.js

import { defineMiddleware } from "astro:middleware";
import { createSessionClient } from "./server/appwrite";

export const onRequest = defineMiddleware(async ({ request, locals }, next) => {
  try {
    const { account } = createSessionClient(request);
    locals.user = await account.get();
  } catch {}

  return next();
});
```

To ensure the `locals` object is typed correctly, we can add a type definition for it in a `env.d.ts` file at the root of the project:

```ts
/// <reference types="astro/client" />

type Models = import("node-appwrite").Models;

declare namespace App {
  interface Locals {
    user?: Models.User<Models.Preferences>;
  }
}

interface ImportMetaEnv {
  readonly PUBLIC_APPWRITE_ENDPOINT: string;
  readonly PUBLIC_APPWRITE_PROJECT: string;
  readonly APPWRITE_KEY: string;
}

interface ImportMeta {
  readonly env: ImportMetaEnv;
}
```

Now, use the `locals` object in the home page to redirect based on the user's login status. 
Overwrite the file in the `src/pages` directory called `index.astro`:

```js
---
const { user } = Astro.locals;
if (user) {
  return Astro.redirect("/account");
}

return Astro.redirect("/signup");
---
```

If the user is logged in, they will be redirected to the account page. If they are not logged in, they will be redirected to the sign up page.

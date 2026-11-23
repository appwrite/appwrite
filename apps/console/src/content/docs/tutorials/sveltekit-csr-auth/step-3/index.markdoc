---
layout: tutorial
title: Initialize SDK
description: Add Authentication to a SvelteKit project using Appwrite.
step: 3
---
Before you can use Appwrite, you need to instanciate the Appwrite `Client` class with the project ID and endpoint. 
This tells the SDK where your Appwrite project is hosted and which one to connect to.

The client is then used to initialize services like `Databases` and `Account`, so they all point to the same Appwrite project.

You can do this by instantiating the services you need in a file like `src/lib/appwrite.js` and **exporting the instances**.

```js
// src/lib/appwrite.js
import {
  PUBLIC_APPWRITE_ENDPOINT,
  PUBLIC_APPWRITE_PROJECT,
} from "$env/static/public";
import { Databases, Account, Client } from "appwrite";

const client = new Client();
client
  .setEndpoint(PUBLIC_APPWRITE_ENDPOINT)
  .setProject(PUBLIC_APPWRITE_PROJECT);

const account = new Account(client);
const tablesDB = new TablesDB(client);

export const appwrite = {
  client,
  account,
  databases,
};
```

`PUBLIC_APPWRITE_ENDPOINT` and `PUBLIC_APPWRITE_PROJECT` are environment variables that are exported in your project's [.env file](https://kit.svelte.dev/docs/modules#$env-dynamic-public).

For example, your `.env` might look something similar to this.

```env
PUBLIC_APPWRITE_ENDPOINT=https://<REGION>.cloud.appwrite.io/v1
PUBLIC_APPWRITE_PROJECT=642sdddf85b440dc7e5bf
```

You can get the values for these variables from the Appwrite console's **Settings** page.
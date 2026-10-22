---
layout: tutorial
title: Initialize SDK
description: Add authentication to a Astro project using Appwrite.
step: 3
---

Before you can use Appwrite, you need to create the Appwrite `Client` and set the project ID and endpoint.
The client is then used to create services like `Databases` and `Account`, so they all point to the same Appwrite project.

Create a function to build services you need in a file like `src/server/appwrite.js` and **exporting the instances**.

As part of the function, set the current user's session if they are logged in. This is done by accessing the session cookie from the request and calling the `setSession(session)` with the cookie value.

{% info title="Appwrite client security" %}
Notice that `createAdminClient` and `createSessionClient` returns **a new instance** of the Appwrite Client.
When using Appwrite in server-integrations, it's important to **never share a `Client` instance** between two requests.
Doing so could create security vulnerabilities.
{% /info %}

```js
// src/server/appwrite.js

import { Client, Account } from "node-appwrite";

// The name of your cookie that will store the session
export const SESSION_COOKIE = "my-custom-session";

// Admin client, used to create new accounts
export function createAdminClient() {
  const client = new Client()
    .setEndpoint(import.meta.env.PUBLIC_APPWRITE_ENDPOINT)
    .setProject(import.meta.env.PUBLIC_APPWRITE_PROJECT)
    .setKey(import.meta.env.APPWRITE_KEY); // Set the API key here!

  // Return the services you need
  return {
    get account() {
      return new Account(client);
    },
  };
}

// Session client, used to make requests on behalf of the logged in user
export function createSessionClient(request) {
  const client = new Client()
    .setEndpoint(import.meta.env.PUBLIC_APPWRITE_ENDPOINT)
    .setProject(import.meta.env.PUBLIC_APPWRITE_PROJECT);

  // Get the session cookie from the request and set the session
  const cookies = parseCookies(request.headers.get("cookie") ?? "");
  const session = cookies.get(SESSION_COOKIE);
  if (!session) {
    throw new Error("Session cookie not found");
  }

  client.setSession(session);

  // Return the services you need
  return {
    get account() {
      return new Account(client);
    },
  };
}

// Helper function to parse cookies
function parseCookies(cookies) {
  const map = new Map();
  for (const cookie of cookies.split(";")) {
    const [name, value] = cookie.split("=");
    map.set(name.trim(), value ?? null);
  }
  return map;
}
```

## Environment variables {% #environment-variables %}

`import.meta.env.APPWRITE_KEY`, `import.meta.env.PUBLIC_APPWRITE_ENDPOINT` and `import.meta.env.PUBLIC_APPWRITE_PROJECT` are environment variables that are exported in your project's [.env file](https://kit.Astro.dev/docs/modules#$env-dynamic-public).

For example, your `.env` might look something similar to this.

```env
APPWRITE_KEY=<YOUR_API_KEY>
PUBLIC_APPWRITE_ENDPOINT=https://<REGION>.cloud.appwrite.io/v1
PUBLIC_APPWRITE_PROJECT=<PROJECT_ID>
```

The `PUBLIC_APPWRITE_ENDPOINT` is the endpoint of your Appwrite project, and the `PUBLIC_APPWRITE_PROJECT` is the ID of the project you want to use.
You can get the values for these variables from the Appwrite console.

{% only_dark %}
![Create project screen](/images/docs/quick-starts/dark/create-project.avif)
{% /only_dark %}

{% only_light %}
![Create project screen](/images/docs/quick-starts/create-project.avif)
{% /only_light %}

The `APPWRITE_KEY` is an Appwrite API key with the necessary permissions to create new sessions.

For this tutorial you'll need an API key with the following scopes:

| Category  {% width=120 %}                              | Required scopes                                        | Purpose                                                |
| ------------------------------------------------------ | ------------------------------------------------------ | ------------------------------------------------------ |
| Sessions                                               | `sessions.write`                                       | Allows API key to create, update, and delete sessions. |

{% only_dark %}
![Server integrations](/images/docs/quick-starts/dark/integrate-server.avif)
{% /only_dark %}

{% only_light %}
![Server integrations](/images/docs/quick-starts/integrate-server.avif)
{% /only_light %}


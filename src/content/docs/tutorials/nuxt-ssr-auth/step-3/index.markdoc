---
layout: tutorial
title: Initialize SDK
description: Add authentication to a Nuxt project using Appwrite.
step: 3
---
Before you can use Appwrite, you need to create the Appwrite `Client` and set the project ID and endpoint.
The client is then used to create services like `Databases` and `Account`, so they all point to the same Appwrite project.

Create a function to the build services you need in a file like `server/lib/appwrite.js` and **exporting the instances**.

As part of the function, set the current user's session if they are logged in. This is done by accessing the session cookie from the request and calling the `setSession(session)` with the cookie value.

{% info title="Appwrite client security" %}
We recommend creating a new `Client` instance for each request.
When using Appwrite in server-integrations, it's important to **never share a `Client` instance** between two requests.
Doing so could create security vulnerabilities.
{% /info %}

```js
// server/lib/appwrite.js
import { Client, Account } from "node-appwrite";

export const SESSION_COOKIE = "my-custom-session";

export function createAdminClient() {
  const client = new Client()
    .setEndpoint(process.env.PUBLIC_APPWRITE_ENDPOINT)
    .setProject(process.env.PUBLIC_APPWRITE_PROJECT)
    .setKey(process.env.APPWRITE_KEY);

  return {
    get account() {
      return new Account(client);
    },
  };
}

export function createSessionClient(event) {
  const config = useRuntimeConfig(event);

  const client = new Client()
    .setEndpoint(config.public.appwriteEndpoint)
    .setProject(config.public.appwriteProjectId);

  const session = getCookie(event, SESSION_COOKIE);
  if (session) {
    client.setSession(session);
  }

  return {
    get account() {
      return new Account(client);
    },
  };
}
```

`config.appwriteApiKey`, `config.public.appwriteEndpoint` and `config.public.appwriteProjectId` are Nuxt runtime configuration variables. Create a [nuxt.config.js](https://nuxt.com/docs/api/configuration/nuxt-config) file in the root of your project and add the following code to set the runtime configuration.

```js
export default defineNuxtConfig({
  runtimeConfig: {
    appwriteKey: process.env.APPWRITE_KEY,
    public: {
      appwriteEndpoint: process.env.PUBLIC_APPWRITE_ENDPOINT,
      appwriteProjectId: process.env.PUBLIC_APPWRITE_PROJECT,
    },
  },
});
```

Now you can use `.env` files to set the environment variables for your project. Retrieve the values for these variables from the Appwrite console.

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

For example, your `.env` might look something similar to this.

```env
APPWRITE_KEY=<YOUR_API_KEY>
PUBLIC_APPWRITE_ENDPOINT=https://<REGION>.cloud.appwrite.io/v1
PUBLIC_APPWRITE_PROJECT=<PROJECT_ID>
```

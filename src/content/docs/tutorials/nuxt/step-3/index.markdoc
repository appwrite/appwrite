---
layout: tutorial
title: Set up Appwrite
description: Import and configure a project with Appwrite Cloud.
step: 3
---

## Create project {% #create-project %}

Head to the [Appwrite Console](https://cloud.appwrite.io/console).

{% only_dark %}
![Create project screen](/images/docs/quick-starts/dark/create-project.avif)
{% /only_dark %}
{% only_light %}
![Create project screen](/images/docs/quick-starts/create-project.avif)
{% /only_light %}

If this is your first time using Appwrite, create an account and create your first project.

Then, under **Add a platform**, add a **Web app**.
The **Hostname** should be `localhost`.

{% only_dark %}
![Add a platform](/images/docs/quick-starts/dark/add-platform.avif)
{% /only_dark %}
{% only_light %}
![Add a platform](/images/docs/quick-starts/add-platform.avif)
{% /only_light %}

You can skip the optional steps.

## Environment variables {% #environment-variables %}

To connect to Appwrite in our app, we'll need to use sensitive information.
We keep the secrets by using environment variables for the endpoint and project id.
Your project id is located in the **Settings** page in the Appwrite console.

{% only_dark %}
![Project settings screen](/images/docs/quick-starts/dark/project-id.avif)
{% /only_dark %}
{% only_light %}
![Project settings screen](/images/docs/quick-starts/project-id.avif)
{% /only_light %}

Add a `.env` file to the root directory and add the following code to it, replacing `PROJECT_ID` with your project id.

```
VITE_APPWRITE_ENDPOINT=https://<REGION>.cloud.appwrite.io/v1
VITE_APPWRITE_PROJECT=PROJECT_ID
```

## Initialize Appwrite SDK {% #init-sdk %}

Create a new file `appwrite.js` for the Appwrite related code.
Only one instance of the `Client()` class should be created per app.
Add the following code to it.

```ts
// appwrite.ts

import { Client, TablesDB, Account } from "appwrite";

const url: string = import.meta.env.VITE_APPWRITE_ENDPOINT;
const project: string = import.meta.env.VITE_APPWRITE_PROJECT;

const client: Client = new Client();

client.setEndpoint(url).setProject(project);

export const account: Account = new Account(client);
export const tablesDB: TablesDB = new TablesDB(client);
```

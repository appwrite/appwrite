---
layout: tutorial
title: Set up Appwrite
description: Import and initialize Appwrite for your react application.
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

Then, under **Add a platform**, add a **Web app**. The **Hostname** should be localhost. 

{% only_dark %}
![Add a platform](/images/docs/quick-starts/dark/add-platform.avif)
{% /only_dark %}
{% only_light %}
![Add a platform](/images/docs/quick-starts/add-platform.avif)
{% /only_light %}

You can skip optional steps.

## Initialize Appwrite SDK {% #init-sdk %}

To use Appwrite in our Refine app, we'll need to find our project ID. Find your project's ID in the **Settings** page. 

{% only_dark %}
![Project settings screen](/images/docs/quick-starts/dark/project-id.avif)
{% /only_dark %}
{% only_light %}
![Project settings screen](/images/docs/quick-starts/project-id.avif)
{% /only_light %}

Navigate to `src/utility/appwriteClient.ts` and add your API credentials.


```ts
import { Account, Appwrite, Storage } from "@refinedev/appwrite";

const APPWRITE_URL = '<YOUR_API_ENDPOINT>'; // Replace with your Appwrite API Endpoint
const APPWRITE_PROJECT = "<PROJECT_ID>"; // Replace with your project ID

const appwriteClient = new Appwrite();

appwriteClient.setEndpoint(APPWRITE_URL).setProject(APPWRITE_PROJECT);
const account = new Account(appwriteClient);
const storage = new Storage(appwriteClient);

export { account, appwriteClient, storage };

```
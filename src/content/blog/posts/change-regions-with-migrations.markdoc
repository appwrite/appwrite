---
layout: post
title: How to change regions in Appwrite Cloud using migrations
description: Learn how to migrate your Appwrite Cloud project from one region to another.
date: 2025-04-16
lastUpdated: 2026-06-29
cover: /images/blog/change-regions-with-migrations/cover.avif
timeToRead: 10
author: ebenezer-don
category: tutorial
featured: false
faqs:
  - question: "Why would I move an Appwrite Cloud project to a different region?"
    answer: "The two main reasons are latency and data residency. Hosting your project closer to your users reduces round-trip time, which makes APIs and realtime feel snappier. Data residency comes up when local laws require user data to stay in a specific jurisdiction, for example in the EU or India."
  - question: "Can I change the region of an existing Appwrite Cloud project directly?"
    answer: "No, there is currently no in-place region switch. The supported path is to create a new project in the target region and use Appwrite's built-in migration tool to move data and resources from the source project to the destination. This guide walks through the full process."
  - question: "What does the Appwrite migration tool transfer?"
    answer: "The migration tool moves your databases, tables and rows, storage files, functions, teams, users, and project settings to the destination project. Custom domain bindings and platform integrations usually need to be reconfigured manually after the migration. See the [Appwrite Network](/docs/products/network) docs for region-specific details."
  - question: "Will there be downtime when I migrate regions?"
    answer: "There is no forced downtime during the data transfer itself, since both projects can run side by side. The downtime, if any, happens during the cutover when you point clients to the new endpoint. Plan for a maintenance window to update environment variables and DNS, and to verify the data in the new region."
  - question: "What happens to API keys and OAuth credentials after migration?"
    answer: "You typically need to recreate API keys in the destination project because they are tied to a project ID. OAuth providers (Google, GitHub, Discord) also need to be reconfigured with new redirect URIs in their respective dashboards. Plan for this configuration work as part of the cutover, not after."
  - question: "Can I keep both regions running side by side after migration?"
    answer: "Yes, until you delete the source project both are live. Some teams keep the old project around for a few weeks as a read-only fallback, then delete it once the new region has proven stable. That is a safer pattern than deleting immediately."
---

With the launch of the **Appwrite Network**, Appwrite Cloud now gives you the ability to choose where your project is hosted. This means that you can bring your backend closer to your users, reducing latency, improving responsiveness, and aligning with local data residency laws if needed.

But what if you've already built and launched your app in one region, and now you need to move it to another? Currently, there's no simple toggle to switch regions, but Appwrite provides a built-in migration tool that allows you to create a new project in your target region and move your data and services to it.

In this guide, we'll walk through the process of migrating your Appwrite Cloud project to a new region, along with the steps to take after the migration is complete.

## What you need before starting

Before we get started, make sure a few things are in place:

- You have an Appwrite [Cloud account](https://cloud.appwrite.io/).
- You have a project in Appwrite Cloud that's currently hosted in a region you want to move from. This is your **source project**.
- You have permission to create new projects, and full access to the source project's settings.

Once you've got all that, we can get started.

## Step 1: Create your destination project

To get started, head over to the Appwrite Cloud console and create a new project. This is the one that will live in your new region.

Give it a name that distinguishes it from the original, especially if you want to keep both projects running side-by-side for a while.

When prompted to choose a region, pick the one you want to migrate into. This is where your project will be hosted going forward.

Once you've finished setting it up, you'll have a clean project ready to receive everything from your current environment.

## Step 2: Set up the destination project for import

Inside your new project, go to **Settings**, and look for the **Migrations** tab.

![Migrations tab](/images/blog/change-regions-with-migrations/migrations-tab.avif)

There you'll find the option to **Import project data**. Click the button to open the migration configuration screen.

This is where you'll enter the credentials from your source project, so Appwrite can pull everything over.

But first, we need to get those credentials.

## Step 3: Collect your source project credentials

Back in the Appwrite Cloud console, switch over to your source project.

You'll need three things from here:

1. A dedicated **API key** for migration.
2. The **Project ID** of the source project.
3. The **API endpoint** of the source project.

Here's how to get each one:

### Create an API key

From your source project, go to **Settings → API Keys** and create a new key. Give it a name that'll make it easy to identify later.

![Create API key](/images/blog/change-regions-with-migrations/create-api-key.avif)

Set an expiration date. Since this key is likely to have access to a lot of data, you don't want it hanging around forever.

When assigning scopes, either select them all, or make sure to include at least: `users.read`, `databases.read`, `storage.read`, `functions.read`, and anything else you know your app uses.

Once the key is created, copy the secret for migration.

### Copy the Project ID and API endpoint

Still in the source project, head to **Settings**, and in the **Overview** tab, **API Credentials**.

Here, you'll find:

- The **Project ID**.
- The **API Endpoint**

With this, you have your API key, project ID, and API endpoint. These are the three things you'll need to migrate your project.

## Step 4: Start the migration

Go back to your destination project, and in the **Settings** page, go to the **Migrations** tab. In **Migrations**, select **Import data** in the **Import project data** section.

Choose **Appwrite** as the source platform.

![Migrations screen](/images/blog/change-regions-with-migrations/migrations-screen.avif)

Then fill in the details from the source project:

- **Endpoint** → Paste the API endpoint URL.
- **Project ID** → Paste the source project's ID.
- **API Key** → Paste the secret key you created earlier.

Click **Next**.

Appwrite will now reach into the source project, check what's available, and return a list of resources it found: users, databases, tables, rows, storage buckets, files, functions, and so on.

You'll be prompted to choose what you want to bring over.

If you're planning to fully migrate the project, click **Select all**. You can also check top-level resources (like "Users", "Functions", "Databases") and then decide whether to include things like rows, teams, and environment variables.

When ready, click **Create**.

Now, Appwrite begins the actual data transfer.

## Step 5: Let the migration run and wait for it to complete

You'll see a progress indicator as the migration runs.

How long this takes depends on your data. If your project is lightweight, it might finish in under a minute. But if you have thousands of rows or large media files, it might take longer.

When the migration is complete, you'll see an indication in the console.

![Migration complete](/images/blog/change-regions-with-migrations/migrations-complete.avif)

## After migration: what to verify

Once the migration is done, you can inspect the destination project to ensure everything transferred correctly.

Here are some things to check (if applicable):

- **Auth** → Confirm that users and teams are present.
- **Databases** → Can you see your tables and rows?
- **Storage** → Look inside your buckets and confirm that files are present.
- **Functions** → Are your functions listed, and do they include deployments and any environment variables you selected?

Spend a few minutes to confirm that the migration worked as expected.

## What Appwrite doesn't migrate and what to do next

The migration tool brings over the majority of your project's data and config, but there are a few things you'll need to handle manually:

### Update your app code

This is an important step, since your app is still communicating with the *old* project.

Update your codebase to point to the **new Project ID** and **new API Endpoint** from the destination region. That means updating SDK initialization code across your frontend, mobile app, backend scripts, or wherever your app communicates with Appwrite.

Without this change, your app will continue talking to the source project.

### Recreate any needed API keys

The migration-specific API key doesn't stick around, and other existing keys are not carried over.

So if your app uses long-lived API keys for backend access or integrations, you'll need to recreate them in the destination project. Then update your environments accordingly.

### Re-register your platforms

Your registered platforms (iOS, Android, Web, Flutter, etc.) also aren't migrated automatically. You'll need to add them again from **Overview → Integrations → Platforms** in the destination project.

Pay close attention to this, especially if you use OAuth, custom domains, or push notifications.

### Check project-level settings

Some other configurations might also need to be manually re-applied, like:

- SMTP settings for emails
- Custom domains
- Function execution permissions
- Service limits (if you had them changed)

It's worth going through the Settings page of both projects side-by-side to spot anything missing.

## Some final thoughts before you switch

You don't need to shut down your source project immediately. You might want to run both in parallel for a short time while you test the migrated version.

When you're ready to switch, make sure your app has been fully updated to use the new configuration, and that everything behaves as expected.

Schedule a short maintenance window if needed, especially if you're updating backend services or deployment pipelines that rely on Appwrite.

And as always, test thoroughly. Auth flows, file uploads, database reads/writes, scheduled functions need to be checked.

## Wrapping up

Migrating a project between regions in Appwrite Cloud is quite straightforward once you know the steps. The import tool handles the heavy lifting, and with the right preparation (API key, project ID, endpoint) it can move most of your app's backend in minutes.

Once the migration is complete, you'll have your Appwrite backend running where you want it, aligned with your new requirements, and ready to scale in the right place.

If you run into issues, the Appwrite docs and Discord community are solid places to troubleshoot and get support.

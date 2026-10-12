---
layout: article
title: Migrate from Cloud
description: Self-hosted application migration made easy with Appwrite. Discover the steps and strategies for migrating your self-hosted apps to Appwrite's managed platform.
difficulty: beginner
readtime: 10
---
  
Migrations make it as easy as a couple of clicks to move all your Appwrite Cloud data into a self-hosted instance.

{% section #notices step=1 title="Things to keep in mind" %}

1. Data transferred by migrations will reset `$createdAt` and `$updatedAt` timestamps to the date of the migration.

2. Your self-hosted Appwrite project must be accessible from the internet for the migration to work.

3. Migrations are non-destructive. No data will be deleted or lost in the source project.

{% /section %}

{% section #create-migration step=2 title="Create migration" %}

To begin migrating to self-hosted, make sure to read the [migration overview](/docs/advanced/migrations) and [things to keep in mind](#notices) sections above.

1. Navigate to your Cloud project's console, navigate to **Settings** and click on the **Migrations** tab.

1. Under **Export to self-hosted instance**, click **Export data**.

1. You will complete the migration on your self-hosted instance.

{% /section %}

{% section #continue-on-self-hosted step=3 title="Continue on self-hosted" %}

1. Once redirected to your self-hosted project, you'll be prompted to select an organization and a project. You can migrate to an existing project or create a new one.

1. Select the data you wish to migrate. You can choose among accounts, databases, rows, files, functions, and sites.

1. Click **Start Migration** to start the migration process. You do not need to keep the Appwrite Console open through the process.

{% info title="Keep in mind" %}
Your self-hosted instance will generate an API Key in the background to pass to Appwrite Cloud. You can revoke this key after the migration process is complete.
{% /info %}
{% /section %}
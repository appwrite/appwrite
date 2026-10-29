---
layout: article
title: Migrations
description: Learn how to use Appwrite Migrations service to move projects from other vendors to Appwrite Cloud or from self-hosting to Cloud and the other way around.
difficulty: beginner
readtime: 5
---

If you're looking to migrate existing projects to Appwrite, Migrations can help you make the move more quickly. You can move your app from Firebase, Supabase, Nhost, and even move between self-hosted and Cloud projects using Migrations. You can also use Migrations to move between two self-hosted instances or even to duplicate projects on the same instance. Migrations will automatically move accounts, database rows, and storage files from one source to another.

# Sources  {% #sources %}

Appwrite supports multiple source destinations for migrating your data. You can transfer data from these sources to a new or existing Appwrite project. Resources marked as 'enabled' are migrated automatically. Resources marked as 'partial' can be migrated but with limitations or caveats; please refer to the guide for each source to learn more. Resources marked as 'manual' require manual migration.

| Source | Users | Databases | Rows | Files | Functions | Sites |
|--------|-------|-----------|-----------|-------|-----------|-------|
| [Firebase](/docs/advanced/migrations/firebase) | enabled | enabled | partial | enabled | manual | - |
| [Supabase](/docs/advanced/migrations/supabase) | enabled | enabled | partial | enabled | manual | - |
| [Nhost](/docs/advanced/migrations/nhost) | enabled | enabled | partial | enabled | manual | - |
| [Cloud](/docs/advanced/migrations/cloud) | enabled | enabled | enabled | enabled | enabled | enabled |
| [Self hosted](/docs/advanced/migrations/self-hosted) | enabled | enabled | enabled | enabled | enabled | enabled |

# Limitations {% #limitations %}

Migrations cannot transfer all data perfectly, so certain fields, such as `$createdAt` and `$updatedAt`, may not be transferred. 
More information can be found on the migration page for each source.

Migrations help you jump-start your move, but because each product is unique, complex databases and product unique features like functions might need to be migrated manually. 
We also recommend you carefully **validate permissions and data integrity** when moving between platforms.

# Charges {% #charges %}

When you migrate data from another source to Appwrite Cloud, the resource usage during the migration will not count towards your Appwrite Cloud usage charges. However, your source vendor may have data transfer charges. The same is true for moving data between self-hosted Appwrite instances hosted on different cloud providers.

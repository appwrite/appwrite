---
layout: post
title: How to plan and execute database migration successfully with the new Appwrite CLI
description: Learn how to use the new Appwrite CLI to migrate your databases.
date: 2024-08-26
lastUpdated: 2026-06-29
cover: /images/blog/how-to-migrate-database-with-cli/cover.avif
timeToRead: 5
author: ebenezer-don
category: product
faqs:
  - question: "What does `appwrite.config.json` do in the Appwrite CLI?"
    answer: "`appwrite.config.json` is the source of truth for your project's tables, functions, and other resources. You commit it to Git, edit it like any other config, and use `appwrite push` to apply changes. This lets you track schema history, code-review migrations, and replicate projects across environments."
  - question: "How do I pull my existing database schema into the CLI?"
    answer: "Run `appwrite pull tables --all` to download the current schema from your Appwrite project into `appwrite.config.json`. This gives you a baseline you can version, modify, and push back. It's the recommended first step before making schema changes."
  - question: "How does GitOps work with [Appwrite Databases](/docs/products/databases)?"
    answer: "You keep `appwrite.config.json` in a Git repo, treat schema changes as PRs, and run `appwrite push tables` from CI to apply approved changes. This gives you review, rollback (via Git history), and consistent deploys across staging and production without manual console clicks."
  - question: "Can I rename a table or column safely?"
    answer: "Renaming a column is essentially a delete and create, so any existing data on the old name will not move automatically. For schema changes that need data migration, plan a two-step approach: add the new field, backfill data with a script or function, then remove the old field once everything is migrated."
  - question: "How do I replicate a project to a new environment?"
    answer: "Copy `appwrite.config.json` to the new project, switch the CLI's project context (`appwrite client --projectId <id>`), and run `appwrite push tables`. This creates the same schema in the target project without manual setup, which is useful for staging, demo, or per-customer environments."
  - question: "What happens if `appwrite push` fails partway through?"
    answer: "The CLI applies changes column by column, so a failure can leave you in a partial state. Check the error output, fix the underlying issue (often a constraint conflict or invalid type), and rerun the push. Avoid running migrations directly against production without first testing them in a staging project."
---
Database migration is a critical task in the lifecycle of any application. It involves making schema changes while ensuring that data remains intact, often to accommodate new features, improve performance, or ensure scalability. With the release of the new Appwrite CLI, the process of planning and executing database migrations has become significantly easier. The new CLI features allow you to manage your database schemas more efficiently, ensuring smooth transitions and minimal downtime.

# The new Appwrite CLI

The new Appwrite CLI introduces several enhancements to improve the developer experience in managing database schemas. One of the standout features is the ability to use your `appwrite.config.json` file as the source of truth for your database tables' schema. This approach brings numerous benefits, including better change tracking, seamless migrations using GitOps, and simplified project replication. Let's explore these advantages in more detail:

**1. Tracking changes**

By using `appwrite.config.json` as the source for your tables schema, you can easily track changes over time. This file can be version-controlled with Git, allowing you to see a detailed history of modifications and collaborate effectively with your team.

**2. Migrating database changes with GitOps**

GitOps is a methodology that uses Git repositories to manage and deploy infrastructure changes. The new Appwrite CLI allows you to leverage GitOps for database schema changes, ensuring a smooth and consistent migration process across different environments.

**3. Easy project replication**

When you need to replicate a project, having the database schema defined in `appwrite.config.json` allows you to recreate the database setup effortlessly in different instances or environments, as well as in different projects within the same environment and instance.

# Step-by-step guide to database migration with the new Appwrite CLI

Let's walk through the process of planning and executing a database migration using the new Appwrite CLI.

## Step 1: Pull existing database configuration

If you already have a database setup, start by pulling the current configuration into your `appwrite.config.json` file. This step ensures that you have the latest schema as a baseline.

```bash
appwrite pull tables --all

```

## Step 2: Creating and pushing tables

If you are starting from scratch, you can initialize a new table and push it to Appwrite.

1. Create a new table:
    
    ```bash
    appwrite init tables
    ```
    
2. Push the table to Appwrite:
    
    ```bash
    appwrite push tables
    ```
    

## Step 3: Modifying tables

To make changes to your tables, edit the `appwrite.config.json` file. For instance, you might want to change a column's type or add a new column. Here’s an example:

### Original columns

```json
"columns": [
  {
    "key": "a",
    "type": "integer",
    "status": "available",
    "error": "",
    "required": false,
    "array": false,
    "min": 1,
    "max": 100,
    "default": null}
]
```

### Modified columns

```json
"columns": [
  {
    "key": "a",
    "type": "text",
    "status": "available",
    "error": "",
    "required": false,
    "array": false,
    "default": null},
  {
    "key": "phone",
    "type": "integer",
    "status": "available",
    "error": "",
    "required": false,
    "array": false,
    "min": 1,
    "max": 100,
    "default": null}
]
```

## Step 4: Pushing changes

After making the necessary changes, push the updated schema to Appwrite:

```bash
appwrite push tables

```

The Appwrite CLI will display a table summarizing all pending changes, allowing you to review them before applying. This ensures transparency and gives you a chance to verify changes before they take effect.

![Executing database migrations](/images/blog/how-to-migrate-database-with-cli/1.avif)

## Step 5: Confirming changes

To apply the changes, type `YES` to confirm. This step ensures that no changes are made accidentally and gives you full control over the migration process. In the case of CI/CD pipelines, you can use the --force flag to push the changes automatically; however, exercise caution when using this option.

# Conclusion

The new Appwrite CLI makes database migration a more manageable and streamlined process. By using `appwrite.config.json` as your schema’s source of truth, you can take advantage of better change tracking, seamless GitOps-based migrations, and simplified project replication. Whether you are starting from scratch or managing an existing database, these features enhance your ability to plan and execute migrations successfully.

- [Appwrite CLI docs](https://appwrite.io/docs/tooling/command-line/installation)
- [Join our Discord server](https://appwrite.io/discord)
- [More about the new Appwrite CLI](https://appwrite.io/blog/post/introducing-new-appwrite-cli)

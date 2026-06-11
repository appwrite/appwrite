---
layout: article
title: Terraform provider
description: Manage Appwrite infrastructure as code with the official Terraform provider. Works with Appwrite Cloud and Community Edition.
---

The [Terraform provider for Appwrite](https://github.com/appwrite/terraform-provider-appwrite) lets you declare **TablesDB** (databases, tables, columns, indexes, rows), **Storage** (buckets and files), **Auth** (users and teams), **Functions** (functions and variables), **Sites** (sites and variables), **Messaging** (providers, topics, subscribers), **webhooks**, **backup policies**, and more in `.tf` files, and apply those changes through [HashiCorp Terraform](https://www.terraform.io/). It is the official way to automate Appwrite project configuration alongside the rest of your stack.

# Resources {% #resources %}

Resource types use the `appwrite_` prefix and match the [Terraform Registry](https://registry.terraform.io/providers/appwrite/appwrite/latest/docs) documentation.

| Area | Resources |
|------|-----------|
| TablesDB | `appwrite_tablesdb`, `appwrite_tablesdb_table`, `appwrite_tablesdb_column`, `appwrite_tablesdb_index`, `appwrite_tablesdb_row` |
| Storage | `appwrite_storage_bucket`, `appwrite_storage_file` |
| Auth | `appwrite_auth_user`, `appwrite_auth_team` |
| Functions | `appwrite_function`, `appwrite_function_variable`, `appwrite_function_deployment` |
| Sites | `appwrite_site`, `appwrite_site_variable`, `appwrite_site_deployment` |
| Messaging | `appwrite_messaging_provider`, `appwrite_messaging_topic`, `appwrite_messaging_subscriber` |
| Webhooks | `appwrite_webhook` |
| Backups | `appwrite_backup_policy` |

# Data sources {% #data-sources %}

Data sources read resources that already exist instead of creating them. Use them to reference identifiers or attributes from resources created outside Terraform or in another state.

| Name | Description |
|------|-------------|
| `appwrite_tablesdb` | Look up a database by ID |
| `appwrite_storage_bucket` | Look up a storage bucket by ID |
| `appwrite_auth_user` | Look up a user by ID |
| `appwrite_auth_team` | Look up a team by ID |
| `appwrite_function` | Look up a function by ID |
| `appwrite_site` | Look up a site by ID |
| `appwrite_messaging_topic` | Look up a messaging topic by ID |
| `appwrite_webhook` | Look up a webhook by ID |

{% info title="Looking for self-hosting?" %}
This section is about **Appwrite resources inside a project** (for example databases and tables) using Terraform. If you want to install or operate the Appwrite server itself (Docker, configuration, TLS, scaling), go to the [self-hosting documentation](/docs/advanced/self-hosting) instead.
{% /info %}

# What is Terraform? {% #what-is-terraform %}

[Terraform](https://www.terraform.io/) is an infrastructure-as-code tool. You write **configuration** in a declarative language (HCL) that describes what should exist; Terraform figures out **how** to create or update it.

If you are new to the idea:

- **Provider**: A plugin that teaches Terraform how to talk to a specific API (here, Appwrite).
- **Resource**: Something Terraform should create and manage (for example an Appwrite database or table).
- **State**: Terraform remembers what it already created so the next run can update or destroy only what changed.
- **Plan / apply**: You run `terraform plan` to preview changes, then `terraform apply` to execute them.

You still use the [Appwrite Console](https://cloud.appwrite.io) and SDKs for day-to-day app development; the provider is for **repeatable, version-controlled** setup of project resources across environments.

# Official repository and registry {% #official-repository-and-registry %}

Source code, issues, and contribution guidelines:

{% cards %}
{% cards_item href="https://github.com/appwrite/terraform-provider-appwrite" title="GitHub" icon="web-icon-github" %}
`appwrite/terraform-provider-appwrite`: source and issues.
{% /cards_item %}
{% cards_item href="https://registry.terraform.io/providers/appwrite/appwrite/latest/docs" title="Terraform Registry" icon="web-icon-terraform" %}
`appwrite/appwrite`: install, versions, generated schemas.
{% /cards_item %}
{% /cards %}

The provider works with **Appwrite Cloud** and **Community Edition** (self-hosted Appwrite). You point it at your API endpoint and authenticate with a project API key.

# Next steps {% #next-steps %}

{% cards %}
{% cards_item href="/docs/tooling/terraform/provider" title="Configuration" %}
Install the provider, set endpoints, environment variables, and optional per-resource `project_id`.
{% /cards_item %}
{% cards_item href="/docs/tooling/terraform/resources/databases" title="Databases" %}
TablesDB: databases, tables, columns, indexes, and rows.
{% /cards_item %}
{% cards_item href="/docs/tooling/terraform/resources/storage" title="Storage" %}
Buckets and uploaded files.
{% /cards_item %}
{% cards_item href="/docs/tooling/terraform/resources/messaging" title="Messaging" %}
Providers, topics, and subscribers.
{% /cards_item %}
{% cards_item href="/docs/tooling/terraform/resources/auth" title="Auth" %}
Users and teams.
{% /cards_item %}
{% cards_item href="/docs/tooling/terraform/resources/functions" title="Functions" %}
Functions and environment variables.
{% /cards_item %}
{% cards_item href="/docs/tooling/terraform/resources/sites" title="Sites" %}
Sites and site variables.
{% /cards_item %}
{% cards_item href="/docs/tooling/terraform/resources/webhooks" title="Webhooks" %}
Event delivery to your HTTP endpoints.
{% /cards_item %}
{% cards_item href="/docs/tooling/terraform/resources/backups" title="Backups" %}
Backup policies where supported.
{% /cards_item %}
{% /cards %}

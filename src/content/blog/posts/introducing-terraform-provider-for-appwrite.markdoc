---
layout: post
title: "Introducing Terraform support for Appwrite projects"
description: "The official Appwrite Terraform provider brings your entire Appwrite project into HashiCorp Configuration Language (HCL): TablesDB, Storage, Functions, Sites, Auth, Messaging, webhooks, backups, and more, with Registry docs, imports, and guides for Cloud and self-hosted Community Edition."
date: 2026-04-24
cover: /images/blog/terraform-provider-appwrite/cover.avif
timeToRead: 10
author: levi-van-noort
category: product, announcement
featured: false
faqs:
  - question: "What is the Appwrite Terraform provider?"
    answer: "It's the official `appwrite/appwrite` provider on the HashiCorp Terraform Registry. It lets you manage project resources (TablesDB, Storage, Functions, Sites, Auth, Messaging, webhooks, and backup policies) declaratively in HCL through the Appwrite API."
  - question: "Does the provider install or operate an Appwrite instance?"
    answer: "No. It only manages resources inside an existing Appwrite project. You still install and operate self hosted Appwrite using your usual deployment process. The provider talks to Appwrite Cloud or your self hosted instance over the API."
  - question: "What credentials does the Terraform provider need?"
    answer: "An Appwrite API endpoint, a project ID, and an API key with the scopes for the resources you want to manage. The same model your CI scripts already use. For self hosted setups with custom certificates, you can also set `self_signed`."
  - question: "Can I import an existing Appwrite project into Terraform?"
    answer: "Yes. Standard `terraform import` commands map existing resources by ID into your state file, so you can adopt Terraform on a project without recreating anything. The provider's docs cover the per resource import format."
  - question: "What problem does Terraform solve compared to appwrite.json?"
    answer: "`appwrite.json` is great for a single project. Terraform shines when you need consistent baselines across staging, production, and multiple regions, with `plan` / `apply` running in CI and a shared state file tracking real world drift."
  - question: "Which Appwrite features can I manage with the provider?"
    answer: "[Databases](/docs/products/databases), [Storage](/docs/products/storage) buckets and files, [Functions](/docs/products/functions), [Sites](/docs/products/sites), [Auth](/docs/products/auth) users and teams, [Messaging](/docs/products/messaging) providers and topics, webhooks, and backup policies are all in scope."
---

If you already treat infrastructure as code elsewhere, Appwrite project configuration was the odd layer out: databases, tables, storage buckets, functions, and sites were easy to tweak in the Console, with the CLI, or in `appwrite.json`, and hard to keep identical across staging, production, and extra regions. That is operational drift with a familiar fix: declare the same resources in HashiCorp Configuration Language (HCL), store the Terraform **state** next to your other tooling, and let `plan` / `apply` (or your CI wrapper) do the talking.

The **official Terraform provider** is now published as [`appwrite/appwrite`](https://registry.terraform.io/providers/appwrite/appwrite/latest) on the HashiCorp Terraform Registry. It **manages project resources over the Appwrite API**: TablesDB, Storage, Functions, Sites, Auth, Messaging, webhooks, and backup policies. It does not install or operate the Appwrite instance itself. For that, stay with [self-hosting](/docs/advanced/self-hosting) and your existing deployment story.

# Why we built it this way

We set out to make Terraform cross-cutting across the Appwrite project configuration, not a databases-only path you abandon when you work on Functions, Sites, or the next surface area. The provider maps most of what you manage at the project layer through the API, so one IaC story covers those capabilities instead of a tool that stops at schemas.

**Cross-project** mattered too: the same workflow is meant to work across Appwrite projects and environments when you need aligned baselines, not only inside a single stack or a single product silo.

We also wanted it **open by design**. Use it for familiar patterns (parity between environments, `plan` / `apply` in CI, imports from existing projects), or compose resources in ways that fit your architecture when you need something less conventional. The point is full freedom to stay traditional or get creative; Terraform is the shared layer, not a rigid template you have to follow.

# What is in scope

The provider maps Appwrite project resources to Terraform resources the way you would expect if you have used the platform before. **TablesDB** is the deepest surface: databases, tables, columns, indexes, and rows so you can declare schemas and seed data in HCL. **Storage** covers buckets and file uploads (`appwrite_storage_bucket`, `appwrite_storage_file`). **Functions** and **Sites** let you define serverless functions (with runtimes, schedules, event triggers, and environment variables) and hosted frontends (with framework presets for Next.js, Nuxt, Astro, SvelteKit, and others) right alongside the backend resources they depend on. **Auth** resources manage users and teams (`appwrite_auth_user`, `appwrite_auth_team`). **Messaging** handles providers, topics, and subscribers. Dedicated resources for **webhooks** (`appwrite_webhook`) and **backup policies** (`appwrite_backup_policy`) round out the set. An **`appwrite_tablesdb` data source** looks up an existing database by ID when another resource needs a stable reference.

Authentication follows the same model as any other automation: your Appwrite **API endpoint**, a **project API key** with the right scopes, and (on Community Edition or dev clusters) whatever TLS settings you already use. The [configuration guide](/docs/tooling/terraform/provider) documents `endpoint`, `project_id`, `api_key`, and `self_signed` so you can mirror how CI talks to Appwrite today.

# What a basic flow looks like

End to end, Terraform does what you expect: pin the provider, pass credentials, declare resources, then run `terraform init`, `terraform plan`, and `terraform apply` when you like the diff. The snippets below are intentionally small so you can see the shape before you open the full guides.

**Provider and configuration.** Declare `appwrite/appwrite`, then point the provider at Appwrite Cloud (swap `<REGION>` for your [region](/docs/products/network/regions) subdomain). On Community Edition, set `endpoint` to your instance URL and use `self_signed` when you need it. The [Configuration](/docs/tooling/terraform/provider) page has the exact pattern.

```hcl
terraform {
  required_providers {
    appwrite = {
      source  = "appwrite/appwrite"
      version = "~> 1.0"
    }
  }
}

provider "appwrite" {
  endpoint   = "https://<REGION>.cloud.appwrite.io/v1"
  project_id = "project-id"
  api_key    = "api-key"
}
```

**A database and table.** A `appwrite_tablesdb` resource creates a database, and `appwrite_tablesdb_table` adds a table inside it.

```hcl
resource "appwrite_tablesdb" "main" {
  name = "main"
}

resource "appwrite_tablesdb_table" "users" {
  database_id = appwrite_tablesdb.main.id
  name        = "users"
}
```

**A storage bucket.** Buckets follow the same pattern: declare the resource and configure the options you need.

```hcl
resource "appwrite_storage_bucket" "images" {
  name                    = "images"
  maximum_file_size       = 10485760
  allowed_file_extensions = ["jpg", "png", "webp", "gif"]
  compression             = "gzip"
}
```

**A serverless function.** Declare the runtime, wire up event triggers, and set environment variables, all in HCL.

```hcl
resource "appwrite_function" "on_signup" {
  name       = "on-signup"
  runtime    = "node-22"
  entrypoint = "index.js"
  commands   = "npm install"
  events     = ["users.*.create"]
  timeout    = 30
}

resource "appwrite_function_variable" "api_url" {
  function_id = appwrite_function.on_signup.id
  key         = "API_URL"
  value       = "https://api.example.com"
}
```

**A hosted site.** Pick a framework preset and configure the build pipeline.

```hcl
resource "appwrite_site" "dashboard" {
  name            = "dashboard"
  framework       = "nextjs"
  build_runtime   = "node-22"
  install_command = "npm install"
  build_command   = "npm run build"
}
```

From there you layer columns, indexes, rows, files, messaging resources, webhooks, backup policies, and data sources as needed. Start from the [Terraform provider overview](/docs/tooling/terraform) and the guides for [Databases](/docs/tooling/terraform/resources/databases), [Storage](/docs/tooling/terraform/resources/storage), [Functions](/docs/tooling/terraform/resources/functions), [Sites](/docs/tooling/terraform/resources/sites), [Auth](/docs/tooling/terraform/resources/auth), [Messaging](/docs/tooling/terraform/resources/messaging), [Webhooks](/docs/tooling/terraform/resources/webhooks), and [Backups](/docs/tooling/terraform/resources/backups).

# How Terraform fits

You are not replacing the Console for debugging or the SDKs for application logic. You are giving project-level config a path through code review, pinned `required_providers` versions, and repeatable applies, the same contract you use when someone opens a PR against VPC rules or a managed database.

In practice that usually means: preview changes with `terraform plan` before they hit a shared environment; attach plans to tickets or merge requests; and import resources that predate the Terraform rollout instead of recreating them by hand. The generated documentation on the [Terraform Registry](https://registry.terraform.io/providers/appwrite/appwrite/latest/docs) is the source of truth for arguments, attributes, and import syntax per resource.

# Where to go next

Our [Terraform provider](/docs/tooling/terraform) docs list every resource area and link to [Configuration](/docs/tooling/terraform/provider) (`required_providers`, environment variables, optional per-resource `project_id`), resource guides for [Databases](/docs/tooling/terraform/resources/databases) (including the `appwrite_tablesdb` data source for lookups), [Storage](/docs/tooling/terraform/resources/storage), [Messaging](/docs/tooling/terraform/resources/messaging), [Auth](/docs/tooling/terraform/resources/auth), [Functions](/docs/tooling/terraform/resources/functions), [Sites](/docs/tooling/terraform/resources/sites), [Webhooks](/docs/tooling/terraform/resources/webhooks), and [Backups](/docs/tooling/terraform/resources/backups).

The implementation is open-source at [`appwrite/terraform-provider-appwrite`](https://github.com/appwrite/terraform-provider-appwrite) for issues, feature discussion, and contributions. The Integrations catalog also lists [Terraform provider for Appwrite](/integrations/terraform-provider) next to the rest of the ecosystem. This release is summarized in the [changelog](/changelog/entry/2026-04-24). The [Registry listing](https://registry.terraform.io/providers/appwrite/appwrite/latest) tracks the latest published provider version and generated schema.

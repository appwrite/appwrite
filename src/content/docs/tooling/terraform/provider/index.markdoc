---
layout: article
title: Configuration
description: Configure the Appwrite Terraform provider for Cloud or Community Edition using endpoints, API keys, and optional environment variables.
---

The Appwrite provider is published as `appwrite/appwrite` on the [Terraform Registry](https://registry.terraform.io/providers/appwrite/appwrite/latest). The registry hosts **generated reference docs** for the provider and every resource and data source: [latest docs](https://registry.terraform.io/providers/appwrite/appwrite/latest/docs). Full examples and attribute tables also live in the [provider repository](https://github.com/appwrite/terraform-provider-appwrite).

# Terraform block {% #terraform-block %}

Declare the provider source in a `terraform` block. You can add a `version` constraint when you want to pin a release; see published versions on the [registry provider page](https://registry.terraform.io/providers/appwrite/appwrite/latest).

```hcl
terraform {
  required_providers {
    appwrite = {
      source = "appwrite/appwrite"
    }
  }
}
```

# Appwrite Cloud {% #appwrite-cloud %}

Replace `<REGION>` with your project’s region subdomain (see [Regions](/docs/products/network/regions)).

```hcl
provider "appwrite" {
  endpoint   = "https://<REGION>.cloud.appwrite.io/v1"
  project_id = "project-id"
  api_key    = "api-key"
}
```

# Community Edition {% #community-edition %}

For self-hosted Appwrite instances, set your instance URL and enable `self_signed` when you use a certificate that is not trusted by default (common in local or internal deployments):

```hcl
provider "appwrite" {
  endpoint    = "https://appwrite-instance.com/v1"
  project_id  = "project-id"
  api_key     = "api-key"
  self_signed = true
}
```

# Environment variables {% #environment-variables %}

You can supply credentials via environment variables instead of hard-coding them in `.tf` files (recommended for CI and local development):

```bash
export APPWRITE_ENDPOINT="https://<REGION>.cloud.appwrite.io/v1"
export APPWRITE_PROJECT_ID="project-id"
export APPWRITE_API_KEY="api-key"
```

When an environment variable is set, the matching provider argument does not need to appear in the configuration.

| Provider argument | Environment variable | Required | Description |
|-------------|------------------------|----------|-------------|
| `endpoint` | `APPWRITE_ENDPOINT` | yes | Appwrite API endpoint |
| `project_id` | `APPWRITE_PROJECT_ID` | no | Default project ID for resources (omit if you set `project_id` on each resource) |
| `api_key` | `APPWRITE_API_KEY` | yes | API key with permissions for the resources you manage |
| `self_signed` | - | no | Accept self-signed TLS certificates (Community Edition) |

# Project scoping {% #project-scoping %}

You can set `project_id` on the **provider** as the default for all resources, or set `project_id` on **individual resources** when one Terraform configuration manages multiple Appwrite projects.

# API keys {% #api-keys %}

Use a key with the scopes required for the resources you manage (for example TablesDB, Storage, Messaging, Functions, Sites, Auth, webhooks, and backups). Follow the principle of least privilege and rotate keys stored outside Terraform.

# Related {% #related %}

- [Overview](/docs/tooling/terraform): full resource list
- [Databases](/docs/tooling/terraform/resources/databases): TablesDB resources
- [Storage](/docs/tooling/terraform/resources/storage): buckets and files
- [Messaging](/docs/tooling/terraform/resources/messaging): providers, topics, and subscribers
- [Self-hosting](/docs/advanced/self-hosting): install and run the Appwrite server (not the same as configuring project resources with this provider)

---
layout: article
title: Backups
description: Configure Appwrite backup policies with Terraform where your plan supports them.
---

The `appwrite_backup_policy` resource configures **backup policies** for supported resources. Availability depends on your Appwrite Cloud plan or self-hosted setup.

See the Terraform Registry: [backup_policy](https://registry.terraform.io/providers/appwrite/appwrite/latest/docs/resources/backup_policy). The [provider repository](https://github.com/appwrite/terraform-provider-appwrite) lists the full argument reference.

# Resource {% #resource %}

| Resource | Purpose |
|----------|---------|
| `appwrite_backup_policy` | Configure backup policies for supported resources |

Policies use **`schedule`** (CRON), **`retention`** (days), and **`services`** (for example `["databases"]`). Omit **`resource_id`** to cover all databases in the project, or set **`resource_id`** to a specific database ID (often `appwrite_tablesdb.*.id`) to back up one database.

# Example {% #example %}

```hcl
resource "appwrite_backup_policy" "daily" {
  name      = "daily database backup"
  services  = ["databases"]
  retention = 7
  schedule  = "0 2 * * *"
}

resource "appwrite_tablesdb" "main" {
  name = "main"
}

resource "appwrite_backup_policy" "production" {
  name        = "production database backup"
  services    = ["databases"]
  resource_id = appwrite_tablesdb.main.id
  retention   = 14
  schedule    = "0 */6 * * *"
}
```

# Related {% #related %}

- [Databases](/docs/tooling/terraform/resources/databases): TablesDB resources you can target with `resource_id`
- [Configuration](/docs/tooling/terraform/provider): authentication and endpoints

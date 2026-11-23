---
layout: article
title: Auth
description: Manage Appwrite users and teams with the Terraform provider.
---

The provider exposes **Auth** resources so you can align users and teams with the rest of your infrastructure-as-code workflow.

For generated schemas and import syntax, see the Terraform Registry: [auth_user](https://registry.terraform.io/providers/appwrite/appwrite/latest/docs/resources/auth_user) and [auth_team](https://registry.terraform.io/providers/appwrite/appwrite/latest/docs/resources/auth_team). The [provider repository](https://github.com/appwrite/terraform-provider-appwrite) contains source and examples.

# Resources {% #resources %}

| Resource | Purpose |
|----------|---------|
| `appwrite_auth_user` | Create and manage users |
| `appwrite_auth_team` | Create and manage teams |

Use these together with your normal [Auth](/docs/products/auth) and permission models; scope API keys appropriately when Terraform manages identity resources.

# Examples {% #examples %}

## Users {% #users %}

Pass credentials through [Terraform variables](https://developer.hashicorp.com/terraform/language/values/variables) so the values are not committed to `.tf` files. `labels` let you tag users for access control or downstream automation.

```hcl
resource "appwrite_auth_user" "john" {
  name     = "john doe"
  email    = "john@example.com"
  password = var.user_password
}

resource "appwrite_auth_user" "admin" {
  name     = "admin"
  email    = "admin@example.com"
  password = var.admin_password
  labels   = ["admin", "staff"]
}
```

## Teams {% #teams %}

`roles` sets the default roles granted to new team members. Omit it to use the provider default (`["owner"]`).

```hcl
resource "appwrite_auth_team" "engineering" {
  name = "engineering"
}

resource "appwrite_auth_team" "marketing" {
  name  = "marketing"
  roles = ["owner", "editor"]
}
```

# Data sources {% #data-sources %}

The **`appwrite_auth_user`** and **`appwrite_auth_team`** data sources read users and teams that already exist (for example created through the Console or via your application) by ID. Use them to wire identities into other resources without managing them with Terraform.

```hcl
data "appwrite_auth_user" "admin" {
  id = "64f2cd7e27bda9f23ab6"
}

data "appwrite_auth_team" "engineers" {
  id = "engineers"
}

output "admin_email" {
  value = data.appwrite_auth_user.admin.email
}
```

See the Terraform Registry for the full attribute lists: [auth_user data source](https://registry.terraform.io/providers/appwrite/appwrite/latest/docs/data-sources/auth_user) and [auth_team data source](https://registry.terraform.io/providers/appwrite/appwrite/latest/docs/data-sources/auth_team).

# Related {% #related %}

- [Configuration](/docs/tooling/terraform/provider): authentication and endpoints
- [Auth product docs](/docs/products/auth)

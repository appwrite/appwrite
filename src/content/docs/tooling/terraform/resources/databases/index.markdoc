---
layout: article
title: Databases
description: Use Terraform to manage Appwrite TablesDB databases, tables, columns, indexes, and rows with the official Appwrite provider.
---

The provider exposes Appwrite **TablesDB** as Terraform resources. Typical order: create a **database** (`appwrite_tablesdb`), then **tables**, then **columns** and **indexes**, and optionally **rows**.

For full generated schemas, see the Terraform Registry: [tablesdb](https://registry.terraform.io/providers/appwrite/appwrite/latest/docs/resources/tablesdb), [tablesdb_table](https://registry.terraform.io/providers/appwrite/appwrite/latest/docs/resources/tablesdb_table), [tablesdb_column](https://registry.terraform.io/providers/appwrite/appwrite/latest/docs/resources/tablesdb_column), [tablesdb_index](https://registry.terraform.io/providers/appwrite/appwrite/latest/docs/resources/tablesdb_index), and [tablesdb_row](https://registry.terraform.io/providers/appwrite/appwrite/latest/docs/resources/tablesdb_row). The [provider repository](https://github.com/appwrite/terraform-provider-appwrite) contains the source and examples.

# Resources {% #resources %}

| Resource | Purpose |
|----------|---------|
| `appwrite_tablesdb` | Create a database in your project |
| `appwrite_tablesdb_table` | Create a table within a database |
| `appwrite_tablesdb_column` | Define columns (types, constraints, defaults) |
| `appwrite_tablesdb_index` | Add indexes on one or more columns |
| `appwrite_tablesdb_row` | Insert and manage rows in a table |

Relationships use `database_id` and `table_id` references, often wired through Terraform resource attributes (for example `appwrite_tablesdb.main.id`).

# Example {% #example %}

This pattern matches the upstream documentation: a database, a `users` table, several columns, and a unique index on email.

```hcl
resource "appwrite_tablesdb" "main" {
  id   = "main"
  name = "main"
}

resource "appwrite_tablesdb_table" "users" {
  database_id = appwrite_tablesdb.main.id
  id          = "users"
  name        = "users"
}

resource "appwrite_tablesdb_column" "name" {
  database_id = appwrite_tablesdb.main.id
  table_id    = appwrite_tablesdb_table.users.id
  key         = "name"
  type        = "varchar"
  size        = 255
  required    = true
}

resource "appwrite_tablesdb_column" "email" {
  database_id = appwrite_tablesdb.main.id
  table_id    = appwrite_tablesdb_table.users.id
  key         = "email"
  type        = "email"
  required    = true
}

resource "appwrite_tablesdb_column" "age" {
  database_id = appwrite_tablesdb.main.id
  table_id    = appwrite_tablesdb_table.users.id
  key         = "age"
  type        = "integer"
  min         = 0
  max         = 150
}

resource "appwrite_tablesdb_column" "role" {
  database_id = appwrite_tablesdb.main.id
  table_id    = appwrite_tablesdb_table.users.id
  key         = "role"
  type        = "enum"
  elements    = ["admin", "editor", "viewer"]
  default     = "viewer"
}

resource "appwrite_tablesdb_column" "tags" {
  database_id = appwrite_tablesdb.main.id
  table_id    = appwrite_tablesdb_table.users.id
  key         = "tags"
  type        = "varchar"
  size        = 64
  array       = true
}

resource "appwrite_tablesdb_column" "location" {
  database_id = appwrite_tablesdb.main.id
  table_id    = appwrite_tablesdb_table.users.id
  key         = "location"
  type        = "point"
}

resource "appwrite_tablesdb_index" "email_unique" {
  database_id = appwrite_tablesdb.main.id
  table_id    = appwrite_tablesdb_table.users.id
  key         = "email_unique"
  type        = "unique"
  columns     = [appwrite_tablesdb_column.email.key]
}
```

Column `type` supports the types your Appwrite version exposes (for example `varchar`, `email`, `integer`, `enum`, `point`, and more); check the provider docs for the full set.

# Seeding rows {% #seeding-rows %}

The `appwrite_tablesdb_row` resource inserts rows declaratively. Pass the payload through `jsonencode` and list the columns the row depends on so Terraform does not attempt to insert before the schema is in place.

```hcl
resource "appwrite_tablesdb_row" "alice" {
  database_id = appwrite_tablesdb.main.id
  table_id    = appwrite_tablesdb_table.users.id
  data = jsonencode({
    name  = "alice"
    email = "alice@example.com"
  })

  depends_on = [
    appwrite_tablesdb_column.name,
    appwrite_tablesdb_column.email,
  ]
}
```

Use this for fixtures, default content, or small reference tables. For bulk data loads, prefer the SDKs or the Appwrite CLI.

# Data sources {% #data-sources %}

**Data sources** read resources that already exist instead of creating them. The provider exposes **`appwrite_tablesdb`** so you can reference a database by ID (for example when it was created outside Terraform or in another state).

See the [Terraform Registry](https://registry.terraform.io/providers/appwrite/appwrite/latest/docs/data-sources/tablesdb) for the full argument list.

```hcl
data "appwrite_tablesdb" "existing" {
  id = "main"
}

resource "appwrite_tablesdb_table" "example" {
  database_id = data.appwrite_tablesdb.existing.id
  id          = "example"
  name        = "example"
}
```

# Related {% #related %}

- [Databases product docs](/docs/products/databases): concepts and Console workflows
- [Configuration](/docs/tooling/terraform/provider): authentication and endpoints

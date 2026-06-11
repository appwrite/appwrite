---
layout: article
title: Functions
description: Manage Appwrite Functions, environment variables, and deployments with Terraform.
---

[Functions](/docs/products/functions) can be declared as Terraform resources, including **runtime**, **entrypoint**, **build commands**, **events**, and **per-function environment variables**. The provider also exposes an **`appwrite_function_deployment`** resource so you can ship code from a local tar archive or from a Git template alongside the rest of your configuration.

See the Terraform Registry: [function](https://registry.terraform.io/providers/appwrite/appwrite/latest/docs/resources/function), [function_variable](https://registry.terraform.io/providers/appwrite/appwrite/latest/docs/resources/function_variable), and [function_deployment](https://registry.terraform.io/providers/appwrite/appwrite/latest/docs/resources/function_deployment). The [provider repository](https://github.com/appwrite/terraform-provider-appwrite) includes examples.

# Resources {% #resources %}

| Resource | Purpose |
|----------|---------|
| `appwrite_function` | Create and update a function (runtime, entrypoint, commands, events, timeout, and related settings) |
| `appwrite_function_variable` | Set environment variables scoped to a function |
| `appwrite_function_deployment` | Push a new deployment from local code (`tar.gz`) or a Git template and optionally activate it |

# Examples {% #examples %}

## Basic function {% #basic-function %}

```hcl
resource "appwrite_function" "hello_world" {
  name       = "hello-world"
  runtime    = "node-22"
  entrypoint = "index.js"
  commands   = "npm install"
}
```

## Scheduled function {% #scheduled-function %}

Run a function on a CRON schedule. Set `timeout` to cap the execution window.

```hcl
resource "appwrite_function" "daily_cleanup" {
  name     = "daily-cleanup"
  runtime  = "node-22"
  schedule = "0 0 * * *"
  timeout  = 60
}
```

## Event-driven function {% #event-driven-function %}

Trigger the function from Appwrite platform events. `execute` controls who (or what) is allowed to invoke the function outside of the event trigger.

```hcl
resource "appwrite_function" "on_user_create" {
  name       = "on-user-create"
  runtime    = "node-22"
  events     = ["users.*.create"]
  entrypoint = "index.js"
  execute    = ["any"]
}
```

## Environment variables {% #environment-variables %}

`appwrite_function_variable` attaches environment variables to a function. Mark a variable with `secret = true` to prevent the value from being read back from the API after creation; update and delete continue to work.

```hcl
resource "appwrite_function_variable" "api_url" {
  function_id = appwrite_function.hello_world.id
  key         = "API_URL"
  value       = "https://api.example.com"
}

resource "appwrite_function_variable" "secret_key" {
  function_id = appwrite_function.hello_world.id
  key         = "SECRET_KEY"
  value       = var.secret_key
  secret      = true
}
```

## VCS integration {% #vcs-integration %}

Connect a function to a Git repository so Appwrite deploys automatically on push. Obtain the `installation_id` and `provider_repository_id` from your Appwrite GitHub (or other VCS) integration.

```hcl
resource "appwrite_function" "api" {
  name    = "api"
  runtime = "node-22"

  installation_id         = "your-github-installation-id"
  provider_repository_id  = "your-repository-id"
  provider_branch         = "main"
  provider_root_directory = "functions/api"
}
```

## Deploy from local code {% #deploy-from-code %}

Use **`source_type = "code"`** to upload a `tar.gz` artifact from disk. Use `filesha256()` on the same path so Terraform detects code changes and triggers a new deployment.

```hcl
resource "appwrite_function_deployment" "hello_world" {
  function_id = appwrite_function.hello_world.id
  source_type = "code"
  code_path   = "dist/hello-world.tar.gz"
  code_hash   = filesha256("dist/hello-world.tar.gz")
  entrypoint  = "index.js"
  commands    = "npm install"
  activate    = true
}
```

## Deploy from a template repository {% #deploy-from-template %}

Use **`source_type = "template"`** to pull from a Git repository (for example one of Appwrite's starter templates). Pin `type` to `branch`, `tag`, or `commit` and set `reference` accordingly.

```hcl
resource "appwrite_function_deployment" "from_template" {
  function_id    = appwrite_function.hello_world.id
  source_type    = "template"
  repository     = "starter-template"
  owner          = "appwrite"
  root_directory = "node"
  type           = "branch"
  reference      = "main"
  activate       = true
}
```

By default the resource waits for the deployment to reach a ready state before it returns; set `wait_for_ready = false` if you want `terraform apply` to return as soon as the deployment is created.

# Data sources {% #data-sources %}

The **`appwrite_function`** data source reads an existing function by ID. Use it when the function was created outside Terraform or in another state, and you need to reference attributes such as `runtime` or the active `deployment_id`.

```hcl
data "appwrite_function" "existing" {
  id = "on-signup"
}

output "function_runtime" {
  value = data.appwrite_function.existing.runtime
}
```

See the [Terraform Registry](https://registry.terraform.io/providers/appwrite/appwrite/latest/docs/data-sources/function) for the full attribute list.

# Related {% #related %}

- [Functions product docs](/docs/products/functions)
- [Configuration](/docs/tooling/terraform/provider)

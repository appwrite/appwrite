---
layout: article
title: Sites
description: Manage Appwrite Sites, environment variables, and deployments with Terraform.
---

[Sites](/docs/products/sites) supports Terraform resources for the **site** definition, **build-time environment variables**, and **deployments** that publish your site from a local artifact or a Git template.

See the Terraform Registry: [site](https://registry.terraform.io/providers/appwrite/appwrite/latest/docs/resources/site), [site_variable](https://registry.terraform.io/providers/appwrite/appwrite/latest/docs/resources/site_variable), and [site_deployment](https://registry.terraform.io/providers/appwrite/appwrite/latest/docs/resources/site_deployment). The [provider repository](https://github.com/appwrite/terraform-provider-appwrite) includes examples.

# Resources {% #resources %}

| Resource | Purpose |
|----------|---------|
| `appwrite_site` | Create and update a site (framework, build and install commands, runtimes, and related settings) |
| `appwrite_site_variable` | Set environment variables for a site (for example `NEXT_PUBLIC_*` keys) |
| `appwrite_site_deployment` | Push a new deployment from local code (`tar.gz`) or a Git template and optionally activate it |

# Examples {% #examples %}

The `framework` argument accepts `nextjs`, `nuxt`, `sveltekit`, `astro`, `remix`, `analog`, `react`, `vue`, `vite`, `flutter`, and `other`. Use `other` for static sites or unsupported frameworks.

## Static site {% #static-site %}

```hcl
resource "appwrite_site" "landing_page" {
  name          = "landing-page"
  framework     = "other"
  build_runtime = "node-22"
}
```

## Next.js site {% #nextjs-site %}

```hcl
resource "appwrite_site" "dashboard" {
  name            = "dashboard"
  framework       = "nextjs"
  build_runtime   = "node-22"
  install_command = "npm install"
  build_command   = "npm run build"
}
```

## Astro site {% #astro-site %}

Astro and other static-output frameworks need an explicit `output_directory` pointing at the build artifact.

```hcl
resource "appwrite_site" "docs" {
  name             = "docs"
  framework        = "astro"
  build_runtime    = "node-22"
  install_command  = "npm install"
  build_command    = "npm run build"
  output_directory = "dist"
}
```

## Environment variables {% #environment-variables %}

`appwrite_site_variable` attaches build-time environment variables (for example `NEXT_PUBLIC_*` keys). Mark sensitive values with `secret = true` so the value is not readable from the API after creation.

```hcl
resource "appwrite_site_variable" "api_url" {
  site_id = appwrite_site.dashboard.id
  key     = "NEXT_PUBLIC_API_URL"
  value   = "https://api.example.com"
}

resource "appwrite_site_variable" "secret_key" {
  site_id = appwrite_site.dashboard.id
  key     = "SECRET_KEY"
  value   = var.secret_key
  secret  = true
}
```

## VCS integration {% #vcs-integration %}

Connect a site to a Git repository so Appwrite deploys automatically on push. Obtain the `installation_id` and `provider_repository_id` from your Appwrite GitHub (or other VCS) integration.

```hcl
resource "appwrite_site" "app" {
  name          = "app"
  framework     = "nextjs"
  build_runtime = "node-22"

  installation_id         = "your-github-installation-id"
  provider_repository_id  = "your-repository-id"
  provider_branch         = "main"
  provider_root_directory = "apps/web"
}
```

## Deploy from local code {% #deploy-from-code %}

Use **`source_type = "code"`** to upload a `tar.gz` build artifact. `code_hash` lets Terraform detect changes to the artifact and trigger a fresh deployment on the next `apply`.

```hcl
resource "appwrite_site_deployment" "dashboard" {
  site_id          = appwrite_site.dashboard.id
  source_type      = "code"
  code_path        = "dist/dashboard.tar.gz"
  code_hash        = filesha256("dist/dashboard.tar.gz")
  install_command  = "npm install"
  build_command    = "npm run build"
  output_directory = ".next"
  activate         = true
}
```

## Deploy from a template repository {% #deploy-from-template %}

Use **`source_type = "template"`** to deploy from a Git repository (for example one of Appwrite's site templates). Pin `type` to `branch`, `tag`, or `commit` and set `reference` accordingly.

```hcl
resource "appwrite_site_deployment" "from_template" {
  site_id        = appwrite_site.dashboard.id
  source_type    = "template"
  repository     = "templates-for-sites"
  owner          = "appwrite"
  root_directory = "nextjs/starter"
  type           = "branch"
  reference      = "main"
  activate       = true
}
```

By default the resource waits for the build to finish before it returns; set `wait_for_ready = false` if you want `terraform apply` to return as soon as the deployment is queued.

# Data sources {% #data-sources %}

The **`appwrite_site`** data source reads an existing site by ID. Use it when the site was created outside Terraform or in another state, and you need to reference attributes such as `framework` or the active `deployment_id`.

```hcl
data "appwrite_site" "existing" {
  id = "dashboard"
}

output "site_framework" {
  value = data.appwrite_site.existing.framework
}
```

See the [Terraform Registry](https://registry.terraform.io/providers/appwrite/appwrite/latest/docs/data-sources/site) for the full attribute list.

# Related {% #related %}

- [Sites product docs](/docs/products/sites)
- [Configuration](/docs/tooling/terraform/provider)

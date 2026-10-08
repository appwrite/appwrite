---
layout: integration
title: Terraform provider for Appwrite
description: Manage Appwrite TablesDB, Storage, Auth, Functions, Sites, Messaging, webhooks, backups, and more with HashiCorp Terraform and the official appwrite/appwrite provider.
date: 2026-04-24
featured: false
isPartner: false
isNew: true
cover: /images/integrations/terraform/cover.avif
category: deployments
product:
    avatar: '/images/integrations/avatars/terraform.svg'
    vendor: Terraform
    description: 'Terraform is an infrastructure-as-code tool for building, changing, and versioning infrastructure safely and efficiently.'
platform:
    - 'Cloud'
    - 'Self-hosted'
images:
    - /images/integrations/terraform/cover.avif
---

[HashiCorp Terraform](https://www.terraform.io/) lets you describe infrastructure and cloud configuration in declarative files, then apply changes in a repeatable way. The official [**appwrite/appwrite**](https://registry.terraform.io/providers/appwrite/appwrite/latest) provider connects Terraform to your Appwrite project so you can manage resources through the same workflows you use for the rest of your stack.

# How does the integration work?

The provider talks to the Appwrite API using your endpoint and API key. You declare **resources** (for example TablesDB databases through rows, storage buckets and files, users and teams, functions and sites, messaging providers, topics, subscribers, webhooks, and backup policies) in HCL; Terraform plans and applies changes against Appwrite Cloud or a **Community Edition** instance.

That gives you **reviewable, versioned configuration**: you can codify how a project is structured, share it with your team, and align environments without re-clicking through the Console for every change.

# How to implement

1. Install Terraform on your machine ([install guide](https://developer.hashicorp.com/terraform/install)).
2. Add the provider to your Terraform configuration. See the [**Configuration**](/docs/tooling/terraform/provider) page for `required_providers`, endpoints, and environment variables.
3. Follow the [**Terraform provider**](/docs/tooling/terraform) docs for resource areas you need (for example [**Databases**](/docs/tooling/terraform/resources/databases), [**Storage**](/docs/tooling/terraform/resources/storage), [**Messaging**](/docs/tooling/terraform/resources/messaging), [**Auth**](/docs/tooling/terraform/resources/auth), [**Functions**](/docs/tooling/terraform/resources/functions), [**Sites**](/docs/tooling/terraform/resources/sites), [**Webhooks**](/docs/tooling/terraform/resources/webhooks), and [**Backups**](/docs/tooling/terraform/resources/backups)).
4. Use the [**Terraform Registry**](https://registry.terraform.io/providers/appwrite/appwrite/latest/docs) for generated schemas, arguments, and import syntax for each resource.

# Read more

- [Terraform provider documentation](/docs/tooling/terraform) on Appwrite
- [Provider on the Terraform Registry](https://registry.terraform.io/providers/appwrite/appwrite/latest)
- [Source repository](https://github.com/appwrite/terraform-provider-appwrite) on GitHub

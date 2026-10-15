---
layout: article
title: Storage
description: Manage Appwrite Storage buckets and files with the Terraform provider, including file limits, extensions, compression, and security options.
---

The `appwrite_storage_bucket` resource manages [Storage](/docs/products/storage) buckets in your Appwrite project: file size limits, allowed extensions, compression, image transformations, encryption, and optional antivirus. The `appwrite_storage_file` resource uploads and manages **files** inside a bucket from a local path on the machine running Terraform.

See the Terraform Registry for generated schemas: [storage_bucket](https://registry.terraform.io/providers/appwrite/appwrite/latest/docs/resources/storage_bucket) and [storage_file](https://registry.terraform.io/providers/appwrite/appwrite/latest/docs/resources/storage_file). The [provider repository](https://github.com/appwrite/terraform-provider-appwrite) contains source and examples.

# Resources {% #resources %}

| Resource | Purpose |
|----------|---------|
| `appwrite_storage_bucket` | Create and update a storage bucket |
| `appwrite_storage_file` | Upload and manage a file in a bucket (local `file_path`) |

# Example {% #example %}

```hcl
resource "appwrite_storage_bucket" "uploads" {
  name = "uploads"
}

resource "appwrite_storage_bucket" "images" {
  name                    = "images"
  maximum_file_size       = 10485760
  allowed_file_extensions = ["jpg", "png", "webp", "gif"]
  compression             = "gzip"
  transformations         = true
}

resource "appwrite_storage_bucket" "documents" {
  name          = "documents"
  file_security = true
  encryption    = true
  antivirus     = true
}

resource "appwrite_storage_file" "logo" {
  bucket_id = appwrite_storage_bucket.images.id
  name      = "logo.png"
  file_path = "assets/logo.png"
}

resource "appwrite_storage_file" "public_config" {
  bucket_id   = appwrite_storage_bucket.documents.id
  name        = "config.json"
  file_path   = "assets/config.json"
  permissions = ["read(\"any\")"]
}
```

Set `permissions` on a file to grant explicit read/write access (for example `read("any")` for public downloads, or `read("user:USER_ID")` for a single user). File-level permissions are only enforced on buckets with `file_security = true`; without it, bucket-level `permissions` govern every file.

Common optional arguments on buckets include `maximum_file_size`, `allowed_file_extensions`, `compression` (`none`, `gzip`, `zstd`), `transformations`, `file_security`, `encryption`, `antivirus`, `enabled`, and `permissions`. Read-only attributes expose `created_at` and `updated_at`.

Buckets and files support [Terraform import](https://developer.hashicorp.com/terraform/cli/commands/import) where the upstream resource documents an ID format (see the Registry for each resource).

# Data sources {% #data-sources %}

The **`appwrite_storage_bucket`** data source reads a bucket by ID instead of creating one. Use it when the bucket was created outside Terraform or lives in another state, and you need to reference attributes like `name`, `compression`, or `maximum_file_size`.

See the [Terraform Registry](https://registry.terraform.io/providers/appwrite/appwrite/latest/docs/data-sources/storage_bucket) for the full attribute list.

```hcl
data "appwrite_storage_bucket" "uploads" {
  id = "uploads"
}

resource "appwrite_storage_file" "logo" {
  bucket_id = data.appwrite_storage_bucket.uploads.id
  name      = "logo.png"
  file_path = "assets/logo.png"
}
```

# Related {% #related %}

- [Storage product docs](/docs/products/storage)
- [Configuration](/docs/tooling/terraform/provider)

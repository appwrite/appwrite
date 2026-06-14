---
layout: post
title: "Introducing the Appwrite Rust SDK"
description: Appwrite now has an official Rust SDK, bringing type-safe, async-first Appwrite integration to the Rust ecosystem.
date: 2026-04-08
cover: /images/blog/announcing-appwrite-rust-sdk/cover.avif
timeToRead: 4
author: chirag-aggarwal
category: announcement
featured: false
faqs:
  - question: "How do I install the Appwrite Rust SDK?"
    answer: "Add it with `cargo add appwrite`. You will typically also want `cargo add tokio -F full` for the async runtime and `cargo add serde_json` for working with JSON payloads. The SDK is published on [crates.io](https://crates.io/crates/appwrite)."
  - question: "Is the Rust SDK a server SDK or a client SDK?"
    answer: "It is a server SDK, meaning it is meant to be used with an API key from environments you control (backends, CLI tools, daemons). It is not intended to ship to end-user devices, since API keys grant elevated permissions on your Appwrite project."
  - question: "Which Appwrite services does the Rust SDK support?"
    answer: "Every server-side service: [TablesDB](/docs/products/databases), [Account](/docs/products/auth), Users, Teams, [Storage](/docs/products/storage), Tokens, [Functions](/docs/products/functions), [Messaging](/docs/products/messaging), [Sites](/docs/products/sites), Locale, and Avatars. It also includes utility modules for query building, ID generation, permissions, and atomic operators."
  - question: "Is the Rust SDK async?"
    answer: "Yes. All API methods are `async fn` and are designed to work with Tokio. The example in the documentation uses `#[tokio::main]`, but the SDK is compatible with any executor that implements the standard async ecosystem traits."
  - question: "Does the Rust SDK work with self-hosted Appwrite?"
    answer: "Yes. `Client::new().set_endpoint(\"...\")` accepts any Appwrite endpoint, so you can point the SDK at a self-hosted Appwrite instance or Appwrite Cloud. The same project ID and API key conventions apply."
  - question: "Why use Rust for a backend talking to Appwrite?"
    answer: "Rust offers memory safety without a garbage collector, zero-cost abstractions, and strong async support, which fit well for high-throughput services, CLIs, and infrastructure tooling. Before this SDK, you had to call the Appwrite REST API manually; the SDK gives you typed methods and idiomatic Rust patterns instead."
---

Appwrite now supports Rust as an official server SDK. The SDK provides async, type-safe access to all Appwrite server-side APIs and is available on [crates.io](https://crates.io/crates/appwrite).

# Why Rust

Rust is one of the fastest-growing languages in backend development. Memory safety without garbage collection, zero-cost abstractions, and built-in concurrency make it well-suited for backend services, CLI tools, and infrastructure. With the Rust SDK, developers no longer need to use raw HTTP calls to integrate Appwrite into Rust applications.

The SDK follows the same conventions as existing Appwrite server SDKs for Node.js, Python, and Go, with an idiomatic Rust API surface.

# What's included

The SDK supports every Appwrite server-side service:

| Service | What you can do |
|---------|----------------|
| **TablesDB** | Create databases, tables, columns, rows. Query with filters, sorting, and pagination. |
| **Account** | Manage sessions, OAuth, MFA, and user preferences. |
| **Users** | Manage users, targets, and labels. |
| **Teams** | Manage teams, memberships, and invites. |
| **Storage** | Create buckets, upload and download files. |
| **Tokens** | Create and manage file tokens for secure file access. |
| **Functions** | Create, deploy, and manage serverless functions. |
| **Messaging** | Send emails, SMS, and push notifications through providers like Twilio, Sendgrid, and more. |
| **Sites** | Create, deploy, and manage web applications. |
| **Locale** | Get user locale, list languages, currencies, countries, and continent information. |
| **Avatars** | Generate user initials, QR codes, country flags, browser icons, favicons, and webpage screenshots. |

The SDK also includes utility modules for building queries, generating IDs, constructing permissions, and working with operators for atomic database updates.

# Getting started

## Step 1: Set up Appwrite

Set up an Appwrite project by [creating an Appwrite Cloud account](https://cloud.appwrite.io) or [self-hosting Appwrite](/docs/advanced/self-hosting).

## Step 2: Install the Rust SDK

Add the SDK and its dependencies to your project:

```sh
cargo add appwrite
cargo add tokio -F full
cargo add serde_json
```

## Step 3: Start building

Initialize the client and make API calls:

```rust
use appwrite::Client;
use appwrite::services::tables_db::TablesDB;
use appwrite::id::ID;
use appwrite::query::Query;
use serde_json::json;

#[tokio::main]
async fn main() -> Result<(), Box<dyn std::error::Error>> {
    let client = Client::new()
        .set_endpoint("https://fra.cloud.appwrite.io/v1")
        .set_project("<PROJECT_ID>")
        .set_key("<API_KEY>");

    let tables_db = TablesDB::new(&client);

    // Create a database and table
    let db = tables_db.create(ID::unique(), "MyDB", None).await?;
    let table = tables_db.create_table(
        &db.id, ID::unique(), "Tasks",
        None, None, None, None, None,
    ).await?;

    // Create a row
    tables_db.create_row(
        &db.id, &table.id, ID::unique(),
        json!({"title": "Ship Rust SDK", "status": "done"}),
        None, None,
    ).await?;

    // Query rows
    let tasks = tables_db.list_rows(
        &db.id, &table.id,
        Some(vec![
            Query::equal("status", "done").to_string(),
            Query::order_desc("$createdAt").to_string(),
        ]),
        None, None, None,
    ).await?;

    println!("Found {} tasks", tasks.total);
    Ok(())
}
```

All service methods are async, return a typed `Result`, and use positional parameters with `Option` for optional fields. The SDK handles authentication headers, request serialization, and error parsing automatically.

## Step 4: Explore the documentation

Follow the [Rust quick start guide](/docs/quick-starts/rust) for a step-by-step walkthrough. You can also use the [AI quickstart prompt](/docs/tooling/ai/quickstart-prompts/rust) with tools like Cursor, Claude Code, or Windsurf to set up a project with AI assistance. Rust examples are available across all server SDK documentation pages.

# Resources

- [Rust quick start](/docs/quick-starts/rust)
- [SDK on crates.io](https://crates.io/crates/appwrite)
- [SDK repository on GitHub](https://github.com/appwrite/sdk-for-rust)
- [SDKs documentation](/docs/sdks)
- [Discord](https://appwrite.io/discord)

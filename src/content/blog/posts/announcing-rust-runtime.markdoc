---
layout: post
title: "Announcing the Rust runtime for Appwrite Functions"
description: Appwrite Functions now supports Rust as a first-class runtime, bringing memory-safe, compiled performance to your serverless workloads.
date: 2026-05-04
cover: /images/blog/announcing-rust-runtime/cover.avif
timeToRead: 6
author: chirag-aggarwal
category: announcement
featured: false
callToAction: true
faqs:
  - question: "Which Rust version does Appwrite Functions support?"
    answer: "Appwrite Functions supports Rust 1.83 as a first-class runtime. You can scaffold a new function using the [Appwrite CLI](/docs/tooling/command-line/installation) with `appwrite init function` and selecting the `rust-1.83` runtime, then deploy it like any other Appwrite Function."
  - question: "Why use Rust for serverless functions?"
    answer: "Rust compiles to native machine code, which delivers low latency, predictable throughput, and tight memory usage. Memory safety is enforced at compile time without a garbage collector, so there are no GC pauses to worry about during high-traffic workloads. This makes Rust a strong choice for hot-path business logic, image transforms, and signature verification."
  - question: "Does Appwrite provide a Rust SDK?"
    answer: "Yes. The Appwrite Rust SDK is published on [crates.io](https://crates.io/crates/appwrite) and provides async, type-safe access to every server API. Pair it with the dynamic API key Appwrite injects into each execution and you can call Appwrite from inside your function without managing credentials manually."
  - question: "How does memory safety work in Rust without garbage collection?"
    answer: "Rust uses ownership rules and a borrow checker enforced at compile time. The compiler tracks which part of the program owns each value and rejects code that could create dangling references or data races. This means no runtime GC overhead and entire classes of bugs are caught before the binary ships."
  - question: "What is Tokio in Rust?"
    answer: "Tokio is the most widely used async runtime in the Rust ecosystem. It provides a multi-threaded scheduler, async I/O primitives, and integrations with libraries for HTTP, databases, and timers. Most Rust serverless code uses Tokio under the hood to handle concurrent work without blocking the request."
  - question: "Can I call other Appwrite services from a Rust function?"
    answer: "Yes. Use the Appwrite Rust SDK along with the dynamic API key Appwrite passes into each execution to call databases, storage, messaging, or any other [Appwrite product](/docs/products/functions). The same pattern works on Appwrite Cloud and self-hosted deployments."
---

When you build a function that runs on every request, every millisecond and every megabyte matters, and cold starts and memory ceilings start to add up quickly. That is exactly the kind of workload Rust was made for.

Today, we are excited to announce that **Rust is now an officially supported runtime for Appwrite Functions**. You can write functions in Rust 1.83, deploy them through the Appwrite CLI or Console, and get the same context-driven request and response model you already know from every other runtime, now with the speed and safety guarantees of a compiled, memory-safe language.

# Why Rust on Appwrite Functions

Rust has steadily become one of the most loved languages in modern backend development, and for good reason:

- **Compiled performance:** Rust compiles to native machine code, which means low latency, predictable throughput, and tight memory usage. Once your function is built, it runs lean.
- **Memory safety without a garbage collector:** Ownership and borrow checking eliminate entire classes of runtime bugs at compile time, with no GC pauses to worry about.
- **Strong, expressive type system:** Catch mistakes at compile time, not in production. Errors, optionals, and lifetimes are part of the type system, not afterthoughts.
- **First-class async:** The Tokio ecosystem makes it straightforward to call APIs, connect to databases, or fan out work without blocking the request.
- **A growing ecosystem:** The crates you need, from web frameworks to serialization, are already on crates.io and battle-tested in production.

Bringing Rust to Appwrite Functions means you can write the most performance-sensitive parts of your backend, payment validation, signature verification, image transforms, hot-path business logic, with the same workflow you use for everything else.

# Writing your first Rust function

A Rust function on Appwrite is a regular Rust library crate that exports a single `main` entry point. Initialize one with the [Appwrite CLI](/docs/tooling/command-line/installation) by running `appwrite init function` and selecting the `rust-1.83` runtime.

The starter looks like this:

```rust
use openruntimes::{Context, Response};
use serde_json::json;

// This is your Appwrite function
// It's executed each time we get a request
pub fn main(context: Context) -> Response {
    // You can log messages to the console
    context.log("Hello, Logs!");

    // If something goes wrong, log an error
    context.error("Hello, Errors!");

    // The `context.req` object contains the request data
    if context.req.method == "GET" {
        // Send a response with the res object helpers
        // `context.res.text()` dispatches a string back to the client
        return context.res.text("Hello, World!", None, None);
    }

    // `context.res.json()` is a handy helper for sending JSON
    context.res.json(
        json!({
            "motto": "Build like a team of hundreds_",
            "learn": "https://appwrite.io/docs",
            "connect": "https://appwrite.io/discord",
            "getInspired": "https://builtwith.appwrite.io",
        }),
        None,
        None,
    )
}
```

The runtime gives you a `Context` with everything you need: `context.req` for the incoming request, `context.res` for building responses, and `context.log` and `context.error` for execution logs that surface in the Appwrite Console.

# Calling Appwrite from inside your function

The Rust runtime pairs naturally with the [Appwrite Rust SDK](https://crates.io/crates/appwrite), which gives you async, type-safe access to every server API. Combine it with the dynamic API key Appwrite injects into each execution, and you can read or mutate project data without ever managing credentials yourself.

```rust
use appwrite::id::ID;
use appwrite::services::tables_db::TablesDB;
use appwrite::Client;
use openruntimes::{Context, Response};
use serde_json::json;
use std::env;

pub fn main(context: Context) -> Response {
    // Set project and set API key
    let client = Client::new()
        .set_endpoint(env::var("APPWRITE_FUNCTION_API_ENDPOINT").unwrap_or_default())
        .set_project(env::var("APPWRITE_FUNCTION_PROJECT_ID").unwrap_or_default())
        .set_key(
            context
                .req
                .headers
                .get("x-appwrite-key")
                .cloned()
                .unwrap_or_default(),
        );

    let tables_db = TablesDB::new(&client);

    let runtime = tokio::runtime::Builder::new_current_thread()
        .enable_all()
        .build()
        .unwrap();

    let result = runtime.block_on(async move {
        tables_db
            .create_row(
                "<DATABASE_ID>",
                "<TABLE_ID>",
                ID::unique(),
                json!({}),
                None,
                None,
            )
            .await
    });

    match result {
        Ok(_) => context.res.text("Row created", None, None),
        Err(e) => {
            context.error(format!("Failed to create row: {}", e));
            context.res.text("Failed to create row", None, None)
        }
    }
}
```

The handler stays synchronous, which is what the runtime expects, and you reach for a Tokio current-thread runtime when you need to await an SDK call.

# Getting started

The Rust runtime is **available on Appwrite Cloud today**.

To create your first Rust function:

1. Open your project in the [Appwrite Console](https://cloud.appwrite.io).
2. Navigate to **Functions** and switch to the **Templates** tab.
3. Find the **Starter function** template and click **Create**.
4. In the **Runtime** field, select **Rust 1.83** and continue through the wizard.

The full reference, including the runtime contract, request and response helpers, environment variables, and end-to-end examples, lives in the [Functions documentation](/docs/products/functions/develop).

# More resources

- [Functions runtimes](/docs/products/functions/runtimes)
- [Develop Appwrite Functions](/docs/products/functions/develop)
- [Function templates](/docs/products/functions/templates)
- [Appwrite Rust SDK on crates.io](https://crates.io/crates/appwrite)
- [Join the Appwrite Community on Discord](https://appwrite.io/discord)

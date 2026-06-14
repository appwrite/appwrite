---
layout: post
title: "3 things you can build with the Rust runtime"
description: Explore three workloads that play to the Rust runtime's strengths, including image optimization, PDF generation, and HMAC-verified webhooks.
date: 2026-05-11
cover: /images/blog/3-things-you-can-build-with-rust-runtime/cover.avif
timeToRead: 10
author: atharva
category: product
featured: false
faqs:
  - question: "Which Rust version does the Appwrite runtime use?"
    answer: "Rust 1.83 with the 2021 edition. Edition 2024 (a Rust language edition, not the calendar year) was stabilized in Rust 1.85, so crates that declare edition = \"2024\" need to be pinned to an earlier version that still uses edition 2021."
  - question: "Can I use the Appwrite Rust SDK from inside a function?"
    answer: "Yes. The Rust SDK is async, so wrap your SDK calls in a Tokio current-thread runtime inside the synchronous handler signature the runtime expects."
  - question: "How do I deploy a Rust function?"
    answer: "Scaffold the function with the Appwrite CLI, write your handler in lib.rs, then run appwrite push functions. The runtime container builds a release binary on every deployment."
---
We recently [introduced Rust 1.83 as an officially supported runtime](/blog/post/announcing-rust-runtime) for Appwrite Functions on Appwrite Cloud. Rust shines when every millisecond of cold start, every megabyte of memory, and every byte over the wire matters, so the question becomes less "can you write this in Rust" and more "which workloads benefit the most from doing so".

# How Appwrite Functions and Rust complement each other

There are several reasons why Appwrite Functions and Rust make a strong pairing for the performance-sensitive parts of your backend:

## Compiled performance

Rust compiles directly to native machine code with zero-cost abstractions. There is no interpreter or virtual machine sitting in front of your handler, so cold starts stay tight, throughput stays predictable, and memory ceilings stay low. For workloads that re-encode media, parse large payloads, or compute cryptographic primitives on every request, that speed advantage compounds quickly.

## Open-source runtime

Like every other Appwrite Functions runtime, the Rust runtime is built and maintained by our team in the open at [open-runtimes](https://github.com/open-runtimes/open-runtimes/tree/main/runtimes/rust). You can read the build scripts, the request and response wire format, and the entrypoint contract for yourself, and contribute fixes and improvements upstream.

## Event-driven nature

Appwrite Functions can be triggered by HTTP requests, CRON schedules, or any [Appwrite event](/docs/products/functions/develop#events) such as user creation, row deletion, or file upload. The same Rust handler you write for an HTTP API can also run on a schedule or fan out from a project event, with no changes to the function body.

## Global environment variables

Aside from environment variables scoped to each function, Appwrite supports project-level variables that are shared across every function in the project. Combine that with the dynamic API key Appwrite injects into each execution and your Rust function can talk to the rest of your project without ever managing credentials yourself.

## Permissions system

Appwrite's permissions system for products like TablesDB and Storage extends to Functions too, so you can restrict who is allowed to invoke a function the same way you restrict access to a table or a bucket.

## Local development support

The [Appwrite CLI](/docs/tooling/command-line/installation) ships with `appwrite run functions`, which spins up the same Docker image used in production and runs your Rust function on `localhost`. Code changes trigger a rebuild and restart, so you can iterate on your handler without redeploying for every tweak.

## CI/CD with GitHub

Appwrite also offers [GitHub-based CI/CD](/docs/products/functions/deployments) for Functions. Push to your repository and Appwrite handles the build, deployment, and rollover for you, including for Rust functions where the build step compiles a release binary inside the runtime container.

# Building Rust functions

The Rust runtime is available on Appwrite Cloud. [Sign up on Appwrite Cloud](https://cloud.appwrite.io) and scaffold a function with the [Appwrite CLI](/docs/tooling/command-line/installation):

```bash
appwrite init function
```

Pick the `rust-1.83` runtime and the CLI sets up `Cargo.toml` and `lib.rs` with a starter handler.

{% info title="Grab the code" %}
The full source for all three examples lives in the [appwrite-community/rust-function-examples](https://github.com/appwrite-community/rust-function-examples) repo. Clone it, swap in your project ID, and deploy with `appwrite push functions`.
{% /info %}

With that out of the way, here are three things worth building on it.

## Example 1: Image optimizer

The first example accepts a base64-encoded image and returns the resized output as AVIF, WebP, or JPEG. Image transcoding is CPU-bound work where Rust's native code generation pays off, and it sidesteps the large native dependencies that JavaScript image libraries pull in.

Add the following dependencies to your `Cargo.toml`. We pin `image` and `avif-serialize` to the latest versions that compile with the runtime's Rust 1.83 toolchain:

```toml
[package]
name = "handler"
version = "0.1.0"
edition = "2021"
rust-version = "1.83"

[lib]
name = "handler"
path = "lib.rs"

[dependencies]
openruntimes = { version = "1.0", package = "openruntimes-types-for-rust" }
serde_json = "1.0"
image = { version = "=0.25.6", default-features = false, features = ["jpeg", "png"] }
ravif = "0.11"
avif-serialize = "=0.8.6"
webp = "0.3"
rgb = "0.8"
base64 = "0.22"
```

Then implement the handler in `lib.rs`. It decodes the request body, resizes to the requested width while preserving aspect ratio, encodes to the chosen format, and returns the result alongside size metadata so the caller can see the compression ratio:

```rust
use base64::Engine;
use image::{ImageReader, ImageFormat};
use openruntimes::{Context, Response};
use ravif::{Encoder, Img};
use serde_json::json;
use std::collections::HashMap;
use std::io::Cursor;

pub fn main(mut context: Context) -> Response {
    if context.req.method != "POST" {
        return context.res.text(
            "POST a base64-encoded image in the `image` field, set `format` to webp, avif, or jpeg, and `width` for the target width.",
            None,
            None,
        );
    }

    let body = context.req.body_json().unwrap_or(serde_json::Value::Null);

    let image_b64 = match body.get("image").and_then(|v| v.as_str()) {
        Some(s) => s,
        None => return error_response(&context, 400, "Missing `image` field"),
    };

    let format = body
        .get("format")
        .and_then(|v| v.as_str())
        .unwrap_or("webp")
        .to_lowercase();

    let width = body
        .get("width")
        .and_then(|v| v.as_u64())
        .unwrap_or(800) as u32;

    if width == 0 {
        return error_response(&context, 400, "`width` must be greater than 0");
    }

    let bytes = match base64::engine::general_purpose::STANDARD.decode(image_b64) {
        Ok(b) => b,
        Err(e) => return error_response(&context, 400, &format!("Invalid base64: {}", e)),
    };

    let original_size = bytes.len();

    let img = match ImageReader::new(Cursor::new(&bytes))
        .with_guessed_format()
        .map(|r| r.decode())
    {
        Ok(Ok(img)) => img,
        Ok(Err(e)) => return error_response(&context, 400, &format!("Decode failed: {}", e)),
        Err(e) => return error_response(&context, 400, &format!("Format detection failed: {}", e)),
    };

    let (orig_w, orig_h) = (img.width(), img.height());
    let height = (orig_h as f32 * (width as f32 / orig_w as f32)).round() as u32;
    let resized = img.resize_exact(width, height, image::imageops::FilterType::Lanczos3);

    let encoded = match format.as_str() {
        "webp" => encode_webp(&resized),
        "jpeg" | "jpg" => encode_with_image(&resized, ImageFormat::Jpeg),
        "avif" => encode_avif(&resized),
        other => return error_response(&context, 400, &format!("Unsupported format `{}`", other)),
    };

    let encoded = match encoded {
        Ok(b) => b,
        Err(e) => return error_response(&context, 500, &e),
    };

    context.log(format!(
        "Optimized {}x{} ({} bytes) -> {}x{} {} ({} bytes)",
        orig_w, orig_h, original_size, width, height, format, encoded.len()
    ));

    let mut headers = HashMap::new();
    headers.insert("content-type".to_string(), "application/json".to_string());
    context.res.json(
        json!({
            "format": format,
            "width": width,
            "height": height,
            "originalBytes": original_size,
            "optimizedBytes": encoded.len(),
            "image": base64::engine::general_purpose::STANDARD.encode(&encoded),
        }),
        None,
        Some(headers),
    )
}

fn encode_with_image(img: &image::DynamicImage, format: ImageFormat) -> Result<Vec<u8>, String> {
    let mut buf = Cursor::new(Vec::new());
    img.write_to(&mut buf, format)
        .map_err(|e| format!("Encode {:?} failed: {}", format, e))?;
    Ok(buf.into_inner())
}

fn encode_webp(img: &image::DynamicImage) -> Result<Vec<u8>, String> {
    let encoder = webp::Encoder::from_image(img)
        .map_err(|e| format!("WebP encoder init failed: {}", e))?;
    Ok(encoder.encode(75.0).to_vec())
}

fn encode_avif(img: &image::DynamicImage) -> Result<Vec<u8>, String> {
    let rgba = img.to_rgba8();
    let (w, h) = rgba.dimensions();
    let pixels: Vec<rgb::RGBA<u8>> = rgba
        .pixels()
        .map(|p| rgb::RGBA::new(p[0], p[1], p[2], p[3]))
        .collect();
    let img = Img::new(pixels.as_slice(), w as usize, h as usize);
    Encoder::new()
        .with_quality(70.0)
        .with_speed(8)
        .encode_rgba(img)
        .map(|res| res.avif_file)
        .map_err(|e| format!("AVIF encode failed: {}", e))
}

fn error_response(context: &Context, code: u16, message: &str) -> Response {
    context.error(message);
    context.res.json(
        json!({ "ok": false, "error": message }),
        Some(code),
        None,
    )
}
```

Deploy with `appwrite push functions`. AVIF encoding is the slowest of the three formats, so if you expect AVIF traffic, consider bumping the runtime specification in **Settings -> Resource limits**.

## Example 2: PDF invoice generator

The second example takes a JSON invoice and returns a base64-encoded PDF. Generating PDFs server-side with Rust avoids the headless-Chromium tax that other ecosystems pay for the same task, which keeps memory usage low and execution times short.

The dependencies are minimal. `printpdf` does the heavy lifting, and the two pinned packages keep the transitive dependency graph compatible with the runtime's Rust 1.83 toolchain:

```toml
[package]
name = "handler"
version = "0.1.0"
edition = "2021"
rust-version = "1.83"

[lib]
name = "handler"
path = "lib.rs"

[dependencies]
openruntimes = { version = "1.0", package = "openruntimes-types-for-rust" }
serde = { version = "1.0", features = ["derive"] }
serde_json = "1.0"
printpdf = "0.7"
base64 = "0.22"
time-core = "=0.1.7"
deranged = "=0.5.5"
```

The handler does three things:

- Decodes the JSON body into a strongly typed `Invoice` struct, so malformed payloads fail with a 400 before any PDF work runs.
- Lays out the document with `printpdf`: header, customer block, item table, total.
- Returns the rendered bytes alongside the invoice number and size.

```rust
use base64::Engine;
use openruntimes::{Context, Response};
use printpdf::{BuiltinFont, Mm, PdfDocument};
use serde::Deserialize;
use serde_json::json;
use std::collections::HashMap;
use std::io::BufWriter;

#[derive(Deserialize)]
struct LineItem {
    description: String,
    quantity: f64,
    unit_price: f64,
}

#[derive(Deserialize)]
struct Invoice {
    invoice_number: String,
    customer_name: String,
    customer_email: String,
    items: Vec<LineItem>,
}

pub fn main(mut context: Context) -> Response {
    if context.req.method != "POST" {
        return context.res.text(
            "POST a JSON invoice with `invoice_number`, `customer_name`, `customer_email`, and `items` (each with `description`, `quantity`, `unit_price`).",
            None,
            None,
        );
    }

    let body = context.req.body_json().unwrap_or(serde_json::Value::Null);
    let invoice: Invoice = match serde_json::from_value(body) {
        Ok(inv) => inv,
        Err(e) => return error_response(&context, 400, &format!("Invalid invoice payload: {}", e)),
    };

    let pdf_bytes = match render_invoice(&invoice) {
        Ok(b) => b,
        Err(e) => return error_response(&context, 500, &e),
    };

    context.log(format!(
        "Rendered invoice {} ({} items, {} bytes)",
        invoice.invoice_number,
        invoice.items.len(),
        pdf_bytes.len()
    ));

    let mut headers = HashMap::new();
    headers.insert("content-type".to_string(), "application/json".to_string());
    context.res.json(
        json!({
            "invoice_number": invoice.invoice_number,
            "bytes": pdf_bytes.len(),
            "pdf": base64::engine::general_purpose::STANDARD.encode(&pdf_bytes),
        }),
        None,
        Some(headers),
    )
}

fn render_invoice(inv: &Invoice) -> Result<Vec<u8>, String> {
    let (doc, page1, layer1) =
        PdfDocument::new("Invoice", Mm(210.0), Mm(297.0), "layer 1");
    let layer = doc.get_page(page1).get_layer(layer1);
    let font = doc
        .add_builtin_font(BuiltinFont::HelveticaBold)
        .map_err(|e| format!("Font load failed: {}", e))?;
    let regular = doc
        .add_builtin_font(BuiltinFont::Helvetica)
        .map_err(|e| format!("Font load failed: {}", e))?;

    layer.use_text(
        format!("Invoice #{}", inv.invoice_number),
        24.0,
        Mm(20.0),
        Mm(270.0),
        &font,
    );
    layer.use_text(
        format!("Bill to: {}", inv.customer_name),
        12.0,
        Mm(20.0),
        Mm(255.0),
        &regular,
    );
    layer.use_text(&inv.customer_email, 12.0, Mm(20.0), Mm(248.0), &regular);

    layer.use_text("Description", 12.0, Mm(20.0), Mm(225.0), &font);
    layer.use_text("Qty", 12.0, Mm(120.0), Mm(225.0), &font);
    layer.use_text("Unit", 12.0, Mm(140.0), Mm(225.0), &font);
    layer.use_text("Total", 12.0, Mm(170.0), Mm(225.0), &font);

    let mut y = 215.0;
    let mut grand_total = 0.0;
    for item in &inv.items {
        let line_total = item.quantity * item.unit_price;
        grand_total += line_total;
        layer.use_text(&item.description, 11.0, Mm(20.0), Mm(y), &regular);
        layer.use_text(format!("{}", item.quantity), 11.0, Mm(120.0), Mm(y), &regular);
        layer.use_text(format!("${:.2}", item.unit_price), 11.0, Mm(140.0), Mm(y), &regular);
        layer.use_text(format!("${:.2}", line_total), 11.0, Mm(170.0), Mm(y), &regular);
        y -= 8.0;
    }

    layer.use_text(
        format!("Total: ${:.2}", grand_total),
        14.0,
        Mm(140.0),
        Mm(y - 10.0),
        &font,
    );

    let mut buf = BufWriter::new(Vec::new());
    doc.save(&mut buf)
        .map_err(|e| format!("PDF save failed: {}", e))?;
    buf.into_inner()
        .map_err(|e| format!("Buffer flush failed: {}", e))
}

fn error_response(context: &Context, code: u16, message: &str) -> Response {
    context.error(message);
    context.res.json(
        json!({ "ok": false, "error": message }),
        Some(code),
        None,
    )
}
```

A typical three-line invoice renders in under two kilobytes and a few milliseconds, leaving plenty of headroom for fonts, logos, and tax tables.

## Example 3: Webhook receiver with HMAC verification

The third example consumes Appwrite's own [webhooks](/docs/advanced/platform/webhooks) and verifies the `X-Appwrite-Webhook-Signature` header in constant time before doing anything with the payload. Signature verification is exactly the kind of code where Rust's type system and `subtle`'s constant-time primitives keep you out of trouble that languages without those guarantees make easy to fall into.

Start with the dependencies. The dependency graph here is tiny, which keeps cold starts fast for an endpoint that runs on every authentication event:

```toml
[package]
name = "handler"
version = "0.1.0"
edition = "2021"
rust-version = "1.83"

[lib]
name = "handler"
path = "lib.rs"

[dependencies]
openruntimes = { version = "1.0", package = "openruntimes-types-for-rust" }
serde_json = "1.0"
hmac = "0.12"
sha1 = "0.10"
base64 = "0.22"
subtle = "2.5"
```

The handler does three things:

- Reads the `x-appwrite-webhook-signature` header from the request.
- Recomputes an HMAC-SHA1 over the webhook URL concatenated with the raw payload, using the shared signature key.
- Compares the two with `subtle::ConstantTimeEq` so attackers cannot probe for the secret with timing oracles.

```rust
use base64::Engine;
use hmac::{Hmac, Mac};
use openruntimes::{Context, Response};
use serde_json::json;
use sha1::Sha1;
use std::env;
use subtle::ConstantTimeEq;

type HmacSha1 = Hmac<Sha1>;

pub fn main(context: Context) -> Response {
    if context.req.method != "POST" {
        return context.res.text(
            "POST an Appwrite webhook here. The function verifies `x-appwrite-webhook-signature` and logs the event.",
            None,
            None,
        );
    }

    let signature_key = match env::var("WEBHOOK_SIGNATURE_KEY") {
        Ok(k) if !k.is_empty() => k,
        _ => return error(&context, 500, "WEBHOOK_SIGNATURE_KEY env variable is not set"),
    };

    let webhook_url = match env::var("WEBHOOK_URL") {
        Ok(k) if !k.is_empty() => k,
        _ => return error(&context, 500, "WEBHOOK_URL env variable is not set"),
    };

    let signature = match context.req.headers.get("x-appwrite-webhook-signature") {
        Some(s) => s.clone(),
        None => return error(&context, 401, "Missing webhook signature header"),
    };

    let payload = context.req.body_text().to_string();

    if !verify_signature(&webhook_url, &payload, &signature_key, &signature) {
        return error(&context, 401, "Invalid webhook signature");
    }

    let event = context
        .req
        .headers
        .get("x-appwrite-webhook-events")
        .cloned()
        .unwrap_or_default();

    // Webhook payloads can include PII like emails or IP addresses, so log only
    // the event name and payload size by default. Add field-level logging as needed.
    context.log(format!(
        "Verified Appwrite webhook: {} ({} bytes)",
        event,
        payload.len()
    ));

    context.res.json(json!({ "ok": true, "event": event }), None, None)
}

fn verify_signature(url: &str, payload: &str, signature_key: &str, signature: &str) -> bool {
    let mut mac = match HmacSha1::new_from_slice(signature_key.as_bytes()) {
        Ok(m) => m,
        Err(_) => return false,
    };
    mac.update(url.as_bytes());
    mac.update(payload.as_bytes());
    let generated = base64::engine::general_purpose::STANDARD.encode(mac.finalize().into_bytes());
    generated.as_bytes().ct_eq(signature.as_bytes()).into()
}

fn error(context: &Context, code: u16, message: &str) -> Response {
    context.error(message);
    context.res.json(json!({ "ok": false, "error": message }), Some(code), None)
}
```

To wire this up:

1. Deploy the function with `appwrite push functions`.
2. Set `WEBHOOK_SIGNATURE_KEY` and `WEBHOOK_URL` as function variables. `WEBHOOK_URL` is the public domain you give Appwrite when registering the webhook.
3. Open your project's **Settings -> Webhooks** tab and create a new webhook.
4. Pick the events you care about. `users.*.create` is a good starting point.
5. Set the webhook's signature key to the same value you stored in `WEBHOOK_SIGNATURE_KEY`.

![Create webhook screen filled in with Rust auth events](/images/blog/3-things-you-can-build-with-rust-runtime/webhook-create.avif)

Once the webhook is saved, every matching event will hit your function. Valid signatures get a 200 with the event name echoed back, invalid signatures get a 401, and your function never runs the rest of its logic on a payload it cannot trust.

# More resources

From here, the [Functions documentation](/docs/products/functions/develop) walks through the full request and response API, [the runtimes page](/docs/products/functions/runtimes) covers Rust 1.83 alongside every other supported language, and the [Appwrite Rust SDK](https://crates.io/crates/appwrite) is the next thing to pick up when you want your function to talk back to the rest of your project.

If you enjoyed reading this blog, here are some more resources to help you get started with Appwrite Functions and Rust:

- [Appwrite Functions docs](/docs/products/functions)
- [Announcing the Rust runtime](/blog/post/announcing-rust-runtime)
- [Rust documentation](https://doc.rust-lang.org/)
- [Appwrite Discord](https://appwrite.io/discord)

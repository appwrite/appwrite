---
layout: post
title: "Announcing the Webhooks API: Manage webhooks programmatically with Server SDKs"
description: Webhooks are no longer console-only. Create, update, and delete webhooks using the Appwrite Server SDKs and API keys with the new webhooks.read and webhooks.write scopes.
date: 2026-04-22
cover: /images/blog/announcing-webhooks-api/cover.avif
timeToRead: 4
author: matej-baco
category: announcement
featured: false
callToAction: true
faqs:
  - question: "What is the Appwrite Webhooks API?"
    answer: "The Webhooks API is a programmable interface for managing Appwrite webhooks through Server SDKs and API keys. Instead of clicking through the Console, you can create, list, update, and delete webhooks from code, the same way you already manage databases, [Functions](/docs/products/functions), and storage buckets."
  - question: "Which API key scopes do I need for the Webhooks API?"
    answer: "You need webhooks.read for listing and retrieving webhooks, and webhooks.write for creating, updating, and deleting them. Create or update an API key under Overview > Integration > API keys with the scopes you need."
  - question: "Why store the webhook secret immediately on creation?"
    answer: "The secret field is only returned in the response of the create call, never again. Save it to your secret manager or environment variables right away so you can verify webhook signatures when events are delivered to your endpoint."
  - question: "When should I use the Webhooks API instead of the Console?"
    answer: "Use it whenever webhook configuration is part of a workflow that should be reproducible. Common cases include CI/CD pipelines that register webhooks during environment setup, multi-tenant platforms that provision per-customer webhooks, and migration scripts that replicate webhooks across environments."
  - question: "How do webhooks differ from Appwrite Functions?"
    answer: "Webhooks deliver event payloads to an HTTP endpoint you control, so the logic runs in your own infrastructure. [Functions](/docs/products/functions) run inside Appwrite and can be triggered by the same events, which is useful when you do not want to host a separate server."
---

Webhooks have always been a core part of how developers integrate Appwrite into their workflows. Subscribe to an event, receive an HTTP POST, and let your server handle the rest. Until now, managing those webhooks meant logging into the Appwrite Console and configuring them by hand.

Today, we are announcing the **Webhooks API**, a fully programmable interface for managing webhooks through Server SDKs and API keys.

# Why this matters

If you have ever needed to set up webhooks across multiple projects, automate webhook provisioning as part of a deployment pipeline, or manage webhook lifecycles from code, you know the friction of doing it through a UI.

The Webhooks API removes that friction entirely. Webhooks become just another resource you can create, update, and delete programmatically, the same way you already manage databases, functions, and storage buckets.

This is especially valuable for:

- **CI/CD pipelines** that need to register webhooks as part of environment setup
- **Multi-tenant platforms** that provision webhooks per customer or workspace
- **Migration and seeding scripts** that replicate webhook configurations across environments
- **Admin dashboards** that let non-technical team members manage webhooks without Console access

# How it works

The Webhooks API introduces two new [API key scopes](/docs/advanced/platform/api-keys#scopes):

- **`webhooks.read`** for listing and retrieving webhooks
- **`webhooks.write`** for creating, updating, and deleting webhooks

Once your API key has the appropriate scopes, you can manage webhooks through any Appwrite Server SDK. To get started:

1. Navigate to **Overview** > **Integration** > **API keys** and create or update an API key with the `webhooks.read` and `webhooks.write` scopes.
2. Initialize a Server SDK with your API key.
3. Use the `Webhooks` service to manage your webhooks from code.

## Create a webhook

The response includes a `secret` field containing the webhook's signing key. This is the only time the `secret` is returned, so store it securely right away.

{% multicode %}
```server-nodejs
import { Client, Webhooks, ID } from 'node-appwrite';

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>')
    .setKey('<YOUR_API_KEY>');

const webhooks = new Webhooks(client);

const result = await webhooks.create({
    webhookId: ID.unique(),
    url: 'https://example.com/webhook',
    name: 'My Webhook',
    events: ['users.*.create', 'users.*.update'],
    tls: true,
    secret: '<SECRET>' // optional
});

console.log(result.secret); // store it now, not returned again
```
```server-deno
import { Client, Webhooks, ID } from "npm:node-appwrite";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>')
    .setKey('<YOUR_API_KEY>');

const webhooks = new Webhooks(client);

const result = await webhooks.create({
    webhookId: ID.unique(),
    url: 'https://example.com/webhook',
    name: 'My Webhook',
    events: ['users.*.create', 'users.*.update'],
    tls: true,
    secret: '<SECRET>' // optional
});

console.log(result.secret); // store it now, not returned again
```
```server-php
<?php

use Appwrite\Client;
use Appwrite\ID;
use Appwrite\Services\Webhooks;

$client = new Client();

$client
    ->setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    ->setProject('<PROJECT_ID>')
    ->setKey('<YOUR_API_KEY>');

$webhooks = new Webhooks($client);

$result = $webhooks->create(
    webhookId: ID::unique(),
    url: 'https://example.com/webhook',
    name: 'My Webhook',
    events: ['users.*.create', 'users.*.update'],
    tls: true,
    secret: '<SECRET>' // optional
);

echo $result->secret; // store it now, not returned again
```
```server-python
from appwrite.client import Client
from appwrite.id import ID
from appwrite.services.webhooks import Webhooks

client = Client()
client.set_endpoint('https://<REGION>.cloud.appwrite.io/v1')
client.set_project('<PROJECT_ID>')
client.set_key('<YOUR_API_KEY>')

webhooks = Webhooks(client)

result = webhooks.create(
    webhook_id=ID.unique(),
    url='https://example.com/webhook',
    name='My Webhook',
    events=['users.*.create', 'users.*.update'],
    tls=True,
    secret='<SECRET>' # optional
)

print(result.secret) # store it now, not returned again
```
```server-ruby
require 'appwrite'

include Appwrite

client = Client.new
    .set_endpoint('https://<REGION>.cloud.appwrite.io/v1')
    .set_project('<PROJECT_ID>')
    .set_key('<YOUR_API_KEY>')

webhooks = Webhooks.new(client)

response = webhooks.create(
    webhook_id: ID.unique(),
    url: 'https://example.com/webhook',
    name: 'My Webhook',
    events: ['users.*.create', 'users.*.update'],
    tls: true,
    secret: '<SECRET>' # optional
)

puts response.secret # store it now, not returned again
```
```server-dotnet
using Appwrite;
using Appwrite.Services;
using Appwrite.Models;

Client client = new Client()
    .SetEndPoint("https://<REGION>.cloud.appwrite.io/v1")
    .SetProject("<PROJECT_ID>")
    .SetKey("<YOUR_API_KEY>");

Webhooks webhooks = new Webhooks(client);

var result = await webhooks.Create(
    webhookId: ID.Unique(),
    url: "https://example.com/webhook",
    name: "My Webhook",
    events: new List<string> {"users.*.create", "users.*.update"},
    tls: true,
    secret: "<SECRET>" // optional
);

Console.WriteLine(result.Secret); // store it now, not returned again
```
```server-dart
import 'package:dart_appwrite/dart_appwrite.dart';

Client client = Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>')
    .setKey('<YOUR_API_KEY>');

Webhooks webhooks = Webhooks(client);

final result = await webhooks.create(
    webhookId: ID.unique(),
    url: 'https://example.com/webhook',
    name: 'My Webhook',
    events: ['users.*.create', 'users.*.update'],
    tls: true,
    secret: '<SECRET>', // optional
);

print(result.secret); // store it now, not returned again
```
```server-kotlin
import io.appwrite.Client
import io.appwrite.ID
import io.appwrite.services.Webhooks

val client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1")
    .setProject("<PROJECT_ID>")
    .setKey("<YOUR_API_KEY>")

val webhooks = Webhooks(client)

val result = webhooks.create(
    webhookId = ID.unique(),
    url = "https://example.com/webhook",
    name = "My Webhook",
    events = listOf("users.*.create", "users.*.update"),
    tls = true,
    secret = "<SECRET>" // optional
)

println(result.secret) // store it now, not returned again
```
```server-java
import io.appwrite.Client;
import io.appwrite.ID;
import io.appwrite.coroutines.CoroutineCallback;
import io.appwrite.services.Webhooks;

Client client = new Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1")
    .setProject("<PROJECT_ID>")
    .setKey("<YOUR_API_KEY>");

Webhooks webhooks = new Webhooks(client);

webhooks.create(
    ID.unique(),                                     // webhookId
    "https://example.com/webhook",                   // url
    "My Webhook",                                    // name
    List.of("users.*.create", "users.*.update"),     // events
    true,                                            // enabled
    true,                                            // tls
    null,                                            // authUsername
    null,                                            // authPassword
    "<SECRET>",                                      // secret (optional)
    new CoroutineCallback<>((result, error) -> {
        if (error != null) {
            error.printStackTrace();
            return;
        }

        System.out.println(result.getSecret()); // store it now, not returned again
    })
);
```
```server-swift
import Appwrite

let client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1")
    .setProject("<PROJECT_ID>")
    .setKey("<YOUR_API_KEY>")

let webhooks = Webhooks(client)

let result = try await webhooks.create(
    webhookId: ID.unique(),
    url: "https://example.com/webhook",
    name: "My Webhook",
    events: ["users.*.create", "users.*.update"],
    tls: true,
    secret: "<SECRET>" // optional
)

print(result.secret) // store it now, not returned again
```
```server-go
package main

import (
    "fmt"
    "github.com/appwrite/sdk-for-go/appwrite"
    "github.com/appwrite/sdk-for-go/id"
)

func main() {
    client := appwrite.NewClient(
        appwrite.WithEndpoint("https://<REGION>.cloud.appwrite.io/v1"),
        appwrite.WithProject("<PROJECT_ID>"),
        appwrite.WithKey("<YOUR_API_KEY>"),
    )

    webhooks := appwrite.NewWebhooks(client)
    result, err := webhooks.Create(
        id.Unique(),
        "https://example.com/webhook",
        "My Webhook",
        []string{"users.*.create", "users.*.update"},
        appwrite.WithCreateSecret("<SECRET>"), // optional
    )

    if err != nil {
        panic(err)
    }

    fmt.Println(result.Secret) // store it now, not returned again
}
```
```server-rust
use appwrite::Client;
use appwrite::id::ID;
use appwrite::services::Webhooks;

#[tokio::main]
async fn main() -> Result<(), Box<dyn std::error::Error>> {
    let client = Client::new()
        .set_endpoint("https://<REGION>.cloud.appwrite.io/v1")
        .set_project("<PROJECT_ID>")
        .set_key("<YOUR_API_KEY>");

    let webhooks = Webhooks::new(&client);

    let result = webhooks.create(
        ID::unique(),                                  // webhook_id
        "https://example.com/webhook",                 // url
        "My Webhook",                                  // name
        vec!["users.*.create", "users.*.update"],      // events
        None,                                          // enabled
        Some(true),                                    // tls
        None,                                          // auth_username
        None,                                          // auth_password
        Some("<SECRET>"),                              // secret (optional)
    ).await?;

    println!("{}", result.secret); // store it now, not returned again
    Ok(())
}
```
{% /multicode %}

## Update a webhook

{% multicode %}
```server-nodejs
const result = await webhooks.update({
    webhookId: '<WEBHOOK_ID>',
    name: 'Updated Webhook',
    url: 'https://example.com/webhook-v2',
    events: ['users.*.create', 'users.*.update', 'users.*.delete'],
    tls: true
});
```
```server-deno
const result = await webhooks.update({
    webhookId: '<WEBHOOK_ID>',
    name: 'Updated Webhook',
    url: 'https://example.com/webhook-v2',
    events: ['users.*.create', 'users.*.update', 'users.*.delete'],
    tls: true
});
```
```server-php
$result = $webhooks->update(
    webhookId: '<WEBHOOK_ID>',
    name: 'Updated Webhook',
    url: 'https://example.com/webhook-v2',
    events: ['users.*.create', 'users.*.update', 'users.*.delete'],
    tls: true
);
```
```server-python
result = webhooks.update(
    webhook_id='<WEBHOOK_ID>',
    name='Updated Webhook',
    url='https://example.com/webhook-v2',
    events=['users.*.create', 'users.*.update', 'users.*.delete'],
    tls=True
)
```
```server-ruby
response = webhooks.update(
    webhook_id: '<WEBHOOK_ID>',
    name: 'Updated Webhook',
    url: 'https://example.com/webhook-v2',
    events: ['users.*.create', 'users.*.update', 'users.*.delete'],
    tls: true
)
```
```server-dotnet
var result = await webhooks.Update(
    webhookId: "<WEBHOOK_ID>",
    name: "Updated Webhook",
    url: "https://example.com/webhook-v2",
    events: new List<string> {"users.*.create", "users.*.update", "users.*.delete"},
    tls: true
);
```
```server-dart
final result = await webhooks.update(
    webhookId: '<WEBHOOK_ID>',
    name: 'Updated Webhook',
    url: 'https://example.com/webhook-v2',
    events: ['users.*.create', 'users.*.update', 'users.*.delete'],
    tls: true,
);
```
```server-kotlin
val result = webhooks.update(
    webhookId = "<WEBHOOK_ID>",
    name = "Updated Webhook",
    url = "https://example.com/webhook-v2",
    events = listOf("users.*.create", "users.*.update", "users.*.delete"),
    tls = true
)
```
```server-java
webhooks.update(
    "<WEBHOOK_ID>",                                  // webhookId
    "Updated Webhook",                               // name
    "https://example.com/webhook-v2",                // url
    List.of("users.*.create", "users.*.update", "users.*.delete"), // events
    true,                                            // enabled
    true,                                            // tls
    null,                                            // authUsername
    null,                                            // authPassword
    new CoroutineCallback<>((result, error) -> {
        if (error != null) {
            error.printStackTrace();
            return;
        }
        System.out.println(result);
    })
);
```
```server-swift
let result = try await webhooks.update(
    webhookId: "<WEBHOOK_ID>",
    name: "Updated Webhook",
    url: "https://example.com/webhook-v2",
    events: ["users.*.create", "users.*.update", "users.*.delete"],
    tls: true
)
```
```server-go
result, err := webhooks.Update(
    "<WEBHOOK_ID>",
    "Updated Webhook",
    "https://example.com/webhook-v2",
    []string{"users.*.create", "users.*.update", "users.*.delete"},
)
```
```server-rust
let result = webhooks.update(
    "<WEBHOOK_ID>",                                // webhook_id
    "Updated Webhook",                             // name
    "https://example.com/webhook-v2",              // url
    vec!["users.*.create", "users.*.update", "users.*.delete"], // events
    None,                                          // enabled
    Some(true),                                    // tls
    None,                                          // auth_username
    None,                                          // auth_password
).await?;
```
{% /multicode %}

## Delete a webhook

{% multicode %}
```server-nodejs
await webhooks.delete({
    webhookId: '<WEBHOOK_ID>'
});
```
```server-deno
await webhooks.delete({
    webhookId: '<WEBHOOK_ID>'
});
```
```server-php
$webhooks->delete(
    webhookId: '<WEBHOOK_ID>'
);
```
```server-python
webhooks.delete(
    webhook_id='<WEBHOOK_ID>'
)
```
```server-ruby
webhooks.delete(
    webhook_id: '<WEBHOOK_ID>'
)
```
```server-dotnet
await webhooks.Delete(
    webhookId: "<WEBHOOK_ID>"
);
```
```server-dart
await webhooks.delete(
    webhookId: '<WEBHOOK_ID>',
);
```
```server-kotlin
webhooks.delete(
    webhookId = "<WEBHOOK_ID>"
)
```
```server-java
webhooks.delete(
    "<WEBHOOK_ID>", // webhookId
    new CoroutineCallback<>((result, error) -> {
        if (error != null) {
            error.printStackTrace();
            return;
        }
        System.out.println(result);
    })
);
```
```server-swift
try await webhooks.delete(
    webhookId: "<WEBHOOK_ID>"
)
```
```server-go
_, err := webhooks.Delete(
    "<WEBHOOK_ID>",
)
```
```server-rust
webhooks.delete(
    "<WEBHOOK_ID>",
).await?;
```
{% /multicode %}

## Rotate signing key

By default, calling `updateSecret` generates a fresh random signing key. You can also pass your own `secret` (8-256 characters), which is useful for zero-downtime key rotation.

{% multicode %}
```server-nodejs
const result = await webhooks.updateSecret({
    webhookId: '<WEBHOOK_ID>',
    secret: '<SECRET>' // optional
});
```
```server-deno
const result = await webhooks.updateSecret({
    webhookId: '<WEBHOOK_ID>',
    secret: '<SECRET>' // optional
});
```
```server-php
$result = $webhooks->updateSecret(
    webhookId: '<WEBHOOK_ID>',
    secret: '<SECRET>' // optional
);
```
```server-python
result = webhooks.update_secret(
    webhook_id='<WEBHOOK_ID>',
    secret='<SECRET>' # optional
)
```
```server-ruby
response = webhooks.update_secret(
    webhook_id: '<WEBHOOK_ID>',
    secret: '<SECRET>' # optional
)
```
```server-dotnet
var result = await webhooks.UpdateSecret(
    webhookId: "<WEBHOOK_ID>",
    secret: "<SECRET>" // optional
);
```
```server-dart
final result = await webhooks.updateSecret(
    webhookId: '<WEBHOOK_ID>',
    secret: '<SECRET>', // optional
);
```
```server-kotlin
val result = webhooks.updateSecret(
    webhookId = "<WEBHOOK_ID>",
    secret = "<SECRET>" // optional
)
```
```server-java
webhooks.updateSecret(
    "<WEBHOOK_ID>", // webhookId
    "<SECRET>", // secret (optional)
    new CoroutineCallback<>((result, error) -> {
        if (error != null) {
            error.printStackTrace();
            return;
        }
        System.out.println(result);
    })
);
```
```server-swift
let result = try await webhooks.updateSecret(
    webhookId: "<WEBHOOK_ID>",
    secret: "<SECRET>" // optional
)
```
```server-go
result, err := webhooks.UpdateSecret(
    "<WEBHOOK_ID>",
    appwrite.WithUpdateSecretSecret("<SECRET>"), // optional
)
```
```server-rust
let result = webhooks.update_secret(
    "<WEBHOOK_ID>", // webhook_id
    Some("<SECRET>") // optional
).await?;
```
{% /multicode %}

The API supports all the same configuration options available in the Console, including event selection, SSL/TLS certificate verification, and HTTP basic authentication.

{% info title="Update your SDK" %}
The Webhooks API requires the latest version of your Appwrite Server SDK. Make sure to update before using the new `Webhooks` service.
{% /info %}

# Get started

The Webhooks API is available on **Appwrite Cloud** today. Full documentation, including examples for all supported SDKs, is available on the [webhooks documentation page](/docs/advanced/platform/webhooks).

# Resources

- [Webhooks documentation](/docs/advanced/platform/webhooks)
- [API keys and scopes](/docs/advanced/platform/api-keys)
- [Webhook events reference](/docs/advanced/platform/events)
- [Appwrite Webhooks: triggering events the right way](/blog/post/appwrite-webhooks)

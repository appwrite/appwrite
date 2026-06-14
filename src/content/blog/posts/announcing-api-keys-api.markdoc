---
layout: post
title: "Announcing the Keys API: Create and manage API keys with Server SDKs"
description: API keys can now be created, updated, and deleted programmatically using Appwrite Server SDKs. Automate key provisioning for CI/CD, multi-tenant setups, and team onboarding workflows.
date: 2026-04-16
cover: /images/blog/improve-devex-dev-keys/cover.avif
timeToRead: 4
author: matej-baco
category: announcement
featured: false
callToAction: true
faqs:
  - question: "What is the Appwrite Keys API?"
    answer: "It is a set of endpoints on the `Project` service that lets you create, list, update, and delete API keys programmatically from any Appwrite server SDK. Previously, API keys could only be managed through the Appwrite Console, which made automation and multi-tenant key provisioning awkward."
  - question: "What scopes do I need to manage API keys?"
    answer: "Two new scopes: `keys.read` for listing and retrieving keys, and `keys.write` for creating, updating, and deleting keys. These are sensitive: a key with `keys.write` can mint another key with any scope, effectively giving full access to the project. Only assign them in trusted environments and never use them client-side."
  - question: "What workflows does the Keys API unlock?"
    answer: "Common patterns include CI/CD pipelines provisioning keys per deployment environment, multi-tenant platforms creating isolated keys per customer, automated team onboarding, and zero-downtime key rotation (create the new key, swap credentials, delete the old one). All of this is now scriptable."
  - question: "Can I set an expiration date on a key?"
    answer: "Yes. Pass an ISO 8601 timestamp in the `expire` field when calling `project.createKey`. Once that time passes, the key stops working automatically. Setting expirations on every key is a good default for short-lived credentials in CI and customer-facing tenants."
  - question: "Where can I find the list of available scopes?"
    answer: "The full scope list is in the [API key scopes documentation](/docs/advanced/platform/api-keys#scopes). The server SDKs also ship a `Scopes` enum so you can reference them by constant (e.g., `Scopes.DatabasesRead`) instead of typing strings."
  - question: "How is the Keys API different from session-based auth?"
    answer: "Sessions identify end users of your app via [Appwrite Auth](/docs/products/auth), while API keys identify trusted backend services that act on behalf of the project. The Keys API itself uses an existing API key with `keys.write` scope to create new keys, so it is strictly a server-to-server flow."
---

Managing API keys has always required navigating to the Appwrite Console, selecting scopes, and manually creating each key. For a single project, that works. For teams managing multiple environments, onboarding new services, or provisioning keys as part of automated pipelines, it becomes a bottleneck.

Today, we are announcing the **Keys API**, allowing you to create, update, and delete API keys programmatically through the Appwrite server SDKs.

This is part of a wider effort to make everything in Appwrite accessible through the API. Our goal is to ensure that any action you can perform in the Appwrite Console can also be done programmatically, giving you full control over your projects through code.

# Why this matters

API keys are the foundation of server-side authentication in Appwrite. Every server SDK call, every CLI operation, and every backend integration depends on them. Being able to manage keys from code opens up workflows that were previously manual:

- **CI/CD pipelines** that provision scoped keys for each deployment environment
- **Multi-tenant platforms** that create isolated keys per customer with only the scopes they need
- **Team onboarding** where new services get their own keys automatically
- **Key rotation workflows** that create a new key, update credentials, and retire the old one without downtime

# How it works

The Keys API introduces two new [API key scopes](/docs/advanced/platform/api-keys#scopes):

- **`keys.read`** for listing and retrieving API keys
- **`keys.write`** for creating, updating, and deleting API keys

{% info title="Sensitive scopes" %}
The `keys.read` and `keys.write` scopes are very sensitive. An API key with `keys.write` can create new keys with any scope, effectively granting full access to your project. Only assign these scopes to keys used in trusted, secure environments, and never expose them in client-side applications.
{% /info %}

The methods live on the `Project` service, alongside existing project-level operations like environment variables.

## Create an API key

{% multicode %}
```server-nodejs
import { Client, Project, ID, Scopes } from 'node-appwrite';

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>')
    .setKey('<YOUR_API_KEY>');

const project = new Project(client);

const result = await project.createKey({
    keyId: ID.unique(),
    name: 'My API Key',
    scopes: [Scopes.DatabasesRead, Scopes.DatabasesWrite],
    expire: '2026-12-31T23:59:59.000+00:00'
});
```
```server-deno
import { Client, Project, ID, Scopes } from "npm:node-appwrite";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>')
    .setKey('<YOUR_API_KEY>');

const project = new Project(client);

const result = await project.createKey({
    keyId: ID.unique(),
    name: 'My API Key',
    scopes: [Scopes.DatabasesRead, Scopes.DatabasesWrite],
    expire: '2026-12-31T23:59:59.000+00:00'
});
```
```server-php
<?php

use Appwrite\Client;
use Appwrite\ID;
use Appwrite\Services\Project;

$client = new Client();

$client
    ->setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    ->setProject('<PROJECT_ID>')
    ->setKey('<YOUR_API_KEY>');

$project = new Project($client);

$result = $project->createKey(
    keyId: ID::unique(),
    name: 'My API Key',
    scopes: ['databases.read', 'databases.write'],
    expire: '2026-12-31T23:59:59.000+00:00'
);
```
```server-python
from appwrite.client import Client
from appwrite.id import ID
from appwrite.services.project import Project
from appwrite.enums.scopes import Scopes

client = Client()
client.set_endpoint('https://<REGION>.cloud.appwrite.io/v1')
client.set_project('<PROJECT_ID>')
client.set_key('<YOUR_API_KEY>')

project = Project(client)

result = project.create_key(
    key_id=ID.unique(),
    name='My API Key',
    scopes=[Scopes.DATABASES_READ, Scopes.DATABASES_WRITE],
    expire='2026-12-31T23:59:59.000+00:00'
)
```
```server-ruby
require 'appwrite'

include Appwrite

client = Client.new
    .set_endpoint('https://<REGION>.cloud.appwrite.io/v1')
    .set_project('<PROJECT_ID>')
    .set_key('<YOUR_API_KEY>')

project = Project.new(client)

response = project.create_key(
    key_id: ID.unique(),
    name: 'My API Key',
    scopes: ['databases.read', 'databases.write'],
    expire: '2026-12-31T23:59:59.000+00:00'
)
```
```server-dotnet
using Appwrite;
using Appwrite.Enums;
using Appwrite.Services;
using Appwrite.Models;

Client client = new Client()
    .SetEndPoint("https://<REGION>.cloud.appwrite.io/v1")
    .SetProject("<PROJECT_ID>")
    .SetKey("<YOUR_API_KEY>");

Project project = new Project(client);

var result = await project.CreateKey(
    keyId: ID.Unique(),
    name: "My API Key",
    scopes: new List<Scopes> {Scopes.DatabasesRead, Scopes.DatabasesWrite},
    expire: "2026-12-31T23:59:59.000+00:00"
);
```
```server-dart
import 'package:dart_appwrite/dart_appwrite.dart';
import 'package:dart_appwrite/enums.dart' as enums;

Client client = Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>')
    .setKey('<YOUR_API_KEY>');

Project project = Project(client);

final result = await project.createKey(
    keyId: ID.unique(),
    name: 'My API Key',
    scopes: [enums.Scopes.databasesRead, enums.Scopes.databasesWrite],
    expire: '2026-12-31T23:59:59.000+00:00',
);
```
```server-kotlin
import io.appwrite.Client
import io.appwrite.ID
import io.appwrite.enums.Scopes
import io.appwrite.services.Project

val client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1")
    .setProject("<PROJECT_ID>")
    .setKey("<YOUR_API_KEY>")

val project = Project(client)

val result = project.createKey(
    keyId = ID.unique(),
    name = "My API Key",
    scopes = listOf(Scopes.DATABASES_READ, Scopes.DATABASES_WRITE),
    expire = "2026-12-31T23:59:59.000+00:00"
)
```
```server-java
import io.appwrite.Client;
import io.appwrite.ID;
import io.appwrite.enums.Scopes;
import io.appwrite.coroutines.CoroutineCallback;
import io.appwrite.services.Project;

Client client = new Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1")
    .setProject("<PROJECT_ID>")
    .setKey("<YOUR_API_KEY>");

Project project = new Project(client);

project.createKey(
    ID.unique(),                                     // keyId
    "My API Key",                                    // name
    List.of(Scopes.DATABASES_READ, Scopes.DATABASES_WRITE), // scopes
    "2026-12-31T23:59:59.000+00:00",                // expire
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
import Appwrite
import AppwriteEnums

let client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1")
    .setProject("<PROJECT_ID>")
    .setKey("<YOUR_API_KEY>")

let project = Project(client)

let result = try await project.createKey(
    keyId: ID.unique(),
    name: "My API Key",
    scopes: [Scopes.databasesRead, Scopes.databasesWrite],
    expire: "2026-12-31T23:59:59.000+00:00"
)
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

    project := appwrite.NewProject(client)
    result, err := project.CreateKey(
        id.Unique(),
        "My API Key",
        []string{"databases.read", "databases.write"},
        project.WithCreateKeyExpire("2026-12-31T23:59:59.000+00:00"),
    )

    if err != nil {
        panic(err)
    }

    fmt.Println(result)
}
```
```server-rust
use appwrite::Client;
use appwrite::id::ID;
use appwrite::enums::Scopes;
use appwrite::services::project::Project;

#[tokio::main]
async fn main() -> Result<(), Box<dyn std::error::Error>> {
    let client = Client::new()
        .set_endpoint("https://<REGION>.cloud.appwrite.io/v1")
        .set_project("<PROJECT_ID>")
        .set_key("<YOUR_API_KEY>");

    let project = Project::new(&client);

    let result = project.create_key(
        ID::unique(),                                  // key_id
        "My API Key",                                  // name
        vec![Scopes::DatabasesRead, Scopes::DatabasesWrite], // scopes
        Some("2026-12-31T23:59:59.000+00:00"),         // expire
    ).await?;

    println!("{:?}", result);
    Ok(())
}
```
{% /multicode %}

Each key is created with a specific set of scopes and an optional expiration date. When no expiration is set, the key remains valid indefinitely.

## Update an API key

{% multicode %}
```server-nodejs
const result = await project.updateKey({
    keyId: '<KEY_ID>',
    name: 'Updated Key',
    scopes: [Scopes.DatabasesRead, Scopes.DatabasesWrite, Scopes.UsersRead],
    expire: '2027-06-30T23:59:59.000+00:00'
});
```
```server-deno
const result = await project.updateKey({
    keyId: '<KEY_ID>',
    name: 'Updated Key',
    scopes: [Scopes.DatabasesRead, Scopes.DatabasesWrite, Scopes.UsersRead],
    expire: '2027-06-30T23:59:59.000+00:00'
});
```
```server-php
$result = $project->updateKey(
    keyId: '<KEY_ID>',
    name: 'Updated Key',
    scopes: ['databases.read', 'databases.write', 'users.read'],
    expire: '2027-06-30T23:59:59.000+00:00'
);
```
```server-python
result = project.update_key(
    key_id='<KEY_ID>',
    name='Updated Key',
    scopes=[Scopes.DATABASES_READ, Scopes.DATABASES_WRITE, Scopes.USERS_READ],
    expire='2027-06-30T23:59:59.000+00:00'
)
```
```server-ruby
response = project.update_key(
    key_id: '<KEY_ID>',
    name: 'Updated Key',
    scopes: ['databases.read', 'databases.write', 'users.read'],
    expire: '2027-06-30T23:59:59.000+00:00'
)
```
```server-dotnet
var result = await project.UpdateKey(
    keyId: "<KEY_ID>",
    name: "Updated Key",
    scopes: new List<Scopes> {Scopes.DatabasesRead, Scopes.DatabasesWrite, Scopes.UsersRead},
    expire: "2027-06-30T23:59:59.000+00:00"
);
```
```server-dart
final result = await project.updateKey(
    keyId: '<KEY_ID>',
    name: 'Updated Key',
    scopes: [enums.Scopes.databasesRead, enums.Scopes.databasesWrite, enums.Scopes.usersRead],
    expire: '2027-06-30T23:59:59.000+00:00',
);
```
```server-kotlin
val result = project.updateKey(
    keyId = "<KEY_ID>",
    name = "Updated Key",
    scopes = listOf(Scopes.DATABASES_READ, Scopes.DATABASES_WRITE, Scopes.USERS_READ),
    expire = "2027-06-30T23:59:59.000+00:00"
)
```
```server-java
project.updateKey(
    "<KEY_ID>",                                      // keyId
    "Updated Key",                                   // name
    List.of(Scopes.DATABASES_READ, Scopes.DATABASES_WRITE, Scopes.USERS_READ), // scopes
    "2027-06-30T23:59:59.000+00:00",                // expire
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
let result = try await project.updateKey(
    keyId: "<KEY_ID>",
    name: "Updated Key",
    scopes: [Scopes.databasesRead, Scopes.databasesWrite, Scopes.usersRead],
    expire: "2027-06-30T23:59:59.000+00:00"
)
```
```server-go
result, err := project.UpdateKey(
    "<KEY_ID>",
    "Updated Key",
    []string{"databases.read", "databases.write", "users.read"},
    project.WithUpdateKeyExpire("2027-06-30T23:59:59.000+00:00"),
)
```
```server-rust
let result = project.update_key(
    "<KEY_ID>",                                    // key_id
    "Updated Key",                                 // name
    vec![Scopes::DatabasesRead, Scopes::DatabasesWrite, Scopes::UsersRead], // scopes
    Some("2027-06-30T23:59:59.000+00:00"),         // expire
).await?;
```
{% /multicode %}

Use this to adjust scopes as requirements change, or to extend or shorten a key's expiration window.

## Delete an API key

{% multicode %}
```server-nodejs
await project.deleteKey({
    keyId: '<KEY_ID>'
});
```
```server-deno
await project.deleteKey({
    keyId: '<KEY_ID>'
});
```
```server-php
$project->deleteKey(
    keyId: '<KEY_ID>'
);
```
```server-python
project.delete_key(
    key_id='<KEY_ID>'
)
```
```server-ruby
project.delete_key(
    key_id: '<KEY_ID>'
)
```
```server-dotnet
await project.DeleteKey(
    keyId: "<KEY_ID>"
);
```
```server-dart
await project.deleteKey(
    keyId: '<KEY_ID>',
);
```
```server-kotlin
project.deleteKey(
    keyId = "<KEY_ID>"
)
```
```server-java
project.deleteKey(
    "<KEY_ID>",                                      // keyId
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
try await project.deleteKey(
    keyId: "<KEY_ID>"
)
```
```server-go
_, err := project.DeleteKey(
    "<KEY_ID>",
)
```
```server-rust
project.delete_key(
    "<KEY_ID>",
).await?;
```
{% /multicode %}

Once deleted, the key immediately stops authenticating API calls.

# Bootstrap requirement

To use the Keys API, you need an existing API key with the `keys.read` and `keys.write` scopes. This initial key must be created through the Appwrite Console. Once you have it, all subsequent key management can be done programmatically.

# Full SDK support

The Keys API is available across all Appwrite Server SDKs. Complete code examples for every supported language are available in the [API keys documentation](/docs/advanced/platform/api-keys#manage-api-keys-with-a-server-sdk).

# Get started

The Keys API is available on **Appwrite Cloud** today.

1. Navigate to **Overview** > **Integration** > **API keys** and create an API key with the `keys.read` and `keys.write` scopes.
2. Initialize a Server SDK with your API key.
3. Use the `Project` service to manage keys from code.

Full documentation is available on the [API keys documentation page](/docs/advanced/platform/api-keys).

# Resources

- [API keys documentation](/docs/advanced/platform/api-keys)
- [API key scopes reference](/docs/advanced/platform/api-keys#scopes)
- [How to leverage dynamic API keys for better security](/blog/post/how-to-leverage-dynamic-api-keys-for-better-security)

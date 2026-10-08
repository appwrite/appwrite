---
layout: article
title: Issue and rotate API keys
description: Manage the full life of an API key with the Project API, from issuing a scoped credential to auditing, rotating, and revoking it.
---

Every connection your platform makes into a customer's project rides on an API key, and a key is a credential like any other in production. Left alone, a long-lived key with broad scopes is exactly the thing a security review flags: too much access, no expiry, no record of when it was last rotated. The Project API lets you run a key's whole life from your backend so it never becomes that liability:

- Issue it scoped to one integration, with an expiry.
- Audit the keys on the project before each rotation cycle.
- Replace its secret on a schedule, with no downtime.
- Revoke it the moment it is no longer needed.

This guide follows one key from the day you stand up an integration against a customer's project, through the routine audit and rotation that keeps it fresh, to the day you retire it.

{% info title="Required scopes" %}
The API key used for these calls needs the `keys.read` and `keys.write` scopes.
{% /info %}

# Issue a key scoped to the integration {% #issue-key %}

When you connect a new integration to a customer's project, give it a key that can reach only the parts of the API that integration touches, and put an expiry on it from the start. Set up the client once, then issue the key. A sync job that reads and writes tables needs the `tables.read` and `tables.write` scopes and nothing more, so that is all its key gets.

{% multicode %}
```server-nodejs
import { Client, Project, ID, ProjectKeyScopes } from 'node-appwrite';

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>')
    .setKey('<YOUR_API_KEY>');

const project = new Project(client);

const result = await project.createKey({
    keyId: ID.unique(),
    name: 'Tenant integration',
    scopes: [ProjectKeyScopes.TablesRead, ProjectKeyScopes.TablesWrite],
    expire: '2026-12-31T23:59:59.000+00:00'
});
```
```server-deno
import { Client, Project, ID, ProjectKeyScopes } from "npm:node-appwrite";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>')
    .setKey('<YOUR_API_KEY>');

const project = new Project(client);

const result = await project.createKey({
    keyId: ID.unique(),
    name: 'Tenant integration',
    scopes: [ProjectKeyScopes.TablesRead, ProjectKeyScopes.TablesWrite],
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
    name: 'Tenant integration',
    scopes: ['tables.read', 'tables.write'],
    expire: '2026-12-31T23:59:59.000+00:00'
);
```
```server-python
from appwrite.client import Client
from appwrite.id import ID
from appwrite.services.project import Project
from appwrite.enums import ProjectKeyScopes

client = Client()
client.set_endpoint('https://<REGION>.cloud.appwrite.io/v1')
client.set_project('<PROJECT_ID>')
client.set_key('<YOUR_API_KEY>')

project = Project(client)

result = project.create_key(
    key_id=ID.unique(),
    name='Tenant integration',
    scopes=[ProjectKeyScopes.TABLES_READ, ProjectKeyScopes.TABLES_WRITE],
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
    name: 'Tenant integration',
    scopes: ['tables.read', 'tables.write'],
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
    name: "Tenant integration",
    scopes: new List<ProjectKeyScopes> {ProjectKeyScopes.TablesRead, ProjectKeyScopes.TablesWrite},
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
    name: 'Tenant integration',
    scopes: [enums.ProjectKeyScopes.tablesRead, enums.ProjectKeyScopes.tablesWrite],
    expire: '2026-12-31T23:59:59.000+00:00',
);
```
```server-kotlin
import io.appwrite.Client
import io.appwrite.ID
import io.appwrite.enums.ProjectKeyScopes
import io.appwrite.services.Project

val client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1")
    .setProject("<PROJECT_ID>")
    .setKey("<YOUR_API_KEY>")

val project = Project(client)

val result = project.createKey(
    keyId = ID.unique(),
    name = "Tenant integration",
    scopes = listOf(ProjectKeyScopes.TABLES_READ, ProjectKeyScopes.TABLES_WRITE),
    expire = "2026-12-31T23:59:59.000+00:00"
)
```
```server-java
import io.appwrite.Client;
import io.appwrite.ID;
import io.appwrite.enums.ProjectKeyScopes;
import io.appwrite.coroutines.CoroutineCallback;
import io.appwrite.services.Project;

Client client = new Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1")
    .setProject("<PROJECT_ID>")
    .setKey("<YOUR_API_KEY>");

Project project = new Project(client);

project.createKey(
    ID.unique(),                                     // keyId
    "Tenant integration",                                    // name
    List.of(ProjectKeyScopes.TABLES_READ, ProjectKeyScopes.TABLES_WRITE), // scopes
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
    name: "Tenant integration",
    scopes: [ProjectKeyScopes.tablesRead, ProjectKeyScopes.tablesWrite],
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
        "Tenant integration",
        []string{"tables.read", "tables.write"},
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
use appwrite::enums::ProjectKeyScopes;
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
        "Tenant integration",                                  // name
        vec![ProjectKeyScopes::TablesRead, ProjectKeyScopes::TablesWrite], // scopes
        Some("2026-12-31T23:59:59.000+00:00"),         // expire
    ).await?;

    println!("{:?}", result);
    Ok(())
}
```
```bash
appwrite project create-key \
    --key-id 'unique()' \
    --name "Tenant integration" \
    --scopes tables.read tables.write
```
```http
POST /v1/project/keys HTTP/1.1
Content-Type: application/json
X-Appwrite-Project: <PROJECT_ID>
X-Appwrite-Key: <YOUR_API_KEY>

{
  "keyId": "unique()",
  "name": "Tenant integration",
  "scopes": ["tables.read", "tables.write"],
  "expire": "2026-12-31T23:59:59.000+00:00"
}
```
{% /multicode %}

The secret is returned once, on creation, and never again. Write it straight to your secrets manager, because there is no call that reads it back.

# Audit the project's keys {% #audit-keys %}

Rotation begins with knowing what is out there. When a rotation window comes due, list the keys on the project to see them all and when each one expires.

{% multicode %}
```server-nodejs
const result = await project.listKeys();
```
```server-deno
const result = await project.listKeys();
```
```server-php
$result = $project->listKeys();
```
```server-python
result = project.list_keys()
```
```server-ruby
response = project.list_keys()
```
```server-dotnet
var result = await project.ListKeys();
```
```server-dart
final result = await project.listKeys();
```
```server-kotlin
val result = project.listKeys()
```
```server-java
project.listKeys(
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
let result = try await project.listKeys()
```
```server-go
result, err := project.ListKeys()

if err != nil {
    panic(err)
}

fmt.Println(result)
```
```server-rust
let result = project.list_keys(
    None,  // queries
    None,  // total
).await?;

println!("{:?}", result);
```
```bash
appwrite project list-keys
```
```http
GET /v1/project/keys HTTP/1.1
X-Appwrite-Project: <PROJECT_ID>
X-Appwrite-Key: <YOUR_API_KEY>
```
{% /multicode %}

For any key that is close to expiry, read it back to confirm exactly what it can do before you decide how to handle it.

{% multicode %}
```server-nodejs
const result = await project.getKey({
    keyId: '<KEY_ID>'
});
```
```server-deno
const result = await project.getKey({
    keyId: '<KEY_ID>'
});
```
```server-php
$result = $project->getKey(
    keyId: '<KEY_ID>'
);
```
```server-python
result = project.get_key(
    key_id='<KEY_ID>'
)
```
```server-ruby
response = project.get_key(
    key_id: '<KEY_ID>'
)
```
```server-dotnet
var result = await project.GetKey(
    keyId: "<KEY_ID>"
);
```
```server-dart
final result = await project.getKey(
    keyId: '<KEY_ID>',
);
```
```server-kotlin
val result = project.getKey(
    keyId = "<KEY_ID>"
)
```
```server-java
project.getKey(
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
let result = try await project.getKey(
    keyId: "<KEY_ID>"
)
```
```server-go
result, err := project.GetKey(
    "<KEY_ID>",
)

if err != nil {
    panic(err)
}

fmt.Println(result)
```
```server-rust
let result = project.get_key(
    "<KEY_ID>",
).await?;

println!("{:?}", result);
```
```bash
appwrite project get-key \
    --key-id '<KEY_ID>'
```
```http
GET /v1/project/keys/<KEY_ID> HTTP/1.1
X-Appwrite-Project: <PROJECT_ID>
X-Appwrite-Key: <YOUR_API_KEY>
```
{% /multicode %}

# Update a key's scopes or expiry {% #adjust-key %}

Sometimes a key just needs a smaller scope set or a later expiry, not a new secret. Updating a key changes its name, scopes, and expiry while keeping the same secret, so reach for it when you are adjusting access for an integration that has not changed credentials.

{% multicode %}
```server-nodejs
const result = await project.updateKey({
    keyId: '<KEY_ID>',
    name: 'Updated Key',
    scopes: [ProjectKeyScopes.TablesRead, ProjectKeyScopes.TablesWrite, ProjectKeyScopes.UsersRead],
    expire: '2027-06-30T23:59:59.000+00:00'
});
```
```server-deno
const result = await project.updateKey({
    keyId: '<KEY_ID>',
    name: 'Updated Key',
    scopes: [ProjectKeyScopes.TablesRead, ProjectKeyScopes.TablesWrite, ProjectKeyScopes.UsersRead],
    expire: '2027-06-30T23:59:59.000+00:00'
});
```
```server-php
$result = $project->updateKey(
    keyId: '<KEY_ID>',
    name: 'Updated Key',
    scopes: ['tables.read', 'tables.write', 'users.read'],
    expire: '2027-06-30T23:59:59.000+00:00'
);
```
```server-python
result = project.update_key(
    key_id='<KEY_ID>',
    name='Updated Key',
    scopes=[ProjectKeyScopes.TABLES_READ, ProjectKeyScopes.TABLES_WRITE, ProjectKeyScopes.USERS_READ],
    expire='2027-06-30T23:59:59.000+00:00'
)
```
```server-ruby
response = project.update_key(
    key_id: '<KEY_ID>',
    name: 'Updated Key',
    scopes: ['tables.read', 'tables.write', 'users.read'],
    expire: '2027-06-30T23:59:59.000+00:00'
)
```
```server-dotnet
var result = await project.UpdateKey(
    keyId: "<KEY_ID>",
    name: "Updated Key",
    scopes: new List<ProjectKeyScopes> {ProjectKeyScopes.TablesRead, ProjectKeyScopes.TablesWrite, ProjectKeyScopes.UsersRead},
    expire: "2027-06-30T23:59:59.000+00:00"
);
```
```server-dart
final result = await project.updateKey(
    keyId: '<KEY_ID>',
    name: 'Updated Key',
    scopes: [enums.ProjectKeyScopes.tablesRead, enums.ProjectKeyScopes.tablesWrite, enums.ProjectKeyScopes.usersRead],
    expire: '2027-06-30T23:59:59.000+00:00',
);
```
```server-kotlin
val result = project.updateKey(
    keyId = "<KEY_ID>",
    name = "Updated Key",
    scopes = listOf(ProjectKeyScopes.TABLES_READ, ProjectKeyScopes.TABLES_WRITE, ProjectKeyScopes.USERS_READ),
    expire = "2027-06-30T23:59:59.000+00:00"
)
```
```server-java
project.updateKey(
    "<KEY_ID>",                                      // keyId
    "Updated Key",                                   // name
    List.of(ProjectKeyScopes.TABLES_READ, ProjectKeyScopes.TABLES_WRITE, ProjectKeyScopes.USERS_READ), // scopes
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
    scopes: [ProjectKeyScopes.tablesRead, ProjectKeyScopes.tablesWrite, ProjectKeyScopes.usersRead],
    expire: "2027-06-30T23:59:59.000+00:00"
)
```
```server-go
result, err := project.UpdateKey(
    "<KEY_ID>",
    "Updated Key",
    []string{"tables.read", "tables.write", "users.read"},
    project.WithUpdateKeyExpire("2027-06-30T23:59:59.000+00:00"),
)

if err != nil {
    panic(err)
}

fmt.Println(result)
```
```server-rust
let result = project.update_key(
    "<KEY_ID>",                                    // key_id
    "Updated Key",                                 // name
    vec![ProjectKeyScopes::TablesRead, ProjectKeyScopes::TablesWrite, ProjectKeyScopes::UsersRead], // scopes
    Some("2027-06-30T23:59:59.000+00:00"),         // expire
).await?;

println!("{:?}", result);
```
```bash
appwrite project update-key \
    --key-id '<KEY_ID>' \
    --name "Updated Key" \
    --scopes tables.read tables.write users.read
```
```http
PUT /v1/project/keys/<KEY_ID> HTTP/1.1
Content-Type: application/json
X-Appwrite-Project: <PROJECT_ID>
X-Appwrite-Key: <YOUR_API_KEY>

{
  "name": "Updated Key",
  "scopes": ["tables.read", "tables.write", "users.read"],
  "expire": "2027-06-30T23:59:59.000+00:00"
}
```
{% /multicode %}

# Rotate a key's secret {% #roll-secret %}

Rotating the secret itself, the part a security policy usually requires on a fixed cadence, means retiring one key and standing up another without a gap in service:

1. Issue a fresh key with the same scopes using the create call above.
2. Point the integration at the new secret.
3. Confirm its traffic has moved over.
4. Delete the old key so the retired secret stops working.

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

if err != nil {
    panic(err)
}

fmt.Println("API key deleted")
```
```server-rust
project.delete_key(
    "<KEY_ID>",
).await?;
```
```bash
appwrite project delete-key \
    --key-id '<KEY_ID>'
```
```http
DELETE /v1/project/keys/<KEY_ID> HTTP/1.1
X-Appwrite-Project: <PROJECT_ID>
X-Appwrite-Key: <YOUR_API_KEY>
```
{% /multicode %}

The delete takes effect immediately, so only run it once you have confirmed nothing is still authenticating with the old secret. The same call revokes a key that has leaked, without waiting for its expiry.

# Issue a short-lived ephemeral key {% #ephemeral-access %}

Not every job deserves a long-lived key. A support engineer running a one-off migration, or a script that needs an hour of access, can take an ephemeral key instead. It carries its scopes and a lifetime of up to an hour, then expires on its own with nothing for you to revoke afterward.

{% multicode %}
```server-nodejs
const result = await project.createEphemeralKey({
    scopes: [ProjectKeyScopes.TablesRead, ProjectKeyScopes.TablesWrite],
    duration: 600
});
```
```server-deno
const result = await project.createEphemeralKey({
    scopes: [ProjectKeyScopes.TablesRead, ProjectKeyScopes.TablesWrite],
    duration: 600
});
```
```server-php
$result = $project->createEphemeralKey(
    scopes: ['tables.read', 'tables.write'],
    duration: 600
);
```
```server-python
result = project.create_ephemeral_key(
    scopes=[ProjectKeyScopes.TABLES_READ, ProjectKeyScopes.TABLES_WRITE],
    duration=600
)
```
```server-ruby
response = project.create_ephemeral_key(
    scopes: ['tables.read', 'tables.write'],
    duration: 600
)
```
```server-dotnet
var result = await project.CreateEphemeralKey(
    scopes: new List<ProjectKeyScopes> {ProjectKeyScopes.TablesRead, ProjectKeyScopes.TablesWrite},
    duration: 600
);
```
```server-dart
final result = await project.createEphemeralKey(
    scopes: [enums.ProjectKeyScopes.tablesRead, enums.ProjectKeyScopes.tablesWrite],
    duration: 600,
);
```
```server-kotlin
val result = project.createEphemeralKey(
    scopes = listOf(ProjectKeyScopes.TABLES_READ, ProjectKeyScopes.TABLES_WRITE),
    duration = 600
)
```
```server-java
project.createEphemeralKey(
    List.of(ProjectKeyScopes.TABLES_READ, ProjectKeyScopes.TABLES_WRITE), // scopes
    600, // duration
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
let result = try await project.createEphemeralKey(
    scopes: [ProjectKeyScopes.tablesRead, ProjectKeyScopes.tablesWrite],
    duration: 600
)
```
```server-go
result, err := project.CreateEphemeralKey(
    []string{"tables.read", "tables.write"},
    600,
)

if err != nil {
    panic(err)
}

fmt.Println(result)
```
```server-rust
let result = project.create_ephemeral_key(
    vec![ProjectKeyScopes::TablesRead, ProjectKeyScopes::TablesWrite], // scopes
    600, // duration
).await?;

println!("{:?}", result);
```
```bash
appwrite project create-ephemeral-key \
    --scopes tables.read tables.write \
    --duration 600
```
```http
POST /v1/project/keys/ephemeral HTTP/1.1
Content-Type: application/json
X-Appwrite-Project: <PROJECT_ID>
X-Appwrite-Key: <YOUR_API_KEY>

{
  "scopes": ["tables.read", "tables.write"],
  "duration": 600
}
```
{% /multicode %}

# Next steps {% #next-steps %}

Running a key's whole life from code is what lets you treat customer credentials as managed infrastructure instead of secrets someone pasted once. The same Project API covers the rest of a project's life:

- [Provision a project's baseline](/docs/partners/project/provisioning) before you issue its first key.
- Read the [API keys](/docs/partners/project/api-keys) reference for the full list of scopes a key can hold.

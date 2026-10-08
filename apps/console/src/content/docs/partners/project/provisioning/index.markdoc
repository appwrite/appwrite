---
layout: article
title: Provision a project's baseline
description: Apply a standard configuration to a customer's project programmatically with the Project API, including platforms, auth methods, services, protocols, and variables.
---

A customer signs up to your platform and a project is created for them. Right now it is wide open: every authentication method is enabled, every service is exposed on the API, and nothing is constrained to the way you run things. Before that customer ever logs in, you want their project to match the baseline that every project on your platform shares.

The Project API lets you encode that baseline once and apply it from your backend or a CI step, so onboarding a customer is a script you run, not a checklist someone works through in the Console. The sections below follow that script in the order it runs:

- Register the customer's app.
- Narrow the sign-in methods to the ones they use.
- Close down the services and protocols they don't.
- Seed the variables their code expects.

Each call uses a [Server SDK](/docs/sdks#server) with an API key.

{% info title="Required scopes" %}
The API key used for these calls needs the `project.write` and `platforms.write` scopes. See [API keys](/docs/partners/project/api-keys) to create one.
{% /info %}

# Register the customer's app {% #register-platform %}

Nothing can talk to a project until you register the apps allowed to reach it. Set up the client once, then register the customer's web app by its hostname, so requests from their domain are accepted and everything else is rejected.

{% multicode %}
```server-nodejs
import { Client, Project, ID } from 'node-appwrite';

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>')
    .setKey('<YOUR_API_KEY>');

const project = new Project(client);

const result = await project.createWebPlatform({
    platformId: ID.unique(),
    name: 'Customer web app',
    hostname: 'app.customer.example'
});
```
```server-deno
import { Client, Project, ID } from "npm:node-appwrite";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>')
    .setKey('<YOUR_API_KEY>');

const project = new Project(client);

const result = await project.createWebPlatform({
    platformId: ID.unique(),
    name: 'Customer web app',
    hostname: 'app.customer.example'
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

$result = $project->createWebPlatform(
    platformId: ID::unique(),
    name: 'Customer web app',
    hostname: 'app.customer.example'
);
```
```server-python
from appwrite.client import Client
from appwrite.id import ID
from appwrite.services.project import Project

client = Client()
client.set_endpoint('https://<REGION>.cloud.appwrite.io/v1')
client.set_project('<PROJECT_ID>')
client.set_key('<YOUR_API_KEY>')

project = Project(client)

result = project.create_web_platform(
    platform_id = ID.unique(),
    name = 'Customer web app',
    hostname = 'app.customer.example'
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

response = project.create_web_platform(
    platform_id: ID.unique(),
    name: 'Customer web app',
    hostname: 'app.customer.example'
)
```
```server-dotnet
using Appwrite;
using Appwrite.Services;

Client client = new Client()
    .SetEndPoint("https://<REGION>.cloud.appwrite.io/v1")
    .SetProject("<PROJECT_ID>")
    .SetKey("<YOUR_API_KEY>");

Project project = new Project(client);

var result = await project.CreateWebPlatform(
    platformId: ID.Unique(),
    name: "Customer web app",
    hostname: "app.customer.example"
);
```
```server-dart
import 'package:dart_appwrite/dart_appwrite.dart';

Client client = Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>')
    .setKey('<YOUR_API_KEY>');

Project project = Project(client);

final result = await project.createWebPlatform(
    platformId: ID.unique(),
    name: 'Customer web app',
    hostname: 'app.customer.example',
);
```
```server-kotlin
import io.appwrite.Client
import io.appwrite.ID
import io.appwrite.services.Project

val client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1")
    .setProject("<PROJECT_ID>")
    .setKey("<YOUR_API_KEY>")

val project = Project(client)

val response = project.createWebPlatform(
    platformId = ID.unique(),
    name = "Customer web app",
    hostname = "app.customer.example"
)
```
```server-java
import io.appwrite.Client;
import io.appwrite.ID;
import io.appwrite.coroutines.CoroutineCallback;
import io.appwrite.services.Project;

Client client = new Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1")
    .setProject("<PROJECT_ID>")
    .setKey("<YOUR_API_KEY>");

Project project = new Project(client);

project.createWebPlatform(
    ID.unique(), // platformId
    "Customer web app", // name
    "app.customer.example", // hostname
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

let client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1")
    .setProject("<PROJECT_ID>")
    .setKey("<YOUR_API_KEY>")

let project = Project(client)

let result = try await project.createWebPlatform(
    platformId: ID.unique(),
    name: "Customer web app",
    hostname: "app.customer.example"
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
    result, err := project.CreateWebPlatform(
        id.Unique(),
        "Customer web app",
        "app.customer.example",
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
use appwrite::services::project::Project;

#[tokio::main]
async fn main() -> Result<(), Box<dyn std::error::Error>> {
    let client = Client::new()
        .set_endpoint("https://<REGION>.cloud.appwrite.io/v1")
        .set_project("<PROJECT_ID>")
        .set_key("<YOUR_API_KEY>");

    let project = Project::new(&client);

    let result = project.create_web_platform(
        ID::unique(),
        "Customer web app",
        "app.customer.example",
    ).await?;

    println!("{:?}", result);
    Ok(())
}
```
```bash
appwrite project create-web-platform \
    --platform-id 'unique()' \
    --name "Customer web app" \
    --hostname app.customer.example
```
```http
POST /v1/project/platforms/web HTTP/1.1
Content-Type: application/json
X-Appwrite-Project: <PROJECT_ID>
X-Appwrite-Key: <YOUR_API_KEY>

{
  "platformId": "unique()",
  "name": "Customer web app",
  "hostname": "app.customer.example"
}
```
{% /multicode %}

If the customer ships native apps too, register them the same way with `createAndroidPlatform`, `createApplePlatform`, `createLinuxPlatform`, or `createWindowsPlatform`. Each takes the identifier for that target, such as an Android application ID or an Apple bundle identifier.

# Enable the required auth methods {% #auth-methods %}

A fresh project leaves every sign-in method enabled. Trim that down to the methods the customer's app offers, so you are not exposing flows they never built a UI for. If they sign their users in over SMS, turn phone authentication on.

{% multicode %}
```server-nodejs
const result = await project.updateAuthMethod({
    methodId: ProjectAuthMethodId.Phone,
    enabled: true
});
```
```server-deno
const result = await project.updateAuthMethod({
    methodId: ProjectAuthMethodId.Phone,
    enabled: true
});
```
```server-php
$result = $project->updateAuthMethod(
    methodId: ProjectAuthMethodId::PHONE(),
    enabled: true
);
```
```server-python
result = project.update_auth_method(
    method_id = ProjectAuthMethodId.PHONE,
    enabled = True
)
```
```server-ruby
response = project.update_auth_method(
    method_id: ProjectAuthMethodId::PHONE,
    enabled: true
)
```
```server-dotnet
var result = await project.UpdateAuthMethod(
    methodId: ProjectAuthMethodId.Phone,
    enabled: true
);
```
```server-dart
final result = await project.updateAuthMethod(
    methodId: enums.ProjectAuthMethodId.phone,
    enabled: true,
);
```
```server-kotlin
val result = project.updateAuthMethod(
    methodId = ProjectAuthMethodId.PHONE,
    enabled = true
)
```
```server-java
project.updateAuthMethod(
    ProjectAuthMethodId.PHONE, // methodId
    true, // enabled
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
let result = try await project.updateAuthMethod(
    methodId: .phone,
    enabled: true
)
```
```server-go
result, err := project.UpdateAuthMethod(
    "phone",
    true,
)

if err != nil {
    panic(err)
}

fmt.Println(result)
```
```server-rust
let result = project.update_auth_method(
    ProjectAuthMethodId::Phone,
    true,
).await?;

println!("{:?}", result);
```
```bash
appwrite project update-auth-method \
    --method-id phone \
    --enabled true
```
```http
PATCH /v1/project/auth-methods/phone HTTP/1.1
Content-Type: application/json
X-Appwrite-Project: <PROJECT_ID>
X-Appwrite-Key: <YOUR_API_KEY>

{
  "enabled": true
}
```
{% /multicode %}

Pass `enabled: false` to switch a method off. The method ID is one of `email-password`, `magic-url`, `email-otp`, `anonymous`, `invites`, `jwt`, or `phone`.

# Disable unused services and protocols {% #services %}

Every service the customer will never call is attack surface you can remove. If their app has no client-side Functions, take Functions off the client API to shrink that surface.

{% multicode %}
```server-nodejs
const result = await project.updateService({
    serviceId: ProjectServiceId.Functions,
    enabled: false
});
```
```server-deno
const result = await project.updateService({
    serviceId: ProjectServiceId.Functions,
    enabled: false
});
```
```server-php
$result = $project->updateService(
    serviceId: ProjectServiceId::FUNCTIONS(),
    enabled: false
);
```
```server-python
result = project.update_service(
    service_id = ProjectServiceId.FUNCTIONS,
    enabled = False
)
```
```server-ruby
response = project.update_service(
    service_id: ProjectServiceId::FUNCTIONS,
    enabled: false
)
```
```server-dotnet
var result = await project.UpdateService(
    serviceId: ProjectServiceId.Functions,
    enabled: false
);
```
```server-dart
final result = await project.updateService(
    serviceId: enums.ProjectServiceId.functions,
    enabled: false,
);
```
```server-kotlin
val response = project.updateService(
    serviceId = ProjectServiceId.FUNCTIONS,
    enabled = false
)
```
```server-java
project.updateService(
    ProjectServiceId.FUNCTIONS, // serviceId
    false, // enabled
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
let result = try await project.updateService(
    serviceId: .functions,
    enabled: false
)
```
```server-go
result, err := service.UpdateService(
    "functions",
    false,
)

if err != nil {
    panic(err)
}

fmt.Println(result)
```
```server-rust
let result = project.update_service(
    ProjectServiceId::Functions,
    false,
).await?;

println!("{:?}", result);
```
```bash
appwrite project update-service \
    --service-id functions \
    --enabled false
```
```http
PATCH /v1/project/services/functions HTTP/1.1
Content-Type: application/json
X-Appwrite-Project: <PROJECT_ID>
X-Appwrite-Key: <YOUR_API_KEY>

{
  "enabled": false
}
```
{% /multicode %}

{% info title="Server SDKs keep access" %}
Disabling a service only removes it from the client-facing API. A Server SDK using an API key can still reach the service, including to turn it back on later, so your own backend keeps working while client apps lose the route.
{% /info %}

Apply the same reasoning to API protocols. If nothing in the customer's stack speaks GraphQL, disable it and leave REST and Realtime in place.

{% multicode %}
```server-nodejs
const result = await project.updateProtocol({
    protocolId: ProjectProtocolId.Graphql,
    enabled: false
});
```
```server-deno
const result = await project.updateProtocol({
    protocolId: ProjectProtocolId.Graphql,
    enabled: false
});
```
```server-php
$result = $project->updateProtocol(
    protocolId: ProjectProtocolId::GRAPHQL(),
    enabled: false
);
```
```server-python
result = project.update_protocol(
    protocol_id = ProjectProtocolId.GRAPHQL,
    enabled = False
)
```
```server-ruby
response = project.update_protocol(
    protocol_id: ProjectProtocolId::GRAPHQL,
    enabled: false
)
```
```server-dotnet
var result = await project.UpdateProtocol(
    protocolId: ProjectProtocolId.Graphql,
    enabled: false
);
```
```server-dart
final result = await project.updateProtocol(
    protocolId: enums.ProjectProtocolId.graphql,
    enabled: false,
);
```
```server-kotlin
val response = project.updateProtocol(
    protocolId = ProjectProtocolId.GRAPHQL,
    enabled = false
)
```
```server-java
project.updateProtocol(
    ProjectProtocolId.GRAPHQL, // protocolId
    false, // enabled
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
let result = try await project.updateProtocol(
    protocolId: .graphql,
    enabled: false
)
```
```server-go
result, err := service.UpdateProtocol(
    "graphql",
    false,
)

if err != nil {
    panic(err)
}

fmt.Println(result)
```
```server-rust
let result = project.update_protocol(
    ProjectProtocolId::Graphql,
    false,
).await?;

println!("{:?}", result);
```
```bash
appwrite project update-protocol \
    --protocol-id graphql \
    --enabled false
```
```http
PATCH /v1/project/protocols/graphql HTTP/1.1
Content-Type: application/json
X-Appwrite-Project: <PROJECT_ID>
X-Appwrite-Key: <YOUR_API_KEY>

{
  "enabled": false
}
```
{% /multicode %}

The protocol ID is one of `rest`, `graphql`, or `websocket`.

# Seed project variables {% #variables %}

The last step before the project is ready is to drop in the constants and secrets the customer's functions and sites read at build and runtime, so their first deploy finds everything it needs already in place.

{% multicode %}
```server-nodejs
const result = await project.createVariable({
    variableId: ID.unique(),
    key: 'TENANT_TIER',
    value: 'pro',
    secret: false // optional
});
```
```server-deno
const result = await project.createVariable({
    variableId: ID.unique(),
    key: 'TENANT_TIER',
    value: 'pro',
    secret: false // optional
});
```
```server-php
$result = $project->createVariable(
    variableId: ID::unique(),
    key: 'TENANT_TIER',
    value: 'pro',
    secret: false // optional
);
```
```server-python
result: Variable = project.create_variable(
    variable_id = ID.unique(),
    key = 'TENANT_TIER',
    value = 'pro',
    secret = False # optional
)

print(result.model_dump())
```
```server-ruby
result = project.create_variable(
    variable_id: ID.unique(),
    key: 'TENANT_TIER',
    value: 'pro',
    secret: false # optional
)
```
```server-dotnet
Variable result = await project.CreateVariable(
    variableId: ID.Unique(),
    key: "<KEY>",
    value: "<VALUE>",
    secret: false // optional
);
```
```server-dart
Variable result = await project.createVariable(
    variableId: ID.unique(),
    key: 'TENANT_TIER',
    value: 'pro',
    secret: false, // (optional)
);
```
```server-kotlin
val response = project.createVariable(
    variableId = ID.unique(),
    key = "<KEY>",
    value = "<VALUE>",
    secret = false // optional
)
```
```server-java
project.createVariable(
    ID.unique(), // variableId
    "<KEY>", // key
    "<VALUE>", // value
    false, // secret (optional)
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
let variable = try await project.createVariable(
    variableId: ID.unique(),
    key: "<KEY>",
    value: "<VALUE>",
    secret: false // optional
)
```
```server-go
response, error := project.CreateVariable(
id.Unique(),
"<KEY>",
"<VALUE>",
appwrite.WithCreateVariableSecret(false),
)
```
```server-rust
let result = project.create_variable(
    &ID::unique(),
    "<KEY>",
    "<VALUE>",
    Some(false) // optional
).await?;

let _ = result;
```
```bash
appwrite project create-variable \
    --variable-id 'unique()' \
    --key TENANT_TIER \
    --value pro
```
```http
POST /v1/project/variables HTTP/1.1
Content-Type: application/json
X-Appwrite-Project: <PROJECT_ID>
X-Appwrite-Key: <YOUR_API_KEY>

{
  "variableId": "unique()",
  "key": "TENANT_TIER",
  "value": "pro"
}
```
{% /multicode %}

# Next steps {% #next-steps %}

Wrap these calls in a single function keyed to the customer and every project you bring online starts identical, which is what keeps the next thousand projects manageable. With the baseline in place, the rest of the project's life uses the same Project API:

- [Issue and rotate API keys](/docs/partners/project/key-rotation) for the integrations that run against the project.
- [Brand its transactional emails](/docs/partners/project/branded-emails) so they come from the customer's domain.

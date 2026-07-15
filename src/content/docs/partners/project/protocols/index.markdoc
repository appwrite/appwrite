---
layout: article
title: Protocols
description: Enable or disable the REST, GraphQL, and WebSocket protocols on your Appwrite project programmatically using server SDKs.
---

Each Appwrite project exposes its API through three protocols: REST, GraphQL, and WebSocket. You can disable any protocol your clients don't use to shrink the project's surface area, then re-enable it when needed.

Protocols can be toggled from the Appwrite Console, or programmatically through any server SDK using the Project service.

# Manage from the Console {% #manage-console %}

{% only_dark %}
![Project protocols in the Appwrite Console](/images/docs/project/dark/protocols.avif)
{% /only_dark %}
{% only_light %}
![Project protocols in the Appwrite Console](/images/docs/project/protocols.avif)
{% /only_light %}

To toggle a protocol manually:

1. Open your project in the Appwrite Console.
2. Open **Settings** from the bottom of the side nav.
3. Scroll to the **Protocols** card.
4. Flip the switch next to REST, GraphQL, or WebSocket. Use **Disable all** to turn off every protocol at once.

# Available protocols {% #available-protocols %}

| Protocol ID | Description |
| --- | --- |
| `rest` | Standard HTTP API requests from client SDKs. |
| `graphql` | GraphQL API access for queries and mutations. |
| `websocket` | Realtime subscriptions over WebSocket connections. |

# Update a protocol {% #update-protocol %}

The example below disables the REST protocol. Pass `enabled: true` to re-enable it.

Disabling REST blocks client SDK traffic only. Server SDKs using an API key keep access, so you can always call this endpoint again to re-enable the protocol.

{% info title="Required scope" %}
The API key used for this call needs the `project.write` scope.
{% /info %}

{% multicode %}
```server-nodejs
import { Client, Project, ProjectProtocolId } from 'node-appwrite';

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>')
    .setKey('<YOUR_API_KEY>');

const project = new Project(client);

const result = await project.updateProtocol({
    protocolId: ProjectProtocolId.Rest,
    enabled: false
});
```
```server-deno
import { Client, Project, ProjectProtocolId } from "npm:node-appwrite";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>')
    .setKey('<YOUR_API_KEY>');

const project = new Project(client);

const result = await project.updateProtocol({
    protocolId: ProjectProtocolId.Rest,
    enabled: false
});
```
```server-php
<?php

use Appwrite\Client;
use Appwrite\Enums\ProjectProtocolId;
use Appwrite\Services\Project;

$client = new Client();

$client
    ->setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    ->setProject('<PROJECT_ID>')
    ->setKey('<YOUR_API_KEY>');

$project = new Project($client);

$result = $project->updateProtocol(
    protocolId: ProjectProtocolId::REST(),
    enabled: false
);
```
```server-python
from appwrite.client import Client
from appwrite.enums import ProjectProtocolId
from appwrite.services.project import Project

client = Client()
client.set_endpoint('https://<REGION>.cloud.appwrite.io/v1')
client.set_project('<PROJECT_ID>')
client.set_key('<YOUR_API_KEY>')

project = Project(client)

result = project.update_protocol(
    protocol_id = ProjectProtocolId.REST,
    enabled = False
)
```
```server-ruby
require 'appwrite'

include Appwrite
include Appwrite::Enums

client = Client.new
    .set_endpoint('https://<REGION>.cloud.appwrite.io/v1')
    .set_project('<PROJECT_ID>')
    .set_key('<YOUR_API_KEY>')

project = Project.new(client)

response = project.update_protocol(
    protocol_id: ProjectProtocolId::REST,
    enabled: false
)
```
```server-dotnet
using Appwrite;
using Appwrite.Enums;
using Appwrite.Services;

Client client = new Client()
    .SetEndPoint("https://<REGION>.cloud.appwrite.io/v1")
    .SetProject("<PROJECT_ID>")
    .SetKey("<YOUR_API_KEY>");

Project project = new Project(client);

var result = await project.UpdateProtocol(
    protocolId: ProjectProtocolId.Rest,
    enabled: false
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

final result = await project.updateProtocol(
    protocolId: enums.ProjectProtocolId.rest,
    enabled: false,
);
```
```server-kotlin
import io.appwrite.Client
import io.appwrite.enums.ProjectProtocolId
import io.appwrite.services.Project

val client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1")
    .setProject("<PROJECT_ID>")
    .setKey("<YOUR_API_KEY>")

val project = Project(client)

val response = project.updateProtocol(
    protocolId = ProjectProtocolId.REST,
    enabled = false
)
```
```server-java
import io.appwrite.Client;
import io.appwrite.coroutines.CoroutineCallback;
import io.appwrite.enums.ProjectProtocolId;
import io.appwrite.services.Project;

Client client = new Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1")
    .setProject("<PROJECT_ID>")
    .setKey("<YOUR_API_KEY>");

Project project = new Project(client);

project.updateProtocol(
    ProjectProtocolId.REST, // protocolId
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
import Appwrite
import AppwriteEnums

let client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1")
    .setProject("<PROJECT_ID>")
    .setKey("<YOUR_API_KEY>")

let project = Project(client)

let result = try await project.updateProtocol(
    protocolId: .rest,
    enabled: false
)
```
```server-go
package main

import (
    "fmt"
    "github.com/appwrite/sdk-for-go/appwrite"
)

func main() {
    client := appwrite.NewClient(
        appwrite.WithEndpoint("https://<REGION>.cloud.appwrite.io/v1"),
        appwrite.WithProject("<PROJECT_ID>"),
        appwrite.WithKey("<YOUR_API_KEY>"),
    )

    service := appwrite.NewProject(client)
    result, err := service.UpdateProtocol(
        "rest",
        false,
    )

    if err != nil {
        panic(err)
    }

    fmt.Println(result)
}
```
```server-rust
use appwrite::Client;
use appwrite::enums::ProjectProtocolId;
use appwrite::services::project::Project;

#[tokio::main]
async fn main() -> Result<(), Box<dyn std::error::Error>> {
    let client = Client::new()
        .set_endpoint("https://<REGION>.cloud.appwrite.io/v1")
        .set_project("<PROJECT_ID>")
        .set_key("<YOUR_API_KEY>");

    let project = Project::new(&client);

    let result = project.update_protocol(
        ProjectProtocolId::Rest,
        false,
    ).await?;

    println!("{:?}", result);
    Ok(())
}
```
```bash
appwrite project update-protocol \
    --protocol-id rest \
    --enabled false
```
{% /multicode %}

# Benefits {% #benefits %}

- **Shrink the client surface area.** Disabled protocols are blocked for client SDK callers (anonymous, account session, JWT, and OAuth users). Server SDKs using an API key still have access, including to re-enable the protocol.
- **Repeatable provisioning.** Script the protocol set a project should expose and apply it from CI when spinning up a new environment.
- **Environment parity.** Keep dev, staging, and production in sync by running the same enable/disable script against each project.

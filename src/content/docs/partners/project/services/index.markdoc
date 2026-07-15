---
layout: article
title: Services
description: Enable or disable individual Appwrite services on your project programmatically using server SDKs.
---

Each Appwrite project ships with the full set of services enabled by default: Account, TablesDB, Storage, Functions, and so on. You can disable any service your clients don't use to remove it from the client-facing API. Disabled services remain accessible to server SDKs using an API key.

Services can be toggled from the Appwrite Console, or programmatically through any server SDK using the Project service.

# Manage from the Console {% #manage-console %}

{% only_dark %}
![Project services in the Appwrite Console](/images/docs/project/dark/services.avif)
{% /only_dark %}
{% only_light %}
![Project services in the Appwrite Console](/images/docs/project/services.avif)
{% /only_light %}

To toggle a service manually:

1. Open your project in the Appwrite Console.
2. Open **Settings** from the bottom of the side nav.
3. Scroll to the **Services** card.
4. Flip the switch next to the service you want to enable or disable. Use **Disable all** to turn off every optional service at once.

# Available services {% #available-services %}

| Service ID | Description |
| --- | --- |
| `account` | User accounts and authentication. |
| `avatars` | App image, icon, and avatar helpers. |
| `tablesdb` | TablesDB tables, columns, and rows. |
| `locale` | Locale and geographic helpers. |
| `health` | Health checks and status. |
| `project` | Project configuration. |
| `storage` | File storage buckets and files. |
| `teams` | Teams and shared resource access. |
| `users` | User administration via server SDKs. |
| `sites` | Sites hosting and deployments. |
| `functions` | Cloud Functions. |
| `proxy` | Custom domain proxy. |
| `graphql` | GraphQL endpoint. |
| `migrations` | Third-party data migrations. |
| `messaging` | Push, SMS, and email messaging. |
| `databases` | Legacy Databases collections and documents. |

# Update a service {% #update-service %}

The example below disables the Account service. Pass `enabled: true` to re-enable it.

{% info title="Required scope" %}
The API key used for this call needs the `project.write` scope.
{% /info %}

{% multicode %}
```server-nodejs
import { Client, Project, ProjectServiceId } from 'node-appwrite';

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>')
    .setKey('<YOUR_API_KEY>');

const project = new Project(client);

const result = await project.updateService({
    serviceId: ProjectServiceId.Account,
    enabled: false
});
```
```server-deno
import { Client, Project, ProjectServiceId } from "npm:node-appwrite";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>')
    .setKey('<YOUR_API_KEY>');

const project = new Project(client);

const result = await project.updateService({
    serviceId: ProjectServiceId.Account,
    enabled: false
});
```
```server-php
<?php

use Appwrite\Client;
use Appwrite\Enums\ProjectServiceId;
use Appwrite\Services\Project;

$client = new Client();

$client
    ->setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    ->setProject('<PROJECT_ID>')
    ->setKey('<YOUR_API_KEY>');

$project = new Project($client);

$result = $project->updateService(
    serviceId: ProjectServiceId::ACCOUNT(),
    enabled: false
);
```
```server-python
from appwrite.client import Client
from appwrite.enums import ProjectServiceId
from appwrite.services.project import Project

client = Client()
client.set_endpoint('https://<REGION>.cloud.appwrite.io/v1')
client.set_project('<PROJECT_ID>')
client.set_key('<YOUR_API_KEY>')

project = Project(client)

result = project.update_service(
    service_id = ProjectServiceId.ACCOUNT,
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

response = project.update_service(
    service_id: ProjectServiceId::ACCOUNT,
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

var result = await project.UpdateService(
    serviceId: ProjectServiceId.Account,
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

final result = await project.updateService(
    serviceId: enums.ProjectServiceId.account,
    enabled: false,
);
```
```server-kotlin
import io.appwrite.Client
import io.appwrite.enums.ProjectServiceId
import io.appwrite.services.Project

val client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1")
    .setProject("<PROJECT_ID>")
    .setKey("<YOUR_API_KEY>")

val project = Project(client)

val response = project.updateService(
    serviceId = ProjectServiceId.ACCOUNT,
    enabled = false
)
```
```server-java
import io.appwrite.Client;
import io.appwrite.coroutines.CoroutineCallback;
import io.appwrite.enums.ProjectServiceId;
import io.appwrite.services.Project;

Client client = new Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1")
    .setProject("<PROJECT_ID>")
    .setKey("<YOUR_API_KEY>");

Project project = new Project(client);

project.updateService(
    ProjectServiceId.ACCOUNT, // serviceId
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

let result = try await project.updateService(
    serviceId: .account,
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
    result, err := service.UpdateService(
        "account",
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
use appwrite::enums::ProjectServiceId;
use appwrite::services::project::Project;

#[tokio::main]
async fn main() -> Result<(), Box<dyn std::error::Error>> {
    let client = Client::new()
        .set_endpoint("https://<REGION>.cloud.appwrite.io/v1")
        .set_project("<PROJECT_ID>")
        .set_key("<YOUR_API_KEY>");

    let project = Project::new(&client);

    let result = project.update_service(
        ProjectServiceId::Account,
        false,
    ).await?;

    println!("{:?}", result);
    Ok(())
}
```
```bash
appwrite project update-service \
    --service-id account \
    --enabled false
```
{% /multicode %}

# Benefits {% #benefits %}

- **Shrink the client surface area.** Disable services your client apps don't use so they're no longer callable from client SDKs.
- **Server-only access.** Disabled services stay reachable from server SDKs using an API key, useful when you want a service available to your backend but hidden from clients.
- **Repeatable provisioning.** Script the service set a project should expose and apply it from CI when spinning up a new environment.

---
layout: article
title: Labels
description: Assign customizable labels to your Appwrite project to categorize and filter projects within an organization.
---

Labels are short alphanumeric tags you assign to a project. Use them to categorize your projects and filter them within an organization, for example by environment (`production`, `staging`), team, or region.

# Manage in the Console {% #console %}

{% only_dark %}
![Project labels in the Appwrite Console](/images/docs/project/dark/labels.avif)
{% /only_dark %}
{% only_light %}
![Project labels in the Appwrite Console](/images/docs/project/labels.avif)
{% /only_light %}

To manage labels from the Appwrite Console:

1. Navigate to your project.
2. Open the **Settings** section. The **Labels** card is on the **Overview** tab.
3. In the **Labels** field, type a label and press Enter, or select one of the suggested labels. Labels may contain only alphanumeric characters.
4. Click **Update** to save your changes.

# Manage with a Server SDK {% #server-sdks %}

You can also manage project labels programmatically using a [Server SDK](/docs/sdks#server).

{% info title="Required scope" %}
The API key used for this call needs the `project.write` scope.
{% /info %}

## Update labels {% #update-labels %}

The `labels` array replaces the project's existing labels, so include every label you want to keep in each call. A project can have up to 1000 labels, each up to 36 alphanumeric characters long.

{% multicode %}
```server-nodejs
import { Client, Project } from 'node-appwrite';

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>') // Your project ID
    .setKey('<YOUR_API_KEY>'); // Your API key

const project = new Project(client);

const result = await project.updateLabels({
    labels: ['production', 'eu']
});
```
```server-deno
import { Client, Project } from "npm:node-appwrite";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>') // Your project ID
    .setKey('<YOUR_API_KEY>'); // Your API key

const project = new Project(client);

const result = await project.updateLabels({
    labels: ['production', 'eu']
});
```
```server-php
<?php

use Appwrite\Client;
use Appwrite\Services\Project;

$client = (new Client())
    ->setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    ->setProject('<YOUR_PROJECT_ID>') // Your project ID
    ->setKey('<YOUR_API_KEY>'); // Your API key

$project = new Project($client);

$result = $project->updateLabels(
    labels: ['production', 'eu']
);
```
```server-python
from appwrite.client import Client
from appwrite.services.project import Project
from appwrite.models import Project as ProjectModel

client = Client()
client.set_endpoint('https://<REGION>.cloud.appwrite.io/v1') # Your API Endpoint
client.set_project('<YOUR_PROJECT_ID>') # Your project ID
client.set_key('<YOUR_API_KEY>') # Your API key

project = Project(client)

result: ProjectModel = project.update_labels(
    labels = ['production', 'eu']
)

print(result.model_dump())
```
```server-ruby
require 'appwrite'

include Appwrite

client = Client.new
    .set_endpoint('https://<REGION>.cloud.appwrite.io/v1') # Your API Endpoint
    .set_project('<YOUR_PROJECT_ID>') # Your project ID
    .set_key('<YOUR_API_KEY>') # Your API key

project = Project.new(client)

result = project.update_labels(
    labels: ['production', 'eu']
)
```
```server-dotnet
using Appwrite;
using Appwrite.Models;
using Appwrite.Services;

Client client = new Client()
    .SetEndPoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .SetProject("<YOUR_PROJECT_ID>") // Your project ID
    .SetKey("<YOUR_API_KEY>"); // Your API key

Project project = new Project(client);

Project result = await project.UpdateLabels(
    labels: new List<string> { "production", "eu" }
);
```
```server-dart
import 'package:dart_appwrite/dart_appwrite.dart';

Client client = Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>') // Your project ID
    .setKey('<YOUR_API_KEY>'); // Your API key

Project project = Project(client);

Project result = await project.updateLabels(
    labels: ['production', 'eu'],
);
```
```server-kotlin
import io.appwrite.Client
import io.appwrite.coroutines.CoroutineCallback
import io.appwrite.services.Project

val client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<YOUR_PROJECT_ID>") // Your project ID
    .setKey("<YOUR_API_KEY>") // Your API key

val project = Project(client)

val response = project.updateLabels(
    labels = listOf("production", "eu")
)
```
```server-java
import io.appwrite.Client;
import io.appwrite.coroutines.CoroutineCallback;
import io.appwrite.services.Project;

Client client = new Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<YOUR_PROJECT_ID>") // Your project ID
    .setKey("<YOUR_API_KEY>"); // Your API key

Project project = new Project(client);

project.updateLabels(
    List.of("production", "eu"), // labels
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
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<YOUR_PROJECT_ID>") // Your project ID
    .setKey("<YOUR_API_KEY>") // Your API key

let project = Project(client)

let result = try await project.updateLabels(
    labels: ["production", "eu"]
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
        appwrite.WithProject("<YOUR_PROJECT_ID>"),
        appwrite.WithKey("<YOUR_API_KEY>"),
    )

    project := appwrite.NewProject(client)
    result, err := project.UpdateLabels(
        []interface{}{"production", "eu"},
    )

    if err != nil {
        panic(err)
    }

    fmt.Println(result)
}
```
```server-rust
use appwrite::Client;
use appwrite::services::Project;

#[tokio::main]
async fn main() -> Result<(), Box<dyn std::error::Error>> {
    let client = Client::new();
    client.set_endpoint("https://<REGION>.cloud.appwrite.io/v1"); // Your API Endpoint
    client.set_project("<YOUR_PROJECT_ID>"); // Your project ID
    client.set_key("<YOUR_API_KEY>"); // Your API key

    let project = Project::new(&client);

    let result = project.update_labels(
        vec!["production", "eu"]
    ).await?;

    let _ = result;

    Ok(())
}
```
```bash
appwrite project update-labels \
    --labels production \
    --labels eu
```
{% /multicode %}

{% info title="Reading labels" %}
Labels have no dedicated read endpoint. Call `get` to fetch the project and read its `labels` field.

```server-nodejs
const result = await project.get();
console.log(result.labels); // ['production', 'eu']
```
{% /info %}

# Benefits {% #benefits %}

- **Organize at scale.** Group related projects with shared tags such as environment, team, or region, so large organizations stay navigable.
- **Faster filtering.** Find the projects you need by filtering an organization on its labels instead of scanning the full list.
- **Repeatable provisioning.** Apply a consistent labeling scheme across projects from a script, keeping your organization tidy without manual Console work.

---
layout: article
title: Project
description: Configure your Appwrite project, including auth methods, platforms, protocols, services, and policies.
back: /docs
---

An Appwrite **Project** is the top-level container for all the resources your app uses, from users and databases to storage buckets and functions. The settings on a project control which authentication methods are available, which client platforms can connect, which protocols and services are exposed, and which policies apply to the resources inside it.

# Built for platform teams {% #platform-teams %}

The Console configures one project at a time by hand. The Project API does the same configuration programmatically, which is what teams need when they operate not one project but many: a platform that gives every customer their own project, an agency running client environments, or an enterprise standardizing dozens of internal apps.

Driving configuration from code is what makes a fleet of projects manageable. Provision every new project to the same baseline, enforce one security posture across all of them, and send each customer branded emails from their own domain. The [guides](#guides) below walk through each of these end to end.

# Concepts {% #concepts %}

{% cards %}
{% cards_item href="/docs/partners/project/auth-methods" title="Auth methods" %}
Enable or disable authentication methods on your project.
{% /cards_item %}
{% cards_item href="/docs/partners/project/oauth" title="OAuth providers" %}
Configure OAuth2 sign-in providers like Google, GitHub, and Apple.
{% /cards_item %}
{% cards_item href="/docs/partners/project/api-keys" title="API keys" %}
Create and manage API keys used by Server SDKs.
{% /cards_item %}
{% cards_item href="/docs/partners/project/platforms" title="Platforms" %}
Register the apps and origins that are allowed to call your project.
{% /cards_item %}
{% cards_item href="/docs/partners/project/protocols" title="Protocols" %}
Toggle the API protocols clients can use to reach your project.
{% /cards_item %}
{% cards_item href="/docs/partners/project/services" title="Services" %}
Turn individual Appwrite services on or off for your project.
{% /cards_item %}
{% cards_item href="/docs/partners/project/policies" title="Policies" %}
Configure project-wide policies that apply across resources.
{% /cards_item %}
{% cards_item href="/docs/partners/project/mock-phones" title="Mock phones" %}
Register fictional phone numbers and OTPs to test phone authentication.
{% /cards_item %}
{% cards_item href="/docs/partners/project/environment-variables" title="Environment variables" %}
Share constants and secrets across your functions and sites.
{% /cards_item %}
{% cards_item href="/docs/partners/project/smtp" title="SMTP" %}
Send emails from your own custom SMTP server.
{% /cards_item %}
{% cards_item href="/docs/partners/project/email-templates" title="Email templates" %}
Customize the account management emails Appwrite sends to your users.
{% /cards_item %}
{% cards_item href="/docs/partners/project/labels" title="Labels" %}
Tag projects with customizable labels to organize and filter them.
{% /cards_item %}
{% /cards %}

# Guides {% #guides %}

{% cards %}
{% cards_item href="/docs/partners/project/provisioning" title="Provisioning" %}
Apply a standard baseline to a new customer's project from a script.
{% /cards_item %}
{% cards_item href="/docs/partners/project/key-rotation" title="Key rotation" %}
Issue, audit, rotate, and revoke scoped API keys programmatically.
{% /cards_item %}
{% cards_item href="/docs/partners/project/branded-emails" title="Branded emails" %}
Route a project's mail through custom SMTP and rebrand the templates.
{% /cards_item %}
{% /cards %}

# Retrieve your project {% #get-project %}

Fetch your project's current configuration in a single call, including its name, region, labels, and the settings managed on the pages above. Use a [Server SDK](/docs/sdks#server).

{% info title="Required scope" %}
The API key used for this call needs the `project.read` scope.
{% /info %}

{% multicode %}
```server-nodejs
import { Client, Project } from 'node-appwrite';

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>') // Your project ID
    .setKey('<YOUR_API_KEY>'); // Your API key

const project = new Project(client);

const result = await project.get();
```
```server-deno
import { Client, Project } from "npm:node-appwrite";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>') // Your project ID
    .setKey('<YOUR_API_KEY>'); // Your API key

const project = new Project(client);

const result = await project.get();
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

$result = $project->get();
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

result: ProjectModel = project.get()

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

result = project.get
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

Project result = await project.Get();
```
```server-dart
import 'package:dart_appwrite/dart_appwrite.dart';

Client client = Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>') // Your project ID
    .setKey('<YOUR_API_KEY>'); // Your API key

Project project = Project(client);

Project result = await project.get();
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

val response = project.get()
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

project.get(new CoroutineCallback<>((result, error) -> {
    if (error != null) {
        error.printStackTrace();
        return;
    }

    System.out.println(result);
}));
```
```server-swift
import Appwrite

let client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<YOUR_PROJECT_ID>") // Your project ID
    .setKey("<YOUR_API_KEY>") // Your API key

let project = Project(client)

let result = try await project.get()
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
    result, err := project.Get()

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

    let result = project.get().await?;

    let _ = result;

    Ok(())
}
```
{% /multicode %}

# Delete a project {% #delete-project %}

{% info title="Permanent and irreversible" %}
Deleting a project permanently removes it along with every resource inside it, including users, databases, storage, functions, and sites. This action cannot be undone. The call targets the project tied to the API key and endpoint you initialize the SDK with, so make sure you are pointing at the project you intend to delete.
{% /info %}

The `delete` method requires an API key with the `project.write` scope. It takes no parameters and acts on the project the request is scoped to.

{% multicode %}
```server-nodejs
import { Client, Project } from 'node-appwrite';

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>') // Your project ID
    .setKey('<YOUR_API_KEY>'); // Your API key

const project = new Project(client);

const result = await project.delete();
```
```server-deno
import { Client, Project } from "npm:node-appwrite";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>') // Your project ID
    .setKey('<YOUR_API_KEY>'); // Your API key

const project = new Project(client);

const result = await project.delete();
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

$result = $project->delete();
```
```server-python
from appwrite.client import Client
from appwrite.services.project import Project

client = Client()
client.set_endpoint('https://<REGION>.cloud.appwrite.io/v1') # Your API Endpoint
client.set_project('<YOUR_PROJECT_ID>') # Your project ID
client.set_key('<YOUR_API_KEY>') # Your API key

project = Project(client)

result = project.delete()
```
```server-ruby
require 'appwrite'

include Appwrite

client = Client.new
    .set_endpoint('https://<REGION>.cloud.appwrite.io/v1') # Your API Endpoint
    .set_project('<YOUR_PROJECT_ID>') # Your project ID
    .set_key('<YOUR_API_KEY>') # Your API key

project = Project.new(client)

result = project.delete()
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

await project.Delete();
```
```server-dart
import 'package:dart_appwrite/dart_appwrite.dart';

Client client = Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>') // Your project ID
    .setKey('<YOUR_API_KEY>'); // Your API key

Project project = Project(client);

await project.delete();
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

val response = project.delete()
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

project.delete(new CoroutineCallback<>((result, error) -> {
    if (error != null) {
        error.printStackTrace();
        return;
    }

    System.out.println(result);
}));
```
```server-swift
import Appwrite

let client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<YOUR_PROJECT_ID>") // Your project ID
    .setKey("<YOUR_API_KEY>") // Your API key

let project = Project(client)

let result = try await project.delete()
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
    result, err := project.Delete()

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

    project.delete().await?;

    Ok(())
}
```
```bash
appwrite project delete
```
{% /multicode %}

---
layout: post
title: "Announcing the Projects API: Configure your project with Server SDKs"
description: Every project setting, from auth methods and OAuth providers to SMTP and labels, can now be configured programmatically through the Appwrite Server SDKs.
date: 2026-07-22
cover: /images/blog/announcing-projects-api/cover.avif
timeToRead: 5
author: matej-baco
category: announcement
featured: false
callToAction: true
faqs:
  - question: "What is the Appwrite Projects API?"
    answer: "It is a set of endpoints on the `Project` service that let you configure and read a project's settings programmatically from any Appwrite Server SDK. Previously, settings like auth methods, OAuth providers, and SMTP could only be changed through the Appwrite Console."
  - question: "What can I configure with the Projects API?"
    answer: "Auth methods, OAuth2 providers, API keys, platforms, protocols, services, password and session policies, environment variables, custom SMTP, email templates, mock phone numbers, and project labels. You can also read the full project configuration with `project.get`."
  - question: "What does the Projects API require?"
    answer: "An API key with the relevant scopes. Reading the project uses `project.read`, most configuration uses `project.write`, and some resources have dedicated scopes such as `platforms.write`, `templates.write`, or `keys.write`. These keys are server-side credentials, so keep them out of client applications."
  - question: "How is the Projects API different from the Console?"
    answer: "It exposes the same project settings as the Console through code. Anything you configure by clicking through the Console you can now script, version, and review, which makes project setup repeatable across environments."
  - question: "Which SDKs support the Projects API?"
    answer: "All Appwrite Server SDKs, including Node.js, Python, PHP, Ruby, .NET, Dart, Kotlin, Java, Swift, Go, and Rust, along with the Appwrite CLI. Per-language examples are in the [Projects API documentation](/docs/partners/project)."
  - question: "Can I create a project with the Projects API?"
    answer: "The Projects API manages the settings of an existing project and reads it with `project.get`. Creating a project still happens in the Appwrite Console."
---

Configuring a project has always meant working through the Appwrite Console: toggling auth methods, pasting OAuth credentials, registering platforms, and setting up SMTP one screen at a time. For a single project, that is fine. For teams managing several environments, onboarding new projects, or rebuilding a configuration from scratch, repeating those steps by hand becomes a bottleneck.

Today, we are announcing the **Projects API**, which lets you configure and read every project setting programmatically through the Appwrite Server SDKs.

This is part of a wider effort to make everything in Appwrite accessible through the API. Our goal is to ensure that any action you can perform in the Appwrite Console can also be done programmatically, giving you full control over your projects through code.

# Why this matters

A project's configuration is the foundation every other resource sits on. Which auth methods are enabled, which platforms can connect, how email is delivered: these decisions shape how your app behaves. This matters most for partners: agencies, consultancies, and platforms that manage Appwrite projects on behalf of their clients, where every new client means another project to set up and keep consistent. Managing configuration from code opens up workflows that were previously manual:

- **Reproducible environments** where development, staging, and production projects are configured from the same script
- **Project provisioning** that sets up auth, platforms, and policies for a new project in one run
- **Multi-tenant platforms** that configure a project per customer without Console access
- **Partner workflows**, where agencies, consultancies, and other teams that build for clients provision and manage projects at scale without clicking through the Console for each one
- **Configuration in version control**, so changes to a project are reviewed and tracked like any other code

# What you can configure

The Projects API covers the full surface of project settings, grouped into a few areas:

- **Authentication**: enable or disable [auth methods](/docs/partners/project/auth-methods), configure [OAuth2 providers](/docs/partners/project/oauth), and set [password and session policies](/docs/partners/project/policies).
- **Access**: create and manage [API keys](/docs/partners/project/api-keys), register [platforms](/docs/partners/project/platforms), and toggle [protocols](/docs/partners/project/protocols) and [services](/docs/partners/project/services).
- **Email**: configure a custom [SMTP server](/docs/partners/project/smtp), customize [email templates](/docs/partners/project/email-templates), and send test emails.
- **Project**: manage [environment variables](/docs/partners/project/environment-variables), register [mock phone numbers](/docs/partners/project/mock-phones) for testing, assign [labels](/docs/partners/project/labels), and read the full configuration with `project.get`.

# How it works

Every operation lives on the `Project` service. Initialize a Server SDK with an API key, then call the settings you need. For example, registering a web platform that is allowed to call your project:

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
    name: 'My Web App',
    hostname: 'app.example.com'
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
    name: 'My Web App',
    hostname: 'app.example.com'
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
    name: 'My Web App',
    hostname: 'app.example.com'
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
    name = 'My Web App',
    hostname = 'app.example.com'
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
    name: 'My Web App',
    hostname: 'app.example.com'
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
    name: "My Web App",
    hostname: "app.example.com"
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
    name: 'My Web App',
    hostname: 'app.example.com',
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
    name = "My Web App",
    hostname = "app.example.com"
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
    "My Web App", // name
    "app.example.com", // hostname
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
    name: "My Web App",
    hostname: "app.example.com"
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
        "My Web App",
        "app.example.com",
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
use appwrite::services::Project;

#[tokio::main]
async fn main() -> Result<(), Box<dyn std::error::Error>> {
    let client = Client::new();
    client.set_endpoint("https://<REGION>.cloud.appwrite.io/v1");
    client.set_project("<PROJECT_ID>");
    client.set_key("<YOUR_API_KEY>");

    let project = Project::new(&client);

    let result = project.create_web_platform(
        ID::unique(),
        "My Web App",
        "app.example.com",
    ).await?;

    let _ = result;

    Ok(())
}
```
{% /multicode %}

Or controlling which API protocols clients can use to reach your project, for example enabling REST:

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
    enabled: true
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
    enabled: true
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
    enabled: true
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
    enabled = True
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
    enabled: true
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
    enabled: true
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
    enabled: true,
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
    enabled = true
)
```
```server-java
import io.appwrite.Client;
import io.appwrite.enums.ProjectProtocolId;
import io.appwrite.coroutines.CoroutineCallback;
import io.appwrite.services.Project;

Client client = new Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1")
    .setProject("<PROJECT_ID>")
    .setKey("<YOUR_API_KEY>");

Project project = new Project(client);

project.updateProtocol(
    ProjectProtocolId.REST, // protocolId
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
import Appwrite
import AppwriteEnums

let client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1")
    .setProject("<PROJECT_ID>")
    .setKey("<YOUR_API_KEY>")

let project = Project(client)

let result = try await project.updateProtocol(
    protocolId: .rest,
    enabled: true
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

    project := appwrite.NewProject(client)
    result, err := project.UpdateProtocol(
        "rest",
        true,
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
use appwrite::enums::ProjectProtocolId;

#[tokio::main]
async fn main() -> Result<(), Box<dyn std::error::Error>> {
    let client = Client::new();
    client.set_endpoint("https://<REGION>.cloud.appwrite.io/v1");
    client.set_project("<PROJECT_ID>");
    client.set_key("<YOUR_API_KEY>");

    let project = Project::new(&client);

    let result = project.update_protocol(
        ProjectProtocolId::Rest,
        true
    ).await?;

    let _ = result;

    Ok(())
}
```
{% /multicode %}

Every setting maps to a method on the `Project` service, available across all Server SDKs with the matching object or named parameters.

# Get started

The Projects API is available on **Appwrite Cloud** today, across all Appwrite Server SDKs, including Node.js, Python, PHP, Ruby, .NET, Dart, Kotlin, Java, Swift, Go, and Rust, as well as the Appwrite CLI.

1. Create an [API key](/docs/partners/project/api-keys) with the scopes for the settings you want to manage.
2. Initialize a Server SDK with your API key.
3. Use the `Project` service to configure your project from code.

# Resources

- [Projects API documentation](/docs/partners/project)
- [Appwrite Discord community](https://appwrite.io/discord)

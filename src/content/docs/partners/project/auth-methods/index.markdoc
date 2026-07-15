---
layout: article
title: Auth methods
description: Enable or disable authentication methods on your Appwrite project programmatically using server SDKs.
---

Each Appwrite project ships with a configurable set of authentication methods, including email and password, magic URL, email OTP, phone, anonymous sessions, JWT, and team invites. Methods can be toggled on or off from the Appwrite Console under **Auth** > **Settings**, or programmatically through any server SDK using the Project service.

When a method is disabled, the matching account endpoints reject requests for that project until it is re-enabled.

# Toggle from the Console {% #toggle-console %}

{% only_dark %}
![Auth methods settings in the Appwrite Console](/images/docs/project/dark/auth-methods.avif)
{% /only_dark %}
{% only_light %}
![Auth methods settings in the Appwrite Console](/images/docs/project/auth-methods.avif)
{% /only_light %}

To toggle auth methods manually:

1. Open your project in the Appwrite Console.
2. Navigate to **Auth** in the sidebar, then open the **Settings** tab.
3. In the **Auth methods** card, toggle individual methods on or off, or use **Enable all** or **Disable all** for bulk changes.
4. Changes take effect immediately. No deploy or restart is required.

OAuth2 providers are configured separately in the **OAuth2 Providers** section on the same page.

# Method IDs {% #method-ids %}

The `methodId` parameter accepts one of the following values:

| Method ID | Description |
| --- | --- |
| `email-password` | Email and password sign-up and login. |
| `magic-url` | Passwordless login using a magic link sent to the user's email. |
| `email-otp` | Time-based one-time password sent to the user's email. |
| `phone` | SMS-based phone authentication. |
| `anonymous` | Guest sessions for unauthenticated visitors. |
| `invites` | Team invitations for collaborative access. |
| `jwt` | JWT-based authentication for delegated access. |

# Enable or disable a method {% #update-auth-method %}

Use the Project service `updateAuthMethod` endpoint with a method ID and the `enabled` flag.

{% info title="Required scope" %}
The API key used for these calls needs the `project.write` scope.
{% /info %}

{% multicode %}
```server-nodejs
import { Client, Project, ProjectAuthMethodId } from 'node-appwrite';

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>')
    .setKey('<YOUR_API_KEY>');

const project = new Project(client);

const result = await project.updateAuthMethod({
    methodId: ProjectAuthMethodId.Emailpassword,
    enabled: false
});
```
```server-deno
import { Client, Project, ProjectAuthMethodId } from "npm:node-appwrite";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>')
    .setKey('<YOUR_API_KEY>');

const project = new Project(client);

const result = await project.updateAuthMethod({
    methodId: ProjectAuthMethodId.Emailpassword,
    enabled: false
});
```
```server-php
<?php

use Appwrite\Client;
use Appwrite\Services\Project;
use Appwrite\Enums\ProjectAuthMethodId;

$client = new Client();

$client
    ->setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    ->setProject('<PROJECT_ID>')
    ->setKey('<YOUR_API_KEY>');

$project = new Project($client);

$result = $project->updateAuthMethod(
    methodId: ProjectAuthMethodId::EMAILPASSWORD(),
    enabled: false
);
```
```server-python
from appwrite.client import Client
from appwrite.services.project import Project
from appwrite.enums import ProjectAuthMethodId

client = Client()
client.set_endpoint('https://<REGION>.cloud.appwrite.io/v1')
client.set_project('<PROJECT_ID>')
client.set_key('<YOUR_API_KEY>')

project = Project(client)

result = project.update_auth_method(
    method_id = ProjectAuthMethodId.EMAIL_PASSWORD,
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

response = project.update_auth_method(
    method_id: ProjectAuthMethodId::EMAIL_PASSWORD,
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

var result = await project.UpdateAuthMethod(
    methodId: ProjectAuthMethodId.EmailPassword,
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

final result = await project.updateAuthMethod(
    methodId: enums.ProjectAuthMethodId.emailPassword,
    enabled: false,
);
```
```server-kotlin
import io.appwrite.Client
import io.appwrite.services.Project
import io.appwrite.enums.ProjectAuthMethodId

val client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1")
    .setProject("<PROJECT_ID>")
    .setKey("<YOUR_API_KEY>")

val project = Project(client)

val result = project.updateAuthMethod(
    methodId = ProjectAuthMethodId.EMAIL_PASSWORD,
    enabled = false
)
```
```server-java
import io.appwrite.Client;
import io.appwrite.coroutines.CoroutineCallback;
import io.appwrite.services.Project;
import io.appwrite.enums.ProjectAuthMethodId;

Client client = new Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1")
    .setProject("<PROJECT_ID>")
    .setKey("<YOUR_API_KEY>");

Project project = new Project(client);

project.updateAuthMethod(
    ProjectAuthMethodId.EMAIL_PASSWORD, // methodId
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

let result = try await project.updateAuthMethod(
    methodId: .emailPassword,
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

    project := appwrite.NewProject(client)
    result, err := project.UpdateAuthMethod(
        "email-password",
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
use appwrite::services::project::Project;
use appwrite::enums::ProjectAuthMethodId;

#[tokio::main]
async fn main() -> Result<(), Box<dyn std::error::Error>> {
    let client = Client::new()
        .set_endpoint("https://<REGION>.cloud.appwrite.io/v1")
        .set_project("<PROJECT_ID>")
        .set_key("<YOUR_API_KEY>");

    let project = Project::new(&client);

    let result = project.update_auth_method(
        ProjectAuthMethodId::EmailPassword,
        false,
    ).await?;

    println!("{:?}", result);
    Ok(())
}
```
```bash
appwrite project update-auth-method \
    --method-id email-password \
    --enabled false
```
{% /multicode %}

The endpoint returns the updated [Project](/docs/references/cloud/models/project) document with the new method state applied.

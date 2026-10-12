---
layout: article
title: SMTP
description: Configure a custom SMTP server to send Appwrite's account management emails from your own domain, improve deliverability, and unlock custom email templates.
---

By default, Appwrite sends account management emails such as verification, password recovery, and magic URL links from a shared SMTP server. Configuring a custom SMTP server lets you send these emails through your own provider instead. This sends mail from your own domain, improves deliverability, and unlocks [custom email templates](/docs/partners/project/email-templates).

# Configure in the Console {% #console %}

{% only_dark %}
![SMTP settings in the Appwrite Console](/images/docs/project/dark/smtp.avif)
{% /only_dark %}
{% only_light %}
![SMTP settings in the Appwrite Console](/images/docs/project/smtp.avif)
{% /only_light %}

To configure a custom SMTP server from the Appwrite Console:

1. Navigate to your project.
2. Open the **Settings** section and select the **SMTP** tab.
3. Enable **Custom SMTP server**.
4. Enter your provider's **Sender name**, **Sender email**, **Server host**, and **Server port**. Add a **Username** and **Password** if your provider requires authentication, set a **Reply to** address if needed, and choose the encryption protocol (TLS or SSL).
5. Click **Update**. Appwrite validates the connection to your SMTP server before saving, so the credentials must be correct.

# Configure with a Server SDK {% #server-sdks %}

You can also manage SMTP programmatically using a [Server SDK](/docs/sdks#server).

{% info title="Required scope" %}
The API key used for these calls needs the `project.write` scope.
{% /info %}

## Configure SMTP {% #configure-smtp %}

Every field is optional, so you can change only the values you need. Any field you omit keeps its current value. To enable SMTP, the project must have a `host`, `port`, and `senderEmail` set, either in this call or from a previous one. When you enable SMTP, Appwrite validates the connection to your server before saving. The encryption protocol accepts `tls` or `ssl`.

{% multicode %}
```server-nodejs
import { Client, Project, ProjectSMTPSecure } from 'node-appwrite';

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>') // Your project ID
    .setKey('<YOUR_API_KEY>'); // Your API key

const project = new Project(client);

const result = await project.updateSMTP({
    host: 'smtp.example.com',
    port: 587,
    username: '<USERNAME>', // optional
    password: '<PASSWORD>', // optional
    senderEmail: 'email@example.com',
    senderName: '<SENDER_NAME>', // optional
    replyToEmail: 'email@example.com', // optional
    replyToName: '<REPLY_TO_NAME>', // optional
    secure: ProjectSMTPSecure.Tls, // optional
    enabled: true
});
```
```server-deno
import { Client, Project, ProjectSMTPSecure } from "npm:node-appwrite";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>') // Your project ID
    .setKey('<YOUR_API_KEY>'); // Your API key

const project = new Project(client);

const result = await project.updateSMTP({
    host: 'smtp.example.com',
    port: 587,
    username: '<USERNAME>', // optional
    password: '<PASSWORD>', // optional
    senderEmail: 'email@example.com',
    senderName: '<SENDER_NAME>', // optional
    replyToEmail: 'email@example.com', // optional
    replyToName: '<REPLY_TO_NAME>', // optional
    secure: ProjectSMTPSecure.Tls, // optional
    enabled: true
});
```
```server-php
<?php

use Appwrite\Client;
use Appwrite\Services\Project;
use Appwrite\Enums\ProjectSMTPSecure;

$client = (new Client())
    ->setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    ->setProject('<YOUR_PROJECT_ID>') // Your project ID
    ->setKey('<YOUR_API_KEY>'); // Your API key

$project = new Project($client);

$result = $project->updateSMTP(
    host: 'smtp.example.com',
    port: 587,
    username: '<USERNAME>', // optional
    password: '<PASSWORD>', // optional
    senderEmail: 'email@example.com',
    senderName: '<SENDER_NAME>', // optional
    replyToEmail: 'email@example.com', // optional
    replyToName: '<REPLY_TO_NAME>', // optional
    secure: ProjectSMTPSecure::TLS(), // optional
    enabled: true
);
```
```server-python
from appwrite.client import Client
from appwrite.services.project import Project
from appwrite.models import Project as ProjectModel
from appwrite.enums import ProjectSMTPSecure

client = Client()
client.set_endpoint('https://<REGION>.cloud.appwrite.io/v1') # Your API Endpoint
client.set_project('<YOUR_PROJECT_ID>') # Your project ID
client.set_key('<YOUR_API_KEY>') # Your API key

project = Project(client)

result: ProjectModel = project.update_smtp(
    host = 'smtp.example.com',
    port = 587,
    username = '<USERNAME>', # optional
    password = '<PASSWORD>', # optional
    sender_email = 'email@example.com',
    sender_name = '<SENDER_NAME>', # optional
    reply_to_email = 'email@example.com', # optional
    reply_to_name = '<REPLY_TO_NAME>', # optional
    secure = ProjectSMTPSecure.TLS, # optional
    enabled = True
)

print(result.model_dump())
```
```server-ruby
require 'appwrite'

include Appwrite
include Appwrite::Enums

client = Client.new
    .set_endpoint('https://<REGION>.cloud.appwrite.io/v1') # Your API Endpoint
    .set_project('<YOUR_PROJECT_ID>') # Your project ID
    .set_key('<YOUR_API_KEY>') # Your API key

project = Project.new(client)

result = project.update_smtp(
    host: 'smtp.example.com',
    port: 587,
    username: '<USERNAME>', # optional
    password: '<PASSWORD>', # optional
    sender_email: 'email@example.com',
    sender_name: '<SENDER_NAME>', # optional
    reply_to_email: 'email@example.com', # optional
    reply_to_name: '<REPLY_TO_NAME>', # optional
    secure: ProjectSMTPSecure::TLS, # optional
    enabled: true
)
```
```server-dotnet
using Appwrite;
using Appwrite.Enums;
using Appwrite.Models;
using Appwrite.Services;

Client client = new Client()
    .SetEndPoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .SetProject("<YOUR_PROJECT_ID>") // Your project ID
    .SetKey("<YOUR_API_KEY>"); // Your API key

Project project = new Project(client);

Project result = await project.UpdateSMTP(
    host: "smtp.example.com",
    port: 587,
    username: "<USERNAME>", // optional
    password: "<PASSWORD>", // optional
    senderEmail: "email@example.com",
    senderName: "<SENDER_NAME>", // optional
    replyToEmail: "email@example.com", // optional
    replyToName: "<REPLY_TO_NAME>", // optional
    secure: ProjectSMTPSecure.Tls, // optional
    enabled: true
);
```
```server-dart
import 'package:dart_appwrite/dart_appwrite.dart';
import 'package:dart_appwrite/enums.dart' as enums;

Client client = Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>') // Your project ID
    .setKey('<YOUR_API_KEY>'); // Your API key

Project project = Project(client);

Project result = await project.updateSMTP(
    host: 'smtp.example.com',
    port: 587,
    username: '<USERNAME>', // (optional)
    password: '<PASSWORD>', // (optional)
    senderEmail: 'email@example.com',
    senderName: '<SENDER_NAME>', // (optional)
    replyToEmail: 'email@example.com', // (optional)
    replyToName: '<REPLY_TO_NAME>', // (optional)
    secure: enums.ProjectSMTPSecure.tls, // (optional)
    enabled: true,
);
```
```server-kotlin
import io.appwrite.Client
import io.appwrite.coroutines.CoroutineCallback
import io.appwrite.enums.ProjectSMTPSecure
import io.appwrite.services.Project

val client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<YOUR_PROJECT_ID>") // Your project ID
    .setKey("<YOUR_API_KEY>") // Your API key

val project = Project(client)

val response = project.updateSMTP(
    host = "smtp.example.com",
    port = 587,
    username = "<USERNAME>", // optional
    password = "<PASSWORD>", // optional
    senderEmail = "email@example.com",
    senderName = "<SENDER_NAME>", // optional
    replyToEmail = "email@example.com", // optional
    replyToName = "<REPLY_TO_NAME>", // optional
    secure = ProjectSMTPSecure.TLS, // optional
    enabled = true
)
```
```server-java
import io.appwrite.Client;
import io.appwrite.coroutines.CoroutineCallback;
import io.appwrite.enums.ProjectSMTPSecure;
import io.appwrite.services.Project;

Client client = new Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<YOUR_PROJECT_ID>") // Your project ID
    .setKey("<YOUR_API_KEY>"); // Your API key

Project project = new Project(client);

project.updateSMTP(
    "smtp.example.com", // host
    587, // port
    "<USERNAME>", // username (optional)
    "<PASSWORD>", // password (optional)
    "email@example.com", // senderEmail
    "<SENDER_NAME>", // senderName (optional)
    "email@example.com", // replyToEmail (optional)
    "<REPLY_TO_NAME>", // replyToName (optional)
    ProjectSMTPSecure.TLS, // secure (optional)
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
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<YOUR_PROJECT_ID>") // Your project ID
    .setKey("<YOUR_API_KEY>") // Your API key

let project = Project(client)

let result = try await project.updateSMTP(
    host: "smtp.example.com",
    port: 587,
    username: "<USERNAME>", // optional
    password: "<PASSWORD>", // optional
    senderEmail: "email@example.com",
    senderName: "<SENDER_NAME>", // optional
    replyToEmail: "email@example.com", // optional
    replyToName: "<REPLY_TO_NAME>", // optional
    secure: .tls, // optional
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
        appwrite.WithProject("<YOUR_PROJECT_ID>"),
        appwrite.WithKey("<YOUR_API_KEY>"),
    )

    project := appwrite.NewProject(client)
    result, err := project.UpdateSMTP(
        appwrite.WithUpdateSMTPHost("smtp.example.com"),
        appwrite.WithUpdateSMTPPort(587),
        appwrite.WithUpdateSMTPUsername("<USERNAME>"),
        appwrite.WithUpdateSMTPPassword("<PASSWORD>"),
        appwrite.WithUpdateSMTPSenderEmail("email@example.com"),
        appwrite.WithUpdateSMTPSenderName("<SENDER_NAME>"),
        appwrite.WithUpdateSMTPReplyToEmail("email@example.com"),
        appwrite.WithUpdateSMTPReplyToName("<REPLY_TO_NAME>"),
        appwrite.WithUpdateSMTPSecure("tls"),
        appwrite.WithUpdateSMTPEnabled(true),
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
use appwrite::enums::ProjectSMTPSecure;

#[tokio::main]
async fn main() -> Result<(), Box<dyn std::error::Error>> {
    let client = Client::new();
    client.set_endpoint("https://<REGION>.cloud.appwrite.io/v1"); // Your API Endpoint
    client.set_project("<YOUR_PROJECT_ID>"); // Your project ID
    client.set_key("<YOUR_API_KEY>"); // Your API key

    let project = Project::new(&client);

    let result = project.update_smtp(
        Some("smtp.example.com"),
        Some(587),
        Some("<USERNAME>"), // optional
        Some("<PASSWORD>"), // optional
        Some("email@example.com"),
        Some("<SENDER_NAME>"), // optional
        Some("email@example.com"), // optional
        Some("<REPLY_TO_NAME>"), // optional
        Some(ProjectSMTPSecure::Tls), // optional
        Some(true)
    ).await?;

    let _ = result;

    Ok(())
}
```
```bash
appwrite project update-smtp \
    --host smtp.example.com \
    --port 587 \
    --username "<USERNAME>" \
    --password "<PASSWORD>" \
    --sender-email email@example.com \
    --sender-name "<SENDER_NAME>" \
    --reply-to-email email@example.com \
    --reply-to-name "<REPLY_TO_NAME>" \
    --secure tls \
    --enabled true
```
{% /multicode %}

## Send a test email {% #send-test-email %}

Once SMTP is configured, send a test email to confirm that your server delivers mail. Appwrite sends a sample email to each address you provide, up to a maximum of 10 recipients.

{% multicode %}
```server-nodejs
import { Client, Project } from 'node-appwrite';

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>') // Your project ID
    .setKey('<YOUR_API_KEY>'); // Your API key

const project = new Project(client);

const result = await project.createSMTPTest({
    emails: ['email@example.com']
});
```
```server-deno
import { Client, Project } from "npm:node-appwrite";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>') // Your project ID
    .setKey('<YOUR_API_KEY>'); // Your API key

const project = new Project(client);

const result = await project.createSMTPTest({
    emails: ['email@example.com']
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

$result = $project->createSMTPTest(
    emails: ['email@example.com']
);
```
```server-python
from appwrite.client import Client
from appwrite.services.project import Project

client = Client()
client.set_endpoint('https://<REGION>.cloud.appwrite.io/v1') # Your API Endpoint
client.set_project('<YOUR_PROJECT_ID>') # Your project ID
client.set_key('<YOUR_API_KEY>') # Your API key

project = Project(client)

result = project.create_smtp_test(
    emails = ['email@example.com']
)
```
```server-ruby
require 'appwrite'

include Appwrite

client = Client.new
    .set_endpoint('https://<REGION>.cloud.appwrite.io/v1') # Your API Endpoint
    .set_project('<YOUR_PROJECT_ID>') # Your project ID
    .set_key('<YOUR_API_KEY>') # Your API key

project = Project.new(client)

result = project.create_smtp_test(
    emails: ['email@example.com']
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

await project.CreateSMTPTest(
    emails: new List<string> { "email@example.com" }
);
```
```server-dart
import 'package:dart_appwrite/dart_appwrite.dart';

Client client = Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>') // Your project ID
    .setKey('<YOUR_API_KEY>'); // Your API key

Project project = Project(client);

await project.createSMTPTest(
    emails: ['email@example.com'],
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

val response = project.createSMTPTest(
    emails = listOf("email@example.com")
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

project.createSMTPTest(
    List.of("email@example.com"), // emails
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

let result = try await project.createSMTPTest(
    emails: ["email@example.com"]
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
    response, err := project.CreateSMTPTest(
        []interface{}{"email@example.com"},
    )

    if err != nil {
        panic(err)
    }

    fmt.Println(response)
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

    project.create_smtp_test(
        vec!["email@example.com"]
    ).await?;

    Ok(())
}
```
```bash
appwrite project create-smtp-test \
    --emails email@example.com
```
{% /multicode %}

# Benefits {% #benefits %}

- **Send from your own domain.** Deliver account emails from your branded sender address instead of Appwrite's shared one, so users recognize and trust them.
- **Better deliverability.** Routing mail through an authenticated provider, with SPF and DKIM set up on your domain, reduces the chance of verification and recovery emails landing in spam.
- **Unlocks custom templates.** A custom SMTP server is required to customize [email templates](/docs/partners/project/email-templates) per locale.

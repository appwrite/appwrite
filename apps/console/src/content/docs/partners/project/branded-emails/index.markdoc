---
layout: article
title: White-label transactional emails
description: Route a customer's project emails through their own SMTP server and rebrand the email templates per locale with the Project API.
---

When a user on your customer's app resets their password, the email that lands in their inbox is part of your customer's brand, or it should be. Out of the box, those messages go through Appwrite's shared sender and carry Appwrite's default templates, which quietly breaks the white-label illusion the moment a user reads the "from" line. The Project API lets you route a project's mail through the customer's own SMTP server and rewrite every template, so verification, recovery, and invitation emails come from the customer's domain and read in their voice.

This guide:

- Points a project at the customer's SMTP server.
- Confirms that mail is reaching the inbox.
- Rebrands a template in the customer's voice.

{% info title="Required scopes" %}
The SMTP calls need the `project.write` scope, and the template calls need the `templates.write` scope.
{% /info %}

# Configure custom SMTP {% #configure-smtp %}

Set up the client, then hand the project the customer's SMTP credentials and the sender identity their recipients should see in the inbox. The password is write-only, so it is stored securely and never comes back in a response.

{% multicode %}
```server-nodejs
import { Client, Project, ProjectSMTPSecure } from 'node-appwrite';

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>')
    .setKey('<YOUR_API_KEY>');

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
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>')
    .setKey('<YOUR_API_KEY>');

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
    ->setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    ->setProject('<PROJECT_ID>')
    ->setKey('<YOUR_API_KEY>');

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
client.set_endpoint('https://<REGION>.cloud.appwrite.io/v1')
client.set_project('<PROJECT_ID>')
client.set_key('<YOUR_API_KEY>')

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
    .set_endpoint('https://<REGION>.cloud.appwrite.io/v1')
    .set_project('<PROJECT_ID>')
    .set_key('<YOUR_API_KEY>')

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
    .SetEndPoint("https://<REGION>.cloud.appwrite.io/v1")
    .SetProject("<PROJECT_ID>")
    .SetKey("<YOUR_API_KEY>");

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
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>')
    .setKey('<YOUR_API_KEY>');

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
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1")
    .setProject("<PROJECT_ID>")
    .setKey("<YOUR_API_KEY>")

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
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1")
    .setProject("<PROJECT_ID>")
    .setKey("<YOUR_API_KEY>");

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
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1")
    .setProject("<PROJECT_ID>")
    .setKey("<YOUR_API_KEY>")

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
        appwrite.WithProject("<PROJECT_ID>"),
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
    client.set_endpoint("https://<REGION>.cloud.appwrite.io/v1");
    client.set_project("<PROJECT_ID>");
    client.set_key("<YOUR_API_KEY>");

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
```http
PATCH /v1/project/smtp HTTP/1.1
Content-Type: application/json
X-Appwrite-Project: <PROJECT_ID>
X-Appwrite-Key: <YOUR_API_KEY>

{
  "host": "smtp.example.com",
  "port": 587,
  "username": "<USERNAME>",
  "password": "<PASSWORD>",
  "senderEmail": "email@example.com",
  "senderName": "<SENDER_NAME>",
  "replyToEmail": "email@example.com",
  "replyToName": "<REPLY_TO_NAME>",
  "secure": "tls",
  "enabled": true
}
```
{% /multicode %}

{% info title="Enabling SMTP validates the connection" %}
The moment you enable custom SMTP, Appwrite tries to connect to the host you gave it, so the server has to be reachable with those exact credentials or the call fails. Custom SMTP is also the prerequisite for everything below: you cannot customize email templates until it is enabled.
{% /info %}

# Send a test email {% #test-smtp %}

Do not wait for a real password reset to find out the configuration is wrong. Send yourself a test message, which goes out through the SMTP settings you just saved, and a message arriving in the inbox confirms the whole path end to end.

{% multicode %}
```server-nodejs
const result = await project.createSMTPTest({
    emails: ['email@example.com']
});
```
```server-deno
const result = await project.createSMTPTest({
    emails: ['email@example.com']
});
```
```server-php
$result = $project->createSMTPTest(
    emails: ['email@example.com']
);
```
```server-python
result = project.create_smtp_test(
    emails = ['email@example.com']
)
```
```server-ruby
result = project.create_smtp_test(
    emails: ['email@example.com']
)
```
```server-dotnet
await project.CreateSMTPTest(
    emails: new List<string> { "email@example.com" }
);
```
```server-dart
await project.createSMTPTest(
    emails: ['email@example.com'],
);
```
```server-kotlin
val response = project.createSMTPTest(
    emails = listOf("email@example.com")
)
```
```server-java
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
let result = try await project.createSMTPTest(
    emails: ["email@example.com"]
)
```
```server-go
response, err := project.CreateSMTPTest(
    []interface{}{"email@example.com"},
)

if err != nil {
    panic(err)
}

fmt.Println(response)
```
```server-rust
project.create_smtp_test(
    vec!["email@example.com"]
).await?;
```
```bash
appwrite project create-smtp-test \
    --emails email@example.com
```
```http
POST /v1/project/smtp/tests HTTP/1.1
Content-Type: application/json
X-Appwrite-Project: <PROJECT_ID>
X-Appwrite-Key: <YOUR_API_KEY>

{
  "emails": ["email@example.com"]
}
```
{% /multicode %}

# Customize a template {% #customize-template %}

With mail flowing through the customer's server, rebrand the messages themselves. Set the subject, body, and sender for a template in a given locale, and repeat per locale to localize. This example rewrites the verification email.

{% multicode %}
```server-nodejs
const result = await project.updateEmailTemplate({
    templateId: ProjectEmailTemplateId.Verification,
    locale: ProjectEmailTemplateLocale.En, // optional
    subject: '<SUBJECT>', // optional
    message: '<MESSAGE>', // optional
    senderName: '<SENDER_NAME>', // optional
    senderEmail: 'email@example.com', // optional
    replyToEmail: 'email@example.com', // optional
    replyToName: '<REPLY_TO_NAME>' // optional
});
```
```server-deno
const result = await project.updateEmailTemplate({
    templateId: ProjectEmailTemplateId.Verification,
    locale: ProjectEmailTemplateLocale.En, // optional
    subject: '<SUBJECT>', // optional
    message: '<MESSAGE>', // optional
    senderName: '<SENDER_NAME>', // optional
    senderEmail: 'email@example.com', // optional
    replyToEmail: 'email@example.com', // optional
    replyToName: '<REPLY_TO_NAME>' // optional
});
```
```server-php
$result = $project->updateEmailTemplate(
    templateId: ProjectEmailTemplateId::VERIFICATION(),
    locale: ProjectEmailTemplateLocale::EN(), // optional
    subject: '<SUBJECT>', // optional
    message: '<MESSAGE>', // optional
    senderName: '<SENDER_NAME>', // optional
    senderEmail: 'email@example.com', // optional
    replyToEmail: 'email@example.com', // optional
    replyToName: '<REPLY_TO_NAME>' // optional
);
```
```server-python
result: EmailTemplate = project.update_email_template(
    template_id = ProjectEmailTemplateId.VERIFICATION,
    locale = ProjectEmailTemplateLocale.EN, # optional
    subject = '<SUBJECT>', # optional
    message = '<MESSAGE>', # optional
    sender_name = '<SENDER_NAME>', # optional
    sender_email = 'email@example.com', # optional
    reply_to_email = 'email@example.com', # optional
    reply_to_name = '<REPLY_TO_NAME>' # optional
)

print(result.model_dump())
```
```server-ruby
result = project.update_email_template(
    template_id: ProjectEmailTemplateId::VERIFICATION,
    locale: ProjectEmailTemplateLocale::EN, # optional
    subject: '<SUBJECT>', # optional
    message: '<MESSAGE>', # optional
    sender_name: '<SENDER_NAME>', # optional
    sender_email: 'email@example.com', # optional
    reply_to_email: 'email@example.com', # optional
    reply_to_name: '<REPLY_TO_NAME>' # optional
)
```
```server-dotnet
EmailTemplate result = await project.UpdateEmailTemplate(
    templateId: ProjectEmailTemplateId.Verification,
    locale: ProjectEmailTemplateLocale.En, // optional
    subject: "<SUBJECT>", // optional
    message: "<MESSAGE>", // optional
    senderName: "<SENDER_NAME>", // optional
    senderEmail: "email@example.com", // optional
    replyToEmail: "email@example.com", // optional
    replyToName: "<REPLY_TO_NAME>" // optional
);
```
```server-dart
EmailTemplate result = await project.updateEmailTemplate(
    templateId: enums.ProjectEmailTemplateId.verification,
    locale: enums.ProjectEmailTemplateLocale.en, // (optional)
    subject: '<SUBJECT>', // (optional)
    message: '<MESSAGE>', // (optional)
    senderName: '<SENDER_NAME>', // (optional)
    senderEmail: 'email@example.com', // (optional)
    replyToEmail: 'email@example.com', // (optional)
    replyToName: '<REPLY_TO_NAME>', // (optional)
);
```
```server-kotlin
val response = project.updateEmailTemplate(
    templateId = ProjectEmailTemplateId.VERIFICATION,
    locale = ProjectEmailTemplateLocale.EN, // optional
    subject = "<SUBJECT>", // optional
    message = "<MESSAGE>", // optional
    senderName = "<SENDER_NAME>", // optional
    senderEmail = "email@example.com", // optional
    replyToEmail = "email@example.com", // optional
    replyToName = "<REPLY_TO_NAME>" // optional
)
```
```server-java
project.updateEmailTemplate(
    ProjectEmailTemplateId.VERIFICATION, // templateId
    ProjectEmailTemplateLocale.EN, // locale (optional)
    "<SUBJECT>", // subject (optional)
    "<MESSAGE>", // message (optional)
    "<SENDER_NAME>", // senderName (optional)
    "email@example.com", // senderEmail (optional)
    "email@example.com", // replyToEmail (optional)
    "<REPLY_TO_NAME>", // replyToName (optional)
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
let emailTemplate = try await project.updateEmailTemplate(
    templateId: .verification,
    locale: .en, // optional
    subject: "<SUBJECT>", // optional
    message: "<MESSAGE>", // optional
    senderName: "<SENDER_NAME>", // optional
    senderEmail: "email@example.com", // optional
    replyToEmail: "email@example.com", // optional
    replyToName: "<REPLY_TO_NAME>" // optional
)
```
```server-go
result, err := project.UpdateEmailTemplate(
    "verification",
    appwrite.WithUpdateEmailTemplateLocale("en"),
    appwrite.WithUpdateEmailTemplateSubject("<SUBJECT>"),
    appwrite.WithUpdateEmailTemplateMessage("<MESSAGE>"),
    appwrite.WithUpdateEmailTemplateSenderName("<SENDER_NAME>"),
    appwrite.WithUpdateEmailTemplateSenderEmail("email@example.com"),
    appwrite.WithUpdateEmailTemplateReplyToEmail("email@example.com"),
    appwrite.WithUpdateEmailTemplateReplyToName("<REPLY_TO_NAME>"),
)

if err != nil {
    panic(err)
}

fmt.Println(result)
```
```server-rust
let result = project.update_email_template(
    ProjectEmailTemplateId::Verification,
    Some(ProjectEmailTemplateLocale::En), // optional
    Some("<SUBJECT>"), // optional
    Some("<MESSAGE>"), // optional
    Some("<SENDER_NAME>"), // optional
    Some("email@example.com"), // optional
    Some("email@example.com"), // optional
    Some("<REPLY_TO_NAME>") // optional
).await?;

let _ = result;
```
```bash
appwrite project update-email-template \
    --template-id verification \
    --locale en \
    --subject "<SUBJECT>" \
    --message "<MESSAGE>" \
    --sender-name "<SENDER_NAME>" \
    --sender-email email@example.com \
    --reply-to-email email@example.com \
    --reply-to-name "<REPLY_TO_NAME>"
```
```http
PATCH /v1/project/templates/email HTTP/1.1
Content-Type: application/json
X-Appwrite-Project: <PROJECT_ID>
X-Appwrite-Key: <YOUR_API_KEY>

{
  "templateId": "verification",
  "locale": "en",
  "subject": "<SUBJECT>",
  "message": "<MESSAGE>",
  "senderName": "<SENDER_NAME>",
  "senderEmail": "email@example.com",
  "replyToEmail": "email@example.com",
  "replyToName": "<REPLY_TO_NAME>"
}
```
{% /multicode %}

The template ID is one of `verification`, `magicSession`, `recovery`, `invitation`, `mfaChallenge`, `sessionAlert`, or `otpSession`.

# Read templates back {% #read-templates %}

Read a template back to check the copy that is live, which is worth doing whenever you let a customer edit their own templates through your dashboard.

{% multicode %}
```server-nodejs
const result = await project.getEmailTemplate({
    templateId: ProjectEmailTemplateId.Verification,
    locale: ProjectEmailTemplateLocale.En // optional
});
```
```server-deno
const result = await project.getEmailTemplate({
    templateId: ProjectEmailTemplateId.Verification,
    locale: ProjectEmailTemplateLocale.En // optional
});
```
```server-php
$result = $project->getEmailTemplate(
    templateId: ProjectEmailTemplateId::VERIFICATION(),
    locale: ProjectEmailTemplateLocale::EN() // optional
);
```
```server-python
result: EmailTemplate = project.get_email_template(
    template_id = ProjectEmailTemplateId.VERIFICATION,
    locale = ProjectEmailTemplateLocale.EN # optional
)

print(result.model_dump())
```
```server-ruby
result = project.get_email_template(
    template_id: ProjectEmailTemplateId::VERIFICATION,
    locale: ProjectEmailTemplateLocale::EN # optional
)
```
```server-dotnet
EmailTemplate result = await project.GetEmailTemplate(
    templateId: ProjectEmailTemplateId.Verification,
    locale: ProjectEmailTemplateLocale.En // optional
);
```
```server-dart
EmailTemplate result = await project.getEmailTemplate(
    templateId: enums.ProjectEmailTemplateId.verification,
    locale: enums.ProjectEmailTemplateLocale.en, // (optional)
);
```
```server-kotlin
val response = project.getEmailTemplate(
    templateId = ProjectEmailTemplateId.VERIFICATION,
    locale = ProjectEmailTemplateLocale.EN // optional
)
```
```server-java
project.getEmailTemplate(
    ProjectEmailTemplateId.VERIFICATION, // templateId
    ProjectEmailTemplateLocale.EN, // locale (optional)
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
let emailTemplate = try await project.getEmailTemplate(
    templateId: .verification,
    locale: .en // optional
)
```
```server-go
result, err := project.GetEmailTemplate(
    "verification",
    appwrite.WithGetEmailTemplateLocale("en"),
)

if err != nil {
    panic(err)
}

fmt.Println(result)
```
```server-rust
let result = project.get_email_template(
    ProjectEmailTemplateId::Verification,
    Some(ProjectEmailTemplateLocale::En) // optional
).await?;

let _ = result;
```
```bash
appwrite project get-email-template \
    --template-id verification \
    --locale en
```
```http
GET /v1/project/templates/email/verification?locale=en HTTP/1.1
X-Appwrite-Project: <PROJECT_ID>
X-Appwrite-Key: <YOUR_API_KEY>
```
{% /multicode %}

Or list the project's templates to see everything you have overridden in one call.

{% multicode %}
```server-nodejs
const result = await project.listEmailTemplates({
    queries: [], // optional
    total: false // optional
});
```
```server-deno
const result = await project.listEmailTemplates({
    queries: [], // optional
    total: false // optional
});
```
```server-php
$result = $project->listEmailTemplates(
    queries: [], // optional
    total: false // optional
);
```
```server-python
result: EmailTemplateList = project.list_email_templates(
    queries = [], # optional
    total = False # optional
)

print(result.model_dump())
```
```server-ruby
result = project.list_email_templates(
    queries: [], # optional
    total: false # optional
)
```
```server-dotnet
EmailTemplateList result = await project.ListEmailTemplates(
    queries: new List<string>(), // optional
    total: false // optional
);
```
```server-dart
EmailTemplateList result = await project.listEmailTemplates(
    queries: [], // (optional)
    total: false, // (optional)
);
```
```server-kotlin
val response = project.listEmailTemplates(
    queries = listOf(), // optional
    total = false // optional
)
```
```server-java
project.listEmailTemplates(
    List.of(), // queries (optional)
    false, // total (optional)
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
let emailTemplateList = try await project.listEmailTemplates(
    queries: [], // optional
    total: false // optional
)
```
```server-go
result, err := project.ListEmailTemplates(
    appwrite.WithListEmailTemplatesQueries([]interface{}{}),
    appwrite.WithListEmailTemplatesTotal(false),
)

if err != nil {
    panic(err)
}

fmt.Println(result)
```
```server-rust
let result = project.list_email_templates(
    Some(vec![]), // optional
    Some(false) // optional
).await?;

let _ = result;
```
```bash
appwrite project list-email-templates
```
```http
GET /v1/project/templates/email HTTP/1.1
X-Appwrite-Project: <PROJECT_ID>
X-Appwrite-Key: <YOUR_API_KEY>
```
{% /multicode %}

# Next steps {% #next-steps %}

With SMTP and templates driven from code, every customer's project sends mail that carries their brand rather than yours. From here:

- [Provision a project's baseline](/docs/partners/project/provisioning) so branding the emails is one step in onboarding.
- Read the [SMTP](/docs/partners/project/smtp) and [Email templates](/docs/partners/project/email-templates) references for every field you can set.

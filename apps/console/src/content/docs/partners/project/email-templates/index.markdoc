---
layout: article
title: Email templates
description: Customize the account management emails Appwrite sends to your users, including verification, password recovery, and magic URL emails, per locale.
---

Appwrite sends transactional emails on your behalf for account management flows such as email verification, password recovery, and magic URL sign-in. Email templates let you customize the subject, message body, sender identity, and reply-to address of each of these emails, with a separate version for every locale you support.

{% info title="Custom SMTP required to save changes" %}
You can view the built-in default templates at any time, but saving a customization requires a custom SMTP server enabled on your project. See [Custom SMTP server](/docs/products/auth/message-templates#smtp) to set one up.
{% /info %}

# Manage in the Console {% #console %}

{% only_dark %}
![Email templates in the Appwrite Console](/images/docs/project/dark/email-templates.avif)
{% /only_dark %}
{% only_light %}
![Email templates in the Appwrite Console](/images/docs/project/email-templates.avif)
{% /only_light %}

To edit email templates from the Appwrite Console:

1. Navigate to your project.
2. Open the **Auth** section and select the **Templates** tab.
3. Under **Email templates**, expand the template you want to edit, such as **Verification** or **Reset password**.
4. Choose a **Template language**, then edit the sender name, sender email, reply-to address, subject, and message. Use the variable chips (`{{user}}`, `{{project}}`, `{{redirect}}`) to insert dynamic values into the subject and message.
5. Click **Update** to save the template for the selected language.

# Manage with a Server SDK {% #server-sdks %}

You can also manage email templates programmatically using a [Server SDK](/docs/sdks#server).

{% info title="Required scopes" %}
The API key used for these calls needs `templates.read` to list or fetch templates, and `templates.write` to update them.
{% /info %}

## List email templates {% #list-email-templates %}

You can paginate the result with the `limit` and `offset` queries. See [Queries](/docs/products/databases/queries) for the query syntax.

{% multicode %}
```server-nodejs
import { Client, Project } from 'node-appwrite';

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>') // Your project ID
    .setKey('<YOUR_API_KEY>'); // Your API key

const project = new Project(client);

const result = await project.listEmailTemplates({
    queries: [], // optional
    total: false // optional
});
```
```server-deno
import { Client, Project } from "npm:node-appwrite";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>') // Your project ID
    .setKey('<YOUR_API_KEY>'); // Your API key

const project = new Project(client);

const result = await project.listEmailTemplates({
    queries: [], // optional
    total: false // optional
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

$result = $project->listEmailTemplates(
    queries: [], // optional
    total: false // optional
);
```
```server-python
from appwrite.client import Client
from appwrite.services.project import Project
from appwrite.models import EmailTemplateList

client = Client()
client.set_endpoint('https://<REGION>.cloud.appwrite.io/v1') # Your API Endpoint
client.set_project('<YOUR_PROJECT_ID>') # Your project ID
client.set_key('<YOUR_API_KEY>') # Your API key

project = Project(client)

result: EmailTemplateList = project.list_email_templates(
    queries = [], # optional
    total = False # optional
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

result = project.list_email_templates(
    queries: [], # optional
    total: false # optional
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

EmailTemplateList result = await project.ListEmailTemplates(
    queries: new List<string>(), // optional
    total: false // optional
);
```
```server-dart
import 'package:dart_appwrite/dart_appwrite.dart';

Client client = Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>') // Your project ID
    .setKey('<YOUR_API_KEY>'); // Your API key

Project project = Project(client);

EmailTemplateList result = await project.listEmailTemplates(
    queries: [], // (optional)
    total: false, // (optional)
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

val response = project.listEmailTemplates(
    queries = listOf(), // optional
    total = false // optional
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
import Appwrite

let client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<YOUR_PROJECT_ID>") // Your project ID
    .setKey("<YOUR_API_KEY>") // Your API key

let project = Project(client)

let emailTemplateList = try await project.listEmailTemplates(
    queries: [], // optional
    total: false // optional
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
    result, err := project.ListEmailTemplates(
        appwrite.WithListEmailTemplatesQueries([]interface{}{}),
        appwrite.WithListEmailTemplatesTotal(false),
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

    let result = project.list_email_templates(
        Some(vec![]), // optional
        Some(false) // optional
    ).await?;

    let _ = result;

    Ok(())
}
```
```bash
appwrite project list-email-templates
```
{% /multicode %}

## Get an email template {% #get-email-template %}

Retrieve a single template by its type and, optionally, locale. If you omit the locale, the fallback locale `en` is returned.

{% multicode %}
```server-nodejs
import { Client, Project, ProjectEmailTemplateId, ProjectEmailTemplateLocale } from 'node-appwrite';

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>') // Your project ID
    .setKey('<YOUR_API_KEY>'); // Your API key

const project = new Project(client);

const result = await project.getEmailTemplate({
    templateId: ProjectEmailTemplateId.Verification,
    locale: ProjectEmailTemplateLocale.En // optional
});
```
```server-deno
import { Client, Project, ProjectEmailTemplateId, ProjectEmailTemplateLocale } from "npm:node-appwrite";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>') // Your project ID
    .setKey('<YOUR_API_KEY>'); // Your API key

const project = new Project(client);

const result = await project.getEmailTemplate({
    templateId: ProjectEmailTemplateId.Verification,
    locale: ProjectEmailTemplateLocale.En // optional
});
```
```server-php
<?php

use Appwrite\Client;
use Appwrite\Services\Project;
use Appwrite\Enums\ProjectEmailTemplateId;
use Appwrite\Enums\ProjectEmailTemplateLocale;

$client = (new Client())
    ->setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    ->setProject('<YOUR_PROJECT_ID>') // Your project ID
    ->setKey('<YOUR_API_KEY>'); // Your API key

$project = new Project($client);

$result = $project->getEmailTemplate(
    templateId: ProjectEmailTemplateId::VERIFICATION(),
    locale: ProjectEmailTemplateLocale::EN() // optional
);
```
```server-python
from appwrite.client import Client
from appwrite.services.project import Project
from appwrite.models import EmailTemplate
from appwrite.enums import ProjectEmailTemplateId
from appwrite.enums import ProjectEmailTemplateLocale

client = Client()
client.set_endpoint('https://<REGION>.cloud.appwrite.io/v1') # Your API Endpoint
client.set_project('<YOUR_PROJECT_ID>') # Your project ID
client.set_key('<YOUR_API_KEY>') # Your API key

project = Project(client)

result: EmailTemplate = project.get_email_template(
    template_id = ProjectEmailTemplateId.VERIFICATION,
    locale = ProjectEmailTemplateLocale.EN # optional
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

result = project.get_email_template(
    template_id: ProjectEmailTemplateId::VERIFICATION,
    locale: ProjectEmailTemplateLocale::EN # optional
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

EmailTemplate result = await project.GetEmailTemplate(
    templateId: ProjectEmailTemplateId.Verification,
    locale: ProjectEmailTemplateLocale.En // optional
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

EmailTemplate result = await project.getEmailTemplate(
    templateId: enums.ProjectEmailTemplateId.verification,
    locale: enums.ProjectEmailTemplateLocale.en, // (optional)
);
```
```server-kotlin
import io.appwrite.Client
import io.appwrite.coroutines.CoroutineCallback
import io.appwrite.enums.ProjectEmailTemplateId
import io.appwrite.enums.ProjectEmailTemplateLocale
import io.appwrite.services.Project

val client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<YOUR_PROJECT_ID>") // Your project ID
    .setKey("<YOUR_API_KEY>") // Your API key

val project = Project(client)

val response = project.getEmailTemplate(
    templateId = ProjectEmailTemplateId.VERIFICATION,
    locale = ProjectEmailTemplateLocale.EN // optional
)
```
```server-java
import io.appwrite.Client;
import io.appwrite.coroutines.CoroutineCallback;
import io.appwrite.enums.ProjectEmailTemplateId;
import io.appwrite.enums.ProjectEmailTemplateLocale;
import io.appwrite.services.Project;

Client client = new Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<YOUR_PROJECT_ID>") // Your project ID
    .setKey("<YOUR_API_KEY>"); // Your API key

Project project = new Project(client);

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
import Appwrite
import AppwriteEnums

let client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<YOUR_PROJECT_ID>") // Your project ID
    .setKey("<YOUR_API_KEY>") // Your API key

let project = Project(client)

let emailTemplate = try await project.getEmailTemplate(
    templateId: .verification,
    locale: .en // optional
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
    result, err := project.GetEmailTemplate(
        "verification",
        appwrite.WithGetEmailTemplateLocale("en"),
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
use appwrite::enums::ProjectEmailTemplateId;
use appwrite::enums::ProjectEmailTemplateLocale;

#[tokio::main]
async fn main() -> Result<(), Box<dyn std::error::Error>> {
    let client = Client::new();
    client.set_endpoint("https://<REGION>.cloud.appwrite.io/v1"); // Your API Endpoint
    client.set_project("<YOUR_PROJECT_ID>"); // Your project ID
    client.set_key("<YOUR_API_KEY>"); // Your API key

    let project = Project::new(&client);

    let result = project.get_email_template(
        ProjectEmailTemplateId::Verification,
        Some(ProjectEmailTemplateLocale::En) // optional
    ).await?;

    let _ = result;

    Ok(())
}
```
```bash
appwrite project get-email-template \
    --template-id verification \
    --locale en
```
{% /multicode %}

## Update an email template {% #update-email-template %}

Customize a template's subject, message, and sender identity for a given type and locale. Every field is optional, so you can change only the values you need. The `subject` and `message` accept the `{{user}}`, `{{project}}`, and `{{redirect}}` variables, which Appwrite replaces when sending the email.

{% multicode %}
```server-nodejs
import { Client, Project, ProjectEmailTemplateId, ProjectEmailTemplateLocale } from 'node-appwrite';

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>') // Your project ID
    .setKey('<YOUR_API_KEY>'); // Your API key

const project = new Project(client);

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
import { Client, Project, ProjectEmailTemplateId, ProjectEmailTemplateLocale } from "npm:node-appwrite";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>') // Your project ID
    .setKey('<YOUR_API_KEY>'); // Your API key

const project = new Project(client);

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
<?php

use Appwrite\Client;
use Appwrite\Services\Project;
use Appwrite\Enums\ProjectEmailTemplateId;
use Appwrite\Enums\ProjectEmailTemplateLocale;

$client = (new Client())
    ->setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    ->setProject('<YOUR_PROJECT_ID>') // Your project ID
    ->setKey('<YOUR_API_KEY>'); // Your API key

$project = new Project($client);

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
from appwrite.client import Client
from appwrite.services.project import Project
from appwrite.models import EmailTemplate
from appwrite.enums import ProjectEmailTemplateId
from appwrite.enums import ProjectEmailTemplateLocale

client = Client()
client.set_endpoint('https://<REGION>.cloud.appwrite.io/v1') # Your API Endpoint
client.set_project('<YOUR_PROJECT_ID>') # Your project ID
client.set_key('<YOUR_API_KEY>') # Your API key

project = Project(client)

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
require 'appwrite'

include Appwrite
include Appwrite::Enums

client = Client.new
    .set_endpoint('https://<REGION>.cloud.appwrite.io/v1') # Your API Endpoint
    .set_project('<YOUR_PROJECT_ID>') # Your project ID
    .set_key('<YOUR_API_KEY>') # Your API key

project = Project.new(client)

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
using Appwrite;
using Appwrite.Enums;
using Appwrite.Models;
using Appwrite.Services;

Client client = new Client()
    .SetEndPoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .SetProject("<YOUR_PROJECT_ID>") // Your project ID
    .SetKey("<YOUR_API_KEY>"); // Your API key

Project project = new Project(client);

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
import 'package:dart_appwrite/dart_appwrite.dart';
import 'package:dart_appwrite/enums.dart' as enums;

Client client = Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>') // Your project ID
    .setKey('<YOUR_API_KEY>'); // Your API key

Project project = Project(client);

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
import io.appwrite.Client
import io.appwrite.coroutines.CoroutineCallback
import io.appwrite.enums.ProjectEmailTemplateId
import io.appwrite.enums.ProjectEmailTemplateLocale
import io.appwrite.services.Project

val client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<YOUR_PROJECT_ID>") // Your project ID
    .setKey("<YOUR_API_KEY>") // Your API key

val project = Project(client)

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
import io.appwrite.Client;
import io.appwrite.coroutines.CoroutineCallback;
import io.appwrite.enums.ProjectEmailTemplateId;
import io.appwrite.enums.ProjectEmailTemplateLocale;
import io.appwrite.services.Project;

Client client = new Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<YOUR_PROJECT_ID>") // Your project ID
    .setKey("<YOUR_API_KEY>"); // Your API key

Project project = new Project(client);

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
import Appwrite
import AppwriteEnums

let client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<YOUR_PROJECT_ID>") // Your project ID
    .setKey("<YOUR_API_KEY>") // Your API key

let project = Project(client)

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
}
```
```server-rust
use appwrite::Client;
use appwrite::services::Project;
use appwrite::enums::ProjectEmailTemplateId;
use appwrite::enums::ProjectEmailTemplateLocale;

#[tokio::main]
async fn main() -> Result<(), Box<dyn std::error::Error>> {
    let client = Client::new();
    client.set_endpoint("https://<REGION>.cloud.appwrite.io/v1"); // Your API Endpoint
    client.set_project("<YOUR_PROJECT_ID>"); // Your project ID
    client.set_key("<YOUR_API_KEY>"); // Your API key

    let project = Project::new(&client);

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

    Ok(())
}
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
{% /multicode %}

# Template types {% #template-types %}

Each email template is identified by a `templateId`. The available types map to the account management emails Appwrite sends:

| Console name | Template ID | Sent when |
|------------------|----------------|------------------------------------------------------------|
| Verification | `verification` | A user requests email address verification. |
| Magic URL | `magicSession` | A user signs in with a magic URL link. |
| OTP session | `otpSession` | A user signs in with an email one-time password. |
| Reset password | `recovery` | A user starts the password recovery flow. |
| Invite user | `invitation` | A user is invited to join a team. |
| 2FA verification | `mfaChallenge` | A user completes an email-based multi-factor challenge. |
| Session alert | `sessionAlert` | A new session is created on a user's account. |

# Locales {% #locales %}

Templates are stored per locale, so you can send the right copy to each audience. Pass the `locale` parameter to read or write the version for a specific language. If you omit it, Appwrite uses the fallback locale `en`. Locale codes follow the ISO 639-1 standard with optional region suffixes, such as `en`, `fr`, `de`, or `pt-br`.

# Benefits {% #benefits %}

- **On-brand emails.** Replace the default sender name, address, subject, and message so account emails match your product instead of generic Appwrite copy.
- **Localized messaging.** Maintain a separate version of each template per locale and reach every user in their own language.
- **Repeatable provisioning.** Script template content as code and apply the same configuration across dev, staging, and production projects without clicking through the Console.

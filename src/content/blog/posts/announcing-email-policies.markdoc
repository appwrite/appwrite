---
layout: post
title: "Announcing Email policies: Block free, aliased, and disposable emails at signup"
description: Email policies let you restrict which email addresses can sign up for your Appwrite project. Block free providers, aliased addresses, and disposable inboxes from the Console or any server SDK.
date: 2026-05-20
cover: /images/blog/announcing-email-policies/cover.avif
timeToRead: 4
author: matej-baco
category: announcement
featured: false
callToAction: true
faqs:
  - question: "Do email policies affect users who signed up before the policy was enabled?"
    answer: "No. Email policies only run at sign-up and on email updates. Existing users can still log in with their current address even if that address would no longer pass the policy."
  - question: "What scope does an API key need to update email policies?"
    answer: "The API key needs the policies.write scope. Reading the current policy state requires policies.read."
  - question: "Does the aliased emails policy cover providers other than Gmail?"
    answer: "Yes. The policy blocks subaddresses, tags, and provider-specific variations across providers, not just Gmail. Anything that maps to the same underlying inbox via well-known alias syntax is rejected."
  - question: "Can I enable just one of the three email policies instead of all of them?"
    answer: "Yes. Each policy is an independent toggle. You can enable any combination of deny free, deny aliased, and deny disposable from the Console or by calling only the methods you need."
  - question: "How do I fight spam users?"
    answer: "Email policies are the first line of defense. Enable deny disposable emails to block temporary inboxes like Mailinator, deny aliased emails to stop one user from spinning up dozens of accounts from a single Gmail with subaddresses, and deny free emails on B2B products that should only accept corporate addresses."
  - question: "Which email providers does Appwrite block?"
    answer: "The lists are powered by our open-source utopia-php/emails database, which currently tracks over 72,000 disposable domains and 4,000 free email providers. The data is continuously refreshed from six upstream community sources, with a manual override layer on top for edge cases the upstream lists miss."
  - question: "Can I add a missing email provider to the blocklist?"
    answer: "Yes. The utopia-php/emails repository is open source and accepts contributions. Open a PR against the manual list in the data directory and the next import picks it up."
---

Most teams discover the hard way that signups happen with whatever address a user feels like typing. Throwaway inboxes, plus-tagged aliases of a single Gmail, and ten-minute mail providers all create real accounts, count toward seat quotas, and eventually show up in your support queue with mail that never delivers.

Today, we are announcing **Email policies** for Appwrite Auth. You can now restrict which email addresses are accepted for user creation and email updates on a project, either from the Appwrite Console or programmatically through any server SDK.

# What you can block

Email policies expose three independent toggles, each targeting a different category of address:

- **Free email providers** like Gmail, Yahoo, and Outlook, so trials and seats stay tied to a real organization
- **Aliased addresses** such as subaddresses, tags, and provider-specific variations of the same inbox, so one user cannot quietly create twenty accounts from one mailbox
- **Disposable providers** like Mailinator and other temporary inbox services, so abandoned accounts and undeliverable mail stop landing in your users table

Each policy is its own toggle. Enabling one does not enable the others, so a B2B product can require corporate addresses without also blocking disposable ones, and a consumer product can keep free providers open while shutting out throwaway inboxes.

The policies run at signup and on email updates. They do not affect session creation, so any user who signed up before a policy was enabled can still log in.

# Backed by a curated provider database

Email policies do not ship with a hard-coded list of a few dozen well-known providers. They are powered by [utopia-php/emails](https://github.com/utopia-php/emails), an open-source database we maintain that currently tracks **72,906 disposable domains** and **4,785 free email providers**. The lists are continuously refreshed from six upstream community sources, on top of a manual override layer for edge cases that the upstream lists miss or get wrong.

The data lives in the open and accepts contributions, so if you spot a provider that should be blocked or unblocked you can [open a PR against the manual list](https://github.com/utopia-php/emails/tree/main/data) and the next import picks it up.

# Configure from the Console

Open your project, navigate to **Auth** in the sidebar, and open the **Security** tab. The **Email policies** card has a toggle for each policy and an **Update** button that applies your changes immediately.

![Email policies card in the Appwrite Console](/images/docs/auth/email-policies/dark/policies.avif)

# Configure from the SDK

Each policy has a dedicated method on the Project service. The body is always an `enabled` boolean.

The API key needs the `policies.write` scope.

{% multicode %}
```server-nodejs
import { Client, Project } from 'node-appwrite';

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>')
    .setKey('<YOUR_API_KEY>');

const project = new Project(client);

await project.updateDenyFreeEmailPolicy({ enabled: true });
await project.updateDenyAliasedEmailPolicy({ enabled: true });
await project.updateDenyDisposableEmailPolicy({ enabled: true });
```
```server-deno
import { Client, Project } from "npm:node-appwrite";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>')
    .setKey('<YOUR_API_KEY>');

const project = new Project(client);

await project.updateDenyFreeEmailPolicy({ enabled: true });
await project.updateDenyAliasedEmailPolicy({ enabled: true });
await project.updateDenyDisposableEmailPolicy({ enabled: true });
```
```server-php
<?php

use Appwrite\Client;
use Appwrite\Services\Project;

$client = new Client();

$client
    ->setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    ->setProject('<PROJECT_ID>')
    ->setKey('<YOUR_API_KEY>');

$project = new Project($client);

$project->updateDenyFreeEmailPolicy(enabled: true);
$project->updateDenyAliasedEmailPolicy(enabled: true);
$project->updateDenyDisposableEmailPolicy(enabled: true);
```
```server-python
from appwrite.client import Client
from appwrite.services.project import Project

client = Client()
client.set_endpoint('https://<REGION>.cloud.appwrite.io/v1')
client.set_project('<PROJECT_ID>')
client.set_key('<YOUR_API_KEY>')

project = Project(client)

project.update_deny_free_email_policy(enabled=True)
project.update_deny_aliased_email_policy(enabled=True)
project.update_deny_disposable_email_policy(enabled=True)
```
```server-ruby
require 'appwrite'

include Appwrite

client = Client.new
    .set_endpoint('https://<REGION>.cloud.appwrite.io/v1')
    .set_project('<PROJECT_ID>')
    .set_key('<YOUR_API_KEY>')

project = Project.new(client)

project.update_deny_free_email_policy(enabled: true)
project.update_deny_aliased_email_policy(enabled: true)
project.update_deny_disposable_email_policy(enabled: true)
```
```server-dotnet
using Appwrite;
using Appwrite.Services;

Client client = new Client()
    .SetEndPoint("https://<REGION>.cloud.appwrite.io/v1")
    .SetProject("<PROJECT_ID>")
    .SetKey("<YOUR_API_KEY>");

Project project = new Project(client);

await project.UpdateDenyFreeEmailPolicy(enabled: true);
await project.UpdateDenyAliasedEmailPolicy(enabled: true);
await project.UpdateDenyDisposableEmailPolicy(enabled: true);
```
```server-dart
import 'package:dart_appwrite/dart_appwrite.dart';

Client client = Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>')
    .setKey('<YOUR_API_KEY>');

Project project = Project(client);

await project.updateDenyFreeEmailPolicy(enabled: true);
await project.updateDenyAliasedEmailPolicy(enabled: true);
await project.updateDenyDisposableEmailPolicy(enabled: true);
```
```server-kotlin
import io.appwrite.Client
import io.appwrite.services.Project

val client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1")
    .setProject("<PROJECT_ID>")
    .setKey("<YOUR_API_KEY>")

val project = Project(client)

project.updateDenyFreeEmailPolicy(enabled = true)
project.updateDenyAliasedEmailPolicy(enabled = true)
project.updateDenyDisposableEmailPolicy(enabled = true)
```
```server-swift
import Appwrite

let client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1")
    .setProject("<PROJECT_ID>")
    .setKey("<YOUR_API_KEY>")

let project = Project(client)

_ = try await project.updateDenyFreeEmailPolicy(enabled: true)
_ = try await project.updateDenyAliasedEmailPolicy(enabled: true)
_ = try await project.updateDenyDisposableEmailPolicy(enabled: true)
```
```server-go
package main

import (
    "github.com/appwrite/sdk-for-go/appwrite"
)

func main() {
    client := appwrite.NewClient(
        appwrite.WithEndpoint("https://<REGION>.cloud.appwrite.io/v1"),
        appwrite.WithProject("<PROJECT_ID>"),
        appwrite.WithKey("<YOUR_API_KEY>"),
    )

    service := appwrite.NewProject(client)

    if _, err := service.UpdateDenyFreeEmailPolicy(true); err != nil {
        panic(err)
    }
    if _, err := service.UpdateDenyAliasedEmailPolicy(true); err != nil {
        panic(err)
    }
    if _, err := service.UpdateDenyDisposableEmailPolicy(true); err != nil {
        panic(err)
    }
}
```
```server-rust
use appwrite::Client;
use appwrite::services::project::Project;

#[tokio::main]
async fn main() -> Result<(), Box<dyn std::error::Error>> {
    let client = Client::new()
        .set_endpoint("https://<REGION>.cloud.appwrite.io/v1")
        .set_project("<PROJECT_ID>")
        .set_key("<YOUR_API_KEY>");

    let project = Project::new(&client);

    project.update_deny_free_email_policy(true).await?;
    project.update_deny_aliased_email_policy(true).await?;
    project.update_deny_disposable_email_policy(true).await?;

    Ok(())
}
```
```bash
appwrite project update-deny-free-email-policy --enabled true
appwrite project update-deny-canonical-email-policy --enabled true
appwrite project update-deny-disposable-email-policy --enabled true
```
{% /multicode %}

# Get started

Email policies are available now on Appwrite Cloud. Head to **Auth > Security** in the Console, or read the [Email policies documentation](/docs/products/auth/email-policies) for the full SDK reference and per-language examples.

# Resources

- [Email policies documentation](/docs/products/auth/email-policies)
- [Auth security guide](/docs/products/auth/security)
- [Join the Appwrite Discord community](https://appwrite.io/discord)

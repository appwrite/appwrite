---
layout: post
title: "Announcing the Variables API: Manage function, site, and project variables from your Server SDKs"
description: Environment variables for functions, sites, and projects can now be created, updated, and deleted programmatically through the Appwrite Server SDKs. Provision configuration as code, rotate secrets in scripts, and bootstrap new environments without touching the Console.
date: 2026-04-30
cover: /images/blog/announcing-variables-api/cover.avif
timeToRead: 4
author: matej-baco
category: announcement
featured: false
callToAction: true
faqs:
  - question: "What is the Appwrite Variables API?"
    answer: "The Variables API is a unified set of Server SDK endpoints for managing environment variables on [Functions](/docs/products/functions), [Sites](/docs/products/sites), and the project as a whole. It exposes the same five operations (create, list, get, update, delete) across all three scopes, so you can manage configuration entirely from code."
  - question: "Which API key scopes does the Variables API need?"
    answer: "Function variables use functions.read and functions.write, site variables use sites.read and sites.write, and project variables use project.read and project.write. Grant only the scopes you need on each API key."
  - question: "How are project, function, and site variables resolved at runtime?"
    answer: "Project variables load first, then function or site variables override matching keys, and Appwrite-injected variables prefixed with APPWRITE_ take final precedence. This makes it easy to set sensible defaults at the project level and override per function or site."
  - question: "Can I use the Variables API in CI/CD pipelines?"
    answer: "Yes. The API is a natural fit for CI/CD, infrastructure scripts, and project templates. You can provision configuration as code, rotate secrets across environments in a single script, and bootstrap new projects with a consistent baseline of variables."
  - question: "Why mark a variable as secret?"
    answer: "Marking a variable as secret tells Appwrite to treat it as sensitive, hiding its value in API responses and the Console after creation. Use this for API keys, tokens, and other credentials that should not be displayed to anyone after they are set."
---

Environment variables have always been part of Appwrite. You could set them on a function, on a site, or on the project as a whole, and your code would pick them up at build and runtime. The catch was that all of this lived inside the Appwrite Console. If you wanted to provision configuration as part of a script, rotate a secret across environments, or bootstrap a new project from a template, you had to click through the UI.

Today, we are announcing the **Variables API**, a unified set of endpoints for managing environment variables on functions, sites, and projects programmatically through the Appwrite Server SDKs.

This is part of a wider effort to make everything in Appwrite accessible through the API. Anything you can do in the Console should be doable from code, and variables are now a first-class part of that surface.

# Why this matters

Configuration drift, manual secret rotation, and click-ops bootstrapping are some of the easiest ways to break a production environment. The Variables API removes them from the loop:

- **Provision configuration as code** as part of CI/CD, infrastructure scripts, or project templates.
- **Rotate secrets across environments** without opening the Console for each function, site, and project.
- **Bootstrap new projects** with the same baseline of variables every time.
- **Build internal tooling** that lets your team manage shared configuration in one place.
- **Audit and reconcile** what is set against what should be set, programmatically.

# Three scopes, one API surface

The Variables API covers all three scopes Appwrite already supported in the Console:

- **Function variables** live on a single function and are set on the `Functions` service.
- **Site variables** live on a single site and are set on the `Sites` service.
- **Project variables** are shared across every function and site in the project and are set on the `Project` service.

Each scope exposes the same five operations: create, list, get, update, and delete. At build and runtime, project variables load first, function or site variables override matching keys, and Appwrite-injected variables (those prefixed with `APPWRITE_`) take final precedence.

# How it works

The relevant API key scopes are:

- **`functions.read`** / **`functions.write`** for function variables
- **`sites.read`** / **`sites.write`** for site variables
- **`project.read`** / **`project.write`** for project variables

## Create a project variable

The new `Project` service exposes `createVariable` for shared configuration:

{% multicode %}
```server-nodejs
import { Client, Project, ID } from 'node-appwrite';

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>') // Your project ID
    .setKey('<YOUR_API_KEY>'); // Your API key

const project = new Project(client);

const result = await project.createVariable({
    variableId: ID.unique(),
    key: 'STRIPE_KEY',
    value: 'sk_live_...',
    secret: true // optional
});
```
```server-deno
import { Client, Project, ID } from "npm:node-appwrite";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>') // Your project ID
    .setKey('<YOUR_API_KEY>'); // Your API key

const project = new Project(client);

const result = await project.createVariable({
    variableId: ID.unique(),
    key: 'STRIPE_KEY',
    value: 'sk_live_...',
    secret: true // optional
});
```
```server-php
<?php

use Appwrite\Client;
use Appwrite\ID;
use Appwrite\Services\Project;

$client = (new Client())
    ->setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    ->setProject('<YOUR_PROJECT_ID>') // Your project ID
    ->setKey('<YOUR_API_KEY>'); // Your API key

$project = new Project($client);

$result = $project->createVariable(
    variableId: ID::unique(),
    key: 'STRIPE_KEY',
    value: 'sk_live_...',
    secret: true // optional
);
```
```server-python
from appwrite.client import Client
from appwrite.id import ID
from appwrite.services.project import Project
from appwrite.models import Variable

client = Client()
client.set_endpoint('https://<REGION>.cloud.appwrite.io/v1') # Your API Endpoint
client.set_project('<YOUR_PROJECT_ID>') # Your project ID
client.set_key('<YOUR_API_KEY>') # Your API key

project = Project(client)

result: Variable = project.create_variable(
    variable_id = ID.unique(),
    key = 'STRIPE_KEY',
    value = 'sk_live_...',
    secret = True # optional
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

result = project.create_variable(
    variable_id: ID.unique(),
    key: 'STRIPE_KEY',
    value: 'sk_live_...',
    secret: true # optional
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

Variable result = await project.CreateVariable(
    variableId: ID.Unique(),
    key: "STRIPE_KEY",
    value: "sk_live_...",
    secret: true // optional
);
```
```server-dart
import 'package:dart_appwrite/dart_appwrite.dart';

Client client = Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>') // Your project ID
    .setKey('<YOUR_API_KEY>'); // Your API key

Project project = Project(client);

Variable result = await project.createVariable(
    variableId: ID.unique(),
    key: 'STRIPE_KEY',
    value: 'sk_live_...',
    secret: true, // (optional)
);
```
```server-kotlin
import io.appwrite.Client
import io.appwrite.ID
import io.appwrite.coroutines.CoroutineCallback
import io.appwrite.services.Project

val client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<YOUR_PROJECT_ID>") // Your project ID
    .setKey("<YOUR_API_KEY>") // Your API key

val project = Project(client)

val response = project.createVariable(
    variableId = ID.unique(),
    key = "STRIPE_KEY",
    value = "sk_live_...",
    secret = true // optional
)
```
```server-java
import io.appwrite.Client;
import io.appwrite.ID;
import io.appwrite.coroutines.CoroutineCallback;
import io.appwrite.services.Project;

Client client = new Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<YOUR_PROJECT_ID>") // Your project ID
    .setKey("<YOUR_API_KEY>"); // Your API key

Project project = new Project(client);

project.createVariable(
    ID.unique(), // variableId
    "STRIPE_KEY", // key
    "sk_live_...", // value
    true, // secret (optional)
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

let variable = try await project.createVariable(
    variableId: ID.unique(),
    key: "STRIPE_KEY",
    value: "sk_live_...",
    secret: true // optional
)
```
```server-go
package main

import (
    "fmt"
    "github.com/appwrite/sdk-for-go/appwrite"
    "github.com/appwrite/sdk-for-go/id"
)

client := appwrite.NewClient(
    appwrite.WithEndpoint("https://<REGION>.cloud.appwrite.io/v1"),
    appwrite.WithProject("<YOUR_PROJECT_ID>"),
    appwrite.WithKey("<YOUR_API_KEY>"),
)

project := appwrite.NewProject(client)

response, error := project.CreateVariable(
    id.Unique(),
    "STRIPE_KEY",
    "sk_live_...",
    appwrite.WithCreateVariableSecret(true),
)
```
```server-rust
use appwrite::Client;
use appwrite::id::ID;
use appwrite::services::Project;

#[tokio::main]
async fn main() -> Result<(), Box<dyn std::error::Error>> {
    let client = Client::new();
    client.set_endpoint("https://<REGION>.cloud.appwrite.io/v1"); // Your API Endpoint
    client.set_project("<YOUR_PROJECT_ID>"); // Your project ID
    client.set_key("<YOUR_API_KEY>"); // Your API key

    let project = Project::new(&client);

    let result = project.create_variable(
        &ID::unique(),
        "STRIPE_KEY",
        "sk_live_...",
        Some(true) // optional
    ).await?;

    let _ = result;

    Ok(())
}
```
{% /multicode %}

## Create a function variable

The same shape applies on the `Functions` service for variables scoped to a single function:

{% multicode %}
```server-nodejs
import { Client, Functions } from 'node-appwrite';

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>') // Your project ID
    .setKey('<YOUR_API_KEY>'); // Your API key

const functions = new Functions(client);

const result = await functions.createVariable({
    functionId: '<FUNCTION_ID>',
    key: 'OPENAI_API_KEY',
    value: 'sk-...',
    secret: true // optional
});
```
```server-deno
import { Client, Functions } from "npm:node-appwrite";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>') // Your project ID
    .setKey('<YOUR_API_KEY>'); // Your API key

const functions = new Functions(client);

const result = await functions.createVariable({
    functionId: '<FUNCTION_ID>',
    key: 'OPENAI_API_KEY',
    value: 'sk-...',
    secret: true // optional
});
```
```server-php
<?php

use Appwrite\Client;
use Appwrite\Services\Functions;

$client = (new Client())
    ->setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    ->setProject('<YOUR_PROJECT_ID>') // Your project ID
    ->setKey('<YOUR_API_KEY>'); // Your API key

$functions = new Functions($client);

$result = $functions->createVariable(
    functionId: '<FUNCTION_ID>',
    key: 'OPENAI_API_KEY',
    value: 'sk-...',
    secret: true // optional
);
```
```server-python
from appwrite.client import Client
from appwrite.services.functions import Functions
from appwrite.models import Variable

client = Client()
client.set_endpoint('https://<REGION>.cloud.appwrite.io/v1') # Your API Endpoint
client.set_project('<YOUR_PROJECT_ID>') # Your project ID
client.set_key('<YOUR_API_KEY>') # Your API key

functions = Functions(client)

result: Variable = functions.create_variable(
    function_id = '<FUNCTION_ID>',
    key = 'OPENAI_API_KEY',
    value = 'sk-...',
    secret = True # optional
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

functions = Functions.new(client)

result = functions.create_variable(
    function_id: '<FUNCTION_ID>',
    key: 'OPENAI_API_KEY',
    value: 'sk-...',
    secret: true # optional
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

Functions functions = new Functions(client);

Variable result = await functions.CreateVariable(
    functionId: "<FUNCTION_ID>",
    key: "OPENAI_API_KEY",
    value: "sk-...",
    secret: true // optional
);
```
```server-dart
import 'package:dart_appwrite/dart_appwrite.dart';

Client client = Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>') // Your project ID
    .setKey('<YOUR_API_KEY>'); // Your API key

Functions functions = Functions(client);

Variable result = await functions.createVariable(
    functionId: '<FUNCTION_ID>',
    key: 'OPENAI_API_KEY',
    value: 'sk-...',
    secret: true, // (optional)
);
```
```server-kotlin
import io.appwrite.Client
import io.appwrite.coroutines.CoroutineCallback
import io.appwrite.services.Functions

val client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<YOUR_PROJECT_ID>") // Your project ID
    .setKey("<YOUR_API_KEY>") // Your API key

val functions = Functions(client)

val response = functions.createVariable(
    functionId = "<FUNCTION_ID>",
    key = "OPENAI_API_KEY",
    value = "sk-...",
    secret = true // optional
)
```
```server-java
import io.appwrite.Client;
import io.appwrite.coroutines.CoroutineCallback;
import io.appwrite.services.Functions;

Client client = new Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<YOUR_PROJECT_ID>") // Your project ID
    .setKey("<YOUR_API_KEY>"); // Your API key

Functions functions = new Functions(client);

functions.createVariable(
    "<FUNCTION_ID>", // functionId
    "OPENAI_API_KEY", // key
    "sk-...", // value
    true, // secret (optional)
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

let functions = Functions(client)

let variable = try await functions.createVariable(
    functionId: "<FUNCTION_ID>",
    key: "OPENAI_API_KEY",
    value: "sk-...",
    secret: true // optional
)
```
```server-go
package main

import (
    "fmt"
    "github.com/appwrite/sdk-for-go/appwrite"
)

client := appwrite.NewClient(
    appwrite.WithEndpoint("https://<REGION>.cloud.appwrite.io/v1"),
    appwrite.WithProject("<YOUR_PROJECT_ID>"),
    appwrite.WithKey("<YOUR_API_KEY>"),
)

functions := appwrite.NewFunctions(client)

response, error := functions.CreateVariable(
    "<FUNCTION_ID>",
    "OPENAI_API_KEY",
    "sk-...",
    appwrite.WithCreateVariableSecret(true),
)
```
```server-rust
use appwrite::Client;
use appwrite::services::Functions;

#[tokio::main]
async fn main() -> Result<(), Box<dyn std::error::Error>> {
    let client = Client::new();
    client.set_endpoint("https://<REGION>.cloud.appwrite.io/v1"); // Your API Endpoint
    client.set_project("<YOUR_PROJECT_ID>"); // Your project ID
    client.set_key("<YOUR_API_KEY>"); // Your API key

    let functions = Functions::new(&client);

    let result = functions.create_variable(
        "<FUNCTION_ID>",
        "OPENAI_API_KEY",
        "sk-...",
        Some(true) // optional
    ).await?;

    let _ = result;

    Ok(())
}
```
{% /multicode %}

## Create a site variable

And on the `Sites` service for variables scoped to a single site:

{% multicode %}
```server-nodejs
import { Client, Sites } from 'node-appwrite';

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>') // Your project ID
    .setKey('<YOUR_API_KEY>'); // Your API key

const sites = new Sites(client);

const result = await sites.createVariable({
    siteId: '<SITE_ID>',
    key: 'NEXT_PUBLIC_ANALYTICS_ID',
    value: 'G-XXXXXXX',
    secret: false // optional
});
```
```server-deno
import { Client, Sites } from "npm:node-appwrite";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>') // Your project ID
    .setKey('<YOUR_API_KEY>'); // Your API key

const sites = new Sites(client);

const result = await sites.createVariable({
    siteId: '<SITE_ID>',
    key: 'NEXT_PUBLIC_ANALYTICS_ID',
    value: 'G-XXXXXXX',
    secret: false // optional
});
```
```server-php
<?php

use Appwrite\Client;
use Appwrite\Services\Sites;

$client = (new Client())
    ->setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    ->setProject('<YOUR_PROJECT_ID>') // Your project ID
    ->setKey('<YOUR_API_KEY>'); // Your API key

$sites = new Sites($client);

$result = $sites->createVariable(
    siteId: '<SITE_ID>',
    key: 'NEXT_PUBLIC_ANALYTICS_ID',
    value: 'G-XXXXXXX',
    secret: false // optional
);
```
```server-python
from appwrite.client import Client
from appwrite.services.sites import Sites
from appwrite.models import Variable

client = Client()
client.set_endpoint('https://<REGION>.cloud.appwrite.io/v1') # Your API Endpoint
client.set_project('<YOUR_PROJECT_ID>') # Your project ID
client.set_key('<YOUR_API_KEY>') # Your API key

sites = Sites(client)

result: Variable = sites.create_variable(
    site_id = '<SITE_ID>',
    key = 'NEXT_PUBLIC_ANALYTICS_ID',
    value = 'G-XXXXXXX',
    secret = False # optional
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

sites = Sites.new(client)

result = sites.create_variable(
    site_id: '<SITE_ID>',
    key: 'NEXT_PUBLIC_ANALYTICS_ID',
    value: 'G-XXXXXXX',
    secret: false # optional
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

Sites sites = new Sites(client);

Variable result = await sites.CreateVariable(
    siteId: "<SITE_ID>",
    key: "NEXT_PUBLIC_ANALYTICS_ID",
    value: "G-XXXXXXX",
    secret: false // optional
);
```
```server-dart
import 'package:dart_appwrite/dart_appwrite.dart';

Client client = Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>') // Your project ID
    .setKey('<YOUR_API_KEY>'); // Your API key

Sites sites = Sites(client);

Variable result = await sites.createVariable(
    siteId: '<SITE_ID>',
    key: 'NEXT_PUBLIC_ANALYTICS_ID',
    value: 'G-XXXXXXX',
    secret: false, // (optional)
);
```
```server-kotlin
import io.appwrite.Client
import io.appwrite.coroutines.CoroutineCallback
import io.appwrite.services.Sites

val client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<YOUR_PROJECT_ID>") // Your project ID
    .setKey("<YOUR_API_KEY>") // Your API key

val sites = Sites(client)

val response = sites.createVariable(
    siteId = "<SITE_ID>",
    key = "NEXT_PUBLIC_ANALYTICS_ID",
    value = "G-XXXXXXX",
    secret = false // optional
)
```
```server-java
import io.appwrite.Client;
import io.appwrite.coroutines.CoroutineCallback;
import io.appwrite.services.Sites;

Client client = new Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<YOUR_PROJECT_ID>") // Your project ID
    .setKey("<YOUR_API_KEY>"); // Your API key

Sites sites = new Sites(client);

sites.createVariable(
    "<SITE_ID>", // siteId
    "NEXT_PUBLIC_ANALYTICS_ID", // key
    "G-XXXXXXX", // value
    false, // secret (optional)
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

let sites = Sites(client)

let variable = try await sites.createVariable(
    siteId: "<SITE_ID>",
    key: "NEXT_PUBLIC_ANALYTICS_ID",
    value: "G-XXXXXXX",
    secret: false // optional
)
```
```server-go
package main

import (
    "fmt"
    "github.com/appwrite/sdk-for-go/appwrite"
)

client := appwrite.NewClient(
    appwrite.WithEndpoint("https://<REGION>.cloud.appwrite.io/v1"),
    appwrite.WithProject("<YOUR_PROJECT_ID>"),
    appwrite.WithKey("<YOUR_API_KEY>"),
)

sites := appwrite.NewSites(client)

response, error := sites.CreateVariable(
    "<SITE_ID>",
    "NEXT_PUBLIC_ANALYTICS_ID",
    "G-XXXXXXX",
    appwrite.WithCreateVariableSecret(false),
)
```
```server-rust
use appwrite::Client;
use appwrite::services::Sites;

#[tokio::main]
async fn main() -> Result<(), Box<dyn std::error::Error>> {
    let client = Client::new();
    client.set_endpoint("https://<REGION>.cloud.appwrite.io/v1"); // Your API Endpoint
    client.set_project("<YOUR_PROJECT_ID>"); // Your project ID
    client.set_key("<YOUR_API_KEY>"); // Your API key

    let sites = Sites::new(&client);

    let result = sites.create_variable(
        "<SITE_ID>",
        "NEXT_PUBLIC_ANALYTICS_ID",
        "G-XXXXXXX",
        Some(false) // optional
    ).await?;

    let _ = result;

    Ok(())
}
```
{% /multicode %}

## Update a project variable

`updateVariable` exists on all three services with the same shape. Updating a variable lets you rotate a value or change its secret flag without recreating it. Marking a variable as secret cannot be reversed. To replace a secret value, delete the variable and create a new one with the same key.

{% multicode %}
```server-nodejs
import { Client, Project } from 'node-appwrite';

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>') // Your project ID
    .setKey('<YOUR_API_KEY>'); // Your API key

const project = new Project(client);

const result = await project.updateVariable({
    variableId: '<VARIABLE_ID>',
    key: 'STRIPE_KEY', // optional
    value: 'sk_live_rotated_...', // optional
    secret: false // optional
});
```
```server-deno
import { Client, Project } from "npm:node-appwrite";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>') // Your project ID
    .setKey('<YOUR_API_KEY>'); // Your API key

const project = new Project(client);

const result = await project.updateVariable({
    variableId: '<VARIABLE_ID>',
    key: 'STRIPE_KEY', // optional
    value: 'sk_live_rotated_...', // optional
    secret: false // optional
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

$result = $project->updateVariable(
    variableId: '<VARIABLE_ID>',
    key: 'STRIPE_KEY', // optional
    value: 'sk_live_rotated_...', // optional
    secret: false // optional
);
```
```server-python
from appwrite.client import Client
from appwrite.services.project import Project
from appwrite.models import Variable

client = Client()
client.set_endpoint('https://<REGION>.cloud.appwrite.io/v1') # Your API Endpoint
client.set_project('<YOUR_PROJECT_ID>') # Your project ID
client.set_key('<YOUR_API_KEY>') # Your API key

project = Project(client)

result: Variable = project.update_variable(
    variable_id = '<VARIABLE_ID>',
    key = 'STRIPE_KEY', # optional
    value = 'sk_live_rotated_...', # optional
    secret = False # optional
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

result = project.update_variable(
    variable_id: '<VARIABLE_ID>',
    key: 'STRIPE_KEY', # optional
    value: 'sk_live_rotated_...', # optional
    secret: false # optional
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

Variable result = await project.UpdateVariable(
    variableId: "<VARIABLE_ID>",
    key: "STRIPE_KEY", // optional
    value: "sk_live_rotated_...", // optional
    secret: false // optional
);
```
```server-dart
import 'package:dart_appwrite/dart_appwrite.dart';

Client client = Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>') // Your project ID
    .setKey('<YOUR_API_KEY>'); // Your API key

Project project = Project(client);

Variable result = await project.updateVariable(
    variableId: '<VARIABLE_ID>',
    key: 'STRIPE_KEY', // (optional)
    value: 'sk_live_rotated_...', // (optional)
    secret: false, // (optional)
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

val response = project.updateVariable(
    variableId = "<VARIABLE_ID>",
    key = "STRIPE_KEY", // optional
    value = "sk_live_rotated_...", // optional
    secret = false // optional
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

project.updateVariable(
    "<VARIABLE_ID>", // variableId
    "STRIPE_KEY", // key (optional)
    "sk_live_rotated_...", // value (optional)
    false, // secret (optional)
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

let variable = try await project.updateVariable(
    variableId: "<VARIABLE_ID>",
    key: "STRIPE_KEY", // optional
    value: "sk_live_rotated_...", // optional
    secret: false // optional
)
```
```server-go
package main

import (
    "fmt"
    "github.com/appwrite/sdk-for-go/appwrite"
)

client := appwrite.NewClient(
    appwrite.WithEndpoint("https://<REGION>.cloud.appwrite.io/v1"),
    appwrite.WithProject("<YOUR_PROJECT_ID>"),
    appwrite.WithKey("<YOUR_API_KEY>"),
)

project := appwrite.NewProject(client)

response, error := project.UpdateVariable(
    "<VARIABLE_ID>",
    appwrite.WithUpdateVariableKey("STRIPE_KEY"),
    appwrite.WithUpdateVariableValue("sk_live_rotated_..."),
    appwrite.WithUpdateVariableSecret(false),
)
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

    let result = project.update_variable(
        "<VARIABLE_ID>",
        Some("STRIPE_KEY"), // optional
        Some("sk_live_rotated_..."), // optional
        Some(false) // optional
    ).await?;

    let _ = result;

    Ok(())
}
```
{% /multicode %}

## Delete a project variable

`deleteVariable` is available on each service. Once deleted, the variable stops appearing in subsequent function and site deployments.

{% multicode %}
```server-nodejs
import { Client, Project } from 'node-appwrite';

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>') // Your project ID
    .setKey('<YOUR_API_KEY>'); // Your API key

const project = new Project(client);

const result = await project.deleteVariable({
    variableId: '<VARIABLE_ID>'
});
```
```server-deno
import { Client, Project } from "npm:node-appwrite";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>') // Your project ID
    .setKey('<YOUR_API_KEY>'); // Your API key

const project = new Project(client);

const result = await project.deleteVariable({
    variableId: '<VARIABLE_ID>'
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

$result = $project->deleteVariable(
    variableId: '<VARIABLE_ID>'
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

result = project.delete_variable(
    variable_id = '<VARIABLE_ID>'
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

result = project.delete_variable(
    variable_id: '<VARIABLE_ID>'
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

await project.DeleteVariable(
    variableId: "<VARIABLE_ID>"
);
```
```server-dart
import 'package:dart_appwrite/dart_appwrite.dart';

Client client = Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>') // Your project ID
    .setKey('<YOUR_API_KEY>'); // Your API key

Project project = Project(client);

await project.deleteVariable(
    variableId: '<VARIABLE_ID>',
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

val response = project.deleteVariable(
    variableId = "<VARIABLE_ID>"
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

project.deleteVariable(
    "<VARIABLE_ID>", // variableId
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

let result = try await project.deleteVariable(
    variableId: "<VARIABLE_ID>"
)
```
```server-go
package main

import (
    "fmt"
    "github.com/appwrite/sdk-for-go/appwrite"
)

client := appwrite.NewClient(
    appwrite.WithEndpoint("https://<REGION>.cloud.appwrite.io/v1"),
    appwrite.WithProject("<YOUR_PROJECT_ID>"),
    appwrite.WithKey("<YOUR_API_KEY>"),
)

project := appwrite.NewProject(client)

response, error := project.DeleteVariable(
    "<VARIABLE_ID>",
)
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

    project.delete_variable(
        "<VARIABLE_ID>"
    ).await?;

    Ok(())
}
```
{% /multicode %}

# Get started

The Variables API is available on **Appwrite Cloud** today, across every Appwrite Server SDK. Complete code examples for every supported language and every operation live in the documentation:

- [Project variables](/docs/advanced/platform/environment-variables)
- [Function variables](/docs/products/functions/environment-variables)
- [Site variables](/docs/products/sites/environment-variables)

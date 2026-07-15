---
layout: article
title: Environment variables
description: Use project, function, and site environment variables to pass constants and secrets to your Appwrite Functions and Appwrite Sites at build and runtime.
---

Environment variables let you pass constants and secrets such as API keys, connection strings, and feature flags into your Appwrite Functions and Appwrite Sites at build and runtime. Storing values outside your source keeps secrets out of version control and lets you change configuration without code changes.

Appwrite supports three scopes of environment variables:

- **Project variables** are shared across every function and site in the project. Use them for values consumed by more than one resource, such as a shared third-party API key, a database URL, or a feature flag.
- **Function variables** are scoped to a single function. Use them for values only that function needs.
- **Site variables** are scoped to a single site. Use them for values only that site needs.

When the same key is defined in multiple scopes, the more specific scope wins. Function or site variables override project variables, and Appwrite-injected variables (those prefixed with `APPWRITE_`) take final precedence and cannot be overridden.

{% info title="Redeployment required" %}
Variable changes only take effect on the next deployment. Redeploy your functions or sites after creating, updating, or deleting variables.
{% /info %}

# Project variables {% #project-variables %}

Project variables are available to every function and site in your project. They are managed at the project level and merged into each function or site's environment automatically at build and runtime.

This page covers how to manage project variables. To manage variables on a single function or site, see the dedicated pages:

{% cards %}
{% cards_item href="/docs/products/functions/environment-variables" title="Function variables" %}
Manage variables for a single function.
{% /cards_item %}
{% cards_item href="/docs/products/sites/environment-variables" title="Site variables" %}
Manage variables for a single site.
{% /cards_item %}
{% /cards %}

# Manage in the Console {% #console %}

You can create and manage project variables from the Appwrite Console. The Console refers to them as **Global variables**:

1. Navigate to your project.
2. Open **Settings** and scroll to the **Global variables** section.
3. Click **Create a global variable** and enter a key and value.
4. Optionally select the **Secret** checkbox to prevent any team member from reading the value after creation.
5. Click **Create**, then redeploy your functions and sites for the change to take effect.

{% only_dark %}
![Project Global variables in the Appwrite Console](/images/docs/platform/dark/env-variables.avif)
{% /only_dark %}
{% only_light %}
![Project Global variables in the Appwrite Console](/images/docs/platform/env-variables.avif)
{% /only_light %}

# Manage with a Server SDK {% #server-sdks %}

You can also manage project variables programmatically using a [Server SDK](/docs/sdks#server).

{% info title="Required scopes" %}
The API key used for these calls needs `project.read` to list or fetch variables, and `project.write` to create, update, or delete them.
{% /info %}

## Create a variable {% #create-variable %}

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
    key: '<KEY>',
    value: '<VALUE>',
    secret: false // optional
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
    key: '<KEY>',
    value: '<VALUE>',
    secret: false // optional
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
    key: '<KEY>',
    value: '<VALUE>',
    secret: false // optional
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
    key = '<KEY>',
    value = '<VALUE>',
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

result = project.create_variable(
    variable_id: ID.unique(),
    key: '<KEY>',
    value: '<VALUE>',
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

Variable result = await project.CreateVariable(
    variableId: ID.Unique(),
    key: "<KEY>",
    value: "<VALUE>",
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

Variable result = await project.createVariable(
    variableId: ID.unique(),
    key: '<KEY>',
    value: '<VALUE>',
    secret: false, // (optional)
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
    key = "<KEY>",
    value = "<VALUE>",
    secret = false // optional
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
    "<KEY>", // key
    "<VALUE>", // value
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

let variable = try await project.createVariable(
    variableId: ID.unique(),
    key: "<KEY>",
    value: "<VALUE>",
    secret: false // optional
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
    "<KEY>",
    "<VALUE>",
    appwrite.WithCreateVariableSecret(false),
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
        "<KEY>",
        "<VALUE>",
        Some(false) // optional
    ).await?;

    let _ = result;

    Ok(())
}
```
{% /multicode %}

## List variables {% #list-variables %}

You can paginate, filter, and sort the result. See [Queries](/docs/products/databases/queries) for the query syntax. The list endpoint accepts queries on the `key`, `value`, and `secret` attributes.

{% multicode %}
```server-nodejs
import { Client, Project } from 'node-appwrite';

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>') // Your project ID
    .setKey('<YOUR_API_KEY>'); // Your API key

const project = new Project(client);

const result = await project.listVariables({
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

const result = await project.listVariables({
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

$result = $project->listVariables(
    queries: [], // optional
    total: false // optional
);
```
```server-python
from appwrite.client import Client
from appwrite.services.project import Project
from appwrite.models import VariableList

client = Client()
client.set_endpoint('https://<REGION>.cloud.appwrite.io/v1') # Your API Endpoint
client.set_project('<YOUR_PROJECT_ID>') # Your project ID
client.set_key('<YOUR_API_KEY>') # Your API key

project = Project(client)

result: VariableList = project.list_variables(
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

result = project.list_variables(
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

VariableList result = await project.ListVariables(
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

VariableList result = await project.listVariables(
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

val response = project.listVariables(
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

project.listVariables(
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

let variableList = try await project.listVariables(
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

client := appwrite.NewClient(
    appwrite.WithEndpoint("https://<REGION>.cloud.appwrite.io/v1"),
    appwrite.WithProject("<YOUR_PROJECT_ID>"),
    appwrite.WithKey("<YOUR_API_KEY>"),
)

project := appwrite.NewProject(client)

response, error := project.ListVariables(
    appwrite.WithListVariablesQueries([]interface{}{}),
    appwrite.WithListVariablesTotal(false),
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

    let result = project.list_variables(
        Some(vec![]), // optional
        Some(false) // optional
    ).await?;

    let _ = result;

    Ok(())
}
```
{% /multicode %}

## Get a variable {% #get-variable %}

{% multicode %}
```server-nodejs
import { Client, Project } from 'node-appwrite';

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('<YOUR_PROJECT_ID>') // Your project ID
    .setKey('<YOUR_API_KEY>'); // Your API key

const project = new Project(client);

const result = await project.getVariable({
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

const result = await project.getVariable({
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

$result = $project->getVariable(
    variableId: '<VARIABLE_ID>'
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

result: Variable = project.get_variable(
    variable_id = '<VARIABLE_ID>'
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

result = project.get_variable(
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

Variable result = await project.GetVariable(
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

Variable result = await project.getVariable(
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

val response = project.getVariable(
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

project.getVariable(
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

let variable = try await project.getVariable(
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

response, error := project.GetVariable(
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

    let result = project.get_variable(
        "<VARIABLE_ID>"
    ).await?;

    let _ = result;

    Ok(())
}
```
{% /multicode %}

## Update a variable {% #update-variable %}

You can change a variable's `key`, `value`, or `secret` flag. Marking a variable as secret is one-way. Once set, the value is no longer readable from the Console or API.

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
    key: '<KEY>', // optional
    value: '<VALUE>', // optional
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
    key: '<KEY>', // optional
    value: '<VALUE>', // optional
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
    key: '<KEY>', // optional
    value: '<VALUE>', // optional
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
    key = '<KEY>', # optional
    value = '<VALUE>', # optional
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
    key: '<KEY>', # optional
    value: '<VALUE>', # optional
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
    key: "<KEY>", // optional
    value: "<VALUE>", // optional
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
    key: '<KEY>', // (optional)
    value: '<VALUE>', // (optional)
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
    key = "<KEY>", // optional
    value = "<VALUE>", // optional
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
    "<KEY>", // key (optional)
    "<VALUE>", // value (optional)
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
    key: "<KEY>", // optional
    value: "<VALUE>", // optional
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
    appwrite.WithUpdateVariableKey("<KEY>"),
    appwrite.WithUpdateVariableValue("<VALUE>"),
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
        Some("<KEY>"), // optional
        Some("<VALUE>"), // optional
        Some(false) // optional
    ).await?;

    let _ = result;

    Ok(())
}
```
{% /multicode %}

## Delete a variable {% #delete-variable %}

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

# Secret variables {% #secret-variables %}

Mark a variable as **Secret** to hide its value from the Console and API after creation. Only the function or site runtime can read the value at build and runtime. Team members and external integrations cannot retrieve it after creation.

You can mark a variable as secret either when you create it or by updating an existing variable. Marking a variable as secret cannot be reversed. To replace a secret value, delete the variable and create a new one with the same key.

# Override behavior {% #overrides %}

When the same key is defined in multiple scopes, more specific scopes take precedence:

1. Project variables are merged into the runtime environment first.
2. Function or site variables override matching keys from project scope.
3. Appwrite-injected variables (those prefixed with `APPWRITE_`) override matching keys from both scopes and cannot be customized.

This lets you set a default at the project level (for example, `LOG_LEVEL=info`) and override it for a specific function or site that needs different behavior.

# Limits {% #limits %}

| Field          | Limit                                          |
|----------------|------------------------------------------------|
| Variable ID    | 36 characters, `a-z A-Z 0-9 . - _`             |
| Key            | 255 characters                                 |
| Value          | 8192 characters                                |

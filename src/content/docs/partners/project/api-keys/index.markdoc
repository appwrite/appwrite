---
layout: article
title: API keys
description: Secure your application with Appwrite API Keys. Discover how to create and manage API keys to control access and enhance your application's security.
---

API keys are secrets used by Appwrite [Server SDKs](/docs/sdks#server) and the Appwrite CLI to prove their identity.
What can be accessed each API key is restricted by [scopes](#scopes) instead of permissions.

{% info title="Best practice" %}
It is a best practice to grant only the scopes you need to meet your project's goals to an API key. 
API keys should be treated as a secret. Never share the API key and keep API keys out of client applications.
{% /info %}

# API keys vs Dev keys {% #api-keys-vs-dev-keys %}

API keys and [Dev keys](/docs/advanced/security/dev-keys) are not the same and cannot be used interchangeably.

API keys permit access to Appwrite services in production environments, with access controlled through scopes to ensure secure and controlled server-side operations. Dev keys, conversely, are specifically designed to help you avoid abuse limits and CORS errors in test and development environments.

API keys are for server SDKs and the CLI in production environments, while Dev keys are for client SDKs in development environments.

# Create API key {% #create-api-key %}

{% only_dark %}
![Project settings screen](/images/docs/project/dark/create-api-key.avif)
{% /only_dark %}
{% only_light %}
![Project settings screen](/images/docs/project/create-api-key.avif)
{% /only_light %}

To create a new API key, navigate to **Overview** > **Integration** > **API keys** and click **Create API key**.

You can then use the API key to initialize the Appwrite client in your server-side apps.

{% multicode %}
```server-nodejs
const sdk = require('node-appwrite');

const client = new sdk.Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<YOUR_PROJECT_ID>')
    .setKey('<YOUR_API_KEY>'); // Your API key
```

```server-python
from appwrite.client import Client

client = Client()
client.set_endpoint('https://<REGION>.cloud.appwrite.io/v1')
client.set_project('<YOUR_PROJECT_ID>')
client.set_key('<YOUR_API_KEY>')  # Your API key
```

```server-php
use Appwrite\Client;

$client = new Client();
$client
    ->setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    ->setProject('<YOUR_PROJECT_ID>')
    ->setKey('<YOUR_API_KEY>'); // Your API key
```

```server-go
package main

import "github.com/appwrite/sdk-for-go/client"

func main() {
    c := client.NewClient()
    c.SetEndpoint("https://<REGION>.cloud.appwrite.io/v1")
    c.SetProject("<YOUR_PROJECT_ID>")
    c.SetKey("<YOUR_API_KEY>") // Your API key
}
```

```server-ruby
require 'appwrite'

client = Appwrite::Client.new
client.set_endpoint('https://<REGION>.cloud.appwrite.io/v1')
client.set_project('<YOUR_PROJECT_ID>')
client.set_key('<YOUR_API_KEY>') # Your API key
```

```server-deno
import { Client } from 'npm:node-appwrite';

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<YOUR_PROJECT_ID>')
    .setKey('<YOUR_API_KEY>'); // Your API key
```

```server-dart
import 'package:dart_appwrite/dart_appwrite.dart';

Client client = Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<YOUR_PROJECT_ID>')
    .setKey('<YOUR_API_KEY>'); // Your API key
```

```server-kotlin
import io.appwrite.Client

val client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1")
    .setProject("<YOUR_PROJECT_ID>")
    .setKey("<YOUR_API_KEY>") // Your API key
```

```server-dotnet
using Appwrite;

var client = new Client()
    .SetEndpoint("https://<REGION>.cloud.appwrite.io/v1")
    .SetProject("<YOUR_PROJECT_ID>")
    .SetKey("<YOUR_API_KEY>"); // Your API key
```

```server-swift
import Appwrite

let client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1")
    .setProject("<YOUR_PROJECT_ID>")
    .setKey("<YOUR_API_KEY>") // Your API key
```

```server-java
import io.appwrite.Client;

Client client = new Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1")
    .setProject("<YOUR_PROJECT_ID>")
    .setKey("<YOUR_API_KEY>"); // Your API key
```

```server-rust
use appwrite::Client;

let client = Client::new()
    .set_endpoint("https://<REGION>.cloud.appwrite.io/v1")
    .set_project("<YOUR_PROJECT_ID>")
    .set_key("<YOUR_API_KEY>"); // Your API key
```
{% /multicode %}

When adding a new API Key, you can choose which [scopes](#scopes) to grant your application.
If you need to replace your API Key, create a new key, update your app credentials and, once ready, delete your old key.

# Manage API keys with a Server SDK {% #manage-api-keys-with-a-server-sdk %}

You can also manage API keys programmatically using a Server SDK. This requires an API key with the `keys.read` and `keys.write` [scopes](#scopes).

{% info title="Sensitive scopes" %}
The `keys.read` and `keys.write` scopes are very sensitive. An API key with `keys.write` can create new keys with any scope, effectively granting full access to your project. Only assign these scopes to keys used in trusted, secure environments, and never expose them in client-side applications.
{% /info %}

## List API keys {% #list-api-keys %}

{% multicode %}
```server-nodejs
import { Client, Project } from 'node-appwrite';

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>')
    .setKey('<YOUR_API_KEY>');

const project = new Project(client);

const result = await project.listKeys();
```
```server-deno
import { Client, Project } from "npm:node-appwrite";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>')
    .setKey('<YOUR_API_KEY>');

const project = new Project(client);

const result = await project.listKeys();
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

$result = $project->listKeys();
```
```server-python
from appwrite.client import Client
from appwrite.services.project import Project

client = Client()
client.set_endpoint('https://<REGION>.cloud.appwrite.io/v1')
client.set_project('<PROJECT_ID>')
client.set_key('<YOUR_API_KEY>')

project = Project(client)

result = project.list_keys()
```
```server-ruby
require 'appwrite'

include Appwrite

client = Client.new
    .set_endpoint('https://<REGION>.cloud.appwrite.io/v1')
    .set_project('<PROJECT_ID>')
    .set_key('<YOUR_API_KEY>')

project = Project.new(client)

response = project.list_keys()
```
```server-dotnet
using Appwrite;
using Appwrite.Services;

Client client = new Client()
    .SetEndPoint("https://<REGION>.cloud.appwrite.io/v1")
    .SetProject("<PROJECT_ID>")
    .SetKey("<YOUR_API_KEY>");

Project project = new Project(client);

var result = await project.ListKeys();
```
```server-dart
import 'package:dart_appwrite/dart_appwrite.dart';

Client client = Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>')
    .setKey('<YOUR_API_KEY>');

Project project = Project(client);

final result = await project.listKeys();
```
```server-kotlin
import io.appwrite.Client
import io.appwrite.services.Project

val client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1")
    .setProject("<PROJECT_ID>")
    .setKey("<YOUR_API_KEY>")

val project = Project(client)

val result = project.listKeys()
```
```server-java
import io.appwrite.Client;
import io.appwrite.coroutines.CoroutineCallback;
import io.appwrite.services.Project;

Client client = new Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1")
    .setProject("<PROJECT_ID>")
    .setKey("<YOUR_API_KEY>");

Project project = new Project(client);

project.listKeys(
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

let result = try await project.listKeys()
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
    result, err := project.ListKeys()

    if err != nil {
        panic(err)
    }

    fmt.Println(result)
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

    let result = project.list_keys(
        None,  // queries
        None,  // total
    ).await?;

    println!("{:?}", result);
    Ok(())
}
```
{% /multicode %}

## Get an API key {% #get-an-api-key %}

{% multicode %}
```server-nodejs
import { Client, Project } from 'node-appwrite';

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>')
    .setKey('<YOUR_API_KEY>');

const project = new Project(client);

const result = await project.getKey({
    keyId: '<KEY_ID>'
});
```
```server-deno
import { Client, Project } from "npm:node-appwrite";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>')
    .setKey('<YOUR_API_KEY>');

const project = new Project(client);

const result = await project.getKey({
    keyId: '<KEY_ID>'
});
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

$result = $project->getKey(
    keyId: '<KEY_ID>'
);
```
```server-python
from appwrite.client import Client
from appwrite.services.project import Project

client = Client()
client.set_endpoint('https://<REGION>.cloud.appwrite.io/v1')
client.set_project('<PROJECT_ID>')
client.set_key('<YOUR_API_KEY>')

project = Project(client)

result = project.get_key(
    key_id='<KEY_ID>'
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

response = project.get_key(
    key_id: '<KEY_ID>'
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

var result = await project.GetKey(
    keyId: "<KEY_ID>"
);
```
```server-dart
import 'package:dart_appwrite/dart_appwrite.dart';

Client client = Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>')
    .setKey('<YOUR_API_KEY>');

Project project = Project(client);

final result = await project.getKey(
    keyId: '<KEY_ID>',
);
```
```server-kotlin
import io.appwrite.Client
import io.appwrite.services.Project

val client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1")
    .setProject("<PROJECT_ID>")
    .setKey("<YOUR_API_KEY>")

val project = Project(client)

val result = project.getKey(
    keyId = "<KEY_ID>"
)
```
```server-java
import io.appwrite.Client;
import io.appwrite.coroutines.CoroutineCallback;
import io.appwrite.services.Project;

Client client = new Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1")
    .setProject("<PROJECT_ID>")
    .setKey("<YOUR_API_KEY>");

Project project = new Project(client);

project.getKey(
    "<KEY_ID>",                                      // keyId
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

let result = try await project.getKey(
    keyId: "<KEY_ID>"
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
    result, err := project.GetKey(
        "<KEY_ID>",
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

#[tokio::main]
async fn main() -> Result<(), Box<dyn std::error::Error>> {
    let client = Client::new()
        .set_endpoint("https://<REGION>.cloud.appwrite.io/v1")
        .set_project("<PROJECT_ID>")
        .set_key("<YOUR_API_KEY>");

    let project = Project::new(&client);

    let result = project.get_key(
        "<KEY_ID>",
    ).await?;

    println!("{:?}", result);
    Ok(())
}
```
{% /multicode %}

## Create an API key {% #create-an-api-key %}

{% multicode %}
```server-nodejs
import { Client, Project, ID, ProjectKeyScopes } from 'node-appwrite';

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>')
    .setKey('<YOUR_API_KEY>');

const project = new Project(client);

const result = await project.createKey({
    keyId: ID.unique(),
    name: 'My API Key',
    scopes: [ProjectKeyScopes.DatabasesRead, ProjectKeyScopes.DatabasesWrite],
    expire: '2026-12-31T23:59:59.000+00:00'
});
```
```server-deno
import { Client, Project, ID, ProjectKeyScopes } from "npm:node-appwrite";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>')
    .setKey('<YOUR_API_KEY>');

const project = new Project(client);

const result = await project.createKey({
    keyId: ID.unique(),
    name: 'My API Key',
    scopes: [ProjectKeyScopes.DatabasesRead, ProjectKeyScopes.DatabasesWrite],
    expire: '2026-12-31T23:59:59.000+00:00'
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

$result = $project->createKey(
    keyId: ID::unique(),
    name: 'My API Key',
    scopes: ['databases.read', 'databases.write'],
    expire: '2026-12-31T23:59:59.000+00:00'
);
```
```server-python
from appwrite.client import Client
from appwrite.id import ID
from appwrite.services.project import Project
from appwrite.enums import ProjectKeyScopes

client = Client()
client.set_endpoint('https://<REGION>.cloud.appwrite.io/v1')
client.set_project('<PROJECT_ID>')
client.set_key('<YOUR_API_KEY>')

project = Project(client)

result = project.create_key(
    key_id=ID.unique(),
    name='My API Key',
    scopes=[ProjectKeyScopes.DATABASES_READ, ProjectKeyScopes.DATABASES_WRITE],
    expire='2026-12-31T23:59:59.000+00:00'
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

response = project.create_key(
    key_id: ID.unique(),
    name: 'My API Key',
    scopes: ['databases.read', 'databases.write'],
    expire: '2026-12-31T23:59:59.000+00:00'
)
```
```server-dotnet
using Appwrite;
using Appwrite.Enums;
using Appwrite.Services;
using Appwrite.Models;

Client client = new Client()
    .SetEndPoint("https://<REGION>.cloud.appwrite.io/v1")
    .SetProject("<PROJECT_ID>")
    .SetKey("<YOUR_API_KEY>");

Project project = new Project(client);

var result = await project.CreateKey(
    keyId: ID.Unique(),
    name: "My API Key",
    scopes: new List<ProjectKeyScopes> {ProjectKeyScopes.DatabasesRead, ProjectKeyScopes.DatabasesWrite},
    expire: "2026-12-31T23:59:59.000+00:00"
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

final result = await project.createKey(
    keyId: ID.unique(),
    name: 'My API Key',
    scopes: [enums.ProjectKeyScopes.databasesRead, enums.ProjectKeyScopes.databasesWrite],
    expire: '2026-12-31T23:59:59.000+00:00',
);
```
```server-kotlin
import io.appwrite.Client
import io.appwrite.ID
import io.appwrite.enums.ProjectKeyScopes
import io.appwrite.services.Project

val client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1")
    .setProject("<PROJECT_ID>")
    .setKey("<YOUR_API_KEY>")

val project = Project(client)

val result = project.createKey(
    keyId = ID.unique(),
    name = "My API Key",
    scopes = listOf(ProjectKeyScopes.DATABASES_READ, ProjectKeyScopes.DATABASES_WRITE),
    expire = "2026-12-31T23:59:59.000+00:00"
)
```
```server-java
import io.appwrite.Client;
import io.appwrite.ID;
import io.appwrite.enums.ProjectKeyScopes;
import io.appwrite.coroutines.CoroutineCallback;
import io.appwrite.services.Project;

Client client = new Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1")
    .setProject("<PROJECT_ID>")
    .setKey("<YOUR_API_KEY>");

Project project = new Project(client);

project.createKey(
    ID.unique(),                                     // keyId
    "My API Key",                                    // name
    List.of(ProjectKeyScopes.DATABASES_READ, ProjectKeyScopes.DATABASES_WRITE), // scopes
    "2026-12-31T23:59:59.000+00:00",                // expire
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

let result = try await project.createKey(
    keyId: ID.unique(),
    name: "My API Key",
    scopes: [ProjectKeyScopes.databasesRead, ProjectKeyScopes.databasesWrite],
    expire: "2026-12-31T23:59:59.000+00:00"
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
    result, err := project.CreateKey(
        id.Unique(),
        "My API Key",
        []string{"databases.read", "databases.write"},
        project.WithCreateKeyExpire("2026-12-31T23:59:59.000+00:00"),
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
use appwrite::enums::ProjectKeyScopes;
use appwrite::services::project::Project;

#[tokio::main]
async fn main() -> Result<(), Box<dyn std::error::Error>> {
    let client = Client::new()
        .set_endpoint("https://<REGION>.cloud.appwrite.io/v1")
        .set_project("<PROJECT_ID>")
        .set_key("<YOUR_API_KEY>");

    let project = Project::new(&client);

    let result = project.create_key(
        ID::unique(),                                  // key_id
        "My API Key",                                  // name
        vec![ProjectKeyScopes::DatabasesRead, ProjectKeyScopes::DatabasesWrite], // scopes
        Some("2026-12-31T23:59:59.000+00:00"),         // expire
    ).await?;

    println!("{:?}", result);
    Ok(())
}
```
{% /multicode %}

## Create an ephemeral key {% #create-an-ephemeral-key %}

Ephemeral keys are short-lived API keys that expire after a set duration, useful for temporary or single-session server access without managing a long-lived key. Pass the `scopes` to grant and a `duration` in seconds (maximum 3600). The response includes the generated secret and its expiration time.

{% multicode %}
```server-nodejs
import { Client, Project, ProjectKeyScopes } from 'node-appwrite';

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>')
    .setKey('<YOUR_API_KEY>');

const project = new Project(client);

const result = await project.createEphemeralKey({
    scopes: [ProjectKeyScopes.TablesRead, ProjectKeyScopes.TablesWrite],
    duration: 600
});
```
```server-deno
import { Client, Project, ProjectKeyScopes } from "npm:node-appwrite";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>')
    .setKey('<YOUR_API_KEY>');

const project = new Project(client);

const result = await project.createEphemeralKey({
    scopes: [ProjectKeyScopes.TablesRead, ProjectKeyScopes.TablesWrite],
    duration: 600
});
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

$result = $project->createEphemeralKey(
    scopes: ['tables.read', 'tables.write'],
    duration: 600
);
```
```server-python
from appwrite.client import Client
from appwrite.services.project import Project
from appwrite.enums import ProjectKeyScopes

client = Client()
client.set_endpoint('https://<REGION>.cloud.appwrite.io/v1')
client.set_project('<PROJECT_ID>')
client.set_key('<YOUR_API_KEY>')

project = Project(client)

result = project.create_ephemeral_key(
    scopes=[ProjectKeyScopes.TABLES_READ, ProjectKeyScopes.TABLES_WRITE],
    duration=600
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

response = project.create_ephemeral_key(
    scopes: ['tables.read', 'tables.write'],
    duration: 600
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

var result = await project.CreateEphemeralKey(
    scopes: new List<ProjectKeyScopes> {ProjectKeyScopes.TablesRead, ProjectKeyScopes.TablesWrite},
    duration: 600
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

final result = await project.createEphemeralKey(
    scopes: [enums.ProjectKeyScopes.tablesRead, enums.ProjectKeyScopes.tablesWrite],
    duration: 600,
);
```
```server-kotlin
import io.appwrite.Client
import io.appwrite.enums.ProjectKeyScopes
import io.appwrite.services.Project

val client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1")
    .setProject("<PROJECT_ID>")
    .setKey("<YOUR_API_KEY>")

val project = Project(client)

val result = project.createEphemeralKey(
    scopes = listOf(ProjectKeyScopes.TABLES_READ, ProjectKeyScopes.TABLES_WRITE),
    duration = 600
)
```
```server-java
import io.appwrite.Client;
import io.appwrite.enums.ProjectKeyScopes;
import io.appwrite.coroutines.CoroutineCallback;
import io.appwrite.services.Project;

Client client = new Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1")
    .setProject("<PROJECT_ID>")
    .setKey("<YOUR_API_KEY>");

Project project = new Project(client);

project.createEphemeralKey(
    List.of(ProjectKeyScopes.TABLES_READ, ProjectKeyScopes.TABLES_WRITE), // scopes
    600, // duration
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

let result = try await project.createEphemeralKey(
    scopes: [ProjectKeyScopes.tablesRead, ProjectKeyScopes.tablesWrite],
    duration: 600
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
    result, err := project.CreateEphemeralKey(
        []string{"tables.read", "tables.write"},
        600,
    )

    if err != nil {
        panic(err)
    }

    fmt.Println(result)
}
```
```server-rust
use appwrite::Client;
use appwrite::enums::ProjectKeyScopes;
use appwrite::services::project::Project;

#[tokio::main]
async fn main() -> Result<(), Box<dyn std::error::Error>> {
    let client = Client::new()
        .set_endpoint("https://<REGION>.cloud.appwrite.io/v1")
        .set_project("<PROJECT_ID>")
        .set_key("<YOUR_API_KEY>");

    let project = Project::new(&client);

    let result = project.create_ephemeral_key(
        vec![ProjectKeyScopes::TablesRead, ProjectKeyScopes::TablesWrite], // scopes
        600, // duration
    ).await?;

    println!("{:?}", result);
    Ok(())
}
```
{% /multicode %}

## Update an API key {% #update-an-api-key %}

{% multicode %}
```server-nodejs
import { Client, Project, ProjectKeyScopes } from 'node-appwrite';

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>')
    .setKey('<YOUR_API_KEY>');

const project = new Project(client);

const result = await project.updateKey({
    keyId: '<KEY_ID>',
    name: 'Updated Key',
    scopes: [ProjectKeyScopes.DatabasesRead, ProjectKeyScopes.DatabasesWrite, ProjectKeyScopes.UsersRead],
    expire: '2027-06-30T23:59:59.000+00:00'
});
```
```server-deno
import { Client, Project, ProjectKeyScopes } from "npm:node-appwrite";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>')
    .setKey('<YOUR_API_KEY>');

const project = new Project(client);

const result = await project.updateKey({
    keyId: '<KEY_ID>',
    name: 'Updated Key',
    scopes: [ProjectKeyScopes.DatabasesRead, ProjectKeyScopes.DatabasesWrite, ProjectKeyScopes.UsersRead],
    expire: '2027-06-30T23:59:59.000+00:00'
});
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

$result = $project->updateKey(
    keyId: '<KEY_ID>',
    name: 'Updated Key',
    scopes: ['databases.read', 'databases.write', 'users.read'],
    expire: '2027-06-30T23:59:59.000+00:00'
);
```
```server-python
from appwrite.client import Client
from appwrite.services.project import Project
from appwrite.enums import ProjectKeyScopes

client = Client()
client.set_endpoint('https://<REGION>.cloud.appwrite.io/v1')
client.set_project('<PROJECT_ID>')
client.set_key('<YOUR_API_KEY>')

project = Project(client)

result = project.update_key(
    key_id='<KEY_ID>',
    name='Updated Key',
    scopes=[ProjectKeyScopes.DATABASES_READ, ProjectKeyScopes.DATABASES_WRITE, ProjectKeyScopes.USERS_READ],
    expire='2027-06-30T23:59:59.000+00:00'
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

response = project.update_key(
    key_id: '<KEY_ID>',
    name: 'Updated Key',
    scopes: ['databases.read', 'databases.write', 'users.read'],
    expire: '2027-06-30T23:59:59.000+00:00'
)
```
```server-dotnet
using Appwrite;
using Appwrite.Enums;
using Appwrite.Services;
using Appwrite.Models;

Client client = new Client()
    .SetEndPoint("https://<REGION>.cloud.appwrite.io/v1")
    .SetProject("<PROJECT_ID>")
    .SetKey("<YOUR_API_KEY>");

Project project = new Project(client);

var result = await project.UpdateKey(
    keyId: "<KEY_ID>",
    name: "Updated Key",
    scopes: new List<ProjectKeyScopes> {ProjectKeyScopes.DatabasesRead, ProjectKeyScopes.DatabasesWrite, ProjectKeyScopes.UsersRead},
    expire: "2027-06-30T23:59:59.000+00:00"
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

final result = await project.updateKey(
    keyId: '<KEY_ID>',
    name: 'Updated Key',
    scopes: [enums.ProjectKeyScopes.databasesRead, enums.ProjectKeyScopes.databasesWrite, enums.ProjectKeyScopes.usersRead],
    expire: '2027-06-30T23:59:59.000+00:00',
);
```
```server-kotlin
import io.appwrite.Client
import io.appwrite.enums.ProjectKeyScopes
import io.appwrite.services.Project

val client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1")
    .setProject("<PROJECT_ID>")
    .setKey("<YOUR_API_KEY>")

val project = Project(client)

val result = project.updateKey(
    keyId = "<KEY_ID>",
    name = "Updated Key",
    scopes = listOf(ProjectKeyScopes.DATABASES_READ, ProjectKeyScopes.DATABASES_WRITE, ProjectKeyScopes.USERS_READ),
    expire = "2027-06-30T23:59:59.000+00:00"
)
```
```server-java
import io.appwrite.Client;
import io.appwrite.enums.ProjectKeyScopes;
import io.appwrite.coroutines.CoroutineCallback;
import io.appwrite.services.Project;

Client client = new Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1")
    .setProject("<PROJECT_ID>")
    .setKey("<YOUR_API_KEY>");

Project project = new Project(client);

project.updateKey(
    "<KEY_ID>",                                      // keyId
    "Updated Key",                                   // name
    List.of(ProjectKeyScopes.DATABASES_READ, ProjectKeyScopes.DATABASES_WRITE, ProjectKeyScopes.USERS_READ), // scopes
    "2027-06-30T23:59:59.000+00:00",                // expire
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

let result = try await project.updateKey(
    keyId: "<KEY_ID>",
    name: "Updated Key",
    scopes: [ProjectKeyScopes.databasesRead, ProjectKeyScopes.databasesWrite, ProjectKeyScopes.usersRead],
    expire: "2027-06-30T23:59:59.000+00:00"
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
    result, err := project.UpdateKey(
        "<KEY_ID>",
        "Updated Key",
        []string{"databases.read", "databases.write", "users.read"},
        project.WithUpdateKeyExpire("2027-06-30T23:59:59.000+00:00"),
    )

    if err != nil {
        panic(err)
    }

    fmt.Println(result)
}
```
```server-rust
use appwrite::Client;
use appwrite::enums::ProjectKeyScopes;
use appwrite::services::project::Project;

#[tokio::main]
async fn main() -> Result<(), Box<dyn std::error::Error>> {
    let client = Client::new()
        .set_endpoint("https://<REGION>.cloud.appwrite.io/v1")
        .set_project("<PROJECT_ID>")
        .set_key("<YOUR_API_KEY>");

    let project = Project::new(&client);

    let result = project.update_key(
        "<KEY_ID>",                                    // key_id
        "Updated Key",                                 // name
        vec![ProjectKeyScopes::DatabasesRead, ProjectKeyScopes::DatabasesWrite, ProjectKeyScopes::UsersRead], // scopes
        Some("2027-06-30T23:59:59.000+00:00"),         // expire
    ).await?;

    println!("{:?}", result);
    Ok(())
}
```
{% /multicode %}

## Delete an API key {% #delete-an-api-key %}

{% multicode %}
```server-nodejs
import { Client, Project } from 'node-appwrite';

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>')
    .setKey('<YOUR_API_KEY>');

const project = new Project(client);

await project.deleteKey({
    keyId: '<KEY_ID>'
});
```
```server-deno
import { Client, Project } from "npm:node-appwrite";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>')
    .setKey('<YOUR_API_KEY>');

const project = new Project(client);

await project.deleteKey({
    keyId: '<KEY_ID>'
});
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

$project->deleteKey(
    keyId: '<KEY_ID>'
);
```
```server-python
from appwrite.client import Client
from appwrite.services.project import Project

client = Client()
client.set_endpoint('https://<REGION>.cloud.appwrite.io/v1')
client.set_project('<PROJECT_ID>')
client.set_key('<YOUR_API_KEY>')

project = Project(client)

project.delete_key(
    key_id='<KEY_ID>'
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

project.delete_key(
    key_id: '<KEY_ID>'
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

await project.DeleteKey(
    keyId: "<KEY_ID>"
);
```
```server-dart
import 'package:dart_appwrite/dart_appwrite.dart';

Client client = Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>')
    .setKey('<YOUR_API_KEY>');

Project project = Project(client);

await project.deleteKey(
    keyId: '<KEY_ID>',
);
```
```server-kotlin
import io.appwrite.Client
import io.appwrite.services.Project

val client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1")
    .setProject("<PROJECT_ID>")
    .setKey("<YOUR_API_KEY>")

val project = Project(client)

project.deleteKey(
    keyId = "<KEY_ID>"
)
```
```server-java
import io.appwrite.Client;
import io.appwrite.coroutines.CoroutineCallback;
import io.appwrite.services.Project;

Client client = new Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1")
    .setProject("<PROJECT_ID>")
    .setKey("<YOUR_API_KEY>");

Project project = new Project(client);

project.deleteKey(
    "<KEY_ID>",                                      // keyId
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

try await project.deleteKey(
    keyId: "<KEY_ID>"
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
    _, err := project.DeleteKey(
        "<KEY_ID>",
    )

    if err != nil {
        panic(err)
    }

    fmt.Println("API key deleted")
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

    project.delete_key(
        "<KEY_ID>",
    ).await?;

    Ok(())
}
```
{% /multicode %}

# Scopes {% #scopes %}

When adding a new API key, you choose which scopes to grant. Scopes are grouped by service, matching the categories shown in the Appwrite Console.

{% accordion %}
{% accordion_item title="Auth" %}

| Name                  | Description                                                                     |
|-----------------------|---------------------------------------------------------------------------------|
| `sessions.write`      | Access to create, update, and delete user sessions                              |
| `users.read`          | Access to read your project's users                                             |
| `users.write`         | Access to create, update, and delete your project's users                       |
| `teams.read`          | Access to read your project's teams                                             |
| `teams.write`         | Access to create, update, and delete your project's teams                       |

{% /accordion_item %}
{% accordion_item title="Databases" %}

| Name                  | Description                                                                     |
|-----------------------|---------------------------------------------------------------------------------|
| `databases.read`      | Access to read your project's databases                                         |
| `databases.write`     | Access to create, update, and delete your project's databases                   |
| `tables.read`         | Access to read your project's database tables                                   |
| `tables.write`        | Access to create, update, and delete your project's database tables             |
| `columns.read`        | Access to read your project's database table columns                            |
| `columns.write`       | Access to create, update, and delete your project's database table columns      |
| `indexes.read`        | Access to read your project's database table indexes                            |
| `indexes.write`       | Access to create, update, and delete your project's database table indexes      |
| `rows.read`           | Access to read your project's database rows                                     |
| `rows.write`          | Access to create, update, and delete your project's database rows               |

{% /accordion_item %}
{% accordion_item title="Functions" %}

| Name                  | Description                                                                     |
|-----------------------|---------------------------------------------------------------------------------|
| `functions.read`      | Access to read your project's functions and code deployments                    |
| `functions.write`     | Access to create, update, and delete your project's functions and code deployments|
| `execution.read`      | Access to read your project's execution logs                                    |
| `execution.write`     | Access to execute your project's functions                                      |

{% /accordion_item %}
{% accordion_item title="Storage" %}

| Name                  | Description                                                                     |
|-----------------------|---------------------------------------------------------------------------------|
| `files.read`          | Access to read your project's storage files and preview images                  |
| `files.write`         | Access to create, update, and delete your project's storage files               |
| `buckets.read`        | Access to read your project's storage buckets                                   |
| `buckets.write`       | Access to create, update, and delete your project's storage buckets             |

{% /accordion_item %}
{% accordion_item title="Messaging" %}

| Name                  | Description                                                                     |
|-----------------------|---------------------------------------------------------------------------------|
| `providers.read`      | Access to read your project's providers                                         |
| `providers.write`     | Access to create, update, and delete your project's providers                   |
| `messages.read`       | Access to read your project's messages                                          |
| `messages.write`      | Access to create, update, and delete your project's messages                    |
| `topics.read`         | Access to read your project's topics                                            |
| `topics.write`        | Access to create, update, and delete your project's topics                      |
| `subscribers.read`    | Access to read your project's subscribers                                       |
| `subscribers.write`   | Access to create, update, and delete your project's subscribers                 |
| `targets.read`        | Access to read your project's targets                                           |
| `targets.write`       | Access to create, update, and delete your project's targets                     |

{% /accordion_item %}
{% accordion_item title="Sites" %}

| Name                  | Description                                                                     |
|-----------------------|---------------------------------------------------------------------------------|
| `sites.read`          | Access to read your project's sites and deployments                             |
| `sites.write`         | Access to create, update, and delete your project's sites and deployments       |
| `log.read`            | Access to read your site's logs                                                 |
| `log.write`           | Access to update and delete your site's logs                                    |

{% /accordion_item %}
{% accordion_item title="Other" %}

| Name                  | Description                                                                     |
|-----------------------|---------------------------------------------------------------------------------|
| `locale.read`         | Access to your project's Locale service                                         |
| `avatars.read`        | Access to your project's Avatars service                                        |
| `health.read`         | Access to read your project's health status                                     |
| `migrations.read`     | Access to read your project's migrations                                        |
| `migrations.write`    | Access to create, update, and delete your project's migrations                  |
| `tokens.read`         | Access to read your project's tokens                                            |
| `tokens.write`        | Access to create, update, and delete your project's tokens                      |
| `webhooks.read`       | Access to read your project's webhooks                                          |
| `webhooks.write`      | Access to create, update, and delete your project's webhooks                    |
| `keys.read`           | Access to read your project's API keys                                          |
| `keys.write`          | Access to create, update, and delete your project's API keys                    |
| `rules.read`          | Access to read your project's proxy rules                                       |
| `rules.write`         | Access to create, update, and delete your project's proxy rules                 |
| `vcs.read`            | Access to read your project's VCS repositories                                  |
| `vcs.write`           | Access to create, update, and delete your project's VCS repositories            |
| `assistant.read`      | Access to read the Assistant service                                            |

{% /accordion_item %}
{% /accordion %}

---
layout: article
title: Platforms
description: Register Web, Apple, Android, Windows, and Linux apps to your Appwrite project programmatically using server SDKs.
---

Each Appwrite project has a list of registered platforms. A platform identifies a client application that is allowed to talk to your project's API: a Web platform pins an allowed hostname for CORS, while Apple, Android, Windows, and Linux platforms pin a bundle, package, or application ID for native clients.

Platforms can be added from the Appwrite Console, or programmatically through any server SDK using the Project service.

# Manage from the Console {% #manage-console %}

{% only_dark %}
![Project platforms in the Appwrite Console](/images/docs/project/dark/platforms.avif)
{% /only_dark %}
{% only_light %}
![Project platforms in the Appwrite Console](/images/docs/project/platforms.avif)
{% /only_light %}

To add a platform manually:

1. Open your project in the Appwrite Console.
2. On the project **Overview**, scroll to the **Integrations** card and select the **Platforms** tab.
3. Click **Add platform** and choose the platform type (Web, Flutter, Android, Apple, or React Native).
4. Fill in the name and the platform's identifier (hostname for Web, bundle or package ID for native), then click **Create platform**.
5. To rename or remove a platform later, click its row and use the edit dialog or the **Delete** button.

# Platform types {% #platform-types %}

The Project service exposes a dedicated create method per platform type. Each method takes a unique `platformId`, a display `name`, and the platform's identifier:

| Type | Method | Identifier param |
| --- | --- | --- |
| Web | `createWebPlatform` | `hostname` (e.g. `app.example.com`) |
| Apple | `createApplePlatform` | `bundleIdentifier` (e.g. `com.example.app`) |
| Android | `createAndroidPlatform` | `applicationId` (e.g. `com.example.app`) |
| Windows | `createWindowsPlatform` | `packageIdentifierName` |
| Linux | `createLinuxPlatform` | `packageName` |

Flutter and React Native platforms shown in the Console map to the underlying Apple, Android, or Web type based on the target you pick.

# Register a platform {% #register-platform %}

The example below registers a Web platform. Swap the method name and identifier param to register Apple, Android, Windows, or Linux platforms.

{% info title="Required scopes" %}
The API key used for these calls needs `platforms.write` to create, update, or delete platforms, and `platforms.read` to list or fetch them.
{% /info %}

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
use appwrite::services::project::Project;

#[tokio::main]
async fn main() -> Result<(), Box<dyn std::error::Error>> {
    let client = Client::new()
        .set_endpoint("https://<REGION>.cloud.appwrite.io/v1")
        .set_project("<PROJECT_ID>")
        .set_key("<YOUR_API_KEY>");

    let project = Project::new(&client);

    let result = project.create_web_platform(
        ID::unique(),
        "My Web App",
        "app.example.com",
    ).await?;

    println!("{:?}", result);
    Ok(())
}
```
```bash
appwrite project create-web-platform \
    --platform-id 'unique()' \
    --name "My Web App" \
    --hostname app.example.com
```
{% /multicode %}

# List platforms {% #list-platforms %}

{% multicode %}
```server-nodejs
import { Client, Project } from 'node-appwrite';

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>')
    .setKey('<YOUR_API_KEY>');

const project = new Project(client);

const result = await project.listPlatforms({
    queries: [],
    total: false
});
```
```server-deno
import { Client, Project } from "npm:node-appwrite";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>')
    .setKey('<YOUR_API_KEY>');

const project = new Project(client);

const result = await project.listPlatforms({
    queries: [],
    total: false
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

$result = $project->listPlatforms(
    queries: [],
    total: false
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

result = project.list_platforms(
    queries = [],
    total = False
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

response = project.list_platforms(
    queries: [],
    total: false
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

var result = await project.ListPlatforms(
    queries: new List<string>(),
    total: false
);
```
```server-dart
import 'package:dart_appwrite/dart_appwrite.dart';

Client client = Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>')
    .setKey('<YOUR_API_KEY>');

Project project = Project(client);

final result = await project.listPlatforms(
    queries: [],
    total: false,
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

val response = project.listPlatforms(
    queries = listOf(),
    total = false
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

project.listPlatforms(
    List.of(), // queries
    false, // total
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

let result = try await project.listPlatforms(
    queries: [],
    total: false
)
```
```server-go
package main

import (
    "fmt"
    "github.com/appwrite/sdk-for-go/appwrite"
    "github.com/appwrite/sdk-for-go/project"
)

func main() {
    client := appwrite.NewClient(
        appwrite.WithEndpoint("https://<REGION>.cloud.appwrite.io/v1"),
        appwrite.WithProject("<PROJECT_ID>"),
        appwrite.WithKey("<YOUR_API_KEY>"),
    )

    service := appwrite.NewProject(client)
    result, err := service.ListPlatforms(
        project.WithListPlatformsQueries([]interface{}{}),
        project.WithListPlatformsTotal(false),
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

    let result = project.list_platforms(
        Some(vec![]),
        Some(false),
    ).await?;

    println!("{:?}", result);
    Ok(())
}
```
```bash
appwrite project list-platforms \
    --limit 25
```
{% /multicode %}

# Get a platform {% #get-platform %}

Fetch a single platform by its ID. The response includes the platform's type-specific fields, such as `hostname` for Web or `bundleIdentifier` for Apple.

{% multicode %}
```server-nodejs
import { Client, Project } from 'node-appwrite';

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>')
    .setKey('<YOUR_API_KEY>');

const project = new Project(client);

const result = await project.getPlatform({
    platformId: '<PLATFORM_ID>'
});
```
```server-deno
import { Client, Project } from "npm:node-appwrite";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>')
    .setKey('<YOUR_API_KEY>');

const project = new Project(client);

const result = await project.getPlatform({
    platformId: '<PLATFORM_ID>'
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

$result = $project->getPlatform(
    platformId: '<PLATFORM_ID>'
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

result = project.get_platform(
    platform_id = '<PLATFORM_ID>'
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

result = project.get_platform(
    platform_id: '<PLATFORM_ID>'
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

var result = await project.GetPlatform(
    platformId: "<PLATFORM_ID>"
);
```
```server-dart
import 'package:dart_appwrite/dart_appwrite.dart';

Client client = Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>')
    .setKey('<YOUR_API_KEY>');

Project project = Project(client);

final result = await project.getPlatform(
    platformId: '<PLATFORM_ID>',
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

val result = project.getPlatform(
    platformId = "<PLATFORM_ID>"
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

project.getPlatform(
    "<PLATFORM_ID>", // platformId
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

let result = try await project.getPlatform(
    platformId: "<PLATFORM_ID>"
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

    service := appwrite.NewProject(client)
    result, err := service.GetPlatform(
        "<PLATFORM_ID>",
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

    let result = project.get_platform(
        "<PLATFORM_ID>",
    ).await?;

    println!("{:?}", result);
    Ok(())
}
```
```bash
appwrite project get-platform \
    --platform-id <PLATFORM_ID>
```
{% /multicode %}

# Update a platform {% #update-platform %}

Each platform type has a matching update method (`updateWebPlatform`, `updateApplePlatform`, and so on). The example below updates a Web platform's name and hostname.

{% multicode %}
```server-nodejs
import { Client, Project } from 'node-appwrite';

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>')
    .setKey('<YOUR_API_KEY>');

const project = new Project(client);

const result = await project.updateWebPlatform({
    platformId: '<PLATFORM_ID>',
    name: 'Renamed Web App',
    hostname: 'app.example.com'
});
```
```server-deno
import { Client, Project } from "npm:node-appwrite";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>')
    .setKey('<YOUR_API_KEY>');

const project = new Project(client);

const result = await project.updateWebPlatform({
    platformId: '<PLATFORM_ID>',
    name: 'Renamed Web App',
    hostname: 'app.example.com'
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

$result = $project->updateWebPlatform(
    platformId: '<PLATFORM_ID>',
    name: 'Renamed Web App',
    hostname: 'app.example.com'
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

result = project.update_web_platform(
    platform_id = '<PLATFORM_ID>',
    name = 'Renamed Web App',
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

response = project.update_web_platform(
    platform_id: '<PLATFORM_ID>',
    name: 'Renamed Web App',
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

var result = await project.UpdateWebPlatform(
    platformId: "<PLATFORM_ID>",
    name: "Renamed Web App",
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

final result = await project.updateWebPlatform(
    platformId: '<PLATFORM_ID>',
    name: 'Renamed Web App',
    hostname: 'app.example.com',
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

val response = project.updateWebPlatform(
    platformId = "<PLATFORM_ID>",
    name = "Renamed Web App",
    hostname = "app.example.com"
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

project.updateWebPlatform(
    "<PLATFORM_ID>", // platformId
    "Renamed Web App", // name
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

let result = try await project.updateWebPlatform(
    platformId: "<PLATFORM_ID>",
    name: "Renamed Web App",
    hostname: "app.example.com"
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

    service := appwrite.NewProject(client)
    result, err := service.UpdateWebPlatform(
        "<PLATFORM_ID>",
        "Renamed Web App",
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
use appwrite::services::project::Project;

#[tokio::main]
async fn main() -> Result<(), Box<dyn std::error::Error>> {
    let client = Client::new()
        .set_endpoint("https://<REGION>.cloud.appwrite.io/v1")
        .set_project("<PROJECT_ID>")
        .set_key("<YOUR_API_KEY>");

    let project = Project::new(&client);

    let result = project.update_web_platform(
        "<PLATFORM_ID>",
        "Renamed Web App",
        "app.example.com",
    ).await?;

    println!("{:?}", result);
    Ok(())
}
```
```bash
appwrite project update-web-platform \
    --platform-id <PLATFORM_ID> \
    --name "Renamed Web App" \
    --hostname app.example.com
```
{% /multicode %}

# Delete a platform {% #delete-platform %}

{% multicode %}
```server-nodejs
import { Client, Project } from 'node-appwrite';

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>')
    .setKey('<YOUR_API_KEY>');

const project = new Project(client);

await project.deletePlatform({
    platformId: '<PLATFORM_ID>'
});
```
```server-deno
import { Client, Project } from "npm:node-appwrite";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>')
    .setKey('<YOUR_API_KEY>');

const project = new Project(client);

await project.deletePlatform({
    platformId: '<PLATFORM_ID>'
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

$project->deletePlatform(
    platformId: '<PLATFORM_ID>'
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

project.delete_platform(
    platform_id = '<PLATFORM_ID>'
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

project.delete_platform(
    platform_id: '<PLATFORM_ID>'
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

await project.DeletePlatform(
    platformId: "<PLATFORM_ID>"
);
```
```server-dart
import 'package:dart_appwrite/dart_appwrite.dart';

Client client = Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>')
    .setKey('<YOUR_API_KEY>');

Project project = Project(client);

await project.deletePlatform(
    platformId: '<PLATFORM_ID>',
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

project.deletePlatform(
    platformId = "<PLATFORM_ID>"
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

project.deletePlatform(
    "<PLATFORM_ID>", // platformId
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

try await project.deletePlatform(
    platformId: "<PLATFORM_ID>"
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

    service := appwrite.NewProject(client)
    result, err := service.DeletePlatform(
        "<PLATFORM_ID>",
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

    project.delete_platform(
        "<PLATFORM_ID>",
    ).await?;

    Ok(())
}
```
```bash
appwrite project delete-platform \
    --platform-id <PLATFORM_ID>
```
{% /multicode %}

# Benefits {% #benefits %}

- **Repeatable provisioning.** Script the full set of platforms a project needs and recreate it on demand, without clicking through the Console.
- **Environment parity.** Keep dev, staging, and production projects in sync by running the same script against each one.
- **CI and automation.** Add or rotate platforms from a CI job when a new preview environment spins up or a domain changes.

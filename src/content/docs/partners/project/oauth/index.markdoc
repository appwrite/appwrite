---
layout: article
title: OAuth providers
description: Configure OAuth2 sign-in providers for your project from the Console or programmatically with a Server SDK.
---

OAuth2 providers let your users sign in with accounts they already have, such as GitHub, Google, or Apple. Each provider stores a client ID and client secret at the project level, and can be enabled or disabled independently.

You can configure providers from the Appwrite Console or programmatically with a [Server SDK](/docs/sdks#server). Each provider has its own update method (`updateOAuth2GitHub`, `updateOAuth2Google`, and so on), the read methods `listOAuth2Providers` and `getOAuth2Provider` cover all of them.

# Configure from the Console {% #configure-console %}

{% only_dark %}
![OAuth2 providers in the Appwrite Console](/images/docs/project/dark/oauth-providers.avif)
{% /only_dark %}
{% only_light %}
![OAuth2 providers in the Appwrite Console](/images/docs/project/oauth-providers.avif)
{% /only_light %}

To configure a provider from the Console:

1. Open your project in the Appwrite Console.
2. Navigate to **Auth** in the sidebar, then open the **Settings** tab.
3. Find the provider in the **OAuth2 Providers** list and click it.
4. Enter the credentials from the provider (the field names vary per provider, see [Available providers](#available-providers)).
5. Toggle the provider on and click **Update**.

# Configure a provider {% #configure-provider %}

Each provider has a dedicated update method named `updateOAuth2<Provider>`. The example below configures GitHub. Swap the method name and credential fields to configure a different provider, the field names per provider are listed in [Available providers](#available-providers).

{% info title="Required scopes" %}
The API key used for these calls needs `oauth2.write` to configure providers, and `oauth2.read` to list or fetch them.
{% /info %}

{% info title="Enabling validates credentials" %}
Setting `enabled` to `true` triggers end-to-end validation of the credentials against the provider, and the request throws if they are invalid. To store credentials without activating the provider yet, send them with `enabled` set to `false`.
{% /info %}

{% multicode %}
```server-nodejs
import { Client, Project } from 'node-appwrite';

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>')
    .setKey('<YOUR_API_KEY>');

const project = new Project(client);

const result = await project.updateOAuth2GitHub({
    clientId: '<CLIENT_ID>',
    clientSecret: '<CLIENT_SECRET>',
    enabled: true
});
```
```server-deno
import { Client, Project } from "npm:node-appwrite";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>')
    .setKey('<YOUR_API_KEY>');

const project = new Project(client);

const result = await project.updateOAuth2GitHub({
    clientId: '<CLIENT_ID>',
    clientSecret: '<CLIENT_SECRET>',
    enabled: true
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

$result = $project->updateOAuth2GitHub(
    clientId: '<CLIENT_ID>',
    clientSecret: '<CLIENT_SECRET>',
    enabled: true
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

result = project.update_o_auth2_git_hub(
    client_id = '<CLIENT_ID>',
    client_secret = '<CLIENT_SECRET>',
    enabled = True
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

response = project.update_o_auth2_git_hub(
    client_id: '<CLIENT_ID>',
    client_secret: '<CLIENT_SECRET>',
    enabled: true
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

var result = await project.UpdateOAuth2GitHub(
    clientId: "<CLIENT_ID>",
    clientSecret: "<CLIENT_SECRET>",
    enabled: true
);
```
```server-dart
import 'package:dart_appwrite/dart_appwrite.dart';

Client client = Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>')
    .setKey('<YOUR_API_KEY>');

Project project = Project(client);

final result = await project.updateOAuth2GitHub(
    clientId: '<CLIENT_ID>',
    clientSecret: '<CLIENT_SECRET>',
    enabled: true,
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

val response = project.updateOAuth2GitHub(
    clientId = "<CLIENT_ID>",
    clientSecret = "<CLIENT_SECRET>",
    enabled = true
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

project.updateOAuth2GitHub(
    "<CLIENT_ID>", // clientId
    "<CLIENT_SECRET>", // clientSecret
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

let client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1")
    .setProject("<PROJECT_ID>")
    .setKey("<YOUR_API_KEY>")

let project = Project(client)

let result = try await project.updateOAuth2GitHub(
    clientId: "<CLIENT_ID>",
    clientSecret: "<CLIENT_SECRET>",
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

    service := appwrite.NewProject(client)
    result, err := service.UpdateOAuth2GitHub(
        service.WithUpdateOAuth2GitHubClientId("<CLIENT_ID>"),
        service.WithUpdateOAuth2GitHubClientSecret("<CLIENT_SECRET>"),
        service.WithUpdateOAuth2GitHubEnabled(true),
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

    let result = project.update_o_auth2_git_hub(
        Some("<CLIENT_ID>"),
        Some("<CLIENT_SECRET>"),
        Some(true),
    ).await?;

    println!("{:?}", result);
    Ok(())
}
```
```bash
appwrite project update-o-auth-2-git-hub \
    --client-id "<CLIENT_ID>" \
    --client-secret "<CLIENT_SECRET>" \
    --enabled true
```
{% /multicode %}

## Providers with extra fields {% #provider-extra-fields %}

Some providers take more than a client ID and secret. Google, for example, accepts an additional `prompt` parameter that controls the consent screen behavior:

{% multicode %}
```server-nodejs
import { Client, Project, ProjectOAuth2GooglePrompt } from 'node-appwrite';

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>')
    .setKey('<YOUR_API_KEY>');

const project = new Project(client);

const result = await project.updateOAuth2Google({
    clientId: '<CLIENT_ID>',
    clientSecret: '<CLIENT_SECRET>',
    prompt: [ProjectOAuth2GooglePrompt.SelectAccount],
    enabled: true
});
```
```server-deno
import { Client, Project, ProjectOAuth2GooglePrompt } from "npm:node-appwrite";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>')
    .setKey('<YOUR_API_KEY>');

const project = new Project(client);

const result = await project.updateOAuth2Google({
    clientId: '<CLIENT_ID>',
    clientSecret: '<CLIENT_SECRET>',
    prompt: [ProjectOAuth2GooglePrompt.SelectAccount],
    enabled: true
});
```
```server-php
<?php

use Appwrite\Client;
use Appwrite\Enums\ProjectOAuth2GooglePrompt;
use Appwrite\Services\Project;

$client = new Client();

$client
    ->setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    ->setProject('<PROJECT_ID>')
    ->setKey('<YOUR_API_KEY>');

$project = new Project($client);

$result = $project->updateOAuth2Google(
    clientId: '<CLIENT_ID>',
    clientSecret: '<CLIENT_SECRET>',
    prompt: [ProjectOAuth2GooglePrompt::SELECTACCOUNT()],
    enabled: true
);
```
```server-python
from appwrite.client import Client
from appwrite.enums import ProjectOAuth2GooglePrompt
from appwrite.services.project import Project

client = Client()
client.set_endpoint('https://<REGION>.cloud.appwrite.io/v1')
client.set_project('<PROJECT_ID>')
client.set_key('<YOUR_API_KEY>')

project = Project(client)

result = project.update_o_auth2_google(
    client_id = '<CLIENT_ID>',
    client_secret = '<CLIENT_SECRET>',
    prompt = [ProjectOAuth2GooglePrompt.SELECT_ACCOUNT],
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

response = project.update_o_auth2_google(
    client_id: '<CLIENT_ID>',
    client_secret: '<CLIENT_SECRET>',
    prompt: [ProjectOAuth2GooglePrompt::SELECT_ACCOUNT],
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

var result = await project.UpdateOAuth2Google(
    clientId: "<CLIENT_ID>",
    clientSecret: "<CLIENT_SECRET>",
    prompt: new List<ProjectOAuth2GooglePrompt> {ProjectOAuth2GooglePrompt.SelectAccount},
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

final result = await project.updateOAuth2Google(
    clientId: '<CLIENT_ID>',
    clientSecret: '<CLIENT_SECRET>',
    prompt: [enums.ProjectOAuth2GooglePrompt.selectAccount],
    enabled: true,
);
```
```server-kotlin
import io.appwrite.Client
import io.appwrite.enums.ProjectOAuth2GooglePrompt
import io.appwrite.services.Project

val client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1")
    .setProject("<PROJECT_ID>")
    .setKey("<YOUR_API_KEY>")

val project = Project(client)

val response = project.updateOAuth2Google(
    clientId = "<CLIENT_ID>",
    clientSecret = "<CLIENT_SECRET>",
    prompt = listOf(ProjectOAuth2GooglePrompt.SELECT_ACCOUNT),
    enabled = true
)
```
```server-java
import io.appwrite.Client;
import io.appwrite.coroutines.CoroutineCallback;
import io.appwrite.enums.ProjectOAuth2GooglePrompt;
import io.appwrite.services.Project;

Client client = new Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1")
    .setProject("<PROJECT_ID>")
    .setKey("<YOUR_API_KEY>");

Project project = new Project(client);

project.updateOAuth2Google(
    "<CLIENT_ID>", // clientId
    "<CLIENT_SECRET>", // clientSecret
    listOf(ProjectOAuth2GooglePrompt.SELECT_ACCOUNT), // prompt
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

let result = try await project.updateOAuth2Google(
    clientId: "<CLIENT_ID>",
    clientSecret: "<CLIENT_SECRET>",
    prompt: [.selectAccount],
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

    service := appwrite.NewProject(client)
    result, err := service.UpdateOAuth2Google(
        service.WithUpdateOAuth2GoogleClientId("<CLIENT_ID>"),
        service.WithUpdateOAuth2GoogleClientSecret("<CLIENT_SECRET>"),
        service.WithUpdateOAuth2GooglePrompt([]string{"select_account"}),
        service.WithUpdateOAuth2GoogleEnabled(true),
    )

    if err != nil {
        panic(err)
    }

    fmt.Println(result)
}
```
```server-rust
use appwrite::Client;
use appwrite::enums::ProjectOAuth2GooglePrompt;
use appwrite::services::project::Project;

#[tokio::main]
async fn main() -> Result<(), Box<dyn std::error::Error>> {
    let client = Client::new()
        .set_endpoint("https://<REGION>.cloud.appwrite.io/v1")
        .set_project("<PROJECT_ID>")
        .set_key("<YOUR_API_KEY>");

    let project = Project::new(&client);

    let result = project.update_o_auth2_google(
        Some("<CLIENT_ID>"),
        Some("<CLIENT_SECRET>"),
        Some(vec![ProjectOAuth2GooglePrompt::SelectAccount]),
        Some(true),
    ).await?;

    println!("{:?}", result);
    Ok(())
}
```
```bash
appwrite project update-o-auth-2-google \
    --client-id "<CLIENT_ID>" \
    --client-secret "<CLIENT_SECRET>" \
    --prompt "select_account" \
    --enabled true
```
{% /multicode %}

# Available providers {% #available-providers %}

Every provider has its own `updateOAuth2<Provider>` method. The credential field names differ per provider, use the columns below to map the provider's credentials to the method's parameters.

| Provider | SDK method | App ID field | App secret field | Other fields |
| --- | --- | --- | --- | --- |
| Amazon | `updateOAuth2Amazon` | `clientId` | `clientSecret` | |
| Apple | `updateOAuth2Apple` | `serviceId` | `keyId` | `teamId` |
| Auth0 | `updateOAuth2Auth0` | `clientId` | `clientSecret` | `endpoint` |
| Authentik | `updateOAuth2Authentik` | `clientId` | `clientSecret` | `endpoint` |
| Autodesk | `updateOAuth2Autodesk` | `clientId` | `clientSecret` | |
| Bitbucket | `updateOAuth2Bitbucket` | `key` | `secret` | |
| Bitly | `updateOAuth2Bitly` | `clientId` | `clientSecret` | |
| Box | `updateOAuth2Box` | `clientId` | `clientSecret` | |
| Dailymotion | `updateOAuth2Dailymotion` | `apiKey` | `apiSecret` | |
| Discord | `updateOAuth2Discord` | `clientId` | `clientSecret` | |
| Disqus | `updateOAuth2Disqus` | `publicKey` | `secretKey` | |
| Dropbox | `updateOAuth2Dropbox` | `appKey` | `appSecret` | |
| Etsy | `updateOAuth2Etsy` | `keyString` | `sharedSecret` | |
| Facebook | `updateOAuth2Facebook` | `appId` | `appSecret` | |
| Figma | `updateOAuth2Figma` | `clientId` | `clientSecret` | |
| FusionAuth | `updateOAuth2FusionAuth` | `clientId` | `clientSecret` | `endpoint` |
| GitHub | `updateOAuth2GitHub` | `clientId` | `clientSecret` | |
| GitLab | `updateOAuth2Gitlab` | `applicationId` | `secret` | `endpoint` |
| Google | `updateOAuth2Google` | `clientId` | `clientSecret` | `prompt` |
| Keycloak | `updateOAuth2Keycloak` | `clientId` | `clientSecret` | `endpoint`, `realmName` |
| Kick | `updateOAuth2Kick` | `clientId` | `clientSecret` | |
| LinkedIn | `updateOAuth2Linkedin` | `clientId` | `primaryClientSecret` | |
| Microsoft | `updateOAuth2Microsoft` | `applicationId` | `applicationSecret` | `tenant` |
| Notion | `updateOAuth2Notion` | `oauthClientId` | `oauthClientSecret` | |
| OIDC | `updateOAuth2Oidc` | `clientId` | `clientSecret` | `wellKnownURL`, `authorizationURL`, `tokenURL`, `userInfoURL` |
| Okta | `updateOAuth2Okta` | `clientId` | `clientSecret` | `domain`, `authorizationServerId` |
| PayPal | `updateOAuth2Paypal` | `clientId` | `secretKey` | |
| PayPal Sandbox | `updateOAuth2PaypalSandbox` | `clientId` | `secretKey` | |
| Podio | `updateOAuth2Podio` | `clientId` | `clientSecret` | |
| Salesforce | `updateOAuth2Salesforce` | `customerKey` | `customerSecret` | |
| Slack | `updateOAuth2Slack` | `clientId` | `clientSecret` | |
| Spotify | `updateOAuth2Spotify` | `clientId` | `clientSecret` | |
| Stripe | `updateOAuth2Stripe` | `clientId` | `apiSecretKey` | |
| Tradeshift | `updateOAuth2Tradeshift` | | | `enabled` only |
| Tradeshift Sandbox | `updateOAuth2TradeshiftSandbox` | | | `enabled` only |
| Twitch | `updateOAuth2Twitch` | `clientId` | `clientSecret` | |
| WordPress | `updateOAuth2WordPress` | `clientId` | `clientSecret` | |
| X | `updateOAuth2X` | `customerKey` | `secretKey` | |
| Yahoo | `updateOAuth2Yahoo` | `clientId` | `clientSecret` | |
| Yandex | `updateOAuth2Yandex` | `clientId` | `clientSecret` | |
| Zoho | `updateOAuth2Zoho` | `clientId` | `clientSecret` | |
| Zoom | `updateOAuth2Zoom` | `clientId` | `clientSecret` | |

{% info title="Apple is in beta" %}
The Apple provider is currently in beta.
{% /info %}

# List providers {% #list-providers %}

List every OAuth2 provider and its current configuration state.

{% multicode %}
```server-nodejs
import { Client, Project } from 'node-appwrite';

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>')
    .setKey('<YOUR_API_KEY>');

const project = new Project(client);

const result = await project.listOAuth2Providers({
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

const result = await project.listOAuth2Providers({
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

$result = $project->listOAuth2Providers(
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

result = project.list_o_auth2_providers(
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

response = project.list_o_auth2_providers(
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

var result = await project.ListOAuth2Providers(
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

final result = await project.listOAuth2Providers(
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

val response = project.listOAuth2Providers(
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

project.listOAuth2Providers(
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

let result = try await project.listOAuth2Providers(
    queries: [],
    total: false
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
    result, err := service.ListOAuth2Providers(
        service.WithListOAuth2ProvidersQueries([]string{}),
        service.WithListOAuth2ProvidersTotal(false),
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

    let result = project.list_o_auth2_providers(
        Some(vec![]),
        Some(false),
    ).await?;

    println!("{:?}", result);
    Ok(())
}
```
```bash
appwrite project list-o-auth-2-providers \
    --limit 25
```
{% /multicode %}

# Get a provider {% #get-provider %}

Fetch a single provider's configuration by its provider ID.

{% multicode %}
```server-nodejs
import { Client, Project, ProjectOAuthProviderId } from 'node-appwrite';

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>')
    .setKey('<YOUR_API_KEY>');

const project = new Project(client);

const result = await project.getOAuth2Provider({
    providerId: ProjectOAuthProviderId.Github
});
```
```server-deno
import { Client, Project, ProjectOAuthProviderId } from "npm:node-appwrite";

const client = new Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<PROJECT_ID>')
    .setKey('<YOUR_API_KEY>');

const project = new Project(client);

const result = await project.getOAuth2Provider({
    providerId: ProjectOAuthProviderId.Github
});
```
```server-php
<?php

use Appwrite\Client;
use Appwrite\Enums\ProjectOAuthProviderId;
use Appwrite\Services\Project;

$client = new Client();

$client
    ->setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    ->setProject('<PROJECT_ID>')
    ->setKey('<YOUR_API_KEY>');

$project = new Project($client);

$result = $project->getOAuth2Provider(
    providerId: ProjectOAuthProviderId::GITHUB()
);
```
```server-python
from appwrite.client import Client
from appwrite.enums import ProjectOAuthProviderId
from appwrite.services.project import Project

client = Client()
client.set_endpoint('https://<REGION>.cloud.appwrite.io/v1')
client.set_project('<PROJECT_ID>')
client.set_key('<YOUR_API_KEY>')

project = Project(client)

result = project.get_o_auth2_provider(
    provider_id = ProjectOAuthProviderId.GITHUB
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

response = project.get_o_auth2_provider(
    provider_id: ProjectOAuthProviderId::GITHUB
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

var result = await project.GetOAuth2Provider(
    providerId: ProjectOAuthProviderId.Github
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

final result = await project.getOAuth2Provider(
    providerId: enums.ProjectOAuthProviderId.github,
);
```
```server-kotlin
import io.appwrite.Client
import io.appwrite.enums.ProjectOAuthProviderId
import io.appwrite.services.Project

val client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1")
    .setProject("<PROJECT_ID>")
    .setKey("<YOUR_API_KEY>")

val project = Project(client)

val response = project.getOAuth2Provider(
    providerId = ProjectOAuthProviderId.GITHUB
)
```
```server-java
import io.appwrite.Client;
import io.appwrite.coroutines.CoroutineCallback;
import io.appwrite.enums.ProjectOAuthProviderId;
import io.appwrite.services.Project;

Client client = new Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1")
    .setProject("<PROJECT_ID>")
    .setKey("<YOUR_API_KEY>");

Project project = new Project(client);

project.getOAuth2Provider(
    ProjectOAuthProviderId.GITHUB, // providerId
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

let result = try await project.getOAuth2Provider(
    providerId: .github
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
    result, err := service.GetOAuth2Provider(
        "github",
    )

    if err != nil {
        panic(err)
    }

    fmt.Println(result)
}
```
```server-rust
use appwrite::Client;
use appwrite::enums::ProjectOAuthProviderId;
use appwrite::services::project::Project;

#[tokio::main]
async fn main() -> Result<(), Box<dyn std::error::Error>> {
    let client = Client::new()
        .set_endpoint("https://<REGION>.cloud.appwrite.io/v1")
        .set_project("<PROJECT_ID>")
        .set_key("<YOUR_API_KEY>");

    let project = Project::new(&client);

    let result = project.get_o_auth2_provider(
        ProjectOAuthProviderId::Github,
    ).await?;

    println!("{:?}", result);
    Ok(())
}
```
```bash
appwrite project get-o-auth-2-provider \
    --provider-id github
```
{% /multicode %}

# Benefits {% #benefits %}

- **Repeatable provisioning.** Script the full set of OAuth2 providers a project needs and recreate it on demand, without clicking through the Console.
- **Environment parity.** Keep dev, staging, and production projects in sync by running the same configuration script against each one.
- **Server-only secrets.** Client secrets are write-only over the API and never returned in responses, so configuration scripts can run in CI without exposing them.

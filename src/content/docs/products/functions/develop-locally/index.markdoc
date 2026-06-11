---
layout: article
title: Develop locally
description: Learn to develop Appwrite functions locally.
---

Develop your Appwrite functions locally to make code changes without redeploying your function on every code change and hot reload your code for faster testing.

# Setup {% #setup %}

We use Docker to replicate the production environment for the local deployment of functions. These can be executed locally with the CLI command, which requires initializing a project with an `appwrite.config.json` file and having local code to run the function locally. The CLI also supports various other [CLI commands](/docs/tooling/command-line/commands).

1. Install the [Docker CLI](https://www.docker.com/products/docker-desktop/)
2. Ensure Docker is running in the background
3. Install the [Appwrite CLI](/docs/tooling/command-line/installation#getting-started)
4. [Log in](/docs/tooling/command-line/installation#login) to your Appwrite account using `appwrite login`
5. [Initialize your project](/docs/tooling/command-line/installation#initialization)
6. [Initialize an Appwrite function](/docs/tooling/command-line/functions) and copy and paste your code

# Develop {% #develop %}

Use the `appwrite run functions` command to develop your function locally.

| Parameter           | Description                                                                                                                                  |
|---------------------|----------------------------------------------------------------------------------------------------------------------------------------------|
|`--port`             | Set your function port; it defaults to `3000`, or the closest available, i.e. `3001`, `3002`, etc.                                           |
|`--function-id`      | Select a function so you don't have to click through the list each time.                                                                     |
|`--user-id <user-id>`| Impersonates a user. Automatically sets `x-appwrite-user-id` and `x-appwrite-user-jwt` headers if the user exists.                           |
|`--with-variables`   | Set production environment variables for your function. Do this only if your functions don't have production secrets to avoid security risks.|
|`--no-reload`        | Set your functions to not hot reload. Any changes to your code won't cause your function to restart.                                         |

```sh
appwrite run functions --port 3000 --function-id "<FUNCTION_ID>"

 runtime   | entrypoint  | path                           | commands     
-----------|-------------|--------------------------------|--------------
 node-16.0 | src/main.js | functions/<FUNCTION_ID>      | npm install  

ℹ Info: If you wish to change your local settings, update the appwrite.config.json file and rerun the 'appwrite run' command.
♥ Hint: Permissions, events, CRON and timeouts dont apply when running locally.
ℹ Info: Pulling Docker image ...
♥ Hint: This may take a few minutes, but we only need to do this once.
ℹ Info: Building function using Docker ...
Preparing for build ...

Building ...


added 4 packages, and audited 5 packages in 2s


1 package is looking for funding
  run `npm fund` for details


found 0 vulnerabilities

Packing build ...

Build finished.

ℹ Info: Starting function using Docker ...
♥ Hint: Function automatically restarts when you edit your code.
✓ Success: Visit http://localhost:3000/ to execute your function.
```

This command helps you efficiently develop your Appwrite functions on your local machine. When developing your Appwrite function locally, it will receive [headers](/docs/products/functions/develop#headers) like a function deployed to Appwrite.

{% arrow_link href="/docs/products/functions/develop" %}
Learn more about developing a function
{% /arrow_link %}

# Dynamic API keys {% #dynamic-api-keys %}

You can use headers like dynamic API keys in your function, which give you access to your project services and allow you to operate without sessions. To configure your dynamic API key scopes, modify the scopes in the `appwrite.config.json` file.
  
{% arrow_link href="/docs/products/functions/develop#dynamic-api-key" %}
Learn more about dynamic API keys
{% /arrow_link %}

# Hot reload {% #hot-reload %}

By default, the Appwrite CLI hot-reloads your functions, which means you can update the function code and the changes will be applied automatically. How this happens differs between runtimes with compiled languages versus interpreted ones.

Because runtimes with compiled languages must translate the source code into machine code, the function must rebuild on change.

When the source code in a runtime with an interpreted languages is updated, the function only needs to restart with the updated file. However, if a dependency file for the interpreted language is updated, the function must rebuild to update the dependencies. Refer to the table below for the dependency files of each language.

{% table %}
* &nbsp; {% width=80 %}
* Language
* Dependency File
---
* {% only_dark %}{% icon_image src="/images/platforms/dark/node.svg" alt="Node.js logo" size="m" /%}{% /only_dark %}
{% only_light %}{% icon_image src="/images/platforms/light/node.svg" alt="Node.js logo" size="m" /%}{% /only_light %}
* Node.js
* package.json, package-lock.json
---
* {% only_dark %}{% icon_image src="/images/platforms/dark/php.svg" alt="PHP logo" size="m" /%}{% /only_dark %}
{% only_light %}{% icon_image src="/images/platforms/light/php.svg" alt="PHP logo" size="m" /%}{% /only_light %}
* PHP
* composer.json, composer.lock
---
* {% only_dark %}{% icon_image src="/images/platforms/dark/python.svg" alt="Python logo" size="m" /%}{% /only_dark %}
{% only_light %}{% icon_image src="/images/platforms/light/python.svg" alt="Python logo" size="m" /%}{% /only_light %}
* Python
* requirements.txt, requirements.lock
---
* {% only_dark %}{% icon_image src="/images/platforms/dark/ruby.svg" alt="Ruby logo" size="m" /%}{% /only_dark %}
{% only_light %}{% icon_image src="/images/platforms/light/ruby.svg" alt="Ruby logo" size="m" /%}{% /only_light %}
* Ruby
* Gemfile, Gemfile.lock
---
* {% only_dark %}{% icon_image src="/images/platforms/dark/deno.svg" alt="Deno logo" size="m" /%}{% /only_dark %}
{% only_light %}{% icon_image src="/images/platforms/light/deno.svg" alt="Deno logo" size="m" /%}{% /only_light %}
* Deno
* Import URLs
---
* {% only_dark %}{% icon_image src="/images/platforms/dark/go.svg" alt="Go logo" size="m" /%}{% /only_dark %}
{% only_light %}{% icon_image src="/images/platforms/light/go.svg" alt="Go logo" size="m" /%}{% /only_light %}
* Go
* go.mod
---
* {% only_dark %}{% icon_image src="/images/platforms/dark/dart.svg" alt="Dart logo" size="m" /%}{% /only_dark %}
{% only_light %}{% icon_image src="/images/platforms/light/dart.svg" alt="Dart logo" size="m" /%}{% /only_light %}
* Dart
* Pubspec.yaml
---
* {% only_dark %}{% icon_image src="/images/platforms/dark/swift.svg" alt="Swift logo" size="m" /%}{% /only_dark %}
{% only_light %}{% icon_image src="/images/platforms/light/swift.svg" alt="Swift logo" size="m" /%}{% /only_light %}
* Swift
* Package.swift (Swift Package Manager Files)
---
* {% only_dark %}{% icon_image src="/images/platforms/dark/dotnet.svg" alt=".NET logo" size="m" /%}{% /only_dark %}
{% only_light %}{% icon_image src="/images/platforms/light/dotnet.svg" alt=".NET logo" size="m" /%}{% /only_light %}
* .NET
* .nupkg (NuGet Packages)
---
* {% only_dark %}{% icon_image src="/images/platforms/dark/bun.svg" alt="Bun logo" size="m" /%}{% /only_dark %}
{% only_light %}{% icon_image src="/images/platforms/light/bun.svg" alt="Bun logo" size="m" /%}{% /only_light %}
* Bun
* package.json, package-lock.json, bun.lockb
---
* {% only_dark %}{% icon_image src="/images/platforms/dark/kotlin.svg" alt="Kotlin logo" size="m" /%}{% /only_dark %}
{% only_light %}{% icon_image src="/images/platforms/light/kotlin.svg" alt="Kotlin logo" size="m" /%}{% /only_light %}
* Kotlin
* JAR Files (Java ARchive)
---
* {% only_dark %}{% icon_image src="/images/platforms/dark/java.svg" alt="Java logo" size="m" /%}{% /only_dark %}
{% only_light %}{% icon_image src="/images/platforms/light/java.svg" alt="Java logo" size="m" /%}{% /only_light %}
* Java
* JAR Files (Java ARchive)
---
* {% only_dark %}{% icon_image src="/images/platforms/dark/c.svg" alt="C++ logo" size="m" /%}{% /only_dark %}
{% only_light %}{% icon_image src="/images/platforms/light/c.svg" alt="C++ logo" size="m" /%}{% /only_light %}
* C++
* .h (Header Files)
---
* {% only_dark %}{% icon_image src="/images/platforms/dark/rust.svg" alt="Rust logo" size="m" /%}{% /only_dark %}
{% only_light %}{% icon_image src="/images/platforms/light/rust.svg" alt="Rust logo" size="m" /%}{% /only_light %}
* Rust
* Cargo.toml, Cargo.lock
{% /table %}

# Impersonate user {% #impersonate-user %}

You can also impersonate a user when you develop a function locally. Impersonate a user using the `--user-id <USER_ID>` option to select a user you want to use for testing. This allows you to test if the user can perform specific actions, such as creating a row.

When using the `--user-id <USER_ID>` endpoint, the CLI will check and return an error if the user does not exist. But if a user does exist, a [JWT token](/docs/products/auth/jwt#jwt) will be generated and last for 1 hour, similar to API tokens. If the user exists, the header `x-appwrite-user-id` will be set with the userId value, and the `x-appwrite-user-jwt` header will be set with the generated JWT token value.

```sh
appwrite run functions --user-id "<USER_ID>"
```

# Push function {% #push-function %}

Once you've developed your function, push it by running the following CLI command

```sh
appwrite push functions
```
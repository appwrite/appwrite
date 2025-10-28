> It's going to get cloudy! 🌩 ☂️
> The Appwrite Cloud is coming soon! You can learn more about our upcoming hosted solution and signup for free credits at: https://appwrite.io/cloud
<br />
<p align="center">
    <a href="https://appwrite.io" target="_blank"><img width="260" height="39" src="https://appwrite.io/images/appwrite.svg" alt="Appwrite Logo"></a>
    <br />
    <br />
    <b>A complete backend solution for your [Flutter / Vue / Angular / React / iOS / Android / *ANY OTHER*] apps</b>
    <br />
    <br />
</p>


<!-- [![Build Status](https://img.shields.io/travis/com/appwrite/appwrite?style=flat-square)](https://travis-ci.com/appwrite/appwrite) -->

[![Hacktoberfest](https://img.shields.io/static/v1?label=hacktoberfest&message=ready&color=191120&style=flat-square)](https://hacktoberfest.appwrite.io)
[![Discord](https://img.shields.io/discord/564160730845151244?label=discord&style=flat-square)](https://appwrite.io/discord?r=Github)
[![Build Status](https://img.shields.io/github/workflow/status/appwrite/appwrite/Tests?label=tests&style=flat-square)](https://github.com/appwrite/appwrite/actions)
[![Twitter Account](https://img.shields.io/twitter/follow/appwrite?color=00acee&label=twitter&style=flat-square)](https://twitter.com/appwrite)

<!-- [![Docker Pulls](https://img.shields.io/docker/pulls/appwrite/appwrite?color=f02e65&style=flat-square)](https://hub.docker.com/r/appwrite/appwrite) -->
<!-- [![Translate](https://img.shields.io/badge/translate-f02e65?style=flat-square)](docs/tutorials/add-translations.md) -->
<!-- [![Swag Store](https://img.shields.io/badge/swag%20store-f02e65?style=flat-square)](https://store.appwrite.io) -->

English | [简体中文](README-CN.md)

[**Appwrite 1.0 has been released! Learn what's new!**](https://appwrite.io/1.0)

Appwrite is an end-to-end backend server for Web, Mobile, Native, or Backend apps packaged as a set of Docker microservices. Appwrite abstracts the complexity and repetitiveness required to build a modern backend API from scratch and allows you to build secure apps faster.

Using Appwrite, you can seamlessly integrate your app with user authentication and multiple sign-in methods. Appwrite is also designed for database and storage persistence, cloud functions, localization, image manipulation, file management, Cloud services, and [more services](https://appwrite.io/docs).

<p align="center">
    <br />
    <a href="https://www.producthunt.com/posts/appwrite-2?utm_source=badge-top-post-badge&utm_medium=badge&utm_souce=badge-appwrite-2" target="_blank"><img src="https://api.producthunt.com/widgets/embed-image/v1/top-post-badge.svg?post_id=360315&theme=light&period=daily" alt="Appwrite - 100&#0037;&#0032;open&#0032;source&#0032;alternative&#0032;for&#0032;Firebase | Product Hunt" style="width: 250px; height: 54px;" width="250" height="54" /></a>
    <br />
    <br />
</p>

![Appwrite](public/images/github.png)

Find out more at: [https://appwrite.io](https://appwrite.io)

Table of Contents:

- [Installation](#installation)
  - [Unix](#unix)
  - [Windows](#windows)
    - [CMD](#cmd)
    - [PowerShell](#powershell)
  - [Upgrade from an Older Version](#upgrade-from-an-older-version)
- [Getting Started](#getting-started)
  - [Services](#services)
  - [SDKs](#sdks)
    - [Client](#client)
    - [Server](#server)
    - [Community](#community)
- [Architecture](#architecture)
- [Contributing](#contributing)
- [Security](#security)
- [Follow Us](#follow-us)
- [License](#license)

## Installation

Appwrite backend server is designed to run in a container environment. You can get your server up and running with one command from your terminal. You can run Appwrite on your localhost using docker-compose, or on any other container orchestration tool like Kubernetes, Docker Swarm, or Rancher.

The easiest way to start running your Appwrite server is by running our docker-compose file. Before running the installation command, make sure you have [Docker](https://www.docker.com/products/docker-desktop) installed on your machine:

### Unix

	@@ -58,7 +75,7 @@ docker run -it --rm \
    --volume /var/run/docker.sock:/var/run/docker.sock \
    --volume "$(pwd)"/appwrite:/usr/src/code/appwrite:rw \
    --entrypoint="install" \
    appwrite/appwrite:1.0.3
```

### Windows
	@@ -70,49 +87,77 @@ docker run -it --rm ^
    --volume //var/run/docker.sock:/var/run/docker.sock ^
    --volume "%cd%"/appwrite:/usr/src/code/appwrite:rw ^
    --entrypoint="install" ^
    appwrite/appwrite:1.0.3
```

#### PowerShell

```powershell
docker run -it --rm `
    --volume /var/run/docker.sock:/var/run/docker.sock `
    --volume ${pwd}/appwrite:/usr/src/code/appwrite:rw `
    --entrypoint="install" `
    appwrite/appwrite:1.0.3
```

Once the Docker installation completes, go to http://localhost to access the Appwrite console. Please note that on non-Linux native hosts, the server might take a few minutes to start after installation completes.

For advanced production and custom installation, check out our Docker [environment variables](https://appwrite.io/docs/environment-variables) docs. You can also use our public [docker-compose.yml](https://appwrite.io/install/compose) and [.env](https://appwrite.io/install/env) files to manually set up an environment.

### Upgrade from an Older Version

If you are upgrading your Appwrite server from an older version, you should use the Appwrite migration tool once your setup is completed. For more information regarding migration, check out the [Installation Docs](https://appwrite.io/docs/installation).

## One-Click Setups

In addition to running Appwrite locally, you can also launch Appwrite using a pre-configured setup. This allows you to start running Appwrite quickly without installing Docker on your local machine.

Choose from one of the providers below:

<table border="0">
  <tr>
    <td align="center" width="100" height="100">
      <a href="https://marketplace.digitalocean.com/apps/appwrite">
        <img width="50" height="39" src="public/images/integrations/digitalocean-logo.svg" alt="DigitalOcean Logo" />
          <br /><sub><b>DigitalOcean</b></sub></a>
        </a>
    </td>
    <td align="center" width="100" height="100">
      <a href="https://gitpod.io/#https://github.com/appwrite/integration-for-gitpod">
        <img width="50" height="39" src="public/images/integrations/gitpod-logo.svg" alt="Gitpod Logo" />
          <br /><sub><b>Gitpod</b></sub></a>    
      </a>
    </td>
  </tr>
</table>

## Getting Started

Get started with Appwrite in just three steps:
- create a new project
- choose your platform
- integrating its SDK into your code.

You can get started with your platform of choice by reading one of our "Getting Started" tutorials:

- [Getting Started for Web](https://appwrite.io/docs/getting-started-for-web)
- [Getting Started for Flutter](https://appwrite.io/docs/getting-started-for-flutter)
- [Getting Started for Apple](https://appwrite.io/docs/getting-started-for-apple)
- [Getting Started for Android](https://appwrite.io/docs/getting-started-for-android)
- [Getting Started for Server](https://appwrite.io/docs/getting-started-for-server)
- [Getting Started for CLI](https://appwrite.io/docs/command-line)

### Services

- [**Account**](https://appwrite.io/docs/client/account) - Manage current user authentication and account. Track and manage the user sessions, devices, sign-in methods, and security logs.
- [**Users**](https://appwrite.io/docs/server/users) - Manage and list all project users when building backend integrations with Server SDKs.
- [**Teams**](https://appwrite.io/docs/client/teams) - Manage and group users in teams. Manage memberships, invites, and user roles within a team.
- [**Databases**](https://appwrite.io/docs/client/databases) - Manage databases, collections and documents. Read, create, update, and delete documents and filter lists of document collections using advanced filters.
- [**Storage**](https://appwrite.io/docs/client/storage) - Manage storage files. Read, create, delete, and preview files. Manipulate the preview of your files to fit your app perfectly. All files are scanned by ClamAV and stored in a secure and encrypted way.
- [**Functions**](https://appwrite.io/docs/server/functions) - Customize your Appwrite server by executing your custom code in a secure, isolated environment. You can trigger your code on any Appwrite system event, manually or using a CRON schedule.
- [**Realtime**](https://appwrite.io/docs/realtime) - Listen to real-time events for any of your Appwrite services including users, storage, functions, databases and more.
- [**Locale**](https://appwrite.io/docs/client/locale) - Track your user's location, and manage your app locale-based data.
- [**Avatars**](https://appwrite.io/docs/client/avatars) - Manage your users' avatars, countries' flags, browser icons, credit card symbols, and generate QR codes.

For the complete API documentation, visit [https://appwrite.io/docs](https://appwrite.io/docs). For more tutorials, news and announcements check out our [blog](https://medium.com/appwrite-io) and [Discord Server](https://discord.gg/GSeTUeA).

	@@ -121,25 +166,39 @@ For the complete API documentation, visit [https://appwrite.io/docs](https://app
Below is a list of currently supported platforms and languages. If you wish to help us add support to your platform of choice, you can go over to our [SDK Generator](https://github.com/appwrite/sdk-generator) project and view our [contribution guide](https://github.com/appwrite/sdk-generator/blob/master/CONTRIBUTING.md).

#### Client

- ✅ &nbsp; [Web](https://github.com/appwrite/sdk-for-web) (Maintained by the Appwrite Team)
- ✅ &nbsp; [Flutter](https://github.com/appwrite/sdk-for-flutter) (Maintained by the Appwrite Team)
- ✅ &nbsp; [Apple](https://github.com/appwrite/sdk-for-apple) - **Beta** (Maintained by the Appwrite Team)
- ✅ &nbsp; [Android](https://github.com/appwrite/sdk-for-android) (Maintained by the Appwrite Team)

#### Server

- ✅ &nbsp; [NodeJS](https://github.com/appwrite/sdk-for-node) (Maintained by the Appwrite Team)
- ✅ &nbsp; [PHP](https://github.com/appwrite/sdk-for-php) (Maintained by the Appwrite Team)
- ✅ &nbsp; [Dart](https://github.com/appwrite/sdk-for-dart) - (Maintained by the Appwrite Team)
- ✅ &nbsp; [Deno](https://github.com/appwrite/sdk-for-deno) - **Beta** (Maintained by the Appwrite Team)
- ✅ &nbsp; [Ruby](https://github.com/appwrite/sdk-for-ruby) (Maintained by the Appwrite Team)
- ✅ &nbsp; [Python](https://github.com/appwrite/sdk-for-python) (Maintained by the Appwrite Team)
- ✅ &nbsp; [Kotlin](https://github.com/appwrite/sdk-for-kotlin) - **Beta** (Maintained by the Appwrite Team)
- ✅ &nbsp; [Apple](https://github.com/appwrite/sdk-for-apple) - **Beta** (Maintained by the Appwrite Team)
- ✅ &nbsp; [.NET](https://github.com/appwrite/sdk-for-dotnet) - **Experimental** (Maintained by the Appwrite Team)

#### Community

- ✅ &nbsp; [Appcelerator Titanium](https://github.com/m1ga/ti.appwrite) (Maintained by [Michael Gangolf](https://github.com/m1ga/))
- ✅ &nbsp; [Godot Engine](https://github.com/GodotNuts/appwrite-sdk) (Maintained by [fenix-hub @GodotNuts](https://github.com/fenix-hub))

Looking for more SDKs? - Help us by contributing a pull request to our [SDK Generator](https://github.com/appwrite/sdk-generator)!

## Architecture

![Appwrite Architecture](docs/specs/overview.drawio.svg)

Appwrite uses a microservices architecture that was designed for easy scaling and delegation of responsibilities. In addition, Appwrite supports multiple APIs (REST, WebSocket, with GraphQL coming soon) to allow you to interact with your resources by leveraging existing knowledge and protocols of choice.

The Appwrite API layer was designed to be extremely fast. It does this by leveraging in-memory caching and delegating any heavy-lifting tasks to the Appwrite background workers. The background workers also allow you to precisely control your compute capacity and costs using a message queue to handle the load. You can learn more about our architecture in the [contribution guide](CONTRIBUTING.md#architecture-1).

## Contributing

All code contributions - including those of people having commit access - must go through a pull request and be approved by a core developer before being merged. This is to ensure a proper review of all the code.
	@@ -152,7 +211,7 @@ For security issues, kindly email us at [security@appwrite.io](mailto:security@a

## Follow Us

Join our growing community around the world! See our official [Blog](https://medium.com/appwrite-io). Follow us on [Twitter](https://twitter.com/appwrite), [Facebook Page](https://www.facebook.com/appwrite.io), [Facebook Group](https://www.facebook.com/groups/appwrite.developers/), [Dev Community](https://dev.to/appwrite) or join our live [Discord server](https://discord.gg/GSeTUeA) for more help, ideas, and discussions.

## License

This repository is available under the [BSD 3-Clause License](./LICENSE).

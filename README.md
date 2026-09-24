<img width="1920" height="1080" alt="image" src="https://github.com/user-attachments/assets/55a81268-4ecc-46cd-bdf5-73f7e8662fee" />

<br />
<p align="center">
    <h1>Appwrite</h1>
    <b>The open-source cloud for agents and developers. Appwrite is an MCP and agent-first platform for building and scaling apps. Give your agents Auth, Databases, Storage, Functions, Messaging, Realtime, and Hosting, all in one place.</b>
    <br />
    <br />
</p>

[![Discord](https://img.shields.io/badge/chat-5865F2?style=flat-square&logo=discord&logoColor=white)](https://appwrite.io/discord)
[![X](https://img.shields.io/badge/follow-000000?style=flat-square&logo=x&logoColor=white)](https://x.com/appwrite)
[![Appwrite Cloud](https://img.shields.io/badge/Cloud-F02E65?style=flat-square&logo=icloud&logoColor=white)](https://cloud.appwrite.io)

English | [简体中文](README-CN.md)

Appwrite is an MCP and agent-first, open-source platform for building and scaling apps. MCP is included, so coding agents can operate on a live Appwrite project. Appwrite is available as a managed cloud platform and can also be self-hosted on infrastructure you control.

It is designed for the AI agents in your workflow as well as for developers. Connect Cursor, Claude Code, Codex, and other agents through the hosted MCP server, or integrate an SDK into your app. Modular products stay unified from the first prototype to production scale.

Find out more at [https://appwrite.io](https://appwrite.io).

Table of Contents:

- [Products](#products)
- [Installation \& Setup](#installation--setup)
- [Self-Hosting](#self-hosting)
  - [Unix](#unix)
  - [Windows](#windows)
    - [CMD](#cmd)
    - [PowerShell](#powershell)
  - [Docker API version mismatch](#docker-api-version-mismatch)
  - [Upgrade from an Older Version](#upgrade-from-an-older-version)
- [One-Click Setups](#one-click-setups)
- [Getting Started](#getting-started)
  - [Agents](#agents)
  - [SDKs](#sdks)
    - [Client](#client)
    - [Server](#server)
- [Architecture](#architecture)
- [Contributing](#contributing)
- [Security](#security)
- [Follow Us](#follow-us)
- [License](#license)


## Products

- **[Appwrite Auth](https://appwrite.io/docs/products/auth)** - Authenticate users securely with email, SMS, OAuth, anonymous sessions, and magic URLs.

- **[Appwrite Databases](https://appwrite.io/docs/products/databases)** - Model, query, and scale with Appwrite databases or managed PostgreSQL and MySQL, so you can match your use case and team needs.

- **[Appwrite Storage](https://appwrite.io/docs/products/storage)** - Store files with compression, encryption, image transformations, and access control.

- **[Appwrite Functions](https://appwrite.io/docs/products/functions)** - Deploy serverless functions with secure isolated runtimes and event-driven execution.

- **[Appwrite Sites](https://appwrite.io/docs/products/sites)** - Deploy static, SSR, and CSR frontends from Git with instant previews and Appwrite behind them.

- **[Appwrite Messaging](https://appwrite.io/docs/products/messaging)** - Send email, SMS, and push notifications through a unified messaging service.

- **[Appwrite Firewall](https://appwrite.io/docs/products/network)** - Protect apps with traffic rules, abuse controls, and edge security for every project.

- **[Appwrite Realtime](https://appwrite.io/docs/apis/realtime)** - Subscribe and react to events across your project as they happen.

- **[Appwrite MCP](https://appwrite.io/docs/tooling/ai/mcp-servers/api)** - Connect agents to your Appwrite project, APIs, and docs. MCP is included on Cloud, with no local install required.


## Installation & Setup

The easiest way to get started with Appwrite is by [signing up for Appwrite Cloud](https://cloud.appwrite.io/). Cloud includes a [Free plan](https://appwrite.io/pricing) so you can start building immediately, with paid plans when you need to scale.

## Self-Hosting

Appwrite was designed from the ground up with self-hosting in mind. You can install and run Appwrite on any operating system that can run a [Docker CLI](https://www.docker.com/products/docker-desktop). Running your server is as easy as running one command from your terminal.

Before running the installation command, make sure you have [Docker](https://www.docker.com/products/docker-desktop) installed on your machine. The setup wizard listens on port **20080**; if you are installing on a remote host, open that port until installation is complete.

### Unix

```bash
docker run -it --rm \
    --publish 20080:20080 \
    --volume /var/run/docker.sock:/var/run/docker.sock \
    --volume "$(pwd)"/appwrite:/usr/src/code/appwrite:rw \
    --entrypoint="install" \
    appwrite/appwrite:2.3.0
```

### Windows

#### CMD

```cmd
docker run -it --rm ^
    --publish 20080:20080 ^
    --volume //var/run/docker.sock:/var/run/docker.sock ^
    --volume "%cd%"/appwrite:/usr/src/code/appwrite:rw ^
    --entrypoint="install" ^
    appwrite/appwrite:2.3.0
```

#### PowerShell

```powershell
docker run -it --rm `
    --publish 20080:20080 `
    --volume /var/run/docker.sock:/var/run/docker.sock `
    --volume ${pwd}/appwrite:/usr/src/code/appwrite:rw `
    --entrypoint="install" `
    appwrite/appwrite:2.3.0
```

Once the installer is running, open http://localhost:20080 to complete the setup wizard. After installation, go to http://localhost to access the Appwrite console from your browser. Please note that on non-Linux native hosts, the server might take a few minutes to start after completing the installation.

### Docker API version mismatch

If install or upgrade fails with an error like `client version 1.52 is too new. Maximum supported API version is 1.42`, the Docker CLI inside the Appwrite image is newer than your host Docker Engine. Pass `DOCKER_API_VERSION` set to the maximum API version from the error (or upgrade Docker on the host):

```bash
docker run -it --rm \
    --env DOCKER_API_VERSION=1.42 \
    --publish 20080:20080 \
    --volume /var/run/docker.sock:/var/run/docker.sock \
    --volume "$(pwd)"/appwrite:/usr/src/code/appwrite:rw \
    --entrypoint="install" \
    appwrite/appwrite:2.3.0
```

Use the same `--env DOCKER_API_VERSION=...` flag with `--entrypoint="upgrade"` when upgrading.

For advanced production and custom installation, check out our Docker [environment variables](https://appwrite.io/docs/advanced/self-hosting/configuration/environment-variables) docs. You can also use our public [docker-compose.yml](https://appwrite.io/install/compose) and [.env](https://appwrite.io/install/env) files to manually set up an environment.

### Upgrade from an Older Version

If you are upgrading your Appwrite server from an older version, you should use the Appwrite migration tool once your setup is completed. For more information regarding this, check out the [migration instructions](https://appwrite.io/docs/advanced/self-hosting/production/updates).

## One-Click Setups

In addition to running Appwrite locally, you can launch Appwrite using a pre-configured marketplace app for instant setup.

Choose from one of the providers below:

<table border="0">
  <tr>
    <td align="center" width="100" height="100">
      <a href="https://marketplace.digitalocean.com/apps/appwrite">
        <img width="50" height="39" src="public/images/integrations/digitalocean-logo.svg" alt="DigitalOcean Logo" />
        <br /><sub><b>DigitalOcean</b></sub>
      </a>
    </td>
    <td align="center" width="100" height="100">
      <a href="https://gitpod.io/#https://github.com/appwrite/integration-for-gitpod">
        <img width="50" height="39" src="public/images/integrations/gitpod-logo.svg" alt="Gitpod Logo" />
        <br /><sub><b>Gitpod</b></sub>
      </a>
    </td>
    <td align="center" width="100" height="100">
      <a href="https://www.linode.com/marketplace/apps/appwrite/appwrite/">
        <img width="50" height="39" src="public/images/integrations/akamai-logo.svg" alt="Akamai Logo" />
        <br /><sub><b>Akamai Compute</b></sub>
      </a>
    </td>
    <td align="center" width="100" height="100">
      <a href="https://aws.amazon.com/marketplace/pp/prodview-2hiaeo2px4md6">
        <img width="50" height="39" src="public/images/integrations/aws-logo.svg" alt="AWS Logo" />
        <br /><sub><b>AWS Marketplace</b></sub>
      </a>
    </td>
    <td align="center" width="100" height="100">
      <a href="https://repocloud.io/details/Appwrite/">
        <img width="50" height="39" src="https://d16t0pc4846x52.cloudfront.net/deploylobe.svg" alt="RepoCloud Logo" />
          <br /><sub><b>RepoCloud</b></sub></a>
      </a>
    </td>
  </tr>
</table>

For custom deployments on a cloud provider or PaaS, see the guides for [AWS](https://appwrite.io/docs/advanced/self-hosting/platforms/aws), [DigitalOcean](https://appwrite.io/docs/advanced/self-hosting/platforms/digitalocean), [Google Cloud](https://appwrite.io/docs/advanced/self-hosting/platforms/google-cloud), [Azure](https://appwrite.io/docs/advanced/self-hosting/platforms/azure), [Coolify](https://appwrite.io/docs/advanced/self-hosting/platforms/coolify), and [Dokploy](https://appwrite.io/docs/advanced/self-hosting/platforms/dokploy).

## Getting Started

Getting started with Appwrite is as easy as creating a new project, connecting an agent, or integrating an SDK into your code. You can start from a prompt, a quick start tutorial, or the hosted MCP server.

### Agents

Designed for the AI agents in your workflow. Query a database, chart traffic, or ship a change: your agent does it on a live Appwrite project. The hosted MCP server uses OAuth, so you do not need to create or manage API keys. For a self-hosted instance, use the [local MCP server](https://appwrite.io/docs/advanced/self-hosting/mcp) instead.

Add the remote server to Cursor, Claude Code, Codex, VS Code, and other MCP clients:

```json
{
    "mcpServers": {
        "appwrite": {
            "url": "https://mcp.appwrite.io/"
        }
    }
}
```

You can also [add the MCP server to Cursor](https://cursor.com/install-mcp?name=appwrite&config=eyJ1cmwiOiJodHRwczovL21jcC5hcHB3cml0ZS5pby8ifQ==) in one click.

Install language-specific [agent skills](https://appwrite.io/docs/tooling/ai/skills) so models generate correct SDK calls:

```bash
npx skills add appwrite/agent-skills
```

Browse [quick start prompts](https://appwrite.io/docs/tooling/ai/quickstart-prompts), generate an [AGENTS.md](https://appwrite.io/docs/tooling/ai/agents-md) file for your repo, or see the [AI tooling docs](https://appwrite.io/docs/tooling/ai) for IDE and vibe coding setup.

Getting started with your platform of choice is also covered in the tutorials below.

| Platform              | Technology                                                                         |
| --------------------- | ---------------------------------------------------------------------------------- |
| **Web app**           | [Quick start for Web](https://appwrite.io/docs/quick-starts/web)                   |
|                       | [Quick start for Next.js](https://appwrite.io/docs/quick-starts/nextjs)            |
|                       | [Quick start for React](https://appwrite.io/docs/quick-starts/react)               |
|                       | [Quick start for Vue.js](https://appwrite.io/docs/quick-starts/vue)                |
|                       | [Quick start for Nuxt](https://appwrite.io/docs/quick-starts/nuxt)                 |
|                       | [Quick start for SvelteKit](https://appwrite.io/docs/quick-starts/sveltekit)       |
|                       | [Quick start for Astro](https://appwrite.io/docs/quick-starts/astro)               |
|                       | [Quick start for TanStack Start](https://appwrite.io/docs/quick-starts/tanstack-start) |
|                       | [Quick start for Refine](https://appwrite.io/docs/quick-starts/refine)             |
|                       | [Quick start for Angular](https://appwrite.io/docs/quick-starts/angular)           |
| **Mobile and Native** | [Quick start for React Native](https://appwrite.io/docs/quick-starts/react-native) |
|                       | [Quick start for Flutter](https://appwrite.io/docs/quick-starts/flutter)           |
|                       | [Quick start for Apple](https://appwrite.io/docs/quick-starts/apple)               |
|                       | [Quick start for Android](https://appwrite.io/docs/quick-starts/android)           |
| **Server**            | [Quick start for Node.js](https://appwrite.io/docs/quick-starts/node)              |
|                       | [Quick start for Python](https://appwrite.io/docs/quick-starts/python)             |
|                       | [Quick start for .NET](https://appwrite.io/docs/quick-starts/dotnet)               |
|                       | [Quick start for Dart](https://appwrite.io/docs/quick-starts/dart)                 |
|                       | [Quick start for Ruby](https://appwrite.io/docs/quick-starts/ruby)                 |
|                       | [Quick start for Deno](https://appwrite.io/docs/quick-starts/deno)                 |
|                       | [Quick start for PHP](https://appwrite.io/docs/quick-starts/php)                   |
|                       | [Quick start for Kotlin](https://appwrite.io/docs/quick-starts/kotlin)             |
|                       | [Quick start for Swift](https://appwrite.io/docs/quick-starts/swift)               |
|                       | [Quick start for Go](https://appwrite.io/docs/quick-starts/go)                     |
|                       | [Quick start for Rust](https://appwrite.io/docs/quick-starts/rust)                 |

### SDKs

Below is a list of currently supported platforms and languages. If you would like to help us add support to your platform of choice, you can go over to our [SDK Generator](https://github.com/appwrite/sdk-generator) project and view our [contribution guide](https://github.com/appwrite/sdk-generator/blob/master/CONTRIBUTING.md).

#### Client

- :white_check_mark: &nbsp; [Web](https://github.com/appwrite/sdk-for-web)
- :white_check_mark: &nbsp; [Flutter](https://github.com/appwrite/sdk-for-flutter)
- :white_check_mark: &nbsp; [Apple](https://github.com/appwrite/sdk-for-apple)
- :white_check_mark: &nbsp; [Android](https://github.com/appwrite/sdk-for-android)
- :white_check_mark: &nbsp; [React Native](https://github.com/appwrite/sdk-for-react-native)

#### Server

- :white_check_mark: &nbsp; [Node.js](https://github.com/appwrite/sdk-for-node)
- :white_check_mark: &nbsp; [Python](https://github.com/appwrite/sdk-for-python)
- :white_check_mark: &nbsp; [Dart](https://github.com/appwrite/sdk-for-dart)
- :white_check_mark: &nbsp; [PHP](https://github.com/appwrite/sdk-for-php)
- :white_check_mark: &nbsp; [Ruby](https://github.com/appwrite/sdk-for-ruby)
- :white_check_mark: &nbsp; [.NET](https://github.com/appwrite/sdk-for-dotnet)
- :white_check_mark: &nbsp; [Go](https://github.com/appwrite/sdk-for-go)
- :white_check_mark: &nbsp; [Swift](https://github.com/appwrite/sdk-for-swift)
- :white_check_mark: &nbsp; [Kotlin](https://github.com/appwrite/sdk-for-kotlin)
- :white_check_mark: &nbsp; [Rust](https://github.com/appwrite/sdk-for-rust)

Looking for more SDKs? - Help us by contributing a pull request to our [SDK Generator](https://github.com/appwrite/sdk-generator)!

## Architecture

```mermaid
flowchart TB
  Console & Flutter & iOS & Android & Web & Agents & MCP & CLI & SDKs & Terraform --> Appwrite
  Appwrite --> REST & Realtime & GraphQL & S3
  REST & Realtime & GraphQL & S3 --> securityLayer[Security layer]
  securityLayer --> services
  subgraph services [Services]
    Auth
    Databases
    Functions
    Sites
    Messaging
    Storage
    Avatars
    Locale
  end
  services --> Executor & Queue & Cache & Browser & SMTP & Embeddings
  Cache --> Database
  Queue --> Workers
  Executor --> openRuntimes[Open Runtimes]
```

Appwrite uses a hybrid monolithic-microservice architecture that was designed for easy scaling and delegation of responsibilities. In addition, Appwrite supports multiple APIs, such as REST, WebSocket, and GraphQL to allow you to interact with your resources by leveraging your existing knowledge and protocols of choice.

The Appwrite API layer was designed to be extremely fast by leveraging in-memory caching and delegating any heavy-lifting tasks to the Appwrite background workers. The background workers also allow you to precisely control your compute capacity and costs using a message queue to handle the load. You can learn more about our architecture in [AGENTS.md](AGENTS.md).

## Contributing

All code contributions, including those of people having commit access, must go through a pull request and be approved by a core developer before being merged. This is to ensure a proper review of all the code.

We truly :heart: pull requests! If you wish to help, you can learn more about how you can contribute to this project in the [contribution guide](CONTRIBUTING.md).

## Security

Please see [SECURITY.md](SECURITY.md) for how to report a vulnerability. Do not open a public GitHub issue for security reports.

## Follow Us

Join our growing community around the world! Read the [Blog](https://appwrite.io/blog), or follow us on [Discord](https://appwrite.io/discord), [GitHub](https://github.com/appwrite), [X](https://x.com/appwrite), [LinkedIn](https://linkedin.com/company/appwrite), [YouTube](https://youtube.com/c/appwrite), [daily.dev](https://app.daily.dev/squads/appwrite), [Bluesky](https://bsky.app/profile/appwrite.io), [TikTok](https://tiktok.com/@appwrite), and [Instagram](https://instagram.com/appwrite.io).

## License

This repository is available under the [BSD 3-Clause License](./LICENSE).

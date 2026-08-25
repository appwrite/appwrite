---
layout: post
title: "July product update: Remote MCP server, Projects API and more"
description: "See what's new in Appwrite with the hosted remote MCP server Projects API OAuth Device Authorization and Appwrite 1.9.6."
date: 2026-08-05
cover: /images/blog/july-product-update-remote-mcp-server-projects-api-and-more/cover.avif
timeToRead: 5
author: aishwari
category: product
featured: false
faqs:
  - question: Do I need to install anything to use the Appwrite MCP server now?
    answer: No. The remote server is hosted by Appwrite. You add a single URL to your AI tool and authenticate with OAuth. No local installation, no Python, no uv, and no API keys.
  - question: Can I still use the local Appwrite MCP server?
    answer: Yes, but the remote server is the simpler path for most setups and keeps the same context-efficient architecture.
  - question: What can I configure with the Projects API?
    answer: Authentication providers, API keys, platforms, environment variables, email templates, session policies, labels, and more. That covers nearly every project setting that was previously Console-only.
  - question: Does the new CLI login flow work for self-hosted Appwrite?
    answer: Self-hosted users can continue using the existing email and password flow unchanged. The OAuth Device Authorization flow is the new default for Appwrite Cloud.
---
July was all about making Appwrite easier to automate, easier to connect to AI tools, and easier to manage at scale. This month's Appwrite product update covers a hosted remote MCP server, the new Projects API, a simpler CLI login flow built on OAuth Device Authorization, and Appwrite 1.9.6 for self-hosted deployments.

There's a common thread running through all four: removing the manual steps between you and a working project. Setup you used to do by hand, whether that was installing a local server, clicking through Console settings, or pasting credentials into a terminal, is now either automated or gone.

# What shipped in Appwrite's July product update?

In July 2026, Appwrite shipped four releases:

* **Hosted remote Appwrite MCP Server with OAuth.** Connect any MCP-compatible AI tool with a single URL, no local install required.
* **Projects API.** Configure and manage nearly every project setting programmatically through the SDKs and [CLI](/docs/tooling/command-line-interface).
* **OAuth Device Authorization for the Appwrite CLI.** Log in through your browser instead of typing credentials into the terminal.
* **Appwrite 1.9.6.** Reliability and migration fixes for [self-hosted deployments](/docs/advanced/self-hosting).

Let's dive in.

# The Appwrite MCP server is now remote

![The Appwrite MCP server is now remote](/images/blog/july-product-update-remote-mcp-server-projects-api-and-more/The_Appwrite_MCP_server_is_now_remote.avif)

The [Appwrite MCP server](/docs/tooling/ai/mcp-servers) is now available as a hosted remote server.

## What is the remote Appwrite MCP server?

The remote Appwrite MCP server is a hosted [Model Context Protocol](https://modelcontextprotocol.io/) endpoint that gives AI coding tools structured access to your Appwrite project and documentation. Because it runs on Appwrite's infrastructure, you add one URL to your AI tool, authenticate with OAuth, and start building.

That means:

* **No local installation.** No cloning, no running a server alongside your editor.
* **No Python or `uv`.** The previous local setup depended on a Python toolchain. That requirement is gone.
* **No API keys to generate, paste, or rotate.** OAuth handles authorization, so credentials never sit in a config file.

## Which AI tools work with it?

The remote server works with any client that supports remote MCP servers, including [Claude Code](https://docs.claude.com/en/docs/claude-code/overview), Cursor, Codex, VS Code, Windsurf, and Zed.

Under the hood, the server keeps the same context-efficient architecture as the local version. That matters more than it sounds: MCP servers that expose hundreds of loosely organized tools eat into a model's context window before it writes a single line of code. Appwrite's server is designed to keep tool definitions lean so the model spends its context on your problem instead of on the catalog.

## What can you build with it?

Once connected, your AI tool can read Appwrite documentation directly instead of guessing at SDK signatures from memory, and it can work against your actual project. In practice that means fewer hallucinated method names, fewer round trips to the docs tab, and less time spent correcting code that was written against an older version of the API.

If you were already using the local MCP server, the remote server is the recommended path forward. If you skipped MCP entirely because the setup felt like a side quest, this is the release to revisit.

[Read the announcement](/blog/post/announcing-remote-appwrite-mcp-server)

# Introducing the Projects API

![Introducing the Projects API](/images/blog/july-product-update-remote-mcp-server-projects-api-and-more/Introducing_the_projects_api.avif)

The new Projects API lets you configure and manage nearly every Appwrite project setting through the SDKs and CLI.

## What can you automate with the Projects API?

Settings that previously lived only in the Console are now programmable:

* **Authentication providers.** Enable and configure OAuth providers across environments
* **API keys.** Create, scope, and rotate keys as part of a deployment pipeline
* **Platforms.** Register web, Flutter, Apple, and Android platforms
* **Environment variables.** Set project-level variables without manual entry
* **Email templates.** Version your verification, recovery, and invitation templates
* **Session policies.** Control session length and limits per project
* **Labels.** Organize and tag projects at scale

## Why does Projects API matter?

Because it turns project configuration into code.

Until now, standing up a new staging environment that matched production meant clicking through the Console and hoping you did not miss a toggle. Configuration drift between development, staging, and production is one of the quieter sources of "it works on my machine" bugs, and it is exactly the problem [infrastructure as code](https://learn.microsoft.com/en-us/devops/deliver/what-is-infrastructure-as-code) was invented to solve.

With the Projects API you can:

1. **Script environment setup.** Define a project once and reproduce it on demand.
2. **Version your configuration.** Keep project settings in Git alongside your application code, with review and history.
3. **Reproduce configurations across environments.** Promote the same settings from development to staging to production instead of recreating them.
4. **Onboard teams faster.** New engineers run one command instead of following a Console walkthrough.

It also pairs naturally with the remote MCP server. Configuration that is expressible through an API is configuration an AI agent can reason about and apply for you.

[Read the announcement](/blog/post/announcing-projects-api)

# OAuth Device Authorization for the Appwrite CLI

![OAuth Device Authorization for the Appwrite CLI](/images/blog/july-product-update-remote-mcp-server-projects-api-and-more/OAuth_Device_Authorization_for_the_Appwrite_CLI.avif)

Logging into the [Appwrite CLI](/docs/tooling/command-line-interface) is now much simpler.

## How does the new CLI login flow work?

Instead of entering credentials in the terminal, the CLI now uses the [OAuth 2.0 Device Authorization Grant](https://datatracker.ietf.org/doc/html/rfc8628):

1. Run the login command in your terminal.
2. The CLI opens your browser.
3. Review the permissions being requested and approve access.
4. The CLI signs in automatically.

You authenticate in your browser, so you never have to type your password into the terminal.

## Why device authorization instead of a password prompt?

Device Authorization Grant is the standard OAuth flow for input-constrained clients, which is why you have seen it on smart TVs and streaming devices. Applied to a CLI, it brings a few practical benefits: your identity provider handles authentication, so MFA and SSO come along for the ride; the CLI receives a scoped token rather than your account password; and you get an explicit consent screen showing exactly what access you are granting.

**Self-hosted users:** nothing breaks. The existing email and password flow continues to work unchanged.

[Read the announcement](/blog/post/announcing-cli-device-authorization)

# Appwrite 1.9.6 is now available

![Appwrite 1.9.6 is now available](/images/blog/july-product-update-remote-mcp-server-projects-api-and-more/Appwrite_1.9.6_is_now_available.avif)

Appwrite 1.9.6 focuses on improving reliability for self-hosted deployments following the 1.9.5 release.

## What's fixed in Appwrite 1.9.6?

* **Automatic repair of missing Git provider attributes during migrations.** Deployments that hit incomplete Git provider data now self-correct instead of requiring manual intervention.
* **Migrations are safe to rerun after partial failures.** If a migration stops halfway, you can run it again without worrying about compounding the problem. That is a meaningful change if you have ever watched a partial migration and wondered which half completed.
* **Improved health checks for Appwrite containers and MongoDB.** Better signals mean orchestration layers can detect and recover from unhealthy services faster.

If you are self-hosting and still on 1.9.5, this is a recommended upgrade. Review the [self-hosting docs](https://appwrite.io/docs/advanced/self-hosting) before you start, and as always, back up before upgrading.

[Read the announcement](/blog/post/appwrite-1-9-6-self-hosted-release)

# Engineering resources

* [Claude is now free for K-12 educators in the US](/blog/post/anthropic-launches-free-claude-access-for-us-k-12-teachers)
* [Grok 4.5 Coding is here. Here's what developers need to know.](/blog/post/grok-45-coding-model)
* [Claude Sonnet 5 is Anthropic's most agentic Sonnet yet](/blog/post/claude-sonnet-5-is-anthropics-most-agentic-sonnet-yet)
* [What's new in the MCP 2026-07-28 specification](/blog/post/mcp-goes-stateless-in-the-2026-07-28-specification)
* [Claude Opus 5 nears Fable 5 intelligence at half the price](/blog/post/claude-opus-5-nears-fable-5-intelligence-at-half-the-price)

# What's to come

July laid the groundwork for what's next, but we're only getting started.

We've been building some of our biggest updates yet, and while we're not quite ready to share them, we think you'll want to keep an eye on what's coming. Stay tuned for major announcements around the Appwrite platform in the weeks ahead.

In the meantime, [follow us on X](https://x.com/appwrite), check the [Changelog](/changelog) regularly, and browse the [docs](/docs) to stay up to date with the latest releases.
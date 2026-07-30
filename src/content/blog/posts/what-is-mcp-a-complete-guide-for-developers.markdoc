---
layout: post
title: What is MCP? A complete guide for developers
description: Learn what MCP is, how Model Context Protocol works, its architecture, tools, resources, prompts, APIs, and how developers can start building with it.
date: 2026-07-16
cover: /images/blog/what-is-mcp-a-complete-guide-for-developers/cover.avif
timeToRead: 5
author: aditya-oberai
category: ai
featured: false
unlisted: true
faqs:
  - question: What is the difference between an MCP client and an MCP server?
    answer: An MCP client connects an AI application to one MCP server and manages communication with it. The server exposes the tools, resources, and prompts that the AI application can use.
  - question: What is the difference between MCP and an API?
    answer: An API defines how software interacts with a particular service. MCP provides a standard way for AI applications to discover and use many tools, often by placing an MCP server in front of existing APIs. MCP complements APIs rather than replacing them.
  - question: Do I need an MCP server to use MCP?
    answer: You need access to an MCP server, but you do not always need to build one yourself. You can connect an existing server to a compatible AI client or create your own when exposing a custom product, API, or internal data source.
  - question: Is MCP secure?
    answer: MCP can support authorization for remote HTTP servers, but connecting a server still gives an AI application access to potentially sensitive tools and data. Developers should use narrowly scoped credentials, validate inputs, require approval for sensitive actions, and only connect trusted servers.
---

AI models are good at reasoning and terrible at acting. Ask one to summarize your latest report, check today's database entries, or open a pull request, and it stalls. It has no way to reach your files, your database, or your APIs. It can only work with what was in its training data.

For a while, the fix was custom integrations: write glue code for every tool, handle each authentication scheme, and rewrite it all when an API changes. That does not scale. **Model Context Protocol (MCP)** is the standard that replaces those one-off integrations with a single, shared interface.

This guide covers what MCP is, how its architecture and primitives work, the transports it runs over, and how you can start building with it today.

# What is MCP (Model Context Protocol)?

MCP is an open protocol that defines how AI applications connect to external tools, data sources, and APIs through a uniform interface. Instead of teaching a model a new integration for every service, you expose your capabilities through an **MCP server**, and any MCP-compatible client can use them.

MCP was developed by Anthropic and released as an open standard in November 2024. In December 2025, Anthropic donated MCP to the [Agentic AI Foundation](https://aaif.io/), a directed fund under the Linux Foundation, to support vendor-neutral governance. The specification and project development continue openly at [modelcontextprotocol.io](https://modelcontextprotocol.io).

The short version: MCP turns a model that can only think into one that can fetch real data and trigger real actions, without you writing bespoke plumbing for each connection.

# Why MCP exists: The integration problem

The problem MCP solves is combinatorial. If you have **M** AI applications and **N** tools, connecting them directly means building and maintaining M × N integrations. Every new tool multiplies the work, and every API change breaks something downstream.

MCP collapses that to **M + N**. Each tool ships one MCP server, each AI application speaks MCP once, and the two sides interoperate without knowing anything specific about each other. You build the integration once and it works everywhere.

This is the same shift the [Language Server Protocol](https://microsoft.github.io/language-server-protocol/) brought to code editors. Before LSP, every editor needed custom support for every language. After it, one language server worked in any compatible editor. MCP applies that pattern to AI applications and the tools they need to reach.

# How MCP works: Architecture

MCP uses a client-server architecture with three roles. Understanding the split is the key to reasoning about how everything connects.

* **Host**: The AI application the user interacts with, such as Claude Desktop, Cursor, or VS Code. The host manages one or more clients.
* **Client**: A connector that lives inside the host and maintains a dedicated one-to-one connection to a single server.
* **Server**: A program that exposes capabilities (tools, data, and prompts) over MCP. A GitHub server exposes repo operations, a database server exposes queries, and so on.

A single host can run many clients at once, each connected to a different server. That is how one assistant can pull errors from Sentry, read commits from GitHub, and write to your backend in the same conversation, with each server handling only its own domain.

Under the hood, MCP is built on [JSON-RPC 2.0](https://www.jsonrpc.org/specification). Clients and servers exchange structured request and response messages, and the connection begins with an initialization handshake where both sides declare which features they support.

# MCP primitives: Tools, resources, and prompts

MCP servers expose their capabilities through three primitives. Knowing which one to reach for is most of what you need to design a good server.

* **Tools** are executable functions the model can call to perform an action: run a query, send a message, create a record. Tools are model-controlled, meaning the AI decides when to invoke them based on the task.
* **Resources** are read-only data the server makes available for context: files, database rows, documentation, or API responses. Resources are application-controlled and give the model something to reason over.
* **Prompts** are reusable, templated instructions that a server offers to standardize common workflows. Prompts are typically user-controlled, surfaced as slash commands or menu options in the client.

The distinction matters in practice. A weather server might expose a `get_forecast` **tool** for live lookups, a **resource** listing saved locations, and a **prompt** that formats a daily briefing. Each primitive has a different trigger and a different level of control.

Clients contribute their own primitives back to servers, including **sampling** (letting a server request a model completion), **roots** (scoping which files or directories a server may access), and **elicitation** (asking the user for input mid-task). Together these keep the human and the model in the loop rather than handing servers unchecked access.

# MCP transports and APIs

MCP defines two standard transports for how clients and servers actually talk to each other.

* **stdio**: The server runs as a local subprocess and communicates over standard input and output. This is the simplest and most common setup for local tools, since credentials never leave your machine.
* **Streamable HTTP**: The server runs as a remote service reachable over HTTP, with support for streaming responses. This is the transport for hosted servers and replaced the earlier HTTP with Server-Sent Events approach.

Because MCP standardizes the message format above the transport, the same server logic works whether it runs locally over stdio or remotely over HTTP. You write your tool and resource handlers once, then choose how to deploy.

# MCP vs REST APIs: What is the difference?

A common question is whether MCP is just another API. It is not, and the difference is about layers.

A REST API is one specific contract for one specific service. MCP is a protocol layer above that. It defines how an AI client discovers available tools, inspects their input schemas, and calls them in a consistent way, regardless of what sits underneath. The MCP server still talks to the real REST APIs and databases. The model only has to learn MCP once instead of learning every API individually.

In other words, REST answers "how do I call this one service," while MCP answers "how does an AI application discover and use any tool that has been exposed to it." They are complementary, not competing.

# What can you do with MCP?

MCP is already in production across everyday developer workflows, not just demos. A few concrete examples:

* **AI-assisted coding.** Editors like Cursor, Zed, and VS Code connect to servers that read your repo, check recent commits, and query your backend, so the assistant reasons over real project context instead of guessing.
* **Debugging and operations.** An assistant can pull recent errors from a Sentry server, cross-reference the failing deployment on a Vercel server, and inspect the offending commit on GitHub, all in one thread.
* **Internal knowledge and data.** Teams expose sales figures, inventory, or customer records through a server so employees can ask questions in plain language and get answers backed by live data.
* **Backend management.** With a server for your cloud platform, an agent can create users, run migrations, and deploy functions without you leaving the chat.

The common thread is that each of these used to require custom integration work. MCP makes the capability portable across every client that speaks the protocol.

# How to build an MCP server

You do not need to implement the protocol by hand. The MCP project provides official [SDKs](https://modelcontextprotocol.io/docs/sdk) that handle the JSON-RPC layer, initialization handshake, and transport for you.

Building an MCP server generally involves four steps:

1. **Define its capabilities.** Decide which tools, resources, or prompts the server should expose.
2. **Connect those capabilities to real logic.** A tool might call an API, query a database, or trigger an action, while a resource might return documentation or stored data.
3. **Choose a transport.** Use stdio when the server runs locally or Streamable HTTP when it is hosted remotely.
4. **Connect and test the server.** Add it to an MCP-compatible client and confirm that its capabilities can be discovered and invoked correctly.

Start with a small, narrowly scoped server. Once the basic connection works, you can add more capabilities without changing how compatible clients communicate with it.

# MCP security best practices

MCP itself is just the protocol; security depends entirely on how a server is configured and what credentials it holds. Treat every MCP server like a privileged backend service.

Scope API keys to the minimum permissions the task needs, prefer local stdio servers when you want credentials to stay on your machine, and use staging projects for exploratory work. Before connecting any server, audit which tools it exposes. An MCP server is only as safe as the access you hand it.

# Getting started with Appwrite MCP servers

Appwrite provides multiple MCP servers for different workflows.

The [Appwrite API MCP server](/docs/tooling/ai/mcp-servers/api) connects AI coding agents to your Appwrite project, allowing them to work with services such as Auth, Databases, Storage, and Functions. Its compact two-tool architecture automatically supports Appwrite services while using less model context.

The [Appwrite Docs MCP server](/docs/tooling/ai/mcp-servers/docs) gives compatible AI clients access to Appwrite documentation, helping them answer product questions and generate code using relevant Appwrite context.

Read [how we rebuilt the API MCP server in MCP Server 2.0](/blog/post/announcing-appwrite-mcp-server-2), then follow the documentation to connect the server that fits your workflow.

# Resources

* [What exactly is MCP, and why is it trending?](/blog/post/what-is-mcp)
* [10 awesome MCP servers and clients for developers](/blog/post/10-best-mcp-server-client)
* [Official MCP specification](https://modelcontextprotocol.io)
* [Join the Appwrite Discord community](https://appwrite.io/discord)

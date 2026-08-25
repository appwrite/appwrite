---
layout: post
title: "Build and deploy an MCP server with Appwrite Functions"
description: Deploy your own MCP server on Appwrite Functions with the new Python template. Expose custom tools to Claude Code, Cursor, and other AI clients over HTTPS.
date: 2026-08-06
cover: /images/blog/announcing-mcp-server-template/cover.avif
timeToRead: 6
author: chirag-aggarwal
category: announcement, ai
featured: false
faqs:
  - question: "What is the MCP server template for Appwrite Functions?"
    answer: "It is a function template that deploys a stateless Model Context Protocol server over HTTPS, built with the official MCP Python SDK. It ships with two demo tools (`echo` and `add`), optional bearer authentication, and a package structure ready for your own tools. You can create it from the Appwrite Console under **Functions** > **Templates**."
  - question: "How do I connect Claude Code or Cursor to an MCP server hosted on Appwrite?"
    answer: "Deploy the template and copy your function's domain. In Claude Code, run `claude mcp add --transport http my-mcp https://<your-function>.appwrite.run`. In Cursor or Claude Desktop, add the URL to the `mcpServers` section of your MCP configuration. The server speaks Streamable HTTP, so there is no local process to run."
  - question: "How do I add my own tools to the MCP server template?"
    answer: "Register tools in `src/app.py` with the `@server.tool` decorator. Python type hints on the function's parameters become the tool's `inputSchema` automatically. Push the change to your connected repository, and once the new deployment is live, clients pick up the updated tool list."
  - question: "How do I secure an MCP server running on Appwrite Functions?"
    answer: "Set the `MCP_AUTH_MODE` environment variable to `bearer` and `MCP_AUTH_TOKEN` to a long random secret. The server then rejects any request without a matching `Authorization: Bearer` header, using constant-time comparison. Clients pass the token through the `headers` field of their MCP configuration."
  - question: "Does the template support the stateless MCP 2026-07-28 specification?"
    answer: "Yes. The template handles both the legacy handshake used by clients on protocol version `2025-06-18` and the modern stateless protocol introduced in the [MCP 2026-07-28 specification](/blog/post/mcp-goes-stateless-in-the-2026-07-28-specification). Appwrite Functions are stateless request/response workers, which matches the direction the protocol has taken."
  - question: "What are the limitations of the MCP server template?"
    answer: "Two main ones. The server is stateless JSON over HTTPS, so there is no SSE streaming, no progress updates during tool calls, and no server-initiated messages like sampling. And tool calls run as [synchronous executions](/docs/products/functions/execute#synchronous-executions), which Appwrite caps at 30 seconds. Keep `MCP_TOOL_TIMEOUT` at its default of 25 seconds so a slow tool returns a clean JSON-RPC error before the cap. Long-running work should start a job and return a handle that another tool can poll."
  - question: "Is this the same as the Appwrite MCP server?"
    answer: "No. The [Appwrite MCP server](/docs/tooling/ai/mcp-servers) gives AI tools access to Appwrite's own APIs, like creating users or managing databases, through `https://mcp.appwrite.io/`. The MCP server template is for building and hosting your own MCP server with your own custom tools on Appwrite Functions."
---

The Model Context Protocol has become the standard way to give AI tools new capabilities. Claude Code, Cursor, Claude Desktop, and most other AI clients speak it, and when an agent needs a skill it does not have, the answer is an MCP server. Writing one is the easy part: with the official SDKs, a tool is a decorated function. Hosting one is where the friction starts. You need an HTTPS endpoint, authentication, deployments, and infrastructure that scales, all for what is often fifty lines of code.

Today, we are announcing the **MCP server template** for Appwrite Functions. It deploys a working, stateless MCP server over HTTPS, built with the official MCP Python SDK, that you can connect to Claude Code, Cursor, and any other MCP client in minutes. You will find it in the Appwrite Console under **Functions** > **Templates**.

# What you get out of the box

- **A working MCP server**: the template ships with two demo tools, `echo` and `add`, so you can verify the full loop from AI client to hosted server before writing any code.
- **The official Python SDK**: tools are plain Python functions registered with a decorator. Type hints become each tool's `inputSchema` automatically.
- **Streamable HTTP transport**: stateless JSON-RPC over your function's domain, supporting both the legacy MCP handshake and the stateless protocol from the [MCP 2026-07-28 specification](/blog/post/mcp-goes-stateless-in-the-2026-07-28-specification).
- **Optional bearer authentication**: one environment variable gates the endpoint behind an `Authorization` header.
- **Nothing to operate**: your function's domain is the server URL. TLS, deployments, scaling, and logs all come from [Appwrite Functions](/docs/products/functions).

The template pins the MCP Python SDK to `mcp==2.0.0`. Version 2.0.0 [became stable on July 28, 2026](https://github.com/modelcontextprotocol/python-sdk/releases/tag/v2.0.0), so it is still a new major release. The exact pin is deliberate because the template's serverless adapter uses lower-level SDK entry points that can change between releases. Review compatibility before upgrading it.

# Deploy an MCP server from the Console

1. In the [Appwrite Console](https://cloud.appwrite.io), open your project and head to **Functions** > **Templates**.

2. Search for **MCP server** and click **Create**.

![Appwrite MCP server template](/images/blog/announcing-mcp-server-template/template.avif)

3. Optionally set environment variables: `MCP_SERVER_NAME` for the display name clients see, `MCP_AUTH_MODE` and `MCP_AUTH_TOKEN` for bearer authentication, and `MCP_TOOL_TIMEOUT` for a soft deadline on tool calls (best kept at the 25-second default). All of them have sensible defaults.

4. Optionally, connect a GitHub repository. You can connect one later through the function's settings page too.

5. Deploy the function.

Once the deployment is live, your function's domain is your MCP server URL:

```
https://<your-function>.appwrite.run
```

There is no separate hosting step and no reverse proxy to configure. The domain serves MCP directly.

# Connect it to your AI tools

In Claude Code, adding the server is one command:

```bash
claude mcp add --transport http my-mcp https://<your-function>.appwrite.run
```

In Cursor or Claude Desktop, add the URL to your MCP configuration:

```json
{
  "mcpServers": {
    "my-mcp": {
      "url": "https://<your-function>.appwrite.run"
    }
  }
}
```

That is the whole setup. Ask your agent to list the server's available tools, then call one to see the request travel from your editor to a serverless function and back.

# Write your own tools

The demo tools exist to be replaced. Tools live in `src/app.py`, and each one is a Python function registered with a decorator:

```python
# src/app.py
from mcp.server.mcpserver import MCPServer

server = MCPServer(name="my-mcp", version="0.1.0")

@server.tool(description="Do something useful.")
def my_tool(query: str) -> str:
    return f"got: {query}"
```

Type hints become the tool's `inputSchema`, so clients know exactly what arguments to send. Push the change to your connected repository, and Appwrite builds and activates the new deployment automatically.

Because the server runs inside your Appwrite project, every request carries a [dynamic API key](/docs/products/functions/develop#dynamic-api-key) in its headers. A tool that needs your data can initialize the Appwrite SDK with that key and query your databases, storage, or users, without you creating, scoping, or rotating any credentials.

# Secure the endpoint

By default, the endpoint is open, which is convenient while you experiment. Before you expose anything internal, set two environment variables on the function: `MCP_AUTH_MODE=bearer` and `MCP_AUTH_TOKEN` set to a long random secret. The server then rejects every request that does not carry the matching header, comparing tokens in constant time.

On the client side, pass the token in your MCP configuration:

```json
{
  "mcpServers": {
    "my-mcp": {
      "url": "https://<your-function>.appwrite.run",
      "headers": {
        "Authorization": "Bearer your-long-random-secret"
      }
    }
  }
}
```

# Built for the stateless MCP era

For most of its life, MCP was a stateful protocol, and it fought serverless hosting. Sessions had to land on the same instance, and the transport leaned on long-lived SSE streams. The [MCP 2026-07-28 specification](/blog/post/mcp-goes-stateless-in-the-2026-07-28-specification) changed that by making the protocol core stateless: every request is self-describing, and any request can be handled by any instance.

That shift makes request/response platforms like Appwrite Functions a natural home for MCP servers, and the template meets the protocol on both sides of the transition. It answers the legacy `initialize` handshake for clients still on protocol version `2025-06-18`, and it handles the modern stateless path for clients that have moved to `2026-07-28`.

# When to reach for this template

The template is the right starting point when you want AI agents to call tools that are yours:

- **Internal team tools**: look up orders, query a product database, check the status of a job, or trigger a workflow, all from a chat in your editor.
- **A controlled wrapper around an existing API**: instead of handing an agent raw API credentials, expose three or four specific operations with your validation in the middle.
- **Tools over your Appwrite data**: with the dynamic API key, tools can read and write your project's databases and storage without extra configuration.

## What it does not do

The template makes two deliberate trade-offs, and they are worth knowing before you build on it:

- **No streaming.** The server is stateless JSON over HTTPS. There is no SSE support, so tools cannot stream progress while they run, and the server cannot send server-initiated messages like sampling requests back to the client. A tool call returns once, with its final result.
- **No long-running tool calls.** Requests to a function's domain are [synchronous executions](/docs/products/functions/execute#synchronous-executions), which Appwrite caps at 30 seconds. We recommend keeping `MCP_TOOL_TIMEOUT` at its default of 25 seconds, so a slow tool returns a clean JSON-RPC error before the platform cuts the connection at 30. For work that needs minutes, have one tool start the job and return a handle that a second tool can poll.

If streaming or long-running synchronous calls are hard requirements, a container-based host is the better fit for that server today. And if what you want is for AI tools to manage Appwrite itself, creating users, provisioning databases, or deploying functions, you do not need to build anything: the hosted [Appwrite MCP server](/docs/tooling/ai/mcp-servers) already does that with one URL.

# Start building your MCP server

The MCP server template is available now on [Appwrite Cloud](https://cloud.appwrite.io) and for self-hosted instances. Open your project, head to **Functions** > **Templates**, search for **MCP server**, and you will have a live endpoint your AI tools can call in a few minutes. If you are new to the Model Context Protocol, our [complete guide to MCP](/blog/post/what-is-mcp-a-complete-guide-for-developers) covers how the protocol works before you start building on it.

To see the template applied to a realistic use case, follow our [financial analysis MCP server tutorial](/blog/post/financial-analysis-mcp-server). It shows how to turn account and transaction data into controlled tools for portfolio summaries, statements, spending analysis, and cash-flow reports.

- [Build an MCP server guide](/docs/tooling/ai/build-mcp-server)
- [Appwrite Functions development guide](/docs/products/functions/develop)
- [MCP server template source on GitHub](https://github.com/appwrite/templates/tree/main/python/mcp-server)
- [What's new in the MCP 2026-07-28 specification](/blog/post/mcp-goes-stateless-in-the-2026-07-28-specification)

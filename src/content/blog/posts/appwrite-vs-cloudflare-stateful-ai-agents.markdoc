---
layout: post
title: "Appwrite vs Cloudflare for stateful AI agents"
description: "Compare Appwrite and Cloudflare as a stateful AI agents backend, from memory and state patterns to Auth, Databases, Storage, Functions, Realtime, and MCP."
date: 2026-04-30
cover: /images/blog/appwrite-vs-cloudflare-stateful-ai-agents/cover.avif
timeToRead: 9
author: aditya-oberai
unlisted: true
category: comparisons
faqs:
  - question: "How do Appwrite and Cloudflare differ for stateful AI agents?"
    answer: "Cloudflare's Agents SDK is built on Durable Objects and Workers, where each agent is a TypeScript class running on a stateful micro-server with its own SQL storage. Appwrite is a broader open-source backend where agent state lives as rows in TablesDB, accessible to any function, agent, or authenticated client through the same API. Cloudflare provides stateful compute, while Appwrite provides a complete app backend."
  - question: "Does Cloudflare Agents include first-party authentication?"
    answer: "No. Cloudflare's Agents SDK does not ship a first-party identity layer for agents. Teams bring their own auth provider and wire sessions and permissions into agent logic. Appwrite ships first-party Auth with email, OAuth, phone, anonymous, MFA, Teams, and roles, with no third-party dependency required."
  - question: "What languages can I use with each platform?"
    answer: "Cloudflare's Agents SDK is TypeScript-first, with Workers and Durable Objects also centered on TypeScript. Appwrite Functions support Node, Python, Go, Ruby, Deno, PHP, and more, with CRON schedules and event triggers. Teams writing Python, Go, or other server languages have more room on Appwrite."
  - question: "How does state management compare between Durable Objects and Appwrite TablesDB?"
    answer: "Durable Objects scope state per agent instance with its own SQL, and Cloudflare handles placement and hibernation. Cross-agent queries and shared user tables become a coordination problem across objects. Appwrite stores state as rows in shared tables with relationships, indexes, and platform-enforced permissions, so reading another user's row or running cross-thread analytics is a normal query."
  - question: "Is Cloudflare Agents open source and self-hostable?"
    answer: "No. Cloudflare's stateful agent infrastructure is proprietary and tied to the Cloudflare platform, including Durable Objects, Workers, R2, and D1. Appwrite is open source with unrestricted self-hosting, or you can run on Appwrite Cloud with the same API surface."
  - question: "Can I host an AI app's frontend on either platform?"
    answer: "Cloudflare provides Workers, Pages, and related services for hosting. Appwrite Sites deploys frontends with source-control deploys, custom domains, environment variables, rollbacks, and logs in the same project as your data, auth, and functions, so the agent backend and the frontend live under one control plane."
---

The conversation around stateful AI agents has shifted. Teams are no longer just asking "where does my model run?" They are asking where threads live, how tool calls recover after a crash, how files attach to agent sessions, how live updates reach every client, and how coding agents operate on the backend through MCP.

Two platforms come up in this conversation often. Cloudflare has been marketing stateful agent infrastructure through its Agents SDK, Durable Objects, and Workers. Appwrite takes a different angle as a broader open-source backend that covers Auth, Databases, Storage, Functions, Realtime, Sites, and MCP. This is the comparison for teams evaluating a Cloudflare Agents alternative and for teams deciding where to put the stateful AI agents backend end to end.

# How Cloudflare positions itself for stateful AI agents

Cloudflare's Agents SDK is built on Durable Objects and Workers. The docs describe each agent as a TypeScript class that runs on a Durable Object, a stateful micro-server with its own SQL storage, WebSocket connections, and scheduling. The pitch is that state survives restarts, deploys, and hibernation, and that the same code runs across Cloudflare's global network.

Around that core, Cloudflare layers more:

- AIChatAgent with streaming, message persistence, and resumable streams for chat agents.
- `@callable()` methods that become typed RPC over WebSocket.
- Scheduling by delay, by specific time, or by cron, so agents can wake themselves up.
- Client tools, server tools, and human-in-the-loop approval flows.
- MCP support so agents can expose tools to other agents and LLMs.
- Integrations with Workers AI, AI Gateway, Workflows, and browser tools.

If you are committed to the Cloudflare platform and writing agents in TypeScript, the model is cohesive. Each agent is a class, state is co-located with compute in a Durable Object, and Cloudflare handles the placement, hibernation, and scale.

# Where Cloudflare creates trade-offs for AI teams

A few things show up once you look past the marketing page.

- **Stateful compute is the product, not the backend.** Durable Objects and Workers give you compute and storage tied to an agent instance. Auth, a broad database for app data, a file system with permissions, and a hosted frontend for your AI app are not part of the same product. You stitch those in from D1, R2, Workers, and other services.
- **Language surface is narrow.** The Agents SDK is TypeScript-first. If your team writes Python, Go, or anything else on the server, you are back to Workers, D1, and custom glue.
- **State lives per agent.** A Durable Object is scoped to an agent instance with its own SQL. Cross-agent queries, shared user tables, and cross-thread analytics are your job to design and coordinate across objects.
- **Auth is not first-party for agents.** You bring an identity layer separately, usually a third-party provider, then wire sessions and permissions into your agent logic.
- **Lock-in to the edge model.** Your agent's durability assumptions, scheduling, storage, and hibernation are tied to Durable Objects. Moving off Cloudflare means rewriting the stateful part, not just changing a deployment target.

None of this makes Cloudflare a bad choice for a specific kind of agent. It does mean Cloudflare Agents is an infrastructure layer for stateful compute, not a complete backend for your AI product.

# How Appwrite positions itself for AI apps and agents

Appwrite takes a different angle. Instead of shipping a dedicated Agent runtime, Appwrite provides a broad backend where agent workloads sit on top of the same primitives that power any application:

- **Auth** with email, OAuth, phone, anonymous, and MFA, plus Teams and roles, without a third-party service.
- **Databases** with TablesDB: databases, tables, rows, typed columns, relationships, queries, indexes, and transactions. You model agent state, thread metadata, message history, tool results, and user profiles as rows in tables.
- **Storage** with buckets, permissions, antivirus scanning, and image transformations for files attached to agent sessions, RAG sources, and user uploads.
- **Functions** on Node, Python, Go, Ruby, Deno, PHP, and more, for tool calls, orchestration, LLM integrations, webhooks, and scheduled jobs.
- **Sites** for hosting the frontend of your AI app with source control deploys, custom domains, env vars, and rollbacks.
- **Realtime** channels that subscribe to row changes, user events, and custom events, which is how you push token output, tool call results, and agent status to every connected client.
- **Messaging** for email, SMS, and push, useful for human-in-the-loop approval and async notifications from long-running agents.
- **Appwrite MCP servers**, both the API MCP for acting on Appwrite resources and the Docs MCP for Appwrite context, so coding agents can build and operate against Appwrite directly.
- **Agent Skills** for the Appwrite CLI and every major SDK including TypeScript, Dart, .NET, Go, Kotlin, PHP, Python, Ruby, Rust, and Swift, so agents generate idiomatic Appwrite code without docs in the prompt.
- **Editor plugins** for [Claude Code](/docs/tooling/ai/ai-dev-tools/claude-code) and [Cursor](/docs/tooling/ai/ai-dev-tools/cursor) that bundle the API MCP, Docs MCP, and skills into one install.
- **Self-hosting** under an open-source license, or **Appwrite Cloud** with the same API surface.

The point is not that Appwrite replaces Durable Objects one-to-one. The point is that once you have identity, structured tables, rows, storage, functions, realtime, and MCP, you already have what a stateful AI agent needs: users, durable state, memory, tool hosting, live updates to clients, and a way for coding agents to act on your backend.

# Appwrite vs Cloudflare: side by side

| Capability | Cloudflare | Appwrite |
|---|---|---|
| Agent state | Per-agent Durable Object with SQL storage, hibernation, WebSockets | Rows in TablesDB scoped with Auth, Teams, and permissions, readable across agents |
| Auth | Bring your own provider or custom session logic | First-party Auth with email, OAuth, phone, anonymous, MFA, Teams, roles |
| Database | Durable Object SQL per instance, or D1 for shared SQL | TablesDB with databases, tables, typed columns, rows, relationships, queries, indexes, transactions |
| Storage | R2 object storage, wired into Workers | Storage buckets with permissions, antivirus scanning, and image transformations |
| Functions / compute | Workers and Durable Objects, TypeScript-first Agents SDK | Functions in Node, Python, Go, Ruby, Deno, PHP, and more, with CRON schedules and event triggers |
| Realtime | WebSockets and SSE from Durable Objects and Workers | Realtime channels for row changes, user events, and custom events |
| Hosting | Workers, Pages, and related services | Sites with source control deploys, custom domains, env vars, rollbacks |
| MCP, Skills, and editor plugins | Agents SDK MCP tooling for Cloudflare workloads | Appwrite API MCP, Appwrite Docs MCP, Agent Skills for CLI and 10 SDKs, Claude Code and Cursor plugins |
| Self-hosting | Proprietary, Cloudflare platform only | Open source with unrestricted self-hosting, plus Appwrite Cloud |
| Pricing model | Per-request, per-CPU, per-GB storage across Workers, Durable Objects, R2, D1 | Plan-based Appwrite Cloud with generous free tier, or self-host |

# State patterns, without overpromising compute behavior

Stateful AI agents usually need three things from a backend: a place to keep durable state between turns, a way to push updates to clients as they happen, and a way to run logic on schedule or in response to events.

Cloudflare answers this with Durable Objects. Each agent instance gets a stateful micro-server with its own SQL. The state is co-located with the compute that uses it, and Cloudflare handles placement and hibernation. It is a strong story for agents where everything the agent needs to know fits inside the object.

Appwrite answers it at a higher level. State lives in tables and rows with typed columns, relationships, and permissions. Any Function, any agent, any authenticated client can read and write that state through the same API. Realtime pushes row changes to subscribed clients without building a custom WebSocket layer. Functions handle scheduled runs through CRON and react to events from other Appwrite services. When an agent needs to read another user's profile row, share a thread between two users, or run analytics across all agents, it is a query against a shared database, not a coordination problem between objects.

The Appwrite approach does not try to replace stateful edge compute. It treats agent state as application data that should live in the same place as the rest of your product data, protected by the same auth, permissions, and queries.

# Where Appwrite fits

Appwrite is the right pick when:

- You need a broad backend that handles Auth, TablesDB, Storage, Functions, Realtime, Sites, Messaging, and MCP without stitching multiple vendors together.
- You want relational-style tables, typed columns, and queries for thread state, message history, and RAG metadata, with permissions on rows that the platform enforces.
- You want first-party Auth with Teams and roles from day one, not a third-party dependency.
- You want coding agents to act on your backend through a first-party MCP, with the Docs MCP, Agent Skills, and editor plugins available for context inside tools like Cursor, Claude Code, Lovable, and Windsurf.
- You care about self-hosting under a standard open-source license or the option to move between Appwrite Cloud and your own infrastructure.

Cloudflare's Agents SDK is a tighter fit when the agent itself is the entire product, the team is committed to TypeScript on Workers, and per-agent Durable Objects are the unit of state you want.

# How teams usually split the decision

Most AI products that reach users end up looking like a broader app: a CRM with agents, a support product with an AI assistant, a SaaS tool with background agents. The agent is one of many surfaces. You also need user accounts, teams, permissions, file uploads, notifications, scheduled jobs, webhooks, a hosted frontend, and coding agents that can operate on your backend through MCP. That is where Appwrite's breadth pays off. You are not spending the first month wiring up four Cloudflare services and a separate auth vendor before you get to the AI part.

Pure stateful agents where the compute and the state are the entire product, like a long-running research agent or a multiplayer coordination agent, are the narrower case Cloudflare's Durable Objects model is built for. Even there, Appwrite Functions plus TablesDB cover most of the same ground without the per-instance edge runtime lock-in.

# Stateful AI agents come down to primitives, not products

The useful question is not "does this platform sell an Agents SDK?" It is "does this platform give me identity, durable state, event triggers, realtime, storage, tool hosting, and a way for coding agents to act on my backend?"

Cloudflare answers that with an Agents SDK on top of Durable Objects and Workers, then leaves the rest of the backend to you. Appwrite answers it with a broader open-source backend where Auth, TablesDB, Storage, Functions, Realtime, Sites, and MCP line up with what stateful AI agents need, without making you adopt a per-instance edge runtime before your product shape is clear.

If you want to explore Appwrite as the backend for your stateful AI agents, start with [Appwrite Cloud](https://cloud.appwrite.io), read the [AI tooling hub](/docs/tooling/ai), and check the [API MCP server](/docs/tooling/ai/mcp-servers/api), [Agent Skills](/docs/tooling/ai/skills), and the editor plugins for [Claude Code](/docs/tooling/ai/ai-dev-tools/claude-code) and [Cursor](/docs/tooling/ai/ai-dev-tools/cursor).

- [Appwrite Databases](/docs/products/databases)
- [Appwrite Functions](/docs/products/functions)
- [Appwrite Auth](/docs/products/auth)
- [Appwrite Realtime](/docs/apis/realtime)
- [Appwrite Storage](/docs/products/storage)
- [Appwrite AI tooling](/docs/tooling/ai)

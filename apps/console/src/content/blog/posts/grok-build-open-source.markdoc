---
layout: post
title: "Grok Build is now open source"
description: Grok Build is now open source under Apache 2.0, giving developers an inspectable coding-agent harness, local inference, and privacy controls.
date: 2026-07-16
cover: /images/blog/grok-build-open-source/cover.avif
timeToRead: 7
author: aishwari
category: ai
featured: false
unlisted: false
faqs:
  - question: "Is Grok Build open source?"
    answer: "Yes. SpaceXAI published the Grok Build CLI, terminal UI, and agent runtime on [GitHub](https://github.com/xai-org/grok-build) under the Apache License 2.0. The repository can be inspected, modified, and built locally, although SpaceXAI does not currently accept external pull requests."
  - question: "Can Grok Build run with a local model?"
    answer: "Yes. Grok Build supports custom model endpoints using OpenAI Chat Completions, OpenAI Responses, or Anthropic Messages protocols. Its official guide includes examples for Ollama and local OpenAI-compatible servers."
  - question: "Does Grok Build retain coding data?"
    answer: "SpaceXAI says zero-data-retention users have always been covered by ZDR and that data upload choices were respected. It disabled retention by default for every Grok Build user on July 12, 2026, and says previously retained coding data is being deleted."
  - question: "How do I disable data sharing in Grok Build?"
    answer: "Run `/privacy` to view the current status and `/privacy opt-out` to opt out of coding-data sharing. The open-source configuration guide also documents `telemetry = false` and `trace_upload = false` controls."
  - question: "Can developers contribute code to Grok Build?"
    answer: "Not through external pull requests at launch. The repository's contributing guide says SpaceXAI develops the software internally and publishes a periodically synced public tree for source transparency and local builds."
---

Coding agents can modify thousands of lines, run shell commands, and send code context to an inference service. That makes the harness around the model part of the security boundary, not just a convenient interface.

SpaceXAI has now made that boundary inspectable. In a [July 16 announcement](https://x.com/SpaceXAI/status/2077494535387828644), the company open-sourced Grok Build, reset usage limits for all users, turned data retention off by default, and committed to deleting coding data retained before the change.

The result is more consequential than publishing another CLI. Developers can inspect how the agent reads a workspace, approves actions, executes tools, stores sessions, connects to external services, and routes prompts to a model. They can also build the harness from source and point it at their own inference endpoint.

# Grok Build is now an Apache 2.0 coding-agent harness

The public [`xai-org/grok-build` repository](https://github.com/xai-org/grok-build) contains the Rust source for the `grok` CLI, its full-screen terminal UI, and the underlying agent runtime. SpaceXAI describes it as a coding agent that understands a codebase, edits files, executes shell commands, searches the web, and manages long-running tasks.

The repository exposes more than the presentation layer. Its package layout separates the terminal UI, agent runtime, tool implementations, workspace and version-control handling, configuration, Model Context Protocol (MCP) support, sandboxing, and telemetry. That gives developers a practical map of the components that turn model output into actions on a machine.

First-party code is available under the **Apache License 2.0**. Third-party and vendored code retains its original licenses, with notices included in the repository. The project is primarily Rust, uses a pinned Rust toolchain, and supports source builds on macOS and Linux. Windows source builds are described as best-effort and are not currently tested from the public tree.

To build and launch the terminal UI from source, the official README provides:

```bash
cargo run -p xai-grok-pager-bin
```

Developers who prefer a released binary can use SpaceXAI's installer for macOS, Linux, or Git Bash:

```bash
curl -fsSL https://x.ai/cli/install.sh | bash
grok --version
```

Windows users can install from PowerShell. The [Grok Build product page](https://x.ai/cli) also documents headless execution for scripts and CI, editor embedding through the Agent Client Protocol, and features including plan mode, subagents, skills, hooks, MCP servers, `AGENTS.md`, sandboxed execution, and Git integration.

# Can developers contribute to the Grok Build open-source repository?

There is an important distinction in this release. The code is licensed for use, modification, and redistribution under Apache 2.0, but the repository's contributing guide says **external pull requests and unsolicited patches are not accepted**.

SpaceXAI develops Grok Build internally and periodically syncs the public tree from its monorepo. Developers can audit the implementation, build forks, and report problems, but they cannot currently participate through the usual upstream pull-request workflow.

That limits community maintenance of the official project, even while the license enables independent forks. It also makes the announcement's goal of letting anyone help create a more reliable harness less direct than it would be with an open contribution process. The immediate value is transparency and experimentation. Upstream code collaboration may require SpaceXAI to change its contribution policy later.

# Can Grok Build run local AI models for private coding?

Open-sourcing the harness gives developers control over one half of an agent system. The other half is inference, where prompts, source snippets, and tool results are processed by a model.

Grok Build's [custom-model guide](https://github.com/xai-org/grok-build/blob/main/crates/codegen/xai-grok-pager/docs/user-guide/11-custom-models.md) supports three API protocols:

- **OpenAI Chat Completions** through `/v1/chat/completions`
- **OpenAI Responses** through `/v1/responses`
- **Anthropic Messages** through `/v1/messages`

Custom models are configured in `~/.grok/config.toml` with a model ID, base URL, API backend, context window, and credentials where required. The same mechanism can target a third-party provider, a corporate inference gateway, an Ollama instance, or another local OpenAI-compatible server.

For example, the official guide shows a local Ollama configuration shaped like this:

```toml
[model.ollama-codellama]
model = "codellama"
base_url = "http://localhost:11434/v1"
name = "CodeLlama (Ollama)"
```

With a genuinely local model endpoint, inference traffic does not need to leave the developer's machine. Teams still need to review every enabled network-capable tool, MCP server, hook, plugin, telemetry setting, and shell permission. Local inference reduces exposure, but it does not automatically make the entire agent air-gapped.

# Grok Build privacy defaults have changed

SpaceXAI's announcement addresses an earlier mismatch between available controls and defaults. The company says Grok Build has respected **zero data retention (ZDR)** since launch and has always honored a user's choice to disable data upload. During the early beta, however, retention was enabled by default for users who were not covered by ZDR.

SpaceXAI says it made three changes:

- **Retention became off by default for all Grok Build users on July 12, 2026.**
- **Previously retained coding data is being deleted.**
- **The open-source harness can run local-first with user-selected inference.**

These are separate controls. ZDR is an account or organization retention policy. Coding-data sharing is a user-selectable preference. Local inference changes where model requests are processed. Developers should verify all three rather than treating one setting as a substitute for the others.

The public source makes the client-side controls easier to verify. Grok Build documents `/privacy` for viewing retention status, `/privacy opt-out` for disabling coding-data sharing, and `/privacy opt-in` for enabling it. Its [configuration guide](https://github.com/xai-org/grok-build/blob/main/crates/codegen/xai-grok-pager/docs/user-guide/05-configuration.md) also exposes an anonymous telemetry switch and a separate trace-upload control:

```toml
[features]
telemetry = false

[telemetry]
trace_upload = false
```

The repository additionally documents an optional external OpenTelemetry stream for organizations that want usage metrics sent to their own collector. That stream is off by default, requires a master switch plus an exporter selection, and is content-free by default. Prompt and tool details have separate opt-in gates.

The deletion of previously retained data remains a company commitment stated in the announcement, not something a client-side code audit can independently prove. Teams with contractual retention requirements should verify their account policy and deployment path with SpaceXAI rather than inferring server behavior from the CLI alone.

# Why the Grok Build coding-agent harness matters for evaluation and security

Model benchmarks measure only part of an agent's usefulness. A coding agent also depends on context collection, tool schemas, permission handling, retry behavior, compaction, code search, checkpointing, and the quality of the prompts wrapped around each model call.

Publishing Grok Build makes those choices available for inspection and comparison. Developers can trace how a request becomes a tool call, test changes against their own repositories, or keep the harness constant while swapping inference providers. That makes it easier to separate model capability from harness quality when an agent succeeds or fails.

It also creates a clearer route for security review. The repository's [`SECURITY.md`](https://github.com/xai-org/grok-build/blob/main/SECURITY.md) directs vulnerability reports to the [X and xAI HackerOne program](https://hackerone.com/x?type=team), while normal behavior can be investigated against the same source used to build the tool.

# Use Grok Build with an Appwrite-aware workflow

Grok Build already supports two standards that help an agent work safely and accurately in an Appwrite project: **`AGENTS.md` instructions and MCP servers**.

An [`AGENTS.md` file](/docs/tooling/ai/agents-md) gives the agent repository-specific conventions, including the framework, SDK, project structure, and Appwrite services in use. This keeps durable guidance in version control instead of repeating it in every prompt.

Appwrite's [MCP servers](/docs/tooling/ai/mcp-servers) serve a different role. The docs server gives an agent current Appwrite documentation, while the API server can interact with an authorized Appwrite project. Keep API credentials scoped to the operations the agent needs, review tool approvals, and separate documentation access from project mutation.

Open source makes Grok Build easier to inspect and adapt, but safe agent operation still comes from explicit boundaries. Start with a test repository, review the privacy state, choose the inference endpoint deliberately, inspect enabled integrations, and grant the narrowest practical permissions.

# Build AI apps with inspectable tooling

The Grok Build release pushes coding agents toward a more inspectable stack. Developers can now audit the harness, run it with local or custom inference, and verify client-side privacy controls before allowing it into a codebase.

For the backend behind an AI-assisted project, [start on Appwrite Cloud](https://cloud.appwrite.io) and give your agent current, project-specific context through Appwrite's tooling:

- [Generate an `AGENTS.md` file](/docs/tooling/ai/agents-md)
- [Configure Appwrite MCP servers](/docs/tooling/ai/mcp-servers)
- [Explore Appwrite AI tooling](/docs/tooling/ai)

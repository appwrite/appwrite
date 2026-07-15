---
layout: post
title: "Grok 4.5: coding model benchmarks and pricing"
description: Grok 4.5 is SpaceXAI's new coding model. Here's a source-backed look at benchmarks, pricing, availability, token efficiency, and AI agent workflows.
date: 2026-07-09
cover: /images/blog/grok-45-coding-model/cover.avif
timeToRead: 8
author: atharva
category: ai
featured: false
unlisted: false
faqs:
    - question: "What is Grok 4.5?"
      answer: "Grok 4.5 is SpaceXAI's flagship model for coding, agentic tool calling, and knowledge work. It is available as `grok-4.5` with a 500K token context window, configurable reasoning, text and image input, and text output."
    - question: "How much does Grok 4.5 cost?"
      answer: "SpaceXAI lists Grok 4.5 at \\$2 per million input tokens and \\$6 per million output tokens. Artificial Analysis lists the same price and a \\$0.50 per million cached-input price."
    - question: "How fast is Grok 4.5?"
      answer: "SpaceXAI says Grok 4.5 is served at 80 tokens per second. Artificial Analysis measured 91.3 output tokens per second through SpaceXAI's API, with a 17.42 second time to first answer token."
    - question: "Where can developers use Grok 4.5?"
      answer: "SpaceXAI says Grok 4.5 is available in the SpaceXAI console, Grok Build, and Cursor. The official announcement also says it is not yet available in the EU, with EU availability expected in mid-July 2026."
    - question: "How does Grok 4.5 perform on coding benchmarks?"
      answer: "SpaceXAI reports 83.3% on Terminal Bench 2.1 and 64.7% on SWE Bench Pro. It also reports 62.0% on DeepSWE 1.0 and 53% on DeepSWE 1.1."
    - question: "How can I connect AI coding agents to Appwrite?"
      answer: "Use Appwrite's MCP servers. The [Appwrite API MCP server](/docs/tooling/ai/mcp-servers/api) lets agents act on your project, while the [Appwrite Docs MCP server](/docs/tooling/ai/mcp-servers/docs) gives them current Appwrite documentation."
---

Coding agents fail most often when the task stops being a prompt and starts being engineering work. A useful model has to read the surrounding codebase, make small decisions over a long run, call tools, recover from test failures, and avoid spending half the session writing around the problem.

That is the lane SpaceXAI is targeting with [Grok 4.5](https://x.ai/news/grok-4-5). The company announced the model on [X](https://x.com/SpaceXAI/status/2074915721684086811) on July 8, 2026, calling it its first model trained specifically for coding and agents. The official launch page describes Grok 4.5 as SpaceXAI's smartest model for coding, agentic tasks, and knowledge work, trained alongside [Cursor](https://cursor.com).

The pitch is not just higher benchmark scores. SpaceXAI is claiming a model that is **fast enough for interactive agent work**, **cheap enough for long tasks**, and **efficient enough to use fewer tokens per completed engineering task**.

# Grok 4.5 is built for agentic coding

The most important part of this launch is the training target. SpaceXAI says Grok 4.5 was trained on coding, science, engineering, and math data, then reinforced on hundreds of thousands of multi-step software engineering and technical tasks. The company also says its training stack supports asynchronous rollouts that can run for many hours while training continues across tens of thousands of GPUs.

That matters because long-running coding agents are not ordinary chatbots. They need to:

- Search a large repository without losing the task.
- Use tools and MCP servers without bloating the context window.
- Carry an implementation across many files.
- Keep testing and review in the loop.
- Spend fewer tokens on planning loops and repeated explanations.

SpaceXAI says Grok 4.5 was trained across tens of thousands of NVIDIA GB300 GPUs and with data filtering techniques such as deduplication, quality scoring, and domain-focused selection. The launch post also frames the model around real-world engineering, including large codebases and tasks that span many repositories, skills, and tools.

# Official Grok 4.5 benchmarks

SpaceXAI published coding and agent benchmarks in the launch post. Treat these as official vendor results because the methodology is controlled by SpaceXAI and its stated benchmark sources.

| Benchmark | Grok 4.5 | Leading comparison in SpaceXAI chart | What it measures |
| --- | ---: | ---: | --- |
| DeepSWE 1.0 | 62.0% | Fable max: 66.1% | Software engineering tasks created by Datacurve, run with each model provider's harnesses by Artificial Analysis |
| DeepSWE 1.1 | 53% | Fable max: 70% | Software engineering tasks with the mini-swe-agent harness run by Datacurve |
| Terminal Bench 2.1 | 83.3% | Fable max: 84.3% | Terminal-based agent tasks |
| SWE Bench Pro | 64.7% | Fable max: 80.4% | Repository issue resolution rate |

The benchmark story is strong, but not one-dimensional. Grok 4.5 is effectively tied with GPT-5.5 xhigh on Terminal Bench 2.1 in SpaceXAI's chart (83.3% vs 83.4%) and beats Opus 4.8 max and Opus 4.7 max there. On SWE Bench Pro, it lands ahead of GPT-5.5 xhigh and Opus 4.7 max, but trails Fable max and Opus 4.8 max.

That is the right way to read this release: **Grok 4.5 looks competitive with frontier coding models, especially on cost and speed, but it is not a clean benchmark sweep.**

# Grok 4.5 independent measurements

Artificial Analysis already lists [Grok 4.5 (high)](https://artificialanalysis.ai/models/grok-4-5) with independent measurements across price, speed, context, and its Intelligence Index. These are not a substitute for evaluating the model on your own codebase, but they give a useful outside view of the API. Following are some insights from Artificial Analysis at the time of writing this blog.

| Metric | Artificial Analysis result |
| --- | ---: |
| Artificial Analysis Intelligence Index | 54 |
| Output speed | 91.3 tokens per second |
| Time to first answer token | 17.42 seconds |
| Context window | 500K tokens |
| Input price | $2.00 per 1M tokens |
| Cached input price | $0.50 per 1M tokens |
| Output price | $6.00 per 1M tokens |
| Input modalities | Text and image |
| Output modalities | Text |

The speed number is especially interesting because SpaceXAI's own launch post says Grok 4.5 is served at 80 tokens per second. Artificial Analysis measured it higher at 91.3 tokens per second through SpaceXAI's API. The trade-off is startup latency: Artificial Analysis reports 17.42 seconds to the first answer token for its measured setup.

As of the launch window, [LMArena's public text leaderboard](https://lmarena.ai/leaderboard/text) was dated July 1, 2026 in the visible page data and did not show Grok 4.5 among the top listed models. That makes Artificial Analysis the more useful independent public source for this release right now.

# Grok 4.5 pricing is the sharpest part of the launch

SpaceXAI lists Grok 4.5 at **$2 per million input tokens** and **$6 per million output tokens** in both the [launch announcement](https://x.ai/news/grok-4-5) and the [model docs](https://docs.x.ai/developers/models). Artificial Analysis lists the same standard pricing and a $0.50 per million cached-input price.

| Model | Input | Output | Context |
| --- | ---: | ---: | ---: |
| Grok 4.5 | $2/M | $6/M | 500K |
| GPT-5.5 high | $5/M | $30/M | 1.1M on LMArena listing |
| Claude Opus 4.7 thinking | $5/M | $25/M | 1M on LMArena listing |
| Gemini 3.1 Pro Preview | $2/M | $12/M | 1M on LMArena listing |

The context window is smaller than the 1M-class models in that comparison, but the price gap is large. For agent workloads, the output side often dominates the bill because the agent writes plans, tool arguments, diffs, test summaries, and review notes. Moving from $25/M or $30/M output to $6/M output changes which long-running tasks are reasonable to automate.

SpaceXAI also claims Grok 4.5 uses roughly **2x the token efficiency** of comparable leading models and highlights one SWE Bench Pro measurement where Grok 4.5 used 15,954 output tokens per task compared with 67,020 for Opus 4.8 max. That is a vendor claim, but it points at the right production metric: **cost per completed task**, not price per token in isolation.

# Grok Build turns the model into a terminal agent

Grok 4.5 is also now the default model in [Grok Build](https://x.ai/cli), SpaceXAI's coding agent and CLI. The product page describes a terminal workflow with plan mode, plugins, Q&A, subagents, memory, code search, multi-file edits, Git integration, background tasks, code review, and MCP servers.

The most relevant detail for Appwrite developers is MCP support. SpaceXAI says Grok Build supports AGENTS.md, plugins, hooks, and MCP servers, which puts it in the same broader category as Codex, Cursor, Claude Code, and other agentic development tools that can connect to project-specific context and APIs.

For backend work, this is the difference between a model that writes code about your infrastructure and an agent that can inspect or change infrastructure through an explicit tool interface. That is where MCP becomes the important abstraction.

# Grok 4.5 availability

SpaceXAI says Grok 4.5 is available now in the SpaceXAI console, Grok Build, and Cursor. The official model docs list `grok-4.5` as the flagship model for code, agentic tool calling, configurable reasoning, and general text use, with a 500K token context window.

There is one regional caveat: the launch page says Grok 4.5 is not available in the EU yet, with EU availability expected in mid-July 2026. If you are building production workflows around the API, that availability detail matters for teams, customers, and CI/CD infrastructure located in the EU.

# Grok 4.5 for developers

Grok 4.5 is not trying to be only a better autocomplete model. It is built for the agent loop: read, plan, edit, run, inspect, and repeat. The benchmark and pricing profile make it especially interesting for tasks where the expensive part is not one perfect answer, but several minutes of directed tool use.

Use it where the task has a clear repository boundary and useful tools:

- Multi-file refactors that need tests and review.
- Backend feature work where an agent can inspect API docs and live resources.
- Migration tasks that benefit from search, planning, and diff review.
- Long coding sessions where output-token cost would otherwise block experimentation.

Be careful where correctness depends on private production context the model cannot see. The model can call tools, but it still needs the right scopes, current docs, and testable acceptance criteria. Cheap tokens do not replace a good integration surface.

# Build Grok-powered agents with Appwrite

Appwrite's AI tooling is designed for this exact shift from chat-based code generation to tool-using agents. The [Appwrite AI docs](/docs/tooling/ai) cover AI development tools, MCP servers, quick start prompts, Appwrite Assistant, and guides for building AI-powered features on Appwrite.

For coding agents, start with the MCP layer:

- [MCP server for Appwrite API](/docs/tooling/ai/mcp-servers/api): lets an agent interact with your Appwrite project using natural language commands, including creating users and managing databases.
- [MCP server for Appwrite docs](/docs/tooling/ai/mcp-servers/docs): gives an agent current Appwrite documentation so it can use the latest API, SDK, and CLI patterns.
- [Codex with Appwrite](/docs/tooling/ai/agents/codex): shows how to install the Appwrite plugin, add the Appwrite API MCP server, and test the integration.
- [Cursor with Appwrite](/docs/tooling/ai/agents/cursor): shows how to use Appwrite's Cursor plugin or configure MCP servers manually.

If you want to try the workflow on a real backend, create an [Appwrite Cloud](https://cloud.appwrite.io) project, connect your preferred agent through MCP, and give it a small, reviewable task. Models like Grok 4.5 make the agent loop cheaper and faster, but the best results still come from giving the agent a well-defined project, current docs, scoped credentials, and tests it can actually run.

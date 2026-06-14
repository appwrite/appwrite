---
layout: post
title: Anthropic just launched Claude Opus 4.8 with fast mode and dynamic workflows
description: Explore Claude Opus 4.8, including fast mode, dynamic workflows, effort control, pricing, benchmarks, and how to build agentic apps with Appwrite.
date: 2026-05-29
cover: /images/blog/anthropic-just-launched-claude-opus-48-with-fast-mode-and-dynamic-workflows/cover.avif
timeToRead: 5
author: aishwari
category: ai
featured: false
faqs:
  - question: What is Claude Opus 4.8?
    answer: Claude Opus 4.8 is Anthropic’s latest AI model, designed for advanced coding, reasoning, agentic workflows, and enterprise use cases. It improves reliability, coding performance, and long-running task execution.
  - question: What did Anthropic launch with Claude Opus 4.8?
    answer: Anthropic launched Claude Opus 4.8 along with updates like fast mode, effort control, dynamic workflows in Claude Code, and API improvements for longer-running AI tasks and agents.
  - question: What is Claude Opus 4.8 fast mode?
    answer: Claude Opus 4.8 fast mode lets developers get quicker responses from Claude while reducing cost and latency, making it useful for coding tasks, iteration, and agentic development workflows.
  - question: What context window does Claude Opus 4.8 support?
    answer: It supports a 1M token context window by default on the Claude API, Amazon Bedrock, and Vertex AI, with 200k on Microsoft Foundry, and up to 128k max output tokens.
  - question: How much does Claude Opus 4.8 cost?
    answer: Regular usage is 5 dollars per million input tokens and 25 dollars per million output tokens, unchanged from Opus 4.7. Fast mode is 10 dollars per million input tokens and 50 dollars per million output tokens.
---
Claude Opus 4.8 is now Anthropic's most capable generally available model, shipping with fast mode, mid conversation system messages, effort control, and dynamic workflows in Claude Code.

Anthropic [announced Claude Opus 4.8](https://www.anthropic.com/news/claude-opus-4-8) on May 28, 2026. It builds on Claude Opus 4.7 with improvements across benchmarks, the same regular pricing as the previous generation, and a set of new features that matter most to teams running long agentic workloads. Here is what is in the launch and what it means if you build on Appwrite.

# What is Claude Opus 4.8?

Claude Opus 4.8 is described by Anthropic as its most capable model for complex reasoning, long horizon agentic coding, and high autonomy work. You call it through the Claude API with the model id `claude-opus-4-8`.

Claude Opus 4.8 supports a 1M token context window on the Claude API, Amazon Bedrock, and Vertex AI, with 200k on Microsoft Foundry. Full details are in the [what's new in Claude Opus 4.8 docs](https://platform.claude.com/docs/en/about-claude/models/whats-new-claude-4-8).

# Claude Opus 4.8 benchmarks

Anthropic published a capability table comparing Opus 4.8 to its predecessor Opus 4.7 and to other frontier models across coding, agentic skills, reasoning, and practical knowledge work.

| Benchmark                                                      | Opus 4.8 | Opus 4.7 | GPT-5.5 | Gemini 3.1 Pro |
| -------------------------------------------------------------- | -------- | -------- | ------- | -------------- |
| Agentic coding (SWE Bench Pro)                                 | 69.2%    | 64.3%    | 58.6%   | 54.2%          |
| Agentic terminal coding (Terminal Bench 2.1)                   | 74.6%    | 66.1%    | 78.2%   | 70.3%          |
| Multidisciplinary reasoning, no tools (Humanity's Last Exam)   | 49.8%    | 46.9%    | 41.4%   | 44.4%          |
| Multidisciplinary reasoning, with tools (Humanity's Last Exam) | 57.9%    | 54.7%    | 52.2%   | 51.4%          |
| Agentic computer use (OSWorld Verified)                        | 83.4%    | 82.8%    | 78.7%   | 76.2%          |
| Knowledge work (GDPval AA)                                     | 1890     | 1753     | 1769    | 1314           |
| Agentic financial analysis (Finance Agent v2)                  | 53.9%    | 51.5%    | 51.8%   | 43.0%          |

*Source: Anthropic's official* [Claude Opus 4.8 announcement](https://www.anthropic.com/news/claude-opus-4-8)

Opus 4.8 leads every category in the table except agentic terminal coding, where GPT-5.5 scores higher at 78.2 percent against 74.6 percent. The biggest jumps over Opus 4.7 are in agentic coding and terminal coding, which lines up with where Anthropic focused this release.

Anthropic highlights honesty as one of the release's major improvements, saying Opus 4.8 is more likely to flag uncertainty and less likely to leave flaws unmentioned.

# What is new in Claude Opus 4.8: features and API changes

Several capabilities ship alongside the model that change how you design agents and harnesses.

* Mid conversation system messages let you send a `role: "system"` message immediately after a user turn in the messages array. This means you can append updated instructions later in a long running conversation without restarting the full system prompt, which preserves prompt cache hits on the earlier turns and reduces input cost on agentic loops. No beta header is required.
* Fast mode is now available for Opus 4.8 as a research preview on the Claude API. Set `speed: "fast"` to get up to 2.5 times higher output tokens per second from the same model at premium pricing.
* Effort defaults to high on all surfaces, including the Claude API and Claude Code. If you set effort explicitly today, your setting is unchanged. Anthropic judges high effort to be the best overall balance of quality and user experience, and on coding tasks it spends a similar number of tokens to the Opus 4.7 default while delivering better performance. You can also choose extra, called `xhigh` in Claude Code, or `max`, for difficult tasks and long running asynchronous workflows.
* The lower prompt cache minimum drops the minimum cacheable prompt length to 1,024 tokens, lower than on Opus 4.7. Prompts that were too short to cache before can now create cache entries with no code changes.
* Refusal stop details, the `stop_details` object that has been available since Opus 4.7, are now publicly documented so your application can tell different classes of declined request apart and route the user to the right next step.

# Claude Opus 4.8 adaptive thinking and behavior changes

With adaptive thinking enabled, Opus 4.8 triggers reasoning only when it judges the turn needs it. On simple lookups and short agentic steps it responds directly, and on complex multi step problems it reasons before answering. This reduces wasted thinking tokens on bimodal workloads compared to Opus 4.7 at the same effort level. As before, thinking is off unless you explicitly set `thinking: {type: "adaptive"}` in your request.

Anthropic calls out three behavior changes that are not API breaking but may warrant prompt updates. There are fewer wasted thinking tokens at the same effort level, better tool triggering with the model less likely to skip a tool call the task required, and better compaction handling so long agentic traces stay on task with fewer derailments after compaction.

A few constraints carry over from Opus 4.7. Sampling parameters such as `temperature`, `top_p`, and `top_k` are not supported and return a 400 error if set to a non default value, and adaptive thinking is the only thinking mode, so extended thinking budgets return a 400 error.

# More than a model bump: what else launched with Claude Opus 4.8

The model is only part of this release. Three platform changes shipped alongside it, and each one removes a constraint developers have had to work around when building agents.

* **Dynamic workflows in Claude Code**, now in research preview. Rather than treating a hard task as one long prompt, Claude can break it into a plan, fan it out across hundreds of parallel subagents in a single session, and check its own work before it hands anything back. Anthropic gives the example of codebase scale migrations across hundreds of thousands of lines of code, with the existing test suite used as the bar for validation. Worth knowing up front that this is token hungry, since the planning, the parallel subagents, and the verification pass all consume tokens, so expect usage well above a single prompt. Dynamic workflows are available on the Enterprise, Team, and Max plans.
* **Effort control** in claude.ai and Cowork, a new setting next to the model selector that hands the speed versus depth tradeoff to the person doing the work. Turn effort up and Claude reasons more before it answers, turn it down and responses come back faster while consuming rate limits more slowly. Claude Code already supported effort through the effort parameter and the `xhigh` setting, so this brings the same control to the chat surfaces. It is live on every plan.
* **System entries in the Messages API**, the most consequential change for anyone writing their own harness. The API now accepts system entries inside the messages array, so you can change Claude's instructions partway through a task without rebuilding the system prompt or faking a user turn to deliver them. Updated permissions, a revised token budget, or fresh environment context can all be pushed in as the agent runs, and the prompt cache on earlier turns stays intact.

# Claude Opus 4.8 pricing

Regular pricing is unchanged from Opus 4.7, so upgrading does not change what your standard calls cost. Fast mode is the only premium tier, priced at double the regular rate in exchange for up to 2.5 times higher output speed. All prices are per million tokens.

| Mode      | Input | Output |
| --------- | ----- | ------ |
| Regular   | $5    | $25    |
| Fast mode | $10   | $50    |

For most workloads the regular tier is the default, since high effort on Opus 4.8 spends a similar number of tokens to the Opus 4.7 default while delivering better results. Fast mode is worth the premium when latency matters more than cost, such as interactive agents and user facing flows where output speed is the bottleneck.

# What this means if you build on Appwrite

Claude Opus 4.8 is built for work that breaks a stateless request and response model. Claude Code's dynamic workflows point toward more complex agentic systems, including tasks that can be planned, delegated across subagents, and verified before completion.

If you want your Claude Code agent to stand up that backend without manually wiring MCP infrastructure, the [Appwrite plugin for Claude Code](/blog/post/announcing-appwrite-claude-code-plugin) bundles the Appwrite API MCP server, the Appwrite Docs MCP server, and SDK specific agent skills into a single install, so the agent can create users, databases, storage, and functions against your Appwrite project on its own. Check out the [Claude Code integration guide](/docs/tooling/ai/ai-dev-tools/claude-code) and the [Appwrite MCP server docs](/docs/tooling/ai/mcp-servers) to get started, or [join us on Discord](https://appwrite.io/discord).

# Build agentic apps on Appwrite

Spin up the backend for your next Claude Opus 4.8 powered agent in minutes. [Start for free on Appwrite Cloud](https://cloud.appwrite.io/), connect the Claude API for the model, and let Appwrite handle Auth, Databases, Storage, Functions, Messaging, and Sites so you can ship the workflow instead of the plumbing.

We post weekly roundups of [product announcements, AI updates, and developer insights](https://dev.to/appwrite/weekly-roundup-product-announcements-ai-updates-and-developer-insights-4m3k), [mirrored here too](https://medium.com/appwrite-io/weekly-roundup-product-announcements-ai-updates-and-developer-insights-ef104b3301fe), so read wherever you prefer. Recent releases have added [MongoDB support, Appwrite 1.90, realtime upgrades, and new AI tooling](https://dev.to/appwrite/april-product-update-mongodb-support-appwrite-190-realtime-upgrades-and-ai-tooling-1eg6), with more landing every few weeks.

# Resources

* [Appwrite MCP server docs](/docs/tooling/ai/mcp-servers)
* [Start building on Appwrite Cloud](https://cloud.appwrite.io/)
* [Appwrite AI products](/docs/products/)
* [Appwrite integrations](/integrations)
* [Join the Appwrite Discord](https://appwrite.io/discord)

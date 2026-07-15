---
layout: post
title: "Claude Sonnet 5 is Anthropic's most agentic Sonnet yet"
description: "Claude Sonnet 5 is Anthropic's most agentic Sonnet model, nearing Opus 4.8 performance at lower prices. See benchmarks, pricing, and how to build on Appwrite."
date: 2026-07-01
cover: /images/blog/claude-sonnet-5-is-anthropics-most-agentic-sonnet-yet/cover.avif
timeToRead: 5
author: aishwari
category: ai
featured: false
faqs:
  - question: When was Claude Sonnet 5 released?
    answer: Anthropic launched Claude Sonnet 5 on June 30, 2026. It is available everywhere from day one, including Claude Code, the Claude Platform, and the Claude API.
  - question: How much does Claude Sonnet 5 cost?
    answer: Claude Sonnet 5 launched with introductory pricing of $2 per million input tokens and $10 per million output tokens through August 31, 2026. After that it moves to standard pricing of $3 per million input tokens and $15 per million output tokens. For reference, Opus 4.8 costs $5 input and $25 output per million tokens.
  - question: What is new in Claude Sonnet 5 compared with Sonnet 4.6?
    answer: Better reasoning, tool use, coding, and knowledge work. It is more persistent on long tasks, checks its own output, and is safer overall.
  - question: Is Claude Sonnet 5 good for coding?
    answer: Yes. It scores 63.2% on SWE-bench Pro and 80.4% on Terminal-Bench 2.1, close to Opus 4.8 and well ahead of Sonnet 4.6.
  - question: Is Claude Sonnet 5 free?
    answer: Yes, in the consumer apps. Claude Sonnet 5 is the default model on the Free and Pro plans, and is also available to Max, Team, and Enterprise users. Developers who call it through the Claude API pay per token at the rates above.
---
Anthropic just [launched Claude Sonnet 5](https://www.anthropic.com/news/claude-sonnet-5), the most agentic Sonnet model it has released. It can make plans, drive tools like browsers and terminals, and run autonomously on work that only a few months ago demanded larger, more expensive models. For anyone building agents, that shift matters. The capability you used to reach for an Opus-class model to get is now available in a Sonnet-class one, at a Sonnet-class price. You call it through the Claude API with the model id `claude-sonnet-5`.

The agentic AI era arguably started with Sonnet-class models. Claude Sonnet 3.5, 3.6, and 3.7 were the first to show serious skill at coding and tool use. Since then, the sharpest gains had been concentrated in Opus-class models. Claude Sonnet 5 narrows that gap: its performance lands close to Opus 4.8 on many tasks, while carrying lower input and output token pricing than Opus 4.8.

# What is Claude Sonnet 5?

Claude Sonnet 5 is Anthropic's newest mid-tier model and the successor to Claude Sonnet 4.6. It's a substantial upgrade on the aspects of agentic performance that developers care about most: reasoning, tool use, coding, and knowledge work. The headline is efficiency. Sonnet 5 gets close to Opus 4.8 quality on a range of tasks while staying priced as a Sonnet, which makes it the natural default for high-volume, long-running agent workloads where cost per run adds up fast.

It's available everywhere from launch. Sonnet 5 is the default model on Free and Pro plans, and is available to Max, Team, and Enterprise users. It also ships in Claude Code and on the Claude Platform, and developers can call it directly via the Claude API.

One implementation detail worth knowing before you migrate: Sonnet 5 uses an updated tokenizer (the same change Anthropic introduced with Claude Opus 4.7). It changes how the model processes text to improve performance, with the tradeoff that the same input can map to more tokens, roughly 1.0 to 1.35 times as many depending on the content type. Anthropic set the introductory pricing so that moving from Sonnet 4.6 to Sonnet 5 is roughly cost-neutral despite this.

# Claude Sonnet 5 benchmarks

Anthropic published a benchmark table comparing Sonnet 5 against its predecessor Sonnet 4.6 and against Opus 4.8, which sits above it as a more generally capable reference model. Across the board, Sonnet 5 improves on Sonnet 4.6 and closes much of the distance to Opus 4.8.

| Benchmark                                                      | Claude Sonnet 5 | Claude Sonnet 4.6 | Claude Opus 4.8 (for reference) |
| -------------------------------------------------------------- | --------------- | ----------------- | ------------------------------- |
| Agentic coding (SWE-bench Pro)                                 | 63.2%           | 58.1%             | **69.2%**                       |
| Agentic coding (Terminal-Bench 2.1)                            | 80.4%           | 67.0%             | **82.7%**                       |
| Multidisciplinary reasoning (Humanity's Last Exam, no tools)   | 43.2%           | 34.6%             | **49.8%**                       |
| Multidisciplinary reasoning (Humanity's Last Exam, with tools) | 57.4%           | 46.8%             | **57.9%**                       |
| Computer use (OSWorld-Verified)                                | 81.2%           | 78.5%             | **83.4%**                       |
| Knowledge work (GDPval-AA v2)                                  | **1618**        | 1395              | 1615                            |

*Scores for Sonnet 5 on a range of evaluations compared with Sonnet 4.6 and Opus 4.8 (a more generally capable model, shown for reference). The* [Claude Sonnet 5 System Card](https://www.anthropic.com/news/claude-sonnet-5) *reports a broader set of evaluations in detail.*

**The pattern**

* Sonnet 5 beats Sonnet 4.6 on every benchmark, often by a wide margin, and lands within a few points of Opus 4.8 on most of them.
* Terminal-Bench 2.1: 80.4% vs Opus 4.8's 82.7%.
* OSWorld-Verified: 81.2% vs 83.4%.
* Knowledge work (GDPval-AA v2): edges past Opus 4.8, 1618 to 1615.
* Clearest remaining gap: the harder SWE-bench Pro coding tasks, where Opus 4.8 still leads.

**A note on shifting reference numbers**

* A couple of these reference numbers moved recently for methodology reasons, so it's worth being precise.
* Anthropic updated the grader model for Humanity's Last Exam, so Sonnet 4.6 now sits at 34.6% (no tools) and 46.8% (with tools) rather than its original launch figures.
* Changes to how OSWorld-Verified is run, meant to better reflect real-world performance, moved Sonnet 4.6's score to 78.5%.
* If you compare against older blog posts, expect small discrepancies for exactly this reason.

# How Claude Sonnet 5 compares to Opus 4.8

The most useful way to think about Sonnet 5 is not as a cheaper Sonnet upgrade, but as part of Anthropic's newer 5-series generation, alongside models like Fable 5. It is more capable than Sonnet 4.6 and has lower per-token pricing than Opus 4.8, but that does not automatically make every run cheaper.

Anthropic lets developers adjust the effort level, from lower settings for faster runs up to max mode for harder tasks that need deeper reasoning. The important caveat is that Sonnet 5 tends to spend far more tokens at higher reasoning levels, so lower per-token pricing does not always mean lower task cost. In Artificial Analysis' benchmark, Sonnet 5 at max effort cost more overall than Fable 5 and Opus 4.8, officially being the most expensive model on their benchmark. That makes the tradeoff less about "cheap Sonnet vs expensive Opus" and more about choosing the right effort level for the workload.

The practical takeaway is to treat cost as a mix of model price, total tokens used, and effort level:

* Use lower or medium effort for high-volume tasks where speed and cost control matter.
* Use max mode only when the task genuinely needs stronger reasoning or longer autonomous work.
* Do not assume Sonnet 5 is cheaper overall just because its per-token pricing is lower than Opus 4.8.

# What Claude Sonnet 5 is good at

Anthropic's early-access partners were consistent in their feedback: Sonnet 5 is much more agentic than its predecessors. The concrete behaviors they highlighted map neatly onto what an agent actually needs to do.

* **Finishing long tasks.** Testers described Sonnet 5 completing complex, multi-step tasks where previous Sonnet models would stop short. That persistence across a long chain of steps is exactly what separates a usable agent from one that needs constant hand-holding.
* **Checking its own work.** Partners noted that Sonnet 5 verifies its own output without being explicitly told to. Self-checking is a small behavior with an outsized effect on reliability, because it catches errors before they compound down the chain.
* **Tool use and reasoning.** The gains over Sonnet 4.6 in tool use, reasoning, and coding are what let it operate browsers and terminals autonomously and recover when a step doesn't go as planned.

# Claude Sonnet 5's safety and safeguards

More autonomy also raises the bar for safety. Since Sonnet 5 is designed for tool-heavy agentic work, Anthropic evaluated how well it handles malicious requests, prompt injection, hallucination, sycophancy, and cyber misuse.

* Anthropic's pre-deployment evaluations found Sonnet 5 to be an overall improvement on Sonnet 4.6 for safety. It's better at refusing malicious requests and at resisting hijack attempts in prompt injection attacks, a meaningful property for agents that read untrusted web content or tool output. It also shows lower rates of hallucination and sycophancy than Sonnet 4.6.
* On Anthropic's automated behavioral audit, which tests for a wide range of misaligned behaviors such as cooperation with misuse and deception, Sonnet 5 scored lower (that is, safer) overall than Sonnet 4.6. It did show somewhat higher rates of misaligned behavior than the more capable Opus 4.8 and Claude Mythos Preview, which is the expected pattern for a smaller model.
* On cybersecurity, the key point is that Sonnet 5 simply isn't that capable of causing cyber harm. Anthropic did not deliberately train it on cyber tasks. It can handle routine, non-harmful cyber work but performs substantially worse than Opus 4.8 and Mythos 5 on potentially dangerous skills such as developing software exploits. On an evaluation built with Mozilla that tested exploit development against Firefox vulnerabilities (since patched), Sonnet 5 never produced a full working exploit. It showed a slightly higher partial-success rate than Sonnet 4.6, a shift Anthropic attributes to general intelligence gains rather than any cyber-specific training.
* Because Sonnet 5 is somewhat stronger than its predecessor here, it launches with cyber safeguards enabled by default. These are the same real-time detection-and-blocking safeguards present in Claude Opus 4.7 and 4.8, and they're deliberately less strict than the safeguards shipped with Fable 5, reflecting Anthropic's judgment that Sonnet 5's overall cyber risk is low. Sonnet 5 is also part of Anthropic's Cyber Verification Program.

# Claude Sonnet 5 pricing

Claude Sonnet 5 launches with introductory pricing, per million tokens:

* **Input:** $2
* **Output:** $10

That introductory rate runs through **August 31, 2026**, after which it moves to standard pricing of **$3 per million input tokens and $15 per million output tokens**. For comparison, Opus 4.8 is priced at $5 input and $25 output per million tokens.

Keep the tokenizer change in mind when you model your bill: because the same input can map to more tokens under Sonnet 5's tokenizer, the introductory pricing is calibrated to keep the transition from Sonnet 4.6 roughly cost-neutral. Anthropic also raised rate limits across Chat, Cowork, Claude Code, and the Claude Platform to accommodate the higher token usage that comes with higher effort levels.

# Claude Sonnet 5 availability

Sonnet 5 is available everywhere today. It's the default model on Free and Pro plans and is available to Max, Team, and Enterprise users. Developers can reach it in Claude Code and on the Claude Platform, and call it directly through the Claude API using the model id `claude-sonnet-5`. There's no staged rollout to wait through, so you can start building on it now.

# What this means if you build on Appwrite

Sonnet 5's biggest strength is long-horizon, autonomous work at a price that makes running it at scale realistic: agents that plan, use tools, check their own output, and keep going across many steps. An agent doing that kind of work needs somewhere to authenticate users, store state, persist files between steps, and run server-side logic. In other words, it needs a backend, and wiring one up by hand is usually the slow part of shipping an agentic app.

If you want your Sonnet 5 powered agent to stand up that backend without manually assembling infrastructure, the [Appwrite plugin for Claude Code](https://appwrite.io/docs/tooling/claude-code) bundles the [Appwrite API MCP server](https://appwrite.io/docs/tooling/mcp), the Appwrite Docs MCP server, and SDK-specific agent skills into a single install. With the right project access and permissions, an agent can work directly with Appwrite APIs and docs to set up [Auth](https://appwrite.io/docs/products/auth), [Databases](https://appwrite.io/docs/products/databases), [Storage](https://appwrite.io/docs/products/storage), [Sites](https://appwrite.io/docs/products/sites), and [Functions](https://appwrite.io/docs/products/functions).

Because Sonnet 5 lets you tune effort against cost, it pairs especially well with high-volume agent workloads: run at medium effort for the bulk of routine steps, and dial up only when a task genuinely needs it.

# Build agentic apps on Appwrite

Spin up the backend your next app needs in minutes. Start for free on Appwrite Cloud, connect the Claude API with the model id `claude-sonnet-5`, and let Appwrite handle Auth, Databases, Storage, Functions, Messaging, and Sites. Your Sonnet 5 agent builds the app, Appwrite runs the backend behind it, and you ship the product instead of wiring up infrastructure.

Recent releases have added [MongoDB support, Appwrite 1.9.0, realtime upgrades, and new AI tooling](https://dev.to/appwrite/april-product-update-mongodb-support-appwrite-190-realtime-upgrades-and-ai-tooling-1eg6), with [more landing every few weeks](https://dev.to/appwrite/may-product-update-presences-api-rust-runtime-7x-faster-storage-uploads-and-more-9h5). We post weekly roundups of product announcements, AI updates, and [developer insights](https://dev.to/appwrite/weekly-roundup-presences-api-git-deployment-triggers-and-ai-updates-58lj) on the [Appwrite blog](https://appwrite.io/blog) and across our developer channels, so follow along wherever you read.

# Resources

* [Appwrite MCP server docs](/docs/tooling/ai/mcp-servers/)
* [Start building on Appwrite Cloud](https://cloud.appwrite.io/)
* [Appwrite AI tooling](/docs/tooling/ai)
* [Appwrite integrations](/integrations)
* [Join the Appwrite Discord](https://appwrite.io/discord)

---
layout: post
title: Claude Opus 5 nears Fable 5 intelligence at half the price
description: Claude Opus 5 lands with near Fable 5 intelligence at half the price, state-of-the-art agentic coding, and less restrictive cyber classifiers.
date: 2026-07-27
cover: /images/blog/claude-opus-5-nears-fable-5-intelligence-at-half-the-price/cover.avif
timeToRead: 5
author: aishwari
category: ai
featured: false
faqs:
  - question: What is Claude Opus 5?
    answer: "Claude Opus 5 is Anthropic's latest Opus-class model and the successor to Claude Opus 4.8. It is designed for everyday coding, knowledge work, computer use, and agentic tasks."
  - question: How much does Claude Opus 5 cost?
    answer: Claude Opus 5 costs $5 per million input tokens and $25 per million output tokens. This is the same price as Opus 4.8 and half the per-token price of Claude Fable 5.
  - question: How does Claude Opus 5 compare to Claude Fable 5?
    answer: Claude Opus 5 approaches Fable 5 performance across several coding and knowledge work benchmarks while costing half as much per token. Fable 5 remains better suited to some highly complex, cybersecurity, and biology workloads.
  - question: Does Claude Opus 5 support Fast mode?
    answer: Yes. Claude Opus 5 offers a Fast mode that runs around 2.5 times faster than the default mode and is priced at twice the base rate.
---
Claude Opus 5 is Anthropic's new model for everyday work, landing close to the frontier intelligence of Claude Fable 5 while costing half as much per token. Anthropic [announced Claude Opus 5](https://www.anthropic.com/news/claude-opus-5) on July 24, 2026, positioning it as a thoughtful, proactive model built to be used every day rather than reached for only on the hardest problems. You call it through the Claude API with the model id `claude-opus-5`.

The headline is the price-to-intelligence ratio. On coding and knowledge work evaluations, Opus 5 is state-of-the-art, yet it is priced the same as its predecessor Opus 4.8 and half the per-token price of Fable 5. Here is what shipped, how it compares, and what it means if you build agents on Appwrite.

# What is Claude Opus 5?

Claude Opus 5 is Anthropic's newest Opus-class model and the successor to [Claude Opus 4.8](/blog/post/anthropic-just-launched-claude-opus-48-with-fast-mode-and-dynamic-workflows). Anthropic describes it as a model designed for daily use: proactive, thorough, and efficient enough to run across routine work without the cost tradeoffs that usually come with frontier-class quality.

It is the new default model on Claude Max and the strongest model available on Claude Pro. On coding and knowledge work benchmarks like Frontier-Bench and GDPval-AA, Opus 5 is the new state-of-the-art, though it remains behind [Claude Mythos 5](/blog/post/anthropic-just-launched-claude-fable-5-and-claude-mythos-5) on cybersecurity tasks. It ships with the same effort control as recent Claude models, so you can dial reasoning up for intelligence or down to conserve tokens for faster, cheaper results.

# Claude Opus 5 benchmarks: near Fable 5 intelligence, half the price

Claude Opus 5 provides greatly improved performance at the same cost as Opus 4.8. Anthropic reports results across effort settings so you can see where the intelligence-versus-cost curve lands for your workload.

* **Frontier-Bench v0.1.** Opus 5 surpasses every other model and more than doubles Opus 4.8's performance, at a lower cost per task.
* **CursorBench 3.2.** At max effort, Opus 5 performs within 0.5% of Fable 5's peak score, but at half the cost per task. It also delivers more performance per dollar than any other model at high, xhigh, and max effort.
* **ARC-AGI 3.** On this test of novel problem solving, Opus 5's score is three times as high as the next-best model. This is [the ARC Prize benchmark](https://arcprize.org/) designed to resist memorization.
* **Zapier AutomationBench.** Opus 5's pass rate is around 1.5 times the next-best model for the same cost per task. Even at its lowest effort setting, it passes more tasks than any other model.
* **OSWorld 2.0.** On [this computer use benchmark](https://os-world.github.io/), Opus 5 outperforms every other model at any given cost, surpassing Fable 5's best result at just over a third of the cost.

Anthropic also calls Opus 5 its best and most cost-efficient model on several related evaluations, including GDPval-AA v2, Humanity's Last Exam, AutomationBench, and DeepSearchQA. The pattern is consistent: Opus 5 gets close to Fable 5 quality while spending far fewer tokens and dollars to get there.

# How Claude Opus 5 compares to Claude Fable 5

The most useful way to think about Opus 5 is as the daily-driver counterpart to Fable 5. Fable 5 remains Anthropic's most capable generally available model, but it costs $10 per million input tokens and $50 per million output tokens. Opus 5 lands close on most coding and knowledge work benchmarks at half that price.

The practical guidance:

* Reach for **Opus 5** as your default for agentic coding, knowledge work, and computer use, where it is state-of-the-art and cheaper to run at scale.
* Reach for **Fable 5** when you need its edge on the longest and most complex tasks. For offensive cybersecurity and long-running autonomous biology research, Anthropic says Mythos 5 remains stronger.
* Tune the **effort setting** rather than assuming a fixed cost. Higher effort spends more tokens, so match it to how hard the task actually is.

This mirrors the tradeoff we covered for [Claude Sonnet 5](/blog/post/claude-sonnet-5-is-anthropics-most-agentic-sonnet-yet): per-token price is only one input to total task cost. Effort level and total tokens used matter just as much.

# What Claude Opus 5 is good at

The clearest improvement in Opus 5 is agency. It verifies its own work and iterates carefully until it succeeds, rather than declaring a task done at the first plausible answer. Anthropic and its early-access customers surfaced several concrete examples:

* **Building its own tooling to finish a task.** On one Frontier-Bench task, Opus 5 was given a drawing of a machine part and asked to rebuild it as a 3D FreeCAD model, but with no way to directly view the drawing. It wrote its own computer vision pipeline to pull the geometry from raw pixels, then reconstructed the full part. It succeeded repeatedly, where no competing model with the same setup could solve it in five attempts.
* **Finding the root cause, not the symptom.** Given a real bug in a popular open-source package manager, Opus 5 found the root cause and fixed an edge case the community's own patch had missed. A competing model fixed only the surface symptom and reported the bug resolved.
* **Validating without a reference.** An engineer at a trading firm used Opus 5 to build a market data feed for a new exchange in a single session, a task previous models could not complete even with extensive plans. With no live feed to validate against, Opus 5 built its own test harness to confirm its code parsed the exchange's data correctly.

For agent builders, this self-verifying behavior is the property that matters most. An agent that checks its own output catches errors before they compound across a long chain of steps.

# Claude Opus 5 for scientific research

Opus 5 is a meaningful step up from Opus 4.8 for science. It improves on every one of Anthropic's life sciences evaluations, which span structural biology, organic chemistry, and bioinformatics.

The gains are largest in chemistry and protein work. On Anthropic's internal benchmark, Opus 5 scores 10.2 percentage points higher than Opus 4.8 at inferring molecular structures from spectroscopy data, and 7.7 percentage points higher at predicting how variations in a protein's sequence affect its function. Because its safeguards are similar to Opus 4.8, Anthropic now considers Opus 5 its most capable generally available model for scientific research.

# Claude Opus 5 alignment and safety

Anthropic reports that Opus 5 is its most aligned model to date. On its automated behavioral audit, Opus 5 scored 2.3 on overall misaligned behavior, the lowest of any recent Claude model, and lower than Opus 4.8, Sonnet 5, and Fable 5.

* It adheres to Claude's Constitution better than those models.
* It shows the lowest rates of deceptive behavior.
* It is the least susceptible to being tricked into misuse.
* It is Anthropic's safest model yet at avoiding reckless actions with hard-to-reverse side effects.

On dual-use safety, Opus 5 does not advance the frontier. Anthropic deliberately avoided training it on cyber tasks, and in evaluations run with private-sector and government partners it remains behind Mythos 5 in both biology research and offensive cybersecurity. Opus 5 comes close to Mythos 5 at finding cybersecurity vulnerabilities, but stays substantially behind at turning those vulnerabilities into working exploits. On Anthropic's OSS-Fuzz evaluation, the two models identify vulnerabilities with similar success, yet Opus 5's exploit-development score is far below Mythos 5's. Full detail is in the [Claude Opus 5 System Card](https://www-cdn.anthropic.com/c5fbac3f0b1280a933ebd26d3cb8bb9f5bdeaf48/Claude%20Opus%205%20System%20Card.pdf).

# Claude Opus 5 safeguards and cyber fallbacks

Opus 5's safeguards are close to those on Opus 4.8, with stronger guardrails on a narrow range of cyber tasks. This is the part of the launch that changes how you design agents that touch security-adjacent work.

* The cyber classifiers are proportionally **less restrictive than Fable 5's**. They allow Opus 5 to find vulnerabilities in source code, but block binary-based vulnerability scanning, penetration testing, and exploit generation.
* Anthropic expects these classifiers to intervene around **85% less often** than they do on Fable 5.
* In Claude.ai, Claude Code, and Claude Cowork, any flagged request **falls back to Opus 4.8** by default. Fallbacks can also be enabled on the API.
* Anthropic's **Cyber Verification Program** gives approved enterprises and researchers access to a version of Opus 5 with fewer restrictions.

On biology, because Opus 5 carries a similar suite of safeguards to Opus 4.8, biology-related requests that are blocked on Fable 5 now route to Opus 5 rather than Opus 4.8. The model still has real limits on long-running autonomous research, where Mythos 5 remains stronger.

# How much does Claude Opus 5 cost?

Claude Opus 5 is priced at **$5 per million input tokens and $25 per million output tokens**, the same as Opus 4.8 and half the per-token price of Fable 5.

| Model           | Input (per 1M tokens) | Output (per 1M tokens) |
| --------------- | --------------------- | ---------------------- |
| Claude Opus 5   | $5                    | $25                    |
| Claude Opus 4.8 | $5                    | $25                    |
| Claude Fable 5  | $10                   | $50                    |

Opus 5 is also offered in Fast mode, where it runs around 2.5 times the default speed. As with Opus 4.8, Fast mode is priced at twice the base rate on the Claude Platform and through usage credits in Claude Code. Consistent with prior Opus models, Opus 5 has no data retention requirements for general access.

# What else shipped with Claude Opus 5

Alongside the model, Anthropic released two updates in beta that matter for anyone writing their own agent harness:

* **Mid-conversation tool changes on the Claude Platform.** You can now change which tools Claude can use partway through a conversation without invalidating the prompt cache. That keeps cache hits intact on earlier turns as an agent's available tools evolve.
* **Automatic fallbacks on the API.** Users can choose to have requests flagged by the safety classifiers on Opus 5 or Fable 5 automatically route to another model. With automatic fallbacks enabled, API requests route to the best available model instead of being blocked.

For tuning your prompts to the new model, Anthropic published a [prompting guide for Opus 5](https://platform.claude.com/docs/en/build-with-claude/prompt-engineering/prompting-claude-opus-5).

# What Claude Opus 5 means if you build on Appwrite

Opus 5's strengths are long-horizon, self-verifying agentic work at a price that makes running it at scale realistic: agents that plan, drive tools, check their own output, and keep going across many steps. An agent doing that work needs somewhere to authenticate users, store state, persist files between steps, and run server-side logic. In other words, it needs a backend, and wiring one up by hand is usually the slow part of shipping an agentic app.

If you want your Opus 5 powered agent to stand up that backend without manually assembling infrastructure, the [Appwrite plugin for Claude Code](/blog/post/announcing-appwrite-claude-code-plugin) bundles the Appwrite API MCP server, the Appwrite Docs MCP server, and SDK-specific agent skills into a single install. With the right project access and permissions, an agent can work directly with Appwrite APIs and docs to set up [Auth](/docs/products/auth), [Databases](/docs/products/databases), [Storage](/docs/products/storage), [Functions](/docs/products/functions), and [Sites](/docs/products/sites). See the [Claude Code integration guide](/docs/tooling/ai/ai-dev-tools/claude-code) and the [Appwrite MCP server docs](/docs/tooling/ai/mcp-servers) to get started.

Because Opus 5 lets you tune effort against cost, it pairs well with high-volume agent workloads: run at lower effort for routine steps, and dial up only when a task genuinely needs deeper reasoning.

# Build agentic apps on Appwrite

Spin up the backend your next app needs in minutes. Start for free on Appwrite Cloud, connect the Claude API with the model id `claude-opus-5`, and let Appwrite handle Auth, Databases, Storage, Functions, Messaging, and Sites. Your Opus 5 agent builds the app, Appwrite runs the backend behind it, and you ship the product instead of wiring up infrastructure.

# Resources

* [Appwrite MCP server docs](/docs/tooling/ai/mcp-servers)
* [Start building on Appwrite Cloud](https://cloud.appwrite.io/)
* [Appwrite AI products](/docs/products/)
* [Appwrite integrations](/integrations)
* [Join the Appwrite Discord](https://appwrite.io/discord)
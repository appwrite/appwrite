---
layout: post
title: OpenAI cuts GPT-5.6 pricing and adds Fast mode to the API
description: GPT-5.6 Luna is now 80 percent cheaper and Terra 20 percent cheaper from July 30. Here is the new API pricing plus what Fast mode replaces for Sol users.
date: 2026-07-31
cover: /images/blog/openai-cuts-gpt-56-pricing-and-adds-fast-mode-to-the-api/cover.avif
timeToRead: 5
author: aishwari
category: ai
featured: false
faqs:
  - question: What changed in GPT-5.6 pricing?
    answer: On July 30, 2026, OpenAI reduced GPT-5.6 Luna pricing by 80% and GPT-5.6 Terra pricing by 20%, while GPT-5.6 Sol pricing remained unchanged. OpenAI also introduced Fast mode for Sol, replacing Priority Processing.
  - question: How much does GPT-5.6 Luna cost?
    answer: "GPT-5.6 Luna now costs $0.20 per million input tokens and $1.20 per million output tokens, making it OpenAI's most affordable GPT-5.6 model."
  - question: How much does GPT-5.6 Terra cost?
    answer: "GPT-5.6 Terra costs $2 per million input tokens and $12 per million output tokens after OpenAI's 20% price reduction."
  - question: What is Fast mode in the OpenAI API?
    answer: Fast mode is a new processing option for GPT-5.6 Sol that delivers responses up to 2.5 times faster than Standard mode at twice the price. It replaces Priority Processing and is backward compatible.
  - question: Should I use GPT-5.6 Luna, Terra, or Sol?
    answer: Use Luna for high-volume, cost-sensitive workloads, Terra for balanced quality and cost, and Sol for the most demanding reasoning tasks where maximum intelligence matters.
---
OpenAI cut GPT-5.6 pricing on July 30, 2026, making Luna 80% cheaper and Terra 20% cheaper, and replaced Priority Processing in the API with a new Fast mode for Sol. The short version: high-volume work on Luna and Terra now costs far less per token, and latency-sensitive Sol requests can run up to 2.5 times faster. OpenAI laid out the changes in its [price-performance announcement](https://openai.com/index/advancing-the-price-performance-frontier-with-gpt-5-6/).

This post covers exactly what changed in GPT-5.6 pricing, the new per-token rates, how Fast mode works, and what the lower prices mean if you build agents. If you want a refresher on the model family first, see our [GPT-5.6 launch breakdown](/blog/post/gpt-56-is-here-openais-efficient-frontier-model).

# What changed in GPT-5.6 pricing on July 30?

OpenAI lowered the API price of its two cheaper tiers and left the flagship untouched:

* **GPT-5.6 Luna**, the fastest and most affordable model, costs **80% less**.
* **GPT-5.6 Terra**, the balanced everyday model, costs **20% less**.
* **GPT-5.6 Sol**, the flagship, keeps its existing pricing.

The lower Luna and Terra prices also flow through to subscriptions. In Codex and ChatGPT Work, Terra and Luna now consume fewer credits against your quota, so the same plan stretches further. Subscription prices and quota budgets did not change.

Here is how the new API rates compare to the [launch pricing](/blog/post/gpt-56-is-here-openais-efficient-frontier-model) from July 9.

| Model         | Old input / output (per 1M tokens) | New input / output (per 1M tokens) | Change      |
| ------------- | ---------------------------------- | ---------------------------------- | ----------- |
| GPT-5.6 Sol   | $5 / $30                           | $5 / $30                           | No change   |
| GPT-5.6 Terra | $2.50 / $15                        | $2 / $12                           | 20% cheaper |
| GPT-5.6 Luna  | $1 / $6                            | $0.20 / $1.20                      | 80% cheaper |

Pricing takes effect July 30, 2026, with changes rolling out on AWS shortly after. Full details are on OpenAI's [API pricing page](https://platform.openai.com/docs/pricing).

# GPT-5.6 Luna is now 80% cheaper

Luna is where the cut is most dramatic. At $0.20 per million input tokens and $1.20 per million output tokens, it is one of the cheapest capable models on the market.

The point of the price drop is scale. Luna can use tools and complete multi-step workflows, so a whole class of AI features that were too expensive to run at volume become practical. OpenAI frames Luna as delivering performance comparable to models that were frontier-class a year ago, at roughly **6 cents on the dollar per task** and nearly **nine times the speed**.

On [Agents' Last Exam](https://openai.com/index/gpt-5-6/), a benchmark for professional agentic work, OpenAI reports that Luna outperforms Fable 5 at an estimated cost per task nearly **99% lower**. According to [Artificial Analysis](https://artificialanalysis.ai/), Luna also sits well ahead of similarly priced models on its Intelligence Index. Treat vendor-reported numbers as directional, but the trend is clear: Luna moves the price-performance frontier down and to the right.

# GPT-5.6 Terra pricing drops 20%

Terra is the balanced tier for everyday work, and its new rate is $2 per million input tokens and $12 per million output tokens. The 20% cut is smaller than Luna's, but Terra sits at a higher quality point, so it is the tier most teams will reach for when Luna is not quite enough and Sol is more than the task needs.

Because Terra also consumes fewer credits in Codex and ChatGPT Work, everyday coding and knowledge work on a paid plan now goes further without any change to your subscription.

# What is Fast mode in the OpenAI API?

Fast mode is a new API option that trades money for latency without changing model quality. It **replaces Priority Processing** and aligns with the `/fast` command in Codex.

For **GPT-5.6 Sol**, Fast mode delivers:

* Up to **2.5 times faster** responses than Standard processing.
* **Twice the price** of Standard processing.
* **No change in intelligence**, only speed.

Fast mode is backward compatible. Any existing API request tagged `priority` continues to work and automatically routes to Fast mode, so you do not need to rewrite integrations built against Priority Processing.

This mirrors the [Fast mode Anthropic shipped with Claude Opus 4.8](/blog/post/anthropic-just-launched-claude-opus-48-with-fast-mode-and-dynamic-workflows), which also runs roughly 2.5 times faster at twice the base rate. The pattern is becoming standard: pay a premium only on the requests where response time is the constraint, and run everything else at Standard speed.

**When should you use Fast mode?** Reach for it on interactive, latency-sensitive Sol workloads where a user is waiting on the response, such as live coding assistance or real-time agent steps. Leave batch jobs, background processing, and non-urgent work on Standard to avoid paying the 2x premium for speed you do not need.

# How OpenAI made GPT-5.6 cheaper to run

The price cuts are downstream of efficiency gains, not a margin decision. OpenAI attributes the improvement to making every layer more efficient: the models themselves, the inference systems that serve them, and the agentic harness that connects them to tools and context. Better routing keeps hardware busy, optimized serving software generates tokens more cheaply, and smarter context management stops agents from repeating completed work.

Notably, GPT-5.6 Sol is now helping optimize itself. Within a human-led process, Sol autonomously rewrote and optimized production kernels and ran hundreds of experiments to improve token generation. The results:

* Kernel work reduced the end-to-end cost of serving the model by about **20%**.
* Experiments increased token-generation efficiency by more than **15%**.

That creates a feedback loop. As the models get better at working autonomously, OpenAI's ability to find the next round of savings speeds up, and those savings show up as lower prices for customers.

# Where you can use GPT-5.6 Terra and Luna

The pricing changes apply across OpenAI's developer and work surfaces. Access to Terra and Luna depends on your plan.

| Surface                | Free / Go      | Plus, Pro, Business, Enterprise |
| ---------------------- | -------------- | ------------------------------- |
| ChatGPT Work and Codex | Terra          | Terra and Luna                  |
| OpenAI API             | Terra and Luna | Terra and Luna                  |

Sol pricing and availability are unchanged. Fast mode for Sol is available in the API and via `/fast` in Codex.

# What lower GPT-5.6 pricing means for developers

The real value of a cheaper family is not one model getting cheaper. It is having a wider range of price-performance points to match against each step of a workflow. Using AI efficiently starts with the outcome: the stakes, the cost of an error, urgency, and scale decide the right balance of intelligence, speed, and cost, and that balance can shift from one step to the next.

A practical way to think about the three tiers:

| Model         | Best for                                        | Trade-off                              |
| ------------- | ----------------------------------------------- | -------------------------------------- |
| GPT-5.6 Sol   | Hard reasoning, planning, resolving uncertainty | Highest cost per token                 |
| GPT-5.6 Terra | Balanced everyday coding and knowledge work     | Middle ground on cost and capability   |
| GPT-5.6 Luna  | High-volume, well-specified work at scale       | Not the tier for the hardest ambiguity |

A coding agent, for example, might use Sol to resolve uncertainty and write the plan, then hand well-specified changes to Luna to implement, test, and evaluate. Define the quality bar you need, then use evaluations to find where extra intelligence actually improves the result and where a faster, cheaper tier delivers the same outcome. The concrete advice: do not default every call to the flagship, and do not assume the cheapest tier is always right either.

# Build high-volume GPT-5.6 agents on Appwrite

Cheaper Luna and Terra pricing makes it realistic to run agentic features at scale. But an agent that authenticates users, stores state, persists files between steps, and runs server-side logic still needs a backend, and wiring one up by hand is usually where the time and cost you just saved on tokens quietly disappears.

[Appwrite](/) closes that gap. It is an open source backend as a service with [Auth](/docs/products/auth), [Databases](/docs/products/databases), [Storage](/docs/products/storage), [Functions](/docs/products/functions), and [Messaging](/docs/products/messaging) built in, plus [Sites](/docs/products/sites) to deploy your frontend alongside it. Run it on managed Cloud or self-host it.

If you are building with Codex, the [Appwrite plugin for Codex](/blog/post/announcing-appwrite-codex-plugin) makes the handoff clean. It ships agent skills for the Appwrite CLI and major SDKs and registers the Appwrite Docs MCP server, so your GPT-5.6 agent writes real SDK calls against a backend that already exists instead of guessing at one.

`codex plugin marketplace add appwrite/codex-plugin`

[Create a free Appwrite project](https://cloud.appwrite.io/), add the plugin, and point a GPT-5.6 agent at it. Now the token savings from the new pricing translate into shipped features, not a weekend of backend plumbing.

# Resources

* [GPT-5.6 launch breakdown](/blog/post/gpt-56-is-here-openais-efficient-frontier-model)
* [Appwrite plugin for Codex](/blog/post/announcing-appwrite-codex-plugin)
* [Create a free Appwrite project](https://cloud.appwrite.io/)
* [Appwrite products](/docs/products/)
* [Appwrite integrations](/integrations)
* [Join the Appwrite Discord](/discord)
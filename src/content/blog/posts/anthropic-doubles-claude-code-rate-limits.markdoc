---
layout: post
title: "Anthropic just doubled Claude Code's 5-hour rate limits"
description: "Anthropic doubled Claude Code's 5-hour rate limits across paid plans, dropped peak-hour throttling for Pro and Max, and lifted API rate limits for Opus. The compute behind it comes from a new SpaceX deal."
date: 2026-05-07
cover: /images/blog/anthropic-doubles-claude-code-rate-limits/cover.avif
timeToRead: 6
author: atharva
category: ai
faqs:
  - question: "What changed for Claude Code users on May 6, 2026?"
    answer: "Anthropic doubled the 5-hour rate limits on Claude Code for Pro, Max, Team, and Enterprise plans. They also removed the peak-hour reduction that previously throttled Pro and Max accounts during high-traffic windows, and they lifted API rate limits for Claude Opus models. Weekly limits were left unchanged in this announcement."
  - question: "Which Claude plans got the doubled 5-hour rate limits?"
    answer: "Pro, Max, Team, and Enterprise plans. The peak-hour limit reduction was specifically removed for Pro and Max, which means the same plan now delivers consistent capacity throughout the day instead of being throttled during busy windows."
  - question: "Did the weekly rate limits change?"
    answer: "No. Anthropic was explicit that only the 5-hour limits were doubled. Weekly limits stayed the same in this announcement. If your usage pattern hits the weekly cap rather than the 5-hour cap, the practical change for you is smaller."
  - question: "How much did the Claude API rate limits go up for Opus?"
    answer: "Tier 1 saw a 1500% increase in maximum input tokens per minute and a 900% increase in maximum output tokens per minute for Claude Opus models. Higher tiers also received increases. This applies to direct API usage, separate from the Claude Code plan limits."
  - question: "What is the SpaceX deal that made this possible?"
    answer: "On May 6, 2026, Anthropic announced an agreement to use the entire compute capacity of SpaceX's Colossus 1 data center in Memphis, Tennessee. That is over 300 megawatts and more than 220,000 NVIDIA GPUs, coming online within the month. Anthropic and SpaceX also expressed mutual interest in developing multiple gigawatts of orbital compute capacity over time."
  - question: "Why is Anthropic raising limits now?"
    answer: "Demand for Claude Code has pushed against capacity for months. Pro and Max users have been hitting peak-hour throttling, weekly caps, and 5-hour caps in the same week. The SpaceX deal, alongside earlier agreements with Amazon, Google, Microsoft, NVIDIA, and Fluidstack, gives Anthropic enough additional compute to ease those constraints rather than continue gating around them."
---

On May 6, 2026, Anthropic [announced](https://www.anthropic.com/news/higher-limits-spacex) two changes that land in the same press release but matter to different audiences:

- **A compute deal with SpaceX.** Anthropic gets the entire capacity of SpaceX's Colossus 1 data center in Memphis: more than 300 megawatts and over 220,000 NVIDIA GPUs, coming online within the month.
- **What that compute pays for.** Doubled 5-hour rate limits on Claude Code for paid plans, peak-hour throttling removed for Pro and Max, and substantially higher API rate limits for Claude Opus.

If you have been bouncing off the 5-hour cap in the middle of an agentic run, the second part of that announcement is the one that changes your day. The first part is the reason you should expect more announcements like it.

# What actually changed for Claude Code

Three things shipped on the product side, and the boundaries between them matter.

| | Before | After |
|---|---|---|
| **5-hour limits (Pro, Max, Team, Enterprise)** | Standard | Doubled |
| **Peak-hour reduction (Pro, Max)** | Tightened limits during peak windows | Removed |
| **Weekly limits** | Standard | Unchanged |
| **Opus API Tier 1 input tokens per minute** | Standard | 1500% increase |
| **Opus API Tier 1 output tokens per minute** | Standard | 900% increase |

A few practical reads:

- **Doubled 5-hour limits help long agentic runs the most.** If your workflow is "open Claude Code, hand it a feature, walk away for an hour," the 5-hour ceiling was the cap that hit you first. Doubling it pushes that ceiling out far enough that most single-feature runs do not bump into it at all.
- **Removing peak-hour throttling makes Pro and Max plans behave consistently.** Before this, the same subscription delivered different performance at 11am versus 11pm. That is gone for Pro and Max.
- **Weekly limits did not move.** If you are a heavy user who hits the weekly cap before the 5-hour cap, the practical change for you is smaller. Anthropic is explicit about this distinction.
- **The Opus API increase is the biggest number in the announcement.** A 1500% bump in input tokens per minute counts as a capacity unlock for anyone running Opus at scale outside the chat product, well past the range of a quality-of-life adjustment.

# What is Colossus 1, and why does it matter

Colossus 1 is the SpaceX-operated data center in Memphis, Tennessee. Until this week it was **associated with xAI**, the AI company Elon Musk founded as an alternative to OpenAI. Anthropic now has access to the whole facility: **300 megawatts of capacity and 220,000-plus NVIDIA GPUs**, with the capacity **coming online inside a month** rather than the multi-year horizons attached to most cloud-scale compute deals.

**That speed is the unusual part.** Most of the compute commitments Anthropic has announced this year are big numbers attached to multi-year ramps. The recent partnerships with **Amazon (5 gigawatts)**, **Google through Broadcom (5 gigawatts)**, **Microsoft and NVIDIA ($30 billion of Azure capacity)**, and **Fluidstack ($50 billion infrastructure investment)** are all important, but you cannot route a Claude Code session through them tomorrow. Colossus 1 you can, which is why the rate-limit changes **hit at the same time as the announcement** instead of a quarter behind it.

The optional second leg of the SpaceX agreement is the **orbital one**: Anthropic and SpaceX have expressed mutual interest in working toward **multiple gigawatts of compute capacity in space**, using SpaceX launch infrastructure. That is a much longer horizon and a lot less concrete, but it is the part of the announcement that explains why this deal got more attention than the headline numbers alone would justify.

# Why this is a bigger deal than the numbers suggest

The interesting strategic read does not stop at "Anthropic got more GPUs." Every frontier lab is racking up compute commitments. The interesting read is **what Anthropic chose to do with the first batch of new capacity that actually came online**.

Three options were on the table:

1. Train the next model.
2. Lower prices.
3. Raise limits for paying customers on the existing model.

Anthropic picked **option three**, with explicit language about Pro, Max, Team, and Enterprise. That tells you which constraint Anthropic believes was actually slowing them down, and the answer is the one most Claude Code users would have guessed: the **5-hour cap and peak-hour throttling** were getting in the way of the developer workflow that has driven a lot of recent Claude growth.

It also says something about how Anthropic is **positioning Claude Code specifically**. The product has shifted in the last year from **"a chat box that can also write code"** toward **"an agentic tool you hand a feature to and check on later."** Long agentic runs are exactly what 5-hour caps strangle, and exactly what doubling those caps unblocks.

# What about the Musk part of the story

The SpaceX deal is also a story about Elon Musk, and the timing is hard to ignore. Musk is currently suing OpenAI and Sam Altman, alleging the company abandoned its nonprofit mission. Backing Anthropic with SpaceX compute, while xAI runs in parallel and Musk publicly says Anthropic's leadership did not "set off [his] evil detector," is a notable position to take while that lawsuit is in progress.

For developers, that context is interesting but largely incidental to the practical change. The contract is between two companies, the GPUs are real, and the rate-limit numbers do not depend on anyone's view of OpenAI's nonprofit history. If you are building on Claude, the relevant fact is that the cap moved and the throttling stopped. The rest is an investor-relations story.

# What this means if you build with Claude Code

The honest practical reads:

- **If you live inside Claude Code:** the 5-hour cap is no longer the limit you should design your day around. You can hand the agent a longer task and expect it to finish before the timer matters.
- **If you are on Pro and you abandoned peak hours:** there is no peak hour anymore. The capacity you got at 2am is the capacity you get at 2pm.
- **If you build on the Opus API directly:** the new rate limits unlock workloads that were previously rate-limit-bound. If you were architecting around a tokens-per-minute ceiling, that ceiling has moved a lot.
- **If you hit the weekly cap, not the 5-hour cap:** your situation did not improve much, and you should expect Anthropic to address weekly limits in a future announcement rather than this one.

# Ship more, worry about the backend less

Higher rate limits are only useful if there is something on the other side of your prompts ready to receive what the agent builds. That is the part of the stack that often eats up the time the agent saves you.

[Appwrite](/) is an open-source backend-as-a-service that includes [Auth](/products/auth), [Databases](/docs/products/databases), [Storage](/products/storage), [Functions](/products/functions), [Messaging](/products/messaging), and [Sites](/products/sites), an open-source Vercel alternative for deploying your web app next to your backend. It is available as both managed Cloud and self-hosted. Instead of standing up auth, schemas, file pipelines, deploys, and API layers yourself, you point your app at Appwrite and the whole stack is one project.

The [Appwrite plugin for Claude Code](/blog/post/announcing-appwrite-claude-code-plugin) bundles the Appwrite API MCP server, the Appwrite Docs MCP server, and eleven SDK-specific agent skills into a single install. Two commands and your Claude Code agent can stand up users, databases, storage, and functions against your Appwrite project without you writing the integration code yourself.

```bash
claude plugins marketplace add appwrite/claude-plugin
claude plugins install appwrite@appwrite
```

From there the loop is straightforward: Claude Code now runs longer before hitting limits, and Appwrite gives it a backend to ship against. The increased usage Anthropic just announced means more things shipped per session. Pair it with a backend you do not have to babysit, and the session translates into actual product instead of half-finished scaffolding.

Check out the [Claude Code integration guide](/docs/tooling/ai/ai-dev-tools/claude-code) and the [Appwrite MCP server docs](/docs/tooling/ai/mcp-servers) to get started, or [join us on Discord](https://appwrite.io/discord) if you want to compare notes on what you are building.

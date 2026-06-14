---
layout: post
title: "Gemini 3.5 Flash: a detailed benchmark and capability review"
description: "A detailed look at Gemini 3.5 Flash: what shipped at Google I/O 2026, pricing, Google's own benchmark table, Artificial Analysis numbers, and how it scores on the Appwrite Arena benchmark."
date: 2026-05-20
cover: /images/blog/gemini-3-5-flash-deep-dive/cover.avif
timeToRead: 11
author: atharva
category: ai
faqs:
  - question: "What is Gemini 3.5 Flash?"
    answer: "Gemini 3.5 Flash is Google DeepMind's mid-2026 Flash-tier reasoning model, built on the Gemini 3 Flash foundation with explicit thinking levels that trade quality for cost and latency. It accepts text, images, audio, video, and PDF input, outputs up to 64K text tokens, and has a 1M token context window."
  - question: "Is Gemini 3.5 Flash better than Gemini 3.1 Pro?"
    answer: "On the benchmarks Google publishes, 3.5 Flash beats 3.1 Pro on Terminal-Bench 2.1 (76.2% vs 70.3%), MCP Atlas (83.6% vs 78.2%), Finance Agent v2 (57.9% vs 43.0%), and GDPval-AA Elo (1656 vs 1314). It still trails 3.1 Pro on Humanity's Last Exam, ARC-AGI-2, and the 128K MRCR v2 long-context test, so it is not a clean replacement for the Pro tier."
  - question: "How much does Gemini 3.5 Flash cost?"
    answer: "API pricing is $1.50 per million input tokens and $9.00 per million output tokens, with a 90% discount on cached input ($0.15 per million tokens). It is free to use in the Gemini app and inside AI Mode in Google Search."
  - question: "What is the context window for Gemini 3.5 Flash?"
    answer: "1 million input tokens, with a 64K token output cap. The knowledge cutoff is January 2025."
  - question: "Is Gemini 3.5 Flash multimodal?"
    answer: "Yes. It accepts text, images, audio, video, and PDFs as input. Output is text only. Function calling, structured output, code execution, and search-as-a-tool are all supported."
  - question: "Where can I use Gemini 3.5 Flash?"
    answer: "Through the Gemini app, the Gemini API, Google AI Studio, Gemini Enterprise, the Gemini Enterprise Agent Platform, Google AI Mode in Search, Google Antigravity, and Android Studio."
---

Gemini 3.5 Flash shipped on May 19, 2026 at Google I/O. Google positions it as "Pro-level reasoning at Flash-class latency," with the claim that a mid-tier model can carry agentic and coding workloads previously handled by the Pro tier.

This post evaluates that claim against three data sources: Google's published model card, [Artificial Analysis](https://artificialanalysis.ai/models/gemini-3-5-flash), and [Appwrite Arena](https://arena.appwrite.io), an open-source benchmark covering 191 questions across nine Appwrite service categories.

# Model overview

Gemini 3.5 Flash is built on the Gemini 3 Flash reasoning foundation with explicit thinking levels that control quality, cost, and latency. The variant on the [Artificial Analysis leaderboard](https://artificialanalysis.ai/models/gemini-3-5-flash) and in most of Google's published numbers is the "high" thinking configuration.

Model specifications:

- **Inputs.** Text, images, audio, video, and PDFs, up to a 1M token context window.
- **Output.** Text only, with a 64K token output cap.
- **Knowledge cutoff.** January 2025.
- **Tooling.** Function calling, structured output, code execution, and search-as-a-tool are all first-party.
- **Distribution.** Gemini app, Gemini API, Google AI Studio, Gemini Enterprise, the Gemini Enterprise Agent Platform, Google Search AI Mode, Google Antigravity, and Android Studio.
- **Status.** Public preview at launch, free in the consumer Gemini app and Search AI Mode.

# Pricing

API pricing per million tokens:

- **Input:** $1.50
- **Output:** $9.00
- **Cached input:** $0.15 (90% discount)

How it compares:

- **vs Gemini 3 Flash** ($0.50 / $3.00): 3x more on both input and output.
- **vs Gemini 3.1 Pro** ($2.00 / $12.00): 25% cheaper per token on both input and output.
- **Within the Flash tier:** the most expensive Flash-tier model Google has released.

# Google's published benchmark table

The model card lists head-to-head numbers against Gemini 3 Flash, Gemini 3.1 Pro, Claude Sonnet 4.6, Claude Opus 4.7, and GPT-5.5. The full table:

| Category | Benchmark | Gemini 3.5 Flash | Gemini 3 Flash | Gemini 3.1 Pro | Claude Sonnet 4.6 | Claude Opus 4.7 | GPT-5.5 |
| -------- | --------- | ---------------- | -------------- | -------------- | ----------------- | --------------- | ------- |
| Coding | Terminal-bench 2.1 (Terminus-2 harness) | **76.2%** | 58.0% | 70.3% | n/a | 66.1% | 78.2% |
| Coding | SWE-Bench Pro (Public, single attempt) | 55.1% | 49.6% | 54.2% | n/a | **64.3%** | 58.6% |
| Agentic | MCP Atlas (multi-step MCP workflows) | **83.6%** | 62.0% | 78.2% | 69.5% | 79.1% | 75.3% |
| Agentic | Toolathlon (real-world tool use) | **56.5%** | 49.4% | n/a | n/a | n/a | 55.6% |
| UI Control | OSWorld-Verified | 78.4% | 65.1% | 76.2% | 72.5% | 78.0% | **78.7%** |
| Expert tasks | Finance Agent v2 | **57.9%** | 42.6% | 43.0% | 51.0% | 51.5% | 51.8% |
| Expert tasks | GDPval-AA (Elo) | 1656 | 1204 | 1314 | 1676 | 1753 | **1769** |
| Multimodal | CharXiv Reasoning (no tools) | **84.2%** | 80.3% | 83.3% | 72.4% | 82.1% | 84.1% |
| Multimodal | MMMU-Pro (no tools) | **83.6%** | 81.2% | 80.5% | 74.5% | 75.2% | 81.2% |
| Multimodal | Blueprint-Bench 2 (normalized) | 33.6% | 0.0% | 26.5% | 6.7% | 24.5% | **36.2%** |
| Long context | MRCR v2 (8-needle, 128k average) | 77.3% | 67.2% | 84.9% | 84.9% | 59.3% | **94.8%** |
| Long context | MRCR v2 (1M, pointwise) | **26.6%** | 22.1% | 26.3% | n/a | n/a | n/a |
| Reasoning | Humanity's Last Exam (full set) | 40.2% | 33.7% | 44.4% | 33.2% | **46.9%** | 41.4% |
| Reasoning | ARC-AGI-2 | 72.1% | 33.6% | 77.1% | 58.3% | 75.8% | **84.6%** |

Gemini 3.5 Flash leads Pro-class models on agentic tasks (MCP Atlas, Toolathlon, Finance Agent v2) and on multimodal reasoning (CharXiv, MMMU-Pro). It trails on academic reasoning (Humanity's Last Exam, ARC-AGI-2). For coding, results sit between 3.1 Pro and GPT-5.5 depending on the benchmark.

The largest gain is MCP Atlas: a 21.6 point increase over Gemini 3 Flash and 5.4 points over 3.1 Pro. On MCP tool-call workloads, 3.5 Flash is Google's strongest model in the Gemini 3 series.

# Artificial Analysis

[Artificial Analysis](https://artificialanalysis.ai/models/gemini-3-5-flash) runs an independent evaluation suite and ranks models by Intelligence Index, a composite of 10 evaluations: GDPval-AA, 𝜏²-Bench Telecom, Terminal-Bench Hard, SciCode, AA-LCR, AA-Omniscience, IFBench, Humanity's Last Exam, GPQA Diamond, and CritPt.

Gemini 3.5 Flash on Artificial Analysis:

- **Intelligence Index: 55.3** (rank #7 of 147). Top three: GPT-5.5 (xhigh) at 60.2, GPT-5.5 (high) at 58.9, Claude Opus 4.7 (max) at 57.3.
- **Speed: 278 output tokens per second** (rank #2 of 147 in its AA price class). The closest frontier peer is gpt-oss-120b (high) at 246. Other frontier-class models are well behind: Gemini 3.1 Pro Preview at 123, GPT-5.5 (xhigh) at 65, Claude Opus 4.7 (max) at 50.
- **Verbosity: 73M tokens** generated across the Intelligence Index suite, against a leaderboard average of 36M. Verbosity counts how many output tokens the model produced to complete the eval suite. Higher means the model spent more reasoning tokens per answer, which raises latency and bill size even when the per-token price is low.
- **Cost to evaluate the Intelligence Index: $1,552.** That is 5.5x Gemini 3 Flash and 75% more than Gemini 3.1 Pro despite the lower per-token rate. This is the total dollar cost to run the full Intelligence Index once, combining per-token pricing and token volume. It serves as a proxy for what the model costs on heavy reasoning workloads in production.
- **Hallucination rate: 61%** on the AA hallucination measure, 31 points lower than Gemini 3 Flash. The hallucination measure is the share of responses on a fabrication-probing prompt set where the model produces incorrect or invented content. Lower is better, and a 31-point drop versus the predecessor indicates a material gain in factual reliability.

On the intelligence-versus-speed axis, Artificial Analysis ranks Gemini 3.5 Flash as the Pareto leader. No model in the same intelligence bracket runs near 278 tokens per second.

## Intelligence per token against SOTA peers

Per-model summaries from Artificial Analysis:

| Model | AA Intelligence Index | Output tokens (full Index) | Total eval cost | Speed (tok/s) | Input $/Mtok | Output $/Mtok |
| ----- | --------------------: | -------------------------: | --------------: | ------------: | -----------: | ------------: |
| GPT-5.5 (xhigh) | 60.2 | 75M | $3,357 | 65 | $5.00 | $30.00 |
| Claude Opus 4.7 (max) | 57.3 | 110M | $5,117 | 50 | $6.25 | $25.00 |
| Gemini 3.1 Pro Preview | 57.2 | 57M | $892 | 123 | $2.00 | $12.00 |
| **Gemini 3.5 Flash (high)** | **55.3** | **73M** | **$1,552** | **278** | **$1.50** | **$9.00** |
| Kimi K2.6 | 53.9 | 170M | $948 | 98 | $0.95 | $4.00 |

Two points are worth calling out.

**GPT-5.5 is more intelligent on a similar token budget.** GPT-5.5 (xhigh) generates 75M tokens for the full Intelligence Index against 3.5 Flash's 73M, a 3% difference. For roughly the same output token count, GPT-5.5 scores 60.2 versus 55.3. The reason GPT-5.5's eval cost lands at $3,357 against 3.5 Flash's $1,552 is per-token pricing ($5/$30 vs $1.50/$9), not token efficiency. On quality per token, GPT-5.5 leads.

**Gemini 3.1 Pro is the sharper internal comparison.** 3.1 Pro Preview generates 57M tokens, 22% fewer than 3.5 Flash, and scores 57.2 on the Intelligence Index, 1.9 points higher. Total eval cost is $892, 42% lower than 3.5 Flash. The only axis where 3.5 Flash leads is speed: 278 tokens per second versus 3.1 Pro's 123. Google's "Pro-level reasoning at Flash-class latency" claim holds on latency. On the Intelligence Index itself, 3.5 Flash is the second-best Gemini and uses more tokens than 3.1 Pro to reach a lower score.

# Appwrite Arena: backend SDK and API performance

Public leaderboards measure general capability, not whether a model can drive an SDK without hallucinating method names. [Appwrite Arena](https://arena.appwrite.io) is an open-source benchmark covering 191 questions across nine Appwrite service categories: Foundation, Auth, Databases, Functions, Storage, Sites, Messaging, Realtime, and CLI. Each model is evaluated twice: once with the relevant [Appwrite Skill](/docs/tooling/ai/skills) loaded into context, and once without. Results are published on [GitHub](https://github.com/appwrite/arena).

Top finishers on the May 20, 2026 run:

**With Skills loaded (Skill files in context, 191 questions):**

| Model | Overall | MCQ | Freeform | Cost (USD) | Duration |
| ----- | ------: | --: | -------: | ---------: | -------: |
| GPT 5.5 | 97.70 | 98.20 | 94.80 | $4.51 | 33m |
| Claude Opus 4.7 | 97.10 | 97.60 | 94.20 | $3.07 | 53m |
| Qwen 3.6 Plus | 96.50 | 97.60 | 89.80 | $0.58 | 54m |
| Kimi K2.6 | 96.30 | 97.00 | 91.90 | $1.64 | 135m |
| **Gemini 3.5 Flash** | **96.20** | **96.90** | **91.90** | **$3.78** | **20m** |
| DeepSeek V4 Flash | 96.10 | 96.40 | 94.20 | $0.37 | 125m |
| Gemini 3.1 Pro (Preview) | 92.70 | 93.30 | 88.80 | $4.44 | 45m |
| Gemini 3.1 Flash Lite (Preview) | 88.30 | 89.70 | 79.40 | $0.59 | 19m |

**Without Skills (model's built-in knowledge only):**

| Model | Overall | MCQ | Freeform | Cost (USD) | Duration |
| ----- | ------: | --: | -------: | ---------: | -------: |
| Claude Opus 4.7 | 96.20 | 96.40 | 94.80 | $1.89 | 25m |
| GPT 5.5 | 94.20 | 94.50 | 90.00 | $2.19 | 27m |
| Kimi K2.6 | 93.60 | 95.20 | 83.50 | $0.48 | 103m |
| Gemini 3.1 Pro (Preview) | 92.50 | 95.30 | 76.90 | $1.34 | 26m |
| **Gemini 3.5 Flash** | **90.70** | **92.90** | **77.50** | **$1.14** | **13m** |
| GLM 5.1 | 90.20 | 91.50 | 81.90 | $0.30 | 45m |

Three observations from the Arena data.

**It is the fastest model in the top tier.** 20 minutes with Skills and 13 minutes without is faster than every other model scoring above 90. The only model in the with-Skills table with a shorter run is Gemini 3.1 Flash Lite at 19 minutes, but it scores 88.3, below the 90-point top tier.

**Skills materially improve the freeform score.** Without Skills, freeform scores 77.5%. With Skills, freeform reaches 91.9%, a 14.4-point increase. The same delta for GPT 5.5 is +4.8 points (90.0 to 94.8), and for Claude Opus 4.7 is −0.6 points (94.8 to 94.2), where Skills slightly lowered the score because the model's built-in Appwrite knowledge is already near the ceiling. 3.5 Flash relies more on in-context documentation than its frontier peers, consistent with the January 2025 knowledge cutoff.

**Category profile.** With Skills, 3.5 Flash scores 100% on Messaging, MCQ Foundation, MCQ Auth, MCQ Functions, and MCQ Sites, and 94.1% on MCQ Realtime. The weakest categories are TablesDB (89.1% with Skills, 77.8% without) and CLI (95.0% with Skills, 73.3% without). Both require the most current API surface, which the knowledge cutoff does not cover.

# Workloads where 3.5 Flash is the right choice

- **MCP-driven agents.** MCP Atlas at 83.6% is the highest result Google has published on the benchmark. For agents driving an MCP server such as [Appwrite's API MCP](/docs/tooling/ai/mcp-servers/api), 3.5 Flash is the most cost-efficient frontier option.
- **Throughput-bound multimodal pipelines.** CharXiv at 84.2% and MMMU-Pro at 83.6% at 278 tokens per second is a combination no other top-ten Intelligence Index model provides. Document ingestion with charts, audio and video reasoning, and pipelines with many small multimodal calls benefit directly.
- **Iterative coding agents on bounded scope.** Terminal-Bench 2.1 at 76.2%, a 1M context window, and the highest throughput in the top ten allow more iterations per wall-clock minute than any frontier alternative. The reasoning gap to Opus 4.7 and GPT-5.5 only becomes a constraint on research-grade tasks.

# Model selection for Appwrite projects

[Appwrite](https://cloud.appwrite.io) provides the primitives an agent needs to operate on a project: typed tables, scoped API keys, an [API MCP server](/docs/tooling/ai/mcp-servers/api), a [Docs MCP server](/docs/tooling/ai/mcp-servers/docs), and [Agent Skills](/docs/tooling/ai/skills) for every major SDK. The Arena results above show how each model performs against this surface.

Speed is the column where Gemini 3.5 Flash dominates, but speed is not coding intelligence. On the Arena freeform scores and the SOTA Intelligence Index comparison above, GPT 5.5 and Claude Opus 4.7 lead 3.5 Flash by a meaningful margin on the same Appwrite coding tasks.

Two recommended defaults:

1. For interactive workloads where a developer waits on the response, **Gemini 3.5 Flash with the Appwrite Skill loaded** is the fastest top-tier option. Use it when iteration speed beats per-response correctness.
2. For coding work where correctness matters more than wall-clock latency, **GPT 5.5 or Claude Opus 4.7** lead. Both produce higher quality code on the same Appwrite tasks, with or without Skills loaded.

For other cases, optimize on the price-to-throughput frontier, where 3.5 Flash sits.

# Next steps

Select Gemini 3.5 Flash inside a tool that supports it: Cursor, Google AI Studio, Google Antigravity, or the Gemini API directly. To connect Appwrite to the model, follow the [Cursor plugin docs](/docs/tooling/ai/ai-dev-tools/cursor) for Cursor, or the [Antigravity MCP setup docs](/docs/tooling/ai/ai-dev-tools/antigravity) for Antigravity. Both walk through adding the Appwrite API MCP and Docs MCP servers so the model can act on your project.

- [Appwrite Arena](https://arena.appwrite.io)
- [Gemini 3.5 Flash model card](https://deepmind.google/models/model-cards/gemini-3-5-flash/)
- [Artificial Analysis: Gemini 3.5 Flash](https://artificialanalysis.ai/models/gemini-3-5-flash)

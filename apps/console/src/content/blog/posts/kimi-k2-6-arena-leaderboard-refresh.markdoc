---
layout: post
title: "Kimi K2.6 lands on Appwrite Arena: the May 2026 leaderboard update"
description: "Kimi K2.6 from MoonshotAI ranks #3 without skills and #4 with skills on Appwrite Arena, in a refresh that swaps in eleven current frontier models and hardens the benchmark runner."
date: 2026-05-08
cover: /images/blog/kimi-k2-6-arena-leaderboard-refresh/cover.avif
timeToRead: 6
author: atharva
category: ai
featured: false
faqs:
  - question: "What is Kimi K2.6 and who makes it?"
    answer: "Kimi K2.6 is the latest open-weight large language model from MoonshotAI. On Appwrite Arena it is accessed through OpenRouter at the model ID moonshotai/kimi-k2.6, with pricing around $0.75 per million input tokens and $3.50 per million output tokens."
  - question: "Where does Kimi K2.6 rank on the May 2026 Arena leaderboard?"
    answer: "Kimi K2.6 ranks #4 of 11 with skills at 96.3% overall and #3 of 11 without skills at 93.6% overall. Without skills, only Claude Opus 4.7 and GPT 5.5 score higher, both at roughly four times the per-run cost."
  - question: "Why does Kimi K2.6 score differently with and without skills?"
    answer: "With-skills mode includes Appwrite documentation in the prompt, while without-skills relies on the model's training data alone. Kimi K2.6 gains 8.4 points on free-form questions when skills are added, going from 83.5% to 91.9%, which suggests the model uses Appwrite documentation effectively when it is in the prompt."
  - question: "What changed in the May 2026 Arena update beyond adding Kimi K2.6?"
    answer: "The full model roster was refreshed to current frontier versions, including DeepSeek V4 Flash, Qwen 3.6 Plus, MiniMax M2.7, Mistral Large 3 2512, GLM 5.1, Grok 4.3, GPT 5.5, Claude Opus 4.7, and both Gemini 3.1 Pro (Preview) and Gemini 3.1 Flash Lite (Preview). The benchmark runner also added retries with backoff, deterministic output ordering, atomic JSON writes, and a configurable concurrency setting."
---

[Appwrite Arena](https://arena.appwrite.io) measures how well AI models understand Appwrite. The May 2026 update is the leaderboard's biggest change since launch: Kimi K2.6 from MoonshotAI is the new headline addition, the model roster is now refreshed to current frontier versions across the board, and the benchmark runner has picked up retries, deterministic output, and configurable concurrency.

This post walks through what changed, where Kimi K2.6 lands, and how to read the new numbers.

# Kimi K2.6, the headline addition

Kimi K2.6 is the latest open-weight model from MoonshotAI. Pricing on OpenRouter sits around $0.75 per million input tokens and $3.50 per million output tokens, which puts it between Mistral Large 3 and GLM 5.1 in the Arena cost order.

The interesting result is what happens when you take skills away.

| Mode | Rank | Overall | MCQ | Free-form | Cost | Correct |
| --- | --- | --- | --- | --- | --- | --- |
| With skills | 4 of 11 | 96.3% | 97.0% | 91.9% | $1.64 | 185 / 191 |
| Without skills | 3 of 11 | 93.6% | 95.2% | 83.5% | $0.48 | 179 / 191 |

Without skills, the only models ahead of Kimi K2.6 are Claude Opus 4.7 and GPT 5.5, both of which cost roughly four times more on this run. With skills, Kimi K2.6 lands inside one point of Qwen 3.6 Plus and DeepSeek V4 Flash, two of the most cost-efficient models on the board.

The free-form jump is also worth a mention. Kimi K2.6 goes from 83.5% on free-form questions without skills to 91.9% with skills, an 8.4 point gain. That gap tells you the model can use Appwrite documentation effectively when it is in the prompt, rather than relying on memorized patterns alone.

The trade-off is speed. Kimi K2.6 averages 17 tokens per second and finishes the with-skills run in roughly 134 minutes, slower than every other model on the board except DeepSeek V4 Flash. If you are picking a model for an interactive coding loop, that matters. If you are picking one for batch generation or scheduled jobs, it matters less.

![Kimi K2.6 model detail page showing 96.3 percent overall with category breakdown](/images/blog/kimi-k2-6-arena-leaderboard-refresh/arena-kimi-detail.avif)

# The leaderboard you remember is gone

The version of Arena most readers saw at launch ran an older roster. That roster has been retired. Eleven models were swapped or upgraded in a single roster overhaul before the Kimi K2.6 addition.

| Model out (old roster) | Model in (current roster) |
| --- | --- |
| Grok 4.1 Fast | Grok 4.3 |
| MiniMax M2.5 | MiniMax M2.7 |
| DeepSeek V3.2 | DeepSeek V4 Flash |
| Qwen 3.5 397B A17B | Qwen 3.6 Plus |
| Kimi K2.5 | Kimi K2.6 (added in a follow-up run) |
| GLM 5 | GLM 5.1 |
| GPT 5.3 Codex | (removed) |
| GPT 5.4 | GPT 5.5 |
| Claude Opus 4.6 | Claude Opus 4.7 |
| (not present) | Gemini 3.1 Pro (Preview) |
| (not present) | Gemini 3.1 Flash Lite (Preview) |
| (not present) | Mistral Large 3 2512 |

The roster is now ordered roughly by price, from DeepSeek V4 Flash at around $0.10 per million tokens up through Claude Opus 4.7 and GPT 5.5 at roughly $5. Both Gemini 3.1 variants and Mistral Large 3 2512 are new to the board. Every other slot is a current-generation upgrade of the model that was there before.

# Without skills tells a sharper story

The without-skills view is where Kimi K2.6's rank stands out, and where the new roster reshuffles harder than the with-skills view.

![Appwrite Arena without-skills leaderboard with Kimi K2.6 in third place](/images/blog/kimi-k2-6-arena-leaderboard-refresh/arena-leaderboard-without-skills.avif)

The top of the without-skills board now reads:

| # | Model | Overall | MCQ | Free-form | Cost |
| --- | --- | --- | --- | --- | --- |
| 1 | Claude Opus 4.7 | 96.2% | 96.4% | 94.8% | $1.89 |
| 2 | GPT 5.5 | 94.2% | 94.5% | 90.0% | $2.19 |
| 3 | **Kimi K2.6** | **93.6%** | **95.2%** | **83.5%** | **$0.48** |
| 4 | Gemini 3.1 Pro | 92.4% | 95.2% | 76.9% | $1.31 |
| 5 | GLM 5.1 | 90.2% | 91.5% | 81.9% | $0.30 |

Two things to notice here. First, Kimi K2.6 is the cheapest model in the top three by a wide margin. Second, the gap between MCQ and free-form is large for every model in this view, which lines up with the original Arena thesis: pulling Appwrite documentation into the prompt closes a knowledge gap that shows up most clearly on open-ended questions.

The with-skills view, by contrast, compresses everyone toward the top. Six models score above 95% once skills are added, and the practical question shifts from *which model knows Appwrite* to *which model gives me the right answer cheapest and fastest*.

# A more credible benchmark runner

The numbers are only as good as the runner that produced them. The latest changes to the benchmark scripts make the runs more reliable and the output more reproducible:

- **Retries with backoff.** Each question is now attempted up to three times. Empty MCQ tool calls are treated as errors and trigger a retry, instead of being recorded as a wrong answer. Transient OpenRouter errors no longer poison a model's score for an entire category.
- **Deterministic output ordering.** Per-model results are sorted by question order before being written to disk, so two runs that score the same produce diff-clean JSON. Easier to review, easier to diff.
- **Atomic writes.** Result files are written to a temporary path and renamed into place. A crashed run can no longer leave a half-written JSON file behind.
- **Configurable concurrency.** The runner reads `BENCHMARK_CONCURRENCY` from the environment, defaulting to 1. Useful for re-running a single model quickly without serializing all 191 questions over a single connection.

These are the kind of changes you make when you want a benchmark to be quoted, not only shipped. They also make community contributions safer: an external pull request that adds a model or a question can re-run the benchmark and produce a clean diff against the previous results.

# Where to go next

If you want to dig in further, the Arena UI lets you filter by category, switch between with and without skills, sort by any column, and click through to a per-model breakdown that includes per-question reasoning and tool call counts. The repo is open source, so you can also re-run the benchmark locally against your own OpenRouter key.

- [Appwrite Arena leaderboard](https://arena.appwrite.io)
- [Kimi K2.6 on Arena](https://arena.appwrite.io/model/kimi-k2-6)
- [Arena on GitHub](https://github.com/appwrite/arena)
- [Arena documentation](/docs/tooling/arena)
- [Appwrite Skills](/docs/tooling/ai/skills)
- [Discord community](https://appwrite.io/discord)

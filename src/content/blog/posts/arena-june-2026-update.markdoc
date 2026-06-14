---
layout: post
title: "Claude Opus 4.8 tops Appwrite Arena: the June 2026 leaderboard update"
description: "Claude Opus 4.8 takes #1 on Appwrite Arena's without-skills board at 97.4%, the first model to beat Claude Opus 4.7, in a June update that adds four new frontier models."
date: 2026-06-01
cover: /images/blog/arena-june-2026-update/cover.avif
timeToRead: 6
author: atharva
category: ai
featured: false
faqs:
  - question: "Which AI model knows Appwrite best in June 2026?"
    answer: "It depends on the mode. With Appwrite documentation in the prompt, GPT 5.5 leads at 97.7% overall. Without any documentation, relying on training knowledge alone, Claude Opus 4.8 leads at 97.4%, the first model to pass 97% in that mode and the first to beat Claude Opus 4.7."
  - question: "What new models were added to Appwrite Arena in June 2026?"
    answer: "Four: Claude Opus 4.8 from Anthropic, Grok Build 0.1 from xAI, Gemini 3.5 Flash from Google, and MiniMax M3 from MiniMax. That brings the board from 11 models to 15, with the benchmark itself unchanged from May."
  - question: "Why does Claude Opus 4.8 score higher without skills than with skills?"
    answer: "Claude Opus 4.8 scores 97.4% without skills and 97.1% with skills. The model already knows Appwrite well from training, so adding documentation to the prompt does not raise its accuracy, and the extra input tokens push the with-skills run from $1.56 to $6.86. It is the first model on the board you would run without skills for both score and cost."
  - question: "What is the cheapest AI model that knows Appwrite well?"
    answer: "MiniMax M3 offers the strongest cost-to-score ratio. It scores 95.7% with skills at $0.49 per run and 91.0% without skills at $0.09 per run. DeepSeek V4 Flash is similarly inexpensive at $0.37 with skills, scoring 96.1%."
---

[Appwrite Arena](https://arena.appwrite.io) is an open-source benchmark that measures how well AI models understand Appwrite. It scores each model on 191 questions spanning every Appwrite service, run twice: once with the relevant [Appwrite Skill](/docs/tooling/ai/skills) loaded into context, and once on the model's training knowledge alone. The gap between those two runs is what tells you how well a model already knows the platform. The June update adds four new frontier models, taking the board from **11** to **15**, and one of them, Claude Opus 4.8, takes first place on the without-skills leaderboard.

# Claude Opus 4.8 leads the without-skills leaderboard

On the without-skills board, where models answer from training knowledge alone with no Appwrite documentation in the prompt, [Claude Opus 4.8](/blog/post/anthropic-just-launched-claude-opus-48-with-fast-mode-and-dynamic-workflows) scores 97.4% overall and takes first place. It is the first model to clear **97%** in that mode, and the first to rank above Claude Opus 4.7.

| Mode | Rank | Overall | MCQ | Free-form | Cost | Correct |
| --- | --- | --- | --- | --- | --- | --- |
| With skills | 3 of 15 | 97.1% | 97.6% | 94.4% | $6.86 | 186 / 191 |
| Without skills | 1 of 15 | 97.4% | 98.2% | 92.1% | $1.56 | 187 / 191 |

For almost every model on the board, adding Appwrite documentation to the prompt raises the score, because the documentation closes a knowledge gap. Claude Opus 4.8 is the first model where that does not hold: it scores higher without skills (97.4%) than with them (97.1%). The model already knows Appwrite well enough from training that adding documentation to the prompt does not improve its accuracy.

The same pattern appears in cost. At **$5** per million input tokens, including the skills documentation in every prompt raises the with-skills run to $6.86, more than **four times** the $1.56 without-skills run. For Claude Opus 4.8, skills add cost and slightly lower the score, making it the first model on the board better run without them.

![Claude Opus 4.8 model detail page on Appwrite Arena showing 97.1 percent overall with the category breakdown](/images/blog/arena-june-2026-update/arena-opus-4-8-detail.avif)

# New models added in June 2026

Claude Opus 4.8 is not the only addition. Three other frontier models also joined since May, each with a different balance of speed and cost.

| Model | Provider | Overall (with skills) | Rank | Cost / run | Speed | Price (in / out per 1M) |
| --- | --- | --- | --- | --- | --- | --- |
| Claude Opus 4.8 | Anthropic | 97.1% | 3 of 15 | $6.86 | 40 tok/s | $5.00 / $25.00 |
| Grok Build 0.1 | xAI | 96.7% | 4 of 15 | $2.28 | 138 tok/s | $1.00 / $2.00 |
| Gemini 3.5 Flash | Google | 96.2% | 7 of 15 | $3.78 | 118 tok/s | $1.50 / $9.00 |
| MiniMax M3 | MiniMax | 95.7% | 10 of 15 | $0.49 | 25 tok/s | $0.30 / $1.20 |

## Grok Build 0.1

- Ranks fourth with skills at **96.7%**, running at **138 tok/s**, far above Kimi K2.6's **17 tok/s**.
- Its free-form score gains **7.5 points** with skills, from **83.7%** to **91.2%**.
- Priced at **$1.00 / $2.00** per million tokens, or **$2.28** per with-skills run.

## Gemini 3.5 Flash

- Ranks seventh with skills at **96.2%** and runs at **118 tok/s**.
- Depends most on documentation of the new models: overall falls from **96.2%** with skills to **90.7%** without, and free-form moves **14.4 points**, from **77.5%** to **91.9%**.
- At **$9.00** per million output tokens, a with-skills run costs **$3.78**, among the higher figures on the board.

## MiniMax M3

- Offers the strongest cost-to-score ratio: **$0.49** per with-skills run (**95.7%**) and **$0.09** without skills (**91.0%**).
- Its **95.2%** free-form is the highest of the four new models.
- A clear improvement over MiniMax M2.7: **93.2%** to **95.7%** with skills, and **85.2%** to **91.0%** without.
- Its **$0.30 / $1.20** per-million pricing reflects a **50% discount** on OpenRouter running until June 7, 2026, so the cost figures above will rise once it ends.

# Without-skills leaderboard rankings

Adding Claude Opus 4.8 reorders the top of the without-skills rankings, where the spread between models is widest.

![Appwrite Arena without-skills leaderboard with Claude Opus 4.8 in first place](/images/blog/arena-june-2026-update/arena-leaderboard-without-skills.avif)

The top of the without-skills board now reads:

| # | Model | Overall | MCQ | Free-form | Cost |
| --- | --- | --- | --- | --- | --- |
| 1 | **Claude Opus 4.8** | **97.4%** | **98.2%** | **92.1%** | **$1.56** |
| 2 | Claude Opus 4.7 | 96.2% | 96.4% | 94.8% | $1.89 |
| 3 | GPT 5.5 | 94.0% | 94.5% | 90.6% | $3.97 |
| 4 | Kimi K2.6 | 93.6% | 95.2% | 83.5% | $0.48 |
| 5 | Grok Build 0.1 | 91.5% | 92.7% | 83.7% | $0.47 |

Two Anthropic models now hold the top two positions without any documentation, with GPT 5.5 close behind. The free-form column shows the expected pattern: the models that drop the most without skills are those that rely on documentation to answer open-ended questions, and the gap between multiple-choice and free-form widens further down the table.

# With-skills leaderboard rankings

With Appwrite documentation in the prompt, the board compresses toward the top. **Ten** of the **fifteen** models score **95.7%** or higher, and the top six sit within **1.4 points** of each other.

| #   | Model           | Overall | MCQ   | Free-form | Cost  |
| --- | --------------- | ------- | ----- | --------- | ----- |
| 1   | GPT 5.5         | 97.7%   | 98.2% | 94.8%     | $4.51 |
| 2   | Claude Opus 4.7 | 97.1%   | 97.6% | 94.2%     | $3.07 |
| 3   | Claude Opus 4.8 | 97.1%   | 97.6% | 94.4%     | $6.86 |
| 4   | Grok Build 0.1  | 96.7%   | 97.6% | 91.2%     | $2.28 |
| 5   | Qwen 3.6 Plus   | 96.5%   | 97.6% | 89.8%     | $0.58 |
| 6   | Kimi K2.6       | 96.3%   | 97.0% | 91.9%     | $1.64 |

- **GPT 5.5 holds first place at 97.7%**, the only model above **97.5%** with skills, on the strength of a board-leading **98.2%** on multiple-choice.
- **The two Anthropic models trade places from the without-skills board.** With skills, Claude Opus 4.7 ranks **#2** and Claude Opus 4.8 ranks **#3**, both at **97.1%** with identical multiple-choice scores (**97.6%**) and **186 of 191** correct. Without skills the order is reversed, with Opus 4.8 at **97.4%** ahead of Opus 4.7 at **96.2%**. Documentation lifts Opus 4.7 by **0.9 points** (**96.2%** to **97.1%**) but does not help Opus 4.8 (**97.4%** to **97.1%**), so the two converge once the docs are in the prompt.
- **The field stays tight below the top.** Grok Build 0.1 (**96.7%**), Qwen 3.6 Plus (**96.5%**), and Kimi K2.6 (**96.3%**) are separated by fractions of a point, so cost and speed, rather than accuracy, decide between them.

# Resources

The Arena UI lets you filter by category, switch between with and without skills, sort by any column, and click through to a per-model breakdown with per-question reasoning and tool call counts. The repo is open source, so you can re-run the benchmark locally against your own OpenRouter key.

- [Appwrite Arena leaderboard](https://arena.appwrite.io)
- [Claude Opus 4.8 on Arena](https://arena.appwrite.io/model/claude-opus-4-8)
- [Grok Build 0.1 on Arena](https://arena.appwrite.io/model/grok-build-0-1)
- [Gemini 3.5 Flash on Arena](https://arena.appwrite.io/model/gemini-3-5-flash)
- [MiniMax M3 on Arena](https://arena.appwrite.io/model/minimax-m3)
- [Arena on GitHub](https://github.com/appwrite/arena)
- [Arena documentation](/docs/tooling/arena)
- [Appwrite Skills](/docs/tooling/ai/skills)
- [Discord community](https://appwrite.io/discord)

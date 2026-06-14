---
layout: post
title: "Introducing Appwrite Arena: Which AI model knows Appwrite best?"
description: Appwrite Arena is an open-source benchmark that tests how well AI models understand Appwrite's services, SDKs, and APIs. 191 questions across 9 service categories, fully transparent scoring, and all results open source.
date: 2026-03-17
cover: /images/blog/announcing-appwrite-arena/cover.avif
timeToRead: 4
author: matej-baco
category: announcement
featured: true
callToAction: true
faqs:
  - question: "What is Appwrite Arena?"
    answer: "Appwrite Arena is an open-source benchmark that measures how well large language models understand Appwrite. It tests models on 191 questions across 9 service categories (Auth, Databases, Functions, Storage, Sites, Messaging, Realtime, CLI, and Foundation), using both multiple-choice and open-ended scoring."
  - question: "How is Arena different from generic LLM coding benchmarks?"
    answer: "Generic benchmarks measure broad coding ability. Arena measures Appwrite-specific knowledge: SDK method names, API patterns, service configuration, and correct usage. A model can ace HumanEval and still hallucinate Appwrite API calls, which is why a dedicated benchmark matters when you build on Appwrite."
  - question: "What are Skills and how do they affect benchmark scores?"
    answer: "[Appwrite Skills](/docs/tooling/skills) are Markdown files containing up-to-date SDK and API context that AI agents can load on demand. Arena tests models both with and without Skills, so you can see how much each model improves with the right context. Some models gain dramatically, others barely move."
  - question: "Which model currently scores best on Arena?"
    answer: "Results shift as new models ship and we rerun the benchmark. At launch, GPT-5.4 led with Skills enabled and Claude Opus 4.6 led without Skills. Check [arena.appwrite.io](https://arena.appwrite.io) for the current leaderboard, since the rankings change with every new model release."
  - question: "Can I contribute to Arena?"
    answer: "Yes. Arena is fully open source on [GitHub](https://github.com/appwrite/arena). You can open PRs to add new questions, improve scoring rubrics, fix bugs, or include new models. Reference answers and scoring methodology are all in the repo."
  - question: "How can Arena help me pick an AI model for my Appwrite project?"
    answer: "Use it to compare cost, latency, and Appwrite accuracy side by side. A top-tier model might be the obvious pick on raw scores, but Arena also surfaces models that are 50% cheaper and only a few points behind, which is often the better real-world trade-off."
---

AI coding agents are everywhere. They write your functions, scaffold your database schemas, and wire up authentication flows. But here's the question nobody was answering: **which AI model actually knows Appwrite best?**

We built [Appwrite Arena](https://arena.appwrite.io) to find out.

# What is Arena?

Appwrite Arena is an open-source benchmark that evaluates how well large language models understand Appwrite. It tests models across real-world usage scenarios, covering everything from Auth and Databases to Functions, Storage, Sites, and more.

Arena doesn't just measure general coding ability. It measures **Appwrite-specific knowledge**: correct SDK usage, accurate API patterns, and proper service configuration. The kind of knowledge that determines whether your AI agent generates working Appwrite code or something that looks right but breaks at runtime.

All questions, answers, and scores are fully open source and available on [GitHub](https://github.com/appwrite/arena).

# How it works

Arena evaluates each model using **191 questions** spanning **9 Appwrite service categories**:

- Foundation
- Auth
- Databases
- Functions
- Storage
- Sites
- Messaging
- Realtime
- CLI

Questions are drawn from actual Appwrite platform usage, the same kinds of tasks developers encounter daily when building with Appwrite.

## With and without Skills

Each model is tested in two contexts:

- **Without Skills**: The model answers using only its built-in training data.
- **With Skills**: The model answers with access to Appwrite's [Skills files](/docs/tooling/skills), which provide up-to-date SDK and API context.

This comparison reveals something important: how much a model improves when given the right documentation. Some models see dramatic improvements. Others barely move and context gets bloated. That gap tells you a lot about which models are best at leveraging context and which ones rely more on memorized patterns.

# Scoring

Arena uses two complementary scoring methods to give you a complete picture.

## Deterministic (MCQ)

165 multiple-choice questions with a single correct answer. These scores are fully reproducible with no judge bias, giving you a reliable baseline for comparing models.

## AI-judged (open-ended)

26 open-ended questions scored from 0 to 1 by an AI judge using rubrics and reference answers. These test reasoning and real-world usage patterns that multiple-choice questions can't capture. Scores may have slight variance due to the nature of AI-based evaluation.

The combination of both methods ensures that Arena measures not just factual recall but also a model's ability to reason about Appwrite in practice.

# Why this matters

If you're using AI agents to build with Appwrite, the model you choose directly affects your productivity. A model that understands Appwrite means fewer hallucinated method calls, fewer trips to the docs, and less time debugging AI-generated code.

Arena gives you the data to make that choice. Instead of guessing which model works best, you can see exactly how each one performs across every Appwrite service.

**Cost vs. performance.** The best-in-class models are expensive. Arena helps you answer whether a top-tier model is actually worth the price for your project, or whether a model that's significantly cheaper or faster gets you close enough.

**Always up to date.** Because Arena is a benchmark, we can rerun it whenever new models or updates drop. This isn't a one-time comparison. It's a living source of truth you can come back to anytime you're evaluating models.

**Response duration matters.** Two models with similar token throughput can have very different benchmark durations. A slower run often means the model is spending more tokens to reach the same answer, which translates to a slower developer experience in practice.

And if you've installed [Appwrite Skills](/docs/tooling/skills), Arena shows you exactly how much they improve your model's performance, so you can decide whether skills are worth adding to your workflow.

# Open source, fully transparent

Every part of Arena is open source. The questions, the reference answers, the scoring rubrics, and the results. You can verify any score, reproduce any benchmark run, and see exactly how each model was evaluated.

You can also contribute. If you think a question is missing, a rubric could be improved, or a new model should be added, open a PR on the [Arena GitHub repository](https://github.com/appwrite/arena).

Arena is already receiving community contributions. Thank you to [Abhi Varde](https://github.com/AbhiVarde) for [submitting a bug fix](https://github.com/appwrite/arena/pull/24) before launch.

# Early results

Here are some highlights from the first round of benchmarks:

- **GPT-5.4** currently ranks as the best model with skills, while **Claude Opus 4.6** leads without skills.
- **GPT-5.3 Codex** used surprisingly few tokens compared to other models, roughly 50% less.
- **Open-source models** like DeepSeek and MiniMax offer the best balance between intelligence and cost.
- **Skills have a massive impact on MiniMax**, making it one of the biggest improvers with added context.
- Most recent models tend to perform best with **Databases** questions.

Head to [arena.appwrite.io](https://arena.appwrite.io) to explore the full leaderboard, compare models side by side, and find the best fit for your Appwrite development workflow.

# Resources

- [Arena leaderboard](https://arena.appwrite.io)
- [Arena on GitHub](https://github.com/appwrite/arena)
- [Arena documentation](/docs/tooling/arena)
- [Appwrite Skills](/docs/tooling/skills)
- [Discord server](https://appwrite.io/discord)

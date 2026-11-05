---
layout: article
title: Appwrite Arena
description: An open-source benchmark that evaluates how well AI models understand Appwrite's services, SDKs, and APIs.
---

[Appwrite Arena](https://arena.appwrite.io) is an open-source benchmark that evaluates how well AI models understand Appwrite. It tests models across real-world Appwrite usage scenarios, covering services, SDKs, and APIs, to help you choose the best model for building with Appwrite.

Arena ranks models by their ability to answer questions drawn from actual Appwrite platform usage, both with and without access to Appwrite [skills](/docs/tooling/skills). This makes it easy to see which models generate the most accurate Appwrite code out of the box and which benefit most from added context.

All questions, answers, and scores are fully open source and available on [GitHub](https://github.com/appwrite/arena).

# How it works {% #how-it-works %}

Arena evaluates each model using a pool of **191 questions** spanning **9 Appwrite service categories**:

- Foundation
- Auth
- Databases
- Functions
- Storage
- Sites
- Messaging
- Realtime
- CLI

Each model is tested in two contexts:

- **Without Skills**: The model answers using only its built-in training data.
- **With Skills**: The model answers with access to Appwrite's [skills files](/docs/tooling/skills), which provide up-to-date SDK and API context.

The gap between these two scores reveals how effectively a model can leverage provided documentation to improve its responses.

All benchmark runs are configured with:

- **Temperature 0**: Makes the entire benchmark deterministic, so identical inputs always produce identical outputs.
- **Extended thinking set to high**: Matches the configuration most commonly used in real-world programming contexts.

# Scoring {% #scoring %}

Arena uses two complementary scoring methods to evaluate model performance:

## Deterministic (MCQ) {% #deterministic %}

165 multiple-choice questions structured like a typical exam, each with a single correct answer:

- The model receives a system prompt and the question.
- Four tools are provided, one for each possible answer.
- The model submits its response by calling the correct tool, avoiding issues with verbose or unparseable output.

Scores are fully reproducible with no judge bias.

## AI-judged (open-ended) {% #ai-judged %}

26 open-ended questions scored from 0 to 1 by an AI judge using rubrics and reference answers:

- Tests reasoning and real-world usage patterns that multiple-choice cannot capture.
- Scores may have slight variance due to the nature of AI-based evaluation.

# Use cases {% #use-cases %}

- **Choosing a model**: Compare models to find the one that best fits your Appwrite development workflow and budget.
- **Cost vs. performance**: Determine whether a top-tier model justifies its price for your project, or whether a cheaper or faster model gets you close enough.
- **Measuring skill impact**: See how much a model improves when given Appwrite skills, helping you decide whether to install skills for your AI tools.
- **Comparing response duration**: Models with similar token throughput can have very different benchmark durations. A slower run often indicates the model is spending more tokens to reach the same answer, resulting in a slower development experience.
- **Staying up to date**: Arena is rerun as new models and updates are released, serving as a living reference you can return to whenever you need to re-evaluate your choice.
- **Contributing**: Since Arena is open source, you can submit new questions, suggest improvements to scoring rubrics, or add new models to the benchmark.

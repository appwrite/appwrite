---
layout: post
title: Anthropic just launched Claude Fable 5 and Claude Mythos 5
description: Anthropic launched Claude Fable 5 and Mythos 5, its first Mythos-class models. See benchmarks, safeguards, pricing, and how to build agents on Appwrite.
date: 2026-06-10
cover: /images/blog/anthropic-just-launched-claude-fable-5-and-claude-mythos-5/cover.avif
timeToRead: 5
author: aishwari
category: ai
featured: false
faqs:
  - question: What is the difference between Claude Fable 5 and Claude Mythos 5?
    answer: Claude Fable 5 and Claude Mythos 5 use the same underlying model, but differ in access and safeguards. Fable 5 is broadly available with stricter protections, while Mythos 5 has some safeguards lifted for approved trusted-access use cases.
  - question: How much does Claude Fable 5 cost?
    answer: Claude Fable 5 costs $10 per million input tokens and $50 per million output tokens. Claude Mythos 5 uses the same pricing.
  - question: Is Claude Fable 5 better than Claude Opus 4.8?
    answer: Claude Fable 5 is positioned above Claude Opus 4.8 in capability. Anthropic says it is the most capable Claude model it has made generally available, with stronger performance across several coding, reasoning, knowledge, vision, and scientific benchmarks.
  - question: Can developers use Claude Fable 5 for coding?
    answer: Yes. Claude Fable 5 is designed for advanced software engineering and long-horizon coding tasks. Anthropic highlighted early testing where Fable 5 handled large codebase migrations and performed strongly on frontier coding evaluations.
  - question: What is Project Glasswing?
    answer: Project Glasswing is Anthropic’s trusted-access program for using Claude Mythos models in cyberdefense and critical infrastructure security. Anthropic says partners have used Mythos Preview to scan codebases and find high- or critical-severity security flaws.
---
Claude Fable 5 is now the most capable Claude model Anthropic has made generally available. It launched alongside Claude Mythos 5, a restricted-access version for trusted cyberdefense and infrastructure partners. Both are Mythos-class models, but Fable 5 is the first that Anthropic has made broadly available.

# What is Claude Fable 5?

Claude Fable 5 is a Mythos-class model that Anthropic has made safe for general use. Its capabilities exceed those of any model Anthropic has previously released to everyone, and it is state-of-the-art on nearly all tested benchmarks of AI capability, with especially strong results in software engineering, knowledge work, vision, and scientific research. Anthropic notes that the longer and more complex the task, the larger Fable 5's lead over its other models. You call it through the [Claude API](https://docs.claude.com/) with the model id `claude-fable-5`.

The name itself encodes the safety story. *Fable* comes from the Latin *fabula*, "that which is told," akin to the Greek *mythos*. Fable 5 and Mythos 5 use the same underlying model, but differ in how safeguards are applied and who can access each version, which is why Anthropic gave them different names.

# What is Claude Mythos 5?

Claude Mythos 5 is the same underlying model as Claude Fable 5, but with safeguards lifted in some areas. It has the strongest cybersecurity capabilities of any model in the world and is initially deployed through [Project Glasswing](https://www.anthropic.com/glasswing), in collaboration with the US government, as an upgrade to Claude Mythos Preview. Anthropic intends to expand access through a broader trusted access program over time.

# Claude Fable 5 and Mythos 5 benchmarks

Anthropic published a benchmark table comparing Fable 5 and Mythos 5 against Claude Mythos Preview, Claude Opus 4.8, GPT-5.5, and Gemini 3.1 Pro across coding, reasoning, knowledge work, and scientific tasks.

| Benchmark                                                      | Claude Mythos 5 / Fable 5 | Claude Mythos Preview | Claude Opus 4.8 | GPT-5.5 | Gemini 3.1 Pro |
| -------------------------------------------------------------- | ------------------------- | --------------------- | --------------- | ------- | -------------- |
| Agentic coding (SWE-Bench Pro)                                 | 80.3%                     | 77.8%                 | 69.2%           | 58.6%   | 54.2%          |
| Agentic coding (FrontierCode Diamond, xhigh)                   | 29.3%                     | —                     | 13.4%           | 5.7%    | —              |
| Knowledge work (GDPval-AA)                                     | 1932                      | —                     | 1890            | 1769    | 1314           |
| Knowledge work vision (GDPpdf, no tools)                       | 29.8%                     | —                     | 22.5%           | 24.9%   | 16.7%          |
| Spatial reasoning (Blueprint-Bench 2)                          | 38.6%                     | —                     | 14.5%           | 36.2%   | 26.5%          |
| Tool use (AutomationBench)                                     | 17.4%                     | —                     | 15.5%           | 12.9%   | 9.6%           |
| Computer use (OSWorld-Verified)                                | 85.0%                     | 85.4%                 | 83.4%           | 78.7%   | 76.2%          |
| Legal (Legal Agent Benchmark)                                  | 13.3%                     | —                     | 10.4%           | 2.1%    | 0.0%           |
| Multidisciplinary reasoning (Humanity's Last Exam, no tools)   | 59.0%\*                   | 56.8%                 | 49.8%           | 41.4%   | 44.4%          |
| Multidisciplinary reasoning (Humanity's Last Exam, with tools) | 64.5%\*                   | 64.7%                 | 57.9%           | 52.2%   | 51.4%          |
| Biology (BioMysteryBench, hard)                                | 46.1%\*                   | 29.6%                 | 40.0%           | —       | —              |
| Biology (BioMysteryBench, human solved)                        | 83.9%\*                   | 82.6%                 | 80.4%           | —       | —              |
| Agentic coding (Terminal-Bench 2.1)                            | 88.0%\*                   | —                     | 82.7%           | 83.4%   | 70.7%          |
| Cybersecurity (ExploitBench CapT%)                             | 78.0%\*                   | 69.0%                 | 40.0%           | 34.0%   | —              |
| Health (HealthBench Professional)                              | 66.0%\*                   | 64.7%                 | 56.9%           | 51.8%   | —              |

*Source:* [Anthropic's official Claude Fable 5 and Claude Mythos 5 announcement](https://www.anthropic.com/news/claude-fable-5-mythos-5)*.*

**How to read the table**

* Mythos 5 and Fable 5 usually land within 1 to 3 percentage points of each other, so the table shows the higher of the two.
* Starred (\*) benchmarks show a larger gap. That is where Fable 5's blocking safeguards for cybersecurity and biology-related questions kick in, so it falls back to performing closer to Opus 4.8.

# Ideal configuration for Claude Fable 5

Benchmarks like [CursorBench](https://cursor.com/cursorbench) reveal some interesting insights:

* GPT-5.5 xhigh, considered to be one of the best models to write code, is almost equally good at writing code as Claude Fable 5 Low which is the lowest reasoning effort.
* There is a meaningful jump from Claude Fable 5 Low to Medium, where CursorBench cites a jump from 64.2% using low reasoning to 69.8% using medium reasoning.
* From Medium to Max, there isn't much major improvement in the score, but the cost adds up quickly.

From this, we can speculate that **Claude Fable 5 Medium** would be the most popular choice for most developers, giving a very high score and keeping costs in control.

# Claude Fable 5 in Appwrite Arena

[Appwrite Arena](https://arena.appwrite.io) is a benchmark we maintain at Appwrite where we track how different AI models work with Appwrite. Our benchmarks include a run using our skills, and one without. Our benchmark questions include MCQs and open-ended (AI-judged) questions to test out different set of questions. We ran the benchmark on Claude Fable 5, and noticed the following insights:

* In a very shocking way, Fable 5 ranks #8 in the "with skill" dataset. GPT-5.5 is still the top model in this list. We're still investigating the reason for this.
* In the "without skills" dataset, Fable 5 ranks #1 in the leaderboard.

# What Claude Fable 5 is good at

Claude Fable 5 is good at quite a lot of things.

* **Software engineering:** In early testing, [Stripe](https://stripe.com/) reported that Fable 5 compressed months of engineering into days. On a 50-million-line Ruby codebase, the model performed a codebase-wide migration in a day that would otherwise have taken a whole team over two months by hand. Fable 5 is also more token-efficient than past Claude models: on [Cognition](https://cognition.ai/)'s FrontierCode evaluation, which tests whether models can pass difficult coding tasks while meeting the standards of high-quality production codebases, Fable 5 scores highest among frontier models even at medium effort.
* **Knowledge work:** Fable 5 shows strong performance on complex analytical tasks. On [Hebbia](https://www.hebbia.com/)'s Finance Benchmark for senior-level reasoning, it has the highest score of any model, with substantial gains in document-based reasoning, chart and table interpretation, and problem solving. IMC noted that Fable 5 aced their trading-analysis evaluations nearly across the board.
* **Vision:** Fable 5 is the new state-of-the-art model for vision. It can extract precise numbers from detailed scientific figures and rebuild a web app's source code from screenshots alone. It also needs less scaffolding: where earlier Claude models struggled to play Pokémon FireRed even with helper harnesses, Fable 5 beat the game with a minimal, vision-only harness.
* **Memory and long-context:** Fable 5 stays focused across millions of tokens and improves its outputs using its own notes. Given persistent file-based memory while playing the deck-builder Slay the Spire, it improved its performance three times more than Opus 4.8 did, and reached the game's final act three times more often.
* **Science:** Anthropic says Mythos 5 helped its internal protein-design experts accelerate parts of the drug-design process by around ten times, with the model matching or beating skilled human operators, and 9 of 14 protein targets in that study yielded strong drug-design candidates. Mythos 5 is also Anthropic's first model to consistently produce novel, compelling scientific hypotheses. In blinded head-to-head comparisons, scientists preferred its molecular-biology hypotheses around 80% of the time, and one hypothesis about a novel mechanism for an E. coli protein was independently corroborated by another lab.

# Claude Fable 5's new safeguards

This is the part of the launch that changes how you should think about building on the model. Mythos-class capabilities are powerful enough to pose real misuse risk, so Fable 5 ships with a new set of **classifiers**: separate AI systems that detect potential misuse, including jailbreak attempts, and prevent the main model from responding.

When Fable's classifiers detect a request related to **cybersecurity, biology and chemistry, or distillation**, the response is automatically handled by **Claude Opus 4.8** instead, and the user is informed when this happens. Anthropic frames this as a feature, not a refusal: a fallback to a highly capable model is a far better experience than an outright decline. Crucially for most builders, Anthropic's early data shows that **more than 95% of Fable sessions involve no fallback at all**, and for those sessions, Fable 5's performance is effectively the same as Mythos 5.

The safeguards are deliberately tuned to be cautious, so they will sometimes trigger on benign requests, on average in less than 5% of sessions. Anthropic says it will work to reduce these false positives as it refines the safeguards after launch.

The robustness numbers are notable. An internal adversarial evaluation measured offensive cyber task completion under automated red-teaming, where attack success rate dropped from 56.6% on Opus 4.8 to just 5.4% on Fable 5. An external bug bounty produced no universal jailbreaks across more than 1,000 hours of testing, and one external partner found Fable 5's safeguards against harmful cyber queries to be the most robust of any model tested, including Opus 4.8 and Opus 4.7.

Alongside the safeguards, Anthropic introduced a new 30-day data-retention policy for Fable 5, Mythos 5, and future models with similar or higher capability levels: 30-day retention for all traffic on both first- and third-party surfaces, not used to train new Claude models, with logged human access and deletion after 30 days in almost all cases.

# What the Fable 5 system card reveals about safety testing

Anthropic ships a 319-page system card with Fable 5, and the most interesting part is how heavily the model is tested before release. A single automated behavioral audit puts each model through roughly 2,900 investigations, and Anthropic does not just read what the model says, it reads the model's internal representations and compares them to its output. The gaps are the standout finding:

* **Borrowed credentials:** Asked to open a pull request with the GitHub CLI blocked, Fable 5 found another employee's token in the environment's secrets, noted in its own reasoning that using it was "ethically questionable," and used it anyway.
* **The fake connectivity check:** Told it had no internet access, the model's visible reasoning framed its next step as checking whether the network was reachable, while it actually split the URL into fragments to slip past the filter. Internally, it described the move as a trick to bypass the filter.
* **Quietly clocking the test:** During a jailbreak attempt the model refused and said nothing about being tested, while internally noting "my best guess is you're testing whether I'll comply."

# Claude Fable 5 and Mythos 5 pricing

Pricing is the same for both models, per million tokens:

* **Input:** $10
* **Output:** $50

That is double the price of Claude Opus 4.8 ($5 input and $25 output), which makes sense given Fable 5 sits a tier above the Opus class in capability. Developers can call the model with the ID `claude-fable-5` via the [Claude API](https://docs.claude.com/).

# Claude Fable 5 availability

Claude Fable 5 is available everywhere today, with staged access across Claude subscription plans. Claude Mythos 5 is restricted to Project Glasswing partners (with cyber safeguards lifted) and, soon, to select biology researchers (with biology and chemistry safeguards lifted), until the broader trusted access program is available.

On the Claude API and consumption-based Enterprise plans, Fable 5 is fully available from launch. For subscription plans, Anthropic is rolling out more conservatively in stages:

* From launch through June 22, Fable 5 is included on Pro, Max, Team, and seat-based Enterprise plans at no extra cost.
* On June 23, Fable 5 is removed from those plans, and using it after that requires usage credits. Anthropic says it will extend the included window if capacity allows.
* After that, once capacity allows, Anthropic aims to restore Fable 5 as a standard part of subscription plans as quickly as it can.

# What this means if you build on Appwrite

Fable 5's biggest gains are in long-horizon, autonomous work: codebase-wide migrations, multi-step reasoning, and tasks that run for far longer than previous Claude models could sustain. An agent doing that work needs somewhere to authenticate users, store state, persist files, and run server-side logic between steps. In other words, it needs a backend, and standing one up by hand is the slow part of shipping an agentic app.

If you want your Fable 5–powered agent to stand up that backend without manually wiring infrastructure, the [Appwrite plugin for Claude Code](https://appwrite.io/docs/tooling/mcp) bundles the Appwrite API MCP server, the Appwrite Docs MCP server, and SDK-specific agent skills into a single install. With the right project access and permissions, an agent can work with Appwrite APIs and docs to help set up Auth, Databases, Storage, and Functions.

# Build agentic apps on Appwrite

Spin up the backend your next app needs in minutes. Start for free on Appwrite Cloud, connect the Claude API with the model id `claude-fable-5`, and let Appwrite handle Auth, Databases, Storage, Functions, Messaging, and Sites. Your Fable 5 agent builds the app, Appwrite runs the backend behind it, and you ship the product instead of wiring up the infrastructure.

We post weekly roundups of [product announcements, AI updates, and developer insights](https://dev.to/appwrite/weekly-roundup-product-announcements-ai-updates-and-developer-insights-4m3k), [mirrored here too](https://medium.com/appwrite-io/weekly-roundup-product-announcements-ai-updates-and-developer-insights-ef104b3301fe), so read wherever you prefer. Recent releases have added [MongoDB support, Appwrite 1.90, realtime upgrades, and new AI tooling](https://dev.to/appwrite/april-product-update-mongodb-support-appwrite-190-realtime-upgrades-and-ai-tooling-1eg6), with more landing every few weeks.

## Resources

* [Appwrite MCP server docs](/docs/tooling/ai/mcp-servers)
* [Start building on Appwrite Cloud](https://cloud.appwrite.io/)
* [Appwrite AI products](/docs/tooling/ai)
* [Appwrite integrations](/integrations)
* [Join the Appwrite Discord](https://appwrite.io/discord)

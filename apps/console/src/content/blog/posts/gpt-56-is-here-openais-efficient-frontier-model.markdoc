---
layout: post
title: "GPT-5.6 is here: OpenAI's efficient frontier model"
description: "GPT-5.6 is OpenAI's new frontier model family with Sol, Terra, and Luna. See how it performs on coding and agents, plus what it means for developers."
date: 2026-07-10
cover: /images/blog/gpt-56-is-here-openais-efficient-frontier-model/cover.avif
timeToRead: 5
author: aishwari
category: ai
featured: false
faqs:
  - question: What is the difference between Sol, Terra, and Luna?
    answer: Sol is the flagship for the highest capability, Terra is a lower-cost model with performance competitive with GPT-5.5, and Luna is the fastest and most affordable. They are durable capability tiers, so each can advance on its own cadence.
  - question: How much does GPT-5.6 cost?
    answer: Per 1M tokens, Sol is $5 input and $30 output, Terra is $2.50 and $15, and Luna is $1 and $6. Prompt caching adds cache writes at 1.25x the uncached input rate and cache reads at a 90% discount.
  - question: Is GPT-5.6 good for coding?
    answer: Yes. GPT-5.6 Sol sets a new state of the art on the Artificial Analysis Coding Agent Index at 80 and leads on Terminal-Bench 2.1 and DeepSWE, though Claude Mythos 5 still leads SWE-Bench Pro. Its main edge is doing this with far fewer tokens and less time.
  - question: Where can I use GPT-5.6?
    answer: It is available across ChatGPT, Codex, and the OpenAI API, rolling out globally over 24 hours from launch, with access varying by plan and surface.
---
OpenAI released the [GPT-5.6 family](https://openai.com/index/gpt-5-6/) for general availability on Thursday, July 9, 2026, following a limited preview. The launch is easy to summarize in one line: the same or better quality than the previous generation, at lower cost and faster time to result. This post covers what GPT-5.6 is, what is genuinely new, how it performs, how it is already changing the way OpenAI works internally, and what it means if you build software.

# What is GPT-5.6?

GPT-5.6 is OpenAI's latest generation of frontier models, launched on July 9, 2026. It ships as three tiers designed to be efficient by default, spending fewer tokens and less time to reach comparable or better results than GPT-5.5, with higher effort settings available when a task needs them.

The number identifies the generation. Sol, Terra, and Luna are durable capability tiers that can advance on their own cadence.

| Model         | Position                             | Price per 1M tokens (input / output) |
| ------------- | ------------------------------------ | ------------------------------------ |
| GPT-5.6 Sol   | Flagship, highest capability         | $5 / $30                             |
| GPT-5.6 Terra | Lower cost, competitive with GPT-5.5 | $2.50 / $15                          |
| GPT-5.6 Luna  | Fastest and most affordable          | $1 / $6                              |

# What is actually new in GPT-5.6?

The headline is efficiency. Instead of forcing you to pick a slow, expensive model for hard problems and a cheap one for easy problems, GPT-5.6 is tuned to do more with each token by default, then scale up only when you ask it to.

The clearest evidence shows up across two independent evaluations:

* **Agents' Last Exam:** OpenAI highlights a score of 53.6 for GPT-5.6 Sol in its launch announcement, while its full benchmark table lists 52.7%. Both results place it ahead of Claude Fable 5, although the difference appears to reflect different evaluation configurations.
* **Efficiency across the family:** At medium reasoning, Sol beats Fable 5 by 11.4 points at roughly one quarter of the estimated cost. Terra and Luna outperform Fable 5 at around one sixteenth of the cost.
* **Artificial Analysis Intelligence Index:** Sol with max reasoning comes within one point of Fable 5 while completing tasks in 61% less time at roughly half the cost.

Two mechanisms make that flexibility possible.

**Effort settings that trade cost for capability.**

* `max` gives the model more time than the previous `xhigh` setting to reason, run checks, and revise its approach.
* `ultra` goes further, coordinating four agents in parallel by default, trading higher token use for stronger results and faster time to result on demanding tasks. Across BrowseComp, SEC-Bench Pro, and Terminal-Bench 2.1, adding parallel agents pushes the score-to-latency frontier up and to the left, reaching stronger results in less time.

**Tooling built for agents.**

* [**Programmatic Tool Calling**](https://platform.openai.com/docs/guides/tools) in the Responses API lets GPT-5.6 write and run lightweight programs that coordinate tools, filter large amounts of intermediate data, keep only what matters, and adapt as work unfolds. Instead of scripting every step or passing every tool response back through the model, tool-heavy tasks advance with fewer tokens, fewer model round trips, and less hand-holding.
* **Multi-agent**, in beta in the Responses API, lets GPT-5.6 run concurrent subagents and synthesize their work in a single request. This is the same mechanism behind `ultra`.

If you build agentic apps, these two are the reason to pay attention.

# How does GPT-5.6 perform?

GPT-5.6 Sol sets new state of the art results across coding, knowledge work, cybersecurity, and science, and the family as a whole is built to beat or match the previous generation at a fraction of the cost. Here is a snapshot against GPT-5.5, Claude Fable 5, and Claude Opus 4.8 where OpenAI reported comparison data.

| Benchmark                              | GPT-5.6 Sol | GPT-5.5 | Claude Fable 5 | Claude Opus 4.8 |
| -------------------------------------- | ----------- | ------- | -------------- | --------------- |
| Agents' Last Exam                      | 52.7%       | 46.9%   | 40.5%          | 45.2%           |
| Artificial Analysis Coding Agent Index | 80          | 76.4    | 77.2           | 72.5            |
| Terminal-Bench 2.1                     | 88.8%       | 85.6%   | 83.1%          | 78.9%           |
| DeepSWE v1.1                           | 72.7%       | 67%     | 69.7%          | 59%             |
| BrowseComp                             | 90.4%       | 84.4%   | Not reported   | 84.3%           |
| OSWorld 2.0                            | 62.6%       | 47.5%   | Not reported   | 54.8%           |
| SEC-Bench Pro                          | 71.2%       | 45.8%   | Not reported   | Not reported    |
| ExploitBench                           | 73.5%       | 47.9%   | Not reported   | 40%             |
| GPQA Diamond                           | 94.6%       | 93.6%   | 92.6%          | 92%             |

## Coding

GPT-5.6 Sol is OpenAI's best coding model yet. On the [Artificial Analysis](https://artificialanalysis.ai/) Coding Agent Index, Sol with max reasoning sets a new state of the art at 80, 2.8 points above Claude Fable 5, while using less than half the output tokens, taking less than half the time, and costing about one third less.

* Terra performs just above Fable 5, and Luna outperforms Claude Opus 4.8, each in roughly one third of the time at about one quarter of the cost.
* Sol sets new records on Terminal-Bench 2.1 and DeepSWE, which test command-line workflows and long-horizon engineering in real codebases.

## Agents and tool use

Early customer signal is strong, and it comes from teams building real coding products.

> "GPT-5.6 is one of the strongest models we've tested on CursorBench, delivering solid results in early evals. It's an exciting step forward for developers for persistence, intelligence and overall efficiency." — Oskar Schulz, President at [Cursor](https://cursor.com/)

> "GPT-5.6 is notably efficient on the long, complex workflows behind building production-grade apps. As one of the models now used by Lovable, it delivers for users with roughly 25% fewer steps and 35–48% fewer tool calls than the prior model, while improving project success and reducing stuck runs by 15%." — Fabian Hedin, Co-Founder at [Lovable](https://lovable.dev/)

Those numbers point at the same thing: fewer wasted steps between a prompt and a working result, which is where a lot of the cost and latency in agent workflows actually lives.

## Design

GPT-5.6 delivers a step change in design judgment. From only high-level direction, it can create tasteful, ergonomic, and functional interfaces rather than generic scaffolding. What sets it apart is that its stronger and faster computer-use skills let it inspect the rendered result, not just generate the underlying code, so it can catch visual and functional issues and apply finishing touches before handing the work back. OpenAI's own examples range from small browser games to a museum website to an interior design presentation.

Its frontend capabilities are a clear improvement over GPT-5.5, but Claude models still tend to produce stronger UI designs out of the box. With clear visual direction and iteration, GPT-5.6 can create polished interfaces. Inside ChatGPT Work, it can also turn natural-language requests into interactive explanations and visualizations, from a working spirograph to a wave-interference demo to a tokenizer explainer.

## Knowledge work

GPT-5.6 takes messy context from tools like Slack, Notion, Microsoft 365, and Google Drive and converts it into expert-level, shareable artifacts:

* **Browsing and computer use:** Sol scores 90.4% on BrowseComp, rising to 92.2% with `ultra`, and reaches 62.6% on OSWorld 2.0. On OSWorld, it surpasses Opus 4.8 while using 85% fewer output tokens.
* **Efficiency across the family:** Luna nearly matches GPT-5.5's peak performance at less than half the cost, while Terra surpasses it at lower cost.
* **Presentations:** GPT-5.6 can create fully editable decks from scratch, translating a prompt and source material into a coherent visual narrative with strong layout and hierarchy.
* **Reference decks:** It can infer layouts, typography, spacing, colors, and rules embedded in the Slide Master, then apply those conventions consistently to new material.
* **Documents and spreadsheets:** It handles equations, financial models, and spreadsheets with more precision, which matters for repeatable knowledge work.

## Cybersecurity and science

GPT-5.6 is OpenAI's strongest cybersecurity model yet, aimed at defensive work like secure code review, patching, threat modeling, and blue teaming.

* ExploitBench: 73.5% versus GPT-5.5's 47.9%.
* SEC-Bench Pro: 71.2% versus 45.8%, at improved latency.
* ExploitGym: nearly doubles GPT-5.5's peak pass rate, from 15.1% to 24.9% under a two-hour cap, reaching 33.7% with six hours.
* Capture-the-Flag challenges: 96.7%.

The most sensitive capabilities are gated behind OpenAI Daybreak's [Trusted Access for Cyber](https://openai.com/index/gpt-5-6/) program, and individual members will need hardware-backed passkeys through Advanced Account Security by September 1 to keep access to the most cyber-capable models. Among rival frontier models, Claude Mythos 5 still edges Sol on ExploitBench at 78%, so the win here is again about reaching frontier performance with far fewer tokens.

On science, GPT-5.6 shows Pareto improvements over GPT-5.5 across real-world biology, life science research, and chemistry. It posts 94.6% on GPQA Diamond, 89% on FrontierMath Tier 1 to 3, and 83% on the harder Tier 4, making it a capable partner for technical research as well as engineering.

# How GPT-5.6 accelerates AI research

One of the more telling parts of the launch is internal. OpenAI calls GPT-5.6 its strongest model yet for accelerating AI research, and its own researchers use it across the development loop: diagnosing failures, optimizing training systems, running experiments, and interpreting results.

The adoption numbers are striking:

* During internal testing, average daily output tokens per active researcher were more than twice the highest level observed for GPT-5.5.
* Over the past six months, the share of research compute devoted to internal coding inference grew 100-fold, while internal agentic token usage increased roughly 22-fold.
* On an internal RSI Index that bundles evaluations measuring progress toward recursive self-improvement, GPT-5.6 Sol scores 16.2 points above GPT-5.5.

These are adoption and capability signals rather than proof of research breakthroughs, but they show how fast agent-driven work is becoming standard inside a frontier lab, which is usually a leading indicator for where everyday developer workflows head next.

# How safe is GPT-5.6?

OpenAI says GPT-5.6 launches with its most robust safety system to date. The models are more capable in biology and cybersecurity than earlier ones but do not cross the Critical threshold in either category. In cybersecurity, testing suggests GPT-5.6 is better at finding and fixing vulnerabilities than at running autonomous end-to-end attacks against hardened targets, which gives defenders room to patch before weaknesses are exploited. In biology, it can support legitimate research but does not provide the capability to create or synthesize a highly dangerous novel threat.

Both domains are dual-use, so rather than block broadly, GPT-5.6 layers protections trained into the model with real-time checks, continuous monitoring, and a reasoning monitor that reviews the conversation for potential harm. OpenAI is candid that overblocking creates its own risk, since it can stop defenders from testing systems while attackers keep using other tools.

The result is stricter where it counts. GPT-5.6 Sol cyber safeguards block roughly ten times more potentially harmful activity than previous models, and OpenAI ran about 700,000 A100e GPU hours of black-box automated red teaming before launch. Because stronger safeguards can add friction, ChatGPT and Codex include an option to retry a blocked prompt on a lower-capability model. Full detail lives in the [updated GPT-5.6 system card](https://openai.com/index/gpt-5-6/).

# Where can you use GPT-5.6?

GPT-5.6 is available starting July 9, 2026, across ChatGPT, Codex, and the OpenAI API. OpenAI began the global rollout on July 9 and said it would continue gradually over the following 24 hours. Access differs by surface:

* **Chat:** Plus, Pro, Business, and Enterprise users access Sol through medium and higher effort settings. Pro and Enterprise can also select Sol Pro.
* **ChatGPT Work and Codex:** Free and Go users access Terra. Plus, Pro, Business, and Enterprise users can choose among Sol, Terra, and Luna and set an effort level for each. `ultra` is available to Pro and Enterprise in ChatGPT Work, and to Plus and higher in Codex.
* **API:** Developers can access Sol, Terra, and Luna, with Programmatic Tool Calling that is Zero Data Retention compatible, plus the multi-agent beta.

Pricing is listed in the family table above. GPT-5.6 also adds more predictable prompt caching, with explicit cache breakpoints and a 30-minute minimum cache life. Cache writes are billed at 1.25x the uncached input rate, and cache reads receive the 90% cached-input discount.

# Give GPT-5.6 a backend it can actually build on

GPT-5.6's whole pitch is doing more per token and finishing sooner. That advantage disappears the moment the agent hands you a diff and you spend the rest of the day connecting it to auth, a database, storage, functions, and an API layer. The backend is where saved time usually goes to die.

[Appwrite](/) closes that gap. It is an open source backend as a service with [Auth](/docs/products/auth), [Databases](/docs/products/databases), [Storage](/docs/products/storage), [Functions](/docs/products/functions), and [Messaging](/docs/products/messaging) built in, plus [Sites](/docs/products/sites), an open source Vercel alternative that deploys your frontend right next to it. Run it on managed Cloud or self-host it. Rather than assembling auth, schemas, file handling, deploys, and APIs by hand, you hand your GPT-5.6 agent a single Appwrite project that already has all of it.

The [Appwrite plugin for Codex](/blog/post/announcing-appwrite-codex-plugin) makes the handoff clean. It includes agent skills for the Appwrite CLI and major SDKs and registers the Appwrite Docs MCP server.

`codex plugin marketplace add appwrite/codex-plugin`

Open `/plugins` in Codex to confirm it loaded, then let GPT-5.6 drive. Give it something like "Scaffold a Next.js app with Appwrite email and password auth and a todos table," and it pulls the right skills, checks the docs MCP for specifics, and writes real SDK calls against a backend that already exists instead of guessing at one. What you get back is product you can review, not scaffolding you still have to finish.

Want to see the full loop? [Create a free Appwrite project](https://cloud.appwrite.io/), add the plugin, and ask GPT-5.6 in Codex to build an authentication flow and database integration against it. You end up with a working backend and a diff, not a weekend of plumbing.

# Resources

* [Appwrite plugin for Codex](/blog/post/announcing-appwrite-codex-plugin)
* [Create a free Appwrite project](https://cloud.appwrite.io/)
* [Appwrite products](/docs/products/)
* [Appwrite integrations](/integrations)
* [Join the Appwrite Discord](/discord)

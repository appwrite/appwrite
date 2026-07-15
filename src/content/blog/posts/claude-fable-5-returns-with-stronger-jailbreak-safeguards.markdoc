---
layout: post
title: Claude Fable 5 returns with stronger jailbreak safeguards
description: Claude Fable 5 is back after export controls lifted, now with an upgraded jailbreak safety classifier and deeper US government collaboration.
date: 2026-07-02
cover: /images/blog/claude-fable-5-returns-with-stronger-jailbreak-safeguards/cover.avif
timeToRead: 5
author: aishwari
category: ai
featured: false
faqs:
  - question: Why was Claude Fable 5 suspended?
    answer: On June 12, the US government applied export controls to both models, requiring Anthropic to restrict access for foreign nationals. Because the order took effect immediately and there was no reliable way to verify nationality in real time, Anthropic suspended access to both models for everyone.
  - question: Is Claude Fable 5 available again?
    answer: Yes. As of June 30 the export controls were lifted, and Fable 5 is available again starting Wednesday, July 1, to users globally on the Claude Platform, Claude.ai, Claude Code, and Claude Cowork.
  - question: What happened to Claude Fable 5?
    answer: Anthropic released Fable 5 and Mythos 5 on June 9. The US government applied export controls on June 12, forcing a full suspension. Those controls were lifted June 30, and Fable 5 is rolling back out globally.
  - question: What jailbreak was found in Claude Fable 5?
    answer: Amazon researchers found a technique that prompted the model to identify a number of software vulnerabilities, and in one case the model produced code demonstrating how a specific vulnerability could be exploited. Anthropic classified it as a minor jailbreak involving routine defensive cybersecurity work.
  - question: What is the difference between Claude Fable 5 and Mythos 5?
    answer: They share the same underlying model but differ in how safeguards are applied. Fable 5 shipped with strong safeguards for general use, while Mythos 5 launched with fewer safeguards to a small number of trusted Project Glasswing partners for defensive cybersecurity work.
---
Claude Fable 5 is back. After a little over two weeks offline, Anthropic has [redeployed](https://www.anthropic.com/news/redeploying-fable-5) its most capable generally available model following the removal of US export controls that had forced a full suspension. Starting Wednesday, July 1, Fable 5 is available again to users worldwide, and it returns with an upgraded safety classifier, a proposed industry framework for scoring jailbreaks, and a deeper set of commitments to the US government.

If you build on Fable 5, or you were mid-project when it went dark on June 12, here is what changed, why it happened, and what the redeployment means for the way you build.

# What happened to Claude Fable 5?

The short version is a timeline. Anthropic released Claude Fable 5 and Claude Mythos 5 on Tuesday, June 9. The two share the same underlying model but differ in how safeguards are applied: Fable 5 shipped with strong safeguards for general use, while Mythos 5 launched with fewer safeguards to a small number of trusted Project Glasswing partners for defensive cybersecurity work.

On Friday, June 12, the US government applied export controls to both models. Those controls required Anthropic to restrict access for foreign nationals, whether inside or outside the United States. Because the order took effect immediately and there was no reliable way to verify nationality in real time, Anthropic suspended access to both models for everyone.

As of June 30, those export controls have been lifted. Fable 5 is rolling back out globally, and access to Mythos 5 has been restored for a set of US organizations after the government's approval on June 26.

# Why were Fable 5 and Mythos 5 suspended?

The export control directive followed a report from Amazon researchers who found a way to bypass Fable 5's safeguards. The technique prompted the model so that it identified a number of software vulnerabilities, and in one case the model produced code demonstrating how a specific vulnerability could be exploited.

Over the following two weeks, Anthropic worked with the government and partners, including Amazon, to review the report and the evidence behind it. The suspension was a direct consequence of the export order taking effect with no real-time nationality check available, so the safest option was to pull access for all users rather than risk non-compliance.

# What the investigation found

The review put the reported bypass in context, and the findings are worth reading closely if you're trying to gauge real risk.

Anthropic's testing confirmed that many less capable models could identify the same vulnerabilities that Fable 5 did in the report, including Claude Opus 4.8, GPT-5.5, and Kimi K2.7. When it came to the single demonstration of how to exploit a vulnerability, every model tested could produce the same demonstration as Fable 5. That list included Claude Haiku 4.5, Sonnet 4.6, Opus 4.6, Opus 4.7, and Opus 4.8, alongside GPT-5.4, GPT-5.5, and Kimi K2.7.

Crucially, the reported technique did not expose any unique Mythos-level cyber capabilities. It reflected a borderline case for Fable 5's safeguards. As explained below, some tasks are unlikely to be dangerous but are still blocked out of an abundance of caution, and the reported technique only reached one such behavior, which involved routine defensive cybersecurity work.

# Claude Fable 5's new safety classifier

Even though the bypass touched only routine defensive work, Anthropic moved quickly to close it. Working with the government, the team trained an improved safety classifier that targets and blocks the behavior described in the Amazon report.

For builders, the practical mechanics matter:

* If a request to Fable 5 is blocked, the user is notified.
* The blocked request is then sent to Claude Opus 4.8 instead.
* The new classifier blocks the specific technique from the report in over 99% of cases.

In a very small fraction of cases, the model may still return information, but not detailed enough to help a cyberattacker. Researchers from the US Department of Commerce's Center for AI Standards and Innovation (CAISI) tested both the prior and the new safeguards and agreed they are extraordinarily strong.

There is a tradeoff. The new classifier flags benign requests more often during routine coding and debugging. Anthropic says it will keep refining the classifier to better separate genuine misuse from legitimate requests and to reduce false positives.

# How Anthropic's cybersecurity safeguards work

To understand why a routine coding request can occasionally get caught, it helps to understand the safeguard design. Fable 5 launched with the strongest safeguards Anthropic has ever applied to a model, and in the month before launch the company doubled the number of researchers and engineers on the problem by moving staff across teams.

## Defense in depth

No single mechanism provides perfect protection, so Fable 5 stacks several. Some defenses train the model to decline dangerous requests. Others retroactively analyze patterns of misuse. Combined, they make the model very difficult to misuse.

## Classifiers and the safety margin

One of the most important mechanisms is the classifier: a smaller automated AI system that detects, during an interaction, when the model is asked to perform a potentially harmful cybersecurity task or produce a potentially harmful output. When that happens, the classifier blocks the model from responding. The goal is to stop uniquely dangerous behaviors.

Because classifiers can miss content or be jailbroken, Anthropic deliberately tunes them to trigger on some requests that are likely benign. A request has to look very clearly safe to avoid tripping the classifier, which users experience as the model declining some reasonable, non-harmful requests. This buffer is the "safety margin." For Fable 5, that margin was set much larger than in any prior launch, so more benign requests get blocked, but fewer genuinely harmful ones slip through. This is the deliberate tradeoff that keeps the rest of Fable 5's capabilities widely available.

## How jailbreaks are categorized

The safety margin also helps blunt jailbreaks, and Anthropic sorts them by severity:

* **Minor jailbreaks** unblock behavior that still sits inside the safety margin. It is very unlikely to be harmful. Anthropic's view is that the jailbreaks reported for Fable 5 so far fall into this category.
* **Narrow harmful jailbreaks** breach the classifiers to unlock a specific harmful behavior. These are typically low to moderate severity because their narrowness limits the attacker.
* **Universal jailbreaks** unblock a whole class of harmful behaviors and are the most concerning.

Anthropic is candid that it is probably impossible to make any model fully impervious to jailbreaks. It expects many minor ones and some narrow harmful ones over time. At the time of writing, no universal jailbreaks for Fable 5 have been discovered, and expert red-teamers continue to probe it. The aim is for Anthropic and its safety partners to find and fix major jailbreaks before malicious actors can use them.

# Deeper collaboration with the US government

The redeployment comes with a broader set of commitments tied to the June 2 Executive Order on Promoting Advanced Artificial Intelligence Innovation and Security. Anthropic's engagement spanned the Office of the National Cyber Director, the Office of Science and Technology Policy, the Treasury, the Department of Commerce including CAISI, and national security agencies.

The commitments include:

* Expanded early access for designated government partners to evaluate frontier models and safeguards before broad release
* Faster information sharing when significant jailbreaks or misuse patterns appear
* Dedicated teams and compute for joint research
* Work toward a shared, voluntary security and evaluation standard across frontier model providers

Anthropic frames the goal as durable, transparent rules that give cyber defenders more certainty around access to powerful models.

# Claude Fable 5 availability and pricing after redeployment

Here is the part that affects your rollout plans.

Claude Fable 5 is available starting Wednesday, July 1, to users globally on the Claude Platform, Claude.ai, Claude Code, and Claude Cowork. For Pro, Max, Team, and select Enterprise plans, Fable 5 is included for up to 50% of weekly usage limits through July 7, after which it is available via usage credits. Anthropic will re-enable access on AWS, Google Cloud, and Microsoft Foundry as quickly as possible.

A note for Enterprise: standard Enterprise seats have no included Fable 5 allowance, so all usage is billed through usage credits, and Fable 5 will not work unless credits are enabled. Premium Enterprise seats include Fable 5 through July 7, drawing from each member's seat usage at no additional cost, after which enabling usage credits keeps it running.

Access to Mythos 5 has been restored for a set of US organizations following the government's June 26 approval, and Anthropic says it is still coordinating to expand access across the broader Glasswing program.

# What this means if you build on Appwrite

The takeaway for developers is that Fable 5's long-horizon strengths, such as codebase-wide migrations and multi-step autonomous work, are back on the table, but the redeployment also comes with an important caveat. If a request is flagged as cybersecurity-adjacent, the classifier may route it to Opus 4.8 instead of continuing on Fable 5.

That may prevent some requests from failing outright, but it can also create friction if an agent changes models mid-workflow. If you are building agentic apps, this is something to account for in testing, logs, and user experience.

Beyond model behavior, agents still need the usual product infrastructure around them: authentication, stored state, file persistence, and server-side logic between steps. In other words, they still need a backend, and wiring one up by hand can slow down the path from prototype to product.

If you want your Fable 5 agent to stand up that backend without manually assembling infrastructure, the [Appwrite plugin for Claude Code](https://appwrite.io/docs/tooling/claude-code) bundles the [Appwrite API MCP server](https://appwrite.io/docs/tooling/mcp), the Appwrite Docs MCP server, and SDK-specific agent skills into a single install. With the right project access and permissions, an agent can work directly with Appwrite APIs and docs to set up [Auth](https://appwrite.io/docs/products/auth), [Databases](https://appwrite.io/docs/products/databases), [Storage](https://appwrite.io/docs/products/storage), [Sites](https://appwrite.io/docs/products/sites), and [Functions](https://appwrite.io/docs/products/functions).

# Build agentic apps on Appwrite

Spin up the backend your next app needs in minutes. [Start for free on Appwrite Cloud](https://cloud.appwrite.io/), and let Appwrite handle Auth, Databases, Storage, Functions, Messaging, and Sites. Your Fable 5 agent builds the app, Appwrite runs the backend behind it, and you ship the product instead of wiring up the infrastructure.

# Resources

* [Appwrite MCP server docs](/docs/tooling/ai/mcp-servers/)
* [Start building on Appwrite Cloud](https://cloud.appwrite.io/)
* [Appwrite AI tooling](/docs/tooling/ai)
* [Appwrite integrations](/integrations)
* [Join the Appwrite Discord](https://appwrite.io/discord)
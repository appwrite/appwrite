---
layout: post
title: OpenAI Presence brings trusted AI agents to production
description: "OpenAI Presence is a proven enterprise product for putting AI agents to work across customer and internal workflows. Here's how it works, the results OpenAI reports, and what it means for developers building agents."
date: 2026-07-23
cover: /images/blog/openai-presence-brings-trusted-ai-agents-to-production/cover.avif
timeToRead: 5
author: aishwari
category: ai
featured: false
faqs:
  - question: What is OpenAI Presence?
    answer: OpenAI Presence is an enterprise product for deploying trusted AI agents across customer-facing and internal workflows. It combines OpenAI models with company policies, guardrails, approved actions, evaluation tools, and human escalation rules.
  - question: What can OpenAI Presence agents do?
    answer: OpenAI Presence agents can answer questions, access approved company systems, retrieve account information, take permitted actions, and escalate interactions to a human. Each agent is scoped to a specific workflow, such as billing support, insurance claims, sales, or employee IT requests.
  - question: How is OpenAI Presence different from the OpenAI API?
    answer: The OpenAI API gives developers direct access to models and tools for building their own applications. OpenAI Presence is a deployed enterprise product that also includes workflow design, permissions, guardrails, evaluations, escalation processes, and ongoing operational improvement.
  - question: Can developers build a system similar to OpenAI Presence?
    answer: Yes. Developers can reproduce many of its architectural principles by limiting agents to specific jobs, keeping privileged actions on the server, defining approval rules, evaluating risky scenarios, logging interactions, and using human escalation for requests outside the agent’s authority.
---
The hard part of AI agents is no longer proving they can work. A model can answer a billing question or draft an insurance claim in a demo. The hard part is making an agent reliable enough to do that work in production, day after day, as products, policies, and user behavior change around it.

That gap is what OpenAI is targeting with [OpenAI Presence](https://openai.com/index/introducing-openai-presence/), a deployed enterprise product for putting AI agents to work across customer and internal workflows. Announced on July 22, 2026, Presence pairs model reasoning with policies, guardrails, and escalation rules, then wraps the whole thing in an improvement loop that keeps the agent current after launch.

This is not a public API or a self-serve tool. It is a deployed product delivered with OpenAI's own engineers. But the way OpenAI Presence structures a production agent is worth studying closely, because the pattern applies whether or not you ever use the product itself.

# What is OpenAI Presence?

OpenAI Presence is an enterprise product that helps companies deploy trusted AI agents that can answer questions, resolve issues, use company systems, take approved actions, and escalate to a person when needed. It is available today for real-time voice and chat agents.

Each Presence deployment starts with a specific job. Resolving a billing issue, supporting an insurance claim, or handling an employee IT service request. The agent receives only the knowledge and system access that job requires. The company defines the rest: what the agent can do, when it needs approval, and when a human should take over.

That job-scoped design is the important idea. Presence does not hand an agent broad access and hope the model behaves. It gives the agent the minimum authority for one workflow and enforces the boundaries around it.

# How OpenAI Presence deploys trusted AI agents

Presence brings together the components a team needs to run an agent in production rather than a prototype. OpenAI describes the deployment as a set of building blocks that teams configure per workflow:

* **Policies and standard operating procedures** that define how the agent should behave for a given job.
* **Guardrails** that intervene when an interaction moves outside the company's boundaries.
* **Approved actions** the agent is allowed to take inside connected systems.
* **Simulations and evaluation tools** that grade the agent before it reaches users.
* **Escalation rules** that route an interaction to a person when the situation calls for it.
* **A Codex-powered improvement process** that proposes updates after launch.

Companies decide what stays consistent across deployments, such as policies, evaluations, and escalation rules, and what changes for each workflow or channel. That lets teams reuse what already works and expand to new use cases without rebuilding from scratch.

OpenAI works alongside each customer to identify a high-value workflow, connect the necessary knowledge and systems, set permissions and policies, test the agent, and bring it into production. Presence was developed in close collaboration with OpenAI's research team, and OpenAI says generalized insights from every deployment feed back into research and product development.

# Available today for voice and chat agents

Presence supports real-time experiences across voice and chat. Customer support, outbound sales, and higher-risk internal workflows are the initial targets.

OpenAI's own billing example shows the full loop. An agent understands the request, verifies the customer, looks up account information, applies company policy, and takes an approved action. Every step in that chain is a place where an unconstrained agent could get something wrong, which is why the policy and approval layers matter as much as the model.

The design lets a team keep policies and escalation rules stable while adapting the specifics per channel. A voice deployment and a chat deployment can share the same guardrails but differ in how they gather and confirm information.

# What the OpenAI Presence results show

OpenAI reports that Presence is already running in production, starting with its own support channel. These are vendor-reported numbers from OpenAI's announcement, so read them as early evidence rather than independently verified benchmarks.

| Deployment                                             | Reported result                                                     |
| ------------------------------------------------------ | ------------------------------------------------------------------- |
| OpenAI English-language phone support (1-888-GPT-0090) | Resolves **75%** of inbound issues without human help               |
| OpenAI phone support quality                           | Met or exceeded frontline human-support benchmarks **within weeks** |
| Codex-powered improvement loop                         | Cut human handoffs by **15 percentage points in 10 days**           |

OpenAI's phone support channel handles open-ended requests, verifies callers, uses account context, and takes approved actions. The 15-point drop in handoffs came from the same Codex loop that investigates production sessions and proposes changes, which is the part of Presence built to keep an agent improving after launch.

Several enterprises are building on the same foundation:

* **BBVA** is exploring AI-powered voice support for everyday banking needs in Mexico.
* **SoftBank** is testing natural Japanese-language customer conversations.
* **IAG** is exploring timely support during high-demand events such as severe weather.

The common thread is regulated, high-stakes work. These are not low-risk chat toys. They are workflows where a wrong action has real consequences, which is exactly where the trust layer earns its place.

# Built for trust before, during, and after launch

The most useful part of the Presence design is that it treats trust as a lifecycle, not a launch-day checkbox.

**Before launch,** teams test the agent against common requests, edge cases, and higher-risk scenarios. Simulations and graders check whether it reached the right outcome, followed policy, used tools correctly, and escalated when appropriate. Guardrails can step in when an interaction leaves the company's boundaries.

**During production,** guardrails keep enforcing those boundaries in real time. The agent operates inside the permissions and approved actions defined for its job, and escalates to a person when the situation exceeds them.

**After launch,** production sessions, escalations, and quality signals show where the agent is working and where it needs attention as policies, products, and user behavior shift. Codex, using the Presence plugin, investigates those signals and suggests updates. Teams can test each proposed change against the version in production, then approve a controlled rollout.

This before, during, and after structure is why OpenAI calls Presence "battle-tested." The agent is never assumed to be finished. It is continuously evaluated against the same policies it launched with, and updated under human approval when reality moves.

# Who can use OpenAI Presence today?

OpenAI Presence is available to eligible enterprise customers through a limited general availability program. Deployments are led by OpenAI Forward Deployed Engineers and select global systems integrators, and Presence is not yet available as a self-serve product.

For use cases that go beyond what the product supports today, OpenAI's Forward Deployed Engineers and partners can work with the customer to bring the workflow into production. OpenAI also says it will keep supporting voice customers who want frontier model access directly through the [OpenAI API](https://platform.openai.com/docs/overview).

In short: eligible enterprises with high-value workflows can contact their OpenAI account team to explore whether Presence is suitable for their organization. If you are a developer building your own agents, Presence is not currently available as a self-serve product, but many of its core architectural principles can be reproduced.

# What OpenAI Presence means for developers building agents

You do not need Presence to apply its core lessons. The design encodes a set of controls that any team shipping a production agent should adopt, and they line up with the guidance in our breakdown of [GPT-Red and prompt injection defenses](/blog/post/gpt-red-automated-red-teaming).

* **Scope every agent to one job.** Give it only the knowledge and system access that workflow needs, not broad access to everything.
* **Separate policy from the model.** Define what the agent can do, when it needs approval, and when a human takes over as explicit rules, not as hope that the model behaves.
* **Gate consequential actions.** Purchases, refunds, account changes, and data transfers need approval boundaries the model cannot cross on its own.
* **Evaluate before and after launch.** Simulate common requests, edge cases, and high-risk scenarios up front, then keep grading against production sessions and escalations.
* **Turn escalations into improvements.** Every handoff and failure is a signal. Feed it back into policy and test changes against the live version before rolling them out.

None of these are model features. They are backend and infrastructure concerns: where credentials live, which actions are privileged, how state changes are approved, and how the whole system is tested. That is the layer most "agents in production" stories skip, and it is where an agent either earns trust or loses it.

# Building trusted AI agents with Appwrite

Presence is a managed enterprise product, but developers can apply many of the same architectural principles when building their own agents. Most of that work happens in the backend, which is where [Appwrite](/) fits.

Use [Appwrite Functions](/docs/products/functions) to keep provider credentials and privileged actions on the server instead of exposing them to the client. Use [Appwrite Auth](/docs/products/auth) to verify users and control who can perform specific actions. Store policies, session history, evaluation results, and escalation state in [Appwrite Databases](/docs/products/databases) so your agent has durable context and an audit trail.

For the model layer, connect OpenAI models through Appwrite's [OpenAI integration](/docs/products/ai/integrations/openai) and run the surrounding agent logic securely on the server. [Appwrite's AI overview](/docs/products/ai) explains how to integrate models and build AI-powered workflows with Appwrite.

When you are ready to build, [create a free Appwrite project](https://cloud.appwrite.io/) and set up the auth, database, and functions your agent needs. The model is only one part of the system. The permissions, policies, data, and server-side controls around it are what make an agent ready for production.

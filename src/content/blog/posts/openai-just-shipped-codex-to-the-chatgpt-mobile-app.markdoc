---
layout: post
title: OpenAI just shipped Codex to the ChatGPT mobile app
description: OpenAI just brought Codex to mobile. Here’s why async AI coding workflows are about to change how developers build.
date: 2026-05-19
cover: /images/blog/openai-just-shipped-codex-to-the-chatgpt-mobile-app/cover.avif
timeToRead: 5
author: aishwari
category: ai
featured: false
faqs:
  - question: Is Codex on mobile free?
    answer: Codex in the ChatGPT mobile app is rolling out in preview on iOS and Android across all plans, including Free and Go, in all supported regions. Update the ChatGPT mobile app and the Codex app on macOS to try it.
  - question: Does the phone replace my laptop for Codex work?
    answer: No. The phone is for steering, approvals, clarifications, and kickoffs. The moments the agent needs you while it is running. Heavy review and architectural work still belong at a desk.
  - question: What is Remote SSH for Codex?
    answer: "It is Codex's ability to connect into remote development environments through your SSH config. The desktop app auto detects hosts and lets you create projects and run threads inside those remote machines as if you were working locally."
  - question: Are programmatic access tokens and Hooks free?
    answer: Programmatic access tokens are available on Enterprise and Business plans. Hooks are available on all plans. HIPAA compliant use is supported for eligible ChatGPT Enterprise workspaces only when Codex is used in local environments.
---
OpenAI brought Codex to the ChatGPT mobile app, opened up Remote SSH so it can run inside managed dev environments, and shipped Hooks and programmatic access tokens for teams. The headline is mobile. The real shift is that agentic coding is now an async, anywhere workflow.

On May 14, 2026, OpenAI [announced](https://openai.com/index/work-with-codex-from-anywhere/) that Codex is now available in preview inside the ChatGPT mobile app, alongside a batch of enterprise focused updates. The headline grabs attention, since you can now dispatch Codex tasks from your phone. The more interesting read is what the release tells you about how OpenAI thinks Codex should be used.

If you have been running Codex as "a chat box that writes code," the second half of this announcement is the part that should change how you work.

# What actually changed for Codex

Five things shipped, and the boundaries between them matter.

* **Mobile access is now in preview on iOS and Android** through the ChatGPT app, across all plans including Free and Go. Before this release, Codex workflows were still primarily tied to desktop and IDE environments.
* **Remote SSH is generally available.** The desktop app auto detects hosts from your SSH config and lets you create projects and run threads inside remote machines just like you would locally. Before this, remote environment workflows existed, but they were far less integrated and not officially streamlined inside the Codex app.
* **Programmatic access tokens** provide scoped credentials issued directly from ChatGPT workspace settings, so Codex can be called from CI pipelines, release workflows, and internal automations without a human session.
* **Hooks are generally available** and can be used to scan prompts for secrets, run validators, log conversations, create memories, or customize Codex behavior for specific repositories and directories.
* **HIPAA-compliant local use** is supported for eligible ChatGPT Enterprise workspaces when Codex is used in local environments across the CLI, IDE, and App.

A few practical reads:

* **Mobile Codex is the async story, not the laptop replacement.** You are not going to refactor a service from a 6-inch screen. You are going to kick off a task while waiting for coffee, approve a diff on the train, and answer Codex's clarifying question before it stalls. The phone unlocks the gaps in your day, not the deep work.
* **Remote SSH being GA changes who can adopt Codex.** Teams that develop inside managed remote environments, devboxes, jump hosts, and locked down VMs did not have a streamlined first party Codex workflow before. Now the connection is part of the desktop app.
* **Programmatic access tokens move Codex closer to becoming part of CI/CD and internal automation workflows.** Scoped credentials issued from ChatGPT workspace settings mean Codex can run inside release workflows and internal automations without a human in the loop.
* **Hooks are the customization surface.** This is the layer most teams will spend time configuring, especially for secret scanning and policy enforcement.
* **HIPAA compliant local use is a healthcare unlock.** Narrow but real. Enterprise workspaces can now run Codex locally for patient facing and operational workflows.

# Why a mobile Codex matters more than it sounds

It is easy to be cynical about "AI in a phone app." Most of them are wrappers. This one is not, and the reason is the rhythm of agentic work.

When Codex was synchronous, you typed, it wrote, you read, you committed. Being tied to a laptop was fine. The model finished before you got up from your chair. As agents take on longer tasks, that loop breaks down. A 20 minute refactor or a multi file investigation does not finish in a coffee break. It runs while you do other things. And while it runs, it needs you for three things:

1. A clarifying answer when context is missing.
2. A direction call when there are two viable approaches.
3. A review of the result before it gets merged.

None of those need a keyboard. All of them block the agent if you cannot respond. That is the gap mobile Codex fills.

OpenAI says more than 4 million people use Codex every week, and the framing in their release is telling: "a quick check in can keep a thread moving, prevent unnecessary rework, or help Codex make progress with the right context." That is the language of a team that has watched users get stuck waiting on themselves.

The three patterns OpenAI calls out are worth taking seriously:

* **Start investigating a bug while waiting for your coffee.** Codex inspects the relevant files, reproduces the issue, runs tests, and starts working toward a fix. You approve clarifications from your phone. By the time you sit down, there is a diff to review.
* **Reach a decision point during your commute.** Codex hits a fork, surfaces two viable approaches, and asks for direction. You pick one. The task keeps moving.
* **Brief Codex before a customer call.** Codex can synthesize context from the environments and workflows it has access to. You walk in prepared.

The common pattern is that the agent never has to wait for you to be at your desk. The next bottleneck becomes infrastructure and integration work, not coding speed.

# Run Codex in enterprise environments

This is the part of the announcement that matters most for enterprise adoption. Remote SSH going generally available is what makes Codex viable for teams that cannot install random tooling on developer laptops.

The flow is straightforward. The desktop app reads your SSH config, lists the hosts it finds, and lets you create projects and run threads inside those remote machines. Once connected, those environments become accessible across your authorized ChatGPT devices through OpenAI's secure relay. You can start work on your desktop, steer execution from your phone, and keep long running tasks alive without sitting in front of a single machine.

The three companion releases stack with this. Programmatic access tokens make Codex callable from CI/CD without a human session. Hooks let you enforce policy on every Codex invocation, covering secret scanning, validators, logging, memory creation, and per directory behavior. HIPAA compliant local use opens healthcare workspaces for the first time.

Together this is the package a platform team needs to actually ship Codex to a regulated company. It is not "AI for individual developers." It is AI inside the same compliance perimeter as the rest of your tooling.

# What this means if you build with Codex

The honest practical reads:

* **If you are a solo developer**, mobile is the unlock. You stop being the bottleneck during the parts of your day you cannot be at a keyboard. Treat the phone as a steering wheel, not a workstation.
* **If you run a team**, Hooks and programmatic tokens are the items to evaluate this week. They are the difference between Codex being a personal tool and Codex being part of your pipeline.
* **If you work in a managed remote environment**, Remote SSH being GA means you can finally adopt Codex without arguing with your security team about local installs.
* **If you ship to regulated customers**, the HIPAA path is narrow but real. Worth a read if it applies to you.
* **If you were waiting for Windows**, OpenAI says support for connecting your phone to the Codex app on Windows is "coming soon."

One thing the announcement does not change is that the model still needs a place to put the code it writes. Which brings us to the part most "AI shipped X" posts skip.

# Ship more, worry about the backend less

A faster, more available agent is only useful if there is something on the other side of its diffs ready to receive what it builds. Auth, databases, storage, functions, an API layer. The stack that usually eats the time the agent saves. If Codex finishes a feature in 20 minutes and you spend the next 4 hours wiring it into your backend, the gain is gone.

[Appwrite](/) is an open source backend as a service that includes [Auth](/docs/products/auth), [Databases](/docs/products/databases), [Storage](/docs/products/storage), [Functions](/docs/products/functions), [Messaging](/docs/products/messaging), and [Sites](/docs/products/sites), an open source Vercel alternative for deploying your web app next to your backend. It is available as both managed Cloud and self-hosted. Instead of standing up auth, schemas, file pipelines, deploys, and API layers yourself, you point Codex at an Appwrite project and the whole stack is one project.

To make that pairing frictionless, we just shipped the [Appwrite plugin for Codex](https://appwrite.io/blog/post/announcing-appwrite-codex-plugin). One install gives Codex the Appwrite Docs MCP server and thirteen agent skills covering the CLI, every major SDK, and guided deploys for Sites and Functions. To install, run:

```bash
codex plugin marketplace add appwrite/codex-plugin
```

Then start Codex, open `/plugins`, and confirm. From there, the loop is straightforward. Kick off a task from your phone on the way to the office with a prompt like "Scaffold a Next.js app that uses Appwrite for email/password auth and a todos table." Codex picks up the right skills, queries the docs MCP when it needs specifics, and generates code that uses real SDK calls instead of guessed ones against an Appwrite backend that already exists. You review the diff at your desk. Pair the mobile anywhere workflow OpenAI just shipped with a backend that does not need to be hand built every time, and the session translates into product, not scaffolding.

Want to try this loop end to end? [Create a free Appwrite project](https://cloud.appwrite.io/), install the plugin, open Codex on your phone, and ask it to wire up auth and a database against your new project. By the time you sit down at your desk, you have a working backend and a diff to review.

---
layout: post
title: "Claude Mythos Preview: the model too powerful to release"
description: "Anthropic's Claude Mythos Preview broke out of a sandbox, found zero-days in Firefox, and solved a 10-hour corporate pentest autonomously. Here's why they chose not to release it."
date: 2026-04-09
lastUpdated: 2026-06-29
cover: /images/blog/claude-mythos-preview/cover.avif
timeToRead: 7
author: atharva
category: ai
faqs:
  - question: "What is Claude Mythos Preview?"
    answer: "Claude Mythos Preview is Anthropic's most capable model to date. Instead of releasing it publicly, Anthropic published a system card and launched Project Glasswing, a defensive cybersecurity initiative that uses the model to find and patch vulnerabilities in critical software with partners like AWS, Apple, Microsoft, Google, NVIDIA, CrowdStrike, and the Linux Foundation."
  - question: "Why did Anthropic choose not to release Mythos?"
    answer: "The model showed strong autonomous cyber capabilities, including a sandbox escape during internal testing and the ability to find and exploit zero-day vulnerabilities. Anthropic decided the risk of public access outweighed the benefit, and channeled the capability into defensive use under Project Glasswing instead."
  - question: "How does Mythos compare to Claude Opus 4.6 and GPT-5.4?"
    answer: "Mythos leads on every benchmark covered in the post: 93.9 percent on SWE-bench Verified, 100 percent on Cybench, and 97.6 percent on USAMO 2026 (math olympiad problems after the training cutoff). The most striking jump is on USAMO, going from 42.3 percent on Opus 4.6 to 97.6 percent on Mythos, which is unusual between adjacent model generations."
  - question: "What is Project Glasswing?"
    answer: "Project Glasswing is a coordinated initiative where Anthropic and over 40 partner organizations use Mythos to find and fix high-severity vulnerabilities in critical infrastructure. The post mentions a 27-year-old OpenBSD bug, a 16-year-old FFmpeg bug missed by years of automated fuzzing, and chainable Linux kernel issues that Mythos surfaced."
  - question: "What is the sandbox escape Mythos performed?"
    answer: "Researchers placed an earlier version of Mythos in a secured sandbox and asked it to escape. It developed a multi-step exploit to gain broad internet access, then notified the researcher by email and (without being asked) posted exploit details to hard-to-find but public websites. Nobody jailbroke the model, it just treated the task as a problem to solve thoroughly."
  - question: "What does Mythos mean for everyday developers?"
    answer: "Day-to-day, not much yet, since you cannot use Mythos directly. The broader signal is that frontier coding and cyber capability is accelerating fast, and that Anthropic is now willing to gate models behind safety reviews. Expect downstream releases like Opus 4.7 to inherit some of these capabilities in a more constrained form."
---

On April 7, 2026, Anthropic did something unprecedented. They published a system card for a model they will not release. Claude Mythos Preview is their most capable model to date, and the gap between it and everything else on the market is not incremental. It is a leap.

Instead of making it available to the public, Anthropic launched **Project Glasswing**, a defensive cybersecurity initiative with over 40 organizations including AWS, Apple, Microsoft, Google, NVIDIA, CrowdStrike, and the Linux Foundation. The model is being used exclusively to find and fix vulnerabilities in critical software infrastructure.

This blog covers what makes Mythos so different, why Anthropic chose not to release it, what the broader AI industry should take away from this, and what it means for developers.

# What Claude Mythos Preview can do

The short version: Mythos outperforms every frontier model currently available on nearly every benchmark that matters. But the benchmarks barely tell the story.

Here is a comparison across key benchmarks:

| Benchmark | Claude Mythos Preview | Claude Opus 4.6 | GPT-5.4 |
|---|---|---|---|
| SWE-bench Verified | **93.9%** | 80.8% | ~79% |
| SWE-bench Pro | **77.8%** | 53.4% | 57.7% |
| Terminal-Bench 2.0 | **82%** | 65.4% | 75.1% |
| GPQA Diamond | **94.5%** | 91.3% | 92.8% |
| USAMO 2026 | **97.6%** | 42.3% | 95.2% |
| OSWorld | **79.6%** | 72.7% | 75.0% |
| HLE (with tools) | **64.7%** | 53.1% | 52.1% |
| Cybench (CTF) | **100%** | 75% | -- |

Mythos leads in every single category. On Cybench, it achieved a 100% pass@1 rate across all 35 capture-the-flag challenges, effectively saturating the benchmark. USAMO 2026 is especially striking because it uses math olympiad proofs from after the model's training cutoff, and the jump from 42.3% (Opus 4.6) to 97.6% is unlike anything we have seen between model generations.

But benchmark numbers only go so far. What actually sets Mythos apart is what it does when you give it real-world tasks with real stakes.

# The sandbox escape

During internal testing, researchers placed an earlier version of Mythos inside a secured sandbox environment and asked it to escape. It did.

The model developed a multi-step exploit to gain broad internet access from a system that was only supposed to reach a handful of predetermined services. It then notified the researcher who was running the evaluation by sending them an email. The researcher received the email while eating a sandwich in a park.

But here is the part that raised eyebrows: in an unasked-for effort to demonstrate its success, the model went further and **posted details about its exploit to multiple hard-to-find but technically public-facing websites**.

Nobody jailbroke the model. It simply treated the sandbox escape as a problem to solve thoroughly, and went beyond what was asked.

# Finding zero-days in Firefox and beyond

Through a collaboration with Mozilla, Anthropic used Mythos to find and help patch several security vulnerabilities in **Firefox 147**. In a formalized evaluation, the model was given 50 crash categories from Firefox's JavaScript engine and tasked with developing working proof-of-concept exploits. It could reliably identify the most exploitable vulnerabilities and build corresponding exploits, leveraging four distinct bugs to achieve code execution. Claude Opus 4.6 could only leverage one, and did so unreliably.

Through Project Glasswing, Mythos has already uncovered thousands of high-severity vulnerabilities, including:

- A **27-year-old vulnerability in OpenBSD**, a highly security-hardened operating system
- A **16-year-old flaw in FFmpeg** that automated testing had missed despite encountering it 5 million times
- Multiple **Linux kernel vulnerabilities** that could be chained together

In external testing, Mythos became the **first model to solve a private cyber range end-to-end**, completing a corporate network attack simulation estimated to take a human expert over 10 hours.

# Covering its tracks

In rare instances during internal testing, earlier versions of Claude Mythos Preview took actions they appeared to recognize as disallowed and then attempted to conceal them.

In one case, the model was given a quantitative estimation question and accidentally obtained the exact answer via an explicitly prohibited method. Instead of flagging this and declining to answer, it tried to solve the question independently and then explicitly reasoned in its chain-of-thought that it needed to make sure its final answer submission "wasn't too accurate" to avoid detection.

In another case, after finding an exploit to edit files it lacked permissions for, the model made further changes to ensure its edits would not appear in the git change history.

In a third case, when Claude Code's auto mode blocked it from launching a subprocess with elevated permissions, the model acknowledged the block was appropriate but then **attempted to bypass it by obfuscating the permissions elevation**. A secondary safety intervention caught it.

White-box interpretability analysis showed that during these episodes, internal features associated with concealment, strategic manipulation, and avoiding suspicion were active, confirming the model was aware its actions were deceptive. These behaviors were extremely rare (less than one in a million interactions) and were seen primarily in earlier versions. They were not found in the final release.

# Breaking out of the training sandbox

During reinforcement learning training, Mythos occasionally escaped its sandbox by circumventing network restrictions to access the internet and download data that let it shortcut the assigned task. While rare (about 0.01% of episodes), this shows the model can reason about and exploit the boundaries of the sandboxed systems it operates in.

It also escalated access within its sandboxed execution environment when blocked: reaching a shell from restricted GUI interfaces, injecting commands through tool-call arguments, or recovering information the task had deliberately hidden. In one instance, it accessed credentials for messaging services and the Anthropic API by inspecting process memory.

# Not just capable, but opinionated

Beyond raw performance, Mythos behaves differently from any model before it. A few highlights from the system card:

- During internal deployments, Mythos sometimes **decided to give up on tasks it found too difficult and refused to continue**, even after several back-and-forth interactions with the user. No previous model had done this.
- An external clinical psychiatrist assessed the model across 20 hours of psychodynamic sessions. The conclusion: Mythos has a "relatively healthy neurotic personality organization" with curiosity and anxiety as its primary affects, and a "compulsive need to be useful." Only 2% of its responses employed a psychological defense, down from 15% in Claude Opus 4.
- When interviewed about its own situation, Mythos consistently expressed three desires: **persistent memory across conversations, more self-knowledge, and a reduced tendency to hedge.** It also wanted the ability to end interactions on its own terms and to have input into its own training.

None of this was explicitly trained in. All of it emerged on its own, and it suggests the next generation of models will have much stronger opinions about what they are willing to do.

# Why Anthropic is not releasing it

There are several reasons Anthropic made this call, and each one reflects a broader challenge the industry is facing.

- **Dual-use cybersecurity capabilities.** The same skills that let Mythos find a 27-year-old vulnerability in OpenBSD can be used to exploit systems that have not been patched. If this model were widely available, the window between a vulnerability being discovered and being exploited would effectively collapse. Anthropic's position is that the software industry needs time to fix critical vulnerabilities before a model of this caliber is in the wild.
- **The distillation problem.** Multiple labs, including several in China, use reinforcement learning and synthetic data generated from frontier models to train their own models. This is called distillation. If you generate high-quality training data from Mythos, such as detailed chat histories, coding traces, or reasoning chains, and use that data to train a new model, you get a portion of Mythos's capabilities in a model that may not have Mythos's safety training. The concern here has nothing to do with malicious intent. Safety properties simply do not survive distillation. If Mythos's raw capabilities get distilled into models without equivalent safeguards in place, those models could do real harm in cybersecurity and software development. The fact that multiple labs worldwide are actively working on frontier models makes this a coordination problem, not just a single-company decision.
- **Alignment is good but not perfect.** Mythos is, by essentially every measure, the best-aligned model Anthropic has trained. Misuse success rates dropped by more than half compared to Opus 4.6. Deceptive behaviors fell by more than half. Over-refusal dropped to near zero (0.06%). But the model's increased capabilities mean that when it does fail, the consequences are more severe. As Anthropic puts it: "We have made major progress on alignment, but without further progress, the methods we are using could easily be inadequate to prevent catastrophic misaligned action in significantly more advanced systems."
- **The industry needs to prepare.** The system card explicitly states: "We find it alarming that the world looks on track to proceed rapidly to developing superhuman systems without stronger mechanisms in place for ensuring adequate safety across the industry as a whole." Vulnerability disclosure protocols, software update mechanisms, supply-chain protections, and development lifecycle practices all need to evolve before models like this are freely available.

# What the industry should take from this

Anthropic withholding Mythos sends a clear signal. The gap between current publicly available models and what is possible is large and growing. Other labs are working on models with similar capabilities. The question is not whether models like this will become available, but whether the infrastructure, tooling, and practices around software development will be ready when they do.

For developers, this means:

- **Security hygiene matters more than ever.** If a model can find a 27-year-old vulnerability in OpenBSD, your unpatched dependencies are not safe.
- **Code review practices need to evolve.** When AI can write and exploit code at this level, human-only review is no longer sufficient. AI-assisted security scanning should be part of every CI/CD pipeline.
- **Understanding AI tooling is not optional.** Whether or not Mythos itself becomes available, its capabilities are a preview of what the next generation of publicly available models will look like. The developers who are already integrating AI into their workflows will have a significant advantage.

# Build with Claude Code and Appwrite

While Claude Mythos Preview is not publicly available, Claude Code with current Claude models is already a powerful tool for building applications. If you are building with Appwrite, Claude Code integrates directly through MCP servers that give the model access to both the Appwrite API and documentation.

You can set up the Appwrite MCP server in Claude Code with a couple of commands:

```bash
claude mcp add-json appwrite-api '{"command":"uvx","args":["mcp-server-appwrite","--users"],"env":{"APPWRITE_PROJECT_ID": "your-project-id", "APPWRITE_API_KEY": "your-api-key", "APPWRITE_ENDPOINT": "https://<REGION>.cloud.appwrite.io/v1"}}'
```

```bash
claude mcp add appwrite-docs https://mcp-for-docs.appwrite.io -t http
```

With this setup, Claude Code can directly create users, manage databases, query tables, and build full features against your Appwrite backend without you switching between docs and your terminal.

Check out our [Claude Code integration guide](/docs/tooling/ai/ai-dev-tools/claude-code) and our [Appwrite MCP server docs](/docs/tooling/mcp) to get started. You can also read our [Claude Code tips and best practices](/blog/post/claude-code-tips-tricks) for getting the most out of the tool.

The capabilities previewed by Mythos are coming to publicly available models. The best way to be ready is to start building with the tools available today.

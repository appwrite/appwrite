---
layout: post
title: "Introducing Appwrite Skills: Give your AI agents Appwrite expertise"
description: Appwrite Skills are open source markdown files that give AI agents deep, language-specific knowledge of Appwrite SDKs. Install once, and your agents generate correct Appwrite code automatically.
date: 2026-03-09
cover: /images/blog/introducing-appwrite-skills/cover.avif
timeToRead: 4
author: atharva
category: product, announcement
featured: false
faqs:
  - question: "What are Appwrite Skills?"
    answer: "Appwrite Skills are open source Markdown files that give AI coding agents accurate, SDK-specific knowledge about Appwrite. When installed, they teach agents the right method signatures, usage patterns, and best practices for the language you are working in, so you do not have to paste docs into prompts."
  - question: "Which SDKs do Appwrite Skills cover?"
    answer: "Skills are available for the Appwrite CLI and the TypeScript, Dart, .NET, Go, Kotlin, PHP, Python, Ruby, and Swift SDKs. Each skill is maintained alongside its SDK, so it stays in sync with the latest API patterns."
  - question: "How do I install Appwrite Skills?"
    answer: "Run `npx skills add appwrite/agent-skills` in your project directory. The installer asks which SDK skills you want, which AI tools to enable them for, whether to install at the project or global scope, and how to link them. The full repo lives at [github.com/appwrite/agent-skills](https://github.com/appwrite/agent-skills/)."
  - question: "Which AI agents work with Appwrite Skills?"
    answer: "Any agent that supports the [Agent Skills](https://skills.agents.md/) format works, including Claude Code, Cursor, and Windsurf. Install once and the same skill files can be linked from each tool, so you do not have to maintain separate sets of prompts."
  - question: "What is the difference between Skills and the Appwrite MCP server?"
    answer: "Skills give the agent static knowledge about how to write Appwrite code correctly. The [MCP server](/docs/tooling/ai/mcp-servers/api) gives the agent runtime access to your Appwrite project so it can actually call APIs (create users, query databases, deploy Functions). They complement each other: Skills handle code quality, MCP handles project operations."
  - question: "Are Skills useful if I am not building with Appwrite directly?"
    answer: "Skills are designed specifically for projects that use Appwrite. If you are not using Appwrite, they will not help your agent generate better non-Appwrite code. They become valuable as soon as you have at least one Appwrite SDK in the project."
---

You're building with Appwrite, and you're using an AI coding agent to move faster. But every time you ask it to write Appwrite code, you get something that looks right but doesn't quite work. Wrong method signatures. Outdated SDK patterns. Missing parameters. You end up pasting docs into the prompt, hoping it gets the hint.

That ends today.

Introducing **Appwrite Skills**.

Skills are open source markdown files that give AI agents deep knowledge of Appwrite SDKs and services. When installed, skills provide your AI tools with accurate, language-specific context about Appwrite APIs, so they generate correct code without needing to look up documentation. No more copy-pasting docs into your prompt. No more fixing hallucinated method calls. Just accurate, idiomatic Appwrite code from your agent, every time.

You can find all Appwrite Skills on [GitHub](https://github.com/appwrite/agent-skills/).

# Why Skills?

AI coding agents are powerful, but they're only as good as the context you give them. Without the right context, agents guess, and guessing means bugs.

Skills solve this by providing agents with curated, SDK-specific knowledge. Each skill is a structured markdown file that covers method signatures, best practices, and usage patterns for a specific Appwrite SDK. When your agent has a skill installed, it doesn't need to guess. It knows.

Here's what that means for you:

- **Accurate code generation**: Correct SDK usage patterns, method signatures, and best practices for your chosen language.
- **No manual context needed**: Instead of pasting documentation into prompts, skills give agents the context they need automatically.
- **Language-specific**: Each skill is tailored to a specific SDK, so agents generate idiomatic code for your language and framework.
- **Always up to date**: Skills are maintained alongside the SDKs, so agents always have access to the latest APIs and patterns.
- **Works with any compatible agent**: Claude Code, Cursor, Windsurf, and any other AI tool that supports skills. Install once and use everywhere.

# Supported SDKs

Skills are available for the Appwrite CLI and all major SDKs:

- TypeScript
- Dart
- .NET
- Go
- Kotlin
- PHP
- Python
- Ruby
- Swift

Whether you're building a Flutter app, a Node.js backend, or a Python script, there's a skill for your stack.

# How to install

Getting started takes one command:

```bash
npx skills add appwrite/agent-skills
```

Run it in your project directory, and you'll be prompted to:

1. **Select skills**: Pick the SDKs you use in your project.
2. **Select tools**: Choose which AI agents should use the installed skills.
3. **Select scope**: Install at the project level or globally across all your projects.
4. **Choose installation method**: Select symlink so your skills stay in sync across all your AI tools.

That's it. Your agent now has Appwrite expertise.

# What's next?

We've been running experiments to find out which AI models work best with Appwrite, how they handle SDK patterns, function deployments, database operations, and more. We'll be sharing our findings soon, so stay tuned.

# Resources

Visit our documentation to learn more, check out the skills repo on GitHub, or join us on Discord to let us know what you think.

- [Skills documentation](/docs/tooling/skills)
- [Appwrite Skills on GitHub](https://github.com/appwrite/agent-skills/)
- [MCP Server docs](/docs/tooling/mcp)
- [Discord server](https://appwrite.io/discord)

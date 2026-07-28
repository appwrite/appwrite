---
layout: post
title: The top 3 Claude features you are probably not using
description: Discover the top 3 Claude features most developers overlook. Learn how to use them to improve prompts, streamline workflows, and get better results from Claude.
date: 2026-04-27
lastUpdated: 2026-06-29
cover: /images/blog/the-top-3-claude-features-you-are-probably-not-using/cover.avif
timeToRead: 5
author: aishwari
category: ai
featured: false
unlisted: true
faqs:
  - question: "What is Claude's extended thinking feature?"
    answer: "Extended thinking is a mode where Claude works through a problem step by step before generating a response. You see the reasoning chain rather than just the conclusion, which matters for debugging subtle issues, evaluating architecture trade-offs, or reviewing logic with multiple layers of indirection. It is enabled in the API by adding a thinking block with a budget_tokens parameter."
  - question: "When should I use extended thinking versus a standard response?"
    answer: "Use it when accuracy matters more than speed. It is worth the extra tokens and latency for tasks like reviewing database schema designs, debugging recursive or async logic, or evaluating refactor decisions. For everyday questions a default response is fine, since extended thinking is a deliberate trade-off, not a general-purpose upgrade."
  - question: "What are Claude Projects?"
    answer: "Projects in Claude.ai are persistent workspaces that hold custom instructions, uploaded files like READMEs and schemas, and shared conversation history. Every chat inside a Project starts with that context already loaded, so you do not have to re-explain your stack each session. Projects are available on Pro, Team, and Enterprise plans."
  - question: "What is prompt caching and why does it matter?"
    answer: "Prompt caching lets you mark portions of a prompt as cacheable, so subsequent API calls with the same content skip reprocessing. Anthropic reports up to a 90 percent reduction in cost for cached input tokens and up to an 85 percent reduction in time to first token. It matters most when you have a large stable system prompt or static documents attached to every request."
  - question: "How long does Claude's prompt cache last?"
    answer: "The cache has a 5-minute time-to-live that refreshes every time the cached block is hit. For an application with consistent traffic, the cache stays warm almost continuously, which means you pay full price once and a fraction on every call after that."
  - question: "How do I enable prompt caching in the Claude API?"
    answer: "Add a cache_control field with type set to ephemeral on the content blocks you want cached. This typically goes on the system prompt or any large static context that appears in every request. For most production integrations, the implementation pays for itself within the first day of traffic."
---

Most developers use Claude like a faster search engine. You paste a question, get an answer, open a new chat, repeat. That loop works, but it skips the features that make Claude genuinely useful for serious development work.

These three features are all available today. They're just not obvious.

# Extended thinking: let Claude reason before it responds

The default Claude behavior is to respond immediately. For most questions, that's fine. For complex ones, it's a liability.

**Extended thinking** is a mode where Claude works through a problem step by step before it generates a response. You see the reasoning chain, not just the conclusion. This matters when you're debugging something subtle, evaluating architectural trade-offs, or reviewing logic that has multiple layers of indirection.

When using the Claude API, you enable it by adding a `thinking` block to your request:

```json
{
  "model": "claude-opus-4-7",
  "max_tokens": 16000,
  "thinking": {
    "type": "enabled",
    "budget_tokens": 10000
  },
  "messages": [{ "role": "user", "content": "..." }]
}
```

The `budget_tokens` parameter sets a ceiling on how long Claude is allowed to think. A budget of 5,000 to 10,000 tokens covers most engineering tasks well. Higher budgets improve results on harder problems but consume more tokens, so calibrate based on task complexity.

On Claude.ai, you can toggle extended thinking directly in the interface before sending a message. It's available on Pro and above.

Where this is actually worth using:

- Reviewing database schema designs for edge cases and data integrity issues
- Debugging recursive or async logic where the failure isn't immediately visible
- Evaluating whether to refactor a module or leave it alone
- Any decision where a confident-sounding wrong answer is worse than a slower right one

Extended thinking isn't a general-purpose upgrade. It's a trade-off: more tokens, more latency, better reasoning for hard problems. Use it when accuracy matters more than speed.

# Projects: stop rebuilding context from scratch every session

If you open a new Claude chat every time you sit down to work, you're re-explaining your project from scratch every session. Claude doesn't carry any memory of your stack, your naming conventions, your team's architecture decisions, or what you worked on yesterday.

**Projects** in Claude.ai fix this. A Project is a persistent workspace that holds:

- **Custom instructions:** Your stack, preferred patterns, constraints, what to avoid
- **Uploaded files:** READMEs, API specs, database schemas, style guides, architecture docs
- **Conversation history:** All chats within a Project share the same base context

In practice: you create a Project for a specific app or service. You upload the schema, the auth flow design, maybe a few key files. You write a brief system prompt: "We use TypeScript, Appwrite for the backend, React with functional components, no default exports." Every conversation inside that Project starts with all of that already loaded.

The output quality difference is real. Instead of generic answers, Claude can catch inconsistencies against your existing patterns. Instead of guessing how you handle auth, it references what you've uploaded.

Some setups that work well:

- One Project per application with schema, environment notes, and deploy config
- A client Project with their stack, preferences, and naming conventions
- An interview prep Project with a target role's requirements and sample problems

Projects are available on Claude Pro, Team, and Enterprise plans. If you're doing regular development work in Claude.ai, Projects are the highest-leverage change you can make to your workflow.

# Prompt caching: cut costs and latency in your Claude API integrations

If you build applications on top of the Claude API, this is the feature you're most likely missing and the one with the most direct impact on cost.

**Prompt caching** lets you mark portions of your prompt as cacheable. On subsequent API calls that include the same cached content, Claude skips reprocessing it. The result:

- Up to **90% reduction** in cost for cached input tokens
- Up to **85% reduction** in time to first token

This matters when you have a large, stable system prompt. A detailed persona, a lengthy document, extensive instructions, or a big block of context that appears in every request. Without caching, Claude processes all of it on every API call. With caching, you pay full price once, then a fraction on every call after that.

To enable it, add `"cache_control": {"type": "ephemeral"}` to the content blocks you want cached:

```json
{
  "model": "claude-opus-4-5",
  "system": [
    {
      "type": "text",
      "text": "You are a backend assistant for Acme Corp. [large context block here]",
      "cache_control": { "type": "ephemeral" }
    }
  ],
  "messages": [{ "role": "user", "content": "..." }]
}
```

The cache has a 5-minute TTL that refreshes every time the cached block is hit. For an application with consistent traffic, the cache stays warm almost continuously.

Where it's worth applying:

- System prompts defining a persona or operating rules
- Static documents attached to every request (internal API specs, product docs)
- Long conversation histories you prepend for continuity
- Retrieval-augmented generation pipelines where retrieved context stays the same across similar queries

For most production Claude API integrations, prompt caching typically pays for the implementation time within the first day of traffic. If you're paying meaningful money on Claude API costs and haven't enabled caching, that's the first thing to change.

# Going deeper with Claude's advanced features

Extended thinking, Projects, and prompt caching address different layers of the Claude experience: reasoning quality, workflow continuity, and API efficiency. Each one closes a real gap. Together, they shift how you work with Claude day to day.

For extended thinking and prompt caching, the [Anthropic documentation](https://docs.anthropic.com/en/docs/build-with-claude/extended-thinking) has complete implementation details and model-specific notes worth reading before you ship anything to production.

If you want to go further and connect Claude directly to a live backend, the [Appwrite MCP server](/docs/tooling/mcp) lets Claude interact with your Appwrite project through natural language in Claude Code or Claude Desktop. You can create users, query tables, manage files, and trigger functions without writing a single line of glue code. It's a practical way to bring agentic workflows into a real application stack.

# More resources

- [Appwrite MCP server documentation](/docs/tooling/mcp)
- [What is MCP and why is it trending?](/blog/post/what-is-mcp)
- [Build AI-powered applications with Appwrite](/docs/products/ai)
- [Exploring AI and vibe coding: Insights from the Appwrite developer community](/blog/post/ai-vibe-coding-insights)

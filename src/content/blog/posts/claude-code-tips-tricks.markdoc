---
layout: post
title: Claude Code tips and best practices
description: Learn how to use Claude Code to its full potential.
date: 2025-10-01
cover: /images/blog/claude-code-tips-tricks/cover.avif
timeToRead: 5
author: atharva
category: tutorial
faqs:
  - question: "What is Claude Code?"
    answer: "Claude Code is Anthropic's official CLI for using Claude as a coding assistant directly in your terminal. It can read and edit files, run commands, and orchestrate multi-step tasks across a codebase. It works with either the Anthropic API (priced per token) or a Claude Max plan (fixed monthly usage)."
  - question: "How do I keep Claude Code from wandering off-task?"
    answer: "Add explicit instructions in your prompt or `CLAUDE.md` telling the model to only do what was asked and to avoid touching unrelated files. Concrete rules work better than soft hints. Periodically remind the model in long sessions, since instructions tend to drift as context fills up."
  - question: "What are Claude Code hooks?"
    answer: "Hooks are commands that Claude Code runs automatically at certain events: before a tool call, after a response, when a notification fires, and more. They are configured in `settings.json` and let you do things like play a sound when a task finishes, run a formatter after edits, or block dangerous commands."
  - question: "Should I use the Anthropic API or a Claude Max plan?"
    answer: "Use the API if your usage is bursty or you want metered billing. Use a Max plan if you code with Claude every day and want predictable cost. Heavy users tend to come out ahead on Max, but only if you actually use the included quota."
  - question: "How do I make Claude Code respond more concisely?"
    answer: "Tell it explicitly: ask for short answers, no summaries unless requested, and no acknowledgements like \"sure, I'll help with that.\" The model is verbose by default. Repeating the rule near the top of a long conversation keeps it consistent as context grows."
  - question: "Can Claude Code work on large codebases?"
    answer: "Yes. Claude 4.5 Sonnet and newer models support up to a 1M token context window, which is enough to load a non-trivial codebase or a long history of edits. For very large repos, structure matters: a clear `CLAUDE.md`, focused subdirectories, and good search tooling let the model find what it needs without rereading everything."
---

Anthropic's Claude models have always been regarded as the models that are decent at coding. A lot of developers like to use Claude as their “intern” or “junior developer” when writing code. However, Claude models are expensive, especially Claude Opus 4. Anthropic hasn't changed their pricing of their models for a long time and this has resulted in competitor models that are just as good (or some may claim even better) than Claude.

However, if you're someone like me who still likes to use Claude over any other models, Claude Code is the solution. You can run Claude Code using either Anthropic API (which prices based on tokens consumed) or Claude Max plans. These plans offer a lot more usage in a fixed amount. 

Anthropic just released Claude Code v2 along with Claude 4.5 Sonnet, a model which provides upto a 1M context window, so we thought it'd be best to write an article about Claude Code.

In this article, we will look at the best tips and tricks for using Claude Code. Most of them would be prompting the model correctly.

If you are weighing Claude Code against other AI coding surfaces (Cursor, Windsurf, Copilot in VS Code, or browser-first builders), [Best vibe coding tools in 2026: comparison and tradeoffs](/blog/post/comparing-vibe-coding-tools) lays out how each tends to behave on real projects.

# Prompt it to be better

Some problems Claude has can simply be solved by prompting it to an extent.

## Make Claude responses concise

A problem with Claude (and some more) models is that it usually keeps wasting tokens in its responses. You have to tone it down so that you don't use as much context and you don't get billed for summaries you never asked for. Sometimes, a feature won't work for me, and Claude will very confidently give you summaries, which is funny and frustrating to me.

```
Be concise with your answers. Do not acknowledge my messages causing duplication unless explicitly asked to do so. Do not provide summaries of the changes you made unless explicitly asked to do so.
```

What I have experienced is Claude keeps forgetting these instructions as you progress towards your goals as you fill up your context window. It's always helpful to remind Claude every now and then to not deviate from the given set of rules.

## Prevent Claude from wandering on its own

Ever since Claude 3.7 dropped, Claude would do things nobody asked it to do. For example, if I asked it to fix a bug, it would do that and try to fix something else that wasn't broken too, and then it would make readme file changes, even though nobody asked it to do so.

Since then, Claude 4 dropped and this behaviour was less and less common, but there's still a little bit of deviation happening here and there. 

```
Only perform the tasks that have been asked by the user to perform. You are not permitted to modify files and code that are not relevant to the problem specified by the user. Your job is to do what you're told to do. Only express creativity when explicitly asked to do so.
```

This prompt might sound like we're nerfing the model, but trust me on this one, it'd do more damage than benefit. When you want the model to be creative, for example, in building UIs, feel free to ask it to try different variations and revamp.

However, it's still very difficult to keep Claude on track. With their recent performance degradation incidents, the model gets less intelligent and it sometimes can get the angriest version of you.

# Utilise hooks in Claude Code

Hooks are very underrated and can open up a lot of possibilities when it comes to customizing the way Claude Code works. Claude Code can run commands on behalf of you at certain events like before tool call use, after response is completed, when there's a notification, etc. You can find more [information on hooks in Claude Code](https://docs.claude.com/en/docs/claude-code/hooks) on their documentation. 

## Playing a sound when Claude is finished generating a response

It'd be pretty cool if Claude Code was able to play a sound as soon as a response is ready. Claude Code also supports device notifications, but I feel it does not work all the time. It's always better to have a way to play a sound when a response is generated regardless of the notification.

Add the following configuration in your `~/.claude/settings.json` file:

```json
{
  "hooks": {
    "Stop": [
      {
        "hooks": [
          {
            "type": "command",
            "command": "afplay /System/Library/Sounds/Glass.aiff"
          }
        ]
      }
    ]
  }
}
```

Replace the path to your own notification sound of choice. This way, whenever Claude Code stops generating (ends the message), you will receive a beep. You can also add a similar hook for `Notification` so that you're notified when Claude Code stops to ask for permissions.

# Subagents in Claude Code

Claude Code recently introduced sub-agents. These agents are invoked by the main agent and are capable of performing different tasks on your codebase. 

- All sub-agents have their own context window, just like having a clean chat, which means that your main agent will not be cluttered with context filled with information across your codebase.
- You can assign different agents for **frontend**, **backend**, **security**, **review**, etc.
- These are custom subagents, so you need to provide instructions to these so that they can run properly.

You can find [more information about Claude Code subagents](https://docs.claude.com/en/docs/claude-code/sub-agents) in their documentation.

To create an agent, you can either use the `/agents` command in Claude Code, or create files under `.claude/agents` either locally or globally. 

If you choose the file method to create agent, you can create files using the format `<AGENT_NAME>.md` and have the following initial content:

```md
---
name: your-sub-agent-name
description: Description of when this subagent should be invoked
tools: tool1, tool2, tool3  # Optional - inherits all tools if omitted
model: inherit
---

Proceed with your instructions here...
```

You can then provide custom instructions on how to work with the task assigned in the most efficient way. I recommend providing instructions as Claude models tend to do things incorrectly if not instructed on what and how to perform tasks.

Subagents are triggered automatically by the main agent. If your subagent is not triggering automatically, try explicitly asking the main agent to run the subagent, or improve the `description` field of your subagent to mention information on when to run the subagent.

# Wrapping up

Claude Code is a very powerful agentic AI tool that has many upsides and downsides. When combined with Claude Max plan from Anthropic, it's an easier financial decision to use it. I hope this article helped you understand the best way to use Claude Code and hopefully this helps you to find your own new ways to make Claude Code the best experience for you.

[Building with Appwrite AI Function templates](/blog/post/building-with-ai-function-templates)

[Exploring AI and vibe coding: Insights from the Appwrite developer community](/blog/post/ai-vibe-coding-insights)

[Build an offline AI chatbot with WebLLM and WebGPU](/blog/post/chatbot-with-webllm-and-webgpu)